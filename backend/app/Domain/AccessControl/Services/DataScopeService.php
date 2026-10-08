<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\Organization\Models\BusinessUnit;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Data scope answers "to which records may the user apply a permission". A user
 * with no data-scope rows sees no scoped records (fail closed). Scopes combine
 * as a union; a BRANCH scope also covers that branch's business units.
 */
class DataScopeService
{
    public function __construct(private readonly AccessCache $cache) {}

    /**
     * @return array{tenant:bool,own:bool,branch_ids:list<string>,business_unit_ids:list<string>}
     */
    public function resolve(string $tenantId, string $tenantUserId): array
    {
        return $this->cache->rememberForTenant($tenantId, "scope:{$tenantUserId}", function () use ($tenantId, $tenantUserId) {
            $rows = DB::table('data_scopes')
                ->where('tenant_id', $tenantId)->where('tenant_user_id', $tenantUserId)->get();

            $branchIds = $rows->where('scope_type', DataScope::BRANCH)->pluck('branch_id')->all();
            $buIds = $rows->where('scope_type', DataScope::BUSINESS_UNIT)->pluck('business_unit_id')->all();

            if ($branchIds !== []) {
                $inBranches = DB::table('business_units')
                    ->where('tenant_id', $tenantId)->whereIn('branch_id', $branchIds)->pluck('id')->all();
                $buIds = array_values(array_unique([...$buIds, ...$inBranches]));
            }

            return [
                'tenant' => $rows->contains('scope_type', DataScope::TENANT),
                'own' => $rows->contains('scope_type', DataScope::OWN),
                'branch_ids' => array_values($branchIds),
                'business_unit_ids' => $buIds,
            ];
        });
    }

    /** @param array{branch_id?:?string,business_unit_id?:?string,owner_id?:?string} $resource */
    public function allows(array $scope, array $resource, string $userId): bool
    {
        if ($scope['tenant']) {
            return true;
        }

        $branch = $resource['branch_id'] ?? null;
        $unit = $resource['business_unit_id'] ?? null;

        if ($branch !== null && in_array($branch, $scope['branch_ids'], true)) {
            return true;
        }

        if ($unit !== null && in_array($unit, $scope['business_unit_ids'], true)) {
            return true;
        }

        return $scope['own'] && ($resource['owner_id'] ?? null) === $userId;
    }

    /**
     * Restrict a query on a tenant-owned resource to the user's scope. Used by OA1+ list endpoints.
     *
     * @param  array{branch?:string,business_unit?:string,owner?:string}  $columns
     */
    public function applyToQuery(Builder $query, array $scope, string $userId, array $columns = []): Builder
    {
        if ($scope['tenant']) {
            return $query;
        }

        $branchCol = $columns['branch'] ?? 'branch_id';
        $unitCol = $columns['business_unit'] ?? 'business_unit_id';
        $ownerCol = $columns['owner'] ?? 'created_by';

        return $query->where(function (Builder $q) use ($scope, $userId, $branchCol, $unitCol, $ownerCol) {
            $q->whereRaw('1 = 0');
            if ($scope['branch_ids'] !== []) {
                $q->orWhereIn($branchCol, $scope['branch_ids']);
            }
            if ($scope['business_unit_ids'] !== []) {
                $q->orWhereIn($unitCol, $scope['business_unit_ids']);
            }
            if ($scope['own']) {
                $q->orWhere($ownerCol, $userId);
            }
        });
    }

    /** True when the business unit belongs to the tenant (used when assigning scopes). */
    public function businessUnitBelongsToTenant(string $businessUnitId, string $tenantId): bool
    {
        return BusinessUnit::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($businessUnitId)->exists();
    }
}
