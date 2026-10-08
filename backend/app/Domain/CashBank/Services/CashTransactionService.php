<?php

namespace App\Domain\CashBank\Services;

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
use App\Domain\CashBank\Models\CashTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Controlled cash and bank payments and receipts outside AP and AR. This is not a money-moving endpoint: every transaction names its
 * purpose, its cash/bank account and one explicit counter account (never a control account, never another cash/bank account), is
 * prepared as a draft, and is posted by someone who may post, in an open period, through `PostingEngine::postEvent`
 * (CASH_PAYMENT: Dr counter, Cr cash/bank. CASH_RECEIPT: Dr cash/bank, Cr counter). Customer receipts and revenue recognition are OA3.
 */
class CashTransactionService
{
    private const EVENTS = [CashTransaction::PAYMENT => 'CASH_PAYMENT', CashTransaction::RECEIPT => 'CASH_RECEIPT'];

    private const SEQUENCES = [CashTransaction::PAYMENT => ['CASH_PAYMENT', 'CP'], CashTransaction::RECEIPT => ['CASH_RECEIPT', 'CR']];

    private const FEATURES = [CashTransaction::PAYMENT => 'PAYMENT', CashTransaction::RECEIPT => 'RECEIPT'];

    public function __construct(
        private readonly DocumentWorkflow $workflow,
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
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------------------------------------------ queries

    public function query(string $kind, array $filter = []): Builder
    {
        $query = CashTransaction::query()->where('cash_transactions.kind', $kind)->with(['cashBankAccount', 'counterAccount', 'branch', 'creator']);
        $this->scope->restrict($query->getQuery(), 'cash_transactions');

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('cash_transactions.status', $v))
            ->when($filter['cash_bank_account_id'] ?? null, fn ($q, $v) => $q->where('cash_transactions.cash_bank_account_id', $v))
            ->when($filter['counter_account_id'] ?? null, fn ($q, $v) => $q->where('cash_transactions.counter_account_id', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('cash_transactions.branch_id', $v))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $v) => $q->where('cash_transactions.business_unit_id', $v))
            ->when($filter['cost_center_id'] ?? null, fn ($q, $v) => $q->where('cash_transactions.cost_center_id', $v))
            ->when($filter['transaction_from'] ?? null, fn ($q, $v) => $q->whereDate('cash_transactions.transaction_date', '>=', $v))
            ->when($filter['transaction_to'] ?? null, fn ($q, $v) => $q->whereDate('cash_transactions.transaction_date', '<=', $v))
            ->when($filter['posting_from'] ?? null, fn ($q, $v) => $q->whereDate('cash_transactions.posting_date', '>=', $v))
            ->when($filter['posting_to'] ?? null, fn ($q, $v) => $q->whereDate('cash_transactions.posting_date', '<=', $v))
            ->when($filter['mine'] ?? false, fn ($q) => $q->where('cash_transactions.created_by', $this->context->user()?->id))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(coalesce(cash_transactions.document_number, \'\')) like ?', [$like])->orWhereRaw('lower(cash_transactions.purpose) like ?', [$like])
                    ->orWhereRaw('lower(cash_transactions.description) like ?', [$like])->orWhereRaw('lower(coalesce(cash_transactions.reference, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(cash_transactions.counterparty_name, \'\')) like ?', [$like]));
            })
            ->orderByDesc('cash_transactions.posting_date')->orderByDesc('cash_transactions.created_at');
    }

    public function load(CashTransaction $transaction): CashTransaction
    {
        return $transaction->load(['cashBankAccount', 'counterAccount', 'glAccount', 'branch', 'businessUnit', 'costCenter', 'creator', 'transitions.actor']);
    }

    public function sod(CashTransaction $transaction, string $userId): array
    {
        return $this->sod->documentAllowed($transaction, $userId, $this->workflow->profile());
    }

    // ------------------------------------------------------------------------------------------------ draft

    public function create(string $kind, array $data, User $actor): CashTransaction
    {
        return DB::transaction(function () use ($kind, $data, $actor) {
            $prepared = $this->prepare($kind, $data, null, $actor, $this->workflow->profile());

            $transaction = new CashTransaction($prepared['header']);
            $transaction->forceFill($prepared['columns']);
            $transaction->kind = $kind;
            $transaction->status = CashTransaction::DRAFT;
            $transaction->created_by = $actor->id;
            $transaction->save();
            $this->workflow->created($transaction, $actor->id);
            $this->audit->record('cash_bank.transaction.created', 'cash_transaction', $transaction->id, null, $this->summary($transaction));

            return $this->load($transaction->refresh());
        });
    }

    public function update(CashTransaction $transaction, array $data, User $actor): CashTransaction
    {
        return DB::transaction(function () use ($transaction, $data, $actor) {
            $transaction = CashTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $this->assertDraft($transaction);
            $before = $this->summary($transaction);

            $prepared = $this->prepare($transaction->kind, $data, $transaction, $actor, $this->workflow->profile());
            $transaction->fill($prepared['header']);
            $transaction->forceFill($prepared['columns']);
            $transaction->save();
            $this->audit->record('cash_bank.transaction.updated', 'cash_transaction', $transaction->id, $before, $this->summary($transaction->refresh()));

            return $this->load($transaction);
        });
    }

    public function cancel(CashTransaction $transaction, User $actor, string $reason): CashTransaction
    {
        return $this->load($this->workflow->cancel($transaction, $actor, $reason));
    }

    // ------------------------------------------------------------------------------------------------ posting

    /**
     * Post a draft in one atomic transaction: lock the transaction and the cash/bank account (FOR SHARE), hand the business fact to the
     * Posting Engine (rule -> journal, period check under lock), issue the number and link the journal. A replay or a concurrent second
     * request finds a POSTED document and is refused; the engine is idempotent on the transaction as well.
     */
    public function post(CashTransaction $transaction, User $actor): CashTransaction
    {
        return DB::transaction(function () use ($transaction, $actor) {
            $transaction = CashTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $this->workflow->assertMayPost($transaction, $actor, false);
            $cash = $this->validateForPosting($transaction, $actor, true);

            $payload = [
                'amount' => Money::str($transaction->amount),
                'role_accounts' => ['CASH_BANK_ACCOUNT' => $cash->account_id, 'DOCUMENT_ACCOUNT' => $transaction->counter_account_id],
            ];
            $dims = ['branch_id' => $transaction->branch_id, 'business_unit_id' => $transaction->business_unit_id, 'cost_center_id' => $transaction->cost_center_id];

            $event = $this->engine->postEvent(
                self::EVENTS[$transaction->kind], CashTransaction::DOCUMENT_TYPE, $transaction->id, $transaction->posting_date->toDateString(), $payload, $dims, 'POST',
                mb_substr(($transaction->kind === CashTransaction::PAYMENT ? 'Pembayaran kas/bank: ' : 'Penerimaan kas/bank: ').$transaction->purpose, 0, 500),
                $transaction->reference, $actor, $this->authority->canPostSoftClosed($actor),
            );
            $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);
            $this->assertJournal($transaction, $journal, $cash->account_id);

            [$sequence, $prefix] = self::SEQUENCES[$transaction->kind];

            return $this->load($this->workflow->markPosted($transaction, $actor, $journal, $event, $sequence, $prefix, ['gl_account_id' => $cash->account_id]));
        });
    }

    /** Reverse a posted transaction through the shared reversal mechanism (a new journal with the sides swapped), then mark it REVERSED, atomically. */
    public function reverse(CashTransaction $transaction, User $actor, string $reason, ?string $postingDate = null): CashTransaction
    {
        return DB::transaction(function () use ($transaction, $actor, $reason, $postingDate) {
            $transaction = CashTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($transaction->status !== CashTransaction::POSTED) {
                throw new DomainException($transaction->status === CashTransaction::REVERSED ? 'This transaction has already been reversed.' : 'Only a posted transaction can be reversed.', $transaction->status === CashTransaction::REVERSED ? 'CASH_TRANSACTION_ALREADY_REVERSED' : 'CASH_TRANSACTION_NOT_POSTED', 409, ['status' => $transaction->status]);
            }
            $this->authority->assertLedgerWritable($actor);
            $this->authority->assertModuleWritable($actor, 'ACCOUNTING_CASH_BANK', self::FEATURES[$transaction->kind]);

            $original = JournalEntry::query()->findOrFail($transaction->journal_entry_id);
            $reversal = $this->reversals->reverse($original, $actor, $reason, $postingDate, $transaction->document_number);

            return $this->load($this->workflow->markReversed($transaction, $actor, $reversal, $reason));
        });
    }

    // ------------------------------------------------------------------------------------------------ validation

    /** Everything posting needs: ledger and module writable, the accounts still usable, period open, readiness. Returns the cash/bank account (locked FOR SHARE when $lock). */
    private function validateForPosting(CashTransaction $transaction, User $actor, bool $lock): CashBankAccount
    {
        $this->authority->assertLedgerWritable($actor);
        $this->authority->assertModuleWritable($actor, 'ACCOUNTING_CASH_BANK', self::FEATURES[$transaction->kind]);
        $cash = $this->cashAccounts->usable($transaction->cash_bank_account_id, $lock);
        $this->counterAccount($transaction->counter_account_id, $cash->account_id);

        $postingDate = $transaction->posting_date->toDateString();
        $this->readiness->assertCanPost($postingDate, JournalEntry::SYSTEM);
        $this->periods->resolveForPosting($postingDate, $this->authority->canPostSoftClosed($actor));

        return $cash;
    }

    /**
     * The counter account is explicit and restricted: an active, postable account that is not a control account (those take postings from
     * their subledger only), not the cash/bank account itself, and not the GL account of any cash or bank account (transfers between
     * cash and bank accounts are not part of OA2).
     */
    private function counterAccount(?string $accountId, ?string $cashGlAccountId, string $field = 'counter_account_id'): string
    {
        $account = $this->accounts->usable($accountId, [], false, $field);
        $isCashBank = $account->id === $cashGlAccountId || DB::table('cash_bank_accounts')->where('tenant_id', $this->context->tenantId())->where('account_id', $account->id)->exists();
        if ($isCashBank) {
            throw new DomainException('The counter account cannot be a cash or bank account; transfers between cash and bank accounts are not supported.', 'CASH_TRANSACTION_COUNTER_IS_CASH_BANK', 422, ['field' => $field, 'account' => $account->code]);
        }

        return $account->id;
    }

    /** @return array{header:array<string,mixed>,columns:array<string,mixed>} */
    private function prepare(string $kind, array $data, ?CashTransaction $existing, User $actor, AccountingProfile $profile): array
    {
        if (! isset(self::EVENTS[$kind])) {
            throw new DomainException('Unknown cash transaction kind.', 'CASH_TRANSACTION_KIND_INVALID', 422, ['field' => 'kind']);
        }
        $scale = (int) $profile->currency_scale;
        $field = fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);
        $date = fn (mixed $v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
        if (isset($data['currency']) && $data['currency'] !== $profile->functional_currency) {
            throw new DomainException("OA2 books in the functional currency ({$profile->functional_currency}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'currency']);
        }

        $cashId = $field('cash_bank_account_id') ?? throw new DomainException('The cash or bank account is required.', 'CASH_BANK_ACCOUNT_REQUIRED', 422, ['field' => 'cash_bank_account_id']);
        $cash = $this->cashAccounts->usable($cashId);
        $counterId = $field('counter_account_id') ?? throw new DomainException('The counter account is required.', 'COUNTER_ACCOUNT_REQUIRED', 422, ['field' => 'counter_account_id']);
        $counterId = $this->counterAccount($counterId, $cash->account_id);

        $amount = Money::parse($field('amount'), $scale, 'amount');
        if ($amount->isLessThanOrEqualTo(0)) {
            throw new DomainException('The amount must be greater than zero.', 'CASH_TRANSACTION_AMOUNT_INVALID', 422, ['field' => 'amount']);
        }
        $purpose = trim((string) $field('purpose'));
        if (mb_strlen($purpose) < 3) {
            throw new DomainException('State the purpose of the transaction.', 'CASH_TRANSACTION_PURPOSE_REQUIRED', 422, ['field' => 'purpose']);
        }
        $transactionDate = $date($field('transaction_date')) ?? throw new DomainException('The transaction date is required.', 'TRANSACTION_DATE_REQUIRED', 422, ['field' => 'transaction_date']);
        $postingDate = $date(array_key_exists('posting_date', $data) && $data['posting_date'] !== null ? $data['posting_date'] : ($existing?->posting_date ?? $transactionDate));

        $dims = $this->dimensions->resolve($field('branch_id', $cash->branch_id), $field('business_unit_id', $cash->business_unit_id), $field('cost_center_id'));
        $this->scope->assertWritable($dims['branch_id'], $dims['business_unit_id'], $existing?->created_by ?? $actor->id);

        return [
            'header' => collect($data)->only(['transaction_date', 'posting_date', 'description', 'reference', 'counterparty_name'])->all() + ['purpose' => $purpose],
            'columns' => [
                'cash_bank_account_id' => $cash->id, 'counter_account_id' => $counterId, 'currency' => $profile->functional_currency, 'amount' => Money::str($amount),
                'transaction_date' => $transactionDate, 'posting_date' => $postingDate,
                'branch_id' => $dims['branch_id'], 'business_unit_id' => $dims['business_unit_id'], 'cost_center_id' => $dims['cost_center_id'],
            ] + ($existing === null ? ['description' => $data['description'] ?? throw new DomainException('The description is required.', 'DESCRIPTION_REQUIRED', 422, ['field' => 'description'])] : []),
        ];
    }

    /** The journal the rule built must move exactly the amount between the cash/bank GL account and the counter account, and touch nothing else. */
    private function assertJournal(CashTransaction $transaction, JournalEntry $journal, string $cashGlAccountId): void
    {
        $lines = collect($journal->posting_snapshot['lines'] ?? []);
        [$cashSide, $counterSide] = $transaction->kind === CashTransaction::PAYMENT ? ['CREDIT', 'DEBIT'] : ['DEBIT', 'CREDIT'];
        $cash = $lines->where('account_role', 'CASH_BANK_ACCOUNT')->where('side', $cashSide);
        $counter = $lines->where('account_role', 'DOCUMENT_ACCOUNT')->where('side', $counterSide);

        if ($lines->count() !== $cash->count() + $counter->count() || ! Money::sum($cash->pluck('amount')->all())->isEqualTo($transaction->amount)
            || ! Money::sum($counter->pluck('amount')->all())->isEqualTo($transaction->amount)
            || $cash->pluck('account_id')->unique()->all() !== [$cashGlAccountId] || $counter->pluck('account_id')->unique()->all() !== [$transaction->counter_account_id]) {
            throw new DomainException('The cash transaction posting rule must move the amount between the cash and bank role and the document account role.', 'CASH_POSTING_RULE_INVALID', 422);
        }
    }

    private function assertDraft(CashTransaction $transaction): void
    {
        if ($transaction->status !== CashTransaction::DRAFT) {
            throw new DomainException("A {$transaction->status} transaction cannot be edited.", in_array($transaction->status, [CashTransaction::POSTED, CashTransaction::REVERSED], true) ? 'DOCUMENT_IMMUTABLE' : 'DOCUMENT_NOT_DRAFT', 409, ['status' => $transaction->status]);
        }
    }

    private function summary(CashTransaction $transaction): array
    {
        return $transaction->only(['document_number', 'kind', 'cash_bank_account_id', 'counter_account_id', 'status', 'transaction_date', 'posting_date', 'amount', 'purpose']);
    }
}
