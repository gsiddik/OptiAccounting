<?php

namespace Tests\Feature\Reporting;

use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/** OA5 batch D: the indirect cash flow statement and its proof against the cash and bank accounts of the general ledger. */
class CashFlowStatementTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReportingFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reportingTenant();
        $this->standardLedger($this->tenant);
    }

    private function cashFlow(array $query = []): array
    {
        return $this->statement('cash-flow', $query);
    }

    /**
     * Cash flow defaults published after a change to their lines. `$change` receives the flat line list (API shape, keyed by code) and returns
     * the list to publish.
     */
    private function publishCashFlow(callable $change): string
    {
        $draft = $this->postJson(self::RP.'/report-definitions/defaults', ['statement_types' => ['CASH_FLOW']])->assertCreated()->json('created.0.id');
        $keep = array_flip(['code', 'label', 'line_type', 'parent_code', 'display_order', 'balance_side', 'cash_flow_category', 'system_key', 'operands', 'show_zero', 'emphasis', 'notes']);
        $lines = collect($this->getJson(self::RP."/report-definitions/{$draft}")->json('lines'))->map(fn ($l) => array_intersect_key($l, $keep))->keyBy('code')->all();
        $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => array_values($change($lines))])->assertOk();
        $this->postJson(self::RP."/report-definitions/{$draft}/publish")->assertOk();

        return $draft;
    }

    public function test_the_indirect_cash_flow_reconciles_to_the_cash_and_bank_accounts_of_the_ledger(): void
    {
        $this->publishedDefinitions($this->tenant);

        $cf = $this->cashFlow(['from' => '2026-01-01', 'to' => '2026-03-31']);

        $this->assertSame('CASH_FLOW', $cf['statement']);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-03-31'], $cf['range']);
        $this->assertTrue($cf['complete']);
        $lines = $this->linesByCode($cf);
        $this->assertSame('1900000.0000', $lines['NET_RESULT']['amount']);
        $this->assertSame('2300000.0000', $lines['CF_OPERATING']['amount'], 'net result plus the payable that grew');
        $this->assertSame('400000.0000', $this->accountAmount($cf, '2140'));
        $this->assertSame('-500000.0000', $this->accountAmount($cf, '1230'), 'equipment bought is cash spent');
        $this->assertSame('-500000.0000', $lines['CF_INVESTING']['amount']);
        $this->assertSame('1800000.0000', $lines['CF_NET_CHANGE']['amount']);
        $this->assertSame('9800000.0000', $lines['CASH_OPENING']['amount']);
        $this->assertSame('11600000.0000', $lines['CF_CLOSING_CALC']['amount']);
        $this->assertSame('11600000.0000', $lines['CASH_CLOSING']['amount']);

        $integrity = $cf['integrity'];
        $this->assertSame('1900000.0000', $integrity['net_result']);
        $this->assertSame('9800000.0000', $integrity['cash_opening']);
        $this->assertSame('1800000.0000', $integrity['net_change']);
        $this->assertSame('11600000.0000', $integrity['cash_closing_calculated']);
        $this->assertSame('11600000.0000', $integrity['cash_closing_ledger']);
        $this->assertSame('0.0000', $integrity['difference']);
        $this->assertTrue($integrity['reconciled']);
        $this->assertTrue($integrity['reliable']);
        $this->assertSame([], $integrity['unclassified']);
        $this->assertSame(['OPERATING' => '400000.0000', 'INVESTING' => '-500000.0000', 'FINANCING' => '0.0000', 'NON_CASH' => '0.0000', 'UNCLASSIFIED' => '0.0000'], $integrity['categories']);
        $this->assertSame([['1110', '6000000.0000', '1200000.0000', '7200000.0000'], ['1120', '3800000.0000', '600000.0000', '4400000.0000']],
            array_map(fn ($a) => [$a['code'], $a['opening'], $a['movement'], $a['closing']], $integrity['cash_accounts']));
        $this->assertSame([], $cf['warnings']);

        // The closing cash of the statement is the cash and bank balance of the balance sheet at the end of the range.
        $bs = $this->balanceSheet(['as_of' => '2026-03-31']);
        $this->assertSame($integrity['cash_closing_ledger'], Money::str(BigDecimal::of($this->accountAmount($bs, '1110'))->plus($this->accountAmount($bs, '1120'))));
    }

    private function balanceSheet(array $query): array
    {
        return $this->statement('balance-sheet', $query);
    }

    public function test_the_opening_balances_are_the_opening_cash_not_an_inflow_of_the_first_year(): void
    {
        $this->publishedDefinitions($this->tenant);

        $cf = $this->cashFlow(['fiscal_year_id' => $this->fiscalYear($this->tenant, 'FY2025')->id]);

        $this->assertSame(['from' => '2025-01-01', 'to' => '2025-12-31'], $cf['range']);
        $integrity = $cf['integrity'];
        $this->assertSame('8000000.0000', $integrity['cash_opening'], 'the opening-balance journal is the starting cash');
        $this->assertSame('800000.0000', $integrity['net_result']);
        $this->assertSame('1000000.0000', $integrity['categories']['FINANCING'], 'the bank loan');
        $this->assertSame('0.0000', $integrity['categories']['INVESTING'], 'the opening equipment is not a purchase of the year');
        $this->assertSame('1800000.0000', $integrity['net_change']);
        $this->assertSame('9800000.0000', $integrity['cash_closing_ledger']);
        $this->assertTrue($integrity['reconciled']);
    }

    public function test_year_end_closing_journals_do_not_distort_the_statement_on_either_side(): void
    {
        $this->publishedDefinitions($this->tenant);
        $before = $this->cashFlow(['from' => '2025-01-01', 'to' => '2026-03-31']);

        $this->closingJournal($this->tenant, '2025-12-31', ['4100' => '1000000', '6200' => '-200000']);
        $after = $this->cashFlow(['from' => '2025-01-01', 'to' => '2026-03-31']);

        $this->assertSame($before['lines'], $after['lines']);
        $this->assertSame($before['integrity'], $after['integrity']);
        $this->assertSame('2700000.0000', $after['integrity']['net_result'], 'both years, the closing journal is not a negative result');
        $this->assertSame('1000000.0000', $after['integrity']['categories']['FINANCING'], 'moving the result to retained earnings is not a financing flow');
        $this->assertTrue($after['integrity']['reconciled']);
    }

    public function test_an_account_the_definition_does_not_classify_is_listed_and_a_missing_line_shows_as_a_difference(): void
    {
        $this->publishCashFlow(function (array $lines) {
            unset($lines['ACC_2140']);

            return $lines;
        });

        $cf = $this->cashFlow(['from' => '2026-01-01', 'to' => '2026-03-31']);

        $integrity = $cf['integrity'];
        $this->assertSame([['code' => '2140', 'amount' => '400000.0000', 'reason' => 'NOT_MAPPED']], array_map(fn ($a) => array_intersect_key($a, array_flip(['code', 'amount', 'reason'])), $integrity['unclassified']));
        $this->assertSame('400000.0000', $this->linesByCode($cf)['UNCLASSIFIED_CASH_FLOW']['amount'], 'the unclassified movement stays on the statement');
        $this->assertSame('0.0000', $integrity['difference']);
        $this->assertTrue($integrity['reconciled']);
        $this->assertFalse($integrity['reliable'], 'cash is explained but not classified');
        $this->assertContains('UNMAPPED_ACCOUNT_WITH_BALANCE', array_column($cf['warnings'], 'code'));
    }

    public function test_a_definition_without_the_unclassified_line_makes_the_gap_a_visible_difference(): void
    {
        $this->publishCashFlow(function (array $lines) {
            unset($lines['ACC_2140'], $lines['UNCLASSIFIED_CASH_FLOW'], $lines['CF_UNCLASSIFIED']);
            $lines['CF_NET_CHANGE']['operands'] = array_values(array_filter($lines['CF_NET_CHANGE']['operands'], fn ($o) => $o['line'] !== 'CF_UNCLASSIFIED'));

            return $lines;
        });

        $cf = $this->cashFlow(['from' => '2026-01-01', 'to' => '2026-03-31']);

        $integrity = $cf['integrity'];
        $this->assertSame('1400000.0000', $integrity['net_change'], '1.9M result - 0.5M equipment; the payable is nowhere');
        $this->assertSame('11200000.0000', $integrity['cash_closing_calculated']);
        $this->assertSame('11600000.0000', $integrity['cash_closing_ledger']);
        $this->assertSame('-400000.0000', $integrity['difference']);
        $this->assertFalse($integrity['reconciled']);
        $this->assertFalse($integrity['reliable']);
        $this->assertSame(['UNMAPPED_ACCOUNT_WITH_BALANCE', 'STATEMENT_DIFFERENCE'], array_column($cf['warnings'], 'code'));
        $this->assertSame('11200000.0000', $this->linesByCode($cf)['CF_CLOSING_CALC']['amount']);
        $this->assertSame('11600000.0000', $this->linesByCode($cf)['CASH_CLOSING']['amount'], 'the ledger figure is shown beside the calculated one');
    }

    public function test_an_account_on_an_unclassified_line_is_listed_with_its_reason(): void
    {
        $this->postJson(self::RP.'/accounts', ['code' => '1900', 'name' => 'Aset lain-lain', 'account_type' => 'ASSET', 'is_postable' => true])->assertCreated();
        $this->book($this->tenant, '2026-03-20', '1900', '1110', '250000');
        $this->publishedDefinitions($this->tenant);

        $cf = $this->cashFlow(['from' => '2026-03-01', 'to' => '2026-03-31']);

        $unclassified = $cf['integrity']['unclassified'];
        $this->assertSame(['1900'], array_column($unclassified, 'code'));
        $this->assertSame('-250000.0000', $unclassified[0]['amount']);
        $this->assertSame('CATEGORY_UNCLASSIFIED', $unclassified[0]['reason']);
        $this->assertSame('-250000.0000', $cf['integrity']['categories']['UNCLASSIFIED']);
        $this->assertFalse($cf['integrity']['reliable']);
        $this->assertTrue($cf['integrity']['reconciled'], 'classification is incomplete, the cash is still explained');
        $this->assertContains('CASH_FLOW_CATEGORY_UNCLASSIFIED', array_column($cf['warnings'], 'code'));
    }

    public function test_the_gl_account_of_every_cash_and_bank_account_is_cash_even_without_a_cash_role(): void
    {
        $this->postJson(self::RP.'/accounts', ['code' => '1170', 'name' => 'Kas kecil cabang', 'account_type' => 'ASSET', 'is_postable' => true])->assertCreated();
        $this->cashAccount($this->tenant, 'KK', 'CASH', '1170');
        $this->book($this->tenant, '2026-03-20', '1170', '4100', '75000');
        $this->publishedDefinitions($this->tenant);

        $cf = $this->cashFlow(['from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertContains('1170', array_column($cf['integrity']['cash_accounts'], 'code'));
        $this->assertNull($this->accountAmount($cf, '1170'), 'a cash account is explained by the statement, never a line of it');
        $this->assertSame('75000.0000', collect($cf['integrity']['cash_accounts'])->firstWhere('code', '1170')['closing']);
        $this->assertTrue($cf['integrity']['reconciled']);
        $this->assertSame('11675000.0000', $cf['integrity']['cash_closing_ledger']);
    }

    public function test_a_narrower_scope_marks_the_cash_flow_partial_and_does_not_claim_it_reconciles(): void
    {
        [$north] = $this->twoBranches($this->tenant);
        $this->book($this->tenant, '2026-05-10', '1110', '4100', '500000', ['branch_id' => $north]);
        $this->publishedDefinitions($this->tenant);

        $cf = $this->cashFlow(['from' => '2026-05-01', 'to' => '2026-05-31', 'branch_id' => $north]);

        $this->assertFalse($cf['complete']);
        $this->assertNull($cf['integrity']['reconciled']);
        $this->assertSame('500000.0000', $cf['integrity']['net_result']);
        $this->assertSame('PARTIAL_LEDGER', $cf['warnings'][0]['code']);
    }

    public function test_the_cash_flow_needs_its_own_definition_and_refuses_contradicting_dates(): void
    {
        $this->getJson(self::RP.'/reports/cash-flow?from=2026-01-01&to=2026-03-31')->assertUnprocessable()->assertJsonPath('code', 'REPORT_DEFINITION_NOT_FOUND');

        $this->publishedDefinitions($this->tenant);
        $period = $this->period($this->tenant, '2026-01')->id;
        $this->getJson(self::RP."/reports/cash-flow?period_id={$period}&to=2026-03-31")->assertUnprocessable()->assertJsonPath('code', 'DATE_FILTER_CONFLICT');
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-01-31'], $this->cashFlow(['period_id' => $period])['range']);

        $bsDefinition = $this->getJson(self::RP.'/report-definitions?statement_type=BALANCE_SHEET')->json('data.0.id');
        $this->getJson(self::RP."/reports/cash-flow?from=2026-01-01&to=2026-03-31&definition_id={$bsDefinition}")->assertNotFound()->assertJsonPath('code', 'REPORT_DEFINITION_NOT_FOUND');
    }
}
