<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection as Connection;
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

        [$status, $code, $message] = $this->gate($connection, $envelope, $occurredAt);
        $now = now()->utc()->format('Y-m-d H:i:s.u').'+00';
        $sequence = $envelope['sequence'] ?? null;

        $inserted = DB::table('external_events')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $connection->tenant_id, 'connection_id' => $connection->id,
            'source_system' => $connection->source_system, 'event_id' => $envelope['event_id'], 'event_type' => $envelope['event_type'],
            'schema_version' => $envelope['schema_version'], 'occurred_at' => $occurredAt->copy()->utc()->format('Y-m-d H:i:s.u').'+00',
            'sequence' => is_int($sequence) || (is_string($sequence) && ctype_digit($sequence)) ? (int) $sequence : null,
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

        $event->forceFill(['conflict' => true])->save();
        $this->audit->record('accounting.integration.event.conflict', 'external_event', $event->id, null, [
            'event_id' => $event->event_id, 'event_type' => $event->event_type, 'stored_hash' => $event->content_hash, 'received_hash' => $hash,
        ], $connection->tenant_id);

        return new ReceiveResult($event, ReceiveResult::CONFLICT);
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
    private function gate(Connection $connection, array $envelope, Carbon $occurredAt): array
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
