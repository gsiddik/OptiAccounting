<?php

namespace Tests\Concurrency;

use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\RaceHelpers;
use Tests\Support\RaceRunner;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\TaxFixtures;

/**
 * OA4 release gate: foreign currency against concurrent requests. Workers are separate PHP processes hitting the real application. The
 * invariants after every race: the books balance in functional currency and in every transaction currency, an invoice carries exactly what
 * it was recognised at minus what the effective settlements released (never a residue, never a second release), and a posted document
 * owns the rate it was posted with whatever happened to the rate master meanwhile.
 */
class CurrencyConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, Fixtures, FxFixtures, PayablesFixtures, RaceHelpers, ReceivablesFixtures, TaxFixtures;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->fxTenant();
        [$user] = $this->member($this->tenant);
        $this->token = $this->tenantToken($user, $this->tenant);
        $this->as($this->token);
    }

    private function http()
    {
        return $this->as($this->token);
    }

    private function assertBooksSound(): void
    {
        $unbalanced = DB::table('journal_entries as j')->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')->where('j.tenant_id', $this->tenant->id)->where('j.status', 'POSTED')
            ->groupBy('j.id')->havingRaw('sum(l.debit) <> sum(l.credit)')->select('j.id')->get()->count();
        $this->assertSame(0, $unbalanced, 'every posted journal balances in functional currency');
        $foreign = DB::table('journal_entries as j')->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')->where('j.tenant_id', $this->tenant->id)->where('j.status', 'POSTED')->where('l.is_fx_difference', false)
            ->groupBy('j.id', 'l.transaction_currency')->havingRaw('sum(l.transaction_debit) <> sum(l.transaction_credit)')->select('j.id')->get()->count();
        $this->assertSame(0, $foreign, 'every posted journal balances in every transaction currency');
    }

    /** What an invoice still carries must be its recognised value minus the release of its effective allocations, exactly. */
    private function assertInvoiceCarries(string $invoiceId, string $table = 'ap_invoices', string $allocations = 'ap_payment_allocations', string $key = 'ap_invoice_id'): void
    {
        $invoice = DB::table($table)->where('id', $invoiceId)->first();
        $released = BigDecimal::of((string) DB::table($allocations)->where($key, $invoiceId)->where('is_effective', true)->sum('carrying_amount'));
        $paid = BigDecimal::of((string) DB::table($allocations)->where($key, $invoiceId)->where('is_effective', true)->sum('amount'));
        $this->assertTrue($released->isLessThanOrEqualTo($invoice->functional_total_amount), 'released more than the invoice carried');
        $this->assertTrue($paid->isLessThanOrEqualTo($invoice->total_amount), 'settled more than the invoice total');
        if ($paid->isEqualTo($invoice->total_amount)) {
            $this->assertTrue($released->isEqualTo($invoice->functional_total_amount), 'a fully settled invoice leaves no residue: '.$released.' of '.$invoice->functional_total_amount);
        }
    }

    private function approvedPayment($vendor, string $bankId, string $invoiceId, string $amount): string
    {
        $id = $this->http()->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bankId, [$invoiceId => $amount], ['currency' => 'USD']))->assertCreated()->json('id');
        $this->postJson(self::AP."/vendor-payments/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$id}/approve")->assertOk();

        return $id;
    }

    public function test_a_posting_racing_the_withdrawal_of_its_rate_either_posts_with_that_rate_or_fails_cleanly(): void
    {
        $rounds = ['posted' => 0, 'refused' => 0];
        $vendor = $this->vendor($this->tenant);

        for ($i = 0; $i < 6; $i++) {
            $date = sprintf('2026-03-%02d', 10 + $i);
            $rate = $this->http()->postJson(self::FX.'/exchange-rates', ['from_currency' => 'USD', 'rate' => (string) (15500 + $i), 'effective_date' => $date])->assertCreated()->json();
            $id = $this->http()->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'USD', 'document_date' => $date, 'posting_date' => $date, 'lines' => [['description' => 'Jasa', 'amount' => '100.00']]]))->assertCreated()->json('id');
            $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
            $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();

            $results = (new RaceRunner)->start([
                $this->job('POST', self::AP."/ap-invoices/{$id}/post"),
                $this->job('POST', self::FX."/exchange-rates/{$rate['id']}/deactivate"),
            ])->results();

            $this->assertNoServerErrors($results);
            $invoice = DB::table('ap_invoices')->where('id', $id)->first();
            if ($results[0]['status'] === 200) {
                $this->assertSame('POSTED', $invoice->status);
                $this->assertSame($rate['id'], $invoice->exchange_rate_id, 'a posted invoice owns the rate it was posted with');
                $this->assertSame($this->functionalOf($invoice->total_amount, $invoice->exchange_rate), $invoice->functional_total_amount);
                $this->assertSame($invoice->functional_total_amount, (string) DB::table('journal_entries')->where('id', $invoice->journal_entry_id)->value('total_debit'));
                $rounds['posted']++;
            } else {
                $this->assertContains($results[0]['body']['code'], ['EXCHANGE_RATE_NOT_FOUND', 'EXCHANGE_RATE_CHANGED']);
                $this->assertSame('APPROVED', $invoice->status);
                $this->assertNull($invoice->journal_entry_id);
                $rounds['refused']++;
            }
            $this->assertBooksSound();
        }

        $this->assertSame(6, array_sum($rounds), json_encode($rounds));
    }

    public function test_two_payments_racing_to_settle_the_same_usd_invoice_settle_it_once_and_leave_no_residue(): void
    {
        $this->http()->postJson(self::FX.'/exchange-rates', ['from_currency' => 'USD', 'rate' => '15500', 'effective_date' => '2026-03-01'])->assertCreated();
        $this->http()->postJson(self::FX.'/exchange-rates', ['from_currency' => 'USD', 'rate' => '15800', 'effective_date' => '2026-03-15'])->assertCreated();
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->http()->postedInvoice($vendor, ['currency' => 'USD', 'lines' => [['description' => 'Jasa', 'amount' => '1000.00']]]);
        $first = $this->approvedPayment($vendor, $bank->id, $invoice['id'], '1000.00');
        $second = $this->approvedPayment($vendor, $bank->id, $invoice['id'], '1000.00');

        $results = (new RaceRunner)->start([$this->job('POST', self::AP."/vendor-payments/{$first}/post"), $this->job('POST', self::AP."/vendor-payments/{$second}/post")])->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, $outcomes['409:AP_ALLOCATION_EXCEEDS_OUTSTANDING'] ?? 0, json_encode($outcomes));
        $this->assertInvoiceCarries($invoice['id']);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2110'));
        $this->assertSame('300000.0000', $this->glBalance($this->tenant, '6950'));
        $this->assertBooksSound();
    }

    public function test_partial_payments_racing_release_exactly_what_fits_and_the_rest_stays_carried(): void
    {
        $this->http()->postJson(self::FX.'/exchange-rates', ['from_currency' => 'USD', 'rate' => '15500', 'effective_date' => '2026-03-01'])->assertCreated();
        $this->http()->postJson(self::FX.'/exchange-rates', ['from_currency' => 'USD', 'rate' => '15800', 'effective_date' => '2026-03-15'])->assertCreated();
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->http()->postedInvoice($vendor, ['currency' => 'USD', 'lines' => [['description' => 'Jasa', 'amount' => '1000.00']]]);
        $payments = array_map(fn () => $this->approvedPayment($vendor, $bank->id, $invoice['id'], '400.00'), [1, 2, 3]);

        $results = (new RaceRunner)->start(array_map(fn ($id) => $this->job('POST', self::AP."/vendor-payments/{$id}/post"), $payments))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(2, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, $outcomes['409:AP_ALLOCATION_EXCEEDS_OUTSTANDING'] ?? 0, json_encode($outcomes));
        $shown = $this->http()->getJson(self::AP."/ap-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['200.0000', '3100000.0000'], [$shown['outstanding_amount'], $shown['outstanding_functional']]);
        $this->assertSame('-3100000.0000', $this->glBalance($this->tenant, '2110'));
        $this->assertInvoiceCarries($invoice['id']);
        $this->assertBooksSound();
    }

    public function test_the_same_foreign_receipt_posted_by_several_requests_books_one_journal(): void
    {
        $this->http()->postJson(self::FX.'/exchange-rates', ['from_currency' => 'USD', 'rate' => '15500', 'effective_date' => '2026-03-01'])->assertCreated();
        $this->http()->postJson(self::FX.'/exchange-rates', ['from_currency' => 'USD', 'rate' => '15800', 'effective_date' => '2026-03-15'])->assertCreated();
        $customer = $this->customer($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->http()->postedArInvoice($customer, ['currency' => 'USD', 'lines' => [['description' => 'Jasa', 'amount' => '1000.00']]]);
        $id = $this->http()->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $bank->id, [$invoice['id'] => '1000.00'], ['currency' => 'USD']))->assertCreated()->json('id');
        $this->postJson(self::AR."/customer-receipts/{$id}/submit")->assertOk();
        $this->postJson(self::AR."/customer-receipts/{$id}/approve")->assertOk();

        $results = (new RaceRunner)->start(array_fill(0, 4, $this->job('POST', self::AR."/customer-receipts/{$id}/post")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->where('source_type', 'customer_receipt')->count());
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1130'));
        $this->assertSame('-300000.0000', $this->glBalance($this->tenant, '4260'));
        $this->assertInvoiceCarries($invoice['id'], 'ar_invoices', 'ar_receipt_allocations', 'ar_invoice_id');
        $this->assertBooksSound();
    }

    /** The functional value of a foreign amount at a rate, half-up at two places (what the application stores), as a four-place string. */
    private function functionalOf(string $amount, string $rate): string
    {
        return (string) BigDecimal::of($amount)->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp)->toScale(4);
    }
}
