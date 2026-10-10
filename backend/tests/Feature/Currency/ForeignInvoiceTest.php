<?php

namespace Tests\Feature\Currency;

use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/** OA4: vendor and customer invoices in a foreign currency: the rate chosen and frozen, the functional journal with its foreign legs, and the safe failures. */
class ForeignInvoiceTest extends TestCase
{
    use AccountingFixtures, Fixtures, FxFixtures, PayablesFixtures, ReceivablesFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->fxTenant();
        $this->signedIn($this->tenant);
    }

    public function test_a_vendor_invoice_in_usd_posts_the_functional_value_and_keeps_the_foreign_leg_and_the_rate(): void
    {
        $rate = $this->rate('USD', '15500', '2026-03-01');
        $vendor = $this->vendor($this->tenant);
        $invoice = $this->usdInvoice($vendor, '1000.00');

        $this->assertSame('USD', $invoice['currency']);
        $this->assertSame('15500.0000000000', (string) $invoice['exchange_rate']);
        $this->assertSame($rate['id'], $invoice['exchange_rate_id']);
        $this->assertSame('1000.0000', $invoice['total_amount']);
        $this->assertSame('15500000.0000', $invoice['functional_total_amount']);

        $journalId = (string) DB::table('ap_invoices')->where('id', $invoice['id'])->value('journal_entry_id');
        $this->assertSame(['15500000.0000', '15500000.0000'], $this->journalTotals($journalId));
        $legs = $this->journalLegs($journalId);
        $this->assertCount(2, $legs);
        foreach ($legs as $leg) {
            $this->assertSame('USD', $leg->transaction_currency);
            $this->assertSame('15500.0000000000', (string) $leg->exchange_rate);
            $this->assertFalse((bool) $leg->is_fx_difference);
        }
        $this->assertSame(['2110', '0.0000', '1000.0000'], [$legs[0]->code, $legs[0]->transaction_debit, $legs[0]->transaction_credit]);
        $this->assertSame(['6900', '1000.0000', '0.0000'], [$legs[1]->code, $legs[1]->transaction_debit, $legs[1]->transaction_credit]);
        $this->assertSame('15500000.0000', $this->glBalance($this->tenant, '6900'));
        $this->assertSame('-15500000.0000', $this->glBalance($this->tenant, '2110'));
    }

    public function test_the_posted_invoice_keeps_its_own_rate_when_the_master_changes_later(): void
    {
        $rate = $this->rate('USD', '15500', '2026-03-01');
        $vendor = $this->vendor($this->tenant);
        $invoice = $this->usdInvoice($vendor);

        $this->postJson(self::FX."/exchange-rates/{$rate['id']}/deactivate")->assertOk();
        $this->rate('USD', '16000', '2026-03-05');

        $shown = $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame('15500000.0000', $shown['functional_total_amount']);
        $this->assertSame($rate['id'], $shown['exchange_rate_id']);
        $this->assertSame('15500000.0000', $this->glBalance($this->tenant, '6900'));
    }

    public function test_a_foreign_invoice_without_a_rate_fails_safely_and_leaves_nothing_behind(): void
    {
        $vendor = $this->vendor($this->tenant);
        $before = $this->glFigures($this->tenant);

        $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'USD', 'lines' => [['description' => 'x', 'amount' => '10.00']]]))
            ->assertStatus(422)->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
        $this->assertSame(0, DB::table('ap_invoices')->where('tenant_id', $this->tenant->id)->count());
        $this->assertEquals($before, $this->glFigures($this->tenant));

        // a rate that is too old is no rate: the resolver never reaches back past the allowed age
        $this->rate('USD', '15500', '2025-12-01');
        $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'USD', 'lines' => [['description' => 'x', 'amount' => '10.00']]]))
            ->assertStatus(422)->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
    }

    public function test_the_rate_is_the_latest_on_or_before_the_posting_date_and_the_type_can_be_asked_for(): void
    {
        $this->rate('USD', '15000', '2026-03-01');
        $spot = $this->rate('USD', '15400', '2026-03-08', 'SPOT');
        $this->rate('USD', '15600', '2026-03-20'); // after the posting date: not eligible
        $vendor = $this->vendor($this->tenant);

        $a = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'USD', 'lines' => [['description' => 'x', 'amount' => '10.00']]]))->assertCreated()->json();
        $this->assertSame(['15400.0000000000', $spot['id'], 'SPOT'], [(string) $a['exchange_rate'], $a['exchange_rate_id'], $a['exchange_rate_type']]);

        $b = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'USD', 'exchange_rate_type' => 'MANUAL', 'lines' => [['description' => 'x', 'amount' => '10.00']]]))->assertCreated()->json();
        $this->assertSame('15000.0000000000', (string) $b['exchange_rate']);
    }

    public function test_a_rate_withdrawn_or_replaced_after_saving_is_reported_when_the_document_moves_on(): void
    {
        $rate = $this->rate('USD', '15500', '2026-03-01');
        $vendor = $this->vendor($this->tenant);
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'USD', 'lines' => [['description' => 'x', 'amount' => '100.00']]]))->assertCreated()->json('id');

        $this->postJson(self::FX."/exchange-rates/{$rate['id']}/deactivate")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertStatus(422)->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');

        $this->rate('USD', '15700', '2026-03-05'); // another rate now applies: the saved one is no longer the current one
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertStatus(409)->assertJsonPath('code', 'EXCHANGE_RATE_CHANGED');
        $this->assertSame('DRAFT', DB::table('ap_invoices')->where('id', $id)->value('status'));

        // saving again picks the rate up
        $this->patchJson(self::AP."/ap-invoices/{$id}", ['description' => 'again'])->assertOk()->assertJsonPath('functional_total_amount', '1570000.0000');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
    }

    public function test_amounts_follow_the_decimal_places_of_the_currency(): void
    {
        $this->rate('USD', '15500', '2026-03-01');
        $vendor = $this->vendor($this->tenant);
        $body = fn (string $amount) => $this->invoiceBody($vendor, ['currency' => 'USD', 'lines' => [['description' => 'x', 'amount' => $amount]]]);

        $this->postJson(self::AP.'/ap-invoices', $body('10.005'))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson(self::AP.'/ap-invoices', $body('10.05'))->assertCreated();
    }

    public function test_an_unknown_inactive_or_unauthorised_currency_is_refused(): void
    {
        $vendor = $this->vendor($this->tenant);
        $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'EUR']))->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_FOUND');

        $usd = DB::table('currencies')->where('tenant_id', $this->tenant->id)->where('code', 'USD')->value('id');
        $this->postJson(self::FX."/currencies/{$usd}/deactivate")->assertOk();
        $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'USD']))->assertStatus(422)->assertJsonPath('code', 'CURRENCY_INACTIVE');
    }

    public function test_a_functional_invoice_is_unchanged_by_all_of_this(): void
    {
        $vendor = $this->vendor($this->tenant);
        $invoice = $this->postedInvoice($vendor);

        $this->assertSame(['IDR', '1.0000000000', null, null], [$invoice['currency'], (string) $invoice['exchange_rate'], $invoice['functional_total_amount'], $invoice['exchange_rate_id']]);
        $journalId = (string) DB::table('ap_invoices')->where('id', $invoice['id'])->value('journal_entry_id');
        foreach ($this->journalLegs($journalId) as $leg) {
            $this->assertSame(['IDR', '1.0000000000'], [$leg->transaction_currency, (string) $leg->exchange_rate]);
        }
    }

    public function test_a_foreign_invoice_with_tax_posts_the_tax_in_functional_currency_and_the_report_adds_up_functional_amounts(): void
    {
        $this->rate('USD', '15500', '2026-03-01');
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode(['code' => 'PPN11']);
        $invoice = $this->usdInvoice($vendor, '1000.00', ['lines' => [['description' => 'Jasa', 'amount' => '1000.00', 'tax_code_id' => $code['id']]]]);

        $this->assertSame(['1110.0000', '17205000.0000'], [$invoice['total_amount'], $invoice['functional_total_amount']]);
        $journalId = (string) DB::table('ap_invoices')->where('id', $invoice['id'])->value('journal_entry_id');
        $this->assertSame([['1150', '1705000.0000', '0.0000'], ['2110', '0.0000', '17205000.0000'], ['6900', '15500000.0000', '0.0000']], $this->sortedJournal($journalId));
        $this->assertSame(['17205000.0000', '17205000.0000'], $this->journalTotals($journalId));

        $row = $this->taxRows('ap_invoice', $invoice['id'])[0];
        $this->assertSame(['USD', '110.0000', '1705000.0000', '15500000.0000'], [$row->currency, $row->tax_amount, $row->functional_tax_amount, $row->functional_base_amount]);
        $report = $this->getJson(self::TX.'/tax-report')->assertOk()->json();
        $this->assertSame('1705000.0000', $report['totals']['input_tax_recoverable']);
    }

    public function test_a_customer_invoice_in_usd_posts_receivable_and_revenue_at_the_rate(): void
    {
        $this->rate('USD', '15500', '2026-03-01');
        $customer = $this->customer($this->tenant);
        $invoice = $this->usdArInvoice($customer, '2000.00');

        $this->assertSame(['USD', '2000.0000', '31000000.0000'], [$invoice['currency'], $invoice['total_amount'], $invoice['functional_total_amount']]);
        $journalId = (string) DB::table('ar_invoices')->where('id', $invoice['id'])->value('journal_entry_id');
        $this->assertSame(['31000000.0000', '31000000.0000'], $this->journalTotals($journalId));
        foreach ($this->journalLegs($journalId) as $leg) {
            // a line is a debit or a credit: the other side of its foreign leg is zero
            $this->assertSame(['USD', '2000.0000'], [$leg->transaction_currency, (string) BigDecimal::of($leg->transaction_debit)->plus($leg->transaction_credit)->toScale(4)]);
        }
        $this->assertSame('31000000.0000', $this->glBalance($this->tenant, '1130'));
    }

    public function test_reversing_a_foreign_invoice_restores_the_ledger_with_the_original_foreign_legs(): void
    {
        $this->rate('USD', '15500', '2026-03-01');
        $vendor = $this->vendor($this->tenant);
        $invoice = $this->usdInvoice($vendor);

        $this->postJson(self::AP."/ap-invoices/{$invoice['id']}/reverse", ['reason' => 'Salah input'])->assertOk()->assertJsonPath('status', 'REVERSED');
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2110'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '6900'));
    }

    public function test_the_aging_report_shows_functional_buckets_and_the_foreign_amount_in_the_detail(): void
    {
        $this->rate('USD', '15500', '2026-03-01');
        $vendor = $this->vendor($this->tenant);
        $this->usdInvoice($vendor, '1000.00');
        $this->postedInvoice($vendor);

        $report = $this->getJson(self::AP.'/ap-aging?as_of=2026-03-31&detail=1')->assertOk()->json();
        $this->assertSame('16500000.0000', $report['totals']['total']);
        $foreign = collect($report['invoices'])->firstWhere('currency', 'USD');
        $this->assertSame(['1000.0000', '15500000.0000'], [$foreign['outstanding_amount'], $foreign['outstanding_functional']]);

        $recon = $this->getJson(self::AP.'/reconciliation/ap?as_of=2026-03-31')->assertOk()->json();
        $this->assertSame('MATCHED', $recon['status']);
    }
}
