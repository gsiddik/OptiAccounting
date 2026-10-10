<?php

namespace Tests\Support;

use App\Domain\Accounting\Services\OperationalSetupService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Payables\Services\PaymentTermService;
use App\Domain\Receivables\Models\Customer;
use App\Domain\Receivables\Services\CustomerService;
use Brick\Math\BigDecimal;

/** Test helpers for the OA3 receivables module; combine with AccountingFixtures, Fixtures and PayablesFixtures (cash/bank accounts, members, glBalance). */
trait ReceivablesFixtures
{
    protected const AR = '/api/v1/app/accounting';

    /** An accounting tenant with the standard OA2 and OA3 posting rules published from the start of its fiscal year and the standard payment terms. */
    protected function receivablesTenant(string $code = 'alpha', array $profile = []): Tenant
    {
        $tenant = $this->accountingTenant($code, profile: $profile);
        $this->inTenant($tenant, function () {
            app(OperationalSetupService::class)->applyDefaults('2026-01-01');
            app(PaymentTermService::class)->applyDefaults();
        });

        return $tenant;
    }

    protected function customer(Tenant $tenant, string $code = 'C1', array $attributes = []): Customer
    {
        return $this->inTenant($tenant, fn () => app(CustomerService::class)->create($attributes + ['code' => $code, 'name' => "Pelanggan {$code}"]));
    }

    /** @return array<string,mixed> a one-line invoice body (revenue Rp 1.000.000 to the REVENUE role unless told otherwise) */
    protected function arInvoiceBody(Customer $customer, array $override = []): array
    {
        return $override + [
            'customer_id' => $customer->id, 'customer_reference' => 'PO-'.substr(uniqid(), -6), 'document_date' => '2026-03-10', 'posting_date' => '2026-03-10',
            'description' => 'Penjualan jasa', 'lines' => [['description' => 'Jasa angkut', 'amount' => '1000000']],
        ];
    }

    /** Create, submit, approve and post a customer invoice as the already-authenticated client. @return array<string,mixed> the posted invoice */
    protected function postedArInvoice(Customer $customer, array $override = []): array
    {
        $id = $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, $override))->assertCreated()->json('id');
        $this->postJson(self::AR."/ar-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AR."/ar-invoices/{$id}/approve")->assertOk();

        return $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertOk()->json();
    }

    /** A posted invoice of a given amount. */
    protected function arInvoice(Customer $customer, string $amount = '1000000', array $override = []): array
    {
        return $this->postedArInvoice($customer, ['lines' => [['description' => 'Jasa', 'amount' => $amount]]] + $override);
    }

    /** A customer receipt body allocating the given amounts [invoice id => amount]; the receipt amount is their sum unless told otherwise. */
    protected function receiptBody(Customer $customer, string $cashBankAccountId, array $allocations, array $override = []): array
    {
        $rows = [];
        $sum = BigDecimal::zero();
        foreach ($allocations as $invoiceId => $amount) {
            $rows[] = ['ar_invoice_id' => $invoiceId, 'amount' => (string) $amount];
            $sum = $sum->plus((string) $amount);
        }

        return $override + [
            'customer_id' => $customer->id, 'cash_bank_account_id' => $cashBankAccountId, 'receipt_date' => '2026-03-20', 'posting_date' => '2026-03-20',
            'amount' => (string) $sum->toScale(4), 'receipt_method' => 'TRANSFER', 'reference' => 'TRF-'.substr(uniqid(), -5), 'allocations' => $rows,
        ];
    }

    /** Create, submit, approve and post a receipt as the already-authenticated client. @return array<string,mixed> the posted receipt */
    protected function postedReceipt(Customer $customer, string $cashBankAccountId, array $allocations, array $override = []): array
    {
        $id = $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $cashBankAccountId, $allocations, $override))->assertCreated()->json('id');
        $this->postJson(self::AR."/customer-receipts/{$id}/submit")->assertOk();
        $this->postJson(self::AR."/customer-receipts/{$id}/approve")->assertOk();

        return $this->postJson(self::AR."/customer-receipts/{$id}/post")->assertOk()->json();
    }

    /** @return array<string,mixed> a credit note body for an invoice (Rp 100.000 net) */
    protected function creditNoteBody(string $invoiceId, array $override = []): array
    {
        return $override + [
            'ar_invoice_id' => $invoiceId, 'document_date' => '2026-03-25', 'posting_date' => '2026-03-25', 'reason' => 'Retur sebagian',
            'lines' => [['description' => 'Retur jasa', 'amount' => '100000']],
        ];
    }

    /** Create, submit, approve and post a credit note as the already-authenticated client. @return array<string,mixed> the posted note */
    protected function postedCreditNote(string $invoiceId, array $override = []): array
    {
        $id = $this->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($invoiceId, $override))->assertCreated()->json('id');
        $this->postJson(self::AR."/ar-credit-notes/{$id}/submit")->assertOk();
        $this->postJson(self::AR."/ar-credit-notes/{$id}/approve")->assertOk();

        return $this->postJson(self::AR."/ar-credit-notes/{$id}/post")->assertOk()->json();
    }

    protected function arOutstanding(string $invoiceId): string
    {
        return (string) $this->getJson(self::AR."/ar-invoices/{$invoiceId}")->assertOk()->json('outstanding_amount');
    }
}
