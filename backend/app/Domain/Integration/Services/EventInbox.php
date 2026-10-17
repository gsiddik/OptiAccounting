<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection as Connection;
use App\Domain\Integration\Models\IntegrationGap;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only writer of `external_events`. Every canonical envelope from a connection (a pulled page today, a push endpoint or file import
 * later) enters here and is stored exactly once.
 *
 *   tenant      always the connection's; a tenant id inside the payload is never read.
 *   shape       event_id, event_type, schema_version (major.minor), occurred_at (ISO-8601), source_system and source_tenant_reference that
 *               equal the connection's. An envelope that cannot be identified or that belongs to another source is refused with a
 *               DomainException and nothing is stored (a pulled page containing it fails as a whole and its cursor stays).
 *   idempotency the same (connection, source_system, event_id) with the same content hash returns the existing row (DUPLICATE, counted);
 *               with a different hash it flags the existing row `conflict`, audits it and returns CONFLICT; the stored content never changes.
 *               The insert is `ON CONFLICT DO NOTHING` on the unique key, so parallel deliveries of one event create one row.
 *   gate        an identified event is stored even when it must not be processed: REJECTED when it predates the cutover (BEFORE_CUTOVER)
 *               or carries a money value as a JSON number (MONEY_NOT_DECIMAL_STRING); NEEDS_REVIEW when the connection's adapter does not
 *               support the event type at this schema major (UNSUPPORTED_EVENT); otherwise RECEIVED, waiting for EventProcessor.
 *   holds       (audit A17/A18/A21) an event whose payload is not an event-time snapshot (`snapshot_basis` other than `event_time`, e.g. rebuilt
 *               later from current state) waits NEEDS_REVIEW (SNAPSHOT_BASIS_REVIEW), and so does an event the source itself marked
 *               `review.required` (SOURCE_REVIEW_REQUIRED: a quarantined repair result); an event inside a gap (the connection was paused or
 *               disconnected when it happened) follows the gap policy: held for review, or refused; a payload that disagrees with the stored
 *               event holds the event NEEDS_REVIEW (PAYLOAD_CONFLICT) until a person decides, with the disputed payload kept as evidence.
 */
class EventInbox
{
    /** Keys whose value is money; a JSON number there loses precision, so only decimal strings are accepted. */
    private const MONEY_KEY = '/(amount|total|tax)$/i';

    public function __construct(private readonly AdapterRegistry $adapters, private readonly AuditService $audit) {}

    /** @param array<string,mixed> $envelope */
    public function receive(Connection $connection, array $envelope): ReceiveResult
    {
        $occurredAt = $this->validate($connection, $envelope);
        $hash = $this->hash($envelope);

        $sequence = $envelope['sequence'] ?? null;
        $sequence = is_int($sequence) || (is_string($sequence) && ctype_digit($sequence)) ? (int) $sequence : null;
        $gap = $sequence === null ? null : $this->gapOf($connection, $sequence);
        [$status, $code, $message] = $this->gate($connection, $envelope, $occurredAt, $gap);
        $now = now()->utc()->format('Y-m-d H:i:s.u').'+00';

        $inserted = DB::table('external_events')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $connection->tenant_id, 'connection_id' => $connection->id,
            'source_system' => $connection->source_system, 'event_id' => $envelope['event_id'], 'event_type' => $envelope['event_type'],
            'schema_version' => $envelope['schema_version'], 'occurred_at' => $occurredAt->copy()->utc()->format('Y-m-d H:i:s.u').'+00',
            'sequence' => $sequence, 'gap_id' => $gap?->id,
            'envelope' => json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'content_hash' => $hash, 'status' => $status, 'last_error_code' => $code, 'last_error' => $message,
            'received_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $event = ExternalEvent::query()->where('connection_id', $connection->id)->where('source_system', $connection->source_system)
            ->where('event_id', $envelope['event_id'])->firstOrFail();
        if ($inserted === 1) {
            return new ReceiveResult($event, ReceiveResult::CREATED);
        }

        if ($event->content_hash === $hash) {
            $event->increment('duplicate_count');

            return new ReceiveResult($event->refresh(), ReceiveResult::DUPLICATE);
        }

        return $this->quarantine($event, $connection, $envelope, $hash);
    }

