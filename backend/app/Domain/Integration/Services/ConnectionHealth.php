<?php

namespace App\Domain\Integration\Services;

use App\Domain\Entitlement\Services\ModuleAvailability;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Contracts\ConnectorRequirements;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection as Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Whether a connection can be trusted to deliver, and if not why (ecosystem audit A19 and A17, consumer side). Read-only: nothing here moves
 * a cursor, an event or a document, so it can never touch posted history. Run inside the tenant's context.
 *
 * Three situations that need different people are kept apart (never one "error"):
 *   - OptiEntry itself will not run the connection (`entry`: tenant not active, module or feature not entitled, connector not entitled, the
 *     organization not linked to OptiNexus): nothing is pulled, the source keeps the events, history stays;
 *   - the source refused (`access.state = REFUSED`: credential revoked, scope missing, pairing mismatch, a subscription gone at OptiNexus);
 *   - the source cannot answer (`UNAVAILABLE`) or answered something that is not the feed (`INVALID`).
 *
 * Alerts (severity ALERT needs a person, WARN is worth a look):
 *   SOURCE_REFUSED / SOURCE_INVALID / SOURCE_UNAVAILABLE   the last contact with the source
 *   SOURCE_BEHIND_CURSOR      the source's newest committed sequence is below what was already consumed: a restored or reset source
 *   PULL_OVERDUE              an ACTIVE connection that nothing refuses has not pulled for far longer than its interval
 *   ENTRY_HELD                OptiEntry holds the connection (see above)
 *   MISSING_RECORDED_EVENT    payments, corrections or cancellations wait for an invoice that never arrived: the recorded event was lost at the
 *                             source (a missed projection); the operator runs the source's monitor and bounded repair, which only ever adds
 *                             quarantined events at the end of the feed
 *   QUARANTINED_EVENTS        events the source rebuilt later from current state wait for a person (never booked automatically)
 *   OPEN_CONFLICTS, DEAD_LETTERS, BACKLOG_STALLED
 */
class ConnectionHealth
{
    public const OVERDUE_FACTOR = 3;

    public const OVERDUE_MIN_MINUTES = 30;

    public const UNAVAILABLE_ALERT_MINUTES = 60;

    public function __construct(private readonly ModuleAvailability $availability, private readonly AdapterRegistry $adapters) {}

    /** @return array{code:string,reasons:list<string>}|null why OptiEntry itself will not pull or process this connection now */
    public function heldBy(Connection $connection): ?array
    {
        $tenant = Tenant::query()->find($connection->tenant_id);
        $held = fn (string $code, string ...$reasons) => ['code' => $code, 'reasons' => array_values($reasons)];
        $adapter = $this->adapters->for($connection->source_system);
        $problems = $adapter instanceof ConnectorRequirements ? $adapter->entitlementProblems() : [];

        return match (true) {
            $tenant === null || ! $tenant->isActive() => $held('TENANT_NOT_ACTIVE', 'the organization is not active'),
            ! $this->availability->writable(IntegrationRunner::MODULE) => $held('MODULE_NOT_AVAILABLE', 'module '.IntegrationRunner::MODULE.' is not available for writing'),
            ! $this->availability->featureEnabled(IntegrationRunner::FEATURE) => $held('FEATURE_NOT_ENABLED', 'feature '.IntegrationRunner::FEATURE.' is not enabled'),
            FeedPairing::required() && ! $this->linked($connection) => $held('TENANT_NOT_LINKED', 'the organization is not linked to OptiNexus'),
            $problems !== [] => $held('CONNECTOR_NOT_ENTITLED', ...$problems),
            default => null,
        };
    }

    /** @return array<string,mixed> */
    public function access(Connection $connection): array
    {
        return [
            'state' => $connection->feed_access, 'code' => $connection->feed_access_code,
            'since' => $connection->feed_access_since?->toIso8601String(), 'checked_at' => $connection->feed_checked_at?->toIso8601String(),
            'next_attempt_at' => $connection->feed_next_attempt_at?->toIso8601String(),
            'pairing' => ['required' => FeedPairing::required(), 'linked' => $this->linked($connection), 'verified_nexus_tenant_id' => $connection->paired_nexus_tenant_id],
            'entry' => $this->heldBy($connection),
        ];
    }

    /** @return array{high_water:?int,high_water_at:?string,cursor:?int,lag:?int} where the source stands relative to what was consumed */
    public function source(Connection $connection): array
    {
        $cursor = $connection->last_cursor === null ? null : (int) $connection->last_cursor;
        $high = $connection->source_high_water === null ? null : (int) $connection->source_high_water;

        return [
            'high_water' => $high, 'high_water_at' => $connection->source_high_water_at?->toIso8601String(), 'cursor' => $cursor,
            'lag' => $high === null ? null : max(0, $high - ($cursor ?? 0)),
        ];
    }

