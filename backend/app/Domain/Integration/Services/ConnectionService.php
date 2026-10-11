<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Contracts\ConnectorRequirements;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection as Connection;
use App\Domain\Shared\DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lifecycle of an integration connection. DRAFT, CONFIGURING and READY follow the configuration by themselves (nothing configured, partly
 * configured, complete: credential, base URL, source tenant reference and cutover date); a person then moves it with activate / pause /
 * resume / disconnect. ERROR is set by the pull when the source keeps failing and is left with `resume` once the cause is fixed. The map in
 * TRANSITIONS is the only authority on allowed moves.
 *
 * Safety rules: the credential is write-only (never returned, never audited); activation needs a cutover date so activating never back-posts
 * history; once a connection has received events its source tenant reference and cutover cannot change; HISTORICAL_BACKFILL is not
 * implemented and is refused. A DISCONNECTED connection is reconfigured (and thereby reconnected to CONFIGURING or READY) through `update`,
 * which is how the same source tenant is connected again: the unique key on (tenant, source system, source tenant reference) allows one
 * connection row per source tenant. The gap policy is stored for that case; applying it is not implemented.
 *
 * A source whose adapter implements ConnectorRequirements adds to this: its `settings` (references to the tenant's own setup, merged key by
 * key and validated by the adapter, null removes a key) are part of what an ACTIVE connection needs, and activating or resuming also needs
 * the tenant to be entitled to the connector. A source without such an adapter accepts no settings.
 */
class ConnectionService
{
    public const TRANSITIONS = [
        Connection::DRAFT => [Connection::CONFIGURING, Connection::READY, Connection::DISCONNECTED],
        Connection::CONFIGURING => [Connection::DRAFT, Connection::READY, Connection::DISCONNECTED],
        Connection::READY => [Connection::DRAFT, Connection::CONFIGURING, Connection::ACTIVE, Connection::DISCONNECTED],
        Connection::ACTIVE => [Connection::PAUSED, Connection::ERROR, Connection::DISCONNECTED],
        Connection::PAUSED => [Connection::ACTIVE, Connection::DISCONNECTED],
        Connection::ERROR => [Connection::ACTIVE, Connection::PAUSED, Connection::DISCONNECTED],
        Connection::DISCONNECTED => [Connection::DRAFT, Connection::CONFIGURING, Connection::READY],
    ];

    /** Consecutive failed pulls after which an ACTIVE connection becomes ERROR. */
    public const MAX_PULL_FAILURES = 5;

    private const EDITABLE = ['name', 'source_tenant_reference', 'base_url', 'credential', 'cutover_policy', 'cutover_date', 'gap_policy', 'pull_interval_minutes', 'settings'];

    /** Once an event was received these identify what was received and can no longer change. */
    private const LOCKED_BY_EVENTS = ['source_tenant_reference', 'cutover_policy', 'cutover_date'];

    public function __construct(private readonly AuditService $audit, private readonly AdapterRegistry $adapters) {}

