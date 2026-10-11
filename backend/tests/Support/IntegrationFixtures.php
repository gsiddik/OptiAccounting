<?php

namespace Tests\Support;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Contracts\IntegrationAdapter;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\AdapterRegistry;
use App\Domain\Integration\Services\EventInbox;
use App\Domain\Integration\Services\ProcessOutcome;
use App\Domain\Integration\Services\ReceiveResult;
use Closure;
use Illuminate\Support\Str;

/** Test helpers for the OA6 integration core; combine with Fixtures and AccountingFixtures (inTenant) and, for tokens, PayablesFixtures. */
trait IntegrationFixtures
{
    protected const IT = '/api/v1/app/integration';

    /** A connection built directly (any status), complete and cut over on 2026-01-01 unless told otherwise. */
    protected function connectionFor(Tenant $tenant, array $override = []): IntegrationConnection
    {
        return $this->inTenant($tenant, function () use ($override) {
            $connection = new IntegrationConnection;
            $connection->forceFill($override + [
                'name' => 'OptiFleet', 'source_system' => 'optifleet', 'source_tenant_reference' => 'fleet-co-1', 'status' => IntegrationConnection::ACTIVE,
                'base_url' => 'https://fleet.example.test', 'credential' => 'feed-secret-123', 'cutover_policy' => IntegrationConnection::OPENING_BALANCE_ONLY,
                'cutover_date' => '2026-01-01', 'pull_interval_minutes' => 5,
            ])->save();

            return $connection;
        });
    }

    /** @return array<string,mixed> a canonical envelope of the default connection (money as decimal strings) */
    protected function envelope(array $override = []): array
    {
        return $override + [
            'event_id' => 'evt-'.Str::uuid(), 'event_type' => 'workshop_invoice.recorded', 'schema_version' => '1.0', 'source_system' => 'optifleet',
            'source_tenant_reference' => 'fleet-co-1', 'occurred_at' => '2026-03-10T08:00:00+07:00', 'correlation_id' => 'corr-1',
            'source' => ['type' => 'workshop_invoice', 'id' => 'wi-1', 'number' => 'WI-001'], 'dimensions' => ['vehicle' => 'veh-1'],
            'payload' => ['total_amount' => '1110000.00', 'tax' => '110000.00', 'currency' => 'IDR'],
        ];
    }

    protected function receive(Tenant $tenant, IntegrationConnection $connection, array $envelope): ReceiveResult
    {
        return $this->inTenant($tenant, fn () => app(EventInbox::class)->receive($connection, $envelope));
    }

    /** A stored RECEIVED event of $connection. */
    protected function storedEvent(Tenant $tenant, IntegrationConnection $connection, array $override = []): ExternalEvent
    {
        return $this->receive($tenant, $connection, $this->envelope($override))->event;
    }

    /**
     * Registers a fake adapter for $source in the container (the real registry is empty until a source adapter is tagged). $handler receives
     * the event and returns a ProcessOutcome (default: nothing to link); $supports receives (type, major) (default: major 1 only).
     * The returned adapter counts its `calls`.
     */
    protected function fakeAdapter(?Closure $handler = null, ?Closure $supports = null, string $source = 'optifleet'): IntegrationAdapter
    {
        $adapter = new class($handler, $supports, $source) implements IntegrationAdapter
        {
            public int $calls = 0;

            public function __construct(private readonly ?Closure $handler, private readonly ?Closure $supports, private readonly string $source) {}

            public function sourceSystem(): string
            {
                return $this->source;
            }

            public function supports(string $eventType, int $major): bool
            {
                return $this->supports !== null ? ($this->supports)($eventType, $major) : $major === 1;
            }

            public function process(ExternalEvent $event): ProcessOutcome
            {
                $this->calls++;

                return $this->handler !== null ? ($this->handler)($event) : new ProcessOutcome;
            }
        };
        $this->app->instance(AdapterRegistry::class, new AdapterRegistry([$adapter]));

        return $adapter;
    }
}