    /**
     * @param  array{stalled?:bool}  $backlog  the figures of ConnectionService::backlog()
     * @return array{level:string,alerts:list<array<string,mixed>>}
     */
    public function evaluate(Connection $connection, array $backlog = []): array
    {
        $alerts = [];
        $add = function (string $code, string $severity, array $detail = []) use (&$alerts) {
            $alerts[] = ['code' => $code, 'severity' => $severity] + $detail;
        };
        $listening = in_array($connection->status, [Connection::ACTIVE, Connection::ERROR], true);

        $held = $connection->status === Connection::ACTIVE ? $this->heldBy($connection) : null;
        if ($held !== null) {
            $add('ENTRY_HELD', 'WARN', ['reason' => $held['code'], 'reasons' => $held['reasons']]);
        }

        if ($listening) {
            $since = $connection->feed_access_since;
            match ($connection->feed_access) {
                'REFUSED' => $add('SOURCE_REFUSED', 'ALERT', ['reason' => $connection->feed_access_code, 'since' => $since?->toIso8601String()]),
                'INVALID' => $add('SOURCE_INVALID', 'ALERT', ['reason' => $connection->feed_access_code, 'since' => $since?->toIso8601String()]),
                'UNAVAILABLE' => $add('SOURCE_UNAVAILABLE', $since !== null && $since->diffInMinutes(now(), absolute: true) >= self::UNAVAILABLE_ALERT_MINUTES ? 'ALERT' : 'WARN',
                    ['reason' => $connection->feed_access_code, 'since' => $since?->toIso8601String()]),
                default => null,
            };
        }

        $source = $this->source($connection);
        if ($source['high_water'] !== null && $source['cursor'] !== null && $source['cursor'] > $source['high_water']) {
            $add('SOURCE_BEHIND_CURSOR', 'ALERT', ['cursor' => $source['cursor'], 'high_water' => $source['high_water']]);
        }

        if ($connection->status === Connection::ACTIVE && $held === null && ! in_array($connection->feed_access, ['REFUSED', 'INVALID'], true)) {
            $limit = max(self::OVERDUE_MIN_MINUTES, self::OVERDUE_FACTOR * $connection->pull_interval_minutes);
            $last = $connection->last_pulled_at ?? $connection->updated_at;
            if ($last !== null && $last->diffInMinutes(now(), absolute: true) > $limit && $connection->feed_access !== 'UNAVAILABLE') {
                $add('PULL_OVERDUE', 'WARN', ['last_pulled_at' => $connection->last_pulled_at?->toIso8601String(), 'minutes' => (int) $last->diffInMinutes(now(), absolute: true)]);
            }
        }

        $this->eventAlerts($connection, $add, $backlog);
        $levels = array_column($alerts, 'severity');

        return ['level' => in_array('ALERT', $levels, true) ? 'ALERT' : (in_array('WARN', $levels, true) ? 'WARN' : 'OK'), 'alerts' => $alerts];
    }

    private function eventAlerts(Connection $connection, callable $add, array $backlog): void
    {
        $events = ExternalEvent::query()->where('connection_id', $connection->id);
        $grace = Carbon::now()->subMinutes((int) config('optientry.integration.orphan_grace_minutes', 120));

        $orphans = (clone $events)->where('status', ExternalEvent::NEEDS_REVIEW)->where('last_error_code', 'INVOICE_NOT_FOUND')->where('received_at', '<=', $grace);
        if (($n = (clone $orphans)->count()) > 0) {
            $add('MISSING_RECORDED_EVENT', 'ALERT', ['count' => $n, 'oldest_received_at' => Carbon::parse((clone $orphans)->min('received_at'))->toIso8601String()]);
        }

        $quarantined = (clone $events)->where('status', ExternalEvent::NEEDS_REVIEW)->whereIn('last_error_code', ['SNAPSHOT_BASIS_REVIEW', 'SOURCE_REVIEW_REQUIRED']);
        if (($n = (clone $quarantined)->count()) > 0) {
            $add('QUARANTINED_EVENTS', 'WARN', ['count' => $n]);
        }

        $conflicts = DB::table('external_event_conflicts')->where('tenant_id', $connection->tenant_id)->whereNull('resolved_at')
            ->whereIn('external_event_id', (clone $events)->select('id'));
        if (($n = $conflicts->count()) > 0) {
            $add('OPEN_CONFLICTS', 'WARN', ['count' => $n]);
        }

        if (($n = (clone $events)->where('status', ExternalEvent::DEAD_LETTER)->count()) > 0) {
            $add('DEAD_LETTERS', 'ALERT', ['count' => $n]);
        }

        if (($backlog['stalled'] ?? false) === true) {
            $add('BACKLOG_STALLED', 'WARN', ['due' => $backlog['due'] ?? null, 'oldest_due_age_seconds' => $backlog['oldest_due_age_seconds'] ?? null]);
        }
    }

    private function linked(Connection $connection): bool
    {
        return (string) Tenant::query()->whereKey($connection->tenant_id)->value('optinexus_tenant_id') !== '';
    }
}
