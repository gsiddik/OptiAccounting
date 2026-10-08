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
}
