<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Integration\Services\OutboxPublisher;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Delivers the outbox to OptiNexus: business events to POST /events, audit records to POST /audit-events.
 *
 * PostgreSQL stays the source of truth: a row becomes DELIVERED only after OptiNexus accepted it, and an event is sent
 * under its own id as `event_id`, so a retry after a timeout cannot create a second event. Rows of a tenant that is not
 * linked to OptiNexus stay PENDING untouched.
 *
 *  - 200/201: DELIVERED.
 *  - OptiNexus unreachable, 5xx, 408, 429, 401 and 422 EVENT_INVALID (type not in the catalog yet): stays PENDING and is
 *    retried with exponential backoff (2 min after the first attempt, doubling up to 1 h), until `max_attempts`.
 *  - any other 4xx (payload does not match the catalog, application not assigned to the tenant, event id conflict):
 *    FAILED at once; fix the cause, then `optientry:nexus:relay-events --retry-failed`.
 */
class OptinexusEventRelay
{
    public function __construct(private readonly NexusApi $api) {}

    /** @return array{delivered:int,retrying:int,failed:int} */
    public function relay(?string $tenantId = null): array
    {
        $result = ['delivered' => 0, 'retrying' => 0, 'failed' => 0];
        $maxAttempts = (int) config('optientry.optinexus.relay.max_attempts');

        $rows = DB::table('outbox_events as o')
            ->leftJoin('tenants as t', 't.id', '=', 'o.tenant_id')
            ->where('o.channel', OutboxPublisher::CHANNEL_OPTINEXUS)->where('o.status', 'PENDING')
            ->where(fn ($q) => $q->whereNull('o.tenant_id')->orWhereNotNull('t.optinexus_tenant_id'))
            ->when($tenantId, fn ($q) => $q->where('o.tenant_id', $tenantId))
            ->whereRaw("(o.last_attempted_at is null or o.last_attempted_at <= ?::timestamptz - (least(60 * power(2, o.attempts), 3600) * interval '1 second'))", [now()->toIso8601String()])
            ->orderBy('o.created_at')->orderBy('o.id')
            ->limit((int) config('optientry.optinexus.relay.batch_size'))
            ->get(['o.*', 't.optinexus_tenant_id as nexus_tenant_id']);

        foreach ($rows as $row) {
            $attempts = $row->attempts + 1;
            $this->mark($row->id, ['attempts' => $attempts, 'last_attempted_at' => now()]);
            $row->attempts = $attempts;

            try {
                $response = $this->deliver($row);
            } catch (IdentityProviderUnavailable $e) {
                // OptiNexus is unreachable: stop instead of hammering it with the rest of the batch.
                $this->retryOrPark($row, 'OptiNexus unavailable: '.Str::limit((string) ($e->details['reason'] ?? ''), 300), $maxAttempts, $result);
                break;
            }

            $status = $response->status();

            if ($response->successful()) {
                $this->mark($row->id, ['status' => 'DELIVERED', 'delivered_at' => now(), 'last_error' => null]);
                $result['delivered']++;
            } elseif ($status === 422 && $response->json('error.code') === 'EVENT_INVALID') {
                $this->retryOrPark($row, 'OptiNexus does not know this event type yet (register it in the Event Catalog).', $maxAttempts, $result);
            } elseif ($status >= 400 && $status < 500 && ! in_array($status, [401, 408, 429], true)) {
                $this->mark($row->id, ['status' => 'FAILED', 'last_error' => Str::limit("HTTP {$status}: ".$response->body(), 1000)]);
                $result['failed']++;
            } else {
                $this->retryOrPark($row, "HTTP {$status}", $maxAttempts, $result);
            }
        }

        return $result;
    }

    /** Puts parked rows back in the queue (after the cause was fixed). */
    public function retryFailed(?string $tenantId = null): int
    {
        return DB::table('outbox_events')->where('channel', OutboxPublisher::CHANNEL_OPTINEXUS)->where('status', 'FAILED')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->update(['status' => 'PENDING', 'attempts' => 0, 'last_attempted_at' => null, 'updated_at' => now()]);
    }

    private function deliver(stdClass $row): Response
    {
        $payload = json_decode($row->payload, true) ?: [];

        if ($row->kind === 'AUDIT') {
            return $this->api->send('POST', '/audit-events', array_filter([
                'tenant_id' => $row->nexus_tenant_id,
                'action' => $payload['action'] ?? $row->event_type,
                'resource_type' => $payload['resource_type'] ?? null,
                'resource_id' => $payload['resource_id'] ?? null,
                'actor_identity' => $payload['actor_identity'] ?? null,
                'old_value' => $payload['old_value'] ?? null,
                'new_value' => $payload['new_value'] ?? null,
                // The audit endpoint has no idempotency key; the outbox id lets a duplicate be recognised.
                'metadata' => ($payload['metadata'] ?? []) + ['outbox_id' => $row->id, 'occurred_at' => Str::of($row->occurred_at)->toString()],
            ], fn ($v) => $v !== null));
        }

        return $this->api->send('POST', '/events', array_filter([
            'event_id' => $row->id,
            'event_key' => $row->event_type,
            'event_version' => (string) $row->schema_version,
            'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String(),
            'tenant_id' => $row->nexus_tenant_id,
            'correlation_id' => $row->id,
            'data' => $payload,
        ], fn ($v) => $v !== null));
    }

    /** @param  array<string,mixed>  $attributes */
    private function mark(string $id, array $attributes): void
    {
        DB::table('outbox_events')->where('id', $id)->update($attributes + ['updated_at' => now()]);
    }

    /** @param  array{delivered:int,retrying:int,failed:int}  $result */
    private function retryOrPark(stdClass $row, string $error, int $maxAttempts, array &$result): void
    {
        if ($row->attempts >= $maxAttempts) {
            $this->mark($row->id, ['status' => 'FAILED', 'last_error' => $error]);
            $result['failed']++;

            return;
        }

        $this->mark($row->id, ['last_error' => $error]);
        $result['retrying']++;
    }
}
