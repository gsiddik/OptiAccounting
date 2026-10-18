<?php

namespace App\Domain\Integration\Adapters\OptiFleet;

use App\Domain\Accounting\Services\DocumentWorkflow;
use App\Domain\Accounting\Services\SegregationOfDuties;
use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\ExternalSourceLink;
use App\Domain\Integration\Services\ProcessOutcome;
use App\Domain\Payables\Models\ApInvoice;
use App\Domain\Payables\Models\ApPaymentAllocation;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Models\VendorPayment;
use App\Domain\Payables\Services\ApInvoiceService;
use App\Domain\Payables\Services\VendorPaymentService;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Books the facts of an OptiFleet workshop invoice through the existing AP services, as the integration user (OptiFleetActor). This class
 * only translates: dates, amounts, lines and tax come from the payload; the accounts come from the posting rules and the tenant's mappings
 * (lines carry the connection's expense category, payments its cash/bank account), never from here.
 *
 *   recorded         a vendor invoice dated by invoice_date (due_date as stated), lines as stated, tax from tax_total
 *   corrected        the earlier document of the same source is reversed (or cancelled when it never posted), then the corrected invoice is booked
 *   cancelled        the same retirement, nothing new
 *   payment_recorded one vendor payment allocated to the posted invoice, credited to the connection's cash/bank account
 *
 * Segregation of duties is never weakened. The integration prepares, and goes as far as the accounting policy lets one actor go: when
 * approval is required it only submits (a person approves and posts), otherwise it posts, unless the policy forbids the preparer to post
 * (the document stays a DRAFT for a person). It never approves. Such a document is linked like a posted one; events that need it posted
 * (payments, ...) wait in NEEDS_REVIEW (INVOICE_NOT_POSTED) and succeed on retry once a person has posted it.
 *
 * Source documents map to ours through `external_source_links` (workshop_invoice and workshop_invoice_payment, by the source ids), never
 * through our own columns. A later event on a source finds our latest document of it there; none (earlier event unresolved) is INVOICE_NOT_FOUND.
 * Everything runs inside the event's transaction; a refusal of the existing services (closed period, missing rule or mapping, duplicate
 * number, inactive vendor, ...) surfaces unchanged and leaves nothing behind.
 */
class WorkshopInvoiceBooking
{
    public const INVOICE = 'workshop_invoice';

    public const PAYMENT = 'workshop_invoice_payment';

    /** The events that create, replace or retire the document of a source invoice. */
    private const DOCUMENT_EVENTS = ['optifleet.workshop_invoice.recorded', 'optifleet.workshop_invoice.corrected', 'optifleet.workshop_invoice.cancelled'];

    public function __construct(
        private readonly ApInvoiceService $invoices,
        private readonly VendorPaymentService $payments,
        private readonly OptiFleetVendors $vendors,
        private readonly DocumentWorkflow $workflow,
        private readonly SegregationOfDuties $sod,
    ) {}

    public function recorded(ExternalEvent $event, User $actor): ProcessOutcome
    {
        return $this->book($event, $actor);
    }

    public function corrected(ExternalEvent $event, User $actor): ProcessOutcome
    {
        $this->assertEarlierEventsDecided($event, $this->source($event)['id']);
        $this->retire($event, $actor, $this->currentInvoice($this->source($event)['id']), 'correction', 'Corrected in OptiFleet');

        return $this->book($event, $actor);
    }

    public function cancelled(ExternalEvent $event, User $actor): ProcessOutcome
    {
        $this->assertEarlierEventsDecided($event, $this->source($event)['id']);
        $invoice = $this->retire($event, $actor, $this->currentInvoice($this->source($event)['id']), 'cancellation', 'Cancelled in OptiFleet');

        // a reversal journal means the books changed; an invoice that never posted is simply cancelled (no journal, nothing to undo in the GL)
        return new ProcessOutcome($invoice->reversal_journal_id, [$this->link(self::INVOICE, $this->source($event), ApInvoice::DOCUMENT_TYPE, $invoice)], documentStatus: $invoice->status);
    }

