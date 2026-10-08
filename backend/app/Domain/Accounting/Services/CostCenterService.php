<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Tenant-owned cost centers (optionally tied to a branch / business unit). Deactivated, never deleted once used. */
class CostCenterService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(array $data): CostCenter
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data) {
            $center = new CostCenter(collect($data)->only(['code', 'name', 'description'])->all());
            [$center->branch_id, $center->business_unit_id] = $this->organization($data['branch_id'] ?? null, $data['business_unit_id'] ?? null);
            $center->status = 'ACTIVE';
            $center->save();
            $this->audit->record('accounting.cost_center.created', 'cost_center', $center->id, null, $center->only(['code', 'name', 'branch_id', 'business_unit_id']));

            return $center;
        }));
    }

    public function update(CostCenter $center, array $data): CostCenter
    {
        return $this->guarded(fn () => DB::transaction(function () use ($center, $data) {
            $before = $center->only(array_keys($data));
            $center->fill(collect($data)->only(['name', 'description'])->all());
            if (array_key_exists('branch_id', $data) || array_key_exists('business_unit_id', $data)) {
                [$center->branch_id, $center->business_unit_id] = $this->organization(
                    array_key_exists('branch_id', $data) ? $data['branch_id'] : $center->branch_id,
                    array_key_exists('business_unit_id', $data) ? $data['business_unit_id'] : $center->business_unit_id,
                );
            }
            $center->save();
            $this->audit->record('accounting.cost_center.updated', 'cost_center', $center->id, $before, $center->only(array_keys($data)));

            return $center;
        }));
    }

    public function setStatus(CostCenter $center, string $status): CostCenter
    {
        return DB::transaction(function () use ($center, $status) {
            $before = ['status' => $center->status];
            $center->status = $status;
            $center->save();
            $this->audit->record('accounting.cost_center.status_changed', 'cost_center', $center->id, $before, ['status' => $status, 'code' => $center->code]);

            return $center;
        });
    }

    /** @return array{0:?string,1:?string} branch and business unit that exist in this tenant and agree with each other */
    private function organization(?string $branchId, ?string $unitId): array
    {
        $branch = $branchId ? (Branch::query()->find($branchId) ?? throw new DomainException('The branch does not exist.', 'DIMENSION_NOT_FOUND', 422)) : null;
        $unit = $unitId ? (BusinessUnit::query()->find($unitId) ?? throw new DomainException('The business unit does not exist.', 'DIMENSION_NOT_FOUND', 422)) : null;
        if ($branch && $unit && $unit->branch_id !== null && $unit->branch_id !== $branch->id) {
            throw new DomainException('The business unit belongs to another branch.', 'DIMENSION_MISMATCH', 422);
        }

        return [$branch?->id, $unit?->id];
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new DomainException('A cost center with this code already exists.', 'COST_CENTER_CODE_TAKEN', 422);
            }
            throw $e;
        }
    }
}
