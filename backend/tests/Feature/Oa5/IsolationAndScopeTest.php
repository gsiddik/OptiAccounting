<?php

namespace Tests\Feature\Oa5;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AccountingFixtures;
use Tests\Support\BudgetFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\Oa5Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\ReconciliationFixtures;
use Tests\Support\ReportingFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/**
 * OA5 verification (spec 71, 81, 26): tenant isolation and data scope beyond the id-in-the-path sweeps of the access matrices. A caller of one tenant
 * puts another tenant's ids into every filter and body of every OA5 family and learns nothing; a caller restricted to one branch cannot reach the
 * other branch by naming it in a filter, and no report, export, definition check or year-end screen shows a tenant-wide figure to them.
 */
class IsolationAndScopeTest extends TestCase
{
    use AccountingFixtures, BudgetFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, Oa5Fixtures, PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, ReportingFixtures, TaxFixtures;

    private Tenant $alpha;

    private Tenant $bravo;

    private string $alphaAdmin;

    private string $bravoAdmin;

    /** @var array<string,string> ids that belong to alpha */
    private array $a = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->reportingTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->bravo = $this->reportingTenant('bravo', ['sod_creator_not_approver' => false]);
        $this->alphaAdmin = $this->memberToken($this->alpha);
        $this->bravoAdmin = $this->memberToken($this->bravo);

        $this->standardLedger($this->alpha);
        $this->publishedDefinitions($this->alpha);
        $this->signedIn($this->bravo);
        $this->book($this->bravo, '2026-02-10', '1110', '4100', '123000');
        $this->publishedDefinitions($this->bravo);

