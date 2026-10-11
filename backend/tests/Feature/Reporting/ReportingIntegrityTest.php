<?php

namespace Tests\Feature\Reporting;

use App\Domain\Currency\Services\CurrencyService;
use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\BudgetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/**
 * OA5 batch E: the integrity guarantees of the reports as a whole, over the deterministic two-year ledger (see ReportingFixtures): only POSTED lines
 * count and count once, every report ties back to the ledger, foreign amounts are the posted functional amounts, tenants and data scopes never leak
 * into each other, and nothing depends on how a tenant numbers its accounts.
 */
class ReportingIntegrityTest extends TestCase
{
    use AccountingFixtures, BudgetFixtures, Fixtures, FxFixtures, PayablesFixtures, ReportingFixtures;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reportingTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->standardLedger($this->tenant);
        $this->publishedDefinitions($this->tenant);
        $this->admin = $this->memberToken($this->tenant);
        $this->as($this->admin);
    }

    // ------------------------------------------------------------------------------------------ helpers

    /** The query strings every report of the standard window is asked with. */
    private const Q1 = ['from' => '2026-01-01', 'to' => '2026-03-31'];

    /** @return array<string,mixed> every report for the first quarter of 2026, without the volatile timestamps */
    private function allReports(): array
    {
        $cash = $this->account($this->tenant, '1110')->id;
        $assets = $this->account($this->tenant, '1000')->id;

        return $this->withoutTimestamps([
            'trial-balance' => $this->getJson(self::RP.'/trial-balance?'.http_build_query(self::Q1))->assertOk()->json(),
            'general-ledger' => $this->getJson(self::RP.'/general-ledger?'.http_build_query(self::Q1 + ['account_id' => $cash]))->assertOk()->json(),
            'account-balance' => $this->statement('account-balance', ['account_id' => $assets, 'as_of' => '2026-03-31']),
            'balance-sheet' => $this->statement('balance-sheet', ['as_of' => '2026-03-31']),
            'profit-loss' => $this->statement('profit-loss', self::Q1),
            'cash-flow' => $this->statement('cash-flow', self::Q1),
            'comparative' => $this->statement('comparative', ['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_YEAR'] + self::Q1),
        ]);
    }

    private function withoutTimestamps(array $report): array
    {
        array_walk_recursive($report, function (&$value, $key) {
            if ($key === 'generated_at') {
                $value = null;
            }
        });

        return $report;
    }

    private function advance(string $journalId, string ...$steps): void
    {
        foreach ($steps as $step) {
            $this->postJson(self::RP."/journals/{$journalId}/{$step}", ['reason' => 'uji'])->assertSuccessful();
        }
    }

    private function sum(array $amounts): string
    {
        return (string) array_reduce($amounts, fn (BigDecimal $carry, string $a) => $carry->plus($a), BigDecimal::zero())->toScale(4);
    }

    // ------------------------------------------------------------------------------------------ 1-4: only posted lines count, once

    public function test_a_journal_that_is_not_posted_moves_no_report_at_any_stage_before_posting(): void
    {
        $baseline = $this->allReports();
        $big = ['document_date' => '2026-03-20', 'posting_date' => '2026-03-20', 'lines' => $this->lines($this->tenant, '9000000', '1110', '4100')];

        $draft = $this->draft($this->tenant, $big);
        $this->assertSame('DRAFT', $draft['status']);
        $this->assertSame($baseline, $this->allReports(), 'a draft changes nothing');

        $this->advance($draft['id'], 'submit');
        $this->assertSame('SUBMITTED', $this->getJson(self::RP."/journals/{$draft['id']}")->json('status'));
        $this->assertSame($baseline, $this->allReports(), 'a submitted journal changes nothing');

        $this->advance($draft['id'], 'approve');
        $this->assertSame('APPROVED', $this->getJson(self::RP."/journals/{$draft['id']}")->json('status'));
        $this->assertSame($baseline, $this->allReports(), 'an approved journal that is not posted changes nothing');

        // A journal that was sent back or cancelled never counts either.
        $rejected = $this->draft($this->tenant, $big);
        $this->advance($rejected['id'], 'submit', 'reject');
        $cancelled = $this->draft($this->tenant, $big);
        $this->advance($cancelled['id'], 'cancel');
        $this->assertSame($baseline, $this->allReports(), 'rejected and cancelled journals change nothing');
    }

    public function test_a_posted_journal_moves_every_report_by_exactly_its_amount_once(): void
    {
        $before = $this->allReports();
        $journal = $this->draft($this->tenant, ['document_date' => '2026-03-20', 'posting_date' => '2026-03-20', 'lines' => $this->lines($this->tenant, '9000000', '1110', '4100')]);
        $this->advance($journal['id'], 'submit', 'approve', 'post');

        $after = $this->allReports();

        $cash = fn (array $r) => collect($r['balance-sheet']['lines'])->firstWhere('code', 'ACC_1110')['amount'];
        $this->assertSame(['7200000.0000', '16200000.0000'], [$cash($before), $cash($after)]);
        $sales = fn (array $r) => collect($r['profit-loss']['lines'])->firstWhere('code', 'ACC_4100')['amount'];
        $this->assertSame(['2800000.0000', '11800000.0000'], [$sales($before), $sales($after)]);
        $tb = fn (array $r, string $code, string $field) => collect($r['trial-balance']['data'])->firstWhere('code', $code)[$field];
        $this->assertSame('9000000.0000', (string) BigDecimal::of($tb($after, '1110', 'debit'))->minus($tb($before, '1110', 'debit'))->toScale(4), 'the trial balance moves once, not twice');
        $this->assertSame('9000000.0000', (string) BigDecimal::of($tb($after, '4100', 'credit'))->minus($tb($before, '4100', 'credit'))->toScale(4));
        $this->assertSame(['14100000.0000', '23100000.0000'], [$before['balance-sheet']['integrity']['assets'], $after['balance-sheet']['integrity']['assets']]);
        $this->assertTrue($after['balance-sheet']['integrity']['balanced']);
        $this->assertSame(['1900000.0000', '10900000.0000'], [$before['profit-loss']['integrity']['ledger_result'], $after['profit-loss']['integrity']['ledger_result']]);
        $this->assertTrue($after['cash-flow']['integrity']['reconciled']);

        // The ledger lists the journal's two lines exactly once.
        $lines = $this->getJson(self::RP.'/general-ledger?'.http_build_query(self::Q1))->assertOk()->json('data');
        $this->assertCount(2, array_filter($lines, fn ($l) => $l['journal_id'] === $journal['id']));

        // Posting it again is refused and changes nothing.
        $this->postJson(self::RP."/journals/{$journal['id']}/post")->assertStatus(409);
        $this->assertSame($after, $this->allReports());
    }

    // ------------------------------------------------------------------------------------------ 5: the trial balance and the ledger tie

    public function test_the_trial_balance_ties_to_the_general_ledger_account_by_account_and_in_total(): void
    {
        $tb = $this->getJson(self::RP.'/trial-balance?'.http_build_query(self::Q1))->assertOk()->json();

        $this->assertTrue($tb['complete']);
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit'], 'movement: debit = credit');
        $this->assertSame($tb['totals']['opening_debit'], $tb['totals']['opening_credit'], 'opening: debit = credit');
        $this->assertSame($tb['totals']['ending_debit'], $tb['totals']['ending_credit'], 'ending: debit = credit');
        $this->assertGreaterThan(5, count($tb['data']));

        foreach ($tb['data'] as $row) {
            $summary = $this->getJson(self::RP.'/general-ledger?'.http_build_query(self::Q1 + ['account_id' => $row['account_id']]))->assertOk()->json('summary');
            $net = fn (string $debit, string $credit) => (string) BigDecimal::of($debit)->minus($credit)->toScale(4);
            $signed = fn (string $net) => $row['normal_balance'] === 'CREDIT' ? (string) BigDecimal::of($net)->negated()->toScale(4) : $net;
            $this->assertSame([$signed($net($row['opening_debit'], $row['opening_credit'])), $row['debit'], $row['credit'], $signed($net($row['ending_debit'], $row['ending_credit']))],
                [$summary['opening'], $summary['debit'], $summary['credit'], $summary['closing']], "{$row['code']} {$row['name']}");
        }
        // All lines of the range: the ledger's grand totals are the trial balance's movement.
        $all = $this->getJson(self::RP.'/general-ledger?'.http_build_query(self::Q1))->assertOk()->json('summary');
        $this->assertSame([$tb['totals']['debit'], $tb['totals']['credit']], [$all['debit'], $all['credit']]);
    }

    // ------------------------------------------------------------------------------------------ 6-7: the balance sheet is the ledger

    public function test_the_balance_sheet_balances_and_equals_the_account_balances_on_every_month_end(): void
    {
        $assets = $this->account($this->tenant, '1000')->id;
        $liabilities = $this->account($this->tenant, '2000')->id;
        $equity = $this->account($this->tenant, '3000')->id;

        foreach (['2025-01-31', '2025-06-30', '2025-12-31', '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'] as $date) {
            $bs = $this->statement('balance-sheet', ['as_of' => $date]);
            $this->assertTrue($bs['complete'] && $bs['integrity']['balanced'] && $bs['integrity']['reliable'], $date);
            $this->assertSame($bs['integrity']['assets'], $bs['integrity']['liabilities_and_equity'], $date);

            // A second path to the same numbers: the account balance report, which is the trial balance, not the statement engine.
            $balance = fn (string $id) => $this->statement('account-balance', ['account_id' => $id, 'as_of' => $date])['totals']['balance'];
            $this->assertSame($balance($assets), $bs['integrity']['assets'], "assets {$date}");
            $this->assertSame($this->sum([$balance($liabilities), $balance($equity), $bs['integrity']['current_result']]), $bs['integrity']['liabilities_and_equity'], "funding {$date}");
        }
    }

    // ------------------------------------------------------------------------------------------ 13-14: foreign currency

    public function test_a_foreign_currency_journal_is_reported_at_its_posted_functional_amount_and_a_later_rate_changes_nothing(): void
    {
        $this->postedJournal($this->tenant, ['document_date' => '2026-02-10', 'posting_date' => '2026-02-10', 'description' => 'Penjualan USD', 'source_type' => 'TEST_SALE', 'source_id' => 'fx-1', 'lines' => [
            ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '1500000', 'transaction' => ['currency' => 'USD', 'amount' => '100', 'rate' => '15000']],
            ['account_id' => $this->account($this->tenant, '4100')->id, 'credit' => '1500000', 'transaction' => ['currency' => 'USD', 'amount' => '100', 'rate' => '15000']],
        ]]);

        $asOf = ['as_of' => '2026-02-28'];
        $feb = ['period_id' => $this->period($this->tenant, '2026-02')->id];
        $snapshot = fn () => $this->withoutTimestamps([
            'bs' => $this->statement('balance-sheet', $asOf), 'bs-later' => $this->statement('balance-sheet', ['as_of' => '2026-03-31']),
            'pl' => $this->statement('profit-loss', $feb), 'cf' => $this->statement('cash-flow', $feb),
            'tb' => $this->getJson(self::RP.'/trial-balance?'.http_build_query(['from' => '2026-02-01', 'to' => '2026-02-28']))->json(),
            'gl' => $this->getJson(self::RP.'/general-ledger?'.http_build_query(['from' => '2026-02-01', 'to' => '2026-02-28', 'currency' => 'USD']))->json(),
        ]);

        $before = $snapshot();

        // The foreign amount is 100 USD, the report shows what was posted: 1,500,000 on top of the ledger.
        $this->assertSame('9200000.0000', $this->accountAmount($before['bs'], '1110'), '7.7M + the 1.5M sale');
        $this->assertSame('2300000.0000', $this->accountAmount($before['pl'], '4100'), '0.8M + the 1.5M sale');
        $this->assertTrue($before['bs']['integrity']['balanced']);
        $this->assertTrue($before['cf']['integrity']['reconciled']);
        $this->assertSame('15000.0000000000', $before['gl']['data'][0]['exchange_rate']);

        // Rates move afterwards; nothing already posted is translated again.
        $this->inTenant($this->tenant, fn () => app(CurrencyService::class)->create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2], $this->adminOf($this->tenant)));
        $this->as($this->admin);
        $this->rate('USD', '16000', '2026-03-01');
        $this->rate('USD', '17250', '2026-03-31');

        $this->assertSame($before, $snapshot(), 'a later exchange rate leaves every historical report as it was');
    }

    // ------------------------------------------------------------------------------------------ 15: tenants

    public function test_another_tenants_statements_definitions_and_ledger_never_cross(): void
    {
        $bravo = $this->reportingTenant('bravo', ['sod_creator_not_approver' => false]);
        $this->signedIn($bravo);
        $this->book($bravo, '2026-02-10', '1110', '4100', '123000');
        $bravoDefinitions = $this->publishedDefinitions($bravo);
        $bravoToken = $this->memberToken($bravo);
        $this->as($this->admin);
        $alphaProfitLoss = $this->statement('profit-loss', ['from' => '2026-01-01', 'to' => '2026-03-31']);

        // Bravo's reports are bravo's alone.
        $this->as($bravoToken);
        $profit = $this->statement('profit-loss', ['from' => '2026-01-01', 'to' => '2026-03-31']);
        $this->assertSame('123000.0000', $profit['integrity']['ledger_result']);
        $this->assertSame('123000.0000', $this->accountAmount($profit, '4100'));
        $bs = $this->statement('balance-sheet', ['as_of' => '2026-03-31']);
        $this->assertSame(['123000.0000', true], [$bs['integrity']['assets'], $bs['integrity']['balanced']], 'no opening balance, no standard ledger of the other tenant');
        $this->assertNotSame($alphaProfitLoss['definition']['id'], $profit['definition']['id']);

        // Alpha's definition, line, account, budget and journal ids are unknown to bravo on every reporting route.
        $alphaDefinition = $this->tenantDefinition('PROFIT_LOSS');
        $alphaAccount = $this->account($this->tenant, '4100')->id;
        $range = ['from' => '2026-01-01', 'to' => '2026-03-31'];
        foreach (['profit-loss', 'profit-loss/export'] as $path) {
            $this->getJson(self::RP."/reports/{$path}?".http_build_query($range + ['definition_id' => $alphaDefinition]))->assertNotFound();
        }
        $this->getJson(self::RP.'/reports/comparative?'.http_build_query(['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_YEAR', 'definition_id' => $alphaDefinition] + $range))->assertNotFound();
        $this->getJson(self::RP.'/reports/drilldown?'.http_build_query(['statement' => 'PROFIT_LOSS', 'line' => 'ACC_4100', 'definition_id' => $alphaDefinition] + $range))->assertNotFound();
        $this->getJson(self::RP.'/reports/account-balance?'.http_build_query(['account_id' => $alphaAccount, 'as_of' => '2026-03-31']))->assertNotFound();
        $this->getJson(self::RP.'/general-ledger?'.http_build_query($range + ['account_id' => $alphaAccount]))->assertNotFound();
        $this->getJson(self::RP."/report-definitions/{$alphaDefinition}")->assertNotFound();
        $this->putJson(self::RP."/report-definitions/{$alphaDefinition}/mappings", ['mappings' => []])->assertNotFound();
        $this->postJson(self::RP."/report-definitions/{$alphaDefinition}/new-version")->assertNotFound();
        $this->postJson(self::RP."/report-definitions/{$alphaDefinition}/archive")->assertNotFound();

        // Mapping an account of alpha into a definition of bravo is refused: the account does not exist for bravo (404; a line of another tenant is the same).
        $draft = $this->postJson(self::RP."/report-definitions/{$bravoDefinitions['PROFIT_LOSS']['id']}/new-version")->assertCreated()->json();
        $this->putJson(self::RP."/report-definitions/{$draft['id']}/mappings", ['mappings' => [['line_code' => 'ACC_4100', 'account_id' => $alphaAccount, 'include_descendants' => false]]])
            ->assertNotFound();

        // And bravo's own work left alpha's figures where they were.
        $this->as($this->admin);
        $this->assertSame($alphaProfitLoss, $this->withoutTimestampsOf($this->statement('profit-loss', ['from' => '2026-01-01', 'to' => '2026-03-31']), $alphaProfitLoss));
    }

    /** The statement without its volatile timestamp, so two runs of the same report compare equal. */
    private function withoutTimestampsOf(array $report, array $like): array
    {
        $report['generated_at'] = $like['generated_at'];

        return $report;
    }

    private function tenantDefinition(string $type): string
    {
        return (string) DB::table('report_definitions')->where('tenant_id', $this->tenant->id)->where('statement_type', $type)->where('status', 'ACTIVE')->value('id');
    }

    // ------------------------------------------------------------------------------------------ 16-17: data scope on every report

    /** Branch-tagged activity in February 2026 on top of the standard ledger; returns [north, south]. @return array{0:string,1:string} */
    private function branchActivity(): array
    {
        [$north, $south] = $this->twoBranches($this->tenant);
        $this->book($this->tenant, '2026-02-10', '1110', '4100', '700000', ['branch_id' => $north]);
        $this->book($this->tenant, '2026-02-12', '6100', '1110', '100000', ['branch_id' => $north]);
        $this->book($this->tenant, '2026-02-15', '1110', '4100', '300000', ['branch_id' => $south]);
        $this->book($this->tenant, '2026-02-16', '6200', '1110', '50000', ['branch_id' => $south]);
        // One journal whose asset side is the north's and whose liability side belongs to no branch: the north alone sees an unbalanced half.
        $this->postedJournal($this->tenant, ['document_date' => '2026-02-18', 'posting_date' => '2026-02-18', 'lines' => [
            ['account_id' => $this->account($this->tenant, '1230')->id, 'debit' => '400000', 'branch_id' => $north],
            ['account_id' => $this->account($this->tenant, '2210')->id, 'credit' => '400000'],
        ]]);

        return [$north, $south];
    }

    private function scopedPermissions(): array
    {
        return [
            'accounting.reporting.balance_sheet.view', 'accounting.reporting.pnl.view', 'accounting.reporting.cash_flow.view', 'accounting.gl.view',
            'accounting.trial_balance.view', 'accounting.report.export', 'accounting.budget.view',
        ];
    }

    public function test_a_branch_scoped_user_sees_only_their_branch_in_every_report_and_none_claims_to_be_complete(): void
    {
        [$north] = $this->branchActivity();
        $feb = ['period_id' => $this->period($this->tenant, '2026-02')->id];
        $budget = $this->activeBudget($this->tenant, [$this->bline($this->tenant, '4100', '2026-02', '2000000')])['budget']['id'];
        $everyone = $this->statement('profit-loss', $feb);
        $this->assertSame('1800000.0000', $this->accountAmount($everyone, '4100'), 'tenant-wide: 0.8M + north 0.7M + south 0.3M');

        $this->as($this->branchToken($this->tenant, $this->scopedPermissions(), 'BRANCH', $north));

        // Trial balance and ledger: the north's lines only, flagged partial.
        $tb = $this->getJson(self::RP.'/trial-balance?'.http_build_query(['from' => '2026-02-01', 'to' => '2026-02-28']))->assertOk()->json();
        $this->assertFalse($tb['complete']);
        $this->assertSame(['1200000.0000', '800000.0000'], [$tb['totals']['debit'], $tb['totals']['credit']], 'the north sees 700k+100k+400k debits and 700k+100k credits: not balanced, and not claimed to be');

        // Account balance.
        $balance = $this->statement('account-balance', ['account_id' => $this->account($this->tenant, '4100')->id] + $feb);
        $this->assertFalse($balance['complete']);
        $this->assertSame('700000.0000', $balance['totals']['credit']);

        // Balance sheet: the north's half of a journal shows as a difference that is not called an error.
        $bs = $this->statement('balance-sheet', ['as_of' => '2026-03-31']);
        $this->assertFalse($bs['complete']);
        $this->assertSame(['600000.0000', '400000.0000'], [$this->accountAmount($bs, '1110'), $this->accountAmount($bs, '1230')]);
        $this->assertSame(['1000000.0000', '600000.0000', '400000.0000'], [$bs['integrity']['assets'], $bs['integrity']['liabilities_and_equity'], $bs['integrity']['difference']]);
        $this->assertNull($bs['integrity']['balanced']);
        $this->assertContains('PARTIAL_LEDGER', array_column($bs['warnings'], 'code'));

        // Profit and loss.
        $pl = $this->statement('profit-loss', $feb);
        $this->assertFalse($pl['complete']);
        $this->assertSame(['700000.0000', '100000.0000', '600000.0000'], [$this->accountAmount($pl, '4100'), $this->accountAmount($pl, '6100'), $pl['integrity']['ledger_result']]);
        $this->assertNull($this->accountAmount($pl, '6200'), 'the south\'s rent is not visible');

        // Cash flow: cash and its explanation are both the north's, the mismatch is shown, `reconciled` is not claimed.
        $cf = $this->statement('cash-flow', $feb);
        $this->assertFalse($cf['complete']);
        $this->assertNull($cf['integrity']['reconciled']);
        $this->assertSame(['0.0000', '600000.0000'], [$cf['integrity']['cash_opening'], $cf['integrity']['cash_closing_ledger']]);
        $this->assertSame('-400000.0000', $cf['integrity']['difference']);

        // Comparatives: all three modes, both sides scoped.
        $prior = $this->statement('comparative', ['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_PERIOD'] + $feb);
        $result = collect($prior['lines'])->firstWhere('code', 'NET_RESULT_CALC');
        $this->assertFalse($prior['complete']);
        $this->assertSame(['600000.0000', '0.0000'], [$result['current'], $result['comparison']], 'January belongs to no branch');
        $year = $this->statement('comparative', ['statement' => 'BALANCE_SHEET', 'mode' => 'PRIOR_YEAR', 'as_of' => '2026-03-31']);
        $this->assertFalse($year['complete']);
        $this->assertNull($year['integrity']['current']['balanced']);
        $this->assertNull($year['integrity']['comparison']['balanced']);
        $vsBudget = $this->statement('comparative', ['statement' => 'PROFIT_LOSS', 'mode' => 'BUDGET', 'budget_id' => $budget] + $feb);
        $this->assertFalse($vsBudget['complete']);
        $this->assertSame('700000.0000', collect($vsBudget['lines'])->firstWhere('code', 'ACC_4100')['current']);

        // Drill-down: the line, its accounts and the ledger query carry the same scope; no tenant-wide figure appears.
        $drill = $this->statement('drilldown', ['statement' => 'PROFIT_LOSS', 'line' => 'TOT_4000'] + $feb);
        $this->assertFalse($drill['complete']);
        $this->assertSame(['700000.0000', '700000.0000'], [$drill['line']['amount'], $drill['accounts_total']]);
        $this->assertSame('700000.0000', $this->getJson(self::RP.'/general-ledger?'.http_build_query($drill['accounts'][0]['gl_query']))->assertOk()->json('summary.credit'));
        foreach ([$bs, $pl, $cf, $prior, $vsBudget, $drill] as $report) {
            $this->assertStringNotContainsString('1800000.0000', json_encode($report), 'a tenant-wide figure leaked into a scoped report');
        }

        // Exports equal the screen of the same user, and say that they are partial.
        foreach ([
            ['balance-sheet', ['as_of' => '2026-03-31'], $bs], ['profit-loss', $feb, $pl], ['cash-flow', $feb, $cf],
        ] as [$path, $query, $screen]) {
            $rows = $this->csvRows($this->get(self::RP."/reports/{$path}/export?".http_build_query($query))->assertOk()->streamedContent());
            $header = array_shift($rows);
            $lines = [];
            foreach ($rows as $row) {
                $row = array_combine($header, array_pad($row, count($header), ''));
                if ($row['Jenis'] === 'Baris') {
                    $lines[$row['Kode baris']] = $row['Jumlah baris'];
                }
                if ($row['Jenis'] === 'Kontrol' && $row['Kode baris'] === 'complete') {
                    $this->assertSame('Tidak', $row['Jumlah baris'], "{$path} export says it is partial");
                }
            }
            $this->assertSame(array_column($screen['lines'], 'amount', 'code'), $lines, "{$path} export equals the scoped screen");
        }
        $comparative = $this->csvRows($this->get(self::RP.'/reports/comparative/export?'.http_build_query(['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_PERIOD'] + $feb))->assertOk()->streamedContent());
        $netRow = collect($comparative)->first(fn ($r) => ($r[1] ?? null) === 'NET_RESULT_CALC');
        $this->assertSame(['600000.0000', '0.0000'], [$netRow[7], $netRow[8]]);
    }

    public function test_the_other_branch_and_a_scope_that_owns_nothing_see_their_own_numbers_only(): void
    {
        [$north, $south] = $this->branchActivity();
        $feb = ['period_id' => $this->period($this->tenant, '2026-02')->id];

        $this->as($this->branchToken($this->tenant, $this->scopedPermissions(), 'BRANCH', $south));
        $pl = $this->statement('profit-loss', $feb);
        $this->assertSame(['300000.0000', '50000.0000', '250000.0000'], [$this->accountAmount($pl, '4100'), $this->accountAmount($pl, '6200'), $pl['integrity']['ledger_result']]);
        $this->assertNull($this->accountAmount($pl, '6100'));
        $bs = $this->statement('balance-sheet', ['as_of' => '2026-03-31']);
        $this->assertSame('250000.0000', $this->accountAmount($bs, '1110'));
        $this->assertNull($bs['integrity']['balanced']);

        // Someone who may only see their own journals, and posted none, sees empty statements that still say they are partial.
        $this->as($this->branchToken($this->tenant, $this->scopedPermissions(), 'OWN'));
        $bs = $this->statement('balance-sheet', ['as_of' => '2026-03-31']);
        $pl = $this->statement('profit-loss', self::Q1);
        $cf = $this->statement('cash-flow', self::Q1);
        $this->assertSame(['0.0000', false, null], [$bs['integrity']['assets'], $bs['complete'], $bs['integrity']['balanced']]);
        $this->assertSame(['0.0000', false], [$pl['integrity']['ledger_result'], $pl['complete']]);
        $this->assertSame([null, '0.0000'], [$cf['integrity']['reconciled'], $cf['integrity']['cash_closing_ledger']]);
        $this->assertSame([], $this->statement('drilldown', ['statement' => 'BALANCE_SHEET', 'line' => 'ACC_1110', 'as_of' => '2026-03-31'])['accounts']);
        $this->assertSame('0.0000', collect($this->statement('comparative', ['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_YEAR'] + self::Q1)['lines'])->firstWhere('code', 'NET_RESULT_CALC')['current']);
    }

    // ------------------------------------------------------------------------------------------ no hardcoded account numbering

    public function test_statements_do_not_depend_on_how_a_tenant_numbers_its_accounts(): void
    {
        $standard = [
            'bs' => $this->statement('balance-sheet', ['as_of' => '2026-03-31']),
            'pl' => $this->statement('profit-loss', self::Q1),
            'cf' => $this->statement('cash-flow', self::Q1),
        ];

        // The same chart and the same ledger, but every code is replaced by one that says nothing and sorts the other way round.
        $gamma = $this->reportingTenant('gamma', ['sod_creator_not_approver' => false]);
        $this->standardLedger($gamma);
        $codes = DB::table('accounts')->where('tenant_id', $gamma->id)->orderBy('code')->pluck('id')->values();
        foreach ($codes as $i => $id) {
            DB::table('accounts')->where('id', $id)->update(['code' => 'Z'.sprintf('%03d', 999 - $i).chr(65 + ($i * 11) % 26)]);
        }
        $this->assertSame(0, DB::table('accounts')->where('tenant_id', $gamma->id)->whereRaw("code ~ '^[0-9]'")->count());
        $created = $this->postJson(self::RP.'/report-definitions/defaults')->assertSuccessful()->json('created');
        $this->assertCount(3, $created, 'a default definition for every statement from a chart that follows no numbering convention');
        foreach ($created as $definition) {
            $this->assertSame([], $definition['validation']['errors'] ?? [], $definition['statement_type']);
            $this->postJson(self::RP."/report-definitions/{$definition['id']}/publish")->assertOk();
        }

        $scrambled = [
            'bs' => $this->statement('balance-sheet', ['as_of' => '2026-03-31']),
            'pl' => $this->statement('profit-loss', self::Q1),
            'cf' => $this->statement('cash-flow', self::Q1),
        ];

        $byLabel = fn (array $report) => collect($report['lines'])->mapWithKeys(fn ($l) => [$l['label'] => $l['amount']])->sortKeys()->all(); // line order follows the account codes, the figures do not
        foreach (['bs', 'pl', 'cf'] as $key) {
            $this->assertSame($byLabel($standard[$key]), $byLabel($scrambled[$key]), "{$key}: the same figures under the same names");
            $this->assertSame(array_diff_key($standard[$key]['integrity'], ['unmapped' => 1, 'unclassified' => 1, 'cash_accounts' => 1]), array_diff_key($scrambled[$key]['integrity'], ['unmapped' => 1, 'unclassified' => 1, 'cash_accounts' => 1]), $key);
        }
        $this->assertTrue($scrambled['bs']['integrity']['balanced'] && $scrambled['bs']['integrity']['reliable']);
        $this->assertTrue($scrambled['cf']['integrity']['reconciled'] && $scrambled['cf']['integrity']['reliable']);
        $this->assertSame('14100000.0000', $scrambled['bs']['integrity']['assets']);
        $this->assertSame('11600000.0000', $scrambled['cf']['integrity']['cash_closing_ledger']);
        $this->assertSame('1900000.0000', $scrambled['pl']['integrity']['ledger_result']);
    }
}
