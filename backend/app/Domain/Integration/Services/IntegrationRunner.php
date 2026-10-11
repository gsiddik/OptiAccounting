<?php

namespace App\Domain\Integration\Services;

use App\Domain\Entitlement\Services\ModuleAvailability;
use App\Domain\Identity\Models\Tenant;
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
    ) {}

    /** @return array{connections:int,pulled:int,received:int,posted:int} */
    public function pullDue(): array
    {
        $totals = ['connections' => 0, 'pulled' => 0, 'received' => 0, 'posted' => 0];
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
                }
            });
        }

        return $totals;
    }

    /** @return array{events:int,posted:int} due RECEIVED events and FAILED events past their backoff, of ACTIVE connections only (a paused connection posts nothing) */
    public function processDue(): array
    {
        $totals = ['events' => 0, 'posted' => 0];
        $tenants = $this->context->withoutTenantScope(fn () => ExternalEvent::query()->due()
            ->whereHas('connection', fn ($q) => $q->where('status', Connection::ACTIVE))->distinct()->pluck('tenant_id'));

        foreach ($tenants as $tenantId) {
            $this->context->runAs($tenantId, function () use (&$totals) {
                if (! $this->servable()) {
                    return;
                }
                $events = ExternalEvent::query()->due()->whereHas('connection', fn ($q) => $q->where('status', Connection::ACTIVE))
                    ->orderBy('received_at')->orderBy('sequence')->orderBy('id')->limit(self::PROCESS_BATCH)->get();
                foreach ($events as $event) {
                    $totals['events']++;
                    try {
                        $totals['posted'] += $this->processor->process($event)->status === ExternalEvent::POSTED ? 1 : 0;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        }

        return $totals;
    }

    /** The tenant in the context may be served: ACTIVE, and the integration module and feature are fully entitled. */
    private function servable(): bool
    {
        $tenant = Tenant::query()->find($this->context->tenantId());

        return $tenant !== null && $tenant->isActive() && $this->availability->writable(self::MODULE) && $this->availability->featureEnabled(self::FEATURE);
    }

    private function due(Connection $connection): bool
    {
        return $connection->last_pulled_at === null
            || $connection->last_pulled_at->copy()->addMinutes($connection->pull_interval_minutes)->subSeconds(self::SLACK_SECONDS)->lte(now());
    }
}
