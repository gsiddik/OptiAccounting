<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\AccessDecision;
use App\Domain\AccessControl\AccessRequest;
use App\Domain\Entitlement\Services\EntitlementSnapshot;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use Illuminate\Support\Facades\DB;

/**
 * The one place that decides whether a request may proceed (OA0 §23):
 *
 *   user active AND tenant active AND membership active
 *   AND subscription allows AND module entitlement allows AND feature enabled
 *   AND permission granted AND data scope allows AND resource tenant matches.
 *
 * Status gates (user, tenant, membership) are read fresh on every call so a
 * suspension takes effect immediately; permission sets, data scopes and
 * entitlements come from version-keyed caches. Controllers, middleware and later
 * phases call evaluate(); nobody re-implements this chain.
 */
class EffectiveAccess
{
    public function __construct(
        private readonly EntitlementSnapshot $entitlements,
        private readonly DataScopeService $scopes,
        private readonly AccessCache $cache,
        private readonly PermissionSource $permissions,
    ) {}

    public function evaluate(AccessRequest $request): AccessDecision
    {
        return $request->tenantId === null ? $this->evaluatePlatform($request) : $this->evaluateTenant($request);
    }

    private function evaluatePlatform(AccessRequest $request): AccessDecision
    {
        if (! $request->user->isActive()) {
            return AccessDecision::deny(AccessDecision::USER_INACTIVE);
        }

        if ($request->permission !== null && ! in_array($request->permission, $this->platformPermissions($request->user->id), true)) {
            return AccessDecision::deny(AccessDecision::PERMISSION_DENIED);
        }

        return AccessDecision::allow();
    }

    private function evaluateTenant(AccessRequest $request): AccessDecision
    {
        $user = $request->user;
        $tenantId = $request->tenantId;

        if (! $user->isActive()) {
            return AccessDecision::deny(AccessDecision::USER_INACTIVE);
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return AccessDecision::deny(AccessDecision::TENANT_NOT_FOUND);
        }
        if (! $tenant->isActive()) {
            return AccessDecision::deny(AccessDecision::TENANT_INACTIVE);
        }

        $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $user->id)->first();
        if (! $membership || ! $membership->isActive()) {
            return AccessDecision::deny(AccessDecision::MEMBERSHIP_INACTIVE);
        }

        if ($request->resource !== null && array_key_exists('tenant_id', $request->resource)
            && $request->resource['tenant_id'] !== $tenantId) {
            return AccessDecision::deny(AccessDecision::TENANT_MISMATCH);
        }

        $readOnly = false;

        if ($request->module !== null || $request->feature !== null) {
            $snapshot = $this->entitlements->forTenant($tenant);
            $module = $request->module;

            if ($request->feature !== null) {
                $feature = $snapshot['features'][$request->feature] ?? null;
                if (! $feature || ! $feature['enabled']) {
                    return AccessDecision::deny(AccessDecision::FEATURE_NOT_ENTITLED);
                }
                $module ??= $feature['module'];
            }

            $subscriptionMode = $snapshot['subscription']['mode'];
            if ($subscriptionMode === EntitlementSnapshot::NONE) {
                return AccessDecision::deny(AccessDecision::SUBSCRIPTION_INACTIVE);
            }

            $moduleMode = $snapshot['modules'][$module]['mode'] ?? EntitlementSnapshot::NONE;
            if ($moduleMode === EntitlementSnapshot::NONE) {
                return AccessDecision::deny(AccessDecision::MODULE_NOT_ENTITLED);
            }

            if ($request->mutating) {
                if ($subscriptionMode === EntitlementSnapshot::READ_ONLY) {
                    return AccessDecision::deny(AccessDecision::SUBSCRIPTION_READ_ONLY);
                }
                if ($moduleMode === EntitlementSnapshot::READ_ONLY) {
                    return AccessDecision::deny(AccessDecision::MODULE_READ_ONLY);
                }
            }

            $readOnly = $subscriptionMode === EntitlementSnapshot::READ_ONLY || $moduleMode === EntitlementSnapshot::READ_ONLY;
        }

        if ($request->permission !== null
            && ! in_array($request->permission, $this->tenantPermissions($tenantId, $membership->id), true)) {
            return AccessDecision::deny(AccessDecision::PERMISSION_DENIED);
        }

        if ($request->resource !== null
            && ! $this->scopes->allows($this->scopes->resolve($tenantId, $membership->id), $request->resource, $user->id)) {
            return AccessDecision::deny(AccessDecision::DATA_SCOPE_DENIED);
        }

        return AccessDecision::allow($readOnly);
    }

    /** @return list<string> */
    public function tenantPermissions(string $tenantId, string $tenantUserId): array
    {
        return $this->permissions->tenantPermissions($tenantId, $tenantUserId);
    }

    /** @return list<string> */
    public function platformPermissions(string $userId): array
    {
        return $this->cache->rememberForPlatform("perm:{$userId}", fn () => DB::table('platform_role_assignments as pa')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'pa.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('pa.user_id', $userId)->where('p.scope', 'platform')->distinct()->pluck('p.code')->all());
    }

    /** Capabilities for the frontend, built from the same data as evaluate() so UI and API agree. */
    public function capabilities(AccessRequest $request): array
    {
        $tenantId = $request->tenantId;

        if ($tenantId === null) {
            return ['scope' => 'platform', 'permissions' => $this->platformPermissions($request->user->id)];
        }

        $tenant = Tenant::query()->findOrFail($tenantId);
        $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $request->user->id)->firstOrFail();
        $snapshot = $this->entitlements->forTenant($tenant);

        $modules = [];
        foreach ($snapshot['modules'] as $code => $info) {
            $modules[$code] = EntitlementSnapshot::weaker($snapshot['subscription']['mode'], $info['mode']);
        }

        $features = [];
        foreach ($snapshot['features'] as $code => $info) {
            $features[$code] = $info['enabled'] && ($modules[$info['module']] ?? 'NONE') !== 'NONE';
        }

        return [
            'scope' => 'tenant',
            'tenant' => $tenant->only(['id', 'code', 'name', 'status', 'timezone', 'default_currency']),
            'permissions' => $this->tenantPermissions($tenantId, $membership->id),
            'subscription' => $snapshot['subscription'],
            'modules' => $modules,
            'features' => $features,
            'data_scope' => $this->scopes->resolve($tenantId, $membership->id),
            'business_date' => $snapshot['date'],
        ];
    }
}
