<?php

namespace App\Http\Controllers\Api\App;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Entitlement\Models\Subscription;
use App\Domain\Entitlement\Models\TenantFeatureEntitlement;
use App\Domain\Entitlement\Models\TenantModuleEntitlement;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Entitlement\Services\EntitlementSnapshot;
use App\Domain\Identity\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The tenant's own subscription, entitlements, usage and audit trail (read-only). */
class AccountController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly EntitlementSnapshot $snapshot,
        private readonly CapacityService $capacity,
    ) {
        parent::__construct($context);
    }

    public function subscription(): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId());

        return response()->json([
            'effective' => $this->snapshot->forTenant($tenant)['subscription'],
            'history' => Subscription::query()->with('bundle:id,code,name')->orderByDesc('starts_on')->get(),
        ]);
    }

    public function modules(): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId());
        $snapshot = $this->snapshot->forTenant($tenant);

        $rows = TenantModuleEntitlement::query()->with('module:id,code,name,description')->orderBy('effective_from')->get()
            ->map(fn ($row) => [
                'module' => $row->module->only(['code', 'name', 'description']),
                'state' => $row->state,
                'source' => $row->source,
                'effective_from' => $row->effective_from->toDateString(),
                'effective_until' => $row->effective_until?->toDateString(),
                'current' => $row->coversDate($snapshot['date']),
                'effective_mode' => $row->coversDate($snapshot['date'])
                    ? EntitlementSnapshot::moduleMode($snapshot, $row->module->code) : 'NONE',
            ]);

        return response()->json(['data' => $rows, 'business_date' => $snapshot['date']]);
    }

    public function features(): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId());
        $snapshot = $this->snapshot->forTenant($tenant);

        $rows = TenantFeatureEntitlement::query()->with('feature.module:id,code,name')->orderBy('effective_from')->get()
            ->map(fn ($row) => [
                'feature' => $row->feature->only(['code', 'name']),
                'module' => $row->feature->module->only(['code', 'name']),
                'state' => $row->state,
                'source' => $row->source,
                'effective_from' => $row->effective_from->toDateString(),
                'effective_until' => $row->effective_until?->toDateString(),
                'current' => $row->coversDate($snapshot['date']),
                'usable' => $row->coversDate($snapshot['date']) && $row->state === 'ACTIVE'
                    && EntitlementSnapshot::moduleMode($snapshot, $row->feature->module->code) !== 'NONE',
            ]);

        return response()->json(['data' => $rows, 'business_date' => $snapshot['date']]);
    }

    public function usage(): JsonResponse
    {
        return response()->json(['data' => $this->capacity->usage($this->tenantId())]);
    }

    public function audit(Request $request): JsonResponse
    {
        $query = AuditLog::query()->orderByDesc('occurred_at');
        if ($action = $request->query('action')) {
            $query->where('action', 'like', $action.'%');
        }

        return response()->json($query->paginate(50));
    }
}
