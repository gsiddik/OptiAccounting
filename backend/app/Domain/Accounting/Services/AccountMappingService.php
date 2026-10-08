<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Semantic account role -> tenant chart-of-accounts account. Posting rules name roles, never account ids, so a tenant
 * re-maps a role without touching a rule, and a posted journal keeps the account it was posted to (posting_snapshot).
 * A mapping may be narrowed to a branch or business unit; the most specific one wins (business unit > branch > default).
 */
class AccountMappingService
{
    public function __construct(private readonly AuditService $audit, private readonly TenantContext $context) {}

    /** @return array{roles:Collection,mappings:Collection} */
    public function overview(): array
    {
        $roles = DB::table('account_roles')->where('status', 'ACTIVE')->orderBy('sort_order')->get(['code', 'name', 'description', 'binding']);
        $usedByRules = $this->rolesUsedByPublishedRules();
        $mappings = AccountMapping::query()->with('account:id,code,name,account_type,status')->orderBy('account_role')->get();

        return [
            'roles' => $roles->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'description' => $r->description, 'used_by_published_rule' => in_array($r->code, $usedByRules, true),
                'mapped' => $mappings->contains(fn ($m) => $m->account_role === $r->code && $m->status === 'ACTIVE' && $m->branch_id === null && $m->business_unit_id === null)])->values(),
            'mappings' => $mappings,
        ];
    }

    /** Create the mapping for (role, branch, business unit) or point the existing active one at another account. */
    public function save(array $data): AccountMapping
    {
        $role = DB::table('account_roles')->where('code', $data['account_role'])->where('status', 'ACTIVE')->first()
            ?? throw new DomainException('Unknown account role.', 'ACCOUNT_ROLE_UNKNOWN', 422, ['account_role' => $data['account_role']]);
        if ($role->binding === 'DOCUMENT') {
            throw new DomainException('This role takes its account from the source document; it has no tenant mapping.', 'ACCOUNT_ROLE_DOCUMENT_BOUND', 422, ['account_role' => $role->code]);
        }
        $branchId = $data['branch_id'] ?? null;
        $unitId = $data['business_unit_id'] ?? null;
        $this->assertOrganization($branchId, $unitId);

        try {
            return DB::transaction(function () use ($data, $role, $branchId, $unitId) {
                $account = Account::query()->lockForUpdate()->find($data['account_id'])
                    ?? throw new DomainException('The account does not exist.', 'ACCOUNT_NOT_FOUND', 422);
                if ($account->status !== 'ACTIVE' || ! $account->is_postable) {
                    throw new DomainException('Only an active, postable account can be mapped to a role.', 'ACCOUNT_NOT_MAPPABLE', 422, ['account_code' => $account->code]);
                }

                $existing = AccountMapping::query()->lockForUpdate()->where('account_role', $role->code)->where('status', 'ACTIVE')
                    ->where('branch_id', $branchId)->where('business_unit_id', $unitId)->first();

                if ($existing) {
                    $before = $existing->only(['account_id']);
                    $existing->account_id = $account->id;
                    $existing->save();
                    $this->audit->record('accounting.account_mapping.updated', 'account_mapping', $existing->id, $before + ['account_role' => $role->code], ['account_id' => $account->id, 'account_code' => $account->code]);

                    return $existing->load('account:id,code,name,account_type,status');
                }

                $mapping = new AccountMapping(['account_role' => $role->code, 'account_id' => $account->id, 'branch_id' => $branchId, 'business_unit_id' => $unitId]);
                $mapping->status = 'ACTIVE';
                $mapping->save();
                $this->audit->record('accounting.account_mapping.created', 'account_mapping', $mapping->id, null, [
                    'account_role' => $role->code, 'account_id' => $account->id, 'account_code' => $account->code, 'branch_id' => $branchId, 'business_unit_id' => $unitId,
                ]);

                return $mapping->load('account:id,code,name,account_type,status');
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') { // a concurrent request created the same mapping first
                throw new DomainException('This role already has a mapping for that scope; save again.', 'ACCOUNT_MAPPING_CONFLICT', 409);
            }
            throw $e;
        }
    }

    /** A mapping is switched off, never deleted. The tenant default of a role used by a published rule cannot be removed. */
    public function deactivate(AccountMapping $mapping): AccountMapping
    {
        return DB::transaction(function () use ($mapping) {
            $mapping = AccountMapping::query()->lockForUpdate()->findOrFail($mapping->id);
            if ($mapping->status !== 'ACTIVE') {
                return $mapping;
            }
            $isDefault = $mapping->branch_id === null && $mapping->business_unit_id === null;
            if ($isDefault && in_array($mapping->account_role, $this->rolesUsedByPublishedRules(), true)) {
                throw new DomainException('A published posting rule uses this role; archive the rule or map the role to another account instead.', 'ACCOUNT_MAPPING_IN_USE', 409, ['account_role' => $mapping->account_role]);
            }

            $mapping->status = 'INACTIVE';
            $mapping->save();
            $this->audit->record('accounting.account_mapping.deactivated', 'account_mapping', $mapping->id, ['status' => 'ACTIVE'], ['status' => 'INACTIVE', 'account_role' => $mapping->account_role]);

            return $mapping;
        });
    }

    /**
     * The account a role resolves to for the given dimensions: business unit, then branch, then the tenant default.
     *
     * @return array{account: Account, mapping_id: string, specificity: string}
     */
    public function resolve(string $role, ?string $branchId = null, ?string $businessUnitId = null): array
    {
        $candidates = AccountMapping::query()->where('account_role', $role)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->whereNull('business_unit_id'))
                ->when($branchId, fn ($w) => $w->orWhere(fn ($b) => $b->where('branch_id', $branchId)->whereNull('business_unit_id')))
                ->when($businessUnitId, fn ($w) => $w->orWhere(fn ($b) => $b->where('business_unit_id', $businessUnitId))))
            ->get();

        $mapping = ($businessUnitId ? $candidates->first(fn ($m) => $m->business_unit_id === $businessUnitId) : null)
            ?? ($branchId ? $candidates->first(fn ($m) => $m->branch_id === $branchId && $m->business_unit_id === null) : null)
            ?? $candidates->first(fn ($m) => $m->branch_id === null && $m->business_unit_id === null);

        if (! $mapping) {
            throw new DomainException("No account is mapped to the role {$role}.", 'ACCOUNT_MAPPING_MISSING', 422, ['account_role' => $role]);
        }

        $account = Account::query()->find($mapping->account_id);
        if (! $account || $account->status !== 'ACTIVE' || ! $account->is_postable) {
            throw new DomainException("The account mapped to the role {$role} is no longer active.", 'ACCOUNT_MAPPING_INVALID', 422, ['account_role' => $role]);
        }

        return [
            'account' => $account, 'mapping_id' => $mapping->id,
            'specificity' => $mapping->business_unit_id ? 'BUSINESS_UNIT' : ($mapping->branch_id ? 'BRANCH' : 'DEFAULT'),
        ];
    }

    /** @return list<string> */
    public function rolesUsedByPublishedRules(): array
    {
        return DB::table('posting_rule_lines as l')->join('posting_rules as r', function ($j) {
            $j->on('r.id', '=', 'l.posting_rule_id')->on('r.tenant_id', '=', 'l.tenant_id');
        })->where('r.tenant_id', $this->context->tenantId())->where('r.status', 'PUBLISHED')->distinct()->pluck('l.account_role')->all();
    }

    private function assertOrganization(?string $branchId, ?string $unitId): void
    {
        if ($branchId && ! Branch::query()->find($branchId)) {
            throw new DomainException('The branch does not exist.', 'DIMENSION_NOT_FOUND', 422);
        }
        if ($unitId) {
            $unit = BusinessUnit::query()->find($unitId) ?? throw new DomainException('The business unit does not exist.', 'DIMENSION_NOT_FOUND', 422);
            if ($branchId && $unit->branch_id !== null && $unit->branch_id !== $branchId) {
                throw new DomainException('The business unit belongs to another branch.', 'DIMENSION_MISMATCH', 422);
            }
        }
    }
}
