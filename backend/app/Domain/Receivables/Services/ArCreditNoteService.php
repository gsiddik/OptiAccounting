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
use App\Domain\Receivables\Models\ArCreditNote;
use App\Domain\Receivables\Models\ArCreditNoteLine;
use App\Domain\Receivables\Models\ArInvoice;
use App\Domain\Receivables\Models\Customer;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes: the controlled way to reduce a posted customer invoice. The invoice itself is never touched; the note is its own
 * posted document (event AR_CREDIT_NOTE_RECOGNIZED: debit the revenue adjustment, credit the receivable account the invoice was booked
 * to) and the invoice's outstanding is derived from it (see ArSubledgerService). A note can never exceed what is outstanding on its
 * invoice, nor the net and tax the invoice recognised, so the receivable cannot go negative; the same limits are enforced under the
 * invoice row lock when posting and again by the database. Reversing a note puts the amount back.
 */
class ArCreditNoteService
{
    public function __construct(
        private readonly DocumentWorkflow $workflow,
        private readonly CustomerService $customers,
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

    public function query(array $filter = []): Builder
    {
        $query = ArCreditNote::query()->with(['customer', 'invoice', 'branch', 'creator']);
        $this->scope->restrict($query->getQuery(), 'ar_credit_notes');

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('ar_credit_notes.status', $v))
            ->when($filter['customer_id'] ?? null, fn ($q, $v) => $q->where('ar_credit_notes.customer_id', $v))
            ->when($filter['ar_invoice_id'] ?? null, fn ($q, $v) => $q->where('ar_credit_notes.ar_invoice_id', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('ar_credit_notes.branch_id', $v))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $v) => $q->where('ar_credit_notes.business_unit_id', $v))
            ->when($filter['cost_center_id'] ?? null, fn ($q, $v) => $q->where('ar_credit_notes.cost_center_id', $v))
            ->when($filter['document_from'] ?? null, fn ($q, $v) => $q->whereDate('ar_credit_notes.document_date', '>=', $v))
            ->when($filter['document_to'] ?? null, fn ($q, $v) => $q->whereDate('ar_credit_notes.document_date', '<=', $v))
            ->when($filter['posting_from'] ?? null, fn ($q, $v) => $q->whereDate('ar_credit_notes.posting_date', '>=', $v))
            ->when($filter['posting_to'] ?? null, fn ($q, $v) => $q->whereDate('ar_credit_notes.posting_date', '<=', $v))
            ->when($filter['mine'] ?? false, fn ($q) => $q->where('ar_credit_notes.created_by', $this->context->user()?->id))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(coalesce(ar_credit_notes.document_number, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(ar_credit_notes.reference, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(ar_credit_notes.reason) like ?', [$like]));
            })
            ->orderByDesc('ar_credit_notes.posting_date')->orderByDesc('ar_credit_notes.created_at');
    }

    public function load(ArCreditNote $note): ArCreditNote
    {
        $note->load(['customer', 'invoice', 'branch', 'businessUnit', 'costCenter', 'creator', 'transitions.actor', 'lines.account', 'lines.costCenter']);
        $note->setAttribute('invoice_outstanding', Money::str($this->outstandingOf($note->ar_invoice_id)));

        return $note;
    }

    public function sod(ArCreditNote $note, string $userId): array
    {
        return $this->sod->documentAllowed($note, $userId, $this->workflow->profile()) + ['approval_required' => $this->workflow->profile()->approval_required];
    }

    // ------------------------------------------------------------------------------------------------ draft

    public function create(array $data, User $actor): ArCreditNote
    {
        return DB::transaction(function () use ($data, $actor) {
            $prepared = $this->prepare($data, null, $actor, $this->workflow->profile());

            $note = new ArCreditNote($prepared['header']);
            $note->forceFill($prepared['columns']);
            $note->status = ArCreditNote::DRAFT;
            $note->created_by = $actor->id;
            $note->save();
            $this->writeLines($note, $prepared['lines']);
            $this->workflow->created($note, $actor->id);
            $this->audit->record('receivables.ar_credit_note.created', 'ar_credit_note', $note->id, null, $this->summary($note));

            return $this->load($note->refresh());
        });
    }

