<?php

namespace Tests\Feature\Integration;

use App\Domain\Integration\Models\IntegrationConnection;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\ModuleAccessMatrix;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/**
 * OA6: one sweep over every integration route (ACCOUNTING_INTEGRATION) for permission, entitlement state, READ_ONLY and tenant ownership.
 * The connection under test is a DRAFT one, so mutating routes never reach a remote source.
 */
class IntegrationAccessMatrixTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, ModuleAccessMatrix, PayablesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->tenant('alpha');
        $this->bravo = $this->tenant('bravo');
        $this->admin = $this->memberToken($this->alpha);

        $connection = $this->connectionFor($this->alpha, ['status' => IntegrationConnection::DRAFT, 'base_url' => null, 'credential' => null, 'source_tenant_reference' => null, 'cutover_date' => null]);
        $active = $this->connectionFor($this->alpha, ['source_tenant_reference' => 'fleet-co-1']);
        $this->ids = ['connection' => $connection->id, 'event' => $this->storedEvent($this->alpha, $active)->id];
    }

    protected function prefix(): string
    {
        return 'api/v1/app/integration/';
    }

    protected function matrixModules(): array
    {
        return ['ACCOUNTING_INTEGRATION'];
    }

    protected function matrixFeatures(): array
    {
        return ['INTEGRATION_CONNECTION'];
    }

    protected function fingerprintTables(): array
    {
        return ['integration_connections', 'external_events', 'external_dimensions', 'external_source_links', 'audit_logs'];
    }

    protected function pinnedPermissions(): array
    {
        return [
            'GET connections' => 'accounting.integration.view',
            'POST connections' => 'accounting.integration.manage',
            'GET connections/{connection}' => 'accounting.integration.view',
            'PATCH connections/{connection}' => 'accounting.integration.manage',
            'POST connections/{connection}/activate' => 'accounting.integration.manage',
            'POST connections/{connection}/pause' => 'accounting.integration.manage',
            'POST connections/{connection}/resume' => 'accounting.integration.manage',
            'POST connections/{connection}/disconnect' => 'accounting.integration.manage',
            'POST connections/{connection}/sync-now' => 'accounting.integration.manage',
            'GET connections/{connection}/summary' => 'accounting.integration.view',
            'GET events' => 'accounting.integration.view',
            'GET events/{event}' => 'accounting.integration.view',
            'POST events/{event}/retry' => 'accounting.integration.retry',
            'GET dimensions' => 'accounting.integration.view',
            'GET source-links' => 'accounting.integration.view',
        ];
    }

    /** Records are looked up by the services (not by route binding), so the refusal carries the domain code. */
    public function test_another_tenants_records_are_not_found_on_every_route_and_nothing_of_theirs_changes(): void
    {
        $bound = array_values(array_filter($this->routes(), fn ($r) => $r['bound']));
        $this->assertCount(10, $bound);
        $before = $this->fingerprint();

        $outcome = $this->sweep($this->memberToken($this->bravo), $bound);

        $bad = $this->violations($outcome, fn ($o, $label) => $o === (str_contains($label, '{event}') ? '404:EVENT_NOT_FOUND' : '404:CONNECTION_NOT_FOUND'));
        $this->assertSame([], $bad, json_encode($bad));
        $this->assertSame($before, $this->fingerprint(), 'a cross-tenant call left a trace in the other tenant');
    }
}