    /**
     * A different payload for a known event id never replaces the stored one (the first is the evidence) and never reaches the books on its own:
     * the disputed payload is kept, the event is flagged and, when it was not posted yet, held until a person decides (ConflictService). A posted
     * event stays as it is; the open conflict is the incident that asks for a review.
     */
    private function quarantine(ExternalEvent $event, Connection $connection, array $envelope, string $hash): ReceiveResult
    {
        DB::transaction(function () use ($event, $connection, $envelope, $hash) {
            $locked = ExternalEvent::query()->lockForUpdate()->findOrFail($event->id);
            $fresh = DB::table('external_event_conflicts')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $connection->tenant_id, 'external_event_id' => $locked->id, 'received_hash' => $hash,
                'envelope' => json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'received_at' => now()->utc()->format('Y-m-d H:i:s.u').'+00', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $holdable = in_array($locked->status, [ExternalEvent::RECEIVED, ExternalEvent::VALIDATED, ExternalEvent::FAILED], true);
            $locked->forceFill(['conflict' => true]);
            if ($holdable) {
                $locked->forceFill([
                    'status' => ExternalEvent::NEEDS_REVIEW, 'next_attempt_at' => null, 'last_error_code' => 'PAYLOAD_CONFLICT',
                    'last_error' => 'The source sent a different payload for this event id; nothing is posted until a person decides.',
                ]);
            }
            $locked->save();
            if ($fresh === 1) {
                $this->audit->record('accounting.integration.event.conflict', 'external_event', $locked->id, null, [
                    'event_id' => $locked->event_id, 'event_type' => $locked->event_type, 'stored_hash' => $locked->content_hash, 'received_hash' => $hash,
                    'status' => $locked->status, 'held' => $holdable,
                ], $connection->tenant_id);
            }
        });

        return new ReceiveResult($event->refresh(), ReceiveResult::CONFLICT);
    }

    /** The gap an event of this connection falls in (it happened while the connection was paused or disconnected), or null. */
    private function gapOf(Connection $connection, int $sequence): ?IntegrationGap
    {
        return IntegrationGap::query()->where('connection_id', $connection->id)->where('sequence_at_open', '<', $sequence)
            ->where(fn ($q) => $q->where('status', IntegrationGap::OPEN)->orWhere('ceiling_sequence', '>=', $sequence))
            ->orderByDesc('opened_at')->first();
    }

