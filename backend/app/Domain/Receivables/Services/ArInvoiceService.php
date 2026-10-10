<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AccountGuard;
use App\Domain\Accounting\Services\ActorAuthority;
use App\Domain\Accounting\Services\DimensionGuard;
use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Services\DocumentWorkflow;
use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Accounting\Services\PostingEngine;
use App\Domain\Accounting\Services\ReadinessService;
use App\Domain\Accounting\Services\ReversalService;
use App\Domain\Accounting\Services\SegregationOfDuties;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\User;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Payables\Services\PaymentTermService;
use App\Domain\Receivables\Models\ArCreditNote;
use App\Domain\Receivables\Models\ArInvoice;
use App\Domain\Receivables\Models\ArInvoiceLine;
use App\Domain\Receivables\Models\ArReceiptAllocation;
use App\Domain\Receivables\Models\Customer;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customer invoices: draft preparation, validation and posting. Posting is a business fact handed to `PostingEngine::postEvent`
 * (event AR_INVOICE_RECOGNIZED): the rule and the tenant's mappings decide the accounts, never this class. A customer can override
 * the receivable account and name a default revenue account; every line can be classified to a revenue account or role. Those are
 * data the document carries. The invoice, its lines and its journal stay linked; after posting nothing financial changes (service
 * and database triggers).
 */
class ArInvoiceService
{
    public function __construct(
        private readonly DocumentWorkflow $workflow,
        private readonly CustomerService $customers,
        private readonly PaymentTermService $terms,
        private readonly AccountGuard $accounts,
        private readonly DimensionGuard $dimensions,
        private readonly DocumentScope $scope,
        private readonly ActorAuthority $authority,
        private readonly PostingEngine $engine,
        private readonly ReversalService $reversals,
        private readonly PeriodGuard $periods,
        private readonly ReadinessService $readiness,
        private readonly SegregationOfDuties $sod,
        private readonly ArSubledgerService $subledger,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------------------------------------------ queries

    /** Invoices the user's data scope reaches, filtered; settlement filters are added by the subledger. */
    public function query(array $filter = []): Builder
    {
        $query = ArInvoice::query()->with(['customer', 'branch', 'businessUnit', 'creator']);
        $this->scope->restrict($query->getQuery(), 'ar_invoices');
        $this->subledger->figures($query);
        $this->subledger->filter($query, $filter, $this->subledger->today());

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('ar_invoices.status', $v))
            ->when($filter['customer_id'] ?? null, fn ($q, $v) => $q->where('ar_invoices.customer_id', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('ar_invoices.branch_id', $v))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $v) => $q->where('ar_invoices.business_unit_id', $v))
            ->when($filter['cost_center_id'] ?? null, fn ($q, $v) => $q->where('ar_invoices.cost_center_id', $v))
            ->when($filter['document_from'] ?? null, fn ($q, $v) => $q->whereDate('ar_invoices.document_date', '>=', $v))
            ->when($filter['document_to'] ?? null, fn ($q, $v) => $q->whereDate('ar_invoices.document_date', '<=', $v))
            ->when($filter['posting_from'] ?? null, fn ($q, $v) => $q->whereDate('ar_invoices.posting_date', '>=', $v))
            ->when($filter['posting_to'] ?? null, fn ($q, $v) => $q->whereDate('ar_invoices.posting_date', '<=', $v))
            ->when($filter['due_from'] ?? null, fn ($q, $v) => $q->whereDate('ar_invoices.due_date', '>=', $v))
            ->when($filter['due_to'] ?? null, fn ($q, $v) => $q->whereDate('ar_invoices.due_date', '<=', $v))
            ->when($filter['mine'] ?? false, fn ($q) => $q->where('ar_invoices.created_by', $this->context->user()?->id))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(coalesce(ar_invoices.document_number, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(ar_invoices.customer_reference, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(ar_invoices.description) like ?', [$like])->orWhereRaw('lower(coalesce(ar_invoices.reference, \'\')) like ?', [$like]));
            })
            ->orderByDesc('ar_invoices.posting_date')->orderByDesc('ar_invoices.created_at');
    }