    public function update(ArCreditNote $note, array $data, User $actor): ArCreditNote
    {
        return DB::transaction(function () use ($note, $data, $actor) {
            $note = ArCreditNote::query()->lockForUpdate()->findOrFail($note->id);
            $this->assertDraft($note);
            $before = $this->summary($note);

            $prepared = $this->prepare($data, $note, $actor, $this->workflow->profile());
            $note->fill($prepared['header']);
            $note->forceFill($prepared['columns']);
            $note->save();
            $this->writeLines($note, $prepared['lines']);
            $this->audit->record('receivables.ar_credit_note.updated', 'ar_credit_note', $note->id, $before, $this->summary($note->refresh()));

            return $this->load($note);
        });
    }

    // ------------------------------------------------------------------------------------------------ workflow

    public function submit(ArCreditNote $note, User $actor): ArCreditNote
    {
        return $this->load($this->workflow->submit($note, $actor, fn (ArCreditNote $n) => $this->validateForPosting($n, $actor, false)));
    }

    public function approve(ArCreditNote $note, User $actor): ArCreditNote
    {
        return $this->load($this->workflow->approve($note, $actor, fn (ArCreditNote $n) => $this->validateForPosting($n, $actor, false)));
    }

    public function reject(ArCreditNote $note, User $actor, string $reason): ArCreditNote
    {
        return $this->load($this->workflow->reject($note, $actor, $reason));
    }

    public function reopen(ArCreditNote $note, User $actor): ArCreditNote
    {
        return $this->load($this->workflow->reopen($note, $actor));
    }

    public function cancel(ArCreditNote $note, User $actor, string $reason): ArCreditNote
    {
        return $this->load($this->workflow->cancel($note, $actor, $reason));
    }

    // ------------------------------------------------------------------------------------------------ posting