        $this->as($this->alphaAdmin);
        [$north, $south] = $this->twoBranches($this->alpha);
        $unit = $this->inTenant($this->alpha, fn () => app(OrganizationService::class)->createBusinessUnit($this->alpha->id, ['code' => 'BU1', 'name' => 'Unit satu'], $north));
        $this->strayLine($this->alpha, '2110', 'CREDIT', '5000', '2026-03-25');
        $budget = $this->activeBudget($this->alpha, [$this->bline($this->alpha, '4100', '2026-03', '2000000')]);
        $this->a = [
            'account' => $this->account($this->alpha, '4100')->id, 'period' => $this->period($this->alpha, '2026-03')->id, 'year' => $this->fiscalYear($this->alpha, 'FY2026')->id,
            'definition' => (string) DB::table('report_definitions')->where('tenant_id', $this->alpha->id)->where('statement_type', 'PROFIT_LOSS')->value('id'),
            'branch' => $north, 'south' => $south, 'unit' => $unit->id, 'budget' => $budget['budget']['id'], 'version' => $budget['version']['id'],
            'exception' => $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-03-31'])->assertCreated()->json('exception.id'),
        ];
        $this->postJson(self::CL.'/closing/periods/'.$this->a['period'].'/soft-close', ['reason' => 'Mulai penutupan Maret'])->assertOk();
    }

    /** What alpha alone could produce: distinctive figures and every id, as strings that must never appear in an answer to bravo (the id a caller itself sent may be echoed back). @return list<string> */
    private function alphaMarks(string $sent = ''): array
    {
        return array_values(array_filter([...array_values($this->a), '14100000', '1900000', '2500000', '5000.0000', 'Alpha Corp'], fn (string $mark) => $sent === '' || ! str_contains($sent, $mark)));
    }

    public function test_another_tenants_ids_in_every_filter_and_body_of_every_family_answer_not_found_or_invalid_and_leak_nothing(): void
    {
        $a = $this->a;
        $range = 'from=2026-01-01&to=2026-03-31';
        $this->as($this->bravoAdmin);
        $own = $this->statement('profit-loss', ['from' => '2026-01-01', 'to' => '2026-03-31']);
        $this->assertSame('123000.0000', $own['integrity']['ledger_result'], 'bravo sees its own ledger only');
        $before = $this->rowHashes($this->alpha, ['report_definitions', 'report_line_mappings', 'reconciliation_runs', 'reconciliation_exceptions', 'closing_events', 'closing_checklist_items', 'accounting_periods', 'journal_entries', 'audit_logs']);

        // Reads: a foreign id is an unknown id (404) or an invalid filter (422); a dimension that matches nothing may answer an empty 200, never alpha's figures.
        $reads = [
            "reports/account-balance?account_id={$a['account']}&as_of=2026-03-31",
            "reports/account-balance?period_id={$a['period']}", "reports/account-balance?fiscal_year_id={$a['year']}",
            "reports/balance-sheet?as_of=2026-03-31&definition_id={$a['definition']}", "reports/profit-loss?{$range}&definition_id={$a['definition']}",
            "reports/profit-loss?period_id={$a['period']}", "reports/profit-loss?fiscal_year_id={$a['year']}", "reports/cash-flow?period_id={$a['period']}",
            "reports/comparative?statement=PROFIT_LOSS&mode=BUDGET&budget_id={$a['budget']}&{$range}", "reports/comparative?statement=PROFIT_LOSS&mode=BUDGET&budget_id={$a['budget']}&version_id={$a['version']}&{$range}",
            "reports/comparative?statement=PROFIT_LOSS&mode=PRIOR_YEAR&period_id={$a['period']}", "reports/drilldown?statement=PROFIT_LOSS&line=ACC_4100&definition_id={$a['definition']}&{$range}",
            "reports/profit-loss/export?period_id={$a['period']}", "reports/balance-sheet/export?as_of=2026-03-31&definition_id={$a['definition']}",
            "reports/account-balance/export?account_id={$a['account']}&as_of=2026-03-31", "reports/comparative/export?statement=PROFIT_LOSS&mode=PRIOR_YEAR&period_id={$a['period']}",
            "trial-balance?period_id={$a['period']}", "general-ledger?account_id={$a['account']}&{$range}",
            "trial-balance/export?fiscal_year_id={$a['year']}",
            "reconciliations/ap-gl?period_id={$a['period']}", "reconciliations/ap-gl/export?period_id={$a['period']}", "reconciliations?period_id={$a['period']}",
            "closing/periods/{$a['period']}/readiness", "closing/periods/{$a['period']}/checklist", "year-end/{$a['year']}", "year-end/{$a['year']}/preview",
            "report-definitions/{$a['definition']}", "report-definitions/{$a['definition']}/unmapped-accounts",
        ];
        $dimensional = [
            "reports/balance-sheet?as_of=2026-03-31&branch_id={$a['branch']}", "reports/profit-loss?{$range}&branch_id={$a['branch']}", "reports/profit-loss?{$range}&business_unit_id={$a['unit']}",
            "reports/cash-flow?{$range}&branch_id={$a['branch']}", "reports/comparative?statement=PROFIT_LOSS&mode=PRIOR_YEAR&{$range}&branch_id={$a['branch']}",
            "reports/account-balance?as_of=2026-03-31&branch_id={$a['branch']}", "trial-balance?{$range}&branch_id={$a['branch']}", "general-ledger?{$range}&business_unit_id={$a['unit']}",
        ];
        foreach ($reads as $path) {
            $response = $this->as($this->bravoAdmin)->getJson(self::RP.'/'.$path);
            $this->assertContains($response->getStatusCode(), [404, 422], "{$path} answered ".$response->getStatusCode());
            $this->assertNoneOf($this->alphaMarks($path), $response->baseResponse instanceof StreamedResponse ? '' : (string) $response->getContent(), $path);
        }
        foreach ($dimensional as $path) {
            $response = $this->as($this->bravoAdmin)->getJson(self::RP.'/'.$path);
            $this->assertContains($response->getStatusCode(), [200, 404, 422], $path);
            if ($response->getStatusCode() === 200) {
                $this->assertNoneOf($this->alphaMarks($path), (string) $response->getContent(), $path);
                $this->assertStringNotContainsString('123000', (string) $response->getContent(), "{$path}: a branch of another tenant selected bravo's own figures");
            }
        }

        // The OA1 general-ledger export does not validate the account: a foreign id is refused or exports the header row alone, never a row of alpha.
        $response = $this->as($this->bravoAdmin)->get(self::RP."/general-ledger/export?account_id={$a['account']}&{$range}");
        $this->assertContains($response->getStatusCode(), [200, 404, 422]);
        if ($response->getStatusCode() === 200) {
            $this->assertCount(1, array_filter(preg_split('/\R/', trim($response->streamedContent()))), 'a foreign account id exported ledger rows');
        }

        // A filter that only narrows what this tenant owns: a foreign id matches nothing, so the answer has no rows at all.
        foreach ([self::RP."/reports/account-balance?account_ids[]={$a['account']}&as_of=2026-03-31", self::RP."/trial-balance?account_ids[]={$a['account']}&{$range}",
            self::CL."/closing/history?period_id={$a['period']}", self::CL."/closing/history?fiscal_year_id={$a['year']}"] as $path) {
            $response = $this->as($this->bravoAdmin)->getJson($path);
            $this->assertContains($response->getStatusCode(), [200, 404, 422], $path);
            if ($response->getStatusCode() === 200) {
                $this->assertSame([], $response->json('data'), "{$path}: a foreign id selected rows");
                $this->assertNoneOf($this->alphaMarks($path), (string) $response->getContent(), $path);
            }
        }

        // Writes: a foreign id in a body names nothing.
        $draft = $this->postJson(self::RP.'/report-definitions', ['statement_type' => 'CASH_FLOW', 'name' => 'Uji lintas tenant'])->assertCreated()->json('id');
        $line = ['code' => 'OP', 'label' => 'Operasi', 'line_type' => 'ACCOUNT_GROUP', 'balance_side' => 'DEBIT', 'cash_flow_category' => 'OPERATING'];
        $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => [$line]])->assertOk();
        $this->putJson(self::RP."/report-definitions/{$draft}/mappings", ['mappings' => [['line_code' => 'OP', 'account_id' => $a['account']]]])->assertNotFound();
        $this->putJson(self::RP."/report-definitions/{$draft}/mappings", ['mappings' => [['report_line_id' => (string) DB::table('report_lines')->where('tenant_id', $this->alpha->id)->value('id'), 'account_id' => $this->account($this->bravo, '1110')->id]]])->assertNotFound();
        $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['period_id' => $a['period']])->assertNotFound();
        $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-03-31'])->assertCreated();
        $this->postJson(self::RC."/reconciliation-exceptions/{$a['exception']}/assign", ['owner_id' => $this->member($this->alpha)[0]->id])->assertNotFound();
        $this->postJson(self::CL."/closing/periods/{$a['period']}/hard-close")->assertNotFound();
        $this->postJson(self::CL."/closing/periods/{$a['period']}/reopen", ['reason' => 'Percobaan lintas tenant'])->assertNotFound();
        $this->postJson(self::CL."/year-end/{$a['year']}/run")->assertNotFound();
        $this->postJson(self::CL."/year-end/{$a['year']}/reverse", ['reason' => 'Percobaan lintas tenant'])->assertNotFound();

        $this->assertSame($before, $this->rowHashes($this->alpha, array_keys($before)), 'bravo changed nothing of alpha');
        $this->assertSame($own['integrity']['ledger_result'], $this->statement('profit-loss', ['from' => '2026-01-01', 'to' => '2026-03-31'])['integrity']['ledger_result']);
    }

    public function test_the_closing_and_reconciliation_views_of_one_tenant_never_show_the_work_of_another(): void
    {
        $this->as($this->bravoAdmin);
        $this->assertSame(0, $this->getJson(self::CL.'/closing/history')->assertOk()->json('total'));
        $overview = $this->getJson(self::CL.'/closing/overview')->assertOk()->json();
        $this->assertSame([], array_values(array_filter($overview['periods'], fn ($p) => $p['last_event'] !== null || $p['status'] !== 'OPEN')), 'no alpha status or event');
        $this->assertSame([], $this->getJson(self::RC.'/reconciliation-exceptions')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson(self::RC.'/reconciliations/runs')->assertOk()->json('data'));
        $years = $this->getJson(self::CL.'/year-end')->assertOk()->json('fiscal_years');
        $this->assertNotContains($this->a['year'], array_column($years, 'id'));
        $definitions = $this->getJson(self::RP.'/report-definitions')->assertOk()->json('data');
        $this->assertNotContains($this->a['definition'], array_column($definitions, 'id'), 'no definition of alpha');
        $this->assertNotEmpty($definitions, 'bravo sees its own');
        // bravo's reconciliation of the same date is its own: alpha's stray line is not in it.
        $this->assertSame(['MATCHED', '0.0000'], [$this->getJson(self::RC.'/reconciliations/ap-gl?as_of=2026-03-31')->json('status'), $this->getJson(self::RC.'/reconciliations/ap-gl?as_of=2026-03-31')->json('gl_balance')]);
        // and the exports.
        $csv = $this->get(self::RC.'/reconciliations/export?as_of=2026-03-31')->assertOk()->streamedContent();
        $this->assertNoneOf(['5000', $this->a['exception']], $csv);
    }

    public function test_the_database_refuses_closing_and_reconciliation_rows_that_point_across_tenants(): void
    {
        $period = $this->a['period']; // alpha's
        $now = now();
        $attempts = [
            'closing event' => fn () => DB::table('closing_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->bravo->id, 'accounting_period_id' => $period, 'event_type' => 'PRE_CLOSE', 'from_status' => 'OPEN', 'to_status' => 'OPEN', 'created_at' => $now]),
            'checklist item' => fn () => DB::table('closing_checklist_items')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->bravo->id, 'accounting_period_id' => $period, 'code' => 'X', 'title' => 'X', 'required' => false, 'status' => 'PENDING', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]),
            'reconciliation run' => fn () => DB::table('reconciliation_runs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->bravo->id, 'reconciliation_type' => 'AP_GL', 'as_of_date' => '2026-03-31', 'accounting_period_id' => $period, 'status' => 'MATCHED', 'purpose' => 'MANUAL', 'scope_complete' => true, 'run_at' => $now, 'created_at' => $now]),
            'year-end run' => fn () => DB::table('year_end_closings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->bravo->id, 'fiscal_year_id' => $this->a['year'], 'run_no' => 1, 'status' => 'POSTED', 'closing_date' => '2026-12-31', 'run_at' => $now, 'closed_account_count' => 0]),
        ];
        foreach ($attempts as $label => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("the database accepted a {$label} of bravo that points at alpha");
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[0], ['23503', '23502', '23514'], "{$label}: ".$e->getMessage()); // a foreign key (or an earlier NOT NULL / CHECK) stops it, never success
            }
        }
        $this->assertSame(0, DB::table('closing_events')->where('tenant_id', $this->bravo->id)->count());
    }

    // ------------------------------------------------------------------------------------------ data scope

    /** @return list<string> */
    private function scopedPermissions(): array
    {
        return [
            'accounting.reporting.balance_sheet.view', 'accounting.reporting.pnl.view', 'accounting.reporting.cash_flow.view', 'accounting.gl.view', 'accounting.trial_balance.view', 'accounting.report.export',
            'accounting.report_definition.view', 'accounting.reconciliation.view', 'accounting.reconciliation.run', 'accounting.year_end.view', 'accounting.year_end.run', 'accounting.period.view', 'accounting.budget.view',
        ];
    }

    public function test_a_user_restricted_to_one_branch_cannot_reach_the_other_branch_or_a_tenant_wide_figure_by_any_report_export_or_check(): void
    {
        [$north, $south] = [$this->a['branch'], $this->a['south']];
        $this->as($this->alphaAdmin);
        $this->book($this->alpha, '2026-02-10', '1110', '4100', '700000', ['branch_id' => $north]);
        $this->book($this->alpha, '2026-02-15', '1110', '4100', '300000', ['branch_id' => $south]);
        $feb = ['period_id' => $this->period($this->alpha, '2026-02')->id];
        $everyone = $this->statement('profit-loss', $feb);
        $this->assertSame('1800000.0000', $this->accountAmount($everyone, '4100'));

        $this->as($this->branchToken($this->alpha, $this->scopedPermissions(), 'BRANCH', $north));
        $own = $this->statement('profit-loss', $feb);
        $this->assertSame('700000.0000', $this->accountAmount($own, '4100'));

        // Naming the other branch cannot widen the scope: the answer is empty and says it is partial.
        $other = ['branch_id' => $south];
        $pl = $this->statement('profit-loss', $feb + $other);
        $bs = $this->statement('balance-sheet', ['as_of' => '2026-03-31'] + $other);
        $cf = $this->statement('cash-flow', $feb + $other);
        $tb = $this->getJson(self::RP.'/trial-balance?'.http_build_query(['from' => '2026-02-01', 'to' => '2026-02-28'] + $other))->assertOk()->json();
        $gl = $this->getJson(self::RP.'/general-ledger?'.http_build_query(['from' => '2026-02-01', 'to' => '2026-02-28'] + $other))->assertOk()->json();
        $balance = $this->statement('account-balance', ['account_id' => $this->account($this->alpha, '4100')->id] + $feb + $other);
        $cmp = $this->statement('comparative', ['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_YEAR'] + $feb + $other);
        $drill = $this->statement('drilldown', ['statement' => 'PROFIT_LOSS', 'line' => 'TOT_4000'] + $feb + $other);
        $this->assertSame(['0.0000', false], [$pl['integrity']['ledger_result'], $pl['complete']]);
        $this->assertSame('0.0000', $bs['integrity']['assets']);
        $this->assertSame(['0.0000', null], [$cf['integrity']['cash_closing_ledger'], $cf['integrity']['reconciled']]);
        $this->assertSame(['0.0000', '0.0000'], [$tb['totals']['debit'], $tb['totals']['credit']]);
        $this->assertSame([], $gl['data']);
        $this->assertSame('0.0000', $balance['totals']['credit']);
        $this->assertSame('0.0000', collect($cmp['lines'])->firstWhere('code', 'NET_RESULT_CALC')['current']);
        $this->assertSame([], $drill['accounts']);

        // Exports of the same user: the on-screen scope, partial, and nothing of the tenant total or the south.
        foreach (['profit-loss/export' => $feb, 'balance-sheet/export' => ['as_of' => '2026-03-31'], 'cash-flow/export' => $feb, 'account-balance/export' => ['as_of' => '2026-03-31'], 'comparative/export' => ['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_PERIOD'] + $feb] as $path => $query) {
            $csv = $this->get(self::RP."/reports/{$path}?".http_build_query($query))->assertOk()->streamedContent();
            $this->assertNoneOf(['1800000', '300000.0000'], $csv, $path);
        }
        foreach (['trial-balance/export' => ['from' => '2026-02-01', 'to' => '2026-02-28'], 'general-ledger/export' => ['from' => '2026-02-01', 'to' => '2026-02-28']] as $path => $query) {
            $csv = $this->get(self::RP."/{$path}?".http_build_query($query + $other))->assertOk()->streamedContent();
            $this->assertNoneOf(['1800000', '300000'], $csv, $path);
        }

        // Aggregates behind the statements: the definition checks and the reconciliation screens carry the same scope.
        $draft = $this->as($this->alphaAdmin)->postJson(self::RP."/report-definitions/{$this->a['definition']}/new-version")->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$draft}/mappings", ['mappings' => []])->assertOk();
        $everything = collect($this->getJson(self::RP."/report-definitions/{$draft}/unmapped-accounts?as_of=2026-03-31")->assertOk()->json('data'))->keyBy('code');
        $this->as($this->branchToken($this->alpha, $this->scopedPermissions(), 'BRANCH', $north));
        $mine = collect($this->getJson(self::RP."/report-definitions/{$draft}/unmapped-accounts?as_of=2026-03-31")->assertOk()->json('data'))->keyBy('code');
        $this->assertNotSame($everything['4100']['balance'], $mine['4100']['balance'] ?? '0.0000');
        $this->assertSame('-700000.0000', $mine['4100']['balance'], 'debit minus credit of the north branch only');
        $overview = $this->getJson(self::RC.'/reconciliations?as_of=2026-03-31')->assertOk()->json();
        $this->assertFalse($overview['complete']);
        $this->assertSame([null], array_values(array_unique(array_column($overview['data'], 'gl_balance'))));
        $this->assertNoneOf(['5000'], (string) $this->get(self::RC.'/reconciliations/export?as_of=2026-03-31')->streamedContent());

        // Year end: every screen and action needs the whole ledger in sight.
        foreach ([['GET', 'year-end'], ['GET', "year-end/{$this->a['year']}"], ['GET', "year-end/{$this->a['year']}/preview"], ['POST', "year-end/{$this->a['year']}/run"]] as [$method, $path]) {
            $this->json($method, self::CL."/{$path}")->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        }
    }
}
