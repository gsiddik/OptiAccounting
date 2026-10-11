<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
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
 * OA5 batch H: TAX_GL (recorded tax transactions against the tax control accounts, an accounting reconciliation and not a tax-return one)
 * and FX_GL (realised exchange differences and open foreign balances; revaluation is NOT IMPLEMENTED and never invented).
 */
class ReconciliationTaxFxTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reconTenant();
        $this->signedIn($this->tenant);
    }

    // ------------------------------------------------------------------------------------------------ tax

    /** Input tax 110.000 (AP invoice of 1.000.000, 10 March) and output tax 220.000 (AR invoice of 2.000.000, 12 March). @return array{input:array<string,mixed>,output:array<string,mixed>} */
    private function taxedDocuments(): array
    {
        $input = $this->postedTaxedInvoice($this->vendor($this->tenant), $this->taxCode()['id']);
        $outputCode = $this->outputCode();
        $output = $this->postedArInvoice($this->customer($this->tenant), ['document_date' => '2026-03-12', 'posting_date' => '2026-03-12', 'lines' => [['description' => 'Jasa', 'amount' => '2000000', 'tax_code_id' => $outputCode['id']]]]);

        return ['input' => $input, 'output' => $output];
    }

    public function test_tax_is_not_applicable_until_a_tax_transaction_is_posted(): void
    {
        $this->taxCode();
        $result = $this->reconResult('tax-gl', '2026-03-31');
        $this->assertSame(['NOT_APPLICABLE', null, null, []], [$result['status'], $result['source_balance'], $result['difference'], $result['components']]);
        $this->postedTaxedInvoice($this->vendor($this->tenant), $this->taxCode()['id']);
        $this->assertSame('NOT_APPLICABLE', $this->reconResult('tax-gl', '2026-03-09')['status']);   // reproducible: nothing was posted yet on that date
        $this->assertSame('MATCHED', $this->reconResult('tax-gl', '2026-03-10')['status']);
    }

    public function test_recorded_tax_equals_the_tax_control_accounts_and_the_note_says_it_is_not_a_tax_return(): void
    {
        $this->taxedDocuments();

        $result = $this->reconResult('tax-gl', '2026-03-31');
        $this->assertSame(['TAX_GL', 'MATCHED', '110000.0000', '110000.0000', '0.0000'], [$result['type'], $result['status'], $result['source_balance'], $result['gl_balance'], $result['difference']]); // net payable
        $this->assertSame(['220000.0000', '220000.0000', 'MATCHED'], [$this->componentOf($result, 'TAX_OUTPUT')['source_balance'], $this->componentOf($result, 'TAX_OUTPUT')['gl_balance'], $this->componentOf($result, 'TAX_OUTPUT')['status']]);
        $this->assertSame(['110000.0000', '110000.0000', 'MATCHED'], [$this->componentOf($result, 'TAX_INPUT_RECOVERABLE')['source_balance'], $this->componentOf($result, 'TAX_INPUT_RECOVERABLE')['gl_balance'], $this->componentOf($result, 'TAX_INPUT_RECOVERABLE')['status']]);
        $this->assertStringContainsString('not a statutory tax-return reconciliation', implode(' ', $result['notes']));
        $this->assertSame(1, count($this->componentOf($result, 'TAX_OUTPUT')['breakdown']));

        $accounts = collect($result['components'])->where('code', 'TAX_CONTROL_ACCOUNT')->mapWithKeys(fn ($c) => [$c['reference']['code'] => $c['gl_balance']])->all();
        $this->assertSame(['1150' => '110000.0000', '2120' => '220000.0000'], $accounts); // each in its normal direction
        $this->assertSame('MATCHED', $this->reconResult('tax-gl', '2026-03-11')['status']);          // only the input tax existed on 11 March
        $this->assertSame('-110000.0000', $this->reconResult('tax-gl', '2026-03-11')['source_balance']);
    }

    public function test_a_settlement_to_the_tax_authority_and_other_ledger_activity_are_not_errors(): void
    {
        $this->taxedDocuments();
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '100000', '2120', '1120'), 'posting_date' => '2026-03-25', 'document_date' => '2026-03-25']);

        $result = $this->reconResult('tax-gl', '2026-03-31');
        $this->assertSame('MATCHED', $result['status']);
        $accounts = collect($result['components'])->where('code', 'TAX_CONTROL_ACCOUNT')->mapWithKeys(fn ($c) => [$c['reference']['code'] => $c['gl_balance']])->all();
        $this->assertSame('120000.0000', $accounts['2120']); // the settlement lowered the payable; the output tax recorded is unchanged
    }

    public function test_a_record_or_ledger_line_that_breaks_equality_is_a_tax_mismatch(): void
    {
        $docs = $this->taxedDocuments();

        // The ledger side: the tax line of the input invoice's own journal is 10.000 short (corrupted by bypassing the guards).
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE journal_lines DISABLE TRIGGER USER');
        DB::table('journal_lines')->where('journal_entry_id', $docs['input']['journal_entry_id'])->where('account_id', $this->account($this->tenant, '1150')->id)->update(['debit' => 100000, 'transaction_debit' => 100000]);
        DB::statement('ALTER TABLE journal_lines ENABLE TRIGGER USER');
        $result = $this->reconResult('tax-gl', '2026-03-31');
        $this->assertSame('MISMATCH', $result['status']);
        $this->assertSame(['110000.0000', '100000.0000', '10000.0000', 'MISMATCH'], [$this->componentOf($result, 'TAX_INPUT_RECOVERABLE')['source_balance'], $this->componentOf($result, 'TAX_INPUT_RECOVERABLE')['gl_balance'], $this->componentOf($result, 'TAX_INPUT_RECOVERABLE')['difference'], $this->componentOf($result, 'TAX_INPUT_RECOVERABLE')['status']]);
        $this->assertSame('MATCHED', $this->componentOf($result, 'TAX_OUTPUT')['status']);

        // The record side: the frozen tax of the output transaction is changed directly.
        DB::statement('ALTER TABLE tax_transactions DISABLE TRIGGER USER');
        DB::table('tax_transactions')->where('source_id', $docs['output']['id'])->update(['tax_amount' => 200000]);
        DB::statement('ALTER TABLE tax_transactions ENABLE TRIGGER USER');
        $both = $this->reconResult('tax-gl', '2026-03-31');
        $this->assertSame(['MISMATCH', 'MISMATCH', '-20000.0000'], [$both['status'], $this->componentOf($both, 'TAX_OUTPUT')['status'], $this->componentOf($both, 'TAX_OUTPUT')['difference']]);
    }

    public function test_a_reversed_document_leaves_both_sides_together_and_a_non_recoverable_tax_is_informational(): void
    {
        $docs = $this->taxedDocuments();
        $this->postedTaxedInvoice($this->vendor($this->tenant, 'V2'), $this->taxCode(['is_recoverable' => false])['id'], '500000', ['document_date' => '2026-03-14', 'posting_date' => '2026-03-14']);
        $this->postJson(self::RC."/ap-invoices/{$docs['input']['id']}/reverse", ['reason' => 'Salah input', 'posting_date' => '2026-03-20'])->assertOk();

        $after = $this->reconResult('tax-gl', '2026-03-31');
        $this->assertSame(['MATCHED', '220000.0000', '220000.0000'], [$after['status'], $after['source_balance'], $after['gl_balance']]);
        $this->assertSame(['0.0000', '0.0000'], [$this->componentOf($after, 'TAX_INPUT_RECOVERABLE')['source_balance'], $this->componentOf($after, 'TAX_INPUT_RECOVERABLE')['gl_balance']]);
        $informational = $this->componentOf($after, 'TAX_INPUT_NON_RECOVERABLE');
        $this->assertSame(['55000.0000', null, 'INFORMATIONAL'], [$informational['source_balance'], $informational['gl_balance'], $informational['status']]); // a cost of its line, not on a tax account

        $before = $this->reconResult('tax-gl', '2026-03-19'); // the reversal had not happened yet: the input tax still counts on both sides
        $this->assertSame(['MATCHED', '110000.0000', '110000.0000'], [$before['status'], $this->componentOf($before, 'TAX_INPUT_RECOVERABLE')['source_balance'], $this->componentOf($before, 'TAX_INPUT_RECOVERABLE')['gl_balance']]);
    }

    // ------------------------------------------------------------------------------------------------ fx

    /** USD 1.000 vendor invoice paid in full at a higher rate (loss 300.000), a USD 500 vendor invoice left open, a USD 800 customer invoice settled at a higher rate (gain 240.000) and a USD 200 one left open. */
    private function foreignDocuments(): array
    {
        $this->rate('USD', '15500', '2026-03-01');
        $this->rate('USD', '15800', '2026-03-15');
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $vendor = $this->vendor($this->tenant, 'V1');
        $customer = $this->customer($this->tenant, 'C1');
        $paid = $this->usdInvoice($vendor, '1000.00');
        $this->usdInvoice($vendor, '500.00');
        $payment = $this->postedPayment($vendor, $bank->id, [$paid['id'] => '1000.00'], ['currency' => 'USD']);
        $sold = $this->usdArInvoice($customer, '800.00');
        $this->usdArInvoice($customer, '200.00');
        $receipt = $this->postedReceipt($customer, $bank->id, [$sold['id'] => '800.00'], ['currency' => 'USD']);

        return ['payment' => $payment, 'receipt' => $receipt];
    }

    public function test_fx_is_not_applicable_until_a_foreign_document_is_posted(): void
    {
        $this->assertSame('NOT_APPLICABLE', $this->reconResult('fx-gl', '2026-03-31')['status']);
        $this->foreignDocuments();
        $this->assertSame('NOT_APPLICABLE', $this->reconResult('fx-gl', '2026-03-05')['status']);
        $this->assertSame('MATCHED', $this->reconResult('fx-gl', '2026-03-10')['status']);
    }

    public function test_realised_fx_and_the_open_foreign_balances_equal_the_ledger_and_revaluation_is_not_invented(): void
    {
        $this->foreignDocuments();

        $result = $this->reconResult('fx-gl', '2026-03-31');
        $this->assertSame('MATCHED', $result['status']);
        $this->assertSame(['-60000.0000', '-60000.0000', '0.0000'], [$result['source_balance'], $result['gl_balance'], $result['difference']]); // net realised gain: 240.000 - 300.000
        $this->assertSame(['240000.0000', '240000.0000'], [$this->componentOf($result, 'REALISED_FX_GAIN')['source_balance'], $this->componentOf($result, 'REALISED_FX_GAIN')['gl_balance']]);
        $this->assertSame(['300000.0000', '300000.0000'], [$this->componentOf($result, 'REALISED_FX_LOSS')['source_balance'], $this->componentOf($result, 'REALISED_FX_LOSS')['gl_balance']]);
        $ap = $this->componentOf($result, 'FOREIGN_OPEN_AP:USD');
        $this->assertSame(['7750000.0000', '7750000.0000', 'MATCHED', '500.0000', 1, 'USD'], [$ap['source_balance'], $ap['gl_balance'], $ap['status'], $ap['foreign_outstanding'], $ap['documents'], $ap['currency']]);
        $ar = $this->componentOf($result, 'FOREIGN_OPEN_AR:USD');
        $this->assertSame(['3100000.0000', '3100000.0000', 'MATCHED', '200.0000', 1], [$ar['source_balance'], $ar['gl_balance'], $ar['status'], $ar['foreign_outstanding'], $ar['documents']]);

        $accounts = collect($result['components'])->where('code', 'FX_ACCOUNT')->mapWithKeys(fn ($c) => [$c['reference']['code'] => $c['gl_balance']])->all();
        $this->assertSame(['4260' => '240000.0000', '6950' => '300000.0000'], $accounts);
        $this->assertSame([], array_values(array_filter(array_column($result['components'], 'code'), fn ($c) => str_contains(strtolower($c), 'reval'))));
        $this->assertStringContainsString('revaluation is NOT IMPLEMENTED', implode(' ', $result['notes']));
        $this->assertSame('MATCHED', $this->reconResult('ap-gl', '2026-03-31')['status']); // the functional control accounts agree with the same documents
        $this->assertSame('MATCHED', $this->reconResult('ar-gl', '2026-03-31')['status']);

        $early = $this->reconResult('fx-gl', '2026-03-18'); // before the settlements: no realised difference yet, both invoices still open
        $this->assertSame(['MATCHED', '0.0000', '0.0000'], [$early['status'], $early['source_balance'], $early['gl_balance']]);
        $this->assertSame('23250000.0000', $this->componentOf($early, 'FOREIGN_OPEN_AP:USD')['source_balance']); // 1.500 USD at 15.500
    }

    public function test_a_reversed_settlement_takes_its_difference_out_of_both_sides(): void
    {
        $docs = $this->foreignDocuments();
        $this->postJson(self::RC."/vendor-payments/{$docs['payment']['id']}/reverse", ['reason' => 'Salah bank', 'posting_date' => '2026-03-25'])->assertOk();

        $after = $this->reconResult('fx-gl', '2026-03-31');
        $this->assertSame('MATCHED', $after['status']);
        $this->assertSame(['0.0000', '0.0000'], [$this->componentOf($after, 'REALISED_FX_LOSS')['source_balance'], $this->componentOf($after, 'REALISED_FX_LOSS')['gl_balance']]);
        $this->assertSame('23250000.0000', $this->componentOf($after, 'FOREIGN_OPEN_AP:USD')['source_balance']); // the invoice is open again at its historical rate
        $this->assertSame(['300000.0000', '300000.0000'], [$this->componentOf($this->reconResult('fx-gl', '2026-03-24'), 'REALISED_FX_LOSS')['source_balance'], $this->componentOf($this->reconResult('fx-gl', '2026-03-24'), 'REALISED_FX_LOSS')['gl_balance']]);
    }

    public function test_a_difference_between_a_settlement_and_its_journal_is_an_fx_mismatch(): void
    {
        $docs = $this->foreignDocuments();

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE journal_lines DISABLE TRIGGER USER');
        DB::table('journal_lines')->where('journal_entry_id', $docs['payment']['journal_entry_id'])->where('is_fx_difference', true)->update(['debit' => 290000]);
        DB::statement('ALTER TABLE journal_lines ENABLE TRIGGER USER');
        $loss = $this->reconResult('fx-gl', '2026-03-31');
        $this->assertSame(['MISMATCH', '300000.0000', '290000.0000', '10000.0000', 'MISMATCH'], [$loss['status'], $this->componentOf($loss, 'REALISED_FX_LOSS')['source_balance'], $this->componentOf($loss, 'REALISED_FX_LOSS')['gl_balance'],
            $this->componentOf($loss, 'REALISED_FX_LOSS')['difference'], $this->componentOf($loss, 'REALISED_FX_LOSS')['status']]);
        $this->assertSame('MATCHED', $this->componentOf($loss, 'REALISED_FX_GAIN')['status']);

        // A foreign invoice whose control line no longer carries its functional value.
        $invoice = DB::table('ar_invoices')->where('currency', 'USD')->where('functional_total_amount', '3100000')->first();
        DB::statement('ALTER TABLE journal_lines DISABLE TRIGGER USER');
        DB::table('journal_lines')->where('journal_entry_id', $invoice->journal_entry_id)->where('account_id', $this->account($this->tenant, '1130')->id)->update(['debit' => 3000000]);
        DB::statement('ALTER TABLE journal_lines ENABLE TRIGGER USER');
        $open = $this->reconResult('fx-gl', '2026-03-31');
        $this->assertSame(['3100000.0000', '3000000.0000', 'MISMATCH'], [$this->componentOf($open, 'FOREIGN_OPEN_AR:USD')['source_balance'], $this->componentOf($open, 'FOREIGN_OPEN_AR:USD')['gl_balance'], $this->componentOf($open, 'FOREIGN_OPEN_AR:USD')['status']]);
        $this->assertSame('MISMATCH', $this->reconResult('ar-gl', '2026-03-31')['status']);
    }

    public function test_tax_and_fx_are_not_applicable_without_their_modules(): void
    {
        $this->taxedDocuments();
        foreach (['ACCOUNTING_TAX', 'ACCOUNTING_MULTI_CURRENCY'] as $module) {
            DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', DB::table('modules')->where('code', $module)->value('id'))->update(['state' => 'DISABLED']);
        }
        app(AccessCache::class)->touchTenant($this->tenant->id);

        $engine = $this->engineFor($this->tenant);
        $this->assertSame(['NOT_APPLICABLE', 'NOT_APPLICABLE'], [$engine->compute('TAX_GL', '2026-03-31')->status, $engine->compute('FX_GL', '2026-03-31')->status]);
        $this->assertSame(['AP_GL', 'AR_GL', 'CASH_BANK_GL', 'FIXED_ASSET_GL'], $engine->availableTypes());
        $this->signedIn($this->tenant);
        $this->getJson(self::RC.'/reconciliations/tax-gl?as_of=2026-03-31')->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
        $this->assertSame(['AP_GL', 'AR_GL', 'CASH_BANK_GL', 'FIXED_ASSET_GL'], array_column($this->getJson(self::RC.'/reconciliations?as_of=2026-03-31')->assertOk()->json('data'), 'type'));
    }
}
