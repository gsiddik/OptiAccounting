<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Services\AccountGuard;
use App\Domain\Accounting\Services\DimensionGuard;
use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cash and bank accounts. The GL mapping is validated here (tenant-owned, active, postable asset account) and again by the foreign key
 * and by the posting; the balance is never stored: `bookBalances` reads posted journal lines of the mapped account. Only the masked bank
 * number is kept, and audit records carry the same masked value, never the full number.
 */
class CashBankAccountService
{
    public function __construct(
        private readonly AccountGuard $accounts,
        private readonly DimensionGuard $dimensions,
        private readonly DocumentScope $scope,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    public function query(array $filter = []): Builder
    {
        $query = CashBankAccount::query()->with(['glAccount', 'branch', 'businessUnit']);
        $this->scope->restrict($query->getQuery(), 'cash_bank_accounts');

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('cash_bank_accounts.status', $v))
            ->when($filter['kind'] ?? null, fn ($q, $v) => $q->where('cash_bank_accounts.kind', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('cash_bank_accounts.branch_id', $v))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $v) => $q->where('cash_bank_accounts.business_unit_id', $v))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(cash_bank_accounts.code) like ?', [$like])->orWhereRaw('lower(cash_bank_accounts.name) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(cash_bank_accounts.bank_name, \'\')) like ?', [$like]));
            })
            ->orderBy('cash_bank_accounts.code');
    }

    public function create(array $data): CashBankAccount
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data) {
            $kind = $data['kind'] ?? null;
            if (! in_array($kind, [CashBankAccount::CASH, CashBankAccount::BANK], true)) {
                throw new DomainException('The kind must be CASH or BANK.', 'CASH_BANK_KIND_INVALID', 422, ['field' => 'kind']);
            }
            $account = new CashBankAccount($this->descriptive($data, $kind));
            $account->kind = $kind;
            $account->status = CashBankAccount::ACTIVE;
            $account->code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
            $this->applyMapping($account, $data, true);
            $account->account_number_masked = $kind === CashBankAccount::BANK ? $this->mask($data['account_number'] ?? null) : null;
            $account->created_by = $this->context->user()?->id;
            $account->save();
            $this->audit->record('cash_bank.account.created', 'cash_bank_account', $account->id, null, $this->summary($account));

            return $this->load($account->refresh());
        }));
    }

    public function update(CashBankAccount $account, array $data): CashBankAccount
    {
        return $this->guarded(fn () => DB::transaction(function () use ($account, $data) {
            $account = CashBankAccount::query()->lockForUpdate()->findOrFail($account->id);
            $before = $this->summary($account);

            $account->fill($this->descriptive($data, $account->kind, partial: true));
            if (array_key_exists('account_number', $data) && $account->kind === CashBankAccount::BANK) {
                $account->account_number_masked = $this->mask($data['account_number']);
            }
            $mappingBefore = $account->only(['account_id', 'currency']);
            $this->applyMapping($account, $data, false);
            $account->updated_by = $this->context->user()?->id;
            $account->save();

            $this->audit->record('cash_bank.account.updated', 'cash_bank_account', $account->id, $before, $this->summary($account));
            if ($account->only(['account_id', 'currency']) !== $mappingBefore) {
                $this->audit->record('cash_bank.account.mapping_changed', 'cash_bank_account', $account->id, $mappingBefore, $account->only(['account_id', 'currency']) + ['code' => $account->code]);
            }

            return $this->load($account->refresh());
        }));
    }

    public function setStatus(CashBankAccount $account, string $status): CashBankAccount
    {
        return $this->guarded(fn () => DB::transaction(function () use ($account, $status) {
            $account = CashBankAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($account->status !== $status) {
                if ($status === CashBankAccount::ACTIVE) {
                    $this->accounts->usable($account->account_id, ['ASSET'], null, 'account_id'); // the mapped GL account must still be usable to reactivate
                }
                $account->status = $status;
                $account->updated_by = $this->context->user()?->id;
                $account->save();
                $this->audit->record('cash_bank.account.status_changed', 'cash_bank_account', $account->id, ['status' => $status === CashBankAccount::ACTIVE ? CashBankAccount::INACTIVE : CashBankAccount::ACTIVE], ['status' => $status, 'code' => $account->code]);
            }

            return $this->load($account->refresh());
        }));
    }

    public function delete(CashBankAccount $account): void
    {
        DB::transaction(function () use ($account) {
            $account = CashBankAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($this->inUse($account)) {
                throw new DomainException('This account has documents; deactivate it instead of deleting it.', 'CASH_BANK_ACCOUNT_IN_USE', 409);
            }
            $account->delete();
            $this->audit->record('cash_bank.account.deleted', 'cash_bank_account', $account->id, $this->summary($account), null);
        });
    }

    /** True once any document (in any status) names the account. */
    public function inUse(CashBankAccount $account): bool
    {
        return (bool) DB::selectOne('SELECT cash_bank_account_in_use(?, ?) AS used', [$account->tenant_id, $account->id])->used;
    }

    /**
     * The account a document may use: active, in the user's scope, mapped to a usable GL account. With $lock a document holds it FOR SHARE, so the
     * account cannot be deactivated or remapped between validation and posting.
     */
    public function usable(string $id, bool $lock = false, string $field = 'cash_bank_account_id'): CashBankAccount
    {
        $query = CashBankAccount::query();
        $account = ($lock ? $query->sharedLock() : $query)->find($id);
        if (! $account || ! $this->scope->visible($account)) { // outside the user's data scope it does not exist for them
            throw new DomainException('The cash or bank account does not exist.', 'CASH_BANK_ACCOUNT_NOT_FOUND', 422, ['field' => $field]);
        }
        if ($account->status !== CashBankAccount::ACTIVE) {
            throw new DomainException("Cash or bank account {$account->code} is inactive.", 'CASH_BANK_ACCOUNT_INACTIVE', 422, ['field' => $field]);
        }
        $this->accounts->usable($account->account_id, ['ASSET'], null, $field);

        return $account;
    }

    public function load(CashBankAccount $account): CashBankAccount
    {
        return $account->load(['glAccount', 'branch', 'businessUnit']);
    }

    /**
     * Book balances from posted journal lines of the mapped GL accounts (debit minus credit, up to and including $asOf).
     *
     * @param  list<string>  $glAccountIds
     * @return array<string,string> GL account id => balance
     */
    public function bookBalances(array $glAccountIds, ?string $asOf = null): array
    {
        if ($glAccountIds === []) {
            return [];
        }
        $rows = DB::table('journal_lines as l')->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
            ->where('l.tenant_id', $this->context->tenantId())->where('j.status', 'POSTED')->whereIn('l.account_id', $glAccountIds)
            ->when($asOf, fn ($q, $d) => $q->where('j.posting_date', '<=', $d))
            ->groupBy('l.account_id')->selectRaw('l.account_id, sum(l.debit) - sum(l.credit) as balance')->pluck('balance', 'account_id');

        $out = [];
        foreach ($glAccountIds as $id) {
            $out[$id] = Money::str($rows[$id] ?? '0');
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function descriptive(array $data, string $kind, bool $partial = false): array
    {
        $fields = collect($data)->only((new CashBankAccount)->getFillable())->all();
        if ($kind === CashBankAccount::CASH) {
            foreach (['bank_name', 'account_holder'] as $bankOnly) {
                if (($fields[$bankOnly] ?? null) !== null) {
                    throw new DomainException('A cash account has no bank details.', 'CASH_ACCOUNT_HAS_NO_BANK_DATA', 422, ['field' => $bankOnly]);
                }
                unset($fields[$bankOnly]);
            }
        } elseif (! $partial && trim((string) ($fields['bank_name'] ?? '')) === '') {
            throw new DomainException('A bank account needs the bank name.', 'BANK_NAME_REQUIRED', 422, ['field' => 'bank_name']);
        } elseif (array_key_exists('bank_name', $fields) && trim((string) $fields['bank_name']) === '') {
            throw new DomainException('A bank account needs the bank name.', 'BANK_NAME_REQUIRED', 422, ['field' => 'bank_name']);
        }
        unset($fields['code']);

        return $fields;
    }

    /** Validate and assign the GL mapping, currency and organization placement present in $data. */
    private function applyMapping(CashBankAccount $account, array $data, bool $creating): void
    {
        $functional = AccountingProfile::query()->value('functional_currency');
        if (array_key_exists('currency', $data) && $data['currency'] !== null && $functional !== null && $data['currency'] !== $functional) {
            throw new DomainException("OA2 books in the functional currency ({$functional}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'currency']);
        }
        $account->currency = $functional ?? ($data['currency'] ?? throw new DomainException('Set up the accounting profile first.', 'ACCOUNTING_NOT_READY', 409));

        if ($creating || array_key_exists('account_id', $data)) {
            $glId = $data['account_id'] ?? null;
            // The GL account must be an active, postable asset account of this tenant (a foreign id looks missing).
            $account->account_id = $this->accounts->usable($glId, ['ASSET'], null, 'account_id')->id;
        }
        if ($creating || array_key_exists('branch_id', $data) || array_key_exists('business_unit_id', $data)) {
            $dims = $this->dimensions->resolve($data['branch_id'] ?? ($creating ? null : $account->branch_id), $data['business_unit_id'] ?? ($creating ? null : $account->business_unit_id), null);
            $this->scope->assertWritable($dims['branch_id'], $dims['business_unit_id'], $account->created_by ?? $this->context->user()?->id);
            $account->branch_id = $dims['branch_id'];
            $account->business_unit_id = $dims['business_unit_id'];
        }
    }

    /** Keep the last four characters only. The full number is never stored, logged or audited. */
    private function mask(mixed $number): ?string
    {
        $clean = preg_replace('/[^0-9A-Za-z]/', '', (string) ($number ?? ''));
        if ($clean === '') {
            return null;
        }

        return '******'.substr($clean, -4);
    }

    private function summary(CashBankAccount $account): array
    {
        return $account->only(['code', 'name', 'kind', 'status', 'currency', 'account_id', 'bank_name', 'account_number_masked']);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? '';
            if ($state === '23505') {
                $glTaken = str_contains($e->getMessage(), 'cash_bank_accounts_active_gl_unique');

                throw new DomainException($glTaken ? 'Another active cash or bank account already uses this GL account.' : 'A cash or bank account with this code already exists.', $glTaken ? 'CASH_BANK_GL_ACCOUNT_TAKEN' : 'CASH_BANK_CODE_TAKEN', 422);
            }
            if ($state === '23514' && str_contains($e->getMessage(), 'documents use')) {
                throw new DomainException('This account has documents; its GL account, currency and kind can no longer change. Create a new account instead.', 'CASH_BANK_ACCOUNT_IN_USE', 409);
            }
            throw $e;
        }
    }
}
