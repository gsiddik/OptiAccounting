<?php

namespace Tests\Feature\Integration;

use App\Domain\Integration\Adapters\OptiDeli\OptiDeliAdapter;
use App\Domain\Integration\Services\BookStatus;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\OptiDeliFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/**
 * Wave 5 part B: the OptiDeli adapter end to end. Feed pages built from the contract go through the real FeedClient (HTTP faked), the inbox,
 * the processor and the existing AR / AP services, so what is asserted is what lands in the subledgers, the ledger and the audit trail.
 */
class OptiDeliAdapterTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, OptiDeliFixtures, PayablesFixtures, ReceivablesFixtures, TaxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDeli();
    }

    /** @return array{0:string,1:string} status and error code */
    private function outcome(array $envelope): array
    {
        return $this->outcomeOf($envelope);
    }

    private function journalBalanced(string $journalId): array
    {
        $sum = DB::table('journal_lines')->where('journal_entry_id', $journalId)->selectRaw('sum(debit) as d, sum(credit) as c')->first();
        $this->assertSame((string) $sum->d, (string) $sum->c);

        return [(string) $sum->d];
    }

    public function test_a_customer_invoice_is_posted_to_the_ar_subledger_and_the_customer_is_created_once(): void
    {
        [$tenant, $connection] = $this->deliTenant();
        $first = $this->invoiceIssued('doc-ar-1');
        $summary = $this->pullDeli($tenant, $connection, [$first]);

        $this->assertSame([1, 1, null], [$summary['received'], $summary['posted'], $summary['error']]);
        $row = $this->eventRow($first);
        $invoice = DB::table('ar_invoices')->first();
        $this->assertSame(['POSTED', 'JOURNAL_POSTED', $invoice->journal_entry_id], [$row->status, $row->accounting_outcome, $row->resulting_journal_id]);
        $this->assertSame(['POSTED', '1110000.0000', '2026-03-10', '2026-04-09', 'AR-000001'], [$invoice->status, (string) $invoice->total_amount, $invoice->document_date, $invoice->due_date, $invoice->reference]);
        $this->assertSame(['1110000.0000'], $this->journalBalanced($invoice->journal_entry_id));

        $customer = DB::table('customers')->first();
        $this->assertSame(['PT Pelanggan Maju', 'optideli'], [$customer->name, $customer->external_source]);
        $link = DB::table('external_source_links')->first();
        $this->assertSame(['optideli', 'ledger_document', 'doc-ar-1', 'ar_invoice', $invoice->id], [$link->source_system, $link->source_type, $link->source_id, $link->document_type, $link->document_id]);

        // A second invoice for the same name finds the same customer.
        $this->pullDeli($tenant, $connection, [$this->invoiceIssued('doc-ar-2')]);
        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame(2, DB::table('ar_invoices')->count());
        $counts = $this->inTenant($tenant, fn () => app(BookStatus::class)->counts($connection->id));
        $this->assertSame(2, $counts['IN_LEDGER']); // AR invoices count in the books state like AP documents
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://deli.example.test/api/v1/integration/feed?') && $request->hasHeader('Authorization', 'Bearer feed-secret-123'));
    }

    public function test_an_inclusive_output_tax_code_splits_the_gross_amount_in_optientry(): void
    {
        [$tenant, $connection] = $this->deliTenant();
        $this->asMember($tenant);
        $code = $this->outputCode(['calculation_method' => 'INCLUSIVE', 'name' => 'PPN Keluaran 11% inklusif']);
        $this->inTenant($tenant, fn () => $connection->forceFill(['settings' => [...$connection->settings, 'output_tax_code_id' => $code['id']]])->save());

        $this->pullDeli($tenant, $connection, [$this->invoiceIssued('doc-ar-1')]);

        $invoice = DB::table('ar_invoices')->first();
        $this->assertSame(['POSTED', '1110000.0000', '110000.0000'], [$invoice->status, (string) $invoice->total_amount, (string) $invoice->tax_amount]);
        $this->assertSame(['1110000.0000'], $this->journalBalanced($invoice->journal_entry_id));
    }

    public function test_only_an_inclusive_tax_code_is_accepted_in_the_settings(): void
    {
        [$tenant] = $this->deliTenant();
        $this->asMember($tenant);
        $exclusive = $this->outputCode(['name' => 'PPN Keluaran eksklusif']);

        $adapter = new OptiDeliAdapter;
        $this->inTenant($tenant, function () use ($adapter, $exclusive) {
            try {
                $adapter->cleanSettings(['output_tax_code_id' => $exclusive['id']]);
                $this->fail('An exclusive tax code must be refused.');
            } catch (DomainException $e) {
                $this->assertSame('CONNECTION_SETTINGS_INVALID', $e->errorCode);
            }
            $this->assertSame([], $adapter->cleanSettings([]));
        });
    }

    public function test_a_vendor_bill_is_posted_to_the_ap_subledger_with_the_vendor_created_from_the_source(): void
    {
        [$tenant, $connection] = $this->deliTenant();
        $event = $this->billRecorded();
        $this->pullDeli($tenant, $connection, [$event]);

        $invoice = DB::table('ap_invoices')->first();
        $this->assertSame(['POSTED', 'FI-77', '800000.0000', '2026-04-09'], [$invoice->status, $invoice->vendor_invoice_number, (string) $invoice->total_amount, $invoice->due_date]);
        $this->assertSame(['800000.0000'], $this->journalBalanced($invoice->journal_entry_id));
        $vendor = DB::table('vendors')->first();
        $this->assertSame(['optideli', 'ven-1', 'CV Angkut Jaya'], [$vendor->external_source, $vendor->external_id, $vendor->name]);
        $this->assertSame('ap_invoice', DB::table('external_source_links')->value('document_type'));
    }

    public function test_a_receipt_and_a_disbursement_settle_the_documents(): void
    {
        [$tenant, $connection] = $this->deliTenant();
        $this->pullDeli($tenant, $connection, [$this->invoiceIssued('doc-ar-1'), $this->billRecorded('doc-ap-1')]);

        $receipt = $this->paymentRecorded('doc-ar-1', 'RECEIPT', '1110000.00', 'RCV-1');
        $payment = $this->paymentRecorded('doc-ap-1', 'DISBURSEMENT', '300000.00', 'TRF-9', ['document_status' => 'PARTIAL']);
        $this->pullDeli($tenant, $connection, [$receipt, $payment]);

        $this->assertSame(['POSTED', null], $this->outcome($receipt));
        $this->assertSame(['POSTED', null], $this->outcome($payment));
        $this->assertSame(['POSTED', 'RCV-1', '1110000.0000'], [DB::table('customer_receipts')->value('status'), DB::table('customer_receipts')->value('reference'), (string) DB::table('customer_receipts')->value('amount')]);
        $this->assertSame(['POSTED', '300000.0000'], [DB::table('vendor_payments')->value('status'), (string) DB::table('vendor_payments')->value('amount')]);
        $this->assertSame('1110000.0000', number_format((float) DB::table('ar_receipt_allocations')->where('is_effective', true)->sum('amount'), 4, '.', ''));
        $this->assertSame(['ledger_payment', 'ledger_payment'], DB::table('external_source_links')->where('source_type', 'ledger_payment')->pluck('source_type')->all());
    }

    public function test_a_payment_that_arrives_before_its_document_waits_and_succeeds_on_retry(): void
    {
        [$tenant, $connection] = $this->deliTenant();
        $payment = $this->paymentRecorded('doc-ar-1', 'RECEIPT', '1110000.00', 'RCV-1');
        $this->pullDeli($tenant, $connection, [$payment]);
        $this->assertSame(['NEEDS_REVIEW', 'INVOICE_NOT_FOUND'], $this->outcome($payment));

        $this->pullDeli($tenant, $connection, [$this->invoiceIssued('doc-ar-1')]);
        $this->asMember($tenant)->postJson(self::IT.'/events/'.$this->eventRow($payment)->id.'/retry', ['reason' => 'Invoice has arrived'])->assertOk();
        $this->assertSame(['POSTED', null], $this->outcome($payment));
        $this->assertSame(1, DB::table('customer_receipts')->count());
    }

    public function test_a_void_reverses_a_posted_invoice_and_is_refused_after_a_payment(): void
    {
        [$tenant, $connection] = $this->deliTenant();
        $this->pullDeli($tenant, $connection, [$this->invoiceIssued('doc-ar-1'), $this->invoiceIssued('doc-ar-2')]);
        $this->pullDeli($tenant, $connection, [$this->paymentRecorded('doc-ar-2', 'RECEIPT', '100000.00', 'RCV-2')]);

        $voided = $this->documentVoided('doc-ar-1');
        $blocked = $this->documentVoided('doc-ar-2');
        $this->pullDeli($tenant, $connection, [$voided, $blocked]);

        $this->assertSame(['POSTED', null], $this->outcome($voided));
        $this->assertSame('REVERSED', DB::table('ar_invoices')->join('external_source_links as l', 'l.document_id', '=', 'ar_invoices.id')->where('l.source_id', 'doc-ar-1')->value('ar_invoices.status'));
        $this->assertSame(['NEEDS_REVIEW', 'PAYMENT_ALREADY_APPLIED'], $this->outcome($blocked));
    }

    public function test_a_foreign_currency_document_is_not_guessed(): void
    {
        [$tenant, $connection] = $this->deliTenant();
        $event = $this->invoiceIssued('doc-ar-1', ['currency' => 'USD']);
        $this->pullDeli($tenant, $connection, [$event]);

        $this->assertSame(['NEEDS_REVIEW', 'CURRENCY_NOT_SUPPORTED'], $this->outcome($event));
        $this->assertSame(0, DB::table('ar_invoices')->count());
    }

    public function test_without_the_operators_service_identity_nothing_is_booked(): void
    {
        [$tenant, $connection] = $this->deliTenant();
        DB::table('service_principals')->where('tenant_id', $tenant->id)->where('kind', 'OPTIDELI_INTEGRATION')->update(['revoked_at' => now(), 'revoke_reason' => 'test']);
        $event = $this->invoiceIssued('doc-ar-1');
        $this->pullDeli($tenant, $connection, [$event]);

        $this->assertSame(0, DB::table('ar_invoices')->count());
        $this->assertNotSame('POSTED', $this->eventRow($event)->status);
    }
}
