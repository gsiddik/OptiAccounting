<?php

namespace Tests\Feature\Currency;

use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/** OA4: payments and receipts in a foreign currency: the value the invoice carried, the value the bank moved, and the realised exchange difference between them. */
class ForeignSettlementTest extends TestCase
{
    use AccountingFixtures, Fixtures, FxFixtures, PayablesFixtures, ReceivablesFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->fxTenant();
        $this->signedIn($this->tenant);
        $this->rate('USD', '15500', '2026-03-01');
    }

    /** @return array{string,string,string,string} [account, debit, credit, foreign leg] per line, sorted by account then by debit, for a posted document's journal */
    private function legs(string $journalId): array
    {
        return array_map(fn ($l) => [$l->code, $l->debit, $l->credit, $l->transaction_currency.' '.($l->transaction_debit > 0 ? $l->transaction_debit : $l->transaction_credit).' @'.(float) $l->exchange_rate.($l->is_fx_difference ? ' FX' : '')], $this->journalLegs($journalId));
    }

    // ------------------------------------------------------------------------------------------------ AP

    public function test_paying_a_usd_invoice_at_a_higher_rate_books_a_realised_loss(): void
    {
        $this->rate('USD', '15800', '2026-03-15');
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->usdInvoice($vendor, '1000.00');

        $payment = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '1000.00'], ['currency' => 'USD']);

        $this->assertSame(['USD', '1000.0000', '15800000.0000', '300000.0000'], [$payment['currency'], $payment['amount'], $payment['functional_amount'], $payment['fx_difference']]);
        $journalId = (string) $payment['journal_entry_id'];
        $this->assertSame([
            ['1120', '0.0000', '15800000.0000', 'USD 1000.0000 @15800'],
            ['2110', '15500000.0000', '0.0000', 'USD 1000.0000 @15500'],
            ['6950', '300000.0000', '0.0000', 'IDR 300000.0000 @1 FX'],
        ], $this->legs($journalId));
        $this->assertSame(['15800000.0000', '15800000.0000'], $this->journalTotals($journalId));

        $shown = $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['0.0000', '0.0000', 'PAID'], [$shown['outstanding_amount'], $shown['outstanding_functional'], $shown['payment_status']]);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2110'));
        $this->assertSame('-15800000.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertSame('300000.0000', $this->glBalance($this->tenant, '6950'));
        $allocation = DB::table('ap_payment_allocations')->where('vendor_payment_id', $payment['id'])->first();
        $this->assertSame(['15500000.0000', '15800000.0000'], [$allocation->carrying_amount, $allocation->settlement_amount]);
    }

    public function test_paying_at_a_lower_rate_books_a_realised_gain(): void
    {
        $this->rate('USD', '15200', '2026-03-15');
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->usdInvoice($vendor, '1000.00');

        $payment = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '1000.00'], ['currency' => 'USD']);

        $this->assertSame(['15200000.0000', '-300000.0000'], [$payment['functional_amount'], $payment['fx_difference']]);
        $this->assertSame([
            ['1120', '0.0000', '15200000.0000', 'USD 1000.0000 @15200'],
            ['2110', '15500000.0000', '0.0000', 'USD 1000.0000 @15500'],
            ['4260', '0.0000', '300000.0000', 'IDR 300000.0000 @1 FX'],
        ], $this->legs((string) $payment['journal_entry_id']));
        $this->assertSame(['15500000.0000', '15500000.0000'], $this->journalTotals((string) $payment['journal_entry_id']));
        $this->assertSame('-300000.0000', $this->glBalance($this->tenant, '4260'));
    }

    public function test_paying_at_the_invoice_rate_books_no_difference_line(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->usdInvoice($vendor, '1000.00');

        $payment = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '1000.00'], ['currency' => 'USD']);

        $this->assertSame('0.0000', $payment['fx_difference']);
        $this->assertCount(2, $this->journalLegs((string) $payment['journal_entry_id']));
    }

    public function test_partial_payments_release_the_invoice_in_proportion_and_the_last_one_leaves_no_residue(): void
    {
        $this->rate('USD', '15833.3333', '2026-03-15');
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->usdInvoice($vendor, '1000.00');

        $first = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '333.33'], ['currency' => 'USD']);
        $afterFirst = $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['666.6700', 'PARTIALLY_PAID'], [$afterFirst['outstanding_amount'], $afterFirst['payment_status']]);
        $carried = BigDecimal::of((string) DB::table('ap_payment_allocations')->where('vendor_payment_id', $first['id'])->value('carrying_amount'));
        $this->assertSame('5166615.0000', (string) $carried->toScale(4)); // 15.500.000 x 333,33 / 1.000
        $this->assertSame('15500000.0000', (string) $carried->plus($afterFirst['outstanding_functional'])->toScale(4));

        $second = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '666.67'], ['currency' => 'USD']);
        $final = $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['0.0000', '0.0000', 'PAID'], [$final['outstanding_amount'], $final['outstanding_functional'], $final['payment_status']]);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2110'));

        // the two journals together relieved exactly what the invoice booked, and the loss is what the bank moved over it
        $carryingTotal = BigDecimal::of((string) DB::table('ap_payment_allocations')->where('ap_invoice_id', $invoice['id'])->sum('carrying_amount'));
        $this->assertSame('15500000.0000', (string) $carryingTotal->toScale(4));
        $moved = BigDecimal::of($first['functional_amount'])->plus($second['functional_amount']);
        $this->assertSame((string) $moved->minus('15500000')->toScale(4), $this->glBalance($this->tenant, '6950'));
    }

    public function test_a_payment_settles_invoices_of_its_own_currency_only(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $usd = $this->usdInvoice($vendor, '1000.00');
        $idr = $this->postedInvoice($vendor);

        $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$idr['id'] => '1000.00'], ['currency' => 'USD']))
            ->assertStatus(422)->assertJsonPath('code', 'AP_ALLOCATION_CURRENCY_MISMATCH');
        $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$usd['id'] => '1000.00']))
            ->assertStatus(422)->assertJsonPath('code', 'AP_ALLOCATION_CURRENCY_MISMATCH');

        // auto allocation proposes the invoices of the payment's currency only
        $suggest = $this->getJson(self::AP."/vendors/{$vendor->id}/open-invoices?currency=USD")->assertOk()->json('data');
        $this->assertSame([$usd['id']], array_column($suggest, 'id'));
        $this->assertSame([['ap_invoice_id' => $usd['id'], 'amount' => '400.0000']], $this->getJson(self::AP."/vendors/{$vendor->id}/allocation-suggestion?amount=400&currency=USD")->assertOk()->json('data'));
    }

    public function test_reversing_a_foreign_payment_restores_the_invoice_and_the_ledger(): void
    {
        $this->rate('USD', '15800', '2026-03-15');
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->usdInvoice($vendor, '1000.00');
        $payment = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '1000.00'], ['currency' => 'USD']);

        $this->postJson(self::AP."/vendor-payments/{$payment['id']}/reverse", ['reason' => 'Salah rekening'])->assertOk()->assertJsonPath('status', 'REVERSED');

        $shown = $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['1000.0000', '15500000.0000', 'UNPAID'], [$shown['outstanding_amount'], $shown['outstanding_functional'], $shown['payment_status']]);
        $this->assertSame('-15500000.0000', $this->glBalance($this->tenant, '2110'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '6950'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertSame('MATCHED', $this->getJson(self::AP.'/reconciliation/ap?as_of=2026-03-31')->assertOk()->json('status'));
    }

    public function test_a_payment_whose_rate_is_missing_or_changed_is_refused_and_posts_nothing(): void
    {
        $rate = $this->rate('USD', '15800', '2026-03-15');
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->usdInvoice($vendor, '1000.00');
        $id = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '1000.00'], ['currency' => 'USD']))->assertCreated()->json('id');
        $this->postJson(self::AP."/vendor-payments/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$id}/approve")->assertOk();
        $before = $this->glFigures($this->tenant);

        $this->postJson(self::FX."/exchange-rates/{$rate['id']}/deactivate")->assertOk(); // the older 15.500 rate applies again: not the one the payment was saved with
        $this->postJson(self::AP."/vendor-payments/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'EXCHANGE_RATE_CHANGED');
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame('APPROVED', DB::table('vendor_payments')->where('id', $id)->value('status'));
    }

    // ------------------------------------------------------------------------------------------------ AR

    public function test_receiving_a_usd_invoice_at_a_higher_rate_books_a_realised_gain(): void
    {
        $this->rate('USD', '15800', '2026-03-15');
        $customer = $this->customer($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->usdArInvoice($customer, '1000.00');

        $receipt = $this->postedReceipt($customer, $bank->id, [$invoice['id'] => '1000.00'], ['currency' => 'USD']);

        $this->assertSame(['USD', '15800000.0000', '300000.0000'], [$receipt['currency'], $receipt['functional_amount'], $receipt['fx_difference']]);
        $this->assertSame([
            ['1120', '15800000.0000', '0.0000', 'USD 1000.0000 @15800'],
            ['1130', '0.0000', '15500000.0000', 'USD 1000.0000 @15500'],
            ['4260', '0.0000', '300000.0000', 'IDR 300000.0000 @1 FX'],
        ], $this->legs((string) $receipt['journal_entry_id']));
        $this->assertSame(['15800000.0000', '15800000.0000'], $this->journalTotals((string) $receipt['journal_entry_id']));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1130'));
        $this->assertSame('0.0000', $this->arOutstanding($invoice['id']));
        $this->assertSame('MATCHED', $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-03-31')->assertOk()->json('status'));
    }

    public function test_receiving_at_a_lower_rate_books_a_realised_loss_and_a_partial_receipt_leaves_the_rest_open(): void
    {
        $this->rate('USD', '15000', '2026-03-15');
        $customer = $this->customer($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->usdArInvoice($customer, '1000.00');

        $receipt = $this->postedReceipt($customer, $bank->id, [$invoice['id'] => '400.00'], ['currency' => 'USD']);

        $this->assertSame(['6000000.0000', '-200000.0000'], [$receipt['functional_amount'], $receipt['fx_difference']]);
        $this->assertSame([
            ['1120', '6000000.0000', '0.0000', 'USD 400.0000 @15000'],
            ['1130', '0.0000', '6200000.0000', 'USD 400.0000 @15500'],
            ['6950', '200000.0000', '0.0000', 'IDR 200000.0000 @1 FX'],
        ], $this->legs((string) $receipt['journal_entry_id']));
        $shown = $this->getJson(self::AR."/ar-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['600.0000', '9300000.0000'], [$shown['outstanding_amount'], $shown['outstanding_functional']]);
        $this->assertSame('9300000.0000', $this->glBalance($this->tenant, '1130'));

        $aging = $this->getJson(self::AR.'/ar-aging?as_of=2026-03-31&detail=1')->assertOk()->json();
        $this->assertSame('9300000.0000', $aging['totals']['total']);
        $this->assertSame(['USD', '600.0000'], [$aging['invoices'][0]['currency'], $aging['invoices'][0]['outstanding_amount']]);
        $this->assertSame('MATCHED', $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-03-31')->assertOk()->json('status'));
    }

    public function test_a_receipt_is_matched_to_invoices_of_its_own_currency_and_can_be_reversed(): void
    {
        $this->rate('USD', '15800', '2026-03-15');
        $customer = $this->customer($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $usd = $this->usdArInvoice($customer, '1000.00');
        $idr = $this->arInvoice($customer);

        $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $bank->id, [$idr['id'] => '1000.00'], ['currency' => 'USD']))
            ->assertStatus(422)->assertJsonPath('code', 'AR_ALLOCATION_CURRENCY_MISMATCH');

        $receipt = $this->postedReceipt($customer, $bank->id, [$usd['id'] => '1000.00'], ['currency' => 'USD']);
        $this->postJson(self::AR."/customer-receipts/{$receipt['id']}/reverse", ['reason' => 'Salah pelanggan'])->assertOk();
        $shown = $this->getJson(self::AR."/ar-invoices/{$usd['id']}")->assertOk()->json();
        $this->assertSame(['1000.0000', '15500000.0000'], [$shown['outstanding_amount'], $shown['outstanding_functional']]);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '4260'));
        $this->assertSame('MATCHED', $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-03-31')->assertOk()->json('status'));
    }

    public function test_the_cash_to_gl_reconciliation_uses_what_the_bank_moved_in_functional_currency(): void
    {
        $this->rate('USD', '15800', '2026-03-15');
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $vendor = $this->vendor($this->tenant);
        $customer = $this->customer($this->tenant);
        $this->postedPayment($vendor, $bank->id, [$this->usdInvoice($vendor, '1000.00')['id'] => '1000.00'], ['currency' => 'USD']);
        $this->postedReceipt($customer, $bank->id, [$this->usdArInvoice($customer, '500.00')['id'] => '500.00'], ['currency' => 'USD']);

        $row = collect($this->getJson(self::AP.'/reconciliation/cash-bank?as_of=2026-03-31')->assertOk()->json('accounts'))->firstWhere('code', 'BCA');
        // 1 000 USD paid and 500 USD received, both at 15 800: the bank moved functional amounts, not the USD figures
        $this->assertSame(['15800000.0000', '7900000.0000', '-7900000.0000', '0.0000', 'MATCHED'], [
            $row['documents']['vendor_payments'], $row['documents']['customer_receipts'], $row['documents']['net'], $row['documents']['difference'], $row['documents']['status'],
        ]);
        $this->assertSame('-7900000.0000', $row['book_balance']);
    }

    public function test_a_credit_note_cannot_be_raised_against_a_foreign_invoice(): void
    {
        $customer = $this->customer($this->tenant);
        $invoice = $this->usdArInvoice($customer, '1000.00');

        $this->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($invoice['id']))->assertStatus(422)->assertJsonPath('code', 'AR_CREDIT_NOTE_FOREIGN_INVOICE');
    }

    public function test_the_dashboard_totals_are_functional(): void
    {
        $vendor = $this->vendor($this->tenant);
        $this->usdInvoice($vendor, '1000.00');
        $this->postedInvoice($vendor);

        $summary = $this->getJson(self::AP.'/operational-summary')->assertOk()->json();
        $this->assertSame('16500000.0000', $summary['payables']['outstanding']['amount']);
    }

    // ------------------------------------------------------------------------------------------------ the database repeats the rules

    public function test_the_database_refuses_to_rewrite_what_a_foreign_settlement_posted(): void
    {
        $this->rate('USD', '15800', '2026-03-15');
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->usdInvoice($vendor, '1000.00');
        $payment = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '1000.00'], ['currency' => 'USD']);
        $allocation = DB::table('ap_payment_allocations')->where('vendor_payment_id', $payment['id'])->value('id');

        $attempts = [
            'the functional value an allocation released' => fn () => DB::table('ap_payment_allocations')->where('id', $allocation)->update(['carrying_amount' => '1']),
            'the functional value that settled it' => fn () => DB::table('ap_payment_allocations')->where('id', $allocation)->update(['settlement_amount' => '1']),
            'the exchange difference of a posted payment' => fn () => DB::table('vendor_payments')->where('id', $payment['id'])->update(['fx_difference' => '0']),
            'the functional amount of a posted payment' => fn () => DB::table('vendor_payments')->where('id', $payment['id'])->update(['functional_amount' => '1']),
            'the rate of a posted payment' => fn () => DB::table('vendor_payments')->where('id', $payment['id'])->update(['exchange_rate' => '1']),
            'the functional total of a posted invoice' => fn () => DB::table('ap_invoices')->where('id', $invoice['id'])->update(['functional_total_amount' => '1']),
            'the rate of a posted invoice' => fn () => DB::table('ap_invoices')->where('id', $invoice['id'])->update(['exchange_rate' => '1']),
            'a line of a posted foreign journal' => fn () => DB::table('journal_lines')->where('journal_entry_id', $payment['journal_entry_id'])->where('is_fx_difference', true)->update(['is_fx_difference' => false]),
        ];
        foreach ($attempts as $what => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("{$what} was changed");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0], "{$what}: ".$e->getMessage());
            }
        }
        $this->assertSame('300000.0000', $this->glBalance($this->tenant, '6950'));
    }

    public function test_a_journal_that_does_not_balance_in_its_own_transaction_currency_is_refused_by_the_database(): void
    {
        $id = $this->draft($this->tenant)['id'];
        $period = $this->period($this->tenant, '2026-03');
        $post = fn () => DB::transaction(fn () => DB::table('journal_entries')->where('id', $id)->update([
            'status' => 'POSTED', 'journal_number' => 'JV-FY2026-000777', 'posted_at' => now(), 'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id,
        ]));

        // balanced in functional currency, but the USD legs differ by one unit
        DB::table('journal_lines')->where('journal_entry_id', $id)->where('line_number', 1)->update(['transaction_currency' => 'USD', 'transaction_debit' => '100.0000', 'exchange_rate' => '1000']);
        DB::table('journal_lines')->where('journal_entry_id', $id)->where('line_number', 2)->update(['transaction_currency' => 'USD', 'transaction_credit' => '99.0000', 'exchange_rate' => '1000']);
        try {
            $post();
            $this->fail('a journal unbalanced in USD was posted');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0], $e->getMessage());
        }
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));

        DB::table('journal_lines')->where('journal_entry_id', $id)->where('line_number', 2)->update(['transaction_credit' => '100.0000']);
        $this->assertSame(1, $post());
    }
}
