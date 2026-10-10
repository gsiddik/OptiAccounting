<?php

namespace Tests\Feature\Budget;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\BudgetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA4 batch B: budget versus actual. Actuals come from POSTED journal lines only; the budget is the version in force; data scope narrows both. */
class BudgetVsActualTest extends TestCase
{
    use AccountingFixtures, BudgetFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->budgetTenant();
        $this->signedIn($this->tenant);
    }

    /** Dr expense (or other debit account) / Cr cash posted on a date, optionally with dimensions. */
    private function spend(string $amount, string $date = '2026-03-10', string $debit = '6100', string $credit = '1110', array $dims = []): void
    {
        $this->postedJournal($this->tenant, ['document_date' => $date, 'posting_date' => $date, 'lines' => $this->lines($this->tenant, $amount, $debit, $credit, $dims)]);
    }

    /** @return array<string,array<string,mixed>> report rows keyed by their code */
    private function rowsByCode(array $report): array
    {
        return collect($report['rows'])->keyBy('code')->all();
    }

    public function test_actual_comes_only_from_posted_journals_and_variance_is_deterministic(): void
    {
        $made = $this->activeBudget($this->tenant, [$this->bline($this->tenant, '6100', '2026-03', '1000000')]);
        $query = ['budget_id' => $made['budget']['id'], 'period_from' => $this->periodId($this->tenant, '2026-03'), 'period_to' => $this->periodId($this->tenant, '2026-03')];

        $this->spend('750000');
        $draft = $this->draft($this->tenant, ['lines' => $this->lines($this->tenant, '200000', '6100', '1110')]);
        $submitted = $this->draft($this->tenant, ['lines' => $this->lines($this->tenant, '300000', '6100', '1110')]);
        $this->postJson(self::BG."/journals/{$submitted['id']}/submit")->assertOk();

        $report = $this->bvaReport($query);
        $row = $this->rowsByCode($report)['6100'];
        $this->assertSame(['1000000.0000', '750000.0000', '-250000.0000', '-25.00', true, false], [$row['budget'], $row['actual'], $row['variance'], $row['variance_pct'], $row['favorable'], $row['unbudgeted']]);
        $this->assertSame(['1000000.0000', '750000.0000', '-250000.0000'], [$report['totals']['budget'], $report['totals']['actual'], $report['totals']['variance']]);
        $this->assertTrue($report['complete']);
        $this->assertSame($report, $this->bvaReport($query)); // deterministic

        // The actual is exactly what the general ledger shows for the account and period.
        $ledger = $this->getJson(self::BG.'/trial-balance?from=2026-03-01&to=2026-03-31')->assertOk()->json();
        $this->assertSame($row['actual'], collect($ledger['data'])->firstWhere('code', '6100')['debit']);

        // Posting the pending journals moves the actual; reversing one moves it back (the reversal's own posting date decides the period).
        $this->postJson(self::BG."/journals/{$submitted['id']}/approve")->assertOk();
        $this->postJson(self::BG."/journals/{$submitted['id']}/post")->assertOk();
        $this->assertSame('1050000.0000', $this->rowsByCode($this->bvaReport($query))['6100']['actual']);
        $this->postJson(self::BG."/journals/{$submitted['id']}/reverse", ['reason' => 'Salah', 'posting_date' => '2026-03-28'])->assertCreated();
        $this->assertSame('750000.0000', $this->rowsByCode($this->bvaReport($query))['6100']['actual']);
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $draft['id'])->value('status'));
    }

    public function test_a_zero_budget_has_no_variance_percent_and_revenue_is_favorable_above_plan(): void
    {
        $made = $this->activeBudget($this->tenant, [
            $this->bline($this->tenant, '6200', '2026-03', '0'),
            $this->bline($this->tenant, '4100', '2026-03', '2000000'),
        ]);
        $this->spend('100000', '2026-03-05', '6200', '1110');
        $this->spend('2500000', '2026-03-06', '1110', '4100');
        $rows = $this->rowsByCode($this->bvaReport(['budget_id' => $made['budget']['id']]));

        $this->assertSame(['0.0000', '100000.0000', '100000.0000', null, false], [$rows['6200']['budget'], $rows['6200']['actual'], $rows['6200']['variance'], $rows['6200']['variance_pct'], $rows['6200']['favorable']]);
        $this->assertSame(['2000000.0000', '2500000.0000', '500000.0000', '25.00', true], [$rows['4100']['budget'], $rows['4100']['actual'], $rows['4100']['variance'], $rows['4100']['variance_pct'], $rows['4100']['favorable']]);
    }

    public function test_a_header_account_budgets_its_whole_group_and_unbudgeted_pnl_activity_is_reported(): void
    {
        $made = $this->activeBudget($this->tenant, [$this->bline($this->tenant, '6000', '2026-03', '5000000')]);
        $this->spend('1000000', '2026-03-05', '6100');
        $this->spend('2000000', '2026-03-06', '6200');
        $this->spend('400000', '2026-03-07', '5100'); // cost of sales: no line covers it
        $this->spend('900000', '2026-03-08', '1230', '1110'); // balance-sheet movement is not a budget miss

        $report = $this->bvaReport(['budget_id' => $made['budget']['id']]);
        $rows = $this->rowsByCode($report);
        $this->assertSame(['5000000.0000', '3000000.0000', '-2000000.0000', false], [$rows['6000']['budget'], $rows['6000']['actual'], $rows['6000']['variance'], $rows['6000']['unbudgeted']]);
        $this->assertSame(['0.0000', '400000.0000', true, null], [$rows['5100']['budget'], $rows['5100']['actual'], $rows['5100']['unbudgeted'], $rows['5100']['variance_pct']]);
        $this->assertArrayNotHasKey('1230', $rows);
        $this->assertSame(['5000000.0000', '3400000.0000', '400000.0000'], [$report['totals']['budget'], $report['totals']['actual'], $report['totals']['unbudgeted_actual']]);

        // Filtering by an account also returns the group that covers it.
        $filtered = $this->bvaReport(['budget_id' => $made['budget']['id'], 'account_id' => $this->account($this->tenant, '6100')->id]);
        $this->assertSame(['6000'], array_column($filtered['rows'], 'code'));
        $this->assertSame('1000000.0000', $filtered['rows'][0]['actual']);
        $this->assertFalse($filtered['complete']);
    }

    public function test_dimension_lines_match_only_their_own_actuals_and_other_dimensions_are_unbudgeted(): void
    {
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $made = $this->activeBudget($this->tenant, [$this->bline($this->tenant, '6100', '2026-03', '1000000', ['branch_id' => $north])]);
        $this->spend('300000', '2026-03-05', '6100', '1110', ['branch_id' => $north]);
        $this->spend('700000', '2026-03-06', '6100', '1110', ['branch_id' => $south]);
        $this->spend('50000', '2026-03-07', '6100', '1110');

        $lines = $this->bvaReport(['budget_id' => $made['budget']['id'], 'group_by' => 'line']);
        $matched = collect($lines['rows'])->firstWhere('unbudgeted', false);
        $this->assertSame(['1000000.0000', '300000.0000', $north], [$matched['budget'], $matched['actual'], $matched['branch']['id']]);
        $this->assertSame(2, collect($lines['rows'])->where('unbudgeted', true)->count());
        $this->assertSame('1050000.0000', $lines['totals']['actual']);

        $byBranch = $this->bvaReport(['budget_id' => $made['budget']['id'], 'group_by' => 'branch']);
        $this->assertSame([null, 'N', 'S'], array_column($byBranch['rows'], 'code'));

        // A dimension filter is strict equality on both sides.
        $onlyNorth = $this->bvaReport(['budget_id' => $made['budget']['id'], 'branch_id' => $north]);
        $this->assertSame(['1000000.0000', '300000.0000'], [$onlyNorth['totals']['budget'], $onlyNorth['totals']['actual']]);
        $this->assertFalse($onlyNorth['complete']);
        $onlySouth = $this->bvaReport(['budget_id' => $made['budget']['id'], 'branch_id' => $south]);
        $this->assertSame(['0.0000', '700000.0000'], [$onlySouth['totals']['budget'], $onlySouth['totals']['actual']]);
    }

    public function test_the_version_in_force_decides_the_budget_and_only_approved_versions_are_compared(): void
    {
        $made = $this->activeBudget($this->tenant, [$this->bline($this->tenant, '6100', '2026-03', '1000000'), $this->bline($this->tenant, '6100', '2026-09', '1000000')]);
        $budgetId = $made['budget']['id'];
        $v1 = $made['version']['id'];
        $v2 = $this->newVersion($budgetId, ['label' => 'Revisi 1', 'copy_from_version_id' => $v1]);
        $this->putLines($v2['id'], [$this->bline($this->tenant, '6100', '2026-03', '1300000'), $this->bline($this->tenant, '6100', '2026-09', '1800000')]);
        $this->postJson(self::BG."/budget-versions/{$v2['id']}/submit")->assertOk();

        $mar = ['period_from' => $this->periodId($this->tenant, '2026-03'), 'period_to' => $this->periodId($this->tenant, '2026-03')];
        $sep = ['period_from' => $this->periodId($this->tenant, '2026-09'), 'period_to' => $this->periodId($this->tenant, '2026-09')];
        $this->getJson(self::BG.'/budget-vs-actual?'.http_build_query(['budget_id' => $budgetId, 'version_id' => $v2['id']] + $mar))->assertStatus(422)->assertJsonPath('code', 'BUDGET_VERSION_NOT_APPROVED');

        $this->postJson(self::BG."/budget-versions/{$v2['id']}/approve")->assertOk();
        $this->activateVersion($v2['id'], '2026-07-01');

        // March is judged by the version in force at the end of the range (v1), September by v2; an explicit version or date overrides.
        $this->assertSame(['1000000.0000', 1], [$this->bvaReport(['budget_id' => $budgetId] + $mar)['totals']['budget'], $this->bvaReport(['budget_id' => $budgetId] + $mar)['version']['version_number']]);
        $this->assertSame(['1800000.0000', 2], [$this->bvaReport(['budget_id' => $budgetId] + $sep)['totals']['budget'], $this->bvaReport(['budget_id' => $budgetId] + $sep)['version']['version_number']]);
        $this->assertSame('1300000.0000', $this->bvaReport(['budget_id' => $budgetId, 'version_id' => $v2['id']] + $mar)['totals']['budget']);
        $this->assertSame('1000000.0000', $this->bvaReport(['budget_id' => $budgetId, 'version_id' => $v1] + $mar)['totals']['budget']);
        $this->assertSame(2, $this->bvaReport(['budget_id' => $budgetId, 'as_of' => '2026-07-01'] + $mar)['version']['version_number']);
        $this->assertSame(1, $this->bvaReport(['budget_id' => $budgetId, 'as_of' => '2026-06-30'] + $sep)['version']['version_number']);
        $this->assertSame('2026-06-30', $this->bvaReport(['budget_id' => $budgetId, 'version_id' => $v1] + $mar)['version']['effective_until']);

        // A budget with no version in force on the date cannot be compared.
        $fresh = $this->newBudget($this->tenant);
        $this->postJson(self::BG."/budgets/{$fresh['id']}/open")->assertOk();
        $v = $this->newVersion($fresh['id']);
        $this->putLines($v['id'], [$this->bline($this->tenant, '6100', '2026-03', '1')]);
        $this->approveVersion($v['id']);
        $this->getJson(self::BG.'/budget-vs-actual?budget_id='.$fresh['id'])->assertStatus(422)->assertJsonPath('code', 'BUDGET_NO_EFFECTIVE_VERSION');
        $this->assertSame(1, $this->bvaReport(['budget_id' => $fresh['id'], 'version_id' => $v['id']])['version']['version_number']); // approved, not yet active: may be previewed
    }

    public function test_only_the_requested_periods_count_on_both_sides_and_groups_follow_the_budget_granularity(): void
    {
        $made = $this->activeBudget($this->tenant, [$this->bline($this->tenant, '6100', '2026-01', '100'), $this->bline($this->tenant, '6100', '2026-02', '200'), $this->bline($this->tenant, '6100', '2026-03', '300')]);
        $this->spend('90', '2026-01-15');
        $this->spend('250', '2026-02-15');
        $this->spend('999', '2026-03-15');

        $byPeriod = $this->bvaReport(['budget_id' => $made['budget']['id'], 'group_by' => 'period', 'period_to' => $this->periodId($this->tenant, '2026-02')]);
        $this->assertSame(['2026-01', '2026-02'], array_column($byPeriod['rows'], 'code'));
        $this->assertSame(['100.0000', '90.0000'], [$byPeriod['rows'][0]['budget'], $byPeriod['rows'][0]['actual']]);
        $this->assertSame(['300.0000', '340.0000', '40.0000'], [$byPeriod['totals']['budget'], $byPeriod['totals']['actual'], $byPeriod['totals']['variance']]);

        $this->getJson(self::BG.'/budget-vs-actual?'.http_build_query(['budget_id' => $made['budget']['id'], 'period_from' => $this->periodId($this->tenant, '2026-03'), 'period_to' => $this->periodId($this->tenant, '2026-01')]))->assertStatus(422)->assertJsonPath('code', 'RANGE_INVALID');
        $this->getJson(self::BG.'/budget-vs-actual?budget_id='.$made['budget']['id'].'&group_by=nonsense')->assertStatus(422);
    }

    public function test_data_scope_narrows_the_budget_lines_and_the_actuals_a_user_sees(): void
    {
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $made = $this->activeBudget($this->tenant, [
            $this->bline($this->tenant, '6100', '2026-03', '1000000', ['branch_id' => $north]),
            $this->bline($this->tenant, '6200', '2026-03', '2000000', ['branch_id' => $south]),
            $this->bline($this->tenant, '6900', '2026-03', '3000000'),
        ]);
        $this->spend('400000', '2026-03-05', '6100', '1110', ['branch_id' => $north]);
        $this->spend('600000', '2026-03-06', '6200', '1110', ['branch_id' => $south]);

        $query = ['budget_id' => $made['budget']['id']];
        $full = $this->bvaReport($query);
        $this->assertSame(['6000000.0000', '1000000.0000', true], [$full['totals']['budget'], $full['totals']['actual'], $full['complete']]);

        $token = $this->scopedToken($this->tenant, ['accounting.budget.view', 'accounting.report.export'], 'BRANCH', $north);
        $scoped = $this->as($token);
        $report = $scoped->getJson(self::BG.'/budget-vs-actual?'.http_build_query($query))->assertOk()->json();
        $this->assertSame(['6100'], array_column($report['rows'], 'code'));
        $this->assertSame(['1000000.0000', '400000.0000', false], [$report['totals']['budget'], $report['totals']['actual'], $report['complete']]);

        // The version itself shows only the lines in scope, and so does its total; the export is the same slice.
        $shown = $scoped->getJson(self::BG."/budget-versions/{$made['version']['id']}")->assertOk()->json();
        $this->assertCount(1, $shown['lines']);
        $this->assertSame(['1000000.0000', false], [$shown['lines_total'], $shown['lines_complete']]);
        $csv = $scoped->get(self::BG.'/budget-vs-actual/export?'.http_build_query($query))->assertOk()->streamedContent();
        $this->assertStringContainsString('6100', $csv);
        $this->assertStringNotContainsString('6200', $csv);
        $this->assertStringNotContainsString('6900', $csv);

        // A scoped user may neither replace every line nor copy a version (they cannot see all of it) and cannot write outside their scope.
        $writerToken = $this->scopedToken($this->tenant, ['accounting.budget.view', 'accounting.budget.manage'], 'BRANCH', $north);
        $draft = $this->signedIn($this->tenant)->postJson(self::BG."/budgets/{$made['budget']['id']}/versions", ['copy_from_version_id' => $made['version']['id']])->assertCreated()->json();
        $writer = $this->as($writerToken);
        $writer->putJson(self::BG."/budget-versions/{$draft['id']}/lines", ['lines' => []])->assertStatus(403)->assertJsonPath('code', 'BUDGET_SCOPE_REPLACE_FORBIDDEN');
        $writer->postJson(self::BG."/budgets/{$made['budget']['id']}/versions", ['copy_from_version_id' => $made['version']['id']])->assertStatus(403)->assertJsonPath('code', 'BUDGET_COPY_REQUIRES_FULL_SCOPE');
        $writer->postJson(self::BG."/budget-versions/{$draft['id']}/lines", $this->bline($this->tenant, '6300', '2026-04', '10', ['branch_id' => $south]))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED');
        $writer->postJson(self::BG."/budget-versions/{$draft['id']}/lines", $this->bline($this->tenant, '6300', '2026-04', '10'))->assertStatus(403);
        $writer->postJson(self::BG."/budget-versions/{$draft['id']}/lines", $this->bline($this->tenant, '6300', '2026-04', '10', ['branch_id' => $north]))->assertCreated();
        $southLine = DB::table('budget_lines')->where('budget_version_id', $draft['id'])->where('branch_id', $south)->value('id');
        $this->assertNotNull($southLine);
        $writer->deleteJson(self::BG."/budget-versions/{$draft['id']}/lines/{$southLine}")->assertNotFound();
        $this->assertTrue(DB::table('budget_lines')->where('id', $southLine)->exists());
    }

    public function test_the_export_is_a_csv_of_the_same_report_and_is_audited(): void
    {
        $made = $this->activeBudget($this->tenant, [$this->bline($this->tenant, '6100', '2026-03', '1000000')]);
        $this->spend('250000');

        $csv = $this->get(self::BG.'/budget-vs-actual/export?budget_id='.$made['budget']['id'])->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('6100', $csv);
        $this->assertStringContainsString('1000000.0000', $csv);
        $this->assertStringContainsString('-750000.0000', $csv);
        $this->assertStringContainsString('-75.00', $csv);
        $log = DB::table('audit_logs')->where('action', 'budget.report.exported')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, json_decode($log->changes, true)['after']['rows']);
    }
}
