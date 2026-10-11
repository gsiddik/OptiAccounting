<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\ReconciliationFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/**
 * OA5 batches F-G: the central engine over AP, AR, cash/bank and fixed assets. Deterministic scenarios prove each subledger equals its
 * ledger, that a stray ledger line becomes MISMATCH (never hidden, never adjusted), that both sides use one cut-off, that the figures equal the
 * legacy routes, and the engine rules of tolerance, data scope and module availability.
 */
class ReconciliationEngineTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reconTenant();
        $this->signedIn($this->tenant);
    }

    /** A vendor invoice of 1.000.000 (10 March) of which 300.000 was paid on 20 March. */
    private function payables(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $vendor = $this->vendor($this->tenant, 'V1');
        $invoice = $this->postedInvoice($vendor);
        $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '300000']);
    }

    // ------------------------------------------------------------------------------------------------ AP and AR

    public function test_ap_subledger_equals_the_ap_control_account_and_a_stray_ledger_line_is_a_mismatch(): void
    {
        $this->payables();

        $result = $this->reconResult('ap-gl', '2026-03-31');
        $this->assertSame(['AP_GL', '2026-03-31', 'MATCHED', '700000.0000', '700000.0000', '0.0000', '0.0000', true], [
            $result['type'], $result['as_of'], $result['status'], $result['source_balance'], $result['gl_balance'], $result['difference'], $result['tolerance'], $result['complete'],
        ]);
        $main = $this->componentOf($result, 'SUBLEDGER_VS_GL');
        $this->assertSame(['700000.0000', '700000.0000', 'MATCHED', false], [$main['source_balance'], $main['gl_balance'], $main['status'], $main['informational']]);
        $this->assertSame('2110', $this->componentOf($result, 'CONTROL_ACCOUNT')['reference']['code']);
        $this->assertSame('INFORMATIONAL', $this->componentOf($result, 'CONTROL_ACCOUNT')['status']);

        // The engine shows the figures of the legacy route; only the sign convention (source - gl) differs.
        $legacy = $this->getJson(self::RC.'/reconciliation/ap?as_of=2026-03-31')->assertOk()->json();
        $this->assertSame([$legacy['subledger_balance'], $legacy['gl_transactional_balance']], [$result['source_balance'], $result['gl_balance']]);
        $this->assertTrue(BigDecimal::of($legacy['difference'])->plus($result['difference'])->isZero(), 'the engine difference is source - gl, the legacy one gl - subledger');

        // A line on the control account that no document explains: MISMATCH with the numbers, nothing posted to hide it.
        $before = $this->glFigures($this->tenant);
        $this->strayLine($this->tenant, '2110', 'CREDIT', '5000', '2026-03-25');
        $off = $this->reconResult('ap-gl', '2026-03-31');
        $this->assertSame(['MISMATCH', '700000.0000', '705000.0000', '-5000.0000'], [$off['status'], $off['source_balance'], $off['gl_balance'], $off['difference']]);
        $this->assertSame('MISMATCH', $this->componentOf($off, 'SUBLEDGER_VS_GL')['status']);
        $legacyOff = $this->getJson(self::RC.'/reconciliation/ap?as_of=2026-03-31')->json();
        $this->assertSame(['MISMATCH', '5000.0000'], [$legacyOff['status'], $legacyOff['difference']]);
        $this->assertSame(2, $this->glFigures($this->tenant)->lines - $before->lines, 'only the stray journal (two lines) reached the ledger: the reconciliation posts nothing');
    }

    public function test_both_sides_use_the_same_cut_off_date(): void
    {
        $this->payables();
        $this->strayLine($this->tenant, '2110', 'CREDIT', '5000', '2026-04-05');

        $this->assertSame('MATCHED', $this->reconResult('ap-gl', '2026-03-31')['status']);          // the stray line is after the cut-off on the ledger side
        $this->assertSame('MATCHED', $this->reconResult('ap-gl', '2026-04-04')['status']);
        $this->assertSame('MISMATCH', $this->reconResult('ap-gl', '2026-04-05')['status']);         // and on the cut-off date itself it counts (end of day)
        $before = $this->reconResult('ap-gl', '2026-03-15');                                         // before the payment: the subledger and the ledger both still hold 1.000.000
        $this->assertSame(['MATCHED', '1000000.0000', '1000000.0000'], [$before['status'], $before['source_balance'], $before['gl_balance']]);
    }

    public function test_an_opening_balance_on_the_control_account_is_shown_beside_the_comparison_not_in_it(): void
    {
        $this->putJson(self::RC.'/opening-balance', ['cutover_date' => '2026-01-01', 'reference' => 'SALDO-AWAL', 'lines' => [
            ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '2500000'],
            ['account_id' => $this->account($this->tenant, '2110')->id, 'credit' => '2000000', 'description' => 'Utang awal'],
            ['account_id' => $this->account($this->tenant, '3100')->id, 'credit' => '500000'],
        ]])->assertOk();
        $this->postJson(self::RC.'/opening-balance/post')->assertOk();
        $this->postedInvoice($this->vendor($this->tenant), ['lines' => [['description' => 'Jasa', 'amount' => '700000']]]);

        $result = $this->reconResult('ap-gl', '2026-04-01');
        $this->assertSame(['MATCHED', '700000.0000', '700000.0000'], [$result['status'], $result['source_balance'], $result['gl_balance']]);
        $opening = $this->componentOf($result, 'OPENING_BALANCE');
        $this->assertSame([null, '2000000.0000', null, 'INFORMATIONAL', true], [$opening['source_balance'], $opening['gl_balance'], $opening['difference'], $opening['status'], $opening['informational']]);
        $this->assertSame('2700000.0000', $this->componentOf($result, 'CONTROL_ACCOUNT')['gl_balance']);
    }

    public function test_ar_subledger_equals_the_ar_control_account_and_a_stray_ledger_line_is_a_mismatch(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $customer = $this->customer($this->tenant, 'C1');
        $invoice = $this->arInvoice($customer, '2000000', ['document_date' => '2026-03-05', 'posting_date' => '2026-03-05']);
        $this->postedReceipt($customer, $bank->id, [$invoice['id'] => '500000']);

        $result = $this->reconResult('ar-gl', '2026-03-31');
        $this->assertSame(['AR_GL', 'MATCHED', '1500000.0000', '1500000.0000', '0.0000'], [$result['type'], $result['status'], $result['source_balance'], $result['gl_balance'], $result['difference']]);
        $legacy = $this->getJson(self::RC.'/reconciliation/ar?as_of=2026-03-31')->json();
        $this->assertSame([$legacy['subledger_balance'], $legacy['gl_transactional_balance']], [$result['source_balance'], $result['gl_balance']]);
        $this->assertSame('1130', $this->componentOf($result, 'CONTROL_ACCOUNT')['reference']['code']);

        $this->strayLine($this->tenant, '1130', 'DEBIT', '7500', '2026-03-28', '4200');
        $off = $this->reconResult('ar-gl', '2026-03-31');
        $this->assertSame(['MISMATCH', '1500000.0000', '1507500.0000', '-7500.0000'], [$off['status'], $off['source_balance'], $off['gl_balance'], $off['difference']]);
        $this->assertSame('MATCHED', $this->reconResult('ar-gl', '2026-03-27')['status']);
    }

    // ------------------------------------------------------------------------------------------------ cash / bank

    /** A bank receipt of 5.000.000 (1 March), a vendor payment of 1.000.000 from the same bank account (10 March). @return array<string,mixed> the bank account */
    private function bankMovements(): object
    {
        $bank = $this->cashAccount($this->tenant, 'BCA', 'BANK', '1120', ['account_number' => '1234567890123']);
        $receipt = $this->postJson(self::RC.'/cash-receipts', ['cash_bank_account_id' => $bank->id, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => '5000000',
            'transaction_date' => '2026-03-01', 'purpose' => 'Setoran modal', 'description' => 'Setoran modal pemilik'])->assertCreated()->json('id');
        $this->postJson(self::RC."/cash-receipts/{$receipt}/post")->assertOk();
        $vendor = $this->vendor($this->tenant, 'V1');
        $invoice = $this->postedInvoice($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000']]]);
        $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '1000000'], ['payment_date' => '2026-03-10', 'posting_date' => '2026-03-10']);

        return $bank;
    }

    public function test_cash_bank_documents_equal_their_ledger_lines_and_the_three_balances_stay_apart(): void
    {
        $bank = $this->bankMovements();
        $this->strayLine($this->tenant, '1120', 'DEBIT', '25000', '2026-03-12', '4200'); // a manual journal on the bank account: other activity, not an error
        $this->postJson(self::RC.'/bank-statements', [
            'cash_bank_account_id' => $bank->id, 'reference' => 'BCA-2026-03', 'statement_date' => '2026-03-31', 'closing_balance' => '3990000',
            'items' => [['item_date' => '2026-03-01', 'description' => 'Setoran', 'amount' => '5000000'], ['item_date' => '2026-03-10', 'description' => 'Transfer', 'amount' => '-1000000']],
        ])->assertCreated();

        $result = $this->reconResult('cash-bank-gl', '2026-03-31');
        $this->assertSame(['CASH_BANK_GL', 'MATCHED', '4000000.0000', '4000000.0000', '0.0000'], [$result['type'], $result['status'], $result['source_balance'], $result['gl_balance'], $result['difference']]);
        $account = $this->componentOf($result, 'CASH_BANK:BCA');
        $this->assertSame(['4000000.0000', '4000000.0000', 'MATCHED', '4025000.0000', '25000.0000'], [$account['source_balance'], $account['gl_balance'], $account['status'], $account['book_balance'], $account['other_activity']]);
        $this->assertSame(['BANK', '1120'], [$account['reference']['kind'], $account['reference']['gl_account']['code']]);

        // The external statement is a third, informational figure: bank closing balance against the book balance; nothing is adjusted.
        $statement = $this->componentOf($result, 'STATEMENT:BCA');
        $this->assertSame(['3990000.0000', '4025000.0000', '-35000.0000', 'INFORMATIONAL', true], [$statement['source_balance'], $statement['gl_balance'], $statement['difference'], $statement['status'], $statement['informational']]);
        $this->assertSame('IN_PROGRESS', $statement['bank_reconciliation']);

        $legacy = $this->getJson(self::RC.'/reconciliation/cash-bank?as_of=2026-03-31')->json();
        $this->assertSame([$legacy['accounts'][0]['documents']['net'], $legacy['accounts'][0]['documents']['ledger_net'], $legacy['accounts'][0]['book_balance']], [$account['source_balance'], $account['gl_balance'], $account['book_balance']]);
    }

    public function test_a_document_that_disagrees_with_its_ledger_lines_is_a_cash_bank_mismatch(): void
    {
        $this->bankMovements();

        // Simulate a corrupted ledger by bypassing the guards: the bank credit of the vendor payment is 100.000 short.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE journal_lines DISABLE TRIGGER USER');
        DB::table('journal_lines')->where('account_id', $this->account($this->tenant, '1120')->id)->where('credit', 1000000)->update(['credit' => 900000, 'transaction_credit' => 900000]);
        DB::statement('ALTER TABLE journal_lines ENABLE TRIGGER USER');

        $result = $this->reconResult('cash-bank-gl', '2026-03-31');
        $this->assertSame(['MISMATCH', '4000000.0000', '4100000.0000', '-100000.0000'], [$result['status'], $result['source_balance'], $result['gl_balance'], $result['difference']]);
        $this->assertSame('MISMATCH', $this->componentOf($result, 'CASH_BANK:BCA')['status']);
        $this->assertSame('MATCHED', $this->reconResult('cash-bank-gl', '2026-03-05')['status']); // before the payment nothing disagrees
    }

    // ------------------------------------------------------------------------------------------------ fixed assets

    public function test_the_asset_register_and_its_accumulated_depreciation_equal_the_ledger_and_a_stray_line_is_a_mismatch(): void
    {
        $this->capitalizedAsset($this->tenant, $this->newCategory());
        $this->postedRun($this->tenant, '2026-05');

        $result = $this->reconResult('fixed-asset-gl', '2026-05-31');
        $this->assertSame(['FIXED_ASSET_GL', 'MATCHED', '9000000.0000', '9000000.0000', '0.0000'], [$result['type'], $result['status'], $result['source_balance'], $result['gl_balance'], $result['difference']]);
        $this->assertSame(['12000000.0000', '12000000.0000', 'MATCHED'], [$this->componentOf($result, 'COST')['source_balance'], $this->componentOf($result, 'COST')['gl_balance'], $this->componentOf($result, 'COST')['status']]);
        $this->assertSame(['3000000.0000', '3000000.0000', 'MATCHED'], [$this->componentOf($result, 'ACCUMULATED_DEPRECIATION')['source_balance'], $this->componentOf($result, 'ACCUMULATED_DEPRECIATION')['gl_balance'], $this->componentOf($result, 'ACCUMULATED_DEPRECIATION')['status']]);
        $this->assertSame('MATCHED', $this->componentOf($result, 'NET_BOOK_VALUE')['status']);
        $this->assertSame('1230', $this->componentOf($result, 'COST')['breakdown'][0]['account']['code']);
        $legacy = $this->getJson(self::RC.'/asset-reconciliation?as_of=2026-05-31')->json();
        $this->assertSame([$legacy['totals']['register_net_book_value'], $legacy['totals']['ledger_net_book_value']], [$result['source_balance'], $result['gl_balance']]);

        // A raw ledger line on the asset account breaks cost and net book value; one on the accumulated account breaks the depreciation.
        $this->strayLine($this->tenant, '1230', 'DEBIT', '5000', '2026-05-20', '4200');
        $cost = $this->reconResult('fixed-asset-gl', '2026-05-31');
        $this->assertSame(['MISMATCH', '9000000.0000', '9005000.0000', '-5000.0000'], [$cost['status'], $cost['source_balance'], $cost['gl_balance'], $cost['difference']]);
        $this->assertSame(['MISMATCH', 'MATCHED', 'MISMATCH'], [$this->componentOf($cost, 'COST')['status'], $this->componentOf($cost, 'ACCUMULATED_DEPRECIATION')['status'], $this->componentOf($cost, 'NET_BOOK_VALUE')['status']]);

        $this->strayLine($this->tenant, '1290', 'CREDIT', '5000', '2026-05-21', '4200');
        $both = $this->reconResult('fixed-asset-gl', '2026-05-31');
        $this->assertSame('MISMATCH', $both['status']);
        $this->assertSame('0.0000', $both['difference']); // the headline nets out ...
        $this->assertSame(['MISMATCH', 'MISMATCH'], [$this->componentOf($both, 'COST')['status'], $this->componentOf($both, 'ACCUMULATED_DEPRECIATION')['status']]); // ... the components do not
        $this->assertStringContainsString('offset each other', implode(' ', $both['notes']));
        $this->assertSame('MATCHED', $this->reconResult('fixed-asset-gl', '2026-05-19')['status']);
    }

    // ------------------------------------------------------------------------------------------------ engine rules

    public function test_the_tolerance_comes_from_the_closing_settings_and_is_reported(): void
    {
        $this->payables();
        $this->strayLine($this->tenant, '2110', 'CREDIT', '5', '2026-03-25', '6900');
        $strict = $this->reconResult('ap-gl', '2026-03-31');
        $this->assertSame(['MISMATCH', '0.0000', '-5.0000'], [$strict['status'], $strict['tolerance'], $strict['difference']]);

        $this->setTolerance($this->tenant, '10');
        $loose = $this->reconResult('ap-gl', '2026-03-31');
        $this->assertSame(['MATCHED', '10.0000', '-5.0000'], [$loose['status'], $loose['tolerance'], $loose['difference']]); // within tolerance: matched, and the difference stays visible

        $this->strayLine($this->tenant, '2110', 'CREDIT', '6', '2026-03-26', '6900');
        $this->assertSame(['MISMATCH', '-11.0000'], [$this->reconResult('ap-gl', '2026-03-31')['status'], $this->reconResult('ap-gl', '2026-03-31')['difference']]);
    }

    public function test_compute_all_covers_every_type_and_only_entitled_types_are_available(): void
    {
        $engine = $this->engineFor($this->tenant);
        $all = $engine->computeAll('2026-03-31');
        $this->assertSame(ReconciliationResult::TYPES, array_keys($all));
        $this->assertSame(ReconciliationResult::TYPES, $engine->availableTypes());
        foreach ($all as $type => $result) {
            $this->assertSame($type, $result->type);
            $this->assertSame('2026-03-31', $result->asOf);
        }
        $this->assertSame(['NOT_APPLICABLE', 'NOT_APPLICABLE'], [$all['TAX_GL']->status, $all['FX_GL']->status]); // nothing taxed, nothing foreign yet
        $this->assertSame($engine->compute('AP_GL', '2026-03-31')->toArray(), $all['AP_GL']->toArray());

        $this->expectExceptionObject(new DomainException('The as_of date must be a valid date (YYYY-MM-DD).', 'AS_OF_INVALID', 422));
        $engine->compute('AP_GL', '2026-02-30');
    }

    public function test_a_type_whose_module_is_not_entitled_is_not_applicable_and_exposes_nothing(): void
    {
        $this->payables();
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', DB::table('modules')->where('code', 'ACCOUNTING_AP')->value('id'))->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($this->tenant->id);

        $engine = $this->engineFor($this->tenant);
        $result = $engine->compute('AP_GL', '2026-03-31')->toArray();
        $this->assertSame(['NOT_APPLICABLE', null, null, null, []], [$result['status'], $result['source_balance'], $result['gl_balance'], $result['difference'], $result['components']]);
        $this->assertNotContains('AP_GL', $engine->availableTypes());
        $this->assertSame('NOT_APPLICABLE', $engine->computeAll('2026-03-31')['AP_GL']->status);
    }

    public function test_a_narrower_data_scope_is_incomplete_and_gives_no_figure(): void
    {
        $this->payables();
        [$branch] = $this->branches($this->tenant, 'JKT');
        $scoped = $this->scopedToken($this->tenant, ['accounting.reconciliation.view'], 'BRANCH', $branch);

        $result = $this->as($scoped)->getJson(self::RC.'/reconciliations/ap-gl?as_of=2026-03-31')->assertOk()->json();
        $this->assertSame(['INCOMPLETE', false, null, null, null, []], [$result['status'], $result['complete'], $result['source_balance'], $result['gl_balance'], $result['difference'], $result['components']]);
        $this->assertNotEmpty($result['notes']);
        $overview = $this->getJson(self::RC.'/reconciliations?as_of=2026-03-31')->assertOk()->json();
        $this->assertFalse($overview['complete']);
        $this->assertSame(['INCOMPLETE'], array_values(array_unique(array_column($overview['data'], 'status'))));
    }

    public function test_a_missing_control_account_is_incomplete_only_when_documents_need_it(): void
    {
        // No AP activity and no control account mapping: nothing to compare.
        DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_role', 'ACCOUNTS_PAYABLE')->delete();
        $empty = $this->reconResult('ap-gl', '2026-03-31');
        $this->assertSame(['MATCHED', '0.0000', '0.0000', []], [$empty['status'], $empty['source_balance'], $empty['gl_balance'], $empty['components']]);
        $this->assertStringContainsString('nothing to compare', implode(' ', $empty['notes']));
    }
}
