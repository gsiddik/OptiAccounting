<?php

namespace App\Domain\Integration\Adapters\OptiDeli;

use App\Domain\Accounting\Services\DocumentWorkflow;
use App\Domain\Accounting\Services\SegregationOfDuties;
use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Adapters\OptiFleet\OptiFleetVendors;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\ExternalSourceLink;
use App\Domain\Integration\Services\ProcessOutcome;
use App\Domain\Payables\Models\ApInvoice;
use App\Domain\Payables\Models\ApPaymentAllocation;
use App\Domain\Payables\Models\VendorPayment;
use App\Domain\Payables\Services\ApInvoiceService;
use App\Domain\Payables\Services\VendorPaymentService;
use App\Domain\Receivables\Models\ArInvoice;
use App\Domain\Receivables\Models\ArReceiptAllocation;
use App\Domain\Receivables\Models\CustomerReceipt;
use App\Domain\Receivables\Services\ArInvoiceService;
use App\Domain\Receivables\Services\CustomerReceiptService;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Books the facts of the OptiDeli sub-ledger through the existing AR and AP services, as the integration user (OptiDeliActor). This class
 * only translates: dates, amounts and parties come from the payload; accounts come from the posting rules and the tenant's mappings, and the
 * tax split from the connection's INCLUSIVE tax codes (the feed states gross amounts and OptiDeli never computes tax).
 *
 * Segregation of duties is never weakened. The integration prepares and goes as far as the accounting policy lets one actor go: with approval
 * required it only submits, otherwise it posts unless the policy forbids the preparer to post (a DRAFT stays for a person). It never approves.
 * A payment or void that needs the document posted waits in NEEDS_REVIEW (INVOICE_NOT_POSTED) and succeeds on retry.
 *
 * A source document maps to ours through `external_source_links` (source type `ledger_document`, the source's document id, which is also the
 * `correlation_id` of every event about it), never through our own columns. Payments link as `ledger_payment`.
 */
class DeliBooking
{
    public const DOCUMENT = 'ledger_document';

    public const PAYMENT = 'ledger_payment';

    private const METHODS = ['BANK_TRANSFER' => 'TRANSFER', 'CASH' => 'CASH', 'CHEQUE' => 'CHEQUE'];

    public function __construct(
        private readonly ArInvoiceService $arInvoices,
        private readonly CustomerReceiptService $receipts,
        private readonly ApInvoiceService $apInvoices,
        private readonly VendorPaymentService $payments,
        private readonly OptiDeliCustomers $customers,
        private readonly OptiFleetVendors $vendors,
        private readonly DocumentWorkflow $workflow,
        private readonly SegregationOfDuties $sod,
    ) {}

    // ------------------------------------------------------------------------------------------------ documents

    public function customerInvoice(ExternalEvent $event, User $actor): ProcessOutcome
    {
        $source = $this->source($event);
        $payload = $this->payload($event);
        $settings = $this->settings($event);
        $this->assertFunctional($payload);
        $this->assertKind($payload, 'CUSTOMER_INVOICE');

        $customer = $this->customers->resolve($event->connection, $payload['customer_name'] ?? $payload['counterparty_name'] ?? null);
        $amount = $this->amount($payload['amount'] ?? null, 'amount');
        $date = $this->date($payload, 'issue_date');
        $orderNumber = $this->optionalText($payload, 'order_number', 60);
        $line = array_filter([
            'description' => Str::limit('Jasa angkut'.($orderNumber ? " order {$orderNumber}" : '')." ({$source['number']})", 250, ''), 'amount' => Money::str($amount),
            'account_id' => $settings[OptiDeliAdapter::REVENUE] ?? null, 'tax_code_id' => $settings[OptiDeliAdapter::OUTPUT_TAX] ?? null,
        ], fn ($v) => $v !== null);

        $created = $this->arInvoices->create([
            'customer_id' => $customer->id, 'document_date' => $date, 'posting_date' => $date, 'due_date' => $this->date($payload, 'due_date'), 'payment_term_id' => null,
            'reference' => $source['number'], 'description' => Str::limit('Invoice pengiriman '.($orderNumber ?? $source['number']), 480, ''), 'lines' => [$line],
        ], $actor);
        $this->assertTotal($created->total_amount, $amount, 'AR invoice');
        $invoice = $this->advance($created, $actor, fn ($i) => $this->arInvoices->submit($i, $actor), fn ($i) => $this->arInvoices->post($i, $actor));

        return $this->outcome($invoice, [$this->link(self::DOCUMENT, $source['id'], $source['number'], ArInvoice::DOCUMENT_TYPE, $invoice)]);
    }

