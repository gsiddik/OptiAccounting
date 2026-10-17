<?php

namespace App\Domain\AccessControl\Services;

/**
 * Where a member's tenant permissions come from (docs/architecture/SAAS_ARCHITECTURE.md §1): local roles in
 * `standalone` mode, OptiNexus in `optinexus` mode. EffectiveAccess asks this and never looks at the mode.
 */
interface PermissionSource
{
    /** @return list<string> permission codes (`resource.action`) the member holds in the tenant */
    public function tenantPermissions(string $tenantId, string $tenantUserId): array;

    /**
     * Each permission code the member holds with the scope it was granted at (TENANT for local roles; GLOBAL, APPLICATION, TENANT or
     * OWN as OptiNexus grants it). The scope belongs to the permission, never to the member as a whole.
     *
     * @return array<string,string> code => scope
     */
    public function tenantGrants(string $tenantId, string $tenantUserId): array;
}
