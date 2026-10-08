<?php

namespace App\Domain\AccessControl;

use App\Domain\Identity\Models\User;

/**
 * What a caller wants to do. `tenantId` null means platform scope.
 * `resource` (optional) carries the target record's tenant_id / branch_id /
 * business_unit_id / owner_id for tenant-match and data-scope checks.
 */
final class AccessRequest
{
    /** @param array{tenant_id?:?string,branch_id?:?string,business_unit_id?:?string,owner_id?:?string}|null $resource */
    public function __construct(
        public readonly User $user,
        public readonly ?string $tenantId,
        public readonly ?string $permission = null,
        public readonly ?string $module = null,
        public readonly ?string $feature = null,
        public readonly bool $mutating = false,
        public readonly ?array $resource = null,
    ) {}
}
