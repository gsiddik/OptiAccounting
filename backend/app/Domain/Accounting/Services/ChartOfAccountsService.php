<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Tenant-owned hierarchical chart of accounts. Accounts with history are deactivated, never deleted or redefined. */
class ChartOfAccountsService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return Collection<int,Account> flat, ordered by code; callers build the tree from parent_id */
    public function list(?string $search = null, ?string $type = null, ?string $status = null): Collection
    {
        $query = Account::query()->orderBy('code');
        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]));
        }

        return $query->when($type, fn ($q) => $q->where('account_type', $type))->when($status, fn ($q) => $q->where('status', $status))->get();
    }

    public function create(array $data): Account
    {
        return $this->guarded(function () use ($data) {
            return DB::transaction(function () use ($data) {
                $account = new Account(collect($data)->only(['code', 'name', 'description', 'account_type', 'is_postable', 'is_control', 'currency'])->all());
                $account->normal_balance = $data['normal_balance'] ?? $this->defaultSide($data['account_type']);
                $account->is_postable = $data['is_postable'] ?? true;
                $account->is_control = $data['is_control'] ?? false;
                $account->parent_id = $this->checkedParent($data['parent_id'] ?? null, $data['account_type'], (bool) $account->is_postable, null);
                $this->assertSingleCurrency($account);
                $account->status = Account::ACTIVE;
                $account->save();
                $this->audit->record('accounting.account.created', 'account', $account->id, null, $account->only(['code', 'name', 'account_type', 'normal_balance', 'is_postable', 'is_control', 'parent_id']));

                return $account;
            });
        });
    }

    public function update(Account $account, array $data): Account
    {
        return $this->guarded(function () use ($account, $data) {
            return DB::transaction(function () use ($account, $data) {
                $account = Account::query()->lockForUpdate()->findOrFail($account->id);
                $before = $account->only(array_keys($data));

                $meaning = ['account_type', 'normal_balance', 'is_postable', 'currency'];
                $changesMeaning = collect($meaning)->contains(fn ($k) => array_key_exists($k, $data) && $data[$k] != $account->{$k});
                if ($changesMeaning && $this->hasHistory($account)) {
                    throw new DomainException('This account has journal history; its type, normal balance, posting flag and currency cannot change.', 'ACCOUNT_IN_USE', 409);
                }

                $account->fill(collect($data)->only(['code', 'name', 'description', 'account_type', 'is_postable', 'is_control', 'currency'])->all());
                if (array_key_exists('normal_balance', $data)) {
                    $account->normal_balance = $data['normal_balance'];
                }
                if (array_key_exists('parent_id', $data)) {
                    $account->parent_id = $this->checkedParent($data['parent_id'], $account->account_type, (bool) $account->is_postable, $account);
                } elseif ($account->isDirty('account_type') && $account->children()->exists()) {
                    throw new DomainException('Change the type of the child accounts first.', 'ACCOUNT_HIERARCHY_INVALID', 422);
                }
                if ($account->isDirty('is_postable') && $account->is_postable && $account->children()->exists()) {
                    throw new DomainException('An account with children stays a header.', 'ACCOUNT_HIERARCHY_INVALID', 422);
                }
                $this->assertSingleCurrency($account);
                $account->save();
                $this->audit->record('accounting.account.updated', 'account', $account->id, $before, $account->only(array_keys($data)));

                return $account;
            });
        });
    }

    public function setStatus(Account $account, string $status): Account
    {
        return DB::transaction(function () use ($account, $status) {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            if ($status === Account::INACTIVE) {
                if ($account->children()->where('status', Account::ACTIVE)->exists()) {
                    throw new DomainException('Deactivate the child accounts first.', 'ACCOUNT_HAS_ACTIVE_CHILDREN', 409);
                }
                if (AccountMapping::query()->where('account_id', $account->id)->where('status', 'ACTIVE')->exists()) {
                    throw new DomainException('This account is used by an account mapping; change the mapping first.', 'ACCOUNT_MAPPED', 409);
                }
            } elseif ($account->parent_id && Account::query()->whereKey($account->parent_id)->value('status') !== Account::ACTIVE) {
                throw new DomainException('Activate the parent account first.', 'ACCOUNT_PARENT_INACTIVE', 409);
            }

            $before = ['status' => $account->status];
            $account->status = $status;
            $account->save();
            $this->audit->record('accounting.account.status_changed', 'account', $account->id, $before, ['status' => $status, 'code' => $account->code]);

            return $account;
        });
    }

    /** Only an account that was never used (no lines, children or mappings) can be removed. */
    public function delete(Account $account): void
    {
        DB::transaction(function () use ($account) {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            if ($this->hasHistory($account) || $account->children()->exists() || AccountMapping::query()->where('account_id', $account->id)->exists()) {
                throw new DomainException('This account is in use; deactivate it instead of deleting.', 'ACCOUNT_IN_USE', 409);
            }
            $account->delete();
            $this->audit->record('accounting.account.deleted', 'account', $account->id, $account->only(['code', 'name']), null);
        });
    }

    /** Platform template -> tenant-owned accounts (+ the suggested role mappings). Refused once the tenant has any account. */
    public function applyTemplate(string $templateCode): array
    {
        return DB::transaction(function () use ($templateCode) {
            $tenantId = $this->context->tenantId();
            // Serialize concurrent applications for the tenant.
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

            $template = DB::table('coa_templates')->where('code', $templateCode)->where('status', 'ACTIVE')->first()
                ?? throw new DomainException('Chart of accounts template not found.', 'COA_TEMPLATE_NOT_FOUND', 404);
            if (Account::query()->exists()) {
                throw new DomainException('A template can only be applied to an empty chart of accounts.', 'COA_NOT_EMPTY', 409);
            }

            $rows = DB::table('coa_template_accounts')->where('coa_template_id', $template->id)->orderBy('sort_order')->get();
            $ids = [];
            // Parents come before children in the template; insert in that order.
            foreach ($rows as $row) {
                $account = new Account(['code' => $row->code, 'name' => $row->name, 'account_type' => $row->account_type, 'is_postable' => $row->is_postable, 'is_control' => $row->is_control]);
                $account->normal_balance = $row->normal_balance;
                $account->parent_id = $row->parent_code ? ($ids[$row->parent_code] ?? null) : null;
                $account->status = Account::ACTIVE;
                $account->save();
                $ids[$row->code] = $account->id;
            }

            $mapped = 0;
            foreach ($rows as $row) {
                if ($row->account_role !== null) {
                    $mapping = new AccountMapping(['account_role' => $row->account_role, 'account_id' => $ids[$row->code]]);
                    $mapping->status = 'ACTIVE';
                    $mapping->save();
                    $mapped++;
                }
            }

            $this->audit->record('accounting.coa.template_applied', 'coa_template', $template->id, null, ['template' => $template->code, 'accounts' => count($ids), 'mappings' => $mapped]);

            return ['template' => $template->code, 'accounts' => count($ids), 'mappings' => $mapped];
        });
    }

    public function hasHistory(Account $account): bool
    {
        return DB::table('journal_lines')->where('tenant_id', $account->tenant_id)->where('account_id', $account->id)->exists();
    }

    private function defaultSide(string $type): string
    {
        return in_array($type, ['ASSET', 'EXPENSE'], true) ? 'DEBIT' : 'CREDIT';
    }

    /** The parent must be an active header of the same tenant and type, and must not be the account itself or one of its descendants. */
    private function checkedParent(?string $parentId, string $type, bool $postable, ?Account $self): ?string
    {
        if ($parentId === null) {
            return null;
        }
        $parent = Account::query()->find($parentId) // tenant-scoped: another tenant's account is simply not found
            ?? throw new DomainException('The parent account does not exist.', 'ACCOUNT_PARENT_NOT_FOUND', 422);
        if ($parent->is_postable) {
            throw new DomainException('A posting account cannot be a parent; make it a header first.', 'ACCOUNT_HIERARCHY_INVALID', 422);
        }
        if ($parent->account_type !== $type) {
            throw new DomainException('A child account has the same account type as its parent.', 'ACCOUNT_HIERARCHY_INVALID', 422);
        }
        if ($parent->status !== Account::ACTIVE) {
            throw new DomainException('The parent account is inactive.', 'ACCOUNT_PARENT_INACTIVE', 422);
        }
        if ($self !== null) {
            for ($cursor = $parent; $cursor !== null; $cursor = $cursor->parent_id ? Account::query()->find($cursor->parent_id) : null) {
                if ($cursor->id === $self->id) {
                    throw new DomainException('The account hierarchy cannot contain a cycle.', 'ACCOUNT_HIERARCHY_CYCLE', 422);
                }
            }
        }

        return $parent->id;
    }

    /** OA1 is single-currency: a currency restriction can only name the functional currency. */
    private function assertSingleCurrency(Account $account): void
    {
        if ($account->currency === null) {
            return;
        }
        $functional = DB::table('accounting_profiles')->where('tenant_id', $this->context->tenantId())->value('functional_currency');
        if ($functional !== null && $account->currency !== $functional) {
            throw new DomainException('Multi-currency accounts arrive in a later phase; the restriction must be the functional currency.', 'ACCOUNT_CURRENCY_UNSUPPORTED', 422);
        }
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? '';
            if ($state === '23505') {
                throw new DomainException('An account with this code already exists.', 'ACCOUNT_CODE_TAKEN', 422);
            }
            if ($state === '23514') {
                throw new DomainException('The change would break the account hierarchy or history rules.', 'ACCOUNT_HIERARCHY_INVALID', 422);
            }
            throw $e;
        }
    }
}
