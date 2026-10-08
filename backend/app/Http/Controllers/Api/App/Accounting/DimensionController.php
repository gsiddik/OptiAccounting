<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Services\CostCenterService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DimensionController extends AppController
{
    public function __construct(TenantContext $context, private readonly CostCenterService $costCenters)
    {
        parent::__construct($context);
    }

    /** Dimension catalog: what can be attached to a journal line. */
    public function types(): JsonResponse
    {
        return response()->json(['data' => DB::table('dimension_types')->where('status', 'ACTIVE')->orderBy('sort_order')->get(['code', 'name', 'kind'])]);
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
