<?php

namespace App\Domain\Payables\Services;

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
use App\Domain\Payables\Models\ApInvoice;
use App\Domain\Payables\Models\ApPaymentAllocation;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Models\VendorPayment;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vendor payments. A payment is prepared as a draft with its allocations to posted vendor invoices; it posts as one atomic unit:
 * lock the payment, the cash/bank account (shared) and every invoice it settles (in id order), re-check what is outstanding under those
 * locks, hand the business fact to `PostingEngine::postEvent` (VENDOR_PAYMENT: debit the payable accounts the invoices were booked
 * to, credit the cash/bank GL account), and only then make the allocations effective. A reversal reverses the journal through the
 * shared mechanism and releases the allocations in the same transaction. Outstanding is derived (see ApSubledgerService); no
 * balance is ever written. A payment is allocated in full: vendor advances and prepayments do not exist in OA2, so nothing can make
 * a payable negative.
 */
class VendorPaymentService
{
    public function __construct(
        private readonly DocumentWorkflow $workflow,
        private readonly VendorService $vendors,
        private readonly CashBankAccountService $cashAccounts,
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
    ) {}

    // ------------------------------------------------------------------------------------------------ queries

    public function query(array $filter = []): Builder
    {
        $allocated = '(select coalesce(sum(a.amount), 0) from ap_payment_allocations a where a.tenant_id = vendor_payments.tenant_id and a.vendor_payment_id = vendor_payments.id)';
        $query = VendorPayment::query()->with(['vendor', 'cashBankAccount', 'branch', 'creator'])->addSelect('vendor_payments.*')->selectRaw("{$allocated} as allocated_amount");
        $this->scope->restrict($query->getQuery(), 'vendor_payments');

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('vendor_payments.status', $v))
            ->when($filter['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_payments.vendor_id', $v))
            ->when($filter['cash_bank_account_id'] ?? null, fn ($q, $v) => $q->where('vendor_payments.cash_bank_account_id', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('vendor_payments.branch_id', $v))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $v) => $q->where('vendor_payments.business_unit_id', $v))
            ->when($filter['cost_center_id'] ?? null, fn ($q, $v) => $q->where('vendor_payments.cost_center_id', $v))
            ->when($filter['payment_from'] ?? null, fn ($q, $v) => $q->whereDate('vendor_payments.payment_date', '>=', $v))
            ->when($filter['payment_to'] ?? null, fn ($q, $v) => $q->whereDate('vendor_payments.payment_date', '<=', $v))
            ->when($filter['posting_from'] ?? null, fn ($q, $v) => $q->whereDate('vendor_payments.posting_date', '>=', $v))
            ->when($filter['posting_to'] ?? null, fn ($q, $v) => $q->whereDate('vendor_payments.posting_date', '<=', $v))
            ->when($filter['mine'] ?? false, fn ($q) => $q->where('vendor_payments.created_by', $this->context->user()?->id))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(coalesce(vendor_payments.document_number, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(vendor_payments.reference, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(vendor_payments.description, \'\')) like ?', [$like]));
            })
            ->orderByDesc('vendor_payments.posting_date')->orderByDesc('vendor_payments.created_at');
    }

    public function load(VendorPayment $payment): VendorPayment
    {
        $payment->load(['vendor', 'cashBankAccount', 'glAccount', 'branch', 'businessUnit', 'costCenter', 'creator', 'transitions.actor', 'allocations.invoice']);
        $allocated = Money::sum($payment->allocations->pluck('amount')->all());
        $payment->setAttribute('allocated_amount', Money::str($allocated));
        $payment->setAttribute('unallocated_amount', Money::str(BigDecimal::of($payment->amount)->minus($allocated)));

        return $payment;
    }

    public function sod(VendorPayment $payment, string $userId): array
    {
        return $this->sod->documentAllowed($payment, $userId, $this->workflow->profile()) + ['approval_required' => $this->workflow->profile()->approval_required];
    }

    // ------------------------------------------------------------------------------------------------ draft

    public function create(array $data, User $actor): VendorPayment
    {
        return DB::transaction(function () use ($data, $actor) {
            $prepared = $this->prepare($data, null, $actor, $this->workflow->profile());

            $payment = new VendorPayment($prepared['header']);
            $payment->forceFill($prepared['columns']);
            $payment->status = VendorPayment::DRAFT;
            $payment->created_by = $actor->id;
            $payment->save();
            $this->writeAllocations($payment, $prepared['allocations']);
            $this->workflow->created($payment, $actor->id);
            $this->audit->record('payables.vendor_payment.created', 'vendor_payment', $payment->id, null, $this->summary($payment));

            return $this->load($payment->refresh());
        });
    }

    public function update(VendorPayment $payment, array $data, User $actor): VendorPayment
    {
        return DB::transaction(function () use ($payment, $data, $actor) {
            $payment = VendorPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->assertDraft($payment);
            $before = $this->summary($payment);

            $prepared = $this->prepare($data, $payment, $actor, $this->workflow->profile());
            $payment->fill($prepared['header']);
            $payment->forceFill($prepared['columns']);
            $payment->save();
            $this->writeAllocations($payment, $prepared['allocations']);
            $this->audit->record('payables.vendor_payment.updated', 'vendor_payment', $payment->id, $before, $this->summary($payment->refresh()));

            return $this->load($payment);
        });
    }

    // ------------------------------------------------------------------------------------------------ workflow

    public function submit(VendorPayment $payment, User $actor): VendorPayment
    {
        return $this->load($this->workflow->submit($payment, $actor, fn (VendorPayment $p) => $this->validateForPosting($p, $actor, false)));
    }

    public function approve(VendorPayment $payment, User $actor): VendorPayment
    {
        return $this->load($this->workflow->approve($payment, $actor, fn (VendorPayment $p) => $this->validateForPosting($p, $actor, false)));
    }

    public function reject(VendorPayment $payment, User $actor, string $reason): VendorPayment
    {
        return $this->load($this->workflow->reject($payment, $actor, $reason));
    }

    public function reopen(VendorPayment $payment, User $actor): VendorPayment
    {
        return $this->load($this->workflow->reopen($payment, $actor));
    }

    public function cancel(VendorPayment $payment, User $actor, string $reason): VendorPayment
    {
        return $this->load($this->workflow->cancel($payment, $actor, $reason));
    }

    // ------------------------------------------------------------------------------------------------ posting

    public function post(VendorPayment $payment, User $actor): VendorPayment
    {
        return DB::transaction(function () use ($payment, $actor) {
            $payment = VendorPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->workflow->assertMayPost($payment, $actor);
            $cash = $this->validateForPosting($payment, $actor, true);

            $allocations = ApPaymentAllocation::query()->where('vendor_payment_id', $payment->id)->orderBy('ap_invoice_id')->get();
            $invoices = ApInvoice::query()->whereIn('id', $allocations->pluck('ap_invoice_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->assertSettleable($payment, $allocations->all(), $invoices->all());

            $payload = $this->payload($payment, $allocations->all(), $invoices->all(), $cash->account_id, (int) $this->workflow->profile()->currency_scale);
            $dims = ['branch_id' => $payment->branch_id, 'business_unit_id' => $payment->business_unit_id, 'cost_center_id' => $payment->cost_center_id];

            $event = $this->engine->postEvent(
                'VENDOR_PAYMENT', VendorPayment::DOCUMENT_TYPE, $payment->id, $payment->posting_date->toDateString(), $payload, $dims, 'POST',
                mb_substr("Pembayaran vendor {$payment->vendor->code}", 0, 500), $payment->reference, $actor, $this->authority->canPostSoftClosed($actor),
            );
            $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);
            $this->assertJournal($journal, $payment->amount);

            $this->workflow->markPosted($payment, $actor, $journal, $event, 'VENDOR_PAYMENT', 'PAY', ['gl_account_id' => $cash->account_id]);
            $this->makeEffective($payment, $allocations->all(), $invoices->all());

            return $this->load($payment);
        });
    }

    /** Reverse the posted payment through the shared mechanism and release its allocations, restoring the outstanding of every invoice, atomically. */
    public function reverse(VendorPayment $payment, User $actor, string $reason, ?string $postingDate = null): VendorPayment
    {
        return DB::transaction(function () use ($payment, $actor, $reason, $postingDate) {
            $payment = VendorPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== VendorPayment::POSTED) {
                throw new DomainException($payment->status === VendorPayment::REVERSED ? 'This payment has already been reversed.' : 'Only a posted payment can be reversed.', $payment->status === VendorPayment::REVERSED ? 'AP_PAYMENT_ALREADY_REVERSED' : 'AP_PAYMENT_NOT_POSTED', 409, ['status' => $payment->status]);
            }
            $this->authority->assertLedgerWritable($actor);
            $allocations = ApPaymentAllocation::query()->where('vendor_payment_id', $payment->id)->orderBy('ap_invoice_id')->get();
            ApInvoice::query()->whereIn('id', $allocations->pluck('ap_invoice_id'))->orderBy('id')->lockForUpdate()->get();

            $original = JournalEntry::query()->findOrFail($payment->journal_entry_id);
            $reversal = $this->reversals->reverse($original, $actor, $reason, $postingDate, $payment->document_number);

            $this->workflow->markReversed($payment, $actor, $reversal, $reason);
            foreach ($allocations as $allocation) {
                $allocation->forceFill(['is_effective' => false, 'released_at' => now()])->save();
                $this->audit->record('payables.vendor_payment.allocation_released', 'vendor_payment', $payment->id, ['ap_invoice_id' => $allocation->ap_invoice_id, 'amount' => $allocation->amount, 'effective' => true], ['effective' => false, 'reason' => $reason]);
            }

            return $this->load($payment);
        });
    }

    // ------------------------------------------------------------------------------------------------ validation

    /**
     * Everything posting needs that does not depend on the invoice locks: vendor, cash/bank account, full allocation, period, readiness.
     * Returns the cash/bank account (locked FOR SHARE when $lock). Submit and approve run it without locks to refuse a hopeless document early.
     */
    private function validateForPosting(VendorPayment $payment, User $actor, bool $lock): CashBankAccount
    {
        $this->authority->assertLedgerWritable($actor);
        $vendor = Vendor::query()->find($payment->vendor_id) ?? throw new DomainException('The vendor no longer exists.', 'VENDOR_NOT_FOUND', 422);
        $this->vendors->assertUsable($vendor);
        $this->authority->assertModuleWritable($actor, 'ACCOUNTING_CASH_BANK', 'CASH_BANK_ACCOUNT');
        $cash = $this->cashAccounts->usable($payment->cash_bank_account_id, $lock);

        $allocations = ApPaymentAllocation::query()->where('vendor_payment_id', $payment->id)->get();
        $allocated = Money::sum($allocations->pluck('amount')->all());
        if ($allocations->isEmpty() || ! $allocated->isEqualTo($payment->amount)) {
            throw new DomainException('A payment must be allocated in full to vendor invoices before it can be approved or posted.', 'PAYMENT_NOT_FULLY_ALLOCATED', 422, [
                'amount' => Money::str($payment->amount), 'allocated' => Money::str($allocated),
            ]);
        }
        if (! $lock) {
            $invoices = ApInvoice::query()->whereIn('id', $allocations->pluck('ap_invoice_id'))->get()->keyBy('id');
            $this->assertSettleable($payment, $allocations->all(), $invoices->all());
        }

        $postingDate = $payment->posting_date->toDateString();
        $this->readiness->assertCanPost($postingDate, JournalEntry::SYSTEM);
        $this->periods->resolveForPosting($postingDate, $this->authority->canPostSoftClosed($actor));

        return $cash;
    }

    /**
     * Each allocation must settle a posted invoice of the payment's vendor, no later than the payment, and no more than is outstanding.
     * Call it under the invoices' row locks when posting; `outstanding` is read from the committed effective allocations.
     *
     * @param  list<ApPaymentAllocation>  $allocations
     * @param  array<string,ApInvoice>  $invoices
     */
    private function assertSettleable(VendorPayment $payment, array $allocations, array $invoices): void
    {
        $effective = ApPaymentAllocation::query()->whereIn('ap_invoice_id', array_keys($invoices))->where('is_effective', true)->groupBy('ap_invoice_id')
            ->selectRaw('ap_invoice_id, sum(amount) as paid')->pluck('paid', 'ap_invoice_id');

        foreach ($allocations as $allocation) {
            $invoice = $invoices[$allocation->ap_invoice_id] ?? null;
            if ($invoice === null || $invoice->status !== ApInvoice::POSTED) {
                throw new DomainException('Only posted invoices can be settled.', 'AP_INVOICE_NOT_PAYABLE', 409, ['ap_invoice_id' => $allocation->ap_invoice_id, 'status' => $invoice?->status]);
            }
            if ($invoice->vendor_id !== $payment->vendor_id) {
                throw new DomainException('A payment settles invoices of its own vendor only.', 'AP_ALLOCATION_VENDOR_MISMATCH', 422, ['ap_invoice_id' => $invoice->id]);
            }
            if ($invoice->posting_date->toDateString() > $payment->posting_date->toDateString()) {
                throw new DomainException('A payment cannot be posted before the invoice it settles.', 'AP_PAYMENT_BEFORE_INVOICE', 422, ['document_number' => $invoice->document_number]);
            }
            $outstanding = BigDecimal::of($invoice->total_amount)->minus($effective[$invoice->id] ?? '0');
            if (BigDecimal::of($allocation->amount)->isGreaterThan($outstanding)) {
                throw new DomainException('The allocation exceeds what is outstanding on the invoice.', 'AP_ALLOCATION_EXCEEDS_OUTSTANDING', 409, [
                    'document_number' => $invoice->document_number, 'outstanding' => Money::str($outstanding), 'allocation' => Money::str($allocation->amount),
                ]);
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ preparation

    /**
     * @return array{header:array<string,mixed>,columns:array<string,mixed>,allocations:list<array{invoice:ApInvoice,amount:BigDecimal}>}
     */
    private function prepare(array $data, ?VendorPayment $existing, User $actor, AccountingProfile $profile): array
    {
        $scale = (int) $profile->currency_scale;
        $field = fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);
        $date = fn (mixed $v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;

        $vendorId = $data['vendor_id'] ?? $existing?->vendor_id ?? throw new DomainException('The vendor is required.', 'VENDOR_REQUIRED', 422);
        $vendor = Vendor::query()->find($vendorId) ?? throw new DomainException('The vendor does not exist.', 'VENDOR_NOT_FOUND', 422, ['field' => 'vendor_id']);
        if ($existing === null || $existing->vendor_id !== $vendor->id) {
            $this->vendors->assertUsable($vendor);
        }
        if ($existing !== null && $existing->vendor_id !== $vendor->id && ! array_key_exists('allocations', $data) && ! ($data['auto_allocate'] ?? false)) {
            throw new DomainException('Changing the vendor requires new allocations.', 'AP_ALLOCATION_VENDOR_MISMATCH', 422, ['field' => 'allocations']);
        }
        if (isset($data['currency']) && $data['currency'] !== $profile->functional_currency) {
            throw new DomainException("OA2 books in the functional currency ({$profile->functional_currency}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'currency']);
        }

        $cashId = $data['cash_bank_account_id'] ?? $existing?->cash_bank_account_id ?? throw new DomainException('The cash or bank account is required.', 'CASH_BANK_ACCOUNT_REQUIRED', 422, ['field' => 'cash_bank_account_id']);
        $cash = $this->cashAccounts->usable($cashId);

        $paymentDate = $date($field('payment_date')) ?? throw new DomainException('The payment date is required.', 'PAYMENT_DATE_REQUIRED', 422);
        $postingDate = $date(array_key_exists('posting_date', $data) && $data['posting_date'] !== null ? $data['posting_date'] : ($existing?->posting_date ?? $paymentDate));
        $amount = Money::parse($field('amount'), $scale, 'amount');
        if ($amount->isLessThanOrEqualTo(0)) {
            throw new DomainException('The payment amount must be greater than zero.', 'PAYMENT_AMOUNT_INVALID', 422, ['field' => 'amount']);
        }
        $method = $field('payment_method');
        if ($method !== null && ! in_array($method, VendorPayment::METHODS, true)) {
            throw new DomainException('Unknown payment method.', 'PAYMENT_METHOD_INVALID', 422, ['field' => 'payment_method']);
        }

        $dims = $this->dimensions->resolve($field('branch_id', $cash->branch_id), $field('business_unit_id', $cash->business_unit_id), $field('cost_center_id'));
        $this->scope->assertWritable($dims['branch_id'], $dims['business_unit_id'], $existing?->created_by ?? $actor->id);

        $allocations = $this->allocations($data, $existing, $vendor, $amount, $postingDate, $scale);

        return [
            'header' => collect($data)->only(['payment_date', 'posting_date', 'payment_method', 'reference', 'description'])->all(),
            'columns' => [
                'vendor_id' => $vendor->id, 'cash_bank_account_id' => $cash->id, 'currency' => $profile->functional_currency, 'amount' => Money::str($amount),
                'payment_date' => $paymentDate, 'posting_date' => $postingDate,
                'branch_id' => $dims['branch_id'], 'business_unit_id' => $dims['business_unit_id'], 'cost_center_id' => $dims['cost_center_id'],
            ],
            'allocations' => $allocations,
        ];
    }

    /**
     * Apply $amount to the vendor's open invoices, oldest due date first, each no more than is outstanding and none later than the
     * posting date. The remainder (if the open invoices are fewer than the amount) is left unallocated and blocks approval.
     *
     * @return list<array{ap_invoice_id:string,amount:string}>
     */
    public function suggest(Vendor $vendor, BigDecimal $amount, ?string $postingDate = null): array
    {
        $rows = [];
        $left = $amount;
        foreach ($this->subledger->openInvoices($vendor->id) as $invoice) {
            if ($left->isLessThanOrEqualTo(0)) {
                break;
            }
            if ($postingDate !== null && $invoice->posting_date->toDateString() > $postingDate) {
                continue;
            }
            $outstanding = BigDecimal::of($invoice->outstanding_amount);
            $take = $outstanding->isGreaterThan($left) ? $left : $outstanding;
            $rows[] = ['ap_invoice_id' => $invoice->id, 'amount' => Money::str($take)];
            $left = $left->minus($take);
        }

        return $rows;
    }

    /** @return list<array{invoice:ApInvoice,amount:BigDecimal}> */
    private function allocations(array $data, ?VendorPayment $existing, Vendor $vendor, BigDecimal $amount, string $postingDate, int $scale): array
    {
        $rows = [];
        if ($data['auto_allocate'] ?? false) {
            $rows = $this->suggest($vendor, $amount, $postingDate);
        } elseif (array_key_exists('allocations', $data)) {
            $rows = (array) $data['allocations'];
        } elseif ($existing !== null) {
            $rows = $existing->allocations()->get()->map(fn ($a) => ['ap_invoice_id' => $a->ap_invoice_id, 'amount' => $a->amount])->all();
        }

        $out = [];
        $seen = [];
        $total = BigDecimal::zero();
        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            $invoice = ApInvoice::query()->find($row['ap_invoice_id'] ?? null);
            if ($invoice === null || ! $this->scope->visible($invoice)) {
                throw new DomainException('The invoice does not exist.', 'AP_INVOICE_NOT_FOUND', 422, ['allocation' => $n]);
            }
            if (isset($seen[$invoice->id])) {
                throw new DomainException('An invoice can be allocated once per payment.', 'AP_ALLOCATION_DUPLICATE', 422, ['allocation' => $n]);
            }
            $seen[$invoice->id] = true;
            if ($invoice->vendor_id !== $vendor->id) {
                throw new DomainException('A payment settles invoices of its own vendor only.', 'AP_ALLOCATION_VENDOR_MISMATCH', 422, ['allocation' => $n]);
            }
            if ($invoice->status !== ApInvoice::POSTED) {
                throw new DomainException('Only posted invoices can be settled.', 'AP_INVOICE_NOT_PAYABLE', 422, ['allocation' => $n, 'status' => $invoice->status]);
            }
            if ($invoice->posting_date->toDateString() > $postingDate) {
                throw new DomainException('A payment cannot be posted before the invoice it settles.', 'AP_PAYMENT_BEFORE_INVOICE', 422, ['allocation' => $n, 'document_number' => $invoice->document_number]);
            }
            $part = Money::parse($row['amount'] ?? null, $scale, 'amount', $n);
            if ($part->isLessThanOrEqualTo(0)) {
                throw new DomainException('An allocation must be greater than zero.', 'AP_ALLOCATION_INVALID', 422, ['allocation' => $n]);
            }
            $paid = ApPaymentAllocation::query()->where('ap_invoice_id', $invoice->id)->where('is_effective', true)->sum('amount');
            $outstanding = BigDecimal::of($invoice->total_amount)->minus((string) $paid);
            if ($part->isGreaterThan($outstanding)) {
                throw new DomainException('The allocation exceeds what is outstanding on the invoice.', 'AP_ALLOCATION_EXCEEDS_OUTSTANDING', 422, [
                    'allocation' => $n, 'document_number' => $invoice->document_number, 'outstanding' => Money::str($outstanding),
                ]);
            }
            $total = $total->plus($part);
            $out[] = ['invoice' => $invoice, 'amount' => $part];
        }
        if ($total->isGreaterThan($amount)) {
            throw new DomainException('The allocations exceed the payment amount.', 'AP_ALLOCATION_EXCEEDS_PAYMENT', 422, ['amount' => Money::str($amount), 'allocated' => Money::str($total)]);
        }

        return $out;
    }

    /** @param list<array{invoice:ApInvoice,amount:BigDecimal}> $allocations */
    private function writeAllocations(VendorPayment $payment, array $allocations): void
    {
        ApPaymentAllocation::query()->where('vendor_payment_id', $payment->id)->delete();
        foreach ($allocations as $row) {
            (new ApPaymentAllocation)->forceFill([
                'id' => (string) Str::uuid7(), 'vendor_payment_id' => $payment->id, 'ap_invoice_id' => $row['invoice']->id, 'amount' => Money::str($row['amount']),
            ])->save();
        }
    }

    // ------------------------------------------------------------------------------------------------ posting payload

    /**
     * The business fact the engine posts: the amount, the cash/bank GL account named by the document, and the payable accounts to debit
     * (the ones the settled invoices were booked to, grouped by account), so the control account of every invoice is relieved exactly.
     *
     * @param  list<ApPaymentAllocation>  $allocations
     * @param  array<string,ApInvoice>  $invoices
     * @return array<string,mixed>
     */
    private function payload(VendorPayment $payment, array $allocations, array $invoices, string $cashGlAccountId, int $scale): array
    {
        $byAccount = [];
        foreach ($allocations as $allocation) {
            $account = $invoices[$allocation->ap_invoice_id]->payable_account_id;
            $byAccount[$account]['amount'] = ($byAccount[$account]['amount'] ?? BigDecimal::zero())->plus($allocation->amount);
            $byAccount[$account]['documents'][] = $invoices[$allocation->ap_invoice_id]->document_number;
        }
        $parts = [];
        foreach ($byAccount as $accountId => $group) {
            $parts[] = ['amount' => Money::str($group['amount']), 'account_id' => $accountId, 'description' => mb_substr('Pelunasan '.implode(', ', $group['documents']), 0, 255)];
        }

        return [
            'amount' => Money::str($payment->amount),
            'role_accounts' => ['CASH_BANK_ACCOUNT' => $cashGlAccountId],
            'distribution' => ['amount@ACCOUNTS_PAYABLE' => $parts],
        ];
    }

    /** The journal the rule built must debit the payable accounts and credit the cash/bank account by exactly the payment amount. */
    private function assertJournal(JournalEntry $journal, string $amount): void
    {
        $lines = collect($journal->posting_snapshot['lines'] ?? []);
        $payable = Money::sum($lines->where('account_role', 'ACCOUNTS_PAYABLE')->where('side', 'DEBIT')->pluck('amount')->all());
        $cash = Money::sum($lines->where('account_role', 'CASH_BANK_ACCOUNT')->where('side', 'CREDIT')->pluck('amount')->all());
        if (! $payable->isEqualTo($amount) || ! $cash->isEqualTo($amount) || $lines->count() !== $lines->whereIn('account_role', ['ACCOUNTS_PAYABLE', 'CASH_BANK_ACCOUNT'])->count()) {
            throw new DomainException('The vendor payment posting rule must debit the accounts payable role and credit the cash and bank role with the payment amount.', 'AP_POSTING_RULE_INVALID', 422);
        }
    }

    /** Make the allocations effective after the payment is POSTED (the database checks the invoice locks, the remaining amount and the full allocation at commit). */
    private function makeEffective(VendorPayment $payment, array $allocations, array $invoices): void
    {
        foreach ($allocations as $allocation) {
            $allocation->forceFill(['is_effective' => true, 'effective_at' => now()])->save();
            $this->audit->record('payables.vendor_payment.allocated', 'vendor_payment', $payment->id, null, [
                'ap_invoice_id' => $allocation->ap_invoice_id, 'invoice_number' => $invoices[$allocation->ap_invoice_id]->document_number, 'amount' => $allocation->amount,
            ]);
        }
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function assertDraft(VendorPayment $payment): void
    {
        if ($payment->status !== VendorPayment::DRAFT) {
            throw new DomainException("A {$payment->status} payment cannot be edited.", in_array($payment->status, [VendorPayment::POSTED, VendorPayment::REVERSED], true) ? 'DOCUMENT_IMMUTABLE' : 'DOCUMENT_NOT_DRAFT', 409, ['status' => $payment->status]);
        }
    }

    private function summary(VendorPayment $payment): array
    {
        return $payment->only(['document_number', 'vendor_id', 'cash_bank_account_id', 'status', 'payment_date', 'posting_date', 'amount']);
    }
}
