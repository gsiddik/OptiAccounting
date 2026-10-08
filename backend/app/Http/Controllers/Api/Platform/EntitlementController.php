<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Entitlement\Models\TenantFeatureEntitlement;
use App\Domain\Entitlement\Models\TenantModuleEntitlement;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Entitlement\Services\EntitlementSnapshot;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Feature;
use App\Domain\ProductCatalog\Models\Module;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EntitlementController extends PlatformController
{
    public function __construct(
        TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly EntitlementSnapshot $snapshot,
        private readonly CapacityService $capacity,
    ) {
        parent::__construct($context);
    }

    /** Full history plus the effective state "today" (what the access resolver would use). */
    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json($this->inTenant($tenant, fn () => [
            'effective' => $this->snapshot->forTenant($tenant),
            'modules' => TenantModuleEntitlement::query()->with('module:id,code,name')->orderBy('effective_from')->get(),
            'features' => TenantFeatureEntitlement::query()->with('feature:id,code,name,module_id')->orderBy('effective_from')->get(),
            'capacity' => $this->capacity->usage($tenant),
        ]));
    }

    public function grantModule(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'module_code' => ['required', 'string', 'exists:modules,code'],
            'state' => ['required', Rule::in(['ACTIVE', 'READ_ONLY', 'SUSPENDED', 'DISABLED'])],
            'source' => ['required', Rule::in(EntitlementService::SOURCES)],
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $row = $this->entitlements->grantModule(
            $tenant, Module::query()->where('code', $data['module_code'])->firstOrFail(), $data['state'], $data['source'],
            $data['effective_from'] ?? $tenant->businessDate(), $data['effective_until'] ?? null,
        );

        return response()->json($row->load('module:id,code,name'), 201);
    }

    public function updateModule(Request $request, Tenant $tenant, string $entitlement): JsonResponse
    {
        $data = $request->validate([
            'state' => ['sometimes', Rule::in(['ACTIVE', 'READ_ONLY', 'SUSPENDED', 'DISABLED'])],
            'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $row = TenantModuleEntitlement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($entitlement);
        $row = $this->entitlements->updateModule($row, $data['state'] ?? null, array_key_exists('effective_until', $data), $data['effective_until'] ?? null);

        return response()->json($row->load('module:id,code,name'));
    }

    public function grantFeature(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'feature_code' => ['required', 'string', 'exists:features,code'],
            'state' => ['required', Rule::in(['ACTIVE', 'DISABLED'])],
            'source' => ['required', Rule::in(EntitlementService::SOURCES)],
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $row = $this->entitlements->grantFeature(
            $tenant, Feature::query()->where('code', $data['feature_code'])->firstOrFail(), $data['state'], $data['source'],
            $data['effective_from'] ?? $tenant->businessDate(), $data['effective_until'] ?? null,
        );

        return response()->json($row->load('feature:id,code,name'), 201);
    }

    public function updateFeature(Request $request, Tenant $tenant, string $entitlement): JsonResponse
    {
        $data = $request->validate([
            'state' => ['sometimes', Rule::in(['ACTIVE', 'DISABLED'])],
            'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $row = TenantFeatureEntitlement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($entitlement);
        $row = $this->entitlements->updateFeature($row, $data['state'] ?? null, array_key_exists('effective_until', $data), $data['effective_until'] ?? null);

        return response()->json($row->load('feature:id,code,name'));
    }

    public function setCapacity(Request $request, Tenant $tenant, string $limitCode): JsonResponse
    {
        $data = $request->validate([
            'limit_value' => ['present', 'nullable', 'integer', 'min:0'],
            'source' => ['required', Rule::in(EntitlementService::SOURCES)],
        ]);

        $row = $this->entitlements->setCapacity($tenant, $limitCode, $data['limit_value'] === null ? null : (int) $data['limit_value'], $data['source']);

        return response()->json($row);
    }
}