    /** @param array{status?:?string,source_system?:?string} $filter */
    public function query(array $filter = []): Builder
    {
        return Connection::query()
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['source_system'] ?? null, fn ($q, $v) => $q->where('source_system', $v))
            ->orderBy('name')->orderBy('id');
    }

    /** The connection of this tenant, or 404 (another tenant's connection does not exist for the caller). */
    public function find(string $id, bool $lock = false): Connection
    {
        $connection = Str::isUuid($id) ? Connection::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id) : null;

        return $connection ?? throw new DomainException('Integration connection not found.', 'CONNECTION_NOT_FOUND', 404);
    }

    public function create(array $data): Connection
    {
        $this->assertPolicies($data);

        try {
            return DB::transaction(function () use ($data) {
                $connection = new Connection;
                $connection->forceFill([
                    'source_system' => $data['source_system'], 'status' => Connection::DRAFT, 'cutover_policy' => Connection::OPENING_BALANCE_ONLY,
                    'gap_policy' => 'REQUIRES_REVIEW', 'pull_interval_minutes' => 5,
                ]);
                $this->apply($connection, $data);
                $connection->status = $this->configuredState($connection);
                $connection->save();
                $this->audit->record('accounting.integration.connection.created', 'integration_connection', $connection->id, null, $this->snapshot($connection));

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->exists();
        }
    }

    public function update(string $id, array $data): Connection
    {
        $this->assertPolicies($data);

        try {
            return DB::transaction(function () use ($id, $data) {
                $connection = $this->find($id, lock: true);
                $before = $this->snapshot($connection);
                $this->assertNotLocked($connection, $data);
                $this->apply($connection, $data);

                if (in_array($connection->status, [Connection::ACTIVE, Connection::PAUSED, Connection::ERROR], true)) {
                    $this->assertComplete($connection);
                } else {
                    $connection->status = $this->configuredState($connection);
                }
                $connection->save();
                $this->audit->record('accounting.integration.connection.updated', 'integration_connection', $connection->id, $before, $this->snapshot($connection));

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->exists();
        }
    }

    public function activate(string $id): Connection
    {
        return $this->move($id, Connection::ACTIVE, [Connection::READY], 'activated', function (Connection $c) {
            $this->assertComplete($c);
            $this->assertEntitled($c);
        });
    }

    public function pause(string $id): Connection
    {
        return $this->move($id, Connection::PAUSED, [Connection::ACTIVE, Connection::ERROR], 'paused');
    }

    /** Back to ACTIVE after a pause or after the pull gave up (ERROR); the failure counter starts again. */
    public function resume(string $id): Connection
    {
        return $this->move($id, Connection::ACTIVE, [Connection::PAUSED, Connection::ERROR], 'resumed', function (Connection $c) {
            $this->assertComplete($c);
            $this->assertEntitled($c);
        });
    }

    public function disconnect(string $id): Connection
    {
        return $this->move($id, Connection::DISCONNECTED, array_diff(Connection::STATUSES, [Connection::DISCONNECTED]), 'disconnected');
    }

    /**
     * Success bookkeeping of one pulled page; call it in the transaction that stored the page's events, so the cursor moves if and only if
     * the events are stored.
     */
    public function pullSucceeded(Connection $connection, ?string $cursor): void
    {
        $connection->forceFill([
            'last_cursor' => $cursor ?? $connection->last_cursor, 'last_pulled_at' => now(), 'last_error' => null, 'consecutive_failures' => 0,
        ])->save();
    }

    /** A pull attempt that stored nothing: keep the cursor, remember why, and give up (ERROR) after MAX_PULL_FAILURES in a row. */
    public function pullFailed(string $id, string $reason): Connection
    {
        return DB::transaction(function () use ($id, $reason) {
            $connection = $this->find($id, lock: true);
            $failures = $connection->consecutive_failures + 1;
            $connection->forceFill(['last_pulled_at' => now(), 'last_error' => Str::limit($reason, 500, ''), 'consecutive_failures' => $failures]);
            if ($failures >= self::MAX_PULL_FAILURES && $connection->status === Connection::ACTIVE) {
                $connection->status = Connection::ERROR;
                $this->audit->record('accounting.integration.connection.errored', 'integration_connection', $connection->id, ['status' => Connection::ACTIVE], [
                    'status' => Connection::ERROR, 'consecutive_failures' => $failures, 'last_error' => $connection->last_error,
                ], $connection->tenant_id);
            }
            $connection->save();

            return $connection;
        });
    }

    /** The API form; the credential is never part of it. */
    public function present(Connection $connection): array
    {
        return [
            'id' => $connection->id, 'name' => $connection->name, 'source_system' => $connection->source_system,
            'source_tenant_reference' => $connection->source_tenant_reference, 'status' => $connection->status, 'base_url' => $connection->base_url,
            'has_credential' => $connection->credential !== null, 'cutover_policy' => $connection->cutover_policy,
            'cutover_date' => $connection->cutover_date?->toDateString(), 'gap_policy' => $connection->gap_policy,
            'pull_interval_minutes' => $connection->pull_interval_minutes, 'settings' => $connection->settings ?: new \stdClass, 'last_cursor' => $connection->last_cursor,
            'last_pulled_at' => $connection->last_pulled_at?->toIso8601String(), 'last_error' => $connection->last_error,
            'consecutive_failures' => $connection->consecutive_failures,
            'created_at' => $connection->created_at?->toIso8601String(), 'updated_at' => $connection->updated_at?->toIso8601String(),
        ];
    }

    /**
     * "Received vs processed" for one connection: events by status, how many were delivered again (duplicates) or with different content
     * (conflicts), and where the pull stands. Source totals are not compared yet, so a MISSING event cannot be detected here.
     */
    public function summary(string $id): array
    {
        $connection = $this->find($id);
        $events = ExternalEvent::query()->where('connection_id', $connection->id);
        $counts = array_fill_keys(ExternalEvent::STATUSES, 0);
        foreach ((clone $events)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') as $status => $n) {
            $counts[$status] = (int) $n;
        }

        return [
            'connection_id' => $connection->id, 'status' => $connection->status, 'received' => array_sum($counts), 'posted' => $counts[ExternalEvent::POSTED],
            'counts' => $counts, 'duplicates' => (int) (clone $events)->sum('duplicate_count'), 'conflicts' => (clone $events)->where('conflict', true)->count(),
            'last_cursor' => $connection->last_cursor, 'last_pulled_at' => $connection->last_pulled_at?->toIso8601String(),
            'last_error' => $connection->last_error, 'consecutive_failures' => $connection->consecutive_failures,
        ];
    }

    /** @return list<string> what an ACTIVE connection needs and this one lacks */
    public function missingForActivation(Connection $connection): array
    {
        $missing = array_keys(array_filter([
            'credential' => $connection->credential, 'base_url' => $connection->base_url,
            'source_tenant_reference' => $connection->source_tenant_reference, 'cutover_date' => $connection->cutover_date,
        ], fn ($value) => $value === null || $value === ''));

        return [...$missing, ...array_map(fn (string $key) => "settings.{$key}", $this->requirements($connection->source_system)?->missingSettings($connection->settings ?? []) ?? [])];
    }

    private function assertComplete(Connection $connection): void
    {
        if (($missing = $this->missingForActivation($connection)) !== []) {
            throw new DomainException('The connection is incomplete: '.implode(', ', $missing).' must be set.', 'CONNECTION_INCOMPLETE', 422, ['missing' => $missing]);
        }
    }

    /** A connector the tenant is not (or no longer) entitled to cannot be switched on; the adapter names what is missing. */
    private function assertEntitled(Connection $connection): void
    {
        if (($problems = $this->requirements($connection->source_system)?->entitlementProblems() ?? []) !== []) {
            throw new DomainException('This connector is not available to the organization: '.implode('; ', $problems).'.', 'CONNECTOR_NOT_ENTITLED', 403, ['reasons' => $problems]);
        }
    }

    /** DRAFT (nothing configured), CONFIGURING (partly) or READY (complete). */
    private function configuredState(Connection $connection): string
    {
        $missing = $this->missingForActivation($connection);
        $everything = 4 + count($this->requirements($connection->source_system)?->missingSettings([]) ?? []);

        return match (true) {
            $missing === [] => Connection::READY,
            count($missing) === $everything => Connection::DRAFT,
            default => Connection::CONFIGURING,
        };
    }

    private function requirements(string $sourceSystem): ?ConnectorRequirements
    {
        $adapter = $this->adapters->for($sourceSystem);

        return $adapter instanceof ConnectorRequirements ? $adapter : null;
    }

    /** The stored settings with the ones sent merged in: each key sent is validated by the source's adapter, null removes it. */
    private function mergedSettings(Connection $connection, mixed $sent): array
    {
        $current = $connection->settings ?? [];
        $sent = is_array($sent) ? $sent : [];
        if ($sent === []) {
            return $current;
        }
        $adapter = $this->requirements($connection->source_system)
            ?? throw new DomainException('This source system has no settings.', 'CONNECTION_SETTINGS_UNSUPPORTED', 422, ['field' => 'settings']);
        $removed = array_keys(array_filter($sent, fn ($value) => $value === null));

        return [...array_diff_key($current, array_flip($removed)), ...$adapter->cleanSettings(array_filter($sent, fn ($value) => $value !== null))];
    }

    private function move(string $id, string $to, array $from, string $action, ?callable $guard = null): Connection
    {
        return DB::transaction(function () use ($id, $to, $from, $action, $guard) {
            $connection = $this->find($id, lock: true);
            $guard?->__invoke($connection);
            if (! in_array($connection->status, $from, true) || ! in_array($to, self::TRANSITIONS[$connection->status], true)) {
                throw new DomainException("A {$connection->status} connection cannot be moved to {$to} this way.", 'CONNECTION_INVALID_TRANSITION', 409, ['status' => $connection->status, 'to' => $to]);
            }
            $before = ['status' => $connection->status];
            $connection->forceFill(['status' => $to]);
            if ($to === Connection::ACTIVE) {
                $connection->forceFill(['consecutive_failures' => 0, 'last_error' => null]);
            }
            $connection->save();
            $this->audit->record("accounting.integration.connection.{$action}", 'integration_connection', $connection->id, $before, ['status' => $to]);

            return $connection;
        });
    }

    private function apply(Connection $connection, array $data): void
    {
        foreach (self::EDITABLE as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            $connection->{$field} = match ($field) {
                'name' => trim((string) $value),
                'base_url' => $value === null || trim((string) $value) === '' ? null : rtrim(trim((string) $value), '/'),
                'credential' => $value === null || $value === '' ? $connection->credential : (string) $value, // write-only: nothing sent means unchanged
                'source_tenant_reference' => $value === null || trim((string) $value) === '' ? null : trim((string) $value),
                'settings' => $this->mergedSettings($connection, $value),
                default => $value,
            };
        }
    }

    private function assertPolicies(array $data): void
    {
        if (($data['cutover_policy'] ?? null) === Connection::HISTORICAL_BACKFILL) {
            throw new DomainException('Historical backfill is not available yet; connections start from the cutover date.', 'HISTORICAL_BACKFILL_NOT_SUPPORTED', 422, ['field' => 'cutover_policy']);
        }
    }

    private function assertNotLocked(Connection $connection, array $data): void
    {
        $changed = array_filter(self::LOCKED_BY_EVENTS, fn (string $field) => array_key_exists($field, $data) && (string) ($data[$field] ?? '') !== (string) ($field === 'cutover_date' ? $connection->cutover_date?->toDateString() : $connection->{$field}));
        if ($changed !== [] && $connection->events()->exists()) {
            throw new DomainException('Events were already received on this connection, so '.implode(', ', $changed).' can no longer change.', 'CONNECTION_LOCKED', 409, ['fields' => array_values($changed)]);
        }
    }

    private function exists(): DomainException
    {
        return new DomainException('This source tenant is already connected.', 'CONNECTION_EXISTS', 409);
    }

    private function snapshot(Connection $connection): array
    {
        return [
            'name' => $connection->name, 'source_system' => $connection->source_system, 'source_tenant_reference' => $connection->source_tenant_reference,
            'status' => $connection->status, 'base_url' => $connection->base_url, 'auth_configured' => $connection->credential !== null,
            'cutover_policy' => $connection->cutover_policy, 'cutover_date' => $connection->cutover_date?->toDateString(),
            'gap_policy' => $connection->gap_policy, 'pull_interval_minutes' => $connection->pull_interval_minutes, 'settings' => $connection->settings ?? [],
        ];
    }
}
