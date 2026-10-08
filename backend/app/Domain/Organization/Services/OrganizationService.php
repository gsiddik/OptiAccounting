<?php

namespace App\Domain\Organization\Services;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Entitlement\Services\CapacityException;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Branches and business units (tenant-owned). Nothing is deleted: units are deactivated. */
class OrganizationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AccessCache $cache,
        private readonly CapacityService $capacity,
    ) {}

    public function createBranch(string $tenantId, array $data): Branch
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $this->reserve($tenantId, CapacityService::BRANCH_LIMIT);
            $branch = new Branch($data);
            $branch->status = 'ACTIVE';
            $this->persist($branch);
            $this->cache->touchTenant($tenantId);
            $this->audit->record('branch.created', 'branch', $branch->id, null, $branch->only(['code', 'name']), $tenantId);

            return $branch;
        });
    }

    public function updateBranch(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data) {
            $before = $branch->only(array_keys($data));
            $branch->fill($data)->save();
            $this->audit->record('branch.updated', 'branch', $branch->id, $before, $branch->only(array_keys($data)), $branch->tenant_id);

            return $branch;
        });
    }

    public function setBranchStatus(Branch $branch, string $status): Branch
    {
        return DB::transaction(function () use ($branch, $status) {
            if ($status === 'ACTIVE' && $branch->status !== 'ACTIVE') {
                $this->reserve($branch->tenant_id, CapacityService::BRANCH_LIMIT);
            }
            if ($status === 'INACTIVE' && $branch->businessUnits()->where('status', 'ACTIVE')->exists()) {
                throw new DomainException('Deactivate the active business units of this branch first.', 'BRANCH_HAS_ACTIVE_UNITS', 409);
            }

            $before = ['status' => $branch->status];
            $branch->status = $status;
            $branch->save();
            $this->cache->touchTenant($branch->tenant_id);
            $this->audit->record('branch.status_changed', 'branch', $branch->id, $before, ['status' => $status], $branch->tenant_id);

            return $branch;
        });
    }

    public function createBusinessUnit(string $tenantId, array $data, ?string $branchId): BusinessUnit
    {
        return DB::transaction(function () use ($tenantId, $data, $branchId) {
            $this->reserve($tenantId, CapacityService::BUSINESS_UNIT_LIMIT);
            $unit = new BusinessUnit($data);
            $unit->status = 'ACTIVE';
            $unit->branch_id = $this->ownBranchId($branchId);
            $this->persist($unit);
            $this->cache->touchTenant($tenantId);
            $this->audit->record('business_unit.created', 'business_unit', $unit->id, null, $unit->only(['code', 'name', 'branch_id']), $tenantId);

            return $unit;
        });
    }

    public function updateBusinessUnit(BusinessUnit $unit, array $data, bool $branchGiven, ?string $branchId): BusinessUnit
    {
        return DB::transaction(function () use ($unit, $data, $branchGiven, $branchId) {
            $before = $unit->only(['name', 'branch_id']);
            $unit->fill($data);
            if ($branchGiven) {
                $unit->branch_id = $this->ownBranchId($branchId);
            }
            $unit->save();
            $this->cache->touchTenant($unit->tenant_id); // a unit's branch decides which branch scopes cover it
            $this->audit->record('business_unit.updated', 'business_unit', $unit->id, $before, $unit->only(['name', 'branch_id']), $unit->tenant_id);

            return $unit;
        });
    }

    public function setBusinessUnitStatus(BusinessUnit $unit, string $status): BusinessUnit
    {
        return DB::transaction(function () use ($unit, $status) {
            if ($status === 'ACTIVE' && $unit->status !== 'ACTIVE') {
                $this->reserve($unit->tenant_id, CapacityService::BUSINESS_UNIT_LIMIT);
            }
            $before = ['status' => $unit->status];
            $unit->status = $status;
            $unit->save();
            $this->cache->touchTenant($unit->tenant_id);
            $this->audit->record('business_unit.status_changed', 'business_unit', $unit->id, $before, ['status' => $status], $unit->tenant_id);

            return $unit;
        });
    }

    /** Resolve a branch id through the tenant scope, so another tenant's branch is simply "not found". */
    private function ownBranchId(?string $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        return Branch::query()->whereKey($branchId)->value('id')
            ?? throw new DomainException('Unknown branch.', 'UNKNOWN_BRANCH');
    }

    private function reserve(string $tenantId, string $limit): void
    {
        try {
            $this->capacity->reserve($tenantId, $limit);
        } catch (CapacityException $e) {
            throw new DomainException('The limit of this subscription has been reached.', 'CAPACITY_EXCEEDED', 409,
                ['limit_code' => $e->limitCode, 'limit' => $e->limit, 'used' => $e->used]);
        }
    }

    private function persist(Branch|BusinessUnit $model): void
    {
        try {
            $model->save();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw new DomainException('This code is already used in the organization.', 'CODE_TAKEN');
            }
            throw $e;
        }
    }
}
