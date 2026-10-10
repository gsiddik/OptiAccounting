<?php

namespace App\Domain\Expense\Services;

use App\Domain\Accounting\Models\AccountingEvent;
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
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\CashBank\Services\CashBankAccountService;
use App\Domain\Expense\Models\Expense;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Payables\Models\ApInvoice;
use App\Domain\Payables\Models\ApPaymentAllocation;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Models\VendorPayment;
use App\Domain\Payables\Services\ApSubledgerService;
use App\Domain\Payables\Services\PaymentTermService;
use App\Domain\Payables\Services\VendorService;
use App\Domain\Shared\DomainException;
use App\Domain\Tax\Services\TaxDocumentService;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Expenses. One expense is one classified cost with an explicit financial path, chosen by the user and fixed in the document:
 *   PAYABLE      posts EXPENSE_RECOGNIZED (Dr expense, Cr accounts payable) and creates the payable in the AP subledger as a posted
 *                ap_invoices row (origin EXPENSE) that shares the journal, so the ordinary vendor payment settles it;
 *   DIRECT_PAID  posts EXPENSE_PAID (Dr expense, Cr the chosen cash or bank account) in one step.
 * The accounts always come from the posting rule and the tenant's mappings through `PostingEngine::postEvent`; the expense only
 * carries the business fact (net, tax, total, the classification the user chose). After posting nothing financial changes.
 */
class ExpenseService
{
    public function __construct(
        private readonly DocumentWorkflow $workflow,
        private readonly ExpenseCategoryService $categories,
        private readonly VendorService $vendors,
        private readonly PaymentTermService $terms,
        private readonly CashBankAccountService $cashAccounts,
        private readonly AccountGuard $accounts,
        private readonly DimensionGuard $dimensions,
        private readonly DocumentScope $scope,
        private readonly ActorAuthority $authority,
        private readonly PostingEngine $engine,
        private readonly ReversalService $reversals,
        private readonly PeriodGuard $periods,
        private readonly ReadinessService $readiness,
        private readonly SegregationOfDuties $sod,
        private readonly ApSubledgerService $subledger,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
        private readonly TaxDocumentService $taxes,
    ) {}

    // ------------------------------------------------------------------------------------------------ queries