    public function paid(ExternalEvent $event, User $actor): ProcessOutcome
    {
        $source = $this->source($event);
        $payload = $this->payload($event);
        $payment = $payload['payment'] ?? null;
        if (! is_array($payment) || ! is_string($payment['id'] ?? null) || $payment['id'] === '') {
            throw $this->invalid('payment.id');
        }
        $settings = $this->settings($event);
        $this->assertFunctional($payload);

        $invoice = $this->currentInvoice($source['id']);
        if ($invoice->status !== ApInvoice::POSTED) {
            throw new DomainException("Invoice {$invoice->vendor_invoice_number} is {$invoice->status}; a payment can settle it only once it is posted.", 'INVOICE_NOT_POSTED', 409, ['status' => $invoice->status]);
        }

        $scale = (int) $this->workflow->profile()->currency_scale;
        $amount = Money::parse($payment['amount'] ?? null, $scale, 'payment.amount');
        $date = $this->date($payment, 'payment_date', 'payment.payment_date');
        $method = mb_strtoupper((string) ($payment['method'] ?? ''));
        $reference = Str::limit((string) (($payment['reference_number'] ?? null) ?: $payment['id']), 100, '');

        $created = $this->payments->create([
            'vendor_id' => $invoice->vendor_id, 'cash_bank_account_id' => $settings[OptiFleetAdapter::CASH_BANK], 'payment_date' => $date, 'posting_date' => $date,
            'amount' => Money::str($amount), 'payment_method' => in_array($method, VendorPayment::METHODS, true) ? $method : null, 'reference' => $reference,
            'description' => Str::limit("Pembayaran faktur bengkel {$invoice->vendor_invoice_number}", 480, ''),
            'allocations' => [['ap_invoice_id' => $invoice->id, 'amount' => Money::str($amount)]],
        ], $actor);
        $document = $this->advance($created, $actor, fn ($p) => $this->payments->submit($p, $actor), fn ($p) => $this->payments->post($p, $actor));

        return $this->outcome($document, [[
            'source_type' => self::PAYMENT, 'source_id' => $payment['id'], 'source_document_number' => $reference, 'document_type' => VendorPayment::DOCUMENT_TYPE, 'document_id' => $document->id,
        ]]);
    }

    // ------------------------------------------------------------------------------------------------ invoices

    private function book(ExternalEvent $event, User $actor): ProcessOutcome
    {
        $source = $this->source($event);
        $payload = $this->payload($event);
        $settings = $this->settings($event);
        $this->assertFunctional($payload);

        $vendor = $this->vendors->resolve($event->connection, $payload['vendor'] ?? null);
        $created = $this->invoices->create($this->invoiceData($event, $payload, $vendor, $settings), $actor);
        $invoice = $this->advance($created, $actor, fn ($i) => $this->invoices->submit($i, $actor), fn ($i) => $this->invoices->post($i, $actor));

        return $this->outcome($invoice, [$this->link(self::INVOICE, $source, ApInvoice::DOCUMENT_TYPE, $invoice, $invoice->vendor_invoice_number)]);
    }

    /**
     * What reached the books (ecosystem audit A27): a posted document is a journal; a submitted one waits for an approver; a draft waits for
     * somebody allowed to post it (approval and segregation of duties are never bypassed here). The event is "processed" in all three cases.
     *
     * @param  list<array<string,?string>>  $links
     */
    private function outcome(Model $document, array $links): ProcessOutcome
    {
        $outcome = match ($document->status) {
            ApInvoice::POSTED => ProcessOutcome::JOURNAL_POSTED,
            ApInvoice::SUBMITTED => ProcessOutcome::AWAITING_APPROVAL,
            default => ProcessOutcome::DRAFT_DOCUMENT,
        };

        return new ProcessOutcome($document->journal_entry_id, $links, $outcome, $document->status);
    }

