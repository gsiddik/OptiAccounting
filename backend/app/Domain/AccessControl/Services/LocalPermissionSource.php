<?php

namespace App\Domain\AccessControl\Services;

use Illuminate\Support\Facades\DB;

/** Permissions from the tenant's own roles (standalone mode). Cached per tenant version. */
class LocalPermissionSource implements PermissionSource
{
    public function __construct(private readonly AccessCache $cache) {}

    public function tenantPermissions(string $tenantId, string $tenantUserId): array
    {
        return $this->cache->rememberForTenant($tenantId, "perm:{$tenantUserId}", fn () => DB::table('tenant_user_roles as ur')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.tenant_id', $tenantId)->where('ur.tenant_user_id', $tenantUserId)
            ->where('p.scope', 'tenant')->distinct()->pluck('p.code')->all());
    }
}
