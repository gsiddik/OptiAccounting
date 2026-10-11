<?php

namespace Tests\Feature\Reporting;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/** OA5 batch A: the advanced trial balance, the filtered general ledger with transaction-currency detail, and the account balance report. */
class AdvancedLedgerReportsTest extends TestCase
{
    use AccountingFixtures, Fixtures, ReportingFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reportingTenant();
        $this->standardLedger($this->tenant);
    }

    private function tb(array $query = [])
    {
        return $this->getJson(self::RP.'/trial-balance?'.http_build_query($query));
    }

    private function gl(array $query = [])
    {
        return $this->getJson(self::RP.'/general-ledger?'.http_build_query($query));
    }

    private function balance(array $query = [])
    {
        return $this->getJson(self::RP.'/reports/account-balance?'.http_build_query($query));
    }

    /** A USD sale of 100 booked at 15,000 (1,500,000 IDR) on 2026-02-10. */
    private function foreignSale(): void
    {
        $this->postedJournal($this->tenant, ['document_date' => '2026-02-10', 'posting_date' => '2026-02-10', 'description' => 'Penjualan USD', 'source_type' => 'TEST_SALE', 'source_id' => 'fx-1', 'lines' => [
            ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '1500000', 'transaction' => ['currency' => 'USD', 'amount' => '100', 'rate' => '15000']],
            ['account_id' => $this->account($this->tenant, '4100')->id, 'credit' => '1500000', 'transaction' => ['currency' => 'USD', 'amount' => '100', 'rate' => '15000']],
        ]]);
    }

    // ------------------------------------------------------------------------------------------ advanced trial balance

    public function test_the_trial_balance_of_a_fiscal_year_reconciles_and_reports_it(): void
    {
        $year = $this->fiscalYear($this->tenant, 'FY2026')->id;

        $tb = $this->tb(['fiscal_year_id' => $year])->assertOk()->json();

        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-12-31'], $tb['range']);
        $this->assertTrue($tb['complete']);
        $this->assertTrue($tb['reconciliation']['reconciled']);
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);
        $this->assertSame($tb['totals']['opening_debit'], $tb['totals']['opening_credit']);
        $this->assertSame($tb['totals']['ending_debit'], $tb['totals']['ending_credit']);
        $cash = collect($tb['data'])->firstWhere('code', '1110');
        $this->assertSame('6000000.0000', $cash['opening_debit']);
        $this->assertSame('2600000.0000', $cash['debit']);
        $this->assertSame('800000.0000', $cash['credit']);
        $this->assertSame('7800000.0000', $cash['ending_debit']);
        $this->assertSame($year, $tb['filters']['fiscal_year_id']);

        // Year to date: the same year, cut at a date inside it.
        $ytd = $this->tb(['fiscal_year_id' => $year, 'to' => '2026-01-31'])->assertOk()->json();
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-01-31'], $ytd['range']);
        $this->assertSame('2000000.0000', collect($ytd['data'])->firstWhere('code', '1110')['debit']);

        $this->tb(['fiscal_year_id' => $year, 'to' => '2027-01-31'])->assertStatus(422)->assertJsonPath('code', 'RANGE_OUTSIDE_FISCAL_YEAR');
        $this->tb(['fiscal_year_id' => (string) Str::uuid()])->assertNotFound()->assertJsonPath('code', 'FISCAL_YEAR_NOT_FOUND');
    }

    public function test_an_account_filter_makes_the_trial_balance_a_partial_view_that_does_not_claim_to_balance(): void
    {
        $revenue = $this->tb(['from' => '2026-01-01', 'to' => '2026-04-30', 'account_type' => 'REVENUE'])->assertOk()->json();

        $this->assertSame(['4100'], array_column($revenue['data'], 'code'));
        $this->assertSame('4400000.0000', $revenue['data'][0]['ending_credit']);
        $this->assertFalse($revenue['complete']);
        $this->assertNull($revenue['reconciliation']['reconciled'], 'a subset of accounts need not balance, so the report must not claim it');
        $this->assertSame('REVENUE', $revenue['filters']['account_type']);

        $ids = [$this->account($this->tenant, '1110')->id, $this->account($this->tenant, '6100')->id];
        $listed = $this->tb(['from' => '2026-01-01', 'to' => '2026-04-30', 'account_ids' => $ids])->assertOk()->json();
        $this->assertEqualsCanonicalizing(['1110', '6100'], array_column($listed['data'], 'code'));
        $this->assertFalse($listed['complete']);

        $range = $this->tb(['from' => '2026-01-01', 'to' => '2026-04-30', 'account_code_from' => '5000', 'account_code_to' => '6299'])->assertOk()->json();
        $this->assertEqualsCanonicalizing(['5100', '6100', '6200'], array_column($range['data'], 'code'));
        $this->tb(['account_code_from' => '6299', 'account_code_to' => '5000'])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_RANGE_INVALID');
        $this->tb(['account_type' => 'NONSENSE'])->assertStatus(422);
    }

    public function test_zero_rows_and_the_hierarchy_honour_the_account_filters(): void
    {
        $zero = $this->tb(['from' => '2026-01-01', 'to' => '2026-04-30', 'account_type' => 'LIABILITY', 'include_zero' => 1])->assertOk()->json();
        $codes = array_column($zero['data'], 'code');
        $this->assertContains('2110', $codes, 'a liability without activity is listed when zero rows are asked for');
        $this->assertNotContains('1110', $codes);
        $this->assertNotContains('4100', $codes);

        $tree = $this->tb(['from' => '2026-01-01', 'to' => '2026-04-30', 'account_type' => 'LIABILITY', 'hierarchy' => 1])->assertOk()->json();
        $headers = collect($tree['data'])->where('is_header', true)->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['2000', '2100', '2200'], $headers, 'only the ancestors of the selected accounts are rolled up');
    }

    public function test_a_transaction_currency_filter_selects_foreign_lines_and_needs_the_module_for_foreign_values(): void
    {
        $this->foreignSale();
        $window = ['from' => '2026-01-01', 'to' => '2026-04-30'];

        $usd = $this->tb($window + ['currency' => 'USD'])->assertOk()->json();
        $this->assertEqualsCanonicalizing(['1110', '4100'], array_column($usd['data'], 'code'));
        $this->assertSame('1500000.0000', collect($usd['data'])->firstWhere('code', '4100')['credit'], 'foreign lines are reported at their posted functional amount');
        $this->assertFalse($usd['complete']);
        $this->assertSame('USD', $usd['filters']['currency']);

        $idr = $this->tb($window + ['currency' => 'IDR'])->assertOk()->json();
        $this->assertSame('4400000.0000', collect($idr['data'])->firstWhere('code', '4100')['ending_credit'] ?? null, 'the functional currency selects every line that is not foreign');
        $this->tb($window + ['currency' => 'usd'])->assertStatus(422);

        // Without the multi-currency module a foreign currency is not a valid filter, the functional one always is.
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)
            ->where('module_id', DB::table('modules')->where('code', 'ACCOUNTING_MULTI_CURRENCY')->value('id'))->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->signedIn($this->tenant);
        $this->tb($window + ['currency' => 'USD'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_FILTER_UNAVAILABLE');
        $this->tb($window + ['currency' => 'IDR'])->assertOk();
    }

    // ------------------------------------------------------------------------------------------ general ledger

    public function test_the_general_ledger_filters_by_source_journal_type_account_range_and_currency(): void
    {
        $this->foreignSale();
        $window = ['from' => '2026-01-01', 'to' => '2026-04-30', 'per_page' => 200];

        $bySource = $this->gl($window + ['source_type' => 'TEST_SALE'])->assertOk()->json();
        $this->assertSame(2, $bySource['meta']['total']);
        $this->assertFalse($bySource['complete']);
        $this->assertSame('fx-1', $bySource['data'][0]['source_id']);

        $byType = $this->gl($window + ['journal_type' => 'SYSTEM', 'account_type' => 'EXPENSE'])->assertOk()->json();
        $this->assertEqualsCanonicalizing(['5100', '6100', '6200'], array_values(array_unique(array_column(array_column($byType['data'], 'account'), 'code'))));
        $this->assertSame('SYSTEM', $byType['data'][0]['journal_type']);

        $byRange = $this->gl($window + ['account_code_from' => '4000', 'account_code_to' => '4999'])->assertOk()->json();
        $this->assertSame(['4100'], array_values(array_unique(array_column(array_column($byRange['data'], 'account'), 'code'))));
        $this->assertSame(4, $byRange['meta']['total']); // 2026-01-15, 2026-02-10 (USD), 2026-02-20, 2026-04-05

        $usd = $this->gl($window + ['currency' => 'USD'])->assertOk()->json();
        $this->assertSame(2, $usd['meta']['total']);
        $this->assertSame('IDR', $usd['functional_currency']);

        // The filtered ledger is consistent with itself: the running balance continues from the filtered opening, line by line.
        $sales = $this->gl($window + ['account_id' => $this->account($this->tenant, '4100')->id, 'currency' => 'USD'])->assertOk()->json();
        $this->assertSame('0.0000', $sales['summary']['opening']);
        $this->assertSame('1500000.0000', $sales['summary']['closing']);
        $this->assertSame('1500000.0000', $sales['data'][0]['running_balance']);
    }

    public function test_a_general_ledger_row_carries_the_transaction_currency_snapshot_and_source_reference(): void
    {
        $this->foreignSale();
        $rows = $this->gl(['from' => '2026-02-01', 'to' => '2026-02-28', 'currency' => 'USD', 'account_id' => $this->account($this->tenant, '1110')->id])->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('USD', $row['currency']);
        $this->assertSame('1500000.0000', $row['debit'], 'the ledger sums the functional amount');
        $this->assertSame('100.0000', $row['foreign_debit']);
        $this->assertSame('0.0000', $row['foreign_credit']);
        $this->assertSame('15000.0000000000', $row['exchange_rate']);
        $this->assertFalse($row['is_fx_difference']);
        $this->assertSame('TEST_SALE', $row['source_type']);
        $this->assertSame('2026-02-10', $row['document_date']);

        // A line without a foreign leg shows the functional currency at rate 1.
        $plain = $this->gl(['from' => '2026-01-01', 'to' => '2026-01-31', 'account_id' => $this->account($this->tenant, '6100')->id])->json('data.0');
        $this->assertSame('IDR', $plain['currency']);
        $this->assertSame('1.0000000000', $plain['exchange_rate']);
    }

    public function test_the_csv_exports_cover_exactly_the_filtered_screen(): void
    {
        $this->foreignSale();
        $filters = ['from' => '2026-01-01', 'to' => '2026-04-30', 'account_type' => 'REVENUE', 'currency' => 'USD'];

        $screen = $this->gl($filters + ['per_page' => 200])->json();
        $csv = $this->csvRows($this->get(self::RP.'/general-ledger/export?'.http_build_query($filters))->assertOk()->streamedContent());
        $this->assertCount($screen['meta']['total'], array_slice($csv, 1));
        $this->assertSame(['Mata uang transaksi'], [$csv[0][11]]);
        $this->assertSame('USD', $csv[1][11]);
        $this->assertSame($screen['data'][0]['journal_number'], $csv[1][1]);

        $tbScreen = $this->tb($filters)->json();
        $tbCsv = $this->csvRows($this->get(self::RP.'/trial-balance/export?'.http_build_query($filters))->assertOk()->streamedContent());
        $this->assertSame(count($tbScreen['data']) + 2, count($tbCsv), 'header + accounts + total row');
        $this->assertSame(array_column($tbScreen['data'], 'code'), array_column(array_slice($tbCsv, 1, -1), 0));
    }

    public function test_the_ledger_and_trial_balance_stay_backward_compatible(): void
    {
        $tb = $this->tb(['from' => '2026-01-01', 'to' => '2026-03-31'])->assertOk()->json();
        foreach (['range', 'data', 'totals', 'reconciliation', 'complete'] as $key) {
            $this->assertArrayHasKey($key, $tb);
        }
        $this->assertTrue($tb['reconciliation']['reconciled']);

        $gl = $this->gl(['from' => '2026-01-01', 'to' => '2026-03-31', 'account_id' => $this->account($this->tenant, '1110')->id])->assertOk()->json();
        foreach (['range', 'account', 'summary', 'data', 'meta', 'complete'] as $key) {
            $this->assertArrayHasKey($key, $gl);
        }
        $this->assertTrue($gl['complete'], 'a single account is still a complete ledger of that account');
        foreach (['line_id', 'posting_date', 'journal_id', 'journal_number', 'journal_type', 'source_type', 'source_id', 'journal_description', 'journal_reference', 'description', 'reference',
            'account', 'branch_id', 'business_unit_id', 'cost_center_id', 'debit', 'credit', 'running_balance'] as $key) {
            $this->assertArrayHasKey($key, $gl['data'][0]);
        }
    }

    // ------------------------------------------------------------------------------------------ account balance

    public function test_the_account_balance_as_of_a_date_equals_the_ledger_and_includes_the_opening_balance(): void
    {
        $cash = $this->account($this->tenant, '1110')->id;

        $report = $this->balance(['account_id' => $cash, 'as_of' => '2026-03-31'])->assertOk()->json();

        $this->assertSame('AS_OF', $report['mode']);
        $this->assertSame('2026-03-31', $report['as_of']);
        $this->assertNull($report['range']);
        $this->assertSame('IDR', $report['functional_currency']);
        $this->assertCount(1, $report['data']);
        $this->assertSame('7200000.0000', $report['data'][0]['balance']);
        $this->assertSame('7200000.0000', $report['totals']['balance']);
        $this->assertTrue($report['complete']);
        $gl = $this->gl(['account_id' => $cash, 'from' => '2025-01-01', 'to' => '2026-03-31'])->json('summary.closing');
        $this->assertSame($gl, $report['data'][0]['balance'], 'the account balance is the ledger closing balance');

        // The day before the first posting of the year, and before the cutover.
        $this->assertSame('6000000.0000', $this->balance(['account_id' => $cash, 'as_of' => '2026-01-14'])->json('data.0.balance'));
        $this->assertSame([], $this->balance(['account_id' => $cash, 'as_of' => '2024-12-31'])->json('data'));

        // A credit-normal account reads positive on its own side.
        $this->assertSame('3000000.0000', $this->balance(['account_id' => $this->account($this->tenant, '2210')->id, 'as_of' => '2026-03-31'])->json('data.0.balance'));
    }

    public function test_the_account_balance_over_a_period_has_opening_movement_and_closing(): void
    {
        $revenue = $this->account($this->tenant, '4100')->id;

        $report = $this->balance(['account_id' => $revenue, 'from' => '2026-02-01', 'to' => '2026-02-28'])->assertOk()->json();

        $this->assertSame('PERIOD', $report['mode']);
        $this->assertSame(['from' => '2026-02-01', 'to' => '2026-02-28'], $report['range']);
        $row = $report['data'][0];
        $this->assertSame(['3000000.0000', '0.0000', '800000.0000', '3800000.0000'], [$row['opening'], $row['debit'], $row['credit'], $row['closing']]);
        $this->assertSame('CREDIT', $row['normal_balance']);

        $year = $this->balance(['account_id' => $revenue, 'fiscal_year_id' => $this->fiscalYear($this->tenant, 'FY2026')->id])->assertOk()->json('data.0');
        $this->assertSame(['1000000.0000', '3400000.0000', '4400000.0000'], [$year['opening'], $year['credit'], $year['closing']]);
        $this->balance(['account_id' => $revenue, 'as_of' => '2026-02-28', 'from' => '2026-02-01'])->assertStatus(422)->assertJsonPath('code', 'DATE_FILTER_CONFLICT');
    }

    public function test_the_account_balance_of_a_group_spans_its_descendants_and_a_range_or_type_selects_accounts(): void
    {
        $assets = $this->account($this->tenant, '1000')->id; // header: stands for every posting account below it

        $group = $this->balance(['account_id' => $assets, 'as_of' => '2026-03-31', 'hierarchy' => 1])->assertOk()->json();

        $this->assertSame('1000', $group['account']['code']);
        $this->assertSame('14100000.0000', $group['totals']['balance']);
        $this->assertSame('DEBIT', $group['totals']['normal_balance']);
        $byCode = collect($group['data'])->keyBy('code');
        $this->assertSame('14100000.0000', $byCode['1000']['balance'], 'the header rolls up everything below it');
        $this->assertSame('11600000.0000', $byCode['1100']['balance']);
        $this->assertSame(['1110', '1120', '1230'], collect($group['data'])->where('is_header', false)->pluck('code')->all());

        $range = $this->balance(['account_code_from' => '2100', 'account_code_to' => '2299', 'as_of' => '2026-03-31'])->assertOk()->json();
        $this->assertEqualsCanonicalizing(['2140', '2210'], array_column($range['data'], 'code'));
        $this->assertFalse($range['complete']);

        $type = $this->balance(['account_type' => 'EQUITY', 'as_of' => '2026-03-31'])->assertOk()->json();
        $this->assertSame(['3100'], array_column($type['data'], 'code'));

        // A group whose accounts the other filters exclude is empty, never unfiltered.
        $empty = $this->balance(['account_id' => $assets, 'account_type' => 'REVENUE', 'as_of' => '2026-03-31'])->assertOk()->json();
        $this->assertSame([], $empty['data']);
        $this->balance(['account_id' => (string) Str::uuid()])->assertNotFound()->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
    }

    public function test_the_account_balance_defaults_to_the_business_date_and_echoes_it(): void
    {
        $report = $this->balance()->assertOk()->json();

        $this->assertSame($this->tenant->businessDate(), $report['as_of']);
        $this->assertSame('AS_OF', $report['mode']);
    }

    public function test_the_account_balance_follows_dimension_filters_and_data_scope(): void
    {
        [$north, $south] = $this->twoBranches($this->tenant);
        $this->book($this->tenant, '2026-02-12', '1110', '4100', '70000', ['branch_id' => $north]);
        $this->book($this->tenant, '2026-02-13', '1110', '4100', '30000', ['branch_id' => $south]);
        $cash = $this->account($this->tenant, '1110')->id;
        $sales = $this->account($this->tenant, '4100')->id;

        $filtered = $this->balance(['account_id' => $sales, 'as_of' => '2026-02-28', 'branch_id' => $north])->assertOk()->json();
        $this->assertSame('70000.0000', $filtered['data'][0]['balance']);
        $this->assertFalse($filtered['complete']);

        $permissions = ['accounting.gl.view'];
        $scoped = $this->as($this->branchToken($this->tenant, $permissions, 'BRANCH', $south))->getJson(self::RP.'/reports/account-balance?'.http_build_query(['account_id' => $sales, 'as_of' => '2026-02-28']))->assertOk()->json();
        $this->assertSame('30000.0000', $scoped['data'][0]['balance'], 'a branch user sees only their branch, totals included');
        $this->assertSame('30000.0000', $scoped['totals']['balance']);
        $this->assertFalse($scoped['complete']);
        unset($cash);
    }

    public function test_the_account_balance_needs_the_gl_permission_and_the_reporting_module(): void
    {
        $this->as($this->tenantToken($this->member($this->tenant, ['accounting.trial_balance.view'])[0], $this->tenant))
            ->getJson(self::RP.'/reports/account-balance')->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');

        $this->signedIn($this->tenant);
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)
            ->where('module_id', DB::table('modules')->where('code', 'ACCOUNTING_REPORTING')->value('id'))->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->signedIn($this->tenant);
        $this->balance()->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
        $this->tb()->assertOk(); // the legacy reports keep working without the reporting module
    }
}