    /**
     * The AP invoice body of a payload. Money is parsed exactly (a value finer than the currency allows is refused, never rounded), and the
     * stated total must equal lines - discount + tax: a document that does not add up is TOTAL_MISMATCH for a person, not guessed.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,string>  $settings
     * @return array<string,mixed>
     */
    private function invoiceData(ExternalEvent $event, array $payload, Vendor $vendor, array $settings): array
    {
        $scale = (int) $this->workflow->profile()->currency_scale;
        $number = $this->text($payload, 'invoice_number', 100);
        $date = $this->date($payload, 'invoice_date', 'invoice_date');
        $due = ($payload['due_date'] ?? null) === null ? null : $this->date($payload, 'due_date', 'due_date');
        $total = Money::parse($payload['total_amount'] ?? throw $this->invalid('total_amount'), $scale, 'total_amount');
        $discount = Money::parse($payload['discount_total'] ?? null, $scale, 'discount_total');
        $tax = Money::parse($payload['tax_total'] ?? null, $scale, 'tax_total');

        $dimensions = (array) ($event->envelope['dimensions'] ?? []);
        $about = array_filter([$dimensions['work_order_number'] ?? null, $dimensions['vehicle_registration_number'] ?? null], 'is_string');
        $description = "Faktur bengkel {$number}".($about === [] ? '' : ' ('.implode(', ', $about).')');

        $lines = $this->lines($payload, $scale, $settings[OptiFleetAdapter::CATEGORY], $total->minus($tax)->plus($discount), $description);
        $expected = Money::sum(array_column($lines, 'amount'))->minus($discount)->plus($tax);
        if (! $expected->isEqualTo($total)) {
            throw new DomainException('The lines, discount and tax of the workshop invoice do not add up to its total.', 'TOTAL_MISMATCH', 422, ['expected' => Money::str($expected), 'total_amount' => Money::str($total)]);
        }

        $data = [
            'vendor_id' => $vendor->id, 'vendor_invoice_number' => $number, 'document_date' => $date, 'posting_date' => $date, 'description' => Str::limit($description, 480, ''),
            'discount_amount' => Money::str($discount), 'tax_amount' => Money::str($tax), 'lines' => $lines,
        ];
        if (($reference = $this->optionalText($payload, 'partner_reference', 100)) !== null) {
            $data['reference'] = $reference;
        }
        if ($due !== null) { // the stated due date wins over the vendor's default term, which would otherwise refuse a different date
            $data['due_date'] = $due;
            $data['payment_term_id'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function lines(array $payload, int $scale, string $categoryId, BigDecimal $whole, string $description): array
    {
        $lines = [];
        foreach (array_values(array_filter((array) ($payload['lines'] ?? []), 'is_array')) as $i => $row) {
            $n = $i + 1;
            $quantity = ($row['quantity'] ?? null) === null ? null : Money::parse($row['quantity'], 4, 'quantity', $n);
            $price = ($row['unit_price'] ?? null) === null ? null : Money::parse($row['unit_price'], 4, 'unit_price', $n);
            $amount = ($row['line_total'] ?? null) !== null ? Money::parse($row['line_total'], $scale, 'line_total', $n)
                : ($quantity !== null && $price !== null ? $quantity->multipliedBy($price)->toScale($scale, RoundingMode::HalfUp) : null);
            if ($amount === null) {
                throw new DomainException('A line states neither its total nor a quantity and unit price.', 'LINE_AMOUNT_INVALID', 422, ['line' => $n]);
            }
            $lines[] = [
                'description' => (string) ($row['description'] ?? ''), 'amount' => Money::str($amount), 'expense_category_id' => $categoryId,
                // the amount is the source's own line total; quantity and price are kept as information (the AP line would recompute the amount from them)
                'metadata' => array_filter(['quantity' => $quantity === null ? null : Money::str($quantity), 'unit_price' => $price === null ? null : Money::str($price)], fn ($v) => $v !== null) ?: null,
            ];
        }

        // An invoice stated by totals only becomes one line for the whole net amount.
        return $lines !== [] ? $lines : [['description' => $description, 'amount' => Money::str($whole), 'expense_category_id' => $categoryId]];
    }

    /**
     * A correction or cancellation replaces the document the source's earlier events produced, so it is applied only once every earlier
     * event that records, corrects or cancels the same source invoice has been decided (POSTED, or REJECTED by review). Otherwise a later
     * cancellation could be followed by an older correction that books the cancelled invoice again, or an older correction could overwrite a
     * newer one. Payments neither wait for nor hold back other events: a payment that arrives before its invoice has its own wait
     * (INVOICE_NOT_FOUND). The held event is retried by a person once the earlier one is settled.
     */
    private function assertEarlierEventsDecided(ExternalEvent $event, string $sourceId): void
    {
        if ($event->sequence === null) {
            return; // only feed events are ordered, and the feed always numbers them
        }
        $earlier = ExternalEvent::query()->where('connection_id', $event->connection_id)->where('sequence', '<', $event->sequence)
            ->whereIn('event_type', self::DOCUMENT_EVENTS)
            ->whereIn('status', [ExternalEvent::RECEIVED, ExternalEvent::VALIDATED, ExternalEvent::FAILED, ExternalEvent::NEEDS_REVIEW, ExternalEvent::DEAD_LETTER])
            ->whereRaw("envelope->'source'->>'id' = ?", [$sourceId])->orderBy('sequence')->first();
        if ($earlier !== null) {
            throw new DomainException(
                "An earlier event of this workshop invoice (sequence {$earlier->sequence}) is not decided yet; it has to be applied or dismissed first, then retry this one.",
                'EARLIER_EVENT_UNRESOLVED', 409, ['earlier_event_id' => $earlier->event_id, 'earlier_sequence' => $earlier->sequence, 'earlier_status' => $earlier->status],
            );
        }
    }

    /** Our latest document of a source invoice (a corrected invoice replaces the one it reversed). */
    private function currentInvoice(string $sourceId): ApInvoice
    {
        $link = ExternalSourceLink::query()->where('source_system', OptiFleetAdapter::SOURCE)->where('source_type', self::INVOICE)->where('source_id', $sourceId)
            ->where('document_type', ApInvoice::DOCUMENT_TYPE)->orderByDesc('created_at')->orderByDesc('id')->first();

        return ($link === null ? null : ApInvoice::query()->find($link->document_id))
            ?? throw new DomainException('No invoice was booked for this workshop invoice yet; the event that records it has to be applied first.', 'INVOICE_NOT_FOUND', 409, ['source_id' => $sourceId]);
    }

    /**
     * Take the booked document out of the books: reverse it when posted, cancel it when it never posted, nothing when it is already gone.
     * A posted invoice with an effective payment is refused (PAYMENT_ALREADY_APPLIED): the payment has to be dealt with by a person first.
     * The reversal is dated by the day OptiFleet recorded the change (tenant time), never before the invoice's own posting date, so a change
     * made after the invoice's period was closed is booked in the current period.
     */
    private function retire(ExternalEvent $event, User $actor, ApInvoice $invoice, string $block, string $fallbackReason): ApInvoice
    {
        $change = (array) ($this->payload($event)[$block] ?? []);
        $reason = Str::limit('OptiFleet: '.(is_string($change['reason'] ?? null) && trim($change['reason']) !== '' ? trim($change['reason']) : $fallbackReason), 480, '');

        return match ($invoice->status) {
            ApInvoice::POSTED => $this->reverse($event, $actor, $invoice, $reason, (bool) ($change['had_payment'] ?? false)),
            ApInvoice::DRAFT, ApInvoice::SUBMITTED, ApInvoice::APPROVED, ApInvoice::REJECTED => $this->invoices->cancel($invoice, $actor, $reason),
            default => $invoice,
        };
    }

    private function reverse(ExternalEvent $event, User $actor, ApInvoice $invoice, string $reason, bool $hadPayment): ApInvoice
    {
        if (ApPaymentAllocation::query()->where('ap_invoice_id', $invoice->id)->where('is_effective', true)->exists()) {
            throw new DomainException('A payment is applied to this invoice in OptiEntry; it has to be reversed before the invoice can be.', 'PAYMENT_ALREADY_APPLIED', 409, ['had_payment' => $hadPayment]);
        }
        $occurred = $event->occurred_at->copy()->setTimezone($event->connection->tenant->timezone)->toDateString();

        return $this->invoices->reverse($invoice, $actor, $reason, max($invoice->posting_date->toDateString(), $occurred));
    }

    // ------------------------------------------------------------------------------------------------ shared

    /**
     * Take a prepared (DRAFT) document as far as the accounting policy allows without a person. Approval is a human act and is never
     * performed here: with approval required the document is submitted; otherwise it is posted when the segregation-of-duties flags let the
     * preparer post, else it stays a DRAFT.
     */
    private function advance(Model $document, User $actor, Closure $submit, Closure $post): Model
    {
        $profile = $this->workflow->profile();
        if ($profile->approval_required) {
            return $submit($document);
        }

        return $this->sod->documentAllowed($document, $actor->id, $profile)['post'] ? $post($document) : $document;
    }

    /** @param array{id:string,number:?string} $source @return array<string,?string> */
    private function link(string $type, array $source, string $documentType, Model $document, ?string $number = null): array
    {
        return [
            'source_type' => $type, 'source_id' => $source['id'], 'source_document_number' => $number ?? $source['number'], 'document_type' => $documentType, 'document_id' => $document->id,
        ];
    }

    /** @return array{id:string,number:?string} the source invoice the event is about */
    private function source(ExternalEvent $event): array
    {
        $source = $event->envelope['source'] ?? null;
        if (! is_array($source) || ($source['type'] ?? null) !== self::INVOICE || ! is_string($source['id'] ?? null) || $source['id'] === '') {
            throw $this->invalid('source');
        }

        return ['id' => $source['id'], 'number' => is_string($source['number'] ?? null) ? Str::limit($source['number'], 120, '') : null];
    }

    /** @return array<string,mixed> */
    private function payload(ExternalEvent $event): array
    {
        return (array) ($event->envelope['payload'] ?? []);
    }

    /** @return array<string,string> the connection's references to our setup, all present */
    private function settings(ExternalEvent $event): array
    {
        $settings = (array) $event->connection->settings;
        if (($missing = OptiFleetAdapter::missing($settings)) !== []) {
            throw new DomainException('The OptiFleet connection lacks '.implode(', ', $missing).'.', 'CONNECTION_SETTINGS_MISSING', 409, ['missing' => $missing]);
        }

        return $settings;
    }

    /** Only documents in the functional currency are booked for now (a foreign one needs rates and settlement the adapter does not handle). */
    private function assertFunctional(array $payload): void
    {
        $functional = $this->workflow->profile()->functional_currency;
        $currency = mb_strtoupper($this->text($payload, 'currency', 3));
        if ($currency !== $functional) {
            throw new DomainException("Only {$functional} workshop invoices can be booked from OptiFleet for now.", 'CURRENCY_NOT_SUPPORTED', 422, ['currency' => $currency, 'functional_currency' => $functional]);
        }
    }

    private function text(array $data, string $key, int $max): string
    {
        $value = is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        if ($value === '' || mb_strlen($value) > $max) {
            throw $this->invalid($key);
        }

        return $value;
    }

    private function optionalText(array $data, string $key, int $max): ?string
    {
        $value = is_string($data[$key] ?? null) ? trim($data[$key]) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function date(array $data, string $key, string $field): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw $this->invalid($field);
        }

        return $value;
    }

    private function invalid(string $field): DomainException
    {
        return new DomainException("The event does not carry a usable {$field}.", 'INVALID_PAYLOAD', 422, ['field' => $field]);
    }
}