    /** The full document for the detail page. */
    public function load(ArInvoice $invoice): ArInvoice
    {
        // The derived settlement figures (received, credited, outstanding, payment status) come from the subledger, never from a stored column.
        $figures = $this->subledger->figures(ArInvoice::query()->whereKey($invoice->id))->first();
        if ($figures !== null) {
            $invoice->setRawAttributes($figures->getAttributes(), true);
        }

        return $invoice->load([
            'customer', 'paymentTerm', 'branch', 'businessUnit', 'costCenter', 'creator', 'transitions.actor',
            'lines.account', 'lines.costCenter', 'allocations.receipt', 'creditNotes',
        ]);
    }

    /** What the signed-in user may still do with this invoice under the segregation-of-duties policy. */
    public function sod(ArInvoice $invoice, string $userId): array
    {
        return $this->sod->documentAllowed($invoice, $userId, $this->workflow->profile()) + ['approval_required' => $this->workflow->profile()->approval_required];
    }

    // ------------------------------------------------------------------------------------------------ draft

    public function create(array $data, User $actor): ArInvoice
    {
        return DB::transaction(function () use ($data, $actor) {
            $prepared = $this->prepare($data, null, $actor, $this->workflow->profile());

            $invoice = new ArInvoice($prepared['header']);
            $invoice->forceFill($prepared['columns']);
            $invoice->status = ArInvoice::DRAFT;
            $invoice->created_by = $actor->id;
            $invoice->save();
            $this->writeLines($invoice, $prepared['lines']);
            $this->workflow->created($invoice, $actor->id);
            $this->audit->record('receivables.ar_invoice.created', 'ar_invoice', $invoice->id, null, $this->summary($invoice));

            return $this->load($invoice->refresh());
        });
    }

    public function update(ArInvoice $invoice, array $data, User $actor): ArInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = ArInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertDraft($invoice);
            $before = $this->summary($invoice);

            $prepared = $this->prepare($data, $invoice, $actor, $this->workflow->profile());
            $invoice->fill($prepared['header']);
            $invoice->forceFill($prepared['columns']);
            $invoice->save();
            $this->writeLines($invoice, $prepared['lines']);
            $this->audit->record('receivables.ar_invoice.updated', 'ar_invoice', $invoice->id, $before, $this->summary($invoice->refresh()));