    public function vendorBill(ExternalEvent $event, User $actor): ProcessOutcome
    {
        $source = $this->source($event);
        $payload = $this->payload($event);
        $settings = $this->settings($event);
        $this->assertFunctional($payload);
        $this->assertKind($payload, 'VENDOR_BILL');

        $vendor = $this->vendors->resolve($event->connection, [
            'id' => $event->envelope['dimensions']['vendor_id'] ?? null, 'code' => $payload['vendor_code'] ?? null, 'name' => $payload['vendor_name'] ?? $payload['counterparty_name'] ?? null,
        ]);
        $amount = $this->amount($payload['amount'] ?? null, 'amount');
        $date = $this->date($payload, 'issue_date');
        $number = $this->optionalText($payload, 'freight_invoice_number', 100) ?? $source['number'];
        $line = array_filter([
            'description' => Str::limit("Tagihan angkut {$number}".(($order = $this->optionalText($payload, 'order_number', 60)) ? " (order {$order})" : ''), 250, ''),
            'amount' => Money::str($amount), 'expense_category_id' => $settings[OptiDeliAdapter::CATEGORY], 'tax_code_id' => $settings[OptiDeliAdapter::INPUT_TAX] ?? null,
        ], fn ($v) => $v !== null);

        $created = $this->apInvoices->create([
            'vendor_id' => $vendor->id, 'vendor_invoice_number' => $number, 'document_date' => $date, 'posting_date' => $date, 'due_date' => $this->date($payload, 'due_date'), 'payment_term_id' => null,
            'reference' => $source['number'], 'description' => Str::limit("Tagihan vendor {$source['number']}", 480, ''), 'discount_amount' => '0', 'lines' => [$line],
        ], $actor);
        $this->assertTotal($created->total_amount, $amount, 'AP invoice');
        $invoice = $this->advance($created, $actor, fn ($i) => $this->apInvoices->submit($i, $actor), fn ($i) => $this->apInvoices->post($i, $actor));

        return $this->outcome($invoice, [$this->link(self::DOCUMENT, $source['id'], $source['number'], ApInvoice::DOCUMENT_TYPE, $invoice)]);
    }

    public function voided(ExternalEvent $event, User $actor): ProcessOutcome
    {
        $source = $this->source($event);
        $payload = $this->payload($event);
        $document = $this->document($source['id']);
        $reason = Str::limit('OptiDeli: '.(is_string($payload['reason'] ?? null) && trim($payload['reason']) !== '' ? trim($payload['reason']) : 'Voided in OptiDeli'), 480, '');
        $occurred = $event->occurred_at->copy()->setTimezone($event->connection->tenant->timezone)->toDateString();

        if ($document instanceof ArInvoice) {
            if (ArReceiptAllocation::query()->where('ar_invoice_id', $document->id)->where('is_effective', true)->exists()) {
                throw $this->paymentApplied();
            }
            $result = match ($document->status) {
                ArInvoice::POSTED => $this->arInvoices->reverse($document, $actor, $reason, max($document->posting_date->toDateString(), $occurred)),
                ArInvoice::DRAFT, ArInvoice::SUBMITTED, ArInvoice::APPROVED, ArInvoice::REJECTED => $this->arInvoices->cancel($document, $actor, $reason),
                default => $document,
            };
        } else {
            if (ApPaymentAllocation::query()->where('ap_invoice_id', $document->id)->where('is_effective', true)->exists()) {
                throw $this->paymentApplied();
            }
            $result = match ($document->status) {
                ApInvoice::POSTED => $this->apInvoices->reverse($document, $actor, $reason, max($document->posting_date->toDateString(), $occurred)),
                ApInvoice::DRAFT, ApInvoice::SUBMITTED, ApInvoice::APPROVED, ApInvoice::REJECTED => $this->apInvoices->cancel($document, $actor, $reason),
                default => $document,
            };
        }

        return new ProcessOutcome($result->reversal_journal_id, [$this->link(self::DOCUMENT, $source['id'], $source['number'], $result::DOCUMENT_TYPE, $result)], documentStatus: $result->status);
    }

    // ------------------------------------------------------------------------------------------------ payments

