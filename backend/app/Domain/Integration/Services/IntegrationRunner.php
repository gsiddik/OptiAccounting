<?php

namespace App\Domain\Integration\Services;

use App\Domain\Entitlement\Services\ModuleAvailability;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Contracts\ConnectorRequirements;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection as Connection;
use App\Support\TenantContext;
use Throwable;

/**
 * The scheduled work (`integration:pull`, `integration:process`): finds the tenants that have something to do and runs it in each tenant's
 * context. A tenant is served only while it is ACTIVE and its ACCOUNTING_INTEGRATION module and INTEGRATION_CONNECTION feature are writable
 * (a READ_ONLY or lapsed subscription stops the automatic posting exactly as it stops a user). Each connection and each event is isolated:
 * one failure is reported and the run goes on.
 */
class IntegrationRunner
{
    public const MODULE = 'ACCOUNTING_INTEGRATION';

    public const FEATURE = 'INTEGRATION_CONNECTION';

    public const PROCESS_BATCH = 200;

    /** The scheduler ticks a little before the previous pull's `last_pulled_at` plus the interval; without slack an interval equal to the tick would run every second tick. */
    private const SLACK_SECONDS = 60;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ModuleAvailability $availability,
        private readonly PullService $pulls,
        private readonly EventProcessor $processor,
        private readonly AdapterRegistry $adapters,
        private readonly ConnectionHealth $health,
    ) {}

    /**
     * Connections that OptiEntry itself holds (connector not entitled, organization not linked to OptiNexus where pairing is required) are not
     * pulled: the source keeps its events and the history stays as it is (`held`, ecosystem audit A19). A source that refused the last pull
     * is asked again only after its back-off (`feed_next_attempt_at`).
     *
     * @return array{connections:int,pulled:int,received:int,posted:int,journals:int,held:int}
     */
    public function pullDue(): array
    {
        $totals = ['connections' => 0, 'pulled' => 0, 'received' => 0, 'posted' => 0, 'journals' => 0, 'held' => 0];
        $tenants = $this->context->withoutTenantScope(fn () => Connection::query()->where('status', Connection::ACTIVE)->distinct()->pluck('tenant_id'));

        foreach ($tenants as $tenantId) {
            $this->context->runAs($tenantId, function () use (&$totals) {
                if (! $this->servable()) {
                    return;
                }
                foreach (Connection::query()->where('status', Connection::ACTIVE)->orderBy('id')->get() as $connection) {
                    if (! $this->due($connection)) {
                        continue;
                    }
                    if ($this->health->heldBy($connection) !== null) {
                        $totals['held']++;

                        continue;
                    }
                    $totals['connections']++;
                    try {
                        $summary = $this->pulls->pull($connection);
                    } catch (Throwable $e) {
                        report($e);

                        continue;
                    }
                    $totals['pulled'] += $summary['skipped'] || $summary['error'] !== null ? 0 : 1;
                    $totals['received'] += $summary['received'];
                    $totals['posted'] += $summary['posted'];
                    $totals['journals'] += $summary['journals'];
                }
            });
        }

        return $totals;
    }

    /**
     * Due RECEIVED events and FAILED events past their backoff, of ACTIVE connections only (a paused connection posts nothing).
     *
     * Each connection gets its own batch of PROCESS_BATCH events (oldest first), so a large backlog on one connection never keeps another
     * one waiting (ecosystem audit A28). A connection whose connector the tenant is not (or no longer) entitled to is not touched at all: its
     * events stay exactly as they are, nothing is posted around the entitlement, and it is counted in `held` and shown in the connection's
     * backlog figures. Inside one connection every due event is eligible alike, so no event can sit in front of the others for good.
     *
     * @return array{events:int,posted:int,journals:int,held:int}
     */
    public function processDue(): array
    {
        $totals = ['events' => 0, 'posted' => 0, 'journals' => 0, 'held' => 0];
        $tenants = $this->context->withoutTenantScope(fn () => ExternalEvent::query()->due()
            ->whereHas('connection', fn ($q) => $q->where('status', Connection::ACTIVE))->distinct()->pluck('tenant_id'));

        foreach ($tenants as $tenantId) {
            $this->context->runAs($tenantId, function () use (&$totals) {
                if (! $this->servable()) {
                    return;
                }
                $connections = Connection::query()->where('status', Connection::ACTIVE)->whereHas('events', fn ($q) => $q->due())->orderBy('id')->get();
                foreach ($connections as $connection) {
                    if ($this->heldByEntitlement($connection)) {
                        $totals['held']++;

                        continue;
                    }
                    $events = ExternalEvent::query()->due()->where('connection_id', $connection->id)
                        ->orderBy('received_at')->orderBy('sequence')->orderBy('id')->limit(self::PROCESS_BATCH)->get();
                    foreach ($events as $event) {
                        $totals['events']++;
                        try {
                            $done = $this->processor->process($event);
                            $totals['posted'] += $done->status === ExternalEvent::POSTED ? 1 : 0;
                            $totals['journals'] += $done->accounting_outcome === ProcessOutcome::JOURNAL_POSTED ? 1 : 0;
                        } catch (Throwable $e) {
                            report($e);
                        }
                    }
                }
            });
        }

        return $totals;
    }

    /** The connection's connector reports entitlement problems for the tenant in the context: the processor would leave every event of it untouched. */
    private function heldByEntitlement(Connection $connection): bool
    {
        $adapter = $this->adapters->for($connection->source_system);

        return $adapter instanceof ConnectorRequirements && $adapter->entitlementProblems() !== [];
    }

    /** The tenant in the context may be served: ACTIVE, and the integration module and feature are fully entitled. */
    private function servable(): bool
    {
        $tenant = Tenant::query()->find($this->context->tenantId());

        return $tenant !== null && $tenant->isActive() && $this->availability->writable(self::MODULE) && $this->availability->featureEnabled(self::FEATURE);
    }

    private function due(Connection $connection): bool
    {
        if ($connection->feed_next_attempt_at !== null && $connection->feed_next_attempt_at->isFuture()) {
            return false; // the source refused or asked for time (Retry-After)
        }

        return $connection->last_pulled_at === null
            || $connection->last_pulled_at->copy()->addMinutes($connection->pull_interval_minutes)->subSeconds(self::SLACK_SECONDS)->lte(now());
    }
}
