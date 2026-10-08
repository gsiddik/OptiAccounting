<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\PermissionCatalog;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Bootstrap data only: seeds the permission registry and the system roles.
 * Seeded roles are plain permission collections; nothing authorizes by their name.
 */
class SystemRoleSynchronizer
{
    /** Template name => predicate selecting the permission codes it holds. */
    public const PLATFORM_ROLES = [
        'Platform Administrator' => 'all',
        'Platform Support (read-only)' => 'view',
    ];

    public const TENANT_ROLES = [
        'Tenant Administrator' => 'all',
        'Tenant Viewer' => 'view',
    ];

    public function syncPermissions(): void
    {
        foreach (PermissionCatalog::all() as $row) {
            Permission::query()->updateOrCreate(['code' => $row['code']], $row);
        }
    }

    /** Create/refresh platform system roles and every existing tenant's system roles. */
    public function syncRoles(): void
    {
        $this->syncPermissions();

        DB::transaction(function () {
            foreach (self::PLATFORM_ROLES as $name => $kind) {
                $role = Role::query()->platform()->whereRaw('lower(name) = ?', [strtolower($name)])->first()
                    ?? Role::query()->forceCreate(['scope' => 'platform', 'name' => $name, 'is_system' => true]);
                $this->grant($role, 'platform', $kind);
            }

            Tenant::query()->each(fn (Tenant $tenant) => $this->syncTenantRoles($tenant));
        });
    }

    public function syncTenantRoles(Tenant $tenant): void
    {
        foreach (self::TENANT_ROLES as $name => $kind) {
            $role = Role::query()->forTenant($tenant->id)->whereRaw('lower(name) = ?', [strtolower($name)])->first()
                ?? Role::query()->forceCreate([
                    'tenant_id' => $tenant->id, 'scope' => 'tenant', 'name' => $name, 'is_system' => true,
                ]);
            $this->grant($role, 'tenant', $kind);
        }
    }

    private function grant(Role $role, string $scope, string $kind): void
    {
        $codes = PermissionCatalog::codes($scope);
        if ($kind === 'view') {
            $codes = array_values(array_filter($codes, fn ($c) => str_ends_with($c, '.view')));
        }

        $ids = Permission::query()->where('scope', $scope)->whereIn('code', $codes)->pluck('id');
        $role->permissions()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [$id => ['scope' => $scope]])->all());
    }
}
