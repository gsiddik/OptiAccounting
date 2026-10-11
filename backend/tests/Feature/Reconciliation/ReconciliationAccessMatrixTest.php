<?php

namespace Tests\Feature\Reconciliation;

use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\ModuleAccessMatrix;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\ReconciliationFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/**
 * OA5: one sweep over every reconciliation route of ACCOUNTING_REPORTING (the reporting and closing routes of the same module have their own
 * matrices) for permission, entitlement state and tenant ownership. `{type}` names a kind of reconciliation, not a tenant record, so the
 * cross-tenant sweep covers the exception routes, where an id is a record.
 */
class ReconciliationAccessMatrixTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, ModuleAccessMatrix {
        routes as private everyReportingRoute;
    }
    use PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, TaxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->reconTenant('alpha');
        $this->bravo = $this->reconTenant('bravo');
        $this->admin = $this->memberToken($this->alpha);

        $this->as($this->admin);
        $this->postedInvoice($this->vendor($this->alpha, 'V1'));
        $this->strayLine($this->alpha, '2110', 'CREDIT', '5000', '2026-03-25');
        $exception = $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-03-31'])->assertCreated()->json('exception.id');
        $this->ids = ['type' => 'ap-gl', 'exception' => $exception];
    }

    protected function matrixModules(): array
    {
        return ['ACCOUNTING_REPORTING'];
    }

    protected function matrixFeatures(): array
    {
        return ['RECONCILIATION'];
    }

    protected function fingerprintTables(): array
    {
        return ['reconciliation_runs', 'reconciliation_exceptions', 'journal_entries', 'journal_lines', 'audit_logs'];
    }

    /** Only the reconciliation routes of the module. */
    protected function routes(): array
    {
        return array_values(array_filter($this->everyReportingRoute(), fn ($route) => str_contains($route['uri'], '/reconciliation')));
    }

    protected function pinnedPermissions(): array
    {
        return [
            'GET reconciliations' => 'accounting.reconciliation.view',
            'GET reconciliations/export' => 'accounting.report.export',
            'GET reconciliations/runs' => 'accounting.reconciliation.view',
            'GET reconciliations/{type}' => 'accounting.reconciliation.view',
            'GET reconciliations/{type}/export' => 'accounting.report.export',
            'POST reconciliations/{type}/run' => 'accounting.reconciliation.run',
            'GET reconciliation-exceptions' => 'accounting.reconciliation.view',
            'POST reconciliation-exceptions/{exception}/assign' => 'accounting.reconciliation.exception.manage',
            'POST reconciliation-exceptions/{exception}/explain' => 'accounting.reconciliation.exception.manage',
            'POST reconciliation-exceptions/{exception}/resolve' => 'accounting.reconciliation.exception.manage',
        ];
    }

    public function test_another_tenants_records_are_not_found_on_every_route_and_nothing_of_theirs_changes(): void
    {
        $intruder = $this->memberToken($this->bravo);
        $records = array_values(array_filter($this->routes(), fn ($r) => str_contains($r['uri'], '{exception}')));
        $this->assertCount(3, $records);
        $before = $this->fingerprint();

        $outcome = $this->sweep($intruder, $records);

        $this->assertSame([], $this->violations($outcome, fn ($o) => $o === '404:RECONCILIATION_EXCEPTION_NOT_FOUND'), json_encode($outcome));
        $this->assertSame($before, $this->fingerprint(), 'a cross-tenant call left a trace in the other tenant');
    }

    public function test_the_views_work_for_a_read_only_subledger_module_and_the_exports_follow_the_same_gate(): void
    {
        $this->entitle('ACCOUNTING_AP', ['state' => 'READ_ONLY']);

        $this->as($this->admin)->getJson(self::RC.'/reconciliations/ap-gl?as_of=2026-03-31')->assertOk()->assertJsonPath('status', 'MISMATCH');
        $this->getJson(self::RC.'/reconciliations?as_of=2026-03-31')->assertOk();
        $this->entitle('ACCOUNTING_AP', ['state' => 'DISABLED']);
        $this->as($this->admin)->getJson(self::RC.'/reconciliations/ap-gl?as_of=2026-03-31')->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
        $this->as($this->admin)->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-03-31'])->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
        $this->as($this->admin)->getJson(self::RC.'/reconciliations/ap-gl/export?as_of=2026-03-31')->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
        $this->as($this->admin)->getJson(self::RC.'/reconciliations/unknown?as_of=2026-03-31')->assertStatus(404)->assertJsonPath('code', 'RECONCILIATION_TYPE_UNKNOWN');
    }
}