    public function payment(ExternalEvent $event, User $actor): ProcessOutcome
    {
        $source = $this->source($event, self::PAYMENT);
        $payload = $this->payload($event);
        $settings = $this->settings($event);
        $this->assertFunctional($payload);

        $document = $this->document($this->correlation($event));
        if ($document->status !== ArInvoice::POSTED) { // the two document types share the status words
            throw new DomainException("Document {$payload['document_number']} is {$document->status}; a payment can settle it only once it is posted.", 'INVOICE_NOT_POSTED', 409, ['status' => $document->status]);
        }
        $amount = Money::str($this->amount($payload['amount'] ?? null, 'amount'));
        $date = $this->date($payload, 'paid_on');
        $reference = Str::limit((string) (($payload['reference'] ?? null) ?: $source['number']), 100, '');
        $method = self::METHODS[(string) ($payload['method'] ?? '')] ?? 'OTHER';
        $direction = (string) ($payload['direction'] ?? '');

        if ($document instanceof ArInvoice && $direction === 'RECEIPT') {
            $created = $this->receipts->create([
                'customer_id' => $document->customer_id, 'cash_bank_account_id' => $settings[OptiDeliAdapter::RECEIPTS_BANK], 'receipt_date' => $date, 'posting_date' => $date,
                'amount' => $amount, 'receipt_method' => in_array($method, CustomerReceipt::METHODS, true) ? $method : 'OTHER', 'reference' => $reference,
                'description' => Str::limit("Penerimaan invoice {$document->document_number}", 480, ''), 'allocations' => [['ar_invoice_id' => $document->id, 'amount' => $amount]],
            ], $actor);
            $result = $this->advance($created, $actor, fn ($r) => $this->receipts->submit($r, $actor), fn ($r) => $this->receipts->post($r, $actor));
            $type = CustomerReceipt::DOCUMENT_TYPE;
        } elseif ($document instanceof ApInvoice && $direction === 'DISBURSEMENT') {
            $created = $this->payments->create([
                'vendor_id' => $document->vendor_id, 'cash_bank_account_id' => $settings[OptiDeliAdapter::PAYABLES_BANK], 'payment_date' => $date, 'posting_date' => $date,
                'amount' => $amount, 'payment_method' => in_array($method, VendorPayment::METHODS, true) ? $method : 'OTHER', 'reference' => $reference,
                'description' => Str::limit("Pembayaran tagihan {$document->vendor_invoice_number}", 480, ''), 'allocations' => [['ap_invoice_id' => $document->id, 'amount' => $amount]],
            ], $actor);
            $result = $this->advance($created, $actor, fn ($p) => $this->payments->submit($p, $actor), fn ($p) => $this->payments->post($p, $actor));
            $type = VendorPayment::DOCUMENT_TYPE;
        } else {
            throw new DomainException('The payment direction does not match the document it settles.', 'PAYMENT_DIRECTION_MISMATCH', 422, ['direction' => $direction]);
        }

        return $this->outcome($result, [$this->link(self::PAYMENT, $source['id'], $reference, $type, $result)]);
    }

    // ------------------------------------------------------------------------------------------------ shared

    /** Our latest document of a source document (AR invoice or AP invoice). */
    private function document(string $sourceId): ArInvoice|ApInvoice
    {
        $link = ExternalSourceLink::query()->where('source_system', OptiDeliAdapter::SOURCE)->where('source_type', self::DOCUMENT)->where('source_id', $sourceId)
            ->whereIn('document_type', [ArInvoice::DOCUMENT_TYPE, ApInvoice::DOCUMENT_TYPE])->orderByDesc('created_at')->orderByDesc('id')->first();
        $document = match ($link?->document_type) {
            ArInvoice::DOCUMENT_TYPE => ArInvoice::query()->find($link->document_id),
            ApInvoice::DOCUMENT_TYPE => ApInvoice::query()->find($link->document_id),
            default => null,
        };

        return $document ?? throw new DomainException('No document was booked for this OptiDeli document yet; the event that issues it has to be applied first.', 'INVOICE_NOT_FOUND', 409, ['source_id' => $sourceId]);
    }

    private function paymentApplied(): DomainException
    {
        return new DomainException('A payment is applied to this document in OptiEntry; it has to be reversed before the document can be.', 'PAYMENT_ALREADY_APPLIED', 409);
    }

