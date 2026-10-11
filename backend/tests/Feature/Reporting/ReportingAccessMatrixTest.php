<?php

namespace Tests\Feature\Reporting;

use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\ModuleAccessMatrix;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/**
 * OA5: one sweep over every FINANCIAL_STATEMENTS route of ACCOUNTING_REPORTING (the closing and reconciliation routes of the same module have
 * their own matrices) for permission, tenant ownership and entitlement state: reports, report definitions, exports and drill-down.
 */
class ReportingAccessMatrixTest extends TestCase
{
    use AccountingFixtures, Fixtures, ModuleAccessMatrix {
        routes as private everyReportingRoute;
    }
    use PayablesFixtures, ReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->reportingTenant('alpha');
        $this->bravo = $this->reportingTenant('bravo');
        $this->admin = $this->memberToken($this->alpha);

        $this->as($this->admin);
        $id = $this->postJson(self::RP.'/report-definitions', ['statement_type' => 'PROFIT_LOSS', 'name' => 'Laba rugi'])->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => [
            ['code' => 'REV', 'label' => 'Pendapatan', 'line_type' => 'SUBTOTAL'],
            ['code' => 'REV_SALES', 'label' => 'Penjualan', 'line_type' => 'ACCOUNT', 'parent_code' => 'REV', 'balance_side' => 'CREDIT'],
        ]])->assertOk();
        $this->ids = ['definition' => $id, 'line' => (string) DB::table('report_lines')->where('report_definition_id', $id)->where('code', 'REV_SALES')->value('id')];
    }

    protected function matrixModules(): array
    {
        return ['ACCOUNTING_REPORTING'];
    }

    protected function matrixFeatures(): array
    {
        return ['FINANCIAL_STATEMENTS'];
    }

    protected function fingerprintTables(): array
    {
        return ['report_definitions', 'report_lines', 'report_line_mappings', 'journal_entries', 'journal_lines', 'audit_logs'];
    }

    /** Only the financial statement routes of the module. */
    protected function routes(): array
    {
        return array_values(array_filter($this->everyReportingRoute(), fn ($route) => $route['feature'] === 'FINANCIAL_STATEMENTS'));
    }

    /**
     * The permissions a route additionally needs inside its service, besides the one on its gate: an export also needs the view permission of the
     * report it exports. (The comparative, comparative export and drill-down name their statement in the query, so without one they stop at
     * validation; their companion rule is tested in ComparativeReportTest, ReportExportTest and DrilldownTest.)
     *
     * @return array<string,list<string>> route label => permissions
     */
    protected function companionPermissions(): array
    {
        return [
            'GET reports/account-balance/export' => ['accounting.gl.view'],
            'GET reports/balance-sheet/export' => ['accounting.reporting.balance_sheet.view'],
            'GET reports/profit-loss/export' => ['accounting.reporting.pnl.view'],
            'GET reports/cash-flow/export' => ['accounting.reporting.cash_flow.view'],
        ];
    }

    /**
     * Same contract as the shared sweep (lacking only P forbids P's routes; holding only P opens exactly P's routes at the gate), except that the
     * routes with a companion permission are opened by P together with their companions.
     */
    public function test_each_atomic_permission_guards_exactly_its_own_routes(): void
    {
        $routes = $this->routes();
        $byPermission = [];
        foreach ($routes as $route) {
            $byPermission[$route['permission']][] = $route;
        }
        $everything = DB::table('permissions')->where('scope', 'tenant')->pluck('code')->all();

        foreach ($byPermission as $permission => $group) {
            $token = $this->memberToken($this->alpha, array_values(array_diff($everything, [$permission])));
            $this->assertSame([], $this->violations($this->sweep($token, $group), fn ($o) => $o === '403:PERMISSION_DENIED'), "lacking only {$permission}");
        }

        foreach ($byPermission as $permission => $group) {
            $held = array_values(array_unique([$permission, ...array_merge([], ...array_map(fn ($r) => $this->companionPermissions()[$this->label($r)] ?? [], $group))]));
            $token = $this->memberToken($this->alpha, $held);
            $others = array_values(array_filter($routes, fn ($r) => ! in_array($r['permission'], $held, true)));

            $opened = $this->sweep($token, $group);
            $this->assertSame([], $this->violations($opened, fn ($o) => $this->passedTheGate($o)), 'holding '.implode(' + ', $held).': '.json_encode($opened));
            $this->assertSame([], $this->violations($this->sweep($token, $others), fn ($o) => str_starts_with($o, '403:PERMISSION_DENIED')), implode(' + ', $held).' must not open other routes');
        }
    }

    public function test_an_export_or_drilldown_without_the_view_permission_of_its_report_is_forbidden_before_its_filters_are_read(): void
    {
        foreach (['balance-sheet' => 'balance_sheet', 'profit-loss' => 'pnl', 'cash-flow' => 'cash_flow'] as $path => $view) {
            $only = $this->memberToken($this->alpha, ['accounting.report.export']);
            $this->as($only)->getJson(self::RP."/reports/{$path}/export?as_of=garbage&from=garbage")->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED')->assertJsonPath('details.permission', "accounting.reporting.{$view}.view");
        }
        $this->as($this->memberToken($this->alpha, ['accounting.report.export']))->getJson(self::RP.'/reports/account-balance/export?as_of=garbage')->assertForbidden()->assertJsonPath('details.permission', 'accounting.gl.view');
    }

    protected function pinnedPermissions(): array
    {
        return [
            'GET reports/account-balance' => 'accounting.gl.view',
            'GET reports/account-balance/export' => 'accounting.report.export',
            'GET reports/balance-sheet' => 'accounting.reporting.balance_sheet.view',
            'GET reports/balance-sheet/export' => 'accounting.report.export',
            'GET reports/profit-loss' => 'accounting.reporting.pnl.view',
            'GET reports/profit-loss/export' => 'accounting.report.export',
            'GET reports/cash-flow' => 'accounting.reporting.cash_flow.view',
            'GET reports/cash-flow/export' => 'accounting.report.export',
            'GET reports/comparative' => 'accounting.reporting.pnl.view',
            'GET reports/comparative/export' => 'accounting.report.export',
            'GET reports/drilldown' => 'accounting.gl.view',
            'GET report-definitions' => 'accounting.report_definition.view',
            'POST report-definitions' => 'accounting.report_definition.manage',
            'POST report-definitions/defaults' => 'accounting.report_definition.manage',
            'GET report-definitions/{definition}' => 'accounting.report_definition.view',
            'PATCH report-definitions/{definition}' => 'accounting.report_definition.manage',
            'DELETE report-definitions/{definition}' => 'accounting.report_definition.manage',
            'PUT report-definitions/{definition}/lines' => 'accounting.report_definition.manage',
            'POST report-definitions/{definition}/lines' => 'accounting.report_definition.manage',
            'PATCH report-definitions/{definition}/lines/{line}' => 'accounting.report_definition.manage',
            'DELETE report-definitions/{definition}/lines/{line}' => 'accounting.report_definition.manage',
            'PUT report-definitions/{definition}/mappings' => 'accounting.report_definition.manage',
            'GET report-definitions/{definition}/unmapped-accounts' => 'accounting.report_definition.view',
            'POST report-definitions/{definition}/validate' => 'accounting.report_definition.view',
            'POST report-definitions/{definition}/new-version' => 'accounting.report_definition.manage',
            'POST report-definitions/{definition}/publish' => 'accounting.report_definition.manage',
            'POST report-definitions/{definition}/archive' => 'accounting.report_definition.manage',
        ];
    }
}
