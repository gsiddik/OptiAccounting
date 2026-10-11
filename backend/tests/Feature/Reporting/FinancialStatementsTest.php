<?php

namespace Tests\Feature\Reporting;

use App\Domain\Identity\Models\Tenant;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/** OA5 batch C: the balance sheet and the profit and loss statement over the deterministic two-year ledger (see ReportingFixtures). */
class FinancialStatementsTest extends TestCase
{
    use AccountingFixtures, Fixtures, ReportingFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reportingTenant();
        $this->standardLedger($this->tenant);
    }

    private function balanceSheet(array $query = []): array
    {
        return $this->statement('balance-sheet', $query);
    }

    private function profitLoss(array $query = []): array
    {
        return $this->statement('profit-loss', $query);
    }

    /** Defaults for one statement type, published without the line of one account (the account stays in the ledger, off the statement). */
    private function publishWithout(string $type, string $accountCode): void
    {
        $draft = $this->postJson(self::RP.'/report-definitions/defaults', ['statement_types' => [$type]])->assertCreated()->json('created.0.id');
        $kept = [];
        foreach ($this->getJson(self::RP."/report-definitions/{$draft}")->json('lines') as $line) {
            if ($line['code'] !== 'ACC_'.$accountCode) {
                $kept[] = array_intersect_key($line, array_flip(['code', 'label', 'line_type', 'parent_code', 'display_order', 'balance_side', 'cash_flow_category', 'system_key', 'operands', 'show_zero', 'emphasis', 'notes']));
            }
        }
        $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => $kept])->assertOk();
        $this->postJson(self::RP."/report-definitions/{$draft}/publish")->assertOk();
    }

    // ------------------------------------------------------------------------------------------ balance sheet

    public function test_the_balance_sheet_is_built_from_posted_lines_and_assets_equal_liabilities_plus_equity(): void
    {
        $this->publishedDefinitions($this->tenant);

        $bs = $this->balanceSheet(['as_of' => '2026-03-31']);

        $this->assertSame('BALANCE_SHEET', $bs['statement']);
        $this->assertSame('2026-03-31', $bs['as_of']);
        $this->assertSame('IDR', $bs['currency']);
        $this->assertTrue($bs['complete']);
        $this->assertSame(['id', 'code', 'name', 'version', 'status', 'effective_from', 'effective_to'], array_keys($bs['definition']));
        $this->assertSame(1, $bs['definition']['version']);

        $this->assertSame('7200000.0000', $this->accountAmount($bs, '1110'));
        $this->assertSame('4400000.0000', $this->accountAmount($bs, '1120'));
        $this->assertSame('2500000.0000', $this->accountAmount($bs, '1230'));
        $this->assertSame('3000000.0000', $this->accountAmount($bs, '2210'));
        $this->assertSame('400000.0000', $this->accountAmount($bs, '2140'));
        $this->assertSame('8000000.0000', $this->accountAmount($bs, '3100'));

        $lines = $this->linesByCode($bs);
        $this->assertSame('14100000.0000', $lines['TOT_1000']['amount']);
        $this->assertSame('3400000.0000', $lines['TOT_2000']['amount']);
        $this->assertSame('10700000.0000', $lines['TOT_3000']['amount'], 'equity includes the current result');
        $this->assertSame('2700000.0000', $lines['CURRENT_RESULT']['amount']);
        $this->assertSame('14100000.0000', $lines['TOT_LIABILITIES_EQUITY']['amount']);

        $integrity = $bs['integrity'];
        $this->assertSame('14100000.0000', $integrity['assets']);
        $this->assertSame('14100000.0000', $integrity['liabilities_and_equity']);
        $this->assertSame('0.0000', $integrity['difference']);
        $this->assertTrue($integrity['balanced']);
        $this->assertTrue($integrity['reliable']);
        $this->assertSame('2700000.0000', $integrity['current_result']);
        $this->assertSame([], $integrity['unmapped']);
        $this->assertSame([], $bs['warnings']);
    }

    public function test_the_lines_are_ordered_with_a_subtotal_after_the_lines_it_totals(): void
    {
        $this->publishedDefinitions($this->tenant);

        $lines = $this->balanceSheet(['as_of' => '2026-03-31'])['lines'];
        $codes = array_column($lines, 'code');

        $this->assertLessThan(array_search('TOT_1000', $codes, true), array_search('ACC_1230', $codes, true), 'a subtotal follows its children');
        $this->assertLessThan(array_search('TOT_1200', $codes, true), array_search('ACC_1230', $codes, true));
        $this->assertLessThan(array_search('TOT_1000', $codes, true), array_search('TOT_1200', $codes, true));
        $this->assertLessThan(array_search('TOT_2000', $codes, true), array_search('ACC_2210', $codes, true));
        $this->assertSame('TOT_LIABILITIES_EQUITY', end($codes));

        $byCode = collect($lines)->keyBy('code');
        $this->assertSame(0, $byCode['TOT_1000']['depth']);
        $this->assertSame(1, $byCode['TOT_1200']['depth']);
        $this->assertSame(2, $byCode['ACC_1230']['depth']);
        $this->assertSame('TOT_1200', $byCode['ACC_1230']['parent_code']);
        $this->assertSame('TOT_1000', $byCode['TOT_1200']['parent_code']);
        $this->assertNull($byCode['TOT_1000']['parent_code']);
        foreach ($lines as $line) {
            $this->assertSame(['id', 'code', 'label', 'line_type', 'depth', 'parent_code', 'amount', 'emphasis', 'accounts', 'balance_side', 'system_key', 'cash_flow_category', 'notes'], array_keys($line));
        }
    }

    public function test_a_historical_balance_sheet_is_the_ledger_as_it_stood_on_that_date(): void
    {
        $this->publishedDefinitions($this->tenant);

        $end2025 = $this->balanceSheet(['as_of' => '2025-12-31']);
        $this->assertSame('6000000.0000', $this->accountAmount($end2025, '1110'));
        $this->assertSame('3800000.0000', $this->accountAmount($end2025, '1120'));
        $this->assertSame('800000.0000', $end2025['integrity']['current_result']);
        $this->assertSame('11800000.0000', $end2025['integrity']['assets']);
        $this->assertTrue($end2025['integrity']['balanced']);

        $opening = $this->balanceSheet(['as_of' => '2025-01-01']);
        $this->assertSame('5000000.0000', $this->accountAmount($opening, '1110'), 'the opening balances are carried');
        $this->assertSame('0.0000', $opening['integrity']['current_result']);
        $this->assertTrue($opening['integrity']['balanced']);

        $before = $this->balanceSheet(['as_of' => '2024-12-31']);
        $this->assertSame('0.0000', $before['integrity']['assets']);
        $this->assertTrue($before['integrity']['balanced']);
    }

    public function test_without_a_date_the_balance_sheet_uses_the_business_date_and_says_so(): void
    {
        $this->publishedDefinitions($this->tenant);

        $bs = $this->balanceSheet();

        $this->assertSame(Tenant::query()->findOrFail($this->tenant->id)->businessDate(), $bs['as_of']);
    }

    public function test_an_account_the_definition_leaves_out_is_listed_and_the_difference_is_shown_not_absorbed(): void
    {
        $this->publishWithout('BALANCE_SHEET', '1230');

        $bs = $this->balanceSheet(['as_of' => '2026-03-31']);

        $integrity = $bs['integrity'];
        $this->assertFalse($integrity['balanced']);
        $this->assertFalse($integrity['reliable']);
        $this->assertSame('11600000.0000', $integrity['assets']);
        $this->assertSame('14100000.0000', $integrity['liabilities_and_equity']);
        $this->assertSame('-2500000.0000', $integrity['difference']);
        $this->assertSame([['id' => $this->account($this->tenant, '1230')->id, 'code' => '1230', 'name' => 'Peralatan', 'account_type' => 'ASSET', 'amount' => '2500000.0000']], $integrity['unmapped']);
        $this->assertSame(['UNMAPPED_ACCOUNT_WITH_BALANCE', 'STATEMENT_DIFFERENCE'], array_column($bs['warnings'], 'code'));
        $this->assertNull($this->accountAmount($bs, '1230'));
        $this->assertSame('11600000.0000', $this->linesByCode($bs)['TOT_1000']['amount'], 'the statement shows what it covers; the imbalance is not hidden');
        $this->assertSame('14100000.0000', $this->linesByCode($bs)['TOT_LIABILITIES_EQUITY']['amount']);
    }

    public function test_zero_lines_are_left_out_unless_asked_for_and_never_change_a_total(): void
    {
        $this->publishedDefinitions($this->tenant);

        $plain = $this->balanceSheet(['as_of' => '2026-03-31']);
        $full = $this->balanceSheet(['as_of' => '2026-03-31', 'include_zero' => 1]);

        $this->assertNull($this->accountAmount($plain, '1130'), 'receivables have no balance');
        $this->assertSame('0.0000', $this->accountAmount($full, '1130'));
        $this->assertGreaterThan(count($plain['lines']), count($full['lines']));
        $this->assertSame($plain['integrity'], $full['integrity']);
        $this->assertSame($this->linesByCode($plain)['TOT_1000']['amount'], $this->linesByCode($full)['TOT_1000']['amount']);
    }

    public function test_a_year_end_closing_moves_the_result_to_retained_earnings_and_the_sheet_still_balances(): void
    {
        $this->publishedDefinitions($this->tenant);
        $before = $this->balanceSheet(['as_of' => '2026-03-31']);

        $this->closingJournal($this->tenant, '2025-12-31', ['4100' => '1000000', '6200' => '-200000']);
        $after = $this->balanceSheet(['as_of' => '2026-03-31']);

        $this->assertSame('800000.0000', $this->accountAmount($after, '3200'));
        $this->assertSame('1900000.0000', $after['integrity']['current_result'], 'only the open year remains in the current result');
        $this->assertSame($before['integrity']['assets'], $after['integrity']['assets']);
        $this->assertSame($before['integrity']['liabilities_and_equity'], $after['integrity']['liabilities_and_equity']);
        $this->assertTrue($after['integrity']['balanced']);
        $this->assertSame('0.0000', $this->balanceSheet(['as_of' => '2025-12-31'])['integrity']['current_result'], 'at the closing date the year is closed');
    }

    public function test_a_narrower_scope_or_filter_marks_the_sheet_partial_and_never_claims_it_balances(): void
    {
        [$north, $south] = $this->twoBranches($this->tenant);
        $this->book($this->tenant, '2026-04-10', '1110', '4100', '500000', ['branch_id' => $north]);
        $this->book($this->tenant, '2026-04-11', '1120', '4100', '300000', ['branch_id' => $south]);
        $this->publishedDefinitions($this->tenant);

        $branch = $this->balanceSheet(['as_of' => '2026-04-30', 'branch_id' => $north]);
        $this->assertFalse($branch['complete']);
        $this->assertNull($branch['integrity']['balanced']);
        $this->assertSame('500000.0000', $branch['integrity']['assets']);
        $this->assertSame('PARTIAL_LEDGER', $branch['warnings'][0]['code']);
        $this->assertSame(['branch_id' => $north, 'include_zero' => false], $branch['filters']);

        $whole = $this->balanceSheet(['as_of' => '2026-04-30']);
        $this->assertTrue($whole['complete']);
        $this->assertTrue($whole['integrity']['balanced']);
    }

    public function test_the_version_of_the_definition_effective_on_the_date_is_used_so_history_keeps_its_layout(): void
    {
        $this->publishedDefinitions($this->tenant, '2025-01-01');
        $v1 = $this->getJson(self::RP.'/report-definitions?statement_type=BALANCE_SHEET')->json('data.0.id');
        $v2 = $this->postJson(self::RP."/report-definitions/{$v1}/new-version")->assertCreated()->json('id');
        $line = collect($this->getJson(self::RP."/report-definitions/{$v2}")->json('lines'))->firstWhere('code', 'ACC_1110');
        $this->patchJson(self::RP."/report-definitions/{$v2}/lines/{$line['id']}", ['label' => 'Kas dan setara kas'])->assertOk();
        $this->postJson(self::RP."/report-definitions/{$v2}/publish", ['effective_from' => '2026-01-01'])->assertOk();

        $old = $this->balanceSheet(['as_of' => '2025-12-31']);
        $new = $this->balanceSheet(['as_of' => '2026-01-01']);
        $explicit = $this->balanceSheet(['as_of' => '2026-01-01', 'definition_id' => $v1]);

        $this->assertSame(1, $old['definition']['version']);
        $this->assertSame('Kas', $this->linesByCode($old)['ACC_1110']['label']);
        $this->assertSame(2, $new['definition']['version']);
        $this->assertSame('Kas dan setara kas', $this->linesByCode($new)['ACC_1110']['label']);
        $this->assertSame(1, $explicit['definition']['version'], 'an explicit definition wins');
        $this->assertSame($old['integrity']['assets'], $this->balanceSheet(['as_of' => '2025-12-31', 'definition_id' => $v2])['integrity']['assets'], 'the layout changes presentation, not the ledger');
    }

    public function test_a_statement_needs_a_published_valid_definition_and_names_what_is_wrong(): void
    {
        $this->getJson(self::RP.'/reports/balance-sheet?as_of=2026-03-31')->assertUnprocessable()->assertJsonPath('code', 'REPORT_DEFINITION_NOT_FOUND');
        $this->getJson(self::RP.'/reports/profit-loss?from=2026-01-01&to=2026-03-31')->assertUnprocessable()->assertJsonPath('code', 'REPORT_DEFINITION_NOT_FOUND');

        $draft = $this->postJson(self::RP.'/report-definitions/defaults', ['statement_types' => ['BALANCE_SHEET']])->assertCreated()->json('created.0.id');
        $warned = $this->getJson(self::RP."/reports/balance-sheet?as_of=2026-03-31&definition_id={$draft}")->assertOk()->json();
        $this->assertSame('DEFINITION_IS_DRAFT', $warned['warnings'][0]['code'], 'a draft can be previewed, and says so');

        $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => [['code' => 'A', 'label' => 'A', 'line_type' => 'ACCOUNT', 'balance_side' => 'DEBIT']]])->assertOk();
        $this->getJson(self::RP."/reports/balance-sheet?as_of=2026-03-31&definition_id={$draft}")->assertUnprocessable()
            ->assertJsonPath('code', 'REPORT_DEFINITION_INVALID')->assertJsonPath('details.errors.0.code', 'REQUIRED_SYSTEM_LINE_MISSING');

        $pl = $this->postJson(self::RP.'/report-definitions/defaults', ['statement_types' => ['PROFIT_LOSS']])->assertCreated()->json('created.0.id');
        $this->getJson(self::RP."/reports/balance-sheet?as_of=2026-03-31&definition_id={$pl}")->assertNotFound()->assertJsonPath('code', 'REPORT_DEFINITION_NOT_FOUND');
        $this->getJson(self::RP.'/reports/balance-sheet?as_of=2026-03-31&definition_id='.fake()->uuid())->assertNotFound();
        $this->getJson(self::RP.'/reports/balance-sheet?as_of=31-03-2026')->assertUnprocessable();
    }

    // ------------------------------------------------------------------------------------------ profit and loss

    public function test_the_profit_and_loss_of_a_range_counts_only_the_activity_inside_it(): void
    {
        $this->publishedDefinitions($this->tenant);

        $pl = $this->profitLoss(['from' => '2026-01-01', 'to' => '2026-03-31']);

        $this->assertSame('PROFIT_LOSS', $pl['statement']);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-03-31'], $pl['range']);
        $this->assertTrue($pl['complete']);
        $this->assertSame('2800000.0000', $this->accountAmount($pl, '4100'), 'the January and February sales; April and 2025 are outside');
        $this->assertSame('300000.0000', $this->accountAmount($pl, '6100'));
        $this->assertSame('200000.0000', $this->accountAmount($pl, '6200'));
        $this->assertSame('400000.0000', $this->accountAmount($pl, '5100'));
        $lines = $this->linesByCode($pl);
        $this->assertSame('2800000.0000', $lines['TOT_4000']['amount']);
        $this->assertSame('400000.0000', $lines['TOT_5000']['amount']);
        $this->assertSame('500000.0000', $lines['TOT_6000']['amount']);
        $this->assertSame('1900000.0000', $lines['NET_RESULT_CALC']['amount']);
        $this->assertSame(['ledger_result' => '1900000.0000', 'mapped_result' => '1900000.0000', 'difference' => '0.0000', 'reliable' => true, 'unmapped' => []], $pl['integrity']);
        $this->assertSame([], $pl['warnings']);
    }

    public function test_the_first_and_the_last_day_of_the_range_are_included_and_the_days_around_it_are_not(): void
    {
        $this->publishedDefinitions($this->tenant);
        $revenue = fn (array $q) => $this->linesByCode($this->profitLoss($q))['TOT_4000']['amount'];

        $this->assertSame('2000000.0000', $revenue(['from' => '2026-01-15', 'to' => '2026-01-15']));
        $this->assertSame('0.0000', $revenue(['from' => '2026-01-16', 'to' => '2026-02-19']), 'nothing between the sales');
        $this->assertSame('800000.0000', $revenue(['from' => '2026-02-20', 'to' => '2026-02-20']));
        $this->assertSame('2000000.0000', $revenue(['from' => '2026-01-01', 'to' => '2026-02-19']));
        $this->assertSame('2800000.0000', $revenue(['from' => '2026-01-15', 'to' => '2026-02-20']));
        $this->assertSame('2000000.0000', $revenue(['period_id' => $this->period($this->tenant, '2026-01')->id]), 'a period is its own first and last day');
        $this->assertSame('300000.0000', $this->accountAmount($this->profitLoss(['period_id' => $this->period($this->tenant, '2026-01')->id]), '6100'), 'the salary of the last day');
        $this->assertSame('800000.0000', $revenue(['period_id' => $this->period($this->tenant, '2026-02')->id]));
    }

    public function test_a_range_across_years_adds_both_years_and_a_fiscal_year_is_year_to_date(): void
    {
        $this->publishedDefinitions($this->tenant);
        $year2026 = $this->fiscalYear($this->tenant, 'FY2026')->id;
        $year2025 = $this->fiscalYear($this->tenant, 'FY2025')->id;

        $across = $this->profitLoss(['from' => '2025-12-01', 'to' => '2026-01-31']);
        $this->assertSame('2000000.0000', $this->accountAmount($across, '4100'), 'December 2025 has no sale; only January 2026');
        $everything = $this->profitLoss(['from' => '2025-01-01', 'to' => '2026-12-31']);
        $this->assertSame('4400000.0000', $this->accountAmount($everything, '4100'));
        $this->assertSame('3300000.0000', $this->linesByCode($everything)['NET_RESULT_CALC']['amount']);

        $fy2025 = $this->profitLoss(['fiscal_year_id' => $year2025]);
        $this->assertSame(['from' => '2025-01-01', 'to' => '2025-12-31'], $fy2025['range']);
        $this->assertSame('800000.0000', $this->linesByCode($fy2025)['NET_RESULT_CALC']['amount']);

        $ytd = $this->profitLoss(['fiscal_year_id' => $year2026, 'to' => '2026-02-28']);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-02-28'], $ytd['range']);
        $this->assertSame('2300000.0000', $this->linesByCode($ytd)['NET_RESULT_CALC']['amount'], '2.8M revenue - 0.3M salary - 0.2M rent; the cost of sales is booked in March');
    }

    public function test_the_closing_journals_of_a_year_never_erase_its_profit_and_loss(): void
    {
        $this->publishedDefinitions($this->tenant);
        $before = $this->profitLoss(['from' => '2025-01-01', 'to' => '2025-12-31']);

        $this->closingJournal($this->tenant, '2025-12-31', ['4100' => '1000000', '6200' => '-200000']);
        $after = $this->profitLoss(['from' => '2025-01-01', 'to' => '2025-12-31']);

        $this->assertSame('800000.0000', $this->linesByCode($before)['NET_RESULT_CALC']['amount']);
        $this->assertSame($before['lines'], $after['lines']);
        $this->assertSame($before['integrity'], $after['integrity']);
        $this->assertSame('1000000.0000', $this->accountAmount($after, '4100'));
        $this->assertSame('1900000.0000', $this->linesByCode($this->profitLoss(['from' => '2026-01-01', 'to' => '2026-03-31']))['NET_RESULT_CALC']['amount']);
    }

    public function test_the_opening_balance_journal_is_not_activity(): void
    {
        $this->publishedDefinitions($this->tenant);

        $pl = $this->profitLoss(['from' => '2025-01-01', 'to' => '2025-01-31']);

        $this->assertSame(['0.0000'], array_values(array_unique(array_column($pl['lines'], 'amount'))), 'January 2025 has only the opening balances');
        $this->assertSame('0.0000', $pl['integrity']['ledger_result']);
    }

    public function test_without_dates_the_profit_and_loss_covers_the_fiscal_year_to_the_business_date_and_says_so(): void
    {
        $this->publishedDefinitions($this->tenant);

        $pl = $this->profitLoss();

        $this->assertSame('2026-01-01', $pl['range']['from']);
        $this->assertSame(Tenant::query()->findOrFail($this->tenant->id)->businessDate(), $pl['range']['to']);
    }

    public function test_dates_that_contradict_each_other_are_refused(): void
    {
        $this->publishedDefinitions($this->tenant);
        $period = $this->period($this->tenant, '2026-01')->id;
        $year = $this->fiscalYear($this->tenant, 'FY2026')->id;

        $this->getJson(self::RP."/reports/profit-loss?period_id={$period}&from=2026-01-01")->assertUnprocessable()->assertJsonPath('code', 'DATE_FILTER_CONFLICT');
        $this->getJson(self::RP."/reports/profit-loss?period_id={$period}&fiscal_year_id={$year}")->assertUnprocessable()->assertJsonPath('code', 'DATE_FILTER_CONFLICT');
        $this->getJson(self::RP.'/reports/profit-loss?from=2026-03-01&to=2026-02-01')->assertUnprocessable()->assertJsonPath('code', 'RANGE_INVALID');
        $this->getJson(self::RP."/reports/profit-loss?fiscal_year_id={$year}&from=2025-12-01")->assertUnprocessable()->assertJsonPath('code', 'RANGE_OUTSIDE_FISCAL_YEAR');
        $this->getJson(self::RP.'/reports/profit-loss?period_id='.fake()->uuid())->assertNotFound()->assertJsonPath('code', 'PERIOD_NOT_FOUND');
        $this->getJson(self::RP.'/reports/profit-loss?from=yesterday')->assertUnprocessable();
    }

    public function test_an_account_the_profit_and_loss_leaves_out_is_listed_and_makes_the_statement_unreliable(): void
    {
        $this->publishWithout('PROFIT_LOSS', '6100');

        $pl = $this->profitLoss(['from' => '2026-01-01', 'to' => '2026-03-31']);

        $this->assertSame('1900000.0000', $pl['integrity']['ledger_result']);
        $this->assertSame('2200000.0000', $pl['integrity']['mapped_result']);
        $this->assertSame('-300000.0000', $pl['integrity']['difference']);
        $this->assertFalse($pl['integrity']['reliable']);
        $this->assertSame(['6100'], array_column($pl['integrity']['unmapped'], 'code'));
        $this->assertSame('300000.0000', $pl['integrity']['unmapped'][0]['amount']);
        $this->assertSame(['UNMAPPED_ACCOUNT_WITH_BALANCE', 'STATEMENT_DIFFERENCE'], array_column($pl['warnings'], 'code'));
        $this->assertSame('2200000.0000', $this->linesByCode($pl)['NET_RESULT_CALC']['amount'], 'the statement shows what it covers');

        $quiet = $this->profitLoss(['from' => '2025-01-01', 'to' => '2025-12-31']);
        $this->assertTrue($quiet['integrity']['reliable'], 'an account with no activity in the range is not a problem');
    }

    public function test_the_profit_and_loss_follows_the_dimension_filters_and_marks_itself_partial(): void
    {
        [$north, $south] = $this->twoBranches($this->tenant);
        $this->book($this->tenant, '2026-05-10', '1110', '4100', '500000', ['branch_id' => $north]);
        $this->book($this->tenant, '2026-05-11', '1110', '4100', '300000', ['branch_id' => $south]);
        $this->publishedDefinitions($this->tenant);

        $pl = $this->profitLoss(['from' => '2026-05-01', 'to' => '2026-05-31', 'branch_id' => $north]);

        $this->assertFalse($pl['complete']);
        $this->assertSame('500000.0000', $this->accountAmount($pl, '4100'));
        $this->assertSame('PARTIAL_LEDGER', $pl['warnings'][0]['code']);
        $this->assertSame('800000.0000', $this->accountAmount($this->profitLoss(['from' => '2026-05-01', 'to' => '2026-05-31']), '4100'));
    }

    public function test_returns_and_other_contra_accounts_reduce_the_section_they_belong_to(): void
    {
        $this->book($this->tenant, '2026-05-10', '4150', '1110', '100000'); // sales return: debit the contra revenue account
        $this->publishedDefinitions($this->tenant);

        $pl = $this->profitLoss(['from' => '2026-05-01', 'to' => '2026-05-31']);

        $this->assertSame('-100000.0000', $this->accountAmount($pl, '4150'));
        $this->assertSame('-100000.0000', $this->linesByCode($pl)['TOT_4000']['amount']);
        $this->assertSame('-100000.0000', $pl['integrity']['ledger_result']);
        $this->assertTrue($pl['integrity']['reliable']);
    }
}
