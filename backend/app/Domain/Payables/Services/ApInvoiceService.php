<?php

namespace App\Domain\Payables\Services;

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
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Payables\Models\ApInvoice;
use App\Domain\Payables\Models\ApInvoiceLine;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Vendor invoices: draft preparation, validation and posting. Posting is a business fact handed to `PostingEngine::postEvent`
 * (event AP_INVOICE_RECOGNIZED): the rule and the tenant's mappings decide the accounts, never this class. A vendor can override
 * the payable account and every line can be classified to an account, category or role; those are data the document carries.
 * The invoice, its lines and its journal stay linked; after posting nothing financial changes (service and database triggers).
 */
class ApInvoiceService
{
    /** Roles a line cannot be classified to: they belong to a subledger or to cash/bank, which have their own documents. */
    private const FORBIDDEN_DESTINATION_ROLES = ['ACCOUNTS_PAYABLE', 'ACCOUNTS_RECEIVABLE', 'CASH', 'BANK', 'RETAINED_EARNINGS'];

    public function __construct(
        private readonly DocumentWorkflow $workflow,
        private readonly VendorService $vendors,
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
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------------------------------------------ queries

    /** Invoices the user's data scope reaches, filtered; settlement filters are added by the subledger. */
    public function query(array $filter = []): Builder
    {
        $query = ApInvoice::query()->with(['vendor', 'branch', 'businessUnit', 'creator']);
        $this->scope->restrict($query->getQuery(), 'ap_invoices');

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('ap_invoices.status', $v))
            ->when($filter['origin'] ?? null, fn ($q, $v) => $q->where('ap_invoices.origin', $v))
            ->when($filter['vendor_id'] ?? null, fn ($q, $v) => $q->where('ap_invoices.vendor_id', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('ap_invoices.branch_id', $v))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $v) => $q->where('ap_invoices.business_unit_id', $v))
            ->when($filter['cost_center_id'] ?? null, fn ($q, $v) => $q->where('ap_invoices.cost_center_id', $v))
            ->when($filter['document_from'] ?? null, fn ($q, $v) => $q->whereDate('ap_invoices.document_date', '>=', $v))
            ->when($filter['document_to'] ?? null, fn ($q, $v) => $q->whereDate('ap_invoices.document_date', '<=', $v))
            ->when($filter['posting_from'] ?? null, fn ($q, $v) => $q->whereDate('ap_invoices.posting_date', '>=', $v))
            ->when($filter['posting_to'] ?? null, fn ($q, $v) => $q->whereDate('ap_invoices.posting_date', '<=', $v))
            ->when($filter['due_from'] ?? null, fn ($q, $v) => $q->whereDate('ap_invoices.due_date', '>=', $v))
            ->when($filter['due_to'] ?? null, fn ($q, $v) => $q->whereDate('ap_invoices.due_date', '<=', $v))
            ->when($filter['mine'] ?? false, fn ($q) => $q->where('ap_invoices.created_by', $this->context->user()?->id))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(ap_invoices.document_number) like ?', [$like])->orWhereRaw('lower(ap_invoices.vendor_invoice_number) like ?', [$like])
                    ->orWhereRaw('lower(ap_invoices.description) like ?', [$like])->orWhereRaw('lower(coalesce(ap_invoices.reference, \'\')) like ?', [$like]));
            })
            ->orderByDesc('ap_invoices.posting_date')->orderByDesc('ap_invoices.created_at');
    }

    /** The full document for the detail page. */
    public function load(ApInvoice $invoice): ApInvoice
    {
        return $invoice->load([
            'vendor', 'paymentTerm', 'branch', 'businessUnit', 'costCenter', 'creator', 'transitions.actor',
            'lines.expenseCategory', 'lines.account', 'lines.costCenter',
        ]);
    }

    /** What the signed-in user may still do with this invoice under the segregation-of-duties policy. */
    public function sod(ApInvoice $invoice, string $userId): array
    {
        return $this->sod->documentAllowed($invoice, $userId, $this->workflow->profile()) + ['approval_required' => $this->workflow->profile()->approval_required];
    }

    // ------------------------------------------------------------------------------------------------ draft

    public function create(array $data, User $actor): ApInvoice
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data, $actor) {
            $profile = $this->workflow->profile();
            $prepared = $this->prepare($data, null, $actor, $profile);
            $this->assertNotDuplicate($prepared['vendor']->id, $data['vendor_invoice_number'], null, $data, $actor);

            $invoice = new ApInvoice($prepared['header']);
            $invoice->origin = ApInvoice::ORIGIN_INVOICE;
            $invoice->forceFill($prepared['columns'] + $this->overrideColumns($data, $actor, $prepared['vendor']->id, null));
            $invoice->status = ApInvoice::DRAFT;
            $invoice->created_by = $actor->id;
            $invoice->save();
            $this->writeLines($invoice, $prepared['lines']);
            $this->workflow->created($invoice, $actor->id);
            $this->audit->record('payables.ap_invoice.created', 'ap_invoice', $invoice->id, null, $this->summary($invoice));

            return $this->load($invoice->refresh());
        }));
    }

    public function update(ApInvoice $invoice, array $data, User $actor): ApInvoice
    {
        return $this->guarded(fn () => DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = ApInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertDraft($invoice);
            $profile = $this->workflow->profile();
            $before = $this->summary($invoice);

            $prepared = $this->prepare($data, $invoice, $actor, $profile);
            if (($data['vendor_invoice_number'] ?? $invoice->vendor_invoice_number) !== $invoice->vendor_invoice_number || $prepared['vendor']->id !== $invoice->vendor_id) {
                $this->assertNotDuplicate($prepared['vendor']->id, $data['vendor_invoice_number'] ?? $invoice->vendor_invoice_number, $invoice->id, $data, $actor);
            }

            $invoice->fill($prepared['header']);
            $invoice->forceFill($prepared['columns'] + $this->overrideColumns($data, $actor, $prepared['vendor']->id, $invoice));
            $invoice->save();
            $this->writeLines($invoice, $prepared['lines']);
            $this->audit->record('payables.ap_invoice.updated', 'ap_invoice', $invoice->id, $before, $this->summary($invoice->refresh()));

            return $this->load($invoice);
        }));
    }

    // ------------------------------------------------------------------------------------------------ workflow

    public function submit(ApInvoice $invoice, User $actor): ApInvoice
    {
        return $this->load($this->workflow->submit($invoice, $actor, function (ApInvoice $invoice) use ($actor) {
            $this->validateForPosting($invoice, $actor);
        }));
    }

    public function approve(ApInvoice $invoice, User $actor): ApInvoice
    {
        return $this->load($this->workflow->approve($invoice, $actor, fn (ApInvoice $invoice) => $this->validateForPosting($invoice, $actor)));
    }

    public function reject(ApInvoice $invoice, User $actor, string $reason): ApInvoice
    {
        return $this->load($this->workflow->reject($invoice, $actor, $reason));
    }

    public function reopen(ApInvoice $invoice, User $actor): ApInvoice
    {
        return $this->load($this->workflow->reopen($invoice, $actor));
    }

    public function cancel(ApInvoice $invoice, User $actor, string $reason): ApInvoice
    {
        return $this->load($this->workflow->cancel($invoice, $actor, $reason));
    }

    // ------------------------------------------------------------------------------------------------ posting

    /**
     * Post an approved invoice: one atomic transaction that locks the invoice, hands the business fact to the Posting Engine
     * (rule -> mapping -> journal, period check under lock), issues the document number and links the journal. A replay or a
     * second concurrent request finds a POSTED invoice and is refused; the engine is idempotent on the invoice as well.
     */
    public function post(ApInvoice $invoice, User $actor): ApInvoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = ApInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->origin !== ApInvoice::ORIGIN_INVOICE) {
                throw new DomainException('A payable created by an expense is posted with its expense.', 'AP_INVOICE_NOT_POSTABLE', 409);
            }
            $this->workflow->assertMayPost($invoice, $actor);
            $vendor = $this->validateForPosting($invoice, $actor);

            $lines = ApInvoiceLine::query()->where('ap_invoice_id', $invoice->id)->orderBy('line_number')->get();
            $payload = $this->payload($invoice, $lines, $vendor, (int) $this->workflow->profile()->currency_scale);
            $dims = ['branch_id' => $invoice->branch_id, 'business_unit_id' => $invoice->business_unit_id, 'cost_center_id' => $invoice->cost_center_id];

            $event = $this->engine->postEvent(
                'AP_INVOICE_RECOGNIZED', ApInvoice::DOCUMENT_TYPE, $invoice->id, $invoice->posting_date->toDateString(), $payload, $dims, 'POST',
                mb_substr("Faktur vendor {$vendor->code} {$invoice->vendor_invoice_number}", 0, 500), $invoice->vendor_invoice_number, $actor, $this->authority->canPostSoftClosed($actor),
            );
            $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);
            $controlAccountId = $this->controlAccount($journal, $invoice->total_amount);

            return $this->load($this->workflow->markPosted($invoice, $actor, $journal, $event, 'AP_INVOICE', 'AP', ['payable_account_id' => $controlAccountId]));
        });
    }

    /**
     * Reverse a posted invoice through the shared reversal mechanism (a new journal with the sides swapped), then mark the
     * invoice REVERSED. Refused while it has payments: those must be reversed first, so the subledger never goes negative.
     */
    public function reverse(ApInvoice $invoice, User $actor, string $reason, ?string $postingDate = null): ApInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason, $postingDate) {
            $invoice = ApInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== ApInvoice::POSTED) {
                throw new DomainException($invoice->status === ApInvoice::REVERSED ? 'This invoice has already been reversed.' : 'Only a posted invoice can be reversed.', $invoice->status === ApInvoice::REVERSED ? 'AP_INVOICE_ALREADY_REVERSED' : 'AP_INVOICE_NOT_POSTED', 409, ['status' => $invoice->status]);
            }
            if ($invoice->origin !== ApInvoice::ORIGIN_INVOICE) {
                throw new DomainException('A payable created by an expense is reversed with its expense.', 'AP_INVOICE_NOT_REVERSIBLE', 409);
            }
            $this->assertNoActivePayments($invoice);

            $original = JournalEntry::query()->findOrFail($invoice->journal_entry_id);
            $reversal = $this->reversals->reverse($original, $actor, $reason, $postingDate, $invoice->document_number);

            return $this->load($this->workflow->markReversed($invoice, $actor, $reversal, $reason));
        });
    }

    /** Refuse a reversal while payments are allocated to the invoice (completed in the payment batch). */
    protected function assertNoActivePayments(ApInvoice $invoice): void
    {
        // Replaced by the allocation check when payments exist (see ApSettlementService).
    }

    // ------------------------------------------------------------------------------------------------ validation

    /** Everything posting needs, checked at submit, approve and post: vendor, totals, lines, period, readiness. Returns the vendor. */
    private function validateForPosting(ApInvoice $invoice, User $actor): Vendor
    {
        $vendor = Vendor::query()->find($invoice->vendor_id) ?? throw new DomainException('The vendor no longer exists.', 'VENDOR_NOT_FOUND', 422);
        $this->vendors->assertUsable($vendor);
        if (! $invoice->lines()->exists()) {
            throw new DomainException('An invoice needs at least one line.', 'AP_INVOICE_NO_LINES', 422);
        }
        if (BigDecimal::of($invoice->total_amount)->isLessThanOrEqualTo(0)) {
            throw new DomainException('The invoice total must be greater than zero.', 'AP_INVOICE_TOTAL_INVALID', 422);
        }
        foreach ($invoice->lines()->get() as $line) {
            if ($line->account_id) {
                $this->accounts->usable($line->account_id, ['EXPENSE', 'ASSET'], false, 'account_id', $line->line_number);
            }
        }
        $postingDate = $invoice->posting_date->toDateString();
        $this->readiness->assertCanPost($postingDate, JournalEntry::SYSTEM);
        $this->periods->resolveForPosting($postingDate, $this->authority->canPostSoftClosed($actor));

        return $vendor;
    }

    /**
     * Validate and normalize client data against the existing invoice (null = new). Totals are always recomputed here.
     *
     * @return array{vendor:Vendor,header:array<string,mixed>,columns:array<string,mixed>,lines:list<array<string,mixed>>}
     */
    private function prepare(array $data, ?ApInvoice $existing, User $actor, AccountingProfile $profile): array
    {
        $scale = (int) $profile->currency_scale;
        $field = fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);
        $date = fn (mixed $v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;

        $vendorId = $data['vendor_id'] ?? $existing?->vendor_id ?? throw new DomainException('The vendor is required.', 'VENDOR_REQUIRED', 422);
        $vendor = Vendor::query()->find($vendorId) ?? throw new DomainException('The vendor does not exist.', 'VENDOR_NOT_FOUND', 422, ['field' => 'vendor_id']);
        if ($existing === null || $existing->vendor_id !== $vendor->id) {
            $this->vendors->assertUsable($vendor);
        }
        if (isset($data['currency']) && $data['currency'] !== $profile->functional_currency) {
            throw new DomainException("OA2 books in the functional currency ({$profile->functional_currency}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'currency']);
        }

        $documentDate = $date($field('document_date')) ?? throw new DomainException('The document date is required.', 'DOCUMENT_DATE_REQUIRED', 422);
        $postingDate = $date(array_key_exists('posting_date', $data) ? $data['posting_date'] : ($existing?->posting_date ?? $documentDate));

        $termId = array_key_exists('payment_term_id', $data) ? $data['payment_term_id'] : ($existing ? $existing->payment_term_id : $vendor->payment_term_id);
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
            throw new DomainException('The discount cannot exceed the subtotal.', 'AP_INVOICE_DISCOUNT_INVALID', 422);
        }
        $total = $subtotal->minus($discount)->plus($tax)->plus($other);

        return [
            'vendor' => $vendor,
            'header' => collect($data)->only(['vendor_invoice_number', 'document_date', 'posting_date', 'description', 'reference'])->merge(['due_date' => $dueDate])->all(),
            'columns' => [
                'vendor_id' => $vendor->id, 'payment_term_id' => $term?->id, 'due_date' => $dueDate, 'due_date_overridden' => $overridden, 'currency' => $profile->functional_currency,
                'posting_date' => $postingDate, 'document_date' => $documentDate,
                'branch_id' => $dims['branch_id'], 'business_unit_id' => $dims['business_unit_id'], 'cost_center_id' => $dims['cost_center_id'],
                'subtotal_amount' => Money::str($subtotal), 'discount_amount' => Money::str($discount), 'tax_amount' => Money::str($tax),
                'other_charges_amount' => Money::str($other), 'total_amount' => Money::str($total),
            ] + ($existing === null ? ['vendor_invoice_number' => $data['vendor_invoice_number'] ?? throw new DomainException('The vendor invoice number is required.', 'AP_INVOICE_NUMBER_REQUIRED', 422), 'description' => $data['description'] ?? throw new DomainException('The description is required.', 'DESCRIPTION_REQUIRED', 422)] : []),
            'lines' => $lines,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function existingLines(?ApInvoice $invoice): array
    {
        return $invoice === null ? [] : $invoice->lines()->get()->map(fn (ApInvoiceLine $l) => [
            'description' => $l->description, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'amount' => $l->amount,
            'expense_category_id' => $l->expense_category_id, 'account_role' => $l->account_role, 'account_id' => $l->account_id,
            'cost_center_id' => $l->cost_center_id, 'metadata' => $l->metadata,
        ])->all();
    }

    /**
     * @param  list<array<string,mixed>>  $lines
     * @return list<array<string,mixed>>
     */
    private function normalizeLines(array $lines, int $scale, ?string $branchId): array
    {
        if ($lines === []) {
            throw new DomainException('An invoice needs at least one line.', 'AP_INVOICE_NO_LINES', 422);
        }
        if (count($lines) > 300) {
            throw new DomainException('An invoice can have at most 300 lines.', 'AP_INVOICE_TOO_MANY_LINES', 422);
        }

        $roles = DB::table('account_roles')->where('status', 'ACTIVE')->get()->keyBy('code');
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

            $categoryId = $line['expense_category_id'] ?? null;
            if ($categoryId !== null) {
                $category = ExpenseCategory::query()->find($categoryId);
                if (! $category || $category->status !== 'ACTIVE') {
                    throw new DomainException('The expense category does not exist or is inactive.', 'EXPENSE_CATEGORY_INVALID', 422, ['line' => $n]);
                }
            }
            $role = $line['account_role'] ?? null;
            if ($role !== null) {
                $def = $roles[$role] ?? null;
                if (! $def || $def->binding !== 'MAPPED' || in_array($role, self::FORBIDDEN_DESTINATION_ROLES, true)) {
                    throw new DomainException("The role {$role} cannot classify an invoice line.", 'ACCOUNT_ROLE_INVALID', 422, ['line' => $n, 'account_role' => $role]);
                }
            }
            $accountId = $line['account_id'] ?? null;
            if ($accountId !== null) {
                $accountId = $this->accounts->usable($accountId, ['EXPENSE', 'ASSET'], false, 'account_id', $n)->id;
            }
            $center = $this->dimensions->resolve($branchId, null, $line['cost_center_id'] ?? null, $n)['cost_center_id'];

            $out[] = [
                'description' => mb_substr($description, 0, 255), 'quantity' => $quantity ? Money::str($quantity) : null, 'unit_price' => $unitPrice ? Money::str($unitPrice) : null,
                'amount' => $amount, 'expense_category_id' => $categoryId, 'account_role' => $role, 'account_id' => $accountId, 'cost_center_id' => $center,
                'metadata' => isset($line['metadata']) && is_array($line['metadata']) ? array_slice($line['metadata'], 0, 20, true) : null,
            ];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $lines */
    private function writeLines(ApInvoice $invoice, array $lines): void
    {
        ApInvoiceLine::query()->where('ap_invoice_id', $invoice->id)->delete();
        foreach ($lines as $i => $data) {
            $line = new ApInvoiceLine(['description' => $data['description'], 'quantity' => $data['quantity'], 'unit_price' => $data['unit_price'], 'amount' => Money::str($data['amount']), 'metadata' => $data['metadata']]);
            $line->forceFill([
                'ap_invoice_id' => $invoice->id, 'line_number' => $i + 1, 'expense_category_id' => $data['expense_category_id'], 'account_role' => $data['account_role'],
                'account_id' => $data['account_id'], 'cost_center_id' => $data['cost_center_id'],
            ])->save();
        }
    }

    // ------------------------------------------------------------------------------------------------ duplicates

    /** The live invoice with the same vendor and vendor invoice number (case, outer and repeated spaces ignored), if any. */
    public function findDuplicate(string $vendorId, string $number, ?string $exceptId = null): ?ApInvoice
    {
        $key = mb_strtoupper(preg_replace('/\s+/', ' ', trim($number)));

        return ApInvoice::query()->where('vendor_id', $vendorId)->whereRaw('vendor_invoice_key = ?', [$key])
            ->whereIn('status', [ApInvoice::DRAFT, ApInvoice::SUBMITTED, ApInvoice::APPROVED, ApInvoice::POSTED])->whereNull('duplicate_override_by')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->first();
    }

    /** Same vendor, same total and document date but another number: worth a warning, not a refusal. */
    public function possibleDuplicates(ApInvoice $invoice): array
    {
        return ApInvoice::query()->where('vendor_id', $invoice->vendor_id)->where('id', '!=', $invoice->id)->where('total_amount', $invoice->total_amount)
            ->whereDate('document_date', $invoice->document_date)->whereIn('status', [ApInvoice::DRAFT, ApInvoice::SUBMITTED, ApInvoice::APPROVED, ApInvoice::POSTED])
            ->tap(fn ($q) => $this->scope->restrict($q->getQuery(), 'ap_invoices'))
            ->limit(5)->get(['id', 'document_number', 'vendor_invoice_number', 'status'])->toArray();
    }

    private function assertNotDuplicate(string $vendorId, string $number, ?string $exceptId, array $data, User $actor): void
    {
        $duplicate = $this->findDuplicate($vendorId, $number, $exceptId);
        if ($duplicate === null) {
            return;
        }
        if (! ($data['duplicate_override'] ?? false)) {
            throw new DomainException('This vendor already has an invoice with this number.', 'AP_INVOICE_DUPLICATE', 409, [
                'status' => $duplicate->status, 'document_number' => $duplicate->document_number, 'vendor_invoice_number' => $duplicate->vendor_invoice_number,
            ]);
        }
        if (! $this->authority->can($actor, 'accounting.ap_invoice.override_duplicate')) {
            throw new DomainException('You are not allowed to record a duplicate vendor invoice number.', 'DUPLICATE_OVERRIDE_NOT_ALLOWED', 403);
        }
        if (trim((string) ($data['duplicate_override_reason'] ?? '')) === '') {
            throw new DomainException('A reason is required to record a duplicate vendor invoice number.', 'DUPLICATE_OVERRIDE_REASON_REQUIRED', 422);
        }
    }

    /** The override is recorded only when a duplicate really exists (a stray flag on a unique number is ignored). @return array<string,mixed> */
    private function overrideColumns(array $data, User $actor, string $vendorId, ?ApInvoice $existing): array
    {
        if ($existing?->duplicate_override_by) {
            return [];
        }
        $number = $data['vendor_invoice_number'] ?? $existing?->vendor_invoice_number;
        if (! ($data['duplicate_override'] ?? false) || $number === null || $this->findDuplicate($vendorId, $number, $existing?->id) === null) {
            return [];
        }

        return ['duplicate_override_by' => $actor->id, 'duplicate_override_reason' => mb_substr(trim((string) $data['duplicate_override_reason']), 0, 255)];
    }

    // ------------------------------------------------------------------------------------------------ posting payload

    /**
     * The business fact the engine posts: components (net, tax, total) plus the document's own account choices. Header discount and
     * other charges are spread over the lines in proportion to their amounts, so every part is a real cost of the line it sits on.
     *
     * @param  iterable<ApInvoiceLine>  $lines
     * @return array<string,mixed>
     */
    private function payload(ApInvoice $invoice, iterable $lines, Vendor $vendor, int $scale): array
    {
        $lines = collect($lines)->values();
        $adjustment = BigDecimal::of($invoice->other_charges_amount)->minus($invoice->discount_amount);
        $shares = Money::prorate($adjustment, $lines->map(fn ($l) => BigDecimal::of($l->amount))->all(), $scale);
        $categories = ExpenseCategory::query()->whereIn('id', $lines->pluck('expense_category_id')->filter()->all())->get()->keyBy('id');

        $parts = [];
        foreach ($lines as $i => $line) {
            $category = $categories[$line->expense_category_id] ?? null;
            $parts[] = array_filter([
                'amount' => Money::str(BigDecimal::of($line->amount)->plus($shares[$i])),
                'account_id' => $line->account_id ?? $category?->account_id,
                'account_role' => $line->account_id || $category?->account_id ? null : ($line->account_role ?? $category?->account_role),
                'description' => $line->description, 'cost_center_id' => $line->cost_center_id,
            ], fn ($v) => $v !== null);
        }

        $net = BigDecimal::of($invoice->subtotal_amount)->minus($invoice->discount_amount)->plus($invoice->other_charges_amount);
        $payload = [
            'net' => Money::str($net), 'tax' => Money::str($invoice->tax_amount), 'total' => Money::str($invoice->total_amount),
            'distribution' => ['net' => $parts],
        ];
        if ($vendor->payable_account_id) {
            $payload['role_accounts'] = ['ACCOUNTS_PAYABLE' => $vendor->payable_account_id];
        }

        return $payload;
    }

    /** The control account the posted journal credited for the payable: found in the stored posting snapshot, and checked against the invoice total. */
    private function controlAccount(JournalEntry $journal, string $total): string
    {
        $credits = collect($journal->posting_snapshot['lines'] ?? [])->where('account_role', 'ACCOUNTS_PAYABLE')->where('side', 'CREDIT');
        $accounts = $credits->pluck('account_id')->unique();
        if ($accounts->count() !== 1 || ! Money::sum($credits->pluck('amount'))->isEqualTo($total)) {
            throw new DomainException('The AP invoice posting rule must credit the accounts payable role with the invoice total.', 'AP_POSTING_RULE_INVALID', 422);
        }

        return $accounts->first();
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function assertDraft(ApInvoice $invoice): void
    {
        if ($invoice->status !== ApInvoice::DRAFT) {
            throw new DomainException("A {$invoice->status} invoice cannot be edited.", in_array($invoice->status, [ApInvoice::POSTED, ApInvoice::REVERSED], true) ? 'DOCUMENT_IMMUTABLE' : 'DOCUMENT_NOT_DRAFT', 409, ['status' => $invoice->status]);
        }
    }

    private function summary(ApInvoice $invoice): array
    {
        return $invoice->only(['document_number', 'vendor_id', 'vendor_invoice_number', 'status', 'document_date', 'posting_date', 'due_date', 'total_amount']);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505' && str_contains($e->getMessage(), 'ap_invoices_vendor_number_unique')) {
                throw new DomainException('This vendor already has an invoice with this number.', 'AP_INVOICE_DUPLICATE', 409);
            }
            throw $e;
        }
    }
}