    /** @return Carbon the event's occurred_at */
    private function validate(Connection $connection, array $envelope): Carbon
    {
        $invalid = [];
        $text = fn (string $key, int $max) => is_string($envelope[$key] ?? null) && $envelope[$key] !== '' && strlen($envelope[$key]) <= $max;

        if (! $text('event_id', 100)) {
            $invalid[] = 'event_id';
        }
        if (! $text('event_type', 120) || ! preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $envelope['event_type'])) {
            $invalid[] = 'event_type';
        }
        if (! $text('schema_version', 10) || ! preg_match('/^\d+\.\d+$/', $envelope['schema_version'])) {
            $invalid[] = 'schema_version';
        }
        $occurredAt = null;
        if (is_string($envelope['occurred_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $envelope['occurred_at'])) {
            try {
                $occurredAt = Carbon::parse($envelope['occurred_at']);
            } catch (Throwable) {
                // reported below with the other invalid fields
            }
        }
        if ($occurredAt === null) {
            $invalid[] = 'occurred_at';
        }
        foreach (['payload', 'dimensions', 'source'] as $object) {
            if (array_key_exists($object, $envelope) && ! is_array($envelope[$object])) {
                $invalid[] = $object;
            }
        }
        if ($invalid !== []) {
            throw new DomainException('The event envelope is invalid: '.implode(', ', $invalid).'.', 'INVALID_ENVELOPE', 422, ['fields' => $invalid]);
        }

        if (($envelope['source_system'] ?? null) !== $connection->source_system || (string) ($envelope['source_tenant_reference'] ?? '') !== (string) $connection->source_tenant_reference) {
            throw new DomainException('The event does not belong to the source of this connection.', 'ENVELOPE_SOURCE_MISMATCH', 422, ['event_id' => $envelope['event_id']]);
        }

        return $occurredAt;
    }

    /** @return array{0:string,1:?string,2:?string} status, error code, message */
    private function gate(Connection $connection, array $envelope, Carbon $occurredAt, ?IntegrationGap $gap): array
    {
        if ($connection->cutover_policy !== Connection::HISTORICAL_BACKFILL && $connection->cutover_date !== null) {
            $localDate = $occurredAt->copy()->setTimezone($connection->tenant->timezone)->toDateString();
            if ($localDate < $connection->cutover_date->toDateString()) {
                return [ExternalEvent::REJECTED, 'BEFORE_CUTOVER', "Occurred on {$localDate}, before the cutover date {$connection->cutover_date->toDateString()}."];
            }
        }

        if (($path = $this->numericMoney($envelope['payload'] ?? [], 'payload')) !== null) {
            return [ExternalEvent::REJECTED, 'MONEY_NOT_DECIMAL_STRING', "Money must be a decimal string, not a JSON number ({$path})."];
        }

        [$major] = explode('.', $envelope['schema_version']);
        $adapter = $this->adapters->for($connection->source_system);
        if ($adapter !== null && ! $adapter->supports($envelope['event_type'], (int) $major)) {
            return [ExternalEvent::NEEDS_REVIEW, 'UNSUPPORTED_EVENT', "The {$connection->source_system} adapter does not support {$envelope['event_type']} at schema major {$major}."];
        }

        // Refusals of the gap policy come first: whatever the source marked, an event the policy refuses is never booked.
        if ($gap !== null && $gap->policy === 'IGNORE_GAP') {
            return [ExternalEvent::REJECTED, 'GAP_IGNORED', 'Happened while the connection was paused or disconnected; the gap policy is to ignore it.'];
        }
        if ($gap !== null && $gap->policy === 'START_NEW_CUTOVER') {
            return [ExternalEvent::REJECTED, 'GAP_NEW_CUTOVER', 'Happened before the new cutover date set when the connection was switched on again.'];
        }

        // The source quarantined this event itself (ecosystem audit A17: it was rebuilt later from current state by the source's bounded repair).
        // It waits for a person, event by event; it is not part of a gap's batch decision either, because its reason is not the gap's.
        $review = $envelope['review'] ?? null;
        if (is_array($review) && ($review['required'] ?? null) === true) {
            $reason = is_string($review['reason'] ?? null) ? Str::limit($review['reason'], 80, '') : 'no reason given';

            return [ExternalEvent::NEEDS_REVIEW, 'SOURCE_REVIEW_REQUIRED', "The source marked this event for review before booking ({$reason}); a person has to decide, it is never booked automatically."];
        }

        if ($gap !== null) {
            return $gap->policy === 'BACKFILL_GAP'
                ? [ExternalEvent::NEEDS_REVIEW, 'GAP_BACKFILL_REVIEW', 'Happened while the connection was paused or disconnected; review it, then process it as a backfill.']
                : [ExternalEvent::NEEDS_REVIEW, 'GAP_REVIEW', 'Happened while the connection was paused or disconnected; a person has to decide before anything is booked.'];
        }

        $basis = $envelope['payload']['snapshot_basis'] ?? null;
        if (is_string($basis) && $basis !== 'event_time') {
            return [ExternalEvent::NEEDS_REVIEW, 'SNAPSHOT_BASIS_REVIEW', "The payload is a {$basis} snapshot, not the state when the event happened; review it before booking."];
        }

        return [ExternalEvent::RECEIVED, null, null];
    }

    /** The path of the first JSON number found under a money key, or null. */
    private function numericMoney(mixed $node, string $path): ?string
    {
        if (! is_array($node)) {
            return null;
        }
        foreach ($node as $key => $value) {
            $here = "{$path}.{$key}";
            if (is_string($key) && preg_match(self::MONEY_KEY, $key) && (is_int($value) || is_float($value))) {
                return $here;
            }
            if (($found = $this->numericMoney($value, $here)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** SHA-256 of the envelope in canonical form (keys sorted at every level); `sequence` is transport metadata, not content. */
    private function hash(array $envelope): string
    {
        unset($envelope['sequence']);

        return hash('sha256', json_encode($this->canonical($envelope), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($item) => $this->canonical($item), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