    public function query(array $filter = []): Builder
    {
        $query = Expense::query()->with(['vendor', 'category', 'cashBankAccount', 'branch', 'creator']);
        $this->scope->restrict($query->getQuery(), 'expenses');

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('expenses.status', $v))
            ->when($filter['settlement'] ?? null, fn ($q, $v) => $q->where('expenses.settlement', $v))
            ->when($filter['vendor_id'] ?? null, fn ($q, $v) => $q->where('expenses.vendor_id', $v))
            ->when($filter['expense_category_id'] ?? null, fn ($q, $v) => $q->where('expenses.expense_category_id', $v))
            ->when($filter['cash_bank_account_id'] ?? null, fn ($q, $v) => $q->where('expenses.cash_bank_account_id', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('expenses.branch_id', $v))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $v) => $q->where('expenses.business_unit_id', $v))
            ->when($filter['cost_center_id'] ?? null, fn ($q, $v) => $q->where('expenses.cost_center_id', $v))
            ->when($filter['expense_from'] ?? null, fn ($q, $v) => $q->whereDate('expenses.expense_date', '>=', $v))
            ->when($filter['expense_to'] ?? null, fn ($q, $v) => $q->whereDate('expenses.expense_date', '<=', $v))
            ->when($filter['posting_from'] ?? null, fn ($q, $v) => $q->whereDate('expenses.posting_date', '>=', $v))
            ->when($filter['posting_to'] ?? null, fn ($q, $v) => $q->whereDate('expenses.posting_date', '<=', $v))
            ->when($filter['mine'] ?? false, fn ($q) => $q->where('expenses.created_by', $this->context->user()?->id))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(coalesce(expenses.document_number, \'\')) like ?', [$like])->orWhereRaw('lower(expenses.description) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(expenses.reference, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(expenses.payee_name, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(expenses.supporting_document, \'\')) like ?', [$like]));
            })
            ->orderByDesc('expenses.posting_date')->orderByDesc('expenses.created_at');
    }

    /** The full document for the detail page; a posted payable expense also shows the derived settlement of its payable. */
    public function load(Expense $expense): Expense
    {
        $expense->load(['vendor', 'category', 'account', 'paymentTerm', 'cashBankAccount', 'glAccount', 'branch', 'businessUnit', 'costCenter', 'creator', 'transitions.actor']);
        if ($expense->settlement === Expense::PAYABLE && in_array($expense->status, [Expense::POSTED, Expense::REVERSED], true)) {
            $payable = $this->subledger->figures(ApInvoice::query()->where('source_type', Expense::DOCUMENT_TYPE)->where('source_id', $expense->id))->first();
            $expense->setRelation('payable', $payable);
        }

        return $expense;
    }

    /** What the signed-in user may still do with this expense under the segregation-of-duties policy. */
    public function sod(Expense $expense, string $userId): array
    {
        return $this->sod->documentAllowed($expense, $userId, $this->workflow->profile()) + ['approval_required' => $this->workflow->profile()->approval_required];
    }

    // ------------------------------------------------------------------------------------------------ draft

    public function create(array $data, User $actor): Expense
    {
        return DB::transaction(function () use ($data, $actor) {
            $prepared = $this->prepare($data, null, $actor, $this->workflow->profile());

            $expense = new Expense($prepared['header']);
            $expense->forceFill($prepared['columns']);
            $expense->status = Expense::DRAFT;
            $expense->created_by = $actor->id;
            $expense->save();
            $this->syncTax($expense, $prepared, $actor);
            $this->workflow->created($expense, $actor->id);
            $this->audit->record('expense.expense.created', 'expense', $expense->id, null, $this->summary($expense));

            return $this->load($expense->refresh());
        });
    }

    public function update(Expense $expense, array $data, User $actor): Expense
    {
        return DB::transaction(function () use ($expense, $data, $actor) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $this->assertDraft($expense);
            $before = $this->summary($expense);

            $prepared = $this->prepare($data, $expense, $actor, $this->workflow->profile());
            $expense->fill($prepared['header']);
            $expense->forceFill($prepared['columns']);
            $expense->save();
            $this->syncTax($expense, $prepared, $actor);
            $this->audit->record('expense.expense.updated', 'expense', $expense->id, $before, $this->summary($expense->refresh()));

            return $this->load($expense);
        });
    }

    // ------------------------------------------------------------------------------------------------ workflow

    public function submit(Expense $expense, User $actor): Expense
    {
        return $this->load($this->workflow->submit($expense, $actor, fn (Expense $e) => $this->validateForPosting($e, $actor, false)));
    }

    public function approve(Expense $expense, User $actor): Expense
    {
        return $this->load($this->workflow->approve($expense, $actor, fn (Expense $e) => $this->validateForPosting($e, $actor, false)));
    }

    public function reject(Expense $expense, User $actor, string $reason): Expense
    {
        return $this->load($this->workflow->reject($expense, $actor, $reason));
    }

    public function reopen(Expense $expense, User $actor): Expense
    {
        return $this->load($this->workflow->reopen($expense, $actor));
    }

    public function cancel(Expense $expense, User $actor, string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $actor, $reason) {
            $cancelled = $this->workflow->cancel($expense, $actor, $reason);
            $this->taxes->discard(Expense::DOCUMENT_TYPE, $cancelled->id);

            return $this->load($cancelled);
        });
    }

    // ------------------------------------------------------------------------------------------------ posting

    /**
     * Post an approved expense in one atomic transaction: lock the expense (and the cash/bank account FOR SHARE), hand the business fact
     * to the Posting Engine (rule -> mapping -> journal, period check under lock), issue the number, link the journal and, on the payable
     * path, create the payable in the AP subledger with the same journal. A replay or a concurrent second request finds a POSTED expense.
     */
    public function post(Expense $expense, User $actor): Expense
    {
        return DB::transaction(function () use ($expense, $actor) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $this->workflow->assertMayPost($expense, $actor);
            ['vendor' => $vendor, 'cash' => $cash, 'category' => $category] = $this->validateForPosting($expense, $actor, true);

            $payable = $expense->settlement === Expense::PAYABLE;
            $tax = $this->taxes->postingParts(Expense::DOCUMENT_TYPE, $expense->id);
            $payload = $this->payload($expense, $category, $vendor, $cash, $tax);
            $dims = ['branch_id' => $expense->branch_id, 'business_unit_id' => $expense->business_unit_id, 'cost_center_id' => $expense->cost_center_id];

            $event = $this->engine->postEvent(
                $payable ? 'EXPENSE_RECOGNIZED' : 'EXPENSE_PAID', Expense::DOCUMENT_TYPE, $expense->id, $expense->posting_date->toDateString(), $payload, $dims, 'POST',
                mb_substr("Beban {$category->code}: {$expense->description}", 0, 500), $expense->reference, $actor, $this->authority->canPostSoftClosed($actor),
            );
            $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);

            if ($payable) {
                $control = $this->subledger->controlAccount($journal, $expense->total_amount);
                $this->workflow->markPosted($expense, $actor, $journal, $event, 'EXPENSE', 'EXP');
                $this->createPayable($expense, $journal, $event, $control, $actor);
            } else {
                $this->assertPaidJournal($journal, $expense->total_amount, $cash->account_id);
                $this->workflow->markPosted($expense, $actor, $journal, $event, 'EXPENSE', 'EXP', ['gl_account_id' => $cash->account_id]);
            }
            $this->taxes->markPosted(Expense::DOCUMENT_TYPE, $expense->id, $journal, $expense->document_number);

            return $this->load($expense);
        });
    }

    /**
     * Reverse a posted expense through the shared reversal mechanism (a new journal with the sides swapped), then mark it REVERSED, and
     * on the payable path its payable with it. Refused while payments are allocated to the payable: they are reversed first.
     */
    public function reverse(Expense $expense, User $actor, string $reason, ?string $postingDate = null): Expense
    {
        return DB::transaction(function () use ($expense, $actor, $reason, $postingDate) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($expense->status !== Expense::POSTED) {
                throw new DomainException($expense->status === Expense::REVERSED ? 'This expense has already been reversed.' : 'Only a posted expense can be reversed.', $expense->status === Expense::REVERSED ? 'EXPENSE_ALREADY_REVERSED' : 'EXPENSE_NOT_POSTED', 409, ['status' => $expense->status]);
            }

            $this->authority->assertLedgerWritable($actor);
            $payable = null;
            if ($expense->settlement === Expense::PAYABLE) {
                $payable = ApInvoice::query()->where('source_type', Expense::DOCUMENT_TYPE)->where('source_id', $expense->id)->lockForUpdate()->firstOrFail();
                if (ApPaymentAllocation::query()->where('ap_invoice_id', $payable->id)->where('is_effective', true)->exists()) {
                    throw new DomainException('Payments are allocated to the payable of this expense; reverse them first.', 'EXPENSE_HAS_PAYMENTS', 409);
                }
            }

            $original = JournalEntry::query()->findOrFail($expense->journal_entry_id);
            $reversal = $this->reversals->reverse($original, $actor, $reason, $postingDate, $expense->document_number);

            $this->workflow->markReversed($expense, $actor, $reversal, $reason);
            if ($payable !== null) {
                $this->workflow->markReversed($payable, $actor, $reversal, $reason);
            }
            $this->taxes->markReversed(Expense::DOCUMENT_TYPE, $expense->id, $reversal);

            return $this->load($expense);
        });
    }

    /** The posted payable of a payable expense, in the AP subledger: the same journal, settled by ordinary vendor payments. */
    private function createPayable(Expense $expense, JournalEntry $journal, AccountingEvent $event, string $controlAccountId, User $actor): ApInvoice
    {
        $payable = new ApInvoice;
        $payable->forceFill([
            'origin' => ApInvoice::ORIGIN_EXPENSE, 'vendor_id' => $expense->vendor_id, 'document_number' => $expense->document_number, 'vendor_invoice_number' => $expense->document_number,
            'document_date' => $expense->expense_date->toDateString(), 'posting_date' => $expense->posting_date->toDateString(), 'due_date' => $expense->due_date->toDateString(),
            'payment_term_id' => $expense->payment_term_id, 'due_date_overridden' => $expense->due_date_overridden, 'currency' => $expense->currency,
            'description' => $expense->description, 'reference' => $expense->reference,
            'branch_id' => $expense->branch_id, 'business_unit_id' => $expense->business_unit_id, 'cost_center_id' => $expense->cost_center_id,
            'subtotal_amount' => $expense->net_amount, 'discount_amount' => '0.0000', 'tax_amount' => $expense->tax_amount, 'other_charges_amount' => '0.0000',
            'total_amount' => $expense->total_amount, 'payable_account_id' => $controlAccountId, 'status' => ApInvoice::POSTED,
            'source_type' => Expense::DOCUMENT_TYPE, 'source_id' => $expense->id,
            'created_by' => $expense->created_by, 'submitted_by' => $expense->submitted_by, 'submitted_at' => $expense->submitted_at,
            'approved_by' => $expense->approved_by, 'approved_at' => $expense->approved_at,
            'posted_by' => $actor->id, 'posted_at' => $expense->posted_at, 'journal_entry_id' => $journal->id, 'accounting_event_id' => $event->id,
        ])->save();
        $this->workflow->log($payable, null, ApInvoice::POSTED, $actor->id);
        $this->audit->record('payables.ap_invoice.created_from_expense', 'ap_invoice', $payable->id, null, [
            'document_number' => $payable->document_number, 'expense_id' => $expense->id, 'vendor_id' => $payable->vendor_id, 'total_amount' => $payable->total_amount, 'due_date' => $expense->due_date->toDateString(),
        ]);

        return $payable;
    }

    // ------------------------------------------------------------------------------------------------ validation

    /**
     * Everything posting needs, checked at submit, approve and post. With $lock the cash/bank account is held FOR SHARE so it cannot be
     * deactivated or remapped before the posting commits.
     *
     * @return array{vendor:?Vendor,cash:?CashBankAccount,category:ExpenseCategory}
     */
    private function validateForPosting(Expense $expense, User $actor, bool $lock): array
    {
        $this->authority->assertLedgerWritable($actor);
        $category = $this->categories->usable($expense->expense_category_id);
        if ($expense->account_id) {
            $this->accounts->usable($expense->account_id, ['EXPENSE', 'ASSET'], false, 'account_id');
        }

        $vendor = $cash = null;
        if ($expense->settlement === Expense::PAYABLE) {
            $vendor = Vendor::query()->find($expense->vendor_id) ?? throw new DomainException('The vendor no longer exists.', 'VENDOR_NOT_FOUND', 422);
            $this->vendors->assertUsable($vendor);
            $this->authority->assertModuleWritable($actor, 'ACCOUNTING_AP', 'VENDOR_INVOICE'); // the payable lives in the AP subledger
        } else {
            $this->authority->assertModuleWritable($actor, 'ACCOUNTING_CASH_BANK', 'CASH_BANK_ACCOUNT');
            $cash = $this->cashAccounts->usable($expense->cash_bank_account_id, $lock);
        }

        $this->taxes->assertCurrent(Expense::DOCUMENT_TYPE, $expense->id, $actor, $lock);
        $postingDate = $expense->posting_date->toDateString();
        $this->readiness->assertCanPost($postingDate, JournalEntry::SYSTEM);
        $this->periods->resolveForPosting($postingDate, $this->authority->canPostSoftClosed($actor));

        return ['vendor' => $vendor, 'cash' => $cash, 'category' => $category];
    }

    /**
     * Validate and normalize client data against the existing expense (null = new). Totals are always recomputed here.
     *
     * @return array{header:array<string,mixed>,columns:array<string,mixed>}
     */
    private function prepare(array $data, ?Expense $existing, User $actor, AccountingProfile $profile): array
    {
        $scale = (int) $profile->currency_scale;
        $field = fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);
        $date = fn (mixed $v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;

        $settlement = $field('settlement');
        if (! in_array($settlement, [Expense::PAYABLE, Expense::DIRECT_PAID], true)) {
            throw new DomainException('Choose how the expense is settled: PAYABLE or DIRECT_PAID.', 'EXPENSE_SETTLEMENT_INVALID', 422, ['field' => 'settlement']);
        }
        if (isset($data['currency']) && $data['currency'] !== $profile->functional_currency) {
            throw new DomainException("OA2 books in the functional currency ({$profile->functional_currency}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'currency']);
        }

        $category = $this->categories->usable($field('expense_category_id'));
        $accountId = array_key_exists('account_id', $data) ? $data['account_id'] : $existing?->account_id;
        if ($accountId !== null && ($existing === null || $accountId !== $existing->account_id)) {
            $accountId = $this->accounts->usable($accountId, ['EXPENSE', 'ASSET'], false, 'account_id')->id;
        }

        $method = $field('payment_method');
        if ($method !== null && ! in_array($method, VendorPayment::METHODS, true)) {
            throw new DomainException('Unknown payment method.', 'PAYMENT_METHOD_INVALID', 422, ['field' => 'payment_method']);
        }

        $expenseDate = $date($field('expense_date')) ?? throw new DomainException('The expense date is required.', 'EXPENSE_DATE_REQUIRED', 422, ['field' => 'expense_date']);
        $postingDate = $date(array_key_exists('posting_date', $data) && $data['posting_date'] !== null ? $data['posting_date'] : ($existing?->posting_date ?? $expenseDate));

        // The amount is kept as entered; with a tax code the net is the base the code leaves (for an inclusive code, the amount without tax).
        $taxCodeId = $field('tax_code_id');
        $entered = Money::parse(array_key_exists('net_amount', $data) ? $data['net_amount'] : ($existing?->tax_code_id ? $existing->entered_amount : $existing?->net_amount), $scale, 'net_amount');
        if ($entered->isLessThanOrEqualTo(0)) {
            throw new DomainException('The expense amount must be greater than zero.', 'EXPENSE_AMOUNT_INVALID', 422, ['field' => 'net_amount']);
        }
        $app = $this->taxes->apply(TaxDocumentService::INPUT, [['amount' => $entered, 'tax_code_id' => $taxCodeId]], substr((string) $expenseDate, 0, 10), $scale, $actor);
        $net = $app->lines[0]['amount'];
        $tax = $this->taxes->headerTax($app, $data, $existing?->id ? Expense::DOCUMENT_TYPE : null, $existing?->id, $existing?->tax_amount, BigDecimal::zero(), $scale);
        $total = $net->plus($tax);

        $vendor = $cash = null;
        $dueDate = $termId = null;
        $overridden = false;
        $vendorId = $field('vendor_id');
        if ($settlement === Expense::PAYABLE) {
            $vendor = Vendor::query()->find($vendorId ?? null) ?? throw new DomainException('A payable expense needs its vendor.', 'VENDOR_REQUIRED', 422, ['field' => 'vendor_id']);
            if ($existing === null || $existing->vendor_id !== $vendor->id) {
                $this->vendors->assertUsable($vendor);
            }
            $termId = array_key_exists('payment_term_id', $data) ? $data['payment_term_id'] : ($existing?->settlement === Expense::PAYABLE ? $existing->payment_term_id : $vendor->payment_term_id);
            $term = $termId ? (PaymentTerm::query()->find($termId) ?? throw new DomainException('The payment term does not exist.', 'PAYMENT_TERM_NOT_FOUND', 422, ['field' => 'payment_term_id'])) : null;
            $explicitDue = array_key_exists('due_date', $data) ? $data['due_date'] : ($existing?->due_date_overridden ? $date($existing->due_date) : null);
            [$dueDate, $overridden] = $this->terms->dueDate($term, $expenseDate, $explicitDue);
            $termId = $term?->id;
        } else {
            if ($vendorId !== null) {
                $vendor = Vendor::query()->find($vendorId) ?? throw new DomainException('The vendor does not exist.', 'VENDOR_NOT_FOUND', 422, ['field' => 'vendor_id']);
                if ($existing === null || $existing->vendor_id !== $vendor->id) {
                    $this->vendors->assertUsable($vendor);
                }
            }
            $cashId = $field('cash_bank_account_id') ?? throw new DomainException('A directly paid expense needs its cash or bank account.', 'CASH_BANK_ACCOUNT_REQUIRED', 422, ['field' => 'cash_bank_account_id']);
            $cash = $this->cashAccounts->usable($cashId);
        }

        $dims = $this->dimensions->resolve($field('branch_id', $cash?->branch_id), $field('business_unit_id', $cash?->business_unit_id), $field('cost_center_id'));
        $this->scope->assertWritable($dims['branch_id'], $dims['business_unit_id'], $existing?->created_by ?? $actor->id);

        return [
            'header' => collect($data)->only(['expense_date', 'posting_date', 'description', 'reference', 'payee_name', 'payment_method', 'supporting_document'])->all(),
            'columns' => [
                'settlement' => $settlement, 'vendor_id' => $vendor?->id, 'expense_category_id' => $category->id, 'account_id' => $accountId, 'currency' => $profile->functional_currency,
                'expense_date' => $expenseDate, 'posting_date' => $postingDate, 'due_date' => $dueDate, 'payment_term_id' => $termId, 'due_date_overridden' => $overridden,
                'cash_bank_account_id' => $cash?->id,
                'branch_id' => $dims['branch_id'], 'business_unit_id' => $dims['business_unit_id'], 'cost_center_id' => $dims['cost_center_id'],
                'net_amount' => Money::str($net), 'tax_amount' => Money::str($tax), 'total_amount' => Money::str($total),
                'tax_code_id' => $app->active() ? $taxCodeId : null, 'entered_amount' => $app->active() ? Money::str($entered) : null,
            ] + ($existing === null ? ['description' => $data['description'] ?? throw new DomainException('The description is required.', 'DESCRIPTION_REQUIRED', 422, ['field' => 'description'])] : []),
            'tax' => $app, 'tax_date' => substr((string) $expenseDate, 0, 10), 'vendor' => $vendor,
        ];
    }

    /** Keep the DRAFT tax transaction of the expense (one, for the whole document) in step with what was just saved. */
    private function syncTax(Expense $expense, array $prepared, User $actor): void
    {
        $vendor = $prepared['vendor'];
        $this->taxes->sync(Expense::DOCUMENT_TYPE, TaxDocumentService::INPUT, $expense, false, $prepared['tax'], $prepared['tax_date'],
            ['type' => 'vendor', 'id' => $vendor?->id, 'name' => $vendor?->name ?? $expense->payee_name, 'tax_id' => $vendor?->tax_id], $actor);
    }

    // ------------------------------------------------------------------------------------------------ posting payload

    /**
     * The business fact the engine posts: components (net, tax, total) plus the classification the document carries (its own account, else
     * its category's account or role; with none, the rule's expense role decides) and the account of the settlement side.
     *
     * @return array<string,mixed>
     */
    private function payload(Expense $expense, ExpenseCategory $category, ?Vendor $vendor, ?CashBankAccount $cash, array $tax): array
    {
        $accountId = $expense->account_id ?? $category->account_id;
        $part = array_filter([
            'amount' => Money::str(BigDecimal::of($expense->net_amount)->plus($tax['cost'][0] ?? 0)), // a non-recoverable tax is a cost of the expense
            'account_id' => $accountId, 'account_role' => $accountId ? null : $category->account_role,
            'description' => mb_substr($expense->description, 0, 255), 'cost_center_id' => $expense->cost_center_id,
        ], fn ($v) => $v !== null);

        // with a tax code the recoverable tax is booked by account and the rest sits in the cost; a manual tax keeps its single tax line
        $taxAmount = $tax['managed'] ? $tax['recoverable'] : BigDecimal::of($expense->tax_amount);
        $payload = [
            'net' => Money::str(BigDecimal::of($expense->total_amount)->minus($taxAmount)), 'tax' => Money::str($taxAmount), 'total' => Money::str($expense->total_amount),
            'distribution' => ['net' => [$part]] + ($tax['parts'] === [] ? [] : ['tax' => $tax['parts']]),
        ];
        if ($expense->settlement === Expense::PAYABLE && $vendor?->payable_account_id) {
            $payload['role_accounts'] = ['ACCOUNTS_PAYABLE' => $vendor->payable_account_id];
        } elseif ($cash !== null) {
            $payload['role_accounts'] = ['CASH_BANK_ACCOUNT' => $cash->account_id];
        }

        return $payload;
    }

    /** A directly paid expense must credit exactly the chosen cash/bank GL account with its total, and never touch accounts payable. */
    private function assertPaidJournal(JournalEntry $journal, string $total, string $cashGlAccountId): void
    {
        $lines = collect($journal->posting_snapshot['lines'] ?? []);
        $credits = $lines->where('account_role', 'CASH_BANK_ACCOUNT')->where('side', 'CREDIT');
        if (! Money::sum($credits->pluck('amount')->all())->isEqualTo($total) || $credits->pluck('account_id')->unique()->all() !== [$cashGlAccountId]
            || $lines->where('account_role', 'ACCOUNTS_PAYABLE')->isNotEmpty()) {
            throw new DomainException('The directly paid expense posting rule must credit the cash and bank role with the expense total.', 'EXPENSE_POSTING_RULE_INVALID', 422);
        }
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function assertDraft(Expense $expense): void
    {
        if ($expense->status !== Expense::DRAFT) {
            throw new DomainException("A {$expense->status} expense cannot be edited.", in_array($expense->status, [Expense::POSTED, Expense::REVERSED], true) ? 'DOCUMENT_IMMUTABLE' : 'DOCUMENT_NOT_DRAFT', 409, ['status' => $expense->status]);
        }
    }

    private function summary(Expense $expense): array
    {
        return $expense->only(['document_number', 'settlement', 'vendor_id', 'expense_category_id', 'cash_bank_account_id', 'status', 'expense_date', 'posting_date', 'due_date', 'total_amount']);
    }
}
