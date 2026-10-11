<?php

namespace Tests\Feature\Reporting;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Reporting\Support\ComparisonPeriod;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\BudgetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/** OA5 batch D: comparative reports - prior period, prior year and budget - over the deterministic two-year ledger. */
class ComparativeReportTest extends TestCase
{
    use AccountingFixtures, BudgetFixtures, Fixtures, PayablesFixtures, ReportingFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reportingTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->standardLedger($this->tenant);
        $this->publishedDefinitions($this->tenant);
    }

    private function comparative(array $query)
    {
        return $this->getJson(self::RP.'/reports/comparative?'.http_build_query($query));
    }

    private function compare(array $query): array
    {
        return $this->comparative($query)->assertOk()->json();
    }

    // ------------------------------------------------------------------------------------------ prior period and prior year

    public function test_a_profit_and_loss_period_is_compared_with_the_period_before(): void
    {
        $february = $this->period($this->tenant, '2026-02')->id;

        $report = $this->compare(['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_PERIOD', 'period_id' => $february]);

        $this->assertSame('PROFIT_LOSS', $report['statement']);
        $this->assertSame(['from' => '2026-02-01', 'to' => '2026-02-28'], $report['range']);
        $this->assertSame(['mode' => 'PRIOR_PERIOD', 'range' => ['from' => '2026-01-01', 'to' => '2026-01-31']], $report['comparison']);
        $this->assertTrue($report['complete']);
        $this->assertSame(['code', 'label', 'line_type', 'depth', 'parent_code', 'emphasis', 'current', 'comparison', 'variance', 'variance_pct', 'accounts'], array_keys($report['lines'][0]));

        $lines = collect($report['lines'])->keyBy('code');
        $this->assertSame(['800000.0000', '2000000.0000', '-1200000.0000', '-60.00'], [$lines['TOT_4000']['current'], $lines['TOT_4000']['comparison'], $lines['TOT_4000']['variance'], $lines['TOT_4000']['variance_pct']]);
        $this->assertSame(['600000.0000', '1700000.0000', '-1100000.0000', '-64.71'], [$lines['NET_RESULT_CALC']['current'], $lines['NET_RESULT_CALC']['comparison'], $lines['NET_RESULT_CALC']['variance'], $lines['NET_RESULT_CALC']['variance_pct']]);

        $this->assertSame([], $lines['TOT_4000']['accounts'], 'a subtotal lists no accounts of its own');
        $sales = $lines['ACC_4100'];
        $this->assertSame(['800000.0000', '2000000.0000'], [$sales['current'], $sales['comparison']]);
        $this->assertSame(['4100', '800000.0000', '2000000.0000', '-1200000.0000'], [$sales['accounts'][0]['code'], $sales['accounts'][0]['current'], $sales['accounts'][0]['comparison'], $sales['accounts'][0]['variance']]);
    }

    public function test_a_line_that_shows_on_either_side_shows_and_a_zero_comparison_has_no_percentage(): void
    {
        $report = $this->compare(['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_PERIOD', 'period_id' => $this->period($this->tenant, '2026-02')->id]);
        $lines = collect($report['lines'])->keyBy('code');

        $salary = $lines['ACC_6100']; // January only
        $this->assertSame(['0.0000', '300000.0000', '-300000.0000', '-100.00'], [$salary['current'], $salary['comparison'], $salary['variance'], $salary['variance_pct']]);
        $rent = $lines['ACC_6200']; // February only
        $this->assertSame(['200000.0000', '0.0000', '200000.0000', null], [$rent['current'], $rent['comparison'], $rent['variance'], $rent['variance_pct']]);
        $this->assertArrayNotHasKey('ACC_5100', $lines->all(), 'a line with nothing on either side stays hidden');
    }

    public function test_the_same_period_a_year_earlier_is_the_prior_year_comparison(): void
    {
        $report = $this->compare(['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_YEAR', 'from' => '2026-01-01', 'to' => '2026-03-31']);

        $this->assertSame(['mode' => 'PRIOR_YEAR', 'range' => ['from' => '2025-01-01', 'to' => '2025-03-31']], $report['comparison']);
        $result = collect($report['lines'])->firstWhere('code', 'NET_RESULT_CALC');
        $this->assertSame(['1900000.0000', '1000000.0000', '900000.0000', '90.00'], [$result['current'], $result['comparison'], $result['variance'], $result['variance_pct']]);
        $this->assertSame('1900000.0000', $report['integrity']['current']['ledger_result']);
        $this->assertSame('1000000.0000', $report['integrity']['comparison']['ledger_result']);
    }

    public function test_balance_sheets_are_compared_by_date_and_the_comparison_never_changes_the_ledger_figures(): void
    {
        $prior = $this->compare(['statement' => 'BALANCE_SHEET', 'mode' => 'PRIOR_PERIOD', 'as_of' => '2026-03-31']);
        $year = $this->compare(['statement' => 'BALANCE_SHEET', 'mode' => 'PRIOR_YEAR', 'as_of' => '2026-03-31']);

        $this->assertSame('2026-03-31', $prior['as_of']);
        $this->assertSame(['mode' => 'PRIOR_PERIOD', 'as_of' => '2026-02-28'], $prior['comparison']);
        $this->assertSame(['mode' => 'PRIOR_YEAR', 'as_of' => '2025-03-31'], $year['comparison']);

        $cash = collect($prior['lines'])->firstWhere('code', 'ACC_1110');
        $this->assertSame(['7200000.0000', '7700000.0000', '-500000.0000', '-6.49'], [$cash['current'], $cash['comparison'], $cash['variance'], $cash['variance_pct']]);
        $cashYear = collect($year['lines'])->firstWhere('code', 'ACC_1110');
        $this->assertSame(['7200000.0000', '6000000.0000', '1200000.0000', '20.00'], [$cashYear['current'], $cashYear['comparison'], $cashYear['variance'], $cashYear['variance_pct']]);

        $screen = $this->statement('balance-sheet', ['as_of' => '2025-03-31']);
        $this->assertSame($screen['integrity'], $year['integrity']['comparison'], 'the comparison column is the statement of that date');
        $this->assertTrue($prior['integrity']['current']['balanced']);
        $this->assertTrue($prior['integrity']['comparison']['balanced']);
    }

    public function test_a_cash_flow_is_compared_with_the_previous_quarter(): void
    {
        $report = $this->compare(['statement' => 'CASH_FLOW', 'mode' => 'PRIOR_PERIOD', 'from' => '2026-01-01', 'to' => '2026-03-31']);

        $this->assertSame(['from' => '2025-10-01', 'to' => '2025-12-31'], $report['comparison']['range']);
        $change = collect($report['lines'])->firstWhere('code', 'CF_NET_CHANGE');
        $this->assertSame(['1800000.0000', '0.0000', '1800000.0000', null], [$change['current'], $change['comparison'], $change['variance'], $change['variance_pct']]);
        $closing = collect($report['lines'])->firstWhere('code', 'CASH_CLOSING');
        $this->assertSame(['11600000.0000', '9800000.0000'], [$closing['current'], $closing['comparison']]);
    }

    public function test_a_range_that_is_not_whole_months_moves_back_by_its_number_of_days(): void
    {
        $report = $this->compare(['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_PERIOD', 'from' => '2026-01-10', 'to' => '2026-01-24']);

        $this->assertSame(['from' => '2025-12-26', 'to' => '2026-01-09'], $report['comparison']['range']);
    }

    public function test_the_comparison_dates_follow_the_calendar(): void
    {
        $this->assertSame(['from' => '2025-11-01', 'to' => '2026-01-31'], ComparisonPeriod::priorPeriodRange('2026-02-01', '2026-04-30'), 'a quarter');
        $this->assertSame(['from' => '2025-01-01', 'to' => '2025-12-31'], ComparisonPeriod::priorPeriodRange('2026-01-01', '2026-12-31'));
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-01-31'], ComparisonPeriod::priorPeriodRange('2026-02-01', '2026-02-28'));
        $this->assertSame(['from' => '2024-02-01', 'to' => '2024-02-29'], ComparisonPeriod::priorYearRange('2025-02-01', '2025-02-28'), 'the last day of February stays the last day');
        $this->assertSame(['from' => '2027-02-01', 'to' => '2027-02-28'], ComparisonPeriod::priorYearRange('2028-02-01', '2028-02-29'));
        $this->assertSame('2028-02-29', ComparisonPeriod::priorYearDate('2029-02-28'));
        $this->assertSame('2026-02-28', ComparisonPeriod::priorPeriodDate('2026-03-31'));
        $this->assertSame('2026-01-31', ComparisonPeriod::priorPeriodDate('2026-02-28'));
        $this->assertSame('2026-02-15', ComparisonPeriod::priorPeriodDate('2026-03-15'));
    }

    public function test_the_filters_of_the_comparative_are_validated(): void
    {
        $this->comparative(['mode' => 'PRIOR_YEAR'])->assertUnprocessable();
        $this->comparative(['statement' => 'PROFIT_LOSS'])->assertUnprocessable();
        $this->comparative(['statement' => 'PROFIT_LOSS', 'mode' => 'LAST_WEEK'])->assertUnprocessable();
        $this->comparative(['statement' => 'TRIAL_BALANCE', 'mode' => 'PRIOR_YEAR'])->assertUnprocessable();
        $this->comparative(['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_YEAR', 'as_of' => '2026-03-31'])->assertUnprocessable()->assertJsonPath('code', 'DATE_FILTER_INVALID');
        $this->comparative(['statement' => 'BALANCE_SHEET', 'mode' => 'PRIOR_YEAR', 'from' => '2026-01-01'])->assertUnprocessable()->assertJsonPath('code', 'DATE_FILTER_INVALID');
        $this->comparative(['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET'])->assertUnprocessable();
    }

    // ------------------------------------------------------------------------------------------ budget

    /** March 2026 actuals: 2.5M of sales and 0.45M of salary (the cost of sales are the standard ledger's 0.4M). */
    private function marchActuals(): void
    {
        $this->book($this->tenant, '2026-03-12', '1110', '4100', '2500000');
        $this->book($this->tenant, '2026-03-31', '6100', '1110', '450000');
    }

    /** An opened budget with an approved, active version of the given lines (account code => amount for March); returns the budget id. */
    private function budgetOf(array $march, string $code = 'BGT-MAR'): string
    {
        $lines = [];
        foreach ($march as $account => $amount) {
            $lines[] = $this->bline($this->tenant, (string) $account, '2026-03', $amount);
        }

        return $this->activeBudget($this->tenant, $lines, ['code' => $code])['budget']['id'];
    }

    /** March actuals and the March budget used by most tests; returns the budget id. */
    private function marchBudget(): string
    {
        $this->marchActuals();

        return $this->budgetOf(['4100' => '3000000', '5100' => '300000', '6100' => '400000', '6200' => '250000']);
    }

    public function test_the_budget_comparison_reuses_the_budget_versus_actual_figures(): void
    {
        $budget = $this->marchBudget();
        $report = $this->compare(['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET', 'budget_id' => $budget, 'from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertSame('BUDGET', $report['mode']);
        $this->assertSame($budget, $report['comparison']['budget']['id']);
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-03-31'], $report['comparison']['range']);
        $lines = collect($report['lines'])->keyBy('code');
        $this->assertSame(['2500000.0000', '3000000.0000', '-500000.0000', '-16.67'], [$lines['TOT_4000']['current'], $lines['TOT_4000']['comparison'], $lines['TOT_4000']['variance'], $lines['TOT_4000']['variance_pct']]);
        $this->assertSame(['1650000.0000', '2050000.0000', '-400000.0000', '-19.51'], [$lines['NET_RESULT_CALC']['current'], $lines['NET_RESULT_CALC']['comparison'], $lines['NET_RESULT_CALC']['variance'], $lines['NET_RESULT_CALC']['variance_pct']]);

        // Account by account the numbers are the budget versus actual report's own.
        $bva = collect($this->bvaReport(['budget_id' => $budget, 'period_from' => $this->periodId($this->tenant, '2026-03'), 'period_to' => $this->periodId($this->tenant, '2026-03')])['rows'])->keyBy('code');
        foreach (['4100' => 'ACC_4100', '5100' => 'ACC_5100', '6100' => 'ACC_6100', '6200' => 'ACC_6200'] as $code => $line) {
            $this->assertSame([$bva[$code]['actual'], $bva[$code]['budget'], $bva[$code]['variance'], $bva[$code]['variance_pct']],
                [$lines[$line]['current'], $lines[$line]['comparison'], $lines[$line]['variance'], $lines[$line]['variance_pct']], $code);
        }
        $this->assertSame([], $report['integrity']['budget']['unplaced']);
        $this->assertSame('2050000.0000', $report['integrity']['budget']['budget_result']);
        $this->assertTrue($report['complete']);
        $this->assertSame([], $report['warnings']);
    }

    public function test_a_budget_set_on_a_header_account_is_placed_when_one_line_covers_everything_below_it(): void
    {
        $this->marchActuals();
        // A compact layout whose expense group covers the whole cost-of-sales and operating-expense subtrees.
        $id = $this->postJson(self::RP.'/report-definitions', ['statement_type' => 'PROFIT_LOSS', 'name' => 'Ringkas'])->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => [
            ['code' => 'REV', 'label' => 'Pendapatan', 'line_type' => 'ACCOUNT_GROUP', 'balance_side' => 'CREDIT'],
            ['code' => 'EXP', 'label' => 'Beban', 'line_type' => 'ACCOUNT_GROUP', 'balance_side' => 'DEBIT'],
            ['code' => 'RESULT', 'label' => 'Laba', 'line_type' => 'CALCULATED_TOTAL', 'operands' => [['line' => 'REV', 'sign' => '+'], ['line' => 'EXP', 'sign' => '-']]],
        ]])->assertOk();
        $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => [
            ['line_code' => 'REV', 'account_id' => $this->account($this->tenant, '4000')->id, 'include_descendants' => true],
            ['line_code' => 'EXP', 'account_id' => $this->account($this->tenant, '5000')->id, 'include_descendants' => true],
            ['line_code' => 'EXP', 'account_id' => $this->account($this->tenant, '6000')->id, 'include_descendants' => true],
        ]])->assertOk();
        $this->postJson(self::RP."/report-definitions/{$id}/publish")->assertOk();

        // The budget of March is held on the headers 4000 and 6000, not on the postings below them.
        $budget = $this->budgetOf(['4000' => '3000000', '6000' => '700000'], 'BGT-HDR');

        $report = $this->compare(['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET', 'budget_id' => $budget, 'from' => '2026-03-01', 'to' => '2026-03-31', 'definition_id' => $id]);

        $lines = collect($report['lines'])->keyBy('code');
        $this->assertSame(['2500000.0000', '3000000.0000'], [$lines['REV']['current'], $lines['REV']['comparison']]);
        $this->assertSame(['850000.0000', '700000.0000'], [$lines['EXP']['current'], $lines['EXP']['comparison']], '0.4M cost of sales + 0.45M salary against the 0.7M header budget');
        $this->assertSame(['1650000.0000', '2300000.0000'], [$lines['RESULT']['current'], $lines['RESULT']['comparison']]);
        $this->assertSame([], $report['integrity']['budget']['unplaced']);
        $this->assertSame([], $report['warnings']);
    }

    public function test_a_budget_row_that_has_no_single_line_is_reported_and_left_out_of_the_comparison(): void
    {
        $this->marchActuals();
        // The standard layout has a line per posting account, so a budget on the header 6000 (salary and rent below it) belongs to no one line.
        $budget = $this->budgetOf(['4100' => '3000000', '6000' => '700000']);

        $report = $this->compare(['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET', 'budget_id' => $budget, 'from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertCount(1, $report['integrity']['budget']['unplaced']);
        $this->assertSame(['6000', '700000.0000'], [$report['integrity']['budget']['unplaced'][0]['code'], $report['integrity']['budget']['unplaced'][0]['budget']]);
        $this->assertSame('3000000.0000', $report['integrity']['budget']['budget_result'], 'only the placed 3.0M of sales is in the budgeted result');
        $warning = collect($report['warnings'])->firstWhere('code', 'BUDGET_NOT_ON_STATEMENT');
        $this->assertNotNull($warning);
        $this->assertSame(1, $warning['details']['count']);
        $lines = collect($report['lines'])->keyBy('code');
        $this->assertSame('0.0000', $lines['ACC_6100']['comparison']);
        $this->assertSame('3000000.0000', $lines['NET_RESULT_CALC']['comparison']);
    }

    public function test_the_budget_needs_a_period_aligned_range_the_profit_and_loss_and_a_known_budget(): void
    {
        $budget = $this->marchBudget();

        $this->comparative(['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET', 'budget_id' => $budget, 'from' => '2026-03-05', 'to' => '2026-03-31'])
            ->assertUnprocessable()->assertJsonPath('code', 'BUDGET_RANGE_NOT_PERIOD_ALIGNED');
        $this->comparative(['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET', 'budget_id' => $budget, 'from' => '2025-03-01', 'to' => '2025-03-31'])
            ->assertUnprocessable()->assertJsonPath('code', 'BUDGET_RANGE_NOT_PERIOD_ALIGNED');
        $this->comparative(['statement' => 'BALANCE_SHEET', 'mode' => 'BUDGET', 'budget_id' => $budget, 'as_of' => '2026-03-31'])
            ->assertUnprocessable()->assertJsonPath('code', 'COMPARISON_MODE_UNSUPPORTED');
        $this->comparative(['statement' => 'CASH_FLOW', 'mode' => 'BUDGET', 'budget_id' => $budget, 'from' => '2026-03-01', 'to' => '2026-03-31'])
            ->assertUnprocessable()->assertJsonPath('code', 'COMPARISON_MODE_UNSUPPORTED');
        $this->comparative(['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET', 'budget_id' => fake()->uuid(), 'from' => '2026-03-01', 'to' => '2026-03-31'])->assertNotFound();
    }

    public function test_a_year_to_date_budget_comparison_spans_the_periods_of_the_range(): void
    {
        $budget = $this->marchBudget();

        $report = $this->compare(['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET', 'budget_id' => $budget, 'from' => '2026-01-01', 'to' => '2026-03-31']);

        $lines = collect($report['lines'])->keyBy('code');
        $this->assertSame('3000000.0000', $lines['TOT_4000']['comparison'], 'only March carries a budget');
        $this->assertSame('5300000.0000', $lines['TOT_4000']['current'], 'January 2.0M + February 0.8M + the 2.5M booked for March');
    }

    public function test_the_budget_needs_the_budget_module_and_the_budget_permission(): void
    {
        $budget = $this->marchBudget();
        $query = ['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET', 'budget_id' => $budget, 'from' => '2026-03-01', 'to' => '2026-03-31'];
        $moduleId = DB::table('modules')->where('code', 'ACCOUNTING_BUDGET')->value('id');

        // Without the budget permission the profit and loss comparison with prior periods still works, the budget one does not.
        $token = $this->memberToken($this->tenant, ['accounting.reporting.pnl.view']);
        $this->as($token)->getJson(self::RP.'/reports/comparative?'.http_build_query(['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_YEAR', 'from' => '2026-03-01', 'to' => '2026-03-31']))->assertOk();
        $this->as($token)->getJson(self::RP.'/reports/comparative?'.http_build_query($query))->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');

        // Without the module the budget comparison is closed whoever asks, the rest of the report is not.
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', $moduleId)->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->signedIn($this->tenant);
        $this->comparative($query)->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
        $this->comparative(['mode' => 'PRIOR_YEAR'] + $query)->assertOk();

        // A read-only budget module still answers (reading is allowed).
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', $moduleId)->update(['state' => 'READ_ONLY']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->comparative($query)->assertOk();
    }

    public function test_the_comparative_needs_the_view_permission_of_the_statement_it_shows(): void
    {
        $profitOnly = $this->memberToken($this->tenant, ['accounting.reporting.pnl.view']);
        $query = ['statement' => 'BALANCE_SHEET', 'mode' => 'PRIOR_YEAR', 'as_of' => '2026-03-31'];

        $this->as($profitOnly)->getJson(self::RP.'/reports/comparative?'.http_build_query($query))->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED')->assertJsonPath('details.permission', 'accounting.reporting.balance_sheet.view');
        $this->as($profitOnly)->getJson(self::RP.'/reports/comparative?'.http_build_query(['statement' => 'CASH_FLOW', 'mode' => 'PRIOR_YEAR', 'from' => '2026-01-01', 'to' => '2026-03-31']))->assertForbidden();
        $both = $this->memberToken($this->tenant, ['accounting.reporting.pnl.view', 'accounting.reporting.balance_sheet.view']);
        $this->as($both)->getJson(self::RP.'/reports/comparative?'.http_build_query($query))->assertOk();
    }
}
