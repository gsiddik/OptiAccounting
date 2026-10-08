<?php

namespace App\Http\Controllers\Api\App;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Organization\Services\OrganizationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends AppController
{
    public function __construct(TenantContext $context, private readonly OrganizationService $organization)
    {
        parent::__construct($context);
    }

    public function branches(): JsonResponse
    {
        return response()->json(['data' => Branch::query()->orderBy('code')->get()]);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->organization->createBranch($this->tenantId(), $data), 201);
    }

    public function updateBranch(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->organization->updateBranch($branch, $data));
    }

    public function branchStatus(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]);

        return response()->json($this->organization->setBranchStatus($branch, $data['status']));
    }

    public function businessUnits(): JsonResponse
    {
        return response()->json(['data' => BusinessUnit::query()->with('branch:id,code,name')->orderBy('code')->get()]);
    }

    public function storeBusinessUnit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'uuid'],
        ]);

        $unit = $this->organization->createBusinessUnit($this->tenantId(), collect($data)->only(['code', 'name'])->all(), $data['branch_id'] ?? null);

        return response()->json($unit->load('branch:id,code,name'), 201);
    }

    public function updateBusinessUnit(Request $request, BusinessUnit $businessUnit): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:255'], 'branch_id' => ['nullable', 'uuid']]);

        $unit = $this->organization->updateBusinessUnit(
            $businessUnit, collect($data)->only(['name'])->all(), array_key_exists('branch_id', $data), $data['branch_id'] ?? null,
        );

        return response()->json($unit->load('branch:id,code,name'));
    }

    public function businessUnitStatus(Request $request, BusinessUnit $businessUnit): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]);

        return response()->json($this->organization->setBusinessUnitStatus($businessUnit, $data['status']));
    }
}
