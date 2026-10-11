<?php

namespace Tests\Feature\Reporting;

use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/** OA5 batch D: drill-down from a statement line to its accounts and to the general ledger query behind them. */
class DrilldownTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReportingFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reportingTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->standardLedger($this->tenant);
        $this->publishedDefinitions($this->tenant);
    }

    private function drill(array $query)
    {
        return $this->getJson(self::RP.'/reports/drilldown?'.http_build_query($query));
    }

    private function drillOk(array $query): array
    {
        return $this->drill($query)->assertOk()->json();
    }

    /** The general ledger as the drill-down tells the caller to ask for it. */
    private function ledgerOf(array $glQuery): array
    {
        return $this->getJson(self::RP.'/general-ledger?'.http_build_query($glQuery))->assertOk()->json();
    }

    private function sum(array $accounts, string $field = 'contribution'): string
    {
        return (string) array_reduce($accounts, fn (BigDecimal $carry, array $a) => $carry->plus($a[$field]), BigDecimal::zero());
    }

    // ------------------------------------------------------------------------------------------ the accounts behind a line

    public function test_an_account_line_lists_its_account_and_the_general_ledger_query_that_reproduces_the_amount(): void
    {
        $drill = $this->drillOk(['statement' => 'BALANCE_SHEET', 'line' => 'ACC_1110', 'as_of' => '2026-03-31']);

        $this->assertSame('BALANCE_SHEET', $drill['statement']);
        $this->assertSame('2026-03-31', $drill['as_of']);
        $this->assertSame(['ACC_1110', 'ACCOUNT', '7200000.0000'], [$drill['line']['code'], $drill['line']['line_type'], $drill['line']['amount']]);
        $this->assertTrue($drill['complete']);
        $this->assertSame([], $drill['components']);
        $this->assertCount(1, $drill['accounts']);
        $account = $drill['accounts'][0];
        $this->assertSame(['1110', '7200000.0000', '7200000.0000', 'ACC_1110'], [$account['code'], $account['amount'], $account['contribution'], $account['line_code']]);
        $this->assertSame(['account_id' => $account['id'], 'from' => '2026-01-01', 'to' => '2026-03-31'], $account['gl_query'], 'a balance sheet account is followed from the start of its fiscal year');
        $this->assertSame(['7200000.0000', '0.0000'], [$drill['accounts_total'], $drill['not_explained_by_accounts']]);

        // The general ledger answers with the same figure: opening balance carried into the year + the year's movement.
        $ledger = $this->ledgerOf($account['gl_query']);
        $this->assertSame('7200000.0000', $ledger['summary']['closing']);
        $this->assertSame('1110', $ledger['account']['code']);
    }

    public function test_a_profit_and_loss_account_is_followed_over_the_range_of_the_statement(): void
    {
        $drill = $this->drillOk(['statement' => 'PROFIT_LOSS', 'line' => 'ACC_4100', 'from' => '2026-01-01', 'to' => '2026-02-28']);

        $this->assertSame('2800000.0000', $drill['line']['amount']);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-02-28'], $drill['range']);
        $query = $drill['accounts'][0]['gl_query'];
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-02-28'], ['from' => $query['from'], 'to' => $query['to']]);
        $ledger = $this->ledgerOf($query);
        $this->assertSame(['0.0000', '2800000.0000'], [$ledger['summary']['debit'], $ledger['summary']['credit']], 'the ledger movement of the range is the statement amount');
        $this->assertSame('1000000.0000', $ledger['summary']['opening'], 'last year\'s revenue was never closed, so the ledger balance carries it; the statement shows the movement only');
        $this->assertStringContainsString('movement', $drill['general_ledger']['note']);
        $this->assertArrayNotHasKey('as_of', $drill);
    }

    public function test_a_subtotal_is_explained_by_its_lines_and_every_account_under_them(): void
    {
        $drill = $this->drillOk(['statement' => 'BALANCE_SHEET', 'line' => 'TOT_1000', 'as_of' => '2026-03-31']);

        $this->assertSame(['SUBTOTAL', '14100000.0000'], [$drill['line']['line_type'], $drill['line']['amount']]);
        $this->assertNotSame([], $drill['components']);
        $this->assertSame('14100000.0000', $this->sum($drill['components'], 'amount'), 'the components add up to the subtotal');
        $this->assertSame('14100000.0000', $this->sum($drill['accounts']));
        $this->assertSame('0.0000', $drill['not_explained_by_accounts']);
        $codes = array_column($drill['accounts'], 'code');
        $this->assertSame(['1110', '1120', '1230'], $codes);
        foreach ($drill['accounts'] as $account) {
            $this->assertSame(['account_id', 'from', 'to'], array_keys($account['gl_query']));
        }
    }

    public function test_a_calculated_total_shows_the_sign_each_part_has_in_it(): void
    {
        $drill = $this->drillOk(['statement' => 'PROFIT_LOSS', 'line' => 'NET_RESULT_CALC', 'from' => '2026-01-01', 'to' => '2026-03-31']);

        $this->assertSame(['CALCULATED_TOTAL', '1900000.0000'], [$drill['line']['line_type'], $drill['line']['amount']]);
        $signed = array_map(fn (array $c) => $c['sign'] === '-' ? BigDecimal::of($c['amount'])->negated() : BigDecimal::of($c['amount']), $drill['components']);
        $this->assertSame('1900000.0000', (string) array_reduce($signed, fn (BigDecimal $c, BigDecimal $v) => $c->plus($v), BigDecimal::zero())->toScale(4), 'signed components add up to the total');
        $this->assertSame('1900000.0000', $this->sum($drill['accounts']), 'revenue contributes positively, expenses negatively');
        $contributions = array_column($drill['accounts'], 'contribution', 'code');
        $this->assertSame('2800000.0000', $contributions['4100']);
        $this->assertSame('-400000.0000', $contributions['5100']);
        $this->assertSame('-300000.0000', $contributions['6100']);
        $this->assertSame('-200000.0000', $contributions['6200']);
    }

    public function test_a_system_line_is_explained_by_the_accounts_that_make_the_figure(): void
    {
        $current = $this->drillOk(['statement' => 'BALANCE_SHEET', 'line' => 'CURRENT_RESULT', 'as_of' => '2026-03-31']);
        $this->assertSame(['SYSTEM', '2700000.0000'], [$current['line']['line_type'], $current['line']['amount']]);
        $this->assertNotNull($current['explanation']);
        $this->assertSame('2700000.0000', $this->sum($current['accounts']), 'revenue less expense of every account not yet closed');
        $this->assertSame('0.0000', $current['not_explained_by_accounts']);
        $this->assertSame(['4100', '5100', '6100', '6200'], array_column($current['accounts'], 'code'));

        $cash = $this->drillOk(['statement' => 'CASH_FLOW', 'line' => 'CASH_CLOSING', 'from' => '2026-01-01', 'to' => '2026-03-31']);
        $this->assertSame('11600000.0000', $cash['line']['amount']);
        $this->assertSame(['1110' => '7200000.0000', '1120' => '4400000.0000'], array_column($cash['accounts'], 'amount', 'code'));
        $this->assertSame('0.0000', $cash['not_explained_by_accounts']);

        $opening = $this->drillOk(['statement' => 'CASH_FLOW', 'line' => 'CASH_OPENING', 'from' => '2026-01-01', 'to' => '2026-03-31']);
        $this->assertSame('9800000.0000', $opening['line']['amount']);
        $this->assertSame('9800000.0000', $this->sum($opening['accounts']));

        // The net change is a calculation over the net result (a system figure) and the sections: every part is traced to accounts.
        $change = $this->drillOk(['statement' => 'CASH_FLOW', 'line' => 'CF_NET_CHANGE', 'from' => '2026-01-01', 'to' => '2026-03-31']);
        $this->assertSame(['CALCULATED_TOTAL', '1800000.0000'], [$change['line']['line_type'], $change['line']['amount']]);
        $this->assertSame('1800000.0000', $this->sum($change['accounts']), 'revenue and expense accounts of the net result, then the balance sheet accounts that moved');
        $this->assertSame('0.0000', $change['not_explained_by_accounts']);
        $this->assertContains('4100', array_column($change['accounts'], 'code'));
        $this->assertContains('1230', array_column($change['accounts'], 'code'));
    }

    public function test_a_cash_flow_account_line_shows_its_cash_effect_and_the_ledger_it_comes_from(): void
    {
        $drill = $this->drillOk(['statement' => 'CASH_FLOW', 'line' => 'ACC_1230', 'from' => '2026-01-01', 'to' => '2026-03-31']);

        $this->assertSame('-500000.0000', $drill['line']['amount'], 'an asset that grew used cash');
        $this->assertSame('-500000.0000', $drill['accounts'][0]['contribution']);
        $ledger = $this->ledgerOf($drill['accounts'][0]['gl_query']);
        $this->assertSame('500000.0000', $ledger['summary']['debit']);
    }

    public function test_a_line_that_shows_nothing_can_still_be_followed_and_lists_no_accounts(): void
    {
        $screen = $this->statement('profit-loss', ['from' => '2026-02-01', 'to' => '2026-02-28']);
        $this->assertArrayNotHasKey('ACC_5100', $this->linesByCode($screen), 'hidden on screen: zero in February');

        $drill = $this->drillOk(['statement' => 'PROFIT_LOSS', 'line' => 'ACC_5100', 'from' => '2026-02-01', 'to' => '2026-02-28']);
        $this->assertSame(['0.0000', []], [$drill['line']['amount'], $drill['accounts']]);

        $withZero = $this->drillOk(['statement' => 'PROFIT_LOSS', 'line' => 'ACC_5100', 'from' => '2026-02-01', 'to' => '2026-02-28', 'include_zero' => '1']);
        $this->assertSame(['5100'], array_column($withZero['accounts'], 'code'));
    }

    public function test_the_ledger_lists_closing_journals_that_a_period_statement_leaves_out_and_the_drilldown_says_so(): void
    {
        $this->closingJournal($this->tenant, '2025-12-31', ['4100' => '1000000', '6200' => '-200000']);

        $drill = $this->drillOk(['statement' => 'PROFIT_LOSS', 'line' => 'ACC_4100', 'fiscal_year_id' => $this->fiscalYear($this->tenant, 'FY2025')->id]);

        $this->assertSame('1000000.0000', $drill['line']['amount'], 'the statement is not erased by closing the year');
        $this->assertTrue($drill['general_ledger']['closing_journals_listed']);
        $this->assertStringContainsString('closing', $drill['general_ledger']['note']);
        $ledger = $this->ledgerOf($drill['accounts'][0]['gl_query']);
        $this->assertSame(['1000000.0000', '1000000.0000', '0.0000'], [$ledger['summary']['credit'], $ledger['summary']['debit'], $ledger['summary']['closing']], 'the ledger shows the closing entry that brings the account to zero');
    }

    public function test_dimension_filters_travel_into_the_ledger_query(): void
    {
        [$north] = $this->twoBranches($this->tenant);
        $this->book($this->tenant, '2026-02-10', '1110', '4100', '700000', ['branch_id' => $north]);

        $drill = $this->drillOk(['statement' => 'PROFIT_LOSS', 'line' => 'ACC_4100', 'from' => '2026-02-01', 'to' => '2026-02-28', 'branch_id' => $north]);

        $this->assertSame('700000.0000', $drill['line']['amount']);
        $this->assertFalse($drill['complete']);
        $this->assertSame($north, $drill['accounts'][0]['gl_query']['branch_id']);
        $this->assertSame('700000.0000', $this->ledgerOf($drill['accounts'][0]['gl_query'])['summary']['credit']);
    }

    // ------------------------------------------------------------------------------------------ errors and access

    public function test_an_unknown_line_a_header_and_bad_input_are_refused(): void
    {
        $this->drill(['statement' => 'PROFIT_LOSS', 'line' => 'NO_SUCH_LINE', 'from' => '2026-01-01', 'to' => '2026-03-31'])->assertNotFound()->assertJsonPath('code', 'REPORT_LINE_NOT_FOUND');
        $id = $this->postJson(self::RP.'/report-definitions', ['statement_type' => 'PROFIT_LOSS', 'name' => 'Dengan judul'])->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => [
            ['code' => 'HDR', 'label' => 'Pendapatan', 'line_type' => 'HEADER'],
            ['code' => 'REV', 'label' => 'Penjualan', 'line_type' => 'ACCOUNT_GROUP', 'parent_code' => 'HDR', 'balance_side' => 'CREDIT'],
        ]])->assertOk();
        $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => [['line_code' => 'REV', 'account_id' => $this->account($this->tenant, '4000')->id, 'include_descendants' => true]]])->assertOk();
        $this->postJson(self::RP."/report-definitions/{$id}/publish")->assertOk();
        $range = ['from' => '2026-01-01', 'to' => '2026-03-31', 'definition_id' => $id];
        $this->drill(['statement' => 'PROFIT_LOSS', 'line' => 'HDR'] + $range)->assertUnprocessable()->assertJsonPath('code', 'REPORT_LINE_NOT_DRILLABLE');
        $this->assertSame('2800000.0000', $this->drillOk(['statement' => 'PROFIT_LOSS', 'line' => 'REV'] + $range)['line']['amount']);
        $this->drill(['line' => 'ACC_4100'])->assertUnprocessable();
        $this->drill(['statement' => 'PROFIT_LOSS'])->assertUnprocessable();
        $this->drill(['statement' => 'TRIAL_BALANCE', 'line' => 'ACC_4100'])->assertUnprocessable();
        $this->drill(['statement' => 'PROFIT_LOSS', 'line' => 'ACC_4100', 'as_of' => '2026-03-31'])->assertUnprocessable()->assertJsonPath('code', 'DATE_FILTER_INVALID');
        $this->drill(['statement' => 'BALANCE_SHEET', 'line' => 'ACC_1110', 'from' => '2026-01-01'])->assertUnprocessable()->assertJsonPath('code', 'DATE_FILTER_INVALID');
        $this->drill(['statement' => 'PROFIT_LOSS', 'line' => 'ACC_4100', 'definition_id' => fake()->uuid()])->assertNotFound();
    }

    public function test_a_drilldown_needs_the_ledger_permission_and_the_view_permission_of_the_statement(): void
    {
        $cases = [
            [['statement' => 'BALANCE_SHEET', 'line' => 'ACC_1110', 'as_of' => '2026-03-31'], 'accounting.reporting.balance_sheet.view'],
            [['statement' => 'PROFIT_LOSS', 'line' => 'ACC_4100', 'from' => '2026-01-01', 'to' => '2026-03-31'], 'accounting.reporting.pnl.view'],
            [['statement' => 'CASH_FLOW', 'line' => 'ACC_1230', 'from' => '2026-01-01', 'to' => '2026-03-31'], 'accounting.reporting.cash_flow.view'],
        ];
        foreach ($cases as [$query, $view]) {
            $url = self::RP.'/reports/drilldown?'.http_build_query($query);
            $this->as($this->memberToken($this->tenant, ['accounting.gl.view']))->getJson($url)->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED')->assertJsonPath('details.permission', $view);
            $this->as($this->memberToken($this->tenant, [$view]))->getJson($url)->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
            $this->as($this->memberToken($this->tenant, [$view, 'accounting.gl.view']))->getJson($url)->assertOk();
        }
    }
}