    /**
     * Post an approved note: lock the note, then its invoice (the same lock a receipt takes), re-check what is outstanding under the
     * lock, hand the business fact to the Posting Engine and link the journal. A note and a receipt competing for the last of an invoice
     * serialize on that lock; the loser is refused, never silently over-applied.
     */
    public function post(ArCreditNote $note, User $actor): ArCreditNote
    {
        return DB::transaction(function () use ($note, $actor) {
            $note = ArCreditNote::query()->lockForUpdate()->findOrFail($note->id);
            $this->workflow->assertMayPost($note, $actor);
            $invoice = $this->validateForPosting($note, $actor, true);

            $lines = ArCreditNoteLine::query()->where('ar_credit_note_id', $note->id)->orderBy('line_number')->get();
            $payload = $this->payload($note, $lines, $invoice, (int) $this->workflow->profile()->currency_scale);
            $dims = ['branch_id' => $note->branch_id, 'business_unit_id' => $note->business_unit_id, 'cost_center_id' => $note->cost_center_id];

            $event = $this->engine->postEvent(
                'AR_CREDIT_NOTE_RECOGNIZED', ArCreditNote::DOCUMENT_TYPE, $note->id, $note->posting_date->toDateString(), $payload, $dims, 'POST',
                mb_substr("Nota kredit {$invoice->document_number}: {$note->reason}", 0, 500), $note->reference, $actor, $this->authority->canPostSoftClosed($actor),
            );
            $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);
            $this->assertJournal($journal, $note->total_amount, $invoice->receivable_account_id);

            return $this->load($this->workflow->markPosted($note, $actor, $journal, $event, 'AR_CREDIT_NOTE', 'CN', ['receivable_account_id' => $invoice->receivable_account_id]));
        });
    }

    /** Reverse a posted note through the shared mechanism; the invoice's outstanding returns by derivation (the note no longer counts). */
    public function reverse(ArCreditNote $note, User $actor, string $reason, ?string $postingDate = null): ArCreditNote
    {
        return DB::transaction(function () use ($note, $actor, $reason, $postingDate) {
            $note = ArCreditNote::query()->lockForUpdate()->findOrFail($note->id);
            if ($note->status !== ArCreditNote::POSTED) {
                throw new DomainException($note->status === ArCreditNote::REVERSED ? 'This credit note has already been reversed.' : 'Only a posted credit note can be reversed.', $note->status === ArCreditNote::REVERSED ? 'AR_CREDIT_NOTE_ALREADY_REVERSED' : 'AR_CREDIT_NOTE_NOT_POSTED', 409, ['status' => $note->status]);
            }
            $this->authority->assertLedgerWritable($actor);
            ArInvoice::query()->whereKey($note->ar_invoice_id)->lockForUpdate()->first();

            $original = JournalEntry::query()->findOrFail($note->journal_entry_id);
            $reversal = $this->reversals->reverse($original, $actor, $reason, $postingDate, $note->document_number);

            return $this->load($this->workflow->markReversed($note, $actor, $reversal, $reason));
        });
    }

    // ------------------------------------------------------------------------------------------------ validation

    /**
     * Everything posting needs: customer, invoice (posted, same customer, not later than the note), the amount limits, period, readiness.
     * With $lock the invoice row is locked first, so the limits are checked against committed receipts and notes. Returns the invoice.
     */
    private function validateForPosting(ArCreditNote $note, User $actor, bool $lock): ArInvoice
    {
        $this->authority->assertLedgerWritable($actor);
        $customer = Customer::query()->find($note->customer_id) ?? throw new DomainException('The customer no longer exists.', 'CUSTOMER_NOT_FOUND', 422);
        $this->customers->assertUsable($customer);
        $invoice = ArInvoice::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($note->ar_invoice_id)
            ?? throw new DomainException('The invoice no longer exists.', 'AR_INVOICE_NOT_FOUND', 422);
        $this->assertInvoiceUsable($invoice, $note->customer_id, $note->posting_date->toDateString());

        if (! $note->lines()->exists()) {
            throw new DomainException('A credit note needs at least one line.', 'AR_CREDIT_NOTE_NO_LINES', 422);
        }
        if (BigDecimal::of($note->total_amount)->isLessThanOrEqualTo(0)) {
            throw new DomainException('The credit note total must be greater than zero.', 'AR_CREDIT_NOTE_TOTAL_INVALID', 422);
        }
        foreach ($note->lines()->get() as $line) {
            if ($line->account_id) {
                $this->accounts->usable($line->account_id, ['REVENUE'], false, 'account_id', $line->line_number);
            }
        }
        $this->assertWithinInvoice($invoice, $note->subtotal_amount, $note->tax_amount, $note->total_amount, $note->id);

        $postingDate = $note->posting_date->toDateString();
        $this->readiness->assertCanPost($postingDate, JournalEntry::SYSTEM);
        $this->periods->resolveForPosting($postingDate, $this->authority->canPostSoftClosed($actor));

        return $invoice;
    }

    private function assertInvoiceUsable(ArInvoice $invoice, string $customerId, string $postingDate): void
    {
        if ($invoice->status !== ArInvoice::POSTED) {
            throw new DomainException('A credit note reduces a posted invoice only.', 'AR_INVOICE_NOT_CREDITABLE', 409, ['status' => $invoice->status]);
        }
        if ($invoice->customer_id !== $customerId) {
            throw new DomainException('A credit note belongs to the customer of its invoice.', 'AR_CREDIT_NOTE_CUSTOMER_MISMATCH', 422);
        }
        if ($invoice->posting_date->toDateString() > $postingDate) {
            throw new DomainException('A credit note cannot be posted before the invoice it reduces.', 'AR_CREDIT_NOTE_BEFORE_INVOICE', 422, ['document_number' => $invoice->document_number]);
        }
    }

    /**
     * A note may not exceed what is outstanding on the invoice, nor take off more net or tax than the invoice recognised together with
     * the other posted notes. $exceptId leaves the note being checked (or posted) out of the sums.
     */
    private function assertWithinInvoice(ArInvoice $invoice, string $subtotal, string $tax, string $total, ?string $exceptId): void
    {
        $outstanding = $this->outstandingOf($invoice->id, $exceptId);
        if (BigDecimal::of($total)->isGreaterThan($outstanding)) {
            throw new DomainException('The credit note exceeds what is outstanding on the invoice.', 'AR_CREDIT_NOTE_EXCEEDS_OUTSTANDING', 409, [
                'document_number' => $invoice->document_number, 'outstanding' => Money::str($outstanding), 'credit_note' => Money::str($total),
            ]);
        }

        $others = ArCreditNote::query()->where('ar_invoice_id', $invoice->id)->where('status', ArCreditNote::POSTED)->when($exceptId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->selectRaw('coalesce(sum(subtotal_amount), 0) as net, coalesce(sum(tax_amount), 0) as tax')->first();
        $invoiceNet = BigDecimal::of($invoice->subtotal_amount)->minus($invoice->discount_amount)->plus($invoice->other_charges_amount);
        if (BigDecimal::of($others->net)->plus($subtotal)->isGreaterThan($invoiceNet) || BigDecimal::of($others->tax)->plus($tax)->isGreaterThan($invoice->tax_amount)) {
            throw new DomainException('Credit notes cannot take off more revenue or tax than the invoice recognised.', 'AR_CREDIT_NOTE_EXCEEDS_INVOICE', 409, ['document_number' => $invoice->document_number]);
        }
    }

    /** What is outstanding on an invoice right now (total - effective receipt allocations - posted credit notes); $exceptNote leaves one posted note out. */
    private function outstandingOf(string $invoiceId, ?string $exceptNote = null): BigDecimal
    {
        $invoice = ArInvoice::query()->find($invoiceId);
        if ($invoice === null || $invoice->status !== ArInvoice::POSTED) {
            return BigDecimal::zero();
        }
        $received = DB::table('ar_receipt_allocations')->where('tenant_id', $invoice->tenant_id)->where('ar_invoice_id', $invoiceId)->where('is_effective', true)->sum('amount');
        $credited = DB::table('ar_credit_notes')->where('tenant_id', $invoice->tenant_id)->where('ar_invoice_id', $invoiceId)->where('status', 'POSTED')
            ->when($exceptNote, fn ($q, $id) => $q->where('id', '!=', $id))->sum('total_amount');

        return BigDecimal::of($invoice->total_amount)->minus((string) $received)->minus((string) $credited);
    }

    // ------------------------------------------------------------------------------------------------ preparation

    /**
     * @return array{header:array<string,mixed>,columns:array<string,mixed>,lines:list<array<string,mixed>>}
     */
    private function prepare(array $data, ?ArCreditNote $existing, User $actor, AccountingProfile $profile): array
    {
        $scale = (int) $profile->currency_scale;
        $field = fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);
        $date = fn (mixed $v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;

        $invoiceId = $data['ar_invoice_id'] ?? $existing?->ar_invoice_id ?? throw new DomainException('The invoice is required.', 'AR_INVOICE_REQUIRED', 422, ['field' => 'ar_invoice_id']);
        $invoice = ArInvoice::query()->find($invoiceId);
        if ($invoice === null || ! $this->scope->visible($invoice)) {
            throw new DomainException('The invoice does not exist.', 'AR_INVOICE_NOT_FOUND', 422, ['field' => 'ar_invoice_id']);
        }
        if ($existing !== null && $existing->ar_invoice_id !== $invoice->id) {
            throw new DomainException('The invoice of a credit note cannot be changed; create a new note.', 'AR_CREDIT_NOTE_INVOICE_LOCKED', 422, ['field' => 'ar_invoice_id']);
        }
        if ($invoice->exchange_rate_id !== null) {
            // a credit note would have to reverse the invoice at its own rate and recompute the exchange difference of earlier receipts: not part of OA4
            throw new DomainException('A credit note cannot be raised against a foreign-currency invoice; reverse the invoice or settle it with receipts.', 'AR_CREDIT_NOTE_FOREIGN_INVOICE', 422, ['field' => 'ar_invoice_id', 'currency' => $invoice->currency]);
        }
        $customer = Customer::query()->findOrFail($invoice->customer_id);
        if ($existing === null) {
            $this->customers->assertUsable($customer);
        }
        if (isset($data['currency']) && $data['currency'] !== $profile->functional_currency) {
            throw new DomainException("OA3 books in the functional currency ({$profile->functional_currency}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'currency']);
        }

        $documentDate = $date($field('document_date')) ?? throw new DomainException('The document date is required.', 'DOCUMENT_DATE_REQUIRED', 422);
        $postingDate = $date(array_key_exists('posting_date', $data) && $data['posting_date'] !== null ? $data['posting_date'] : ($existing?->posting_date ?? $documentDate));
        $this->assertInvoiceUsable($invoice, $customer->id, $postingDate);
        $reason = trim((string) $field('reason'));
        if ($reason === '') {
            throw new DomainException('The reason is required.', 'REASON_REQUIRED', 422, ['field' => 'reason']);
        }

        $dims = $this->dimensions->resolve($field('branch_id', $invoice->branch_id), $field('business_unit_id', $invoice->business_unit_id), $field('cost_center_id', $invoice->cost_center_id));
        $this->scope->assertWritable($dims['branch_id'], $dims['business_unit_id'], $existing?->created_by ?? $actor->id);

        $lines = $this->normalizeLines($data['lines'] ?? $this->existingLines($existing), $scale, $dims['branch_id']);
        $subtotal = Money::sum(array_column($lines, 'amount'));
        $tax = Money::parse($field('tax_amount'), $scale, 'tax_amount');
        $total = $subtotal->plus($tax);
        $this->assertWithinInvoice($invoice, Money::str($subtotal), Money::str($tax), Money::str($total), $existing?->id);

        return [
            'header' => collect($data)->only(['document_date', 'posting_date', 'reference'])->merge(['reason' => mb_substr($reason, 0, 500)])->all(),
            'columns' => [
                'customer_id' => $customer->id, 'ar_invoice_id' => $invoice->id, 'currency' => $profile->functional_currency,
                'document_date' => $documentDate, 'posting_date' => $postingDate,
                'branch_id' => $dims['branch_id'], 'business_unit_id' => $dims['business_unit_id'], 'cost_center_id' => $dims['cost_center_id'],
                'subtotal_amount' => Money::str($subtotal), 'tax_amount' => Money::str($tax), 'total_amount' => Money::str($total),
            ],
            'lines' => $lines,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function existingLines(?ArCreditNote $note): array
    {
        return $note === null ? [] : $note->lines()->get()->map(fn (ArCreditNoteLine $l) => [
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
            throw new DomainException('A credit note needs at least one line.', 'AR_CREDIT_NOTE_NO_LINES', 422);
        }
        if (count($lines) > 300) {
            throw new DomainException('A credit note can have at most 300 lines.', 'AR_CREDIT_NOTE_TOO_MANY_LINES', 422);
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
    private function writeLines(ArCreditNote $note, array $lines): void
    {
        ArCreditNoteLine::query()->where('ar_credit_note_id', $note->id)->delete();
        foreach ($lines as $i => $data) {
            $line = new ArCreditNoteLine(['description' => $data['description'], 'quantity' => $data['quantity'], 'unit_price' => $data['unit_price'], 'amount' => Money::str($data['amount']), 'metadata' => $data['metadata']]);
            $line->forceFill([
                'ar_credit_note_id' => $note->id, 'line_number' => $i + 1, 'account_role' => $data['account_role'],
                'account_id' => $data['account_id'], 'cost_center_id' => $data['cost_center_id'],
            ])->save();
        }
    }

    // ------------------------------------------------------------------------------------------------ posting payload

    /**
     * The business fact the engine posts: net, tax and total, the revenue (adjustment) accounts the lines name, and the receivable account
     * of the invoice, so the note relieves exactly the control account its invoice was booked to.
     *
     * @param  iterable<ArCreditNoteLine>  $lines
     * @return array<string,mixed>
     */
    private function payload(ArCreditNote $note, iterable $lines, ArInvoice $invoice, int $scale): array
    {
        $parts = [];
        foreach ($lines as $line) {
            $parts[] = array_filter([
                'amount' => Money::str($line->amount), 'account_id' => $line->account_id, 'account_role' => $line->account_id ? null : $line->account_role,
                'description' => $line->description, 'cost_center_id' => $line->cost_center_id,
            ], fn ($v) => $v !== null);
        }

        return [
            'net' => Money::str($note->subtotal_amount), 'tax' => Money::str($note->tax_amount), 'total' => Money::str($note->total_amount),
            'role_accounts' => ['ACCOUNTS_RECEIVABLE' => $invoice->receivable_account_id],
            'distribution' => ['net' => $parts],
        ];
    }

    /** The journal must credit the invoice's receivable account with exactly the note total. */
    private function assertJournal(JournalEntry $journal, string $total, string $receivableAccountId): void
    {
        $credits = collect($journal->posting_snapshot['lines'] ?? [])->where('account_role', 'ACCOUNTS_RECEIVABLE')->where('side', 'CREDIT');
        if ($credits->pluck('account_id')->unique()->all() !== [$receivableAccountId] || ! Money::sum($credits->pluck('amount')->all())->isEqualTo($total)) {
            throw new DomainException('The AR credit note posting rule must credit the accounts receivable role with the credit note total.', 'AR_POSTING_RULE_INVALID', 422);
        }
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function assertDraft(ArCreditNote $note): void
    {
        if ($note->status !== ArCreditNote::DRAFT) {
            throw new DomainException("A {$note->status} credit note cannot be edited.", in_array($note->status, [ArCreditNote::POSTED, ArCreditNote::REVERSED], true) ? 'DOCUMENT_IMMUTABLE' : 'DOCUMENT_NOT_DRAFT', 409, ['status' => $note->status]);
        }
    }

    private function summary(ArCreditNote $note): array
    {
        return $note->only(['document_number', 'customer_id', 'ar_invoice_id', 'status', 'document_date', 'posting_date', 'total_amount']);
    }
}
