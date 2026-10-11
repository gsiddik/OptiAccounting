<?php

namespace Tests\Feature\Oa5;

use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
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
 * OA5 verification (spec 77-80, 82): one financial cycle on the real engines, no fakes. Posted AP / AR / expense / cash / asset documents, the six
 * reconciliations, readiness, soft and hard close, year end, its reversal and a second run, then the first period of the next year - and after
 * every step the statements, the trial balance and the budget comparison agree with one another and with the ledger.
 */
class FinancialCycleTest extends TestCase
{
    use AccountingFixtures, BudgetFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, Oa5Fixtures, PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, ReportingFixtures, TaxFixtures;

    private const YEAR = ['from' => '2026-01-01', 'to' => '2026-12-31'];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->oa5Tenant();
        $this->memberToken($this->tenant);
    }

    private function fy(string $code = 'FY2026'): string
    {
        return $this->fiscalYear($this->tenant, $code)->id;
    }

    /** The result of the year as the ledger holds it: credit minus debit over every revenue and expense account, the closing journal included. */
    private function ledgerResult(): string
    {
        return Money::str((string) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.tenant_id', $this->tenant->id)->where('e.status', 'POSTED')->whereIn('a.account_type', ['REVENUE', 'EXPENSE'])->selectRaw('coalesce(sum(l.credit - l.debit), 0) as n')->value('n'));
    }

    private function sum(string $a, string $b): string
    {
        return Money::str(BigDecimal::of($a)->plus($b));
    }

    private function retained(array $balanceSheet): string
    {
        return $this->accountAmount($balanceSheet, '3200') ?? '0.0000';
    }

    /** What every report says about the whole year, in a shape that can be compared before and after a closing step. @return array<string,mixed> */
    private function reports(): array
    {
        $pl = $this->statement('profit-loss', self::YEAR);
        $bs = $this->statement('balance-sheet', ['as_of' => '2026-12-31']);
        $tb = $this->getJson(self::RP.'/trial-balance?'.http_build_query(self::YEAR))->assertOk()->json();

        return ['pl' => $pl, 'bs' => $bs, 'tb' => $tb];
    }

    public function test_the_cycle_from_posted_documents_to_a_reversed_and_rerun_year_end_keeps_every_report_consistent(): void
    {
        $this->marchDocuments($this->tenant);
        $this->publishedDefinitions($this->tenant);
        $this->book($this->tenant, '2026-02-10', '1110', '4100', '3000000');
        $this->book($this->tenant, '2026-04-10', '6100', '1110', '400000');
        $this->book($this->tenant, '2026-12-05', '1110', '4100', '200000');
        $fy = $this->fy();

        // 1. Before any closing: the statements agree with the ledger and with each other.
        $before = $this->reports();
        $result = $before['pl']['integrity']['ledger_result'];
        $this->assertTrue(BigDecimal::of($result)->isPositive(), 'the year is a profit');
        $this->assertSame($this->ledgerResult(), $result, 'the profit and loss statement is the ledger result');
        $this->assertSame(['0.0000', true], [$before['pl']['integrity']['difference'], $before['pl']['integrity']['reliable']]);
        $this->assertSame(['0.0000', true], [$before['bs']['integrity']['difference'], $before['bs']['integrity']['balanced']]);
        $this->assertSame($result, $before['bs']['integrity']['current_result'], 'the balance sheet carries the open result as the current-year result');
        $this->assertSame($before['tb']['totals']['debit'], $before['tb']['totals']['credit']);
        $this->assertTrue($before['tb']['reconciliation']['reconciled']);

        // 2. Reconciliations: the subledgers agree with the ledger; nothing mismatches.
        $overview = $this->getJson(self::RC.'/reconciliations?as_of=2026-03-31')->assertOk()->json();
        $status = array_column($overview['data'], 'status', 'type');
        foreach (['AP_GL', 'AR_GL', 'CASH_BANK_GL', 'FIXED_ASSET_GL'] as $type) {
            $this->assertSame('MATCHED', $status[$type], $type);
        }
        $this->assertNotContains('MISMATCH', $status);
        $this->assertNotContains('INCOMPLETE', $status);

        // 3. Readiness, then soft close and hard close of the first three months in order.
        foreach (['2026-01', '2026-02', '2026-03'] as $month) {
            $id = $this->period($this->tenant, $month)->id;
            $ready = $this->getJson(self::CL."/closing/periods/{$id}/readiness")->assertOk()->json();
            $this->assertSame(['READY', 0], [$ready['result'], $ready['blocking_count']], $month);
            $this->postJson(self::CL."/closing/periods/{$id}/soft-close", ['reason' => "Penutupan sementara {$month}"])->assertOk()->assertJsonPath('status', 'SOFT_CLOSED');
            $this->hardClose($this->tenant, $month);
        }
        $this->assertSame(['CLOSED', 'CLOSED', 'CLOSED', 'OPEN'], array_map(fn ($m) => $this->periodStatus($this->tenant, $m), ['2026-01', '2026-02', '2026-03', '2026-04']));
        $this->assertEquals($before['pl']['lines'], $this->statement('profit-loss', self::YEAR)['lines'], 'closing periods changes no figure');
        $this->assertSame($before['pl']['integrity']['ledger_result'], $this->statement('profit-loss', self::YEAR)['integrity']['ledger_result']);

        // 4. Year end: one run, the temporary accounts at zero, the profit in retained earnings.
        $preview = $this->getJson(self::CL."/year-end/{$fy}/preview")->assertOk()->json();
        $this->assertSame([true, $result], [$preview['can_run'], $preview['totals']['net_result']]);
        $run = $this->postJson(self::CL."/year-end/{$fy}/run", ['reason' => 'Penutupan tahun buku 2026'])->assertCreated()->json();
        $this->assertSame([1, 'POSTED', $result], [$run['run_no'], $run['status'], $run['net_result']]);
        $this->assertSame('0.0000', $this->ledgerResult(), 'the temporary accounts are at zero in the ledger');
        $this->postJson(self::CL."/year-end/{$fy}/run")->assertStatus(409)->assertJsonPath('code', 'YEAR_END_ALREADY_POSTED');
        $this->assertSame(1, DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->where('source_type', 'YEAR_END_CLOSING')->count());

        $after = $this->reports();
        $this->assertEquals($before['pl']['lines'], $after['pl']['lines'], 'the profit and loss statement is unchanged by the closing journal');
        $this->assertSame([$result, '0.0000'], [$after['pl']['integrity']['ledger_result'], $after['pl']['integrity']['difference']]);
        $this->assertSame(['0.0000', true, $before['bs']['integrity']['assets'], $before['bs']['integrity']['liabilities_and_equity']],
            [$after['bs']['integrity']['difference'], $after['bs']['integrity']['balanced'], $after['bs']['integrity']['assets'], $after['bs']['integrity']['liabilities_and_equity']], 'the balance sheet still balances with the same totals');
        $this->assertSame('0.0000', $after['bs']['integrity']['current_result'], 'the result left the current-year line');
        $this->assertSame($this->sum($this->retained($before['bs']), $result), $this->retained($after['bs']), 'it moved into retained earnings');
        $this->assertSame($after['tb']['totals']['debit'], $after['tb']['totals']['credit']);
        $this->assertTrue($after['tb']['reconciliation']['reconciled']);

        // 5. Reversal: every figure is back, the closed months stay closed, the history keeps both.
        $this->postJson(self::CL."/year-end/{$fy}/reverse", ['reason' => 'Koreksi klasifikasi pendapatan'])->assertOk()->assertJsonPath('status', 'REVERSED');
        $reversed = $this->reports();
        $this->assertSame($result, $this->ledgerResult());
        $this->assertEquals($before['pl']['lines'], $reversed['pl']['lines']);
        $this->assertSame([$result, $this->retained($before['bs'])], [$reversed['bs']['integrity']['current_result'], $this->retained($reversed['bs'])]);
        $this->assertSame('0.0000', $reversed['bs']['integrity']['difference']);
        $this->assertSame(['CLOSED', 'CLOSED', 'CLOSED'], array_map(fn ($m) => $this->periodStatus($this->tenant, $m), ['2026-01', '2026-02', '2026-03']));

        // 6. A second run after the correction; the first stays in the history.
        $this->book($this->tenant, '2026-12-06', '1110', '4100', '100000');
        $second = $this->postJson(self::CL."/year-end/{$fy}/run")->assertCreated()->json();
        $this->assertSame([2, $this->sum($result, '100000')], [$second['run_no'], $second['net_result']]);
        $this->assertSame([['REVERSED', 1], ['POSTED', 2]], array_map(fn ($r) => [$r['status'], $r['run_no']], $this->getJson(self::CL."/year-end/{$fy}")->assertOk()->json('runs')));
        $final = $this->reports();
        $this->assertSame($this->sum($result, '100000'), $final['pl']['integrity']['ledger_result']);
        $this->assertSame(['0.0000', '0.0000'], [$final['bs']['integrity']['difference'], $final['bs']['integrity']['current_result']]);

        // 7. The next year starts with zero temporary accounts and carries the rest.
        $next = $this->postJson(self::CL.'/fiscal-years', ['code' => 'FY2027', 'name' => 'Tahun 2027', 'start_date' => '2027-01-01'])->assertCreated()->json('id');
        $this->postJson(self::CL."/fiscal-years/{$next}/open")->assertOk();
        $opening = $this->statement('balance-sheet', ['as_of' => '2027-01-01']);
        $this->assertSame([$final['bs']['integrity']['assets'], $final['bs']['integrity']['liabilities_and_equity'], '0.0000', '0.0000'],
            [$opening['integrity']['assets'], $opening['integrity']['liabilities_and_equity'], $opening['integrity']['current_result'], $opening['integrity']['difference']]);
        $this->assertSame($this->retained($final['bs']), $this->retained($opening));
        $this->assertSame('0.0000', $this->statement('profit-loss', ['from' => '2027-01-01', 'to' => '2027-03-31'])['integrity']['ledger_result']);
        $rows = array_column($this->getJson(self::RP.'/trial-balance?from=2027-01-01&to=2027-03-31&include_zero=1')->assertOk()->json('data'), null, 'code');
        $this->assertSame(['0.0000', '0.0000', '0.0000', '0.0000'], [$rows['4100']['opening_debit'], $rows['4100']['opening_credit'], $rows['6100']['opening_debit'], $rows['6100']['opening_credit']]);
    }

    public function test_budget_versus_actual_and_the_budget_comparison_do_not_count_the_year_end_closing_as_activity(): void
    {
        $this->signedIn($this->tenant);
        $this->publishedDefinitions($this->tenant);
        $this->book($this->tenant, '2026-03-10', '1110', '4100', '1000000');
        $this->book($this->tenant, '2026-04-10', '6100', '1110', '400000');
        $this->book($this->tenant, '2026-12-05', '1110', '4100', '200000');
        $budget = $this->activeBudget($this->tenant, [
            $this->bline($this->tenant, '4100', '2026-03', '900000'), $this->bline($this->tenant, '4100', '2026-12', '150000'), $this->bline($this->tenant, '6100', '2026-04', '300000'),
        ]);
        $query = ['budget_id' => $budget['budget']['id'], 'period_from' => $this->period($this->tenant, '2026-01')->id, 'period_to' => $this->period($this->tenant, '2026-12')->id];
        $compare = ['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET', 'budget_id' => $budget['budget']['id']] + self::YEAR;

        $actualBefore = $this->bvaReport($query);
        $byPeriod = $this->bvaReport($query + ['group_by' => 'period']);
        $comparisonBefore = $this->getJson(self::RP.'/reports/comparative?'.http_build_query($compare))->assertOk()->json();
        $accounts = array_column($actualBefore['rows'], null, 'code');
        $this->assertSame(['1050000.0000', '1200000.0000', '300000.0000', '400000.0000'], [$accounts['4100']['budget'], $accounts['4100']['actual'], $accounts['6100']['budget'], $accounts['6100']['actual']]);
        $this->assertSame(['1350000.0000', '1600000.0000', '0.0000'], [$actualBefore['totals']['budget'], $actualBefore['totals']['actual'], $actualBefore['totals']['unbudgeted_actual']]);

        $run = $this->postJson(self::CL.'/year-end/'.$this->fy().'/run', ['reason' => 'Penutupan tahun buku 2026'])->assertCreated()->json();
        $this->assertSame('800000.0000', $run['net_result']);
        $this->assertSame('0.0000', $this->ledgerResult(), 'the closing journal is in the ledger');

        $actualAfter = $this->bvaReport($query);
        $this->assertEquals($actualBefore['rows'], $actualAfter['rows'], 'December still shows the 200000 sale, not the 1200000 debit of the closing journal');
        $this->assertSame($actualBefore['totals'], $actualAfter['totals']);
        $this->assertEquals($byPeriod['rows'], $this->bvaReport($query + ['group_by' => 'period'])['rows']);
        $comparisonAfter = $this->getJson(self::RP.'/reports/comparative?'.http_build_query($compare))->assertOk()->json();
        $this->assertEquals($comparisonBefore['lines'], $comparisonAfter['lines']);
        $this->assertEquals($comparisonBefore['integrity'], $comparisonAfter['integrity']);
    }
}