            return $this->load($invoice);
        });
    }

    // ------------------------------------------------------------------------------------------------ workflow

    public function submit(ArInvoice $invoice, User $actor): ArInvoice
    {
        return $this->load($this->workflow->submit($invoice, $actor, function (ArInvoice $invoice) use ($actor) {
            $this->validateForPosting($invoice, $actor);
        }));
    }

    public function approve(ArInvoice $invoice, User $actor): ArInvoice
    {
        return $this->load($this->workflow->approve($invoice, $actor, fn (ArInvoice $invoice) => $this->validateForPosting($invoice, $actor)));
    }

    public function reject(ArInvoice $invoice, User $actor, string $reason): ArInvoice
    {
        return $this->load($this->workflow->reject($invoice, $actor, $reason));
    }

    public function reopen(ArInvoice $invoice, User $actor): ArInvoice
    {
        return $this->load($this->workflow->reopen($invoice, $actor));
    }

    public function cancel(ArInvoice $invoice, User $actor, string $reason): ArInvoice
    {
        return $this->load($this->workflow->cancel($invoice, $actor, $reason));
    }

    // ------------------------------------------------------------------------------------------------ posting

    /**
     * Post an approved invoice: one atomic transaction that locks the invoice, hands the business fact to the Posting Engine
     * (rule -> mapping -> journal, period check under lock), issues the document number and links the journal. A replay or a
     * second concurrent request finds a POSTED invoice and is refused; the engine is idempotent on the invoice as well.
     */
    public function post(ArInvoice $invoice, User $actor): ArInvoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = ArInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->workflow->assertMayPost($invoice, $actor);
            $customer = $this->validateForPosting($invoice, $actor);

            $lines = ArInvoiceLine::query()->where('ar_invoice_id', $invoice->id)->orderBy('line_number')->get();
            $payload = $this->payload($invoice, $lines, $customer, (int) $this->workflow->profile()->currency_scale);
            $dims = ['branch_id' => $invoice->branch_id, 'business_unit_id' => $invoice->business_unit_id, 'cost_center_id' => $invoice->cost_center_id];

            $event = $this->engine->postEvent(
                'AR_INVOICE_RECOGNIZED', ArInvoice::DOCUMENT_TYPE, $invoice->id, $invoice->posting_date->toDateString(), $payload, $dims, 'POST',
                mb_substr("Faktur pelanggan {$customer->code} ".($invoice->customer_reference ?? $invoice->description), 0, 500), $invoice->customer_reference, $actor, $this->authority->canPostSoftClosed($actor),
            );
            $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);
            $controlAccountId = $this->subledger->controlAccount($journal, $invoice->total_amount);

            return $this->load($this->workflow->markPosted($invoice, $actor, $journal, $event, 'AR_INVOICE', 'INV', ['receivable_account_id' => $controlAccountId]));
        });
    }

    /**
     * Reverse a posted invoice through the shared reversal mechanism (a new journal with the sides swapped), then mark the
     * invoice REVERSED. Refused while it has receipts or credit notes: those are reversed first, so the subledger never goes negative.
     */
    public function reverse(ArInvoice $invoice, User $actor, string $reason, ?string $postingDate = null): ArInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason, $postingDate) {
            $invoice = ArInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== ArInvoice::POSTED) {
                throw new DomainException($invoice->status === ArInvoice::REVERSED ? 'This invoice has already been reversed.' : 'Only a posted invoice can be reversed.', $invoice->status === ArInvoice::REVERSED ? 'AR_INVOICE_ALREADY_REVERSED' : 'AR_INVOICE_NOT_POSTED', 409, ['status' => $invoice->status]);
            }
            $this->assertNoActiveSettlements($invoice);
            $this->authority->assertLedgerWritable($actor);

            $original = JournalEntry::query()->findOrFail($invoice->journal_entry_id);
            $reversal = $this->reversals->reverse($original, $actor, $reason, $postingDate, $invoice->document_number);

            return $this->load($this->workflow->markReversed($invoice, $actor, $reversal, $reason));
        });
    }

    /** Refuse a reversal while receipts are allocated or credit notes are posted against the invoice. */
    private function assertNoActiveSettlements(ArInvoice $invoice): void
    {
        if (ArReceiptAllocation::query()->where('ar_invoice_id', $invoice->id)->where('is_effective', true)->exists()) {
            throw new DomainException('Receipts are allocated to this invoice; reverse them first.', 'AR_INVOICE_HAS_RECEIPTS', 409);
        }
        if (ArCreditNote::query()->where('ar_invoice_id', $invoice->id)->where('status', ArCreditNote::POSTED)->exists()) {
            throw new DomainException('Credit notes are posted against this invoice; reverse them first.', 'AR_INVOICE_HAS_CREDIT_NOTES', 409);
        }
    }

    // ------------------------------------------------------------------------------------------------ validation

    /** Everything posting needs, checked at submit, approve and post: customer, totals, lines, period, readiness. Returns the customer. */
    private function validateForPosting(ArInvoice $invoice, User $actor): Customer
    {
        $this->authority->assertLedgerWritable($actor);
        $customer = Customer::query()->find($invoice->customer_id) ?? throw new DomainException('The customer no longer exists.', 'CUSTOMER_NOT_FOUND', 422);
        $this->customers->assertUsable($customer);
        $lines = $invoice->lines()->get();
        if ($lines->isEmpty()) {
            throw new DomainException('An invoice needs at least one line.', 'AR_INVOICE_NO_LINES', 422);
        }
        if (BigDecimal::of($invoice->total_amount)->isLessThanOrEqualTo(0)) {
            throw new DomainException('The invoice total must be greater than zero.', 'AR_INVOICE_TOTAL_INVALID', 422);
        }
        foreach ($lines as $line) {
            if ($line->account_id) {
                $this->accounts->usable($line->account_id, ['REVENUE'], false, 'account_id', $line->line_number);
            }
        }
        $postingDate = $invoice->posting_date->toDateString();
        $this->readiness->assertCanPost($postingDate, JournalEntry::SYSTEM);
        $this->periods->resolveForPosting($postingDate, $this->authority->canPostSoftClosed($actor));

        return $customer;
    }

    /**
     * Validate and normalize client data against the existing invoice (null = new). Totals are always recomputed here.
     *
     * @return array{customer:Customer,header:array<string,mixed>,columns:array<string,mixed>,lines:list<array<string,mixed>>}
     */
    private function prepare(array $data, ?ArInvoice $existing, User $actor, AccountingProfile $profile): array
    {
        $scale = (int) $profile->currency_scale;
        $field = fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);
        $date = fn (mixed $v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;

        $customerId = $data['customer_id'] ?? $existing?->customer_id ?? throw new DomainException('The customer is required.', 'CUSTOMER_REQUIRED', 422);
        $customer = Customer::query()->find($customerId) ?? throw new DomainException('The customer does not exist.', 'CUSTOMER_NOT_FOUND', 422, ['field' => 'customer_id']);
        if ($existing === null || $existing->customer_id !== $customer->id) {
            $this->customers->assertUsable($customer);
        }
        if (isset($data['currency']) && $data['currency'] !== $profile->functional_currency) {
            throw new DomainException("OA3 books in the functional currency ({$profile->functional_currency}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'currency']);
        }

        $documentDate = $date($field('document_date')) ?? throw new DomainException('The document date is required.', 'DOCUMENT_DATE_REQUIRED', 422);
        $postingDate = $date(array_key_exists('posting_date', $data) ? $data['posting_date'] : ($existing?->posting_date ?? $documentDate));

        $termId = array_key_exists('payment_term_id', $data) ? $data['payment_term_id'] : ($existing ? $existing->payment_term_id : $customer->payment_term_id);
        $term = $termId ? (PaymentTerm::query()->find($termId) ?? throw new DomainException('The payment term does not exist.', 'PAYMENT_TERM_NOT_FOUND', 422, ['field' => 'payment_term_id'])) : null;
        $explicitDue = array_key_exists('due_date', $data) ? $data['due_date'] : ($existing?->due_date_overridden ? $date($existing->due_date) : null);
        [$dueDate, $overridden] = $this->terms->dueDate($term, $documentDate, $explicitDue);

        $dims = $this->dimensions->resolve($field('branch_id'), $field('business_unit_id'), $field('cost_center_id'));
        $this->scope->assertWritable($dims['branch_id'], $dims['business_unit_id'], $existing?->created_by ?? $actor->id);

        $lines = $this->normalizeLines($data['lines'] ?? $this->existingLines($existing), $scale, $dims['branch_id']);
        $subtotal = Money::sum(array_column($lines, 'amount'));
        $discount = Money::parse($field('discount_amount'), $scale, 'discount_amount');
        $tax = Money::parse($field('tax_amount'), $scale, 'tax_amount');
        $other = Money::parse($field('other_charges_amount'), $scale, 'other_charges_amount');
        if ($discount->isGreaterThan($subtotal)) {
            throw new DomainException('The discount cannot exceed the subtotal.', 'AR_INVOICE_DISCOUNT_INVALID', 422);
        }
        $total = $subtotal->minus($discount)->plus($tax)->plus($other);

        return [
            'customer' => $customer,
            'header' => collect($data)->only(['customer_reference', 'document_date', 'posting_date', 'description', 'reference'])->merge(['due_date' => $dueDate])->all(),
            'columns' => [
                'customer_id' => $customer->id, 'payment_term_id' => $term?->id, 'due_date' => $dueDate, 'due_date_overridden' => $overridden, 'currency' => $profile->functional_currency,
                'posting_date' => $postingDate, 'document_date' => $documentDate,
                'branch_id' => $dims['branch_id'], 'business_unit_id' => $dims['business_unit_id'], 'cost_center_id' => $dims['cost_center_id'],
                'subtotal_amount' => Money::str($subtotal), 'discount_amount' => Money::str($discount), 'tax_amount' => Money::str($tax),
                'other_charges_amount' => Money::str($other), 'total_amount' => Money::str($total),
            ] + ($existing === null ? ['description' => $data['description'] ?? throw new DomainException('The description is required.', 'DESCRIPTION_REQUIRED', 422)] : []),
            'lines' => $lines,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function existingLines(?ArInvoice $invoice): array
    {
        return $invoice === null ? [] : $invoice->lines()->get()->map(fn (ArInvoiceLine $l) => [
            'description' => $l->description, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'amount' => $l->amount,
            'account_role' => $l->account_role, 'account_id' => $l->account_id, 'cost_center_id' => $l->cost_center_id, 'metadata' => $l->metadata,
        ])->all();
    }

    /**
     * @param  list<array<string,mixed>>  $lines
     * @return list<array<string,mixed>>
     */
    private function normalizeLines(array $lines, int $scale, ?string $branchId): array
    {
        if ($lines === []) {
            throw new DomainException('An invoice needs at least one line.', 'AR_INVOICE_NO_LINES', 422);
        }
        if (count($lines) > 300) {
            throw new DomainException('An invoice can have at most 300 lines.', 'AR_INVOICE_TOO_MANY_LINES', 422);
        }

        $out = [];
        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                throw new DomainException('Every line needs a description.', 'LINE_DESCRIPTION_REQUIRED', 422, ['line' => $n]);
            }

            $quantity = $unitPrice = null;
            if (($line['quantity'] ?? null) !== null || ($line['unit_price'] ?? null) !== null) {
                $quantity = Money::parse($line['quantity'] ?? null, 4, 'quantity', $n);
                $unitPrice = Money::parse($line['unit_price'] ?? null, 4, 'unit_price', $n);
                if ($quantity->isZero() || ($line['unit_price'] ?? null) === null) {
                    throw new DomainException('Quantity and unit price go together and quantity must be greater than zero.', 'LINE_QUANTITY_INVALID', 422, ['line' => $n]);
                }
                $amount = $quantity->multipliedBy($unitPrice)->toScale($scale, RoundingMode::HalfUp)->toScale(4);
            } else {
                $amount = Money::parse($line['amount'] ?? null, $scale, 'amount', $n);
            }
            if ($amount->isLessThanOrEqualTo(0)) {
                throw new DomainException('A line amount must be greater than zero.', 'LINE_AMOUNT_INVALID', 422, ['line' => $n]);
            }

            $role = $line['account_role'] ?? null;
            if ($role !== null) {
                $this->accounts->destinationRole($role, 'account_role', $n);
            }
            $accountId = $line['account_id'] ?? null;
            if ($accountId !== null) {
                $accountId = $this->accounts->usable($accountId, ['REVENUE'], false, 'account_id', $n)->id;
            }
            $center = $this->dimensions->resolve($branchId, null, $line['cost_center_id'] ?? null, $n)['cost_center_id'];

            $out[] = [
                'description' => mb_substr($description, 0, 255), 'quantity' => $quantity ? Money::str($quantity) : null, 'unit_price' => $unitPrice ? Money::str($unitPrice) : null,
                'amount' => $amount, 'account_role' => $role, 'account_id' => $accountId, 'cost_center_id' => $center,
                'metadata' => isset($line['metadata']) && is_array($line['metadata']) ? array_slice($line['metadata'], 0, 20, true) : null,
            ];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $lines */
    private function writeLines(ArInvoice $invoice, array $lines): void
    {
        ArInvoiceLine::query()->where('ar_invoice_id', $invoice->id)->delete();
        foreach ($lines as $i => $data) {
            $line = new ArInvoiceLine(['description' => $data['description'], 'quantity' => $data['quantity'], 'unit_price' => $data['unit_price'], 'amount' => Money::str($data['amount']), 'metadata' => $data['metadata']]);
            $line->forceFill([
                'ar_invoice_id' => $invoice->id, 'line_number' => $i + 1, 'account_role' => $data['account_role'],
                'account_id' => $data['account_id'], 'cost_center_id' => $data['cost_center_id'],
            ])->save();
        }
    }

    // ------------------------------------------------------------------------------------------------ duplicates

    /**
     * Soft duplicate warning: another live invoice of the same customer with the same customer reference, or with the same total on the
     * same document date. A customer reference is the customer's own number (a purchase order); it is not unique, so this warns and never refuses.
     *
     * @return list<array<string,mixed>>
     */
    public function possibleDuplicates(ArInvoice $invoice): array
    {
        $reference = $invoice->customer_reference === null ? null : mb_strtoupper(preg_replace('/\s+/', ' ', trim($invoice->customer_reference)));

        return ArInvoice::query()->where('customer_id', $invoice->customer_id)->where('id', '!=', $invoice->id)
            ->whereIn('status', [ArInvoice::DRAFT, ArInvoice::SUBMITTED, ArInvoice::APPROVED, ArInvoice::POSTED])
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('total_amount', $invoice->total_amount)->whereDate('document_date', $invoice->document_date))
                ->when($reference, fn ($w, $ref) => $w->orWhereRaw('upper(trim(customer_reference)) = ?', [$ref])))
            ->tap(fn ($q) => $this->scope->restrict($q->getQuery(), 'ar_invoices'))
            ->limit(5)->get(['id', 'document_number', 'customer_reference', 'status'])->toArray();
    }

    // ------------------------------------------------------------------------------------------------ posting payload

    /**
     * The business fact the engine posts: components (net, tax, total) plus the document's own account choices. Header discount and
     * other charges are spread over the lines in proportion to their amounts, so every part is real revenue of the line it sits on.
     * A line's revenue account is, in order: its own account, its own role, the customer's default revenue account, the REVENUE mapping.
     *
     * @param  iterable<ArInvoiceLine>  $lines
     * @return array<string,mixed>
     */
    private function payload(ArInvoice $invoice, iterable $lines, Customer $customer, int $scale): array
    {
        $lines = collect($lines)->values();
        $adjustment = BigDecimal::of($invoice->other_charges_amount)->minus($invoice->discount_amount);
        $shares = Money::prorate($adjustment, $lines->map(fn ($l) => BigDecimal::of($l->amount))->all(), $scale);

        $parts = [];
        foreach ($lines as $i => $line) {
            $accountId = $line->account_id ?? ($line->account_role === null ? $customer->default_revenue_account_id : null);
            $parts[] = array_filter([
                'amount' => Money::str(BigDecimal::of($line->amount)->plus($shares[$i])),
                'account_id' => $accountId,
                'account_role' => $accountId ? null : $line->account_role,
                'description' => $line->description, 'cost_center_id' => $line->cost_center_id,
            ], fn ($v) => $v !== null);
        }

        $net = BigDecimal::of($invoice->subtotal_amount)->minus($invoice->discount_amount)->plus($invoice->other_charges_amount);
        $payload = [
            'net' => Money::str($net), 'tax' => Money::str($invoice->tax_amount), 'total' => Money::str($invoice->total_amount),
            'distribution' => ['net' => $parts],
        ];
        if ($customer->receivable_account_id) {
            $payload['role_accounts'] = ['ACCOUNTS_RECEIVABLE' => $customer->receivable_account_id];
        }

        return $payload;
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function assertDraft(ArInvoice $invoice): void
    {
        if ($invoice->status !== ArInvoice::DRAFT) {
            throw new DomainException("A {$invoice->status} invoice cannot be edited.", in_array($invoice->status, [ArInvoice::POSTED, ArInvoice::REVERSED], true) ? 'DOCUMENT_IMMUTABLE' : 'DOCUMENT_NOT_DRAFT', 409, ['status' => $invoice->status]);
        }
    }

    private function summary(ArInvoice $invoice): array
    {
        return $invoice->only(['document_number', 'customer_id', 'customer_reference', 'status', 'document_date', 'posting_date', 'due_date', 'total_amount']);
    }
}
