<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Models\JournalEntry;
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
use App\Domain\Identity\Models\User;
use App\Domain\Receivables\Models\ArCreditNote;
use App\Domain\Receivables\Models\ArInvoice;
use App\Domain\Receivables\Models\ArReceiptAllocation;
use App\Domain\Receivables\Models\Customer;
use App\Domain\Receivables\Models\CustomerReceipt;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer receipts. A receipt is prepared as a draft with its allocations to posted customer invoices; it posts as one atomic unit:
 * lock the receipt, the cash/bank account (shared) and every invoice it settles (in id order), re-check what is outstanding under those
 * locks, hand the business fact to `PostingEngine::postEvent` (CUSTOMER_RECEIPT: debit the cash/bank GL account, credit the
 * receivable accounts the invoices were booked to), and only then make the allocations effective. A reversal reverses the journal through the
 * shared mechanism and releases the allocations in the same transaction. Outstanding is derived (see ArSubledgerService); no
 * balance is ever written. A receipt is allocated in full: customer advances and prereceipts do not exist in OA2, so nothing can make
 * a receivable negative.
 */
class CustomerReceiptService
{
    public function __construct(
        private readonly DocumentWorkflow $workflow,
        private readonly CustomerService $customers,
        private readonly CashBankAccountService $cashAccounts,
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
        $allocated = '(select coalesce(sum(a.amount), 0) from ar_receipt_allocations a where a.tenant_id = customer_receipts.tenant_id and a.customer_receipt_id = customer_receipts.id)';
        $query = CustomerReceipt::query()->with(['customer', 'cashBankAccount', 'branch', 'creator'])->addSelect('customer_receipts.*')->selectRaw("{$allocated} as allocated_amount");
        $this->scope->restrict($query->getQuery(), 'customer_receipts');

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('customer_receipts.status', $v))
            ->when($filter['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_receipts.customer_id', $v))
            ->when($filter['cash_bank_account_id'] ?? null, fn ($q, $v) => $q->where('customer_receipts.cash_bank_account_id', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('customer_receipts.branch_id', $v))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $v) => $q->where('customer_receipts.business_unit_id', $v))
            ->when($filter['cost_center_id'] ?? null, fn ($q, $v) => $q->where('customer_receipts.cost_center_id', $v))
            ->when($filter['receipt_from'] ?? null, fn ($q, $v) => $q->whereDate('customer_receipts.receipt_date', '>=', $v))
            ->when($filter['receipt_to'] ?? null, fn ($q, $v) => $q->whereDate('customer_receipts.receipt_date', '<=', $v))
            ->when($filter['posting_from'] ?? null, fn ($q, $v) => $q->whereDate('customer_receipts.posting_date', '>=', $v))
            ->when($filter['posting_to'] ?? null, fn ($q, $v) => $q->whereDate('customer_receipts.posting_date', '<=', $v))
            ->when($filter['mine'] ?? false, fn ($q) => $q->where('customer_receipts.created_by', $this->context->user()?->id))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(coalesce(customer_receipts.document_number, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(customer_receipts.reference, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(customer_receipts.description, \'\')) like ?', [$like]));
            })
            ->orderByDesc('customer_receipts.posting_date')->orderByDesc('customer_receipts.created_at');
    }

    public function load(CustomerReceipt $receipt): CustomerReceipt
    {
        $receipt->load(['customer', 'cashBankAccount', 'glAccount', 'branch', 'businessUnit', 'costCenter', 'creator', 'transitions.actor', 'allocations.invoice']);
        $allocated = Money::sum($receipt->allocations->pluck('amount')->all());
        $receipt->setAttribute('allocated_amount', Money::str($allocated));
        $receipt->setAttribute('unallocated_amount', Money::str(BigDecimal::of($receipt->amount)->minus($allocated)));

        return $receipt;
    }

    public function sod(CustomerReceipt $receipt, string $userId): array
    {
        return $this->sod->documentAllowed($receipt, $userId, $this->workflow->profile()) + ['approval_required' => $this->workflow->profile()->approval_required];
    }

    // ------------------------------------------------------------------------------------------------ draft

    public function create(array $data, User $actor): CustomerReceipt
    {
        return DB::transaction(function () use ($data, $actor) {
            $prepared = $this->prepare($data, null, $actor, $this->workflow->profile());

            $receipt = new CustomerReceipt($prepared['header']);
            $receipt->forceFill($prepared['columns']);
            $receipt->status = CustomerReceipt::DRAFT;
            $receipt->created_by = $actor->id;
            $receipt->save();
            $this->writeAllocations($receipt, $prepared['allocations']);
            $this->workflow->created($receipt, $actor->id);
            $this->audit->record('receivables.customer_receipt.created', 'customer_receipt', $receipt->id, null, $this->summary($receipt));

            return $this->load($receipt->refresh());
        });
    }

    public function update(CustomerReceipt $receipt, array $data, User $actor): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $data, $actor) {
            $receipt = CustomerReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $this->assertDraft($receipt);
            $before = $this->summary($receipt);

            $prepared = $this->prepare($data, $receipt, $actor, $this->workflow->profile());
            $receipt->fill($prepared['header']);
            $receipt->forceFill($prepared['columns']);
            $receipt->save();
            $this->writeAllocations($receipt, $prepared['allocations']);
            $this->audit->record('receivables.customer_receipt.updated', 'customer_receipt', $receipt->id, $before, $this->summary($receipt->refresh()));

            return $this->load($receipt);
        });
    }

    // ------------------------------------------------------------------------------------------------ workflow

    public function submit(CustomerReceipt $receipt, User $actor): CustomerReceipt
    {
        return $this->load($this->workflow->submit($receipt, $actor, fn (CustomerReceipt $p) => $this->validateForPosting($p, $actor, false)));
    }

    public function approve(CustomerReceipt $receipt, User $actor): CustomerReceipt
    {
        return $this->load($this->workflow->approve($receipt, $actor, fn (CustomerReceipt $p) => $this->validateForPosting($p, $actor, false)));
    }

    public function reject(CustomerReceipt $receipt, User $actor, string $reason): CustomerReceipt
    {
        return $this->load($this->workflow->reject($receipt, $actor, $reason));
    }

    public function reopen(CustomerReceipt $receipt, User $actor): CustomerReceipt
    {
        return $this->load($this->workflow->reopen($receipt, $actor));
    }

    public function cancel(CustomerReceipt $receipt, User $actor, string $reason): CustomerReceipt
    {
        return $this->load($this->workflow->cancel($receipt, $actor, $reason));
    }

    // ------------------------------------------------------------------------------------------------ posting

    public function post(CustomerReceipt $receipt, User $actor): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $actor) {
            $receipt = CustomerReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $this->workflow->assertMayPost($receipt, $actor);
            $cash = $this->validateForPosting($receipt, $actor, true);

            $allocations = ArReceiptAllocation::query()->where('customer_receipt_id', $receipt->id)->orderBy('ar_invoice_id')->get();
            $invoices = ArInvoice::query()->whereIn('id', $allocations->pluck('ar_invoice_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->assertSettleable($receipt, $allocations->all(), $invoices->all());

            $payload = $this->payload($receipt, $allocations->all(), $invoices->all(), $cash->account_id, (int) $this->workflow->profile()->currency_scale);
            $dims = ['branch_id' => $receipt->branch_id, 'business_unit_id' => $receipt->business_unit_id, 'cost_center_id' => $receipt->cost_center_id];

            $event = $this->engine->postEvent(
                'CUSTOMER_RECEIPT', CustomerReceipt::DOCUMENT_TYPE, $receipt->id, $receipt->posting_date->toDateString(), $payload, $dims, 'POST',
                mb_substr("Penerimaan pelanggan {$receipt->customer->code}", 0, 500), $receipt->reference, $actor, $this->authority->canPostSoftClosed($actor),
            );
            $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);
            $this->assertJournal($journal, $receipt->amount);

            $this->workflow->markPosted($receipt, $actor, $journal, $event, 'CUSTOMER_RECEIPT', 'RCT', ['gl_account_id' => $cash->account_id]);
            $this->makeEffective($receipt, $allocations->all(), $invoices->all());

            return $this->load($receipt);
        });
    }

    /** Reverse the posted receipt through the shared mechanism and release its allocations, restoring the outstanding of every invoice, atomically. */
    public function reverse(CustomerReceipt $receipt, User $actor, string $reason, ?string $postingDate = null): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $actor, $reason, $postingDate) {
            $receipt = CustomerReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->status !== CustomerReceipt::POSTED) {
                throw new DomainException($receipt->status === CustomerReceipt::REVERSED ? 'This receipt has already been reversed.' : 'Only a posted receipt can be reversed.', $receipt->status === CustomerReceipt::REVERSED ? 'AR_RECEIPT_ALREADY_REVERSED' : 'AR_RECEIPT_NOT_POSTED', 409, ['status' => $receipt->status]);
            }
            $this->authority->assertLedgerWritable($actor);
            $allocations = ArReceiptAllocation::query()->where('customer_receipt_id', $receipt->id)->orderBy('ar_invoice_id')->get();
            ArInvoice::query()->whereIn('id', $allocations->pluck('ar_invoice_id'))->orderBy('id')->lockForUpdate()->get();

            $original = JournalEntry::query()->findOrFail($receipt->journal_entry_id);
            $reversal = $this->reversals->reverse($original, $actor, $reason, $postingDate, $receipt->document_number);

            $this->workflow->markReversed($receipt, $actor, $reversal, $reason);
            foreach ($allocations as $allocation) {
                $allocation->forceFill(['is_effective' => false, 'released_at' => now()])->save();
                $this->audit->record('receivables.customer_receipt.allocation_released', 'customer_receipt', $receipt->id, ['ar_invoice_id' => $allocation->ar_invoice_id, 'amount' => $allocation->amount, 'effective' => true], ['effective' => false, 'reason' => $reason]);
            }

            return $this->load($receipt);
        });
    }

    // ------------------------------------------------------------------------------------------------ validation

    /**
     * Everything posting needs that does not depend on the invoice locks: customer, cash/bank account, full allocation, period, readiness.
     * Returns the cash/bank account (locked FOR SHARE when $lock). Submit and approve run it without locks to refuse a hopeless document early.
     */
    private function validateForPosting(CustomerReceipt $receipt, User $actor, bool $lock): CashBankAccount
    {
        $this->authority->assertLedgerWritable($actor);
        $customer = Customer::query()->find($receipt->customer_id) ?? throw new DomainException('The customer no longer exists.', 'CUSTOMER_NOT_FOUND', 422);
        $this->customers->assertUsable($customer);
        $this->authority->assertModuleWritable($actor, 'ACCOUNTING_CASH_BANK', 'CASH_BANK_ACCOUNT');
        $cash = $this->cashAccounts->usable($receipt->cash_bank_account_id, $lock);

        $allocations = ArReceiptAllocation::query()->where('customer_receipt_id', $receipt->id)->get();
        $allocated = Money::sum($allocations->pluck('amount')->all());
        if ($allocations->isEmpty() || ! $allocated->isEqualTo($receipt->amount)) {
            throw new DomainException('A receipt must be allocated in full to customer invoices before it can be approved or posted.', 'RECEIPT_NOT_FULLY_ALLOCATED', 422, [
                'amount' => Money::str($receipt->amount), 'allocated' => Money::str($allocated),
            ]);
        }
        if (! $lock) {
            $invoices = ArInvoice::query()->whereIn('id', $allocations->pluck('ar_invoice_id'))->get()->keyBy('id');
            $this->assertSettleable($receipt, $allocations->all(), $invoices->all());
        }

        $postingDate = $receipt->posting_date->toDateString();
        $this->readiness->assertCanPost($postingDate, JournalEntry::SYSTEM);
        $this->periods->resolveForPosting($postingDate, $this->authority->canPostSoftClosed($actor));

        return $cash;
    }

    /**
     * Each allocation must settle a posted invoice of the receipt's customer, no later than the receipt, and no more than is outstanding.
     * Call it under the invoices' row locks when posting; `outstanding` is read from the committed effective allocations.
     *
     * @param  list<ArReceiptAllocation>  $allocations
     * @param  array<string,ArInvoice>  $invoices
     */
    private function assertSettleable(CustomerReceipt $receipt, array $allocations, array $invoices): void
    {
        $effective = ArReceiptAllocation::query()->whereIn('ar_invoice_id', array_keys($invoices))->where('is_effective', true)->groupBy('ar_invoice_id')
            ->selectRaw('ar_invoice_id, sum(amount) as paid')->pluck('paid', 'ar_invoice_id');
        $credited = ArCreditNote::query()->whereIn('ar_invoice_id', array_keys($invoices))->where('status', ArCreditNote::POSTED)->groupBy('ar_invoice_id')
            ->selectRaw('ar_invoice_id, sum(total_amount) as credited')->pluck('credited', 'ar_invoice_id');

        foreach ($allocations as $allocation) {
            $invoice = $invoices[$allocation->ar_invoice_id] ?? null;
            if ($invoice === null || $invoice->status !== ArInvoice::POSTED) {
                throw new DomainException('Only posted invoices can be settled.', 'AR_INVOICE_NOT_PAYABLE', 409, ['ar_invoice_id' => $allocation->ar_invoice_id, 'status' => $invoice?->status]);
            }
            if ($invoice->customer_id !== $receipt->customer_id) {
                throw new DomainException('A receipt settles invoices of its own customer only.', 'AR_ALLOCATION_CUSTOMER_MISMATCH', 422, ['ar_invoice_id' => $invoice->id]);
            }
            if ($invoice->posting_date->toDateString() > $receipt->posting_date->toDateString()) {
                throw new DomainException('A receipt cannot be posted before the invoice it settles.', 'AR_RECEIPT_BEFORE_INVOICE', 422, ['document_number' => $invoice->document_number]);
            }
            $outstanding = BigDecimal::of($invoice->total_amount)->minus($effective[$invoice->id] ?? '0')->minus($credited[$invoice->id] ?? '0');
            if (BigDecimal::of($allocation->amount)->isGreaterThan($outstanding)) {
                throw new DomainException('The allocation exceeds what is outstanding on the invoice.', 'AR_ALLOCATION_EXCEEDS_OUTSTANDING', 409, [
                    'document_number' => $invoice->document_number, 'outstanding' => Money::str($outstanding), 'allocation' => Money::str($allocation->amount),
                ]);
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ preparation

    /**
     * @return array{header:array<string,mixed>,columns:array<string,mixed>,allocations:list<array{invoice:ArInvoice,amount:BigDecimal}>}
     */
    private function prepare(array $data, ?CustomerReceipt $existing, User $actor, AccountingProfile $profile): array
    {
        $scale = (int) $profile->currency_scale;
        $field = fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);
        $date = fn (mixed $v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;

        $customerId = $data['customer_id'] ?? $existing?->customer_id ?? throw new DomainException('The customer is required.', 'CUSTOMER_REQUIRED', 422);
        $customer = Customer::query()->find($customerId) ?? throw new DomainException('The customer does not exist.', 'CUSTOMER_NOT_FOUND', 422, ['field' => 'customer_id']);
        if ($existing === null || $existing->customer_id !== $customer->id) {
            $this->customers->assertUsable($customer);
        }
        if ($existing !== null && $existing->customer_id !== $customer->id && ! array_key_exists('allocations', $data) && ! ($data['auto_allocate'] ?? false)) {
            throw new DomainException('Changing the customer requires new allocations.', 'AR_ALLOCATION_CUSTOMER_MISMATCH', 422, ['field' => 'allocations']);
        }
        if (isset($data['currency']) && $data['currency'] !== $profile->functional_currency) {
            throw new DomainException("OA2 books in the functional currency ({$profile->functional_currency}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'currency']);
        }

        $cashId = $data['cash_bank_account_id'] ?? $existing?->cash_bank_account_id ?? throw new DomainException('The cash or bank account is required.', 'CASH_BANK_ACCOUNT_REQUIRED', 422, ['field' => 'cash_bank_account_id']);
        $cash = $this->cashAccounts->usable($cashId);

        $receiptDate = $date($field('receipt_date')) ?? throw new DomainException('The receipt date is required.', 'RECEIPT_DATE_REQUIRED', 422);
        $postingDate = $date(array_key_exists('posting_date', $data) && $data['posting_date'] !== null ? $data['posting_date'] : ($existing?->posting_date ?? $receiptDate));
        $amount = Money::parse($field('amount'), $scale, 'amount');
        if ($amount->isLessThanOrEqualTo(0)) {
            throw new DomainException('The receipt amount must be greater than zero.', 'RECEIPT_AMOUNT_INVALID', 422, ['field' => 'amount']);
        }
        $method = $field('receipt_method');
        if ($method !== null && ! in_array($method, CustomerReceipt::METHODS, true)) {
            throw new DomainException('Unknown receipt method.', 'RECEIPT_METHOD_INVALID', 422, ['field' => 'receipt_method']);
        }

        $dims = $this->dimensions->resolve($field('branch_id', $cash->branch_id), $field('business_unit_id', $cash->business_unit_id), $field('cost_center_id'));
        $this->scope->assertWritable($dims['branch_id'], $dims['business_unit_id'], $existing?->created_by ?? $actor->id);

        $allocations = $this->allocations($data, $existing, $customer, $amount, $postingDate, $scale);

        return [
            'header' => collect($data)->only(['receipt_date', 'posting_date', 'receipt_method', 'reference', 'description'])->all(),
            'columns' => [
                'customer_id' => $customer->id, 'cash_bank_account_id' => $cash->id, 'currency' => $profile->functional_currency, 'amount' => Money::str($amount),
                'receipt_date' => $receiptDate, 'posting_date' => $postingDate,
                'branch_id' => $dims['branch_id'], 'business_unit_id' => $dims['business_unit_id'], 'cost_center_id' => $dims['cost_center_id'],
            ],
            'allocations' => $allocations,
        ];
    }

    /**
     * Apply $amount to the customer's open invoices, oldest due date first, each no more than is outstanding and none later than the
     * posting date. The remainder (if the open invoices are fewer than the amount) is left unallocated and blocks approval.
     *
     * @return list<array{ar_invoice_id:string,amount:string}>
     */
    public function suggest(Customer $customer, BigDecimal $amount, ?string $postingDate = null): array
    {
        $rows = [];
        $left = $amount;
        foreach ($this->subledger->openInvoices($customer->id) as $invoice) {
            if ($left->isLessThanOrEqualTo(0)) {
                break;
            }
            if ($postingDate !== null && $invoice->posting_date->toDateString() > $postingDate) {
                continue;
            }
            $outstanding = BigDecimal::of($invoice->outstanding_amount);
            $take = $outstanding->isGreaterThan($left) ? $left : $outstanding;
            $rows[] = ['ar_invoice_id' => $invoice->id, 'amount' => Money::str($take)];
            $left = $left->minus($take);
        }

        return $rows;
    }

    /** @return list<array{invoice:ArInvoice,amount:BigDecimal}> */
    private function allocations(array $data, ?CustomerReceipt $existing, Customer $customer, BigDecimal $amount, string $postingDate, int $scale): array
    {
        $rows = [];
        if ($data['auto_allocate'] ?? false) {
            $rows = $this->suggest($customer, $amount, $postingDate);
        } elseif (array_key_exists('allocations', $data)) {
            $rows = (array) $data['allocations'];
        } elseif ($existing !== null) {
            $rows = $existing->allocations()->get()->map(fn ($a) => ['ar_invoice_id' => $a->ar_invoice_id, 'amount' => $a->amount])->all();
        }

        $out = [];
        $seen = [];
        $total = BigDecimal::zero();
        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            $invoice = ArInvoice::query()->find($row['ar_invoice_id'] ?? null);
            if ($invoice === null || ! $this->scope->visible($invoice)) {
                throw new DomainException('The invoice does not exist.', 'AR_INVOICE_NOT_FOUND', 422, ['allocation' => $n]);
            }
            if (isset($seen[$invoice->id])) {
                throw new DomainException('An invoice can be allocated once per receipt.', 'AR_ALLOCATION_DUPLICATE', 422, ['allocation' => $n]);
            }
            $seen[$invoice->id] = true;
            if ($invoice->customer_id !== $customer->id) {
                throw new DomainException('A receipt settles invoices of its own customer only.', 'AR_ALLOCATION_CUSTOMER_MISMATCH', 422, ['allocation' => $n]);
            }
            if ($invoice->status !== ArInvoice::POSTED) {
                throw new DomainException('Only posted invoices can be settled.', 'AR_INVOICE_NOT_PAYABLE', 422, ['allocation' => $n, 'status' => $invoice->status]);
            }
            if ($invoice->posting_date->toDateString() > $postingDate) {
                throw new DomainException('A receipt cannot be posted before the invoice it settles.', 'AR_RECEIPT_BEFORE_INVOICE', 422, ['allocation' => $n, 'document_number' => $invoice->document_number]);
            }
            $part = Money::parse($row['amount'] ?? null, $scale, 'amount', $n);
            if ($part->isLessThanOrEqualTo(0)) {
                throw new DomainException('An allocation must be greater than zero.', 'AR_ALLOCATION_INVALID', 422, ['allocation' => $n]);
            }
            $paid = ArReceiptAllocation::query()->where('ar_invoice_id', $invoice->id)->where('is_effective', true)->sum('amount');
            $credited = ArCreditNote::query()->where('ar_invoice_id', $invoice->id)->where('status', ArCreditNote::POSTED)->sum('total_amount');
            $outstanding = BigDecimal::of($invoice->total_amount)->minus((string) $paid)->minus((string) $credited);
            if ($part->isGreaterThan($outstanding)) {
                throw new DomainException('The allocation exceeds what is outstanding on the invoice.', 'AR_ALLOCATION_EXCEEDS_OUTSTANDING', 422, [
                    'allocation' => $n, 'document_number' => $invoice->document_number, 'outstanding' => Money::str($outstanding),
                ]);
            }
            $total = $total->plus($part);
            $out[] = ['invoice' => $invoice, 'amount' => $part];
        }
        if ($total->isGreaterThan($amount)) {
            throw new DomainException('The allocations exceed the receipt amount.', 'AR_ALLOCATION_EXCEEDS_RECEIPT', 422, ['amount' => Money::str($amount), 'allocated' => Money::str($total)]);
        }

        return $out;
    }

    /** @param list<array{invoice:ArInvoice,amount:BigDecimal}> $allocations */
    private function writeAllocations(CustomerReceipt $receipt, array $allocations): void
    {
        ArReceiptAllocation::query()->where('customer_receipt_id', $receipt->id)->delete();
        foreach ($allocations as $row) {
            (new ArReceiptAllocation)->forceFill([
                'id' => (string) Str::uuid7(), 'customer_receipt_id' => $receipt->id, 'ar_invoice_id' => $row['invoice']->id, 'amount' => Money::str($row['amount']),
            ])->save();
        }
    }

    // ------------------------------------------------------------------------------------------------ posting payload

    /**
     * The business fact the engine posts: the amount, the cash/bank GL account named by the document, and the receivable accounts to credit
     * (the ones the settled invoices were booked to, grouped by account), so the control account of every invoice is relieved exactly.
     *
     * @param  list<ArReceiptAllocation>  $allocations
     * @param  array<string,ArInvoice>  $invoices
     * @return array<string,mixed>
     */
    private function payload(CustomerReceipt $receipt, array $allocations, array $invoices, string $cashGlAccountId, int $scale): array
    {
        $byAccount = [];
        foreach ($allocations as $allocation) {
            $account = $invoices[$allocation->ar_invoice_id]->receivable_account_id;
            $byAccount[$account]['amount'] = ($byAccount[$account]['amount'] ?? BigDecimal::zero())->plus($allocation->amount);
            $byAccount[$account]['documents'][] = $invoices[$allocation->ar_invoice_id]->document_number;
        }
        $parts = [];
        foreach ($byAccount as $accountId => $group) {
            $parts[] = ['amount' => Money::str($group['amount']), 'account_id' => $accountId, 'description' => mb_substr('Pelunasan piutang '.implode(', ', $group['documents']), 0, 255)];
        }

        return [
            'amount' => Money::str($receipt->amount),
            'role_accounts' => ['CASH_BANK_ACCOUNT' => $cashGlAccountId],
            'distribution' => ['amount@ACCOUNTS_RECEIVABLE' => $parts],
        ];
    }

    /** The journal the rule built must debit the cash/bank account and credit the receivable accounts by exactly the receipt amount. */
    private function assertJournal(JournalEntry $journal, string $amount): void
    {
        $lines = collect($journal->posting_snapshot['lines'] ?? []);
        $receivable = Money::sum($lines->where('account_role', 'ACCOUNTS_RECEIVABLE')->where('side', 'CREDIT')->pluck('amount')->all());
        $cash = Money::sum($lines->where('account_role', 'CASH_BANK_ACCOUNT')->where('side', 'DEBIT')->pluck('amount')->all());
        if (! $receivable->isEqualTo($amount) || ! $cash->isEqualTo($amount) || $lines->count() !== $lines->whereIn('account_role', ['ACCOUNTS_RECEIVABLE', 'CASH_BANK_ACCOUNT'])->count()) {
            throw new DomainException('The customer receipt posting rule must debit the cash and bank role and credit the accounts receivable role with the receipt amount.', 'AR_POSTING_RULE_INVALID', 422);
        }
    }

    /** Make the allocations effective after the receipt is POSTED (the database checks the invoice locks, the remaining amount and the full allocation at commit). */
    private function makeEffective(CustomerReceipt $receipt, array $allocations, array $invoices): void
    {
        foreach ($allocations as $allocation) {
            $allocation->forceFill(['is_effective' => true, 'effective_at' => now()])->save();
            $this->audit->record('receivables.customer_receipt.allocated', 'customer_receipt', $receipt->id, null, [
                'ar_invoice_id' => $allocation->ar_invoice_id, 'invoice_number' => $invoices[$allocation->ar_invoice_id]->document_number, 'amount' => $allocation->amount,
            ]);
        }
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function assertDraft(CustomerReceipt $receipt): void
    {
        if ($receipt->status !== CustomerReceipt::DRAFT) {
            throw new DomainException("A {$receipt->status} receipt cannot be edited.", in_array($receipt->status, [CustomerReceipt::POSTED, CustomerReceipt::REVERSED], true) ? 'DOCUMENT_IMMUTABLE' : 'DOCUMENT_NOT_DRAFT', 409, ['status' => $receipt->status]);
        }
    }

    private function summary(CustomerReceipt $receipt): array
    {
        return $receipt->only(['document_number', 'customer_id', 'cash_bank_account_id', 'status', 'receipt_date', 'posting_date', 'amount']);
    }
}