    /** The feed states gross amounts; a tax code that would change the total makes the document differ from the source, so it is refused. */
    private function assertTotal(mixed $total, BigDecimal $expected, string $what): void
    {
        $actual = Money::parse((string) $total, 4, 'total_amount');
        if (! $actual->isEqualTo($expected)) {
            throw new DomainException("The {$what} total differs from the amount OptiDeli states.", 'TOTAL_MISMATCH', 422, ['expected' => Money::str($expected), 'total_amount' => Money::str($actual)]);
        }
    }

    private function amount(mixed $value, string $field): BigDecimal
    {
        $amount = Money::parse($value, (int) $this->workflow->profile()->currency_scale, $field);
        if ($amount->isLessThanOrEqualTo(0)) {
            throw $this->invalid($field);
        }

        return $amount;
    }

    /** What reached the books (ecosystem audit A27): posted = journal; submitted = awaiting an approver; draft = waiting for somebody allowed to post. */
    private function outcome(Model $document, array $links): ProcessOutcome
    {
        $outcome = match ($document->status) {
            'POSTED' => ProcessOutcome::JOURNAL_POSTED,
            'SUBMITTED' => ProcessOutcome::AWAITING_APPROVAL,
            default => ProcessOutcome::DRAFT_DOCUMENT,
        };

        return new ProcessOutcome($document->journal_entry_id, $links, $outcome, $document->status);
    }

    /** Take a prepared (DRAFT) document as far as the accounting policy allows without a person; approval is never performed here. */
    private function advance(Model $document, User $actor, Closure $submit, Closure $post): Model
    {
        $profile = $this->workflow->profile();
        if ($profile->approval_required) {
            return $submit($document);
        }

        return $this->sod->documentAllowed($document, $actor->id, $profile)['post'] ? $post($document) : $document;
    }

    /** @return array<string,?string> */
    private function link(string $type, string $sourceId, ?string $number, string $documentType, Model $document): array
    {
        return ['source_type' => $type, 'source_id' => $sourceId, 'source_document_number' => $number, 'document_type' => $documentType, 'document_id' => $document->id];
    }

    /** @return array{id:string,number:?string} the source object the event is about */
    private function source(ExternalEvent $event, string $type = self::DOCUMENT): array
    {
        $source = $event->envelope['source'] ?? null;
        if (! is_array($source) || ($source['type'] ?? null) !== $type || ! is_string($source['id'] ?? null) || $source['id'] === '') {
            throw $this->invalid('source');
        }

        return ['id' => $source['id'], 'number' => is_string($source['number'] ?? null) ? Str::limit($source['number'], 120, '') : null];
    }

    /** The id of the document a payment settles: every event about a document carries it as its correlation id. */
    private function correlation(ExternalEvent $event): string
    {
        $id = $event->envelope['correlation_id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw $this->invalid('correlation_id');
        }

        return $id;
    }

    /** @return array<string,mixed> */
    private function payload(ExternalEvent $event): array
    {
        return (array) ($event->envelope['payload'] ?? []);
    }

    /** @return array<string,string> the connection's references to our setup, the required ones present */
    private function settings(ExternalEvent $event): array
    {
        $settings = (array) $event->connection->settings;
        if (($missing = OptiDeliAdapter::missing($settings)) !== []) {
            throw new DomainException('The OptiDeli connection lacks '.implode(', ', $missing).'.', 'CONNECTION_SETTINGS_MISSING', 409, ['missing' => $missing]);
        }

        return $settings;
    }

    private function assertKind(array $payload, string $kind): void
    {
        if (($payload['document_kind'] ?? null) !== $kind) {
            throw $this->invalid('document_kind');
        }
    }

    /** Only documents in the functional currency are booked for now (a foreign one needs rates and settlement the adapter does not handle). */
    private function assertFunctional(array $payload): void
    {
        $functional = $this->workflow->profile()->functional_currency;
        $currency = mb_strtoupper(is_string($payload['currency'] ?? null) ? $payload['currency'] : '');
        if ($currency !== $functional) {
            throw new DomainException("Only {$functional} documents can be booked from OptiDeli for now.", 'CURRENCY_NOT_SUPPORTED', 422, ['currency' => $currency, 'functional_currency' => $functional]);
        }
    }

    private function optionalText(array $data, string $key, int $max): ?string
    {
        $value = is_string($data[$key] ?? null) ? trim($data[$key]) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function date(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw $this->invalid($key);
        }

        return $value;
    }

    private function invalid(string $field): DomainException
    {
        return new DomainException("The event does not carry a usable {$field}.", 'INVALID_PAYLOAD', 422, ['field' => $field]);
    }
}
