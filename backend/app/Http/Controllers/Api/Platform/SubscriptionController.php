<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Entitlement\Models\Subscription;
use App\Domain\Entitlement\Services\SubscriptionService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Bundle;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends PlatformController
{
    public function __construct(TenantContext $context, private readonly SubscriptionService $subscriptions)
    {
        parent::__construct($context);
    }

    public function index(Tenant $tenant): JsonResponse
    {
        $rows = $this->inTenant($tenant, fn () => Subscription::query()->with('bundle:id,code,name')->orderByDesc('starts_on')->get());

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'bundle_code' => ['nullable', 'string', 'exists:bundles,code'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in([Subscription::PENDING, Subscription::ACTIVE])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $bundle = isset($data['bundle_code']) ? Bundle::query()->where('code', $data['bundle_code'])->firstOrFail() : null;
        $subscription = $this->subscriptions->create(
            $tenant, $bundle, $data['starts_on'], $data['ends_on'] ?? null, $data['status'] ?? Subscription::PENDING, $data['notes'] ?? null,
        );

        return response()->json($subscription->load('bundle:id,code,name'), 201);
    }

    public function transition(Request $request, Tenant $tenant, string $subscription): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Subscription::TRANSITIONS))],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $row = $this->inTenant($tenant, fn () => Subscription::query()->findOrFail($subscription));

        return response()->json($this->subscriptions->transition($row, $data['status'], $data['reason']));
    }

    public function reschedule(Request $request, Tenant $tenant, string $subscription): JsonResponse
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $row = $this->inTenant($tenant, fn () => Subscription::query()->findOrFail($subscription));

        return response()->json($this->subscriptions->reschedule($row, $data['starts_on'], $data['ends_on'] ?? null));
    }
}
