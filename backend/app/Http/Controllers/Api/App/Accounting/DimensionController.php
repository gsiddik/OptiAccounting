<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Services\AccountingScope;
use App\Domain\Accounting\Services\CostCenterService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DimensionController extends AppController
{
    public function __construct(TenantContext $context, private readonly CostCenterService $costCenters, private readonly AccountingScope $scope)
    {
        parent::__construct($context);
    }

    /** Dimension catalog: what can be attached to a journal line. */
    public function types(): JsonResponse
    {
        return response()->json(['data' => DB::table('dimension_types')->where('status', 'ACTIVE')->orderBy('sort_order')->get(['code', 'name', 'kind'])]);
    }

    /**
     * What a journal line can be attached to: active branches, business units and cost centers, narrowed to the signed-in
     * user's data scope (a user restricted to one branch is offered that branch only). The API still enforces the scope.
     */
    public function catalog(): JsonResponse
    {
        $scope = $this->scope->scope();
        $branches = Branch::query()->where('status', 'ACTIVE')->orderBy('code')->get(['id', 'code', 'name']);
        $units = BusinessUnit::query()->where('status', 'ACTIVE')->orderBy('code')->get(['id', 'code', 'name', 'branch_id']);

        if (! $scope['tenant']) {
            $units = $units->filter(fn ($u) => in_array($u->id, $scope['business_unit_ids'], true) || in_array($u->branch_id, $scope['branch_ids'], true))->values();
            $branchIds = array_unique([...$scope['branch_ids'], ...$units->pluck('branch_id')->filter()->all()]);
            $branches = $branches->filter(fn ($b) => in_array($b->id, $branchIds, true))->values();
        }

        return response()->json([
            'branches' => $branches,
            'business_units' => $units,
            'cost_centers' => CostCenter::query()->where('status', 'ACTIVE')->orderBy('code')->get(['id', 'code', 'name', 'branch_id', 'business_unit_id']),
        ]);
    }

    public function costCenters(): JsonResponse
    {
        return response()->json(['data' => CostCenter::query()->orderBy('code')->get()]);
    }

    public function storeCostCenter(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'uuid'],
            'business_unit_id' => ['nullable', 'uuid'],
        ]);

        return response()->json($this->costCenters->create($data), 201);
    }

    public function updateCostCenter(Request $request, CostCenter $costCenter): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'uuid'],
            'business_unit_id' => ['nullable', 'uuid'],
        ]);

        return response()->json($this->costCenters->update($costCenter, $data));
    }

    public function costCenterStatus(Request $request, CostCenter $costCenter): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]);

        return response()->json($this->costCenters->setStatus($costCenter, $data['status']));
    }
}
