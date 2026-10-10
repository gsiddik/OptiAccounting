<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\DomainException;

/** Branch / business unit / cost center named on an OA2 document: tenant-owned (a foreign id looks missing), active and consistent with each other. */
class DimensionGuard
{
    /** @return array{branch_id:?string,business_unit_id:?string,cost_center_id:?string} */
    public function resolve(?string $branchId, ?string $unitId, ?string $costCenterId, ?int $line = null): array
    {
        $at = $line === null ? [] : ['line' => $line];
        $branch = $this->find(Branch::class, $branchId, 'branch', $at);
        $unit = $this->find(BusinessUnit::class, $unitId, 'business unit', $at);
        $center = $this->find(CostCenter::class, $costCenterId, 'cost center', $at);

        if ($unit && $branch && $unit->branch_id !== null && $unit->branch_id !== $branch->id) {
            throw new DomainException('The business unit belongs to another branch.', 'DIMENSION_MISMATCH', 422, $at);
        }
        if ($center && $branch && $center->branch_id !== null && $center->branch_id !== $branch->id) {
            throw new DomainException('The cost center belongs to another branch.', 'DIMENSION_MISMATCH', 422, $at);
        }

        return ['branch_id' => $branch?->id, 'business_unit_id' => $unit?->id, 'cost_center_id' => $center?->id];
    }

    private function find(string $model, ?string $id, string $label, array $at): mixed
    {
        if ($id === null || $id === '') {
            return null;
        }
        $row = preg_match('/^[0-9a-f-]{36}$/i', $id) ? $model::query()->find($id) : null;
        if (! $row) {
            throw new DomainException("The {$label} does not exist.", 'DIMENSION_NOT_FOUND', 422, $at);
        }
        if (($row->status ?? 'ACTIVE') !== 'ACTIVE') {
            throw new DomainException("The {$label} is inactive.", 'DIMENSION_INACTIVE', 422, $at);
        }

        return $row;
    }
}
