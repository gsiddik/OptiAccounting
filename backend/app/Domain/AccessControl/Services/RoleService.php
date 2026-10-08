<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Tenant and platform role management. Roles are only named permission sets.
 * Privilege-escalation guard: nobody can put a permission into a role (or assign
 * a role) that they do not hold themselves.
 */
class RoleService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AccessCache $cache,
        private readonly EffectiveAccess $access,
    ) {}

    /** @param list<string> $permissionCodes */
    public function create(string $scope, ?string $tenantId, User $actor, string $name, ?string $description, array $permissionCodes): Role
    {
        return DB::transaction(function () use ($scope, $tenantId, $actor, $name, $description, $permissionCodes) {
            $permissions = $this->resolvePermissions($scope, $permissionCodes);
            $this->assertActorHolds($actor, $scope, $tenantId, $permissions->pluck('code')->all());

            $this->assertNameFree($scope, $tenantId, $name, null);

            $role = new Role(['name' => $name, 'description' => $description]);
            $role->scope = $scope;
            $role->tenant_id = $tenantId;
            $role->is_system = false;
            $role->save();
            $this->sync($role, $scope, $permissions);

            $this->touch($scope, $tenantId);
            $this->audit->record('role.created', 'role', $role->id, null, ['name' => $name, 'permissions' => $permissions->pluck('code')->sort()->values()->all()], $tenantId);

            return $role->load('permissions');
        });
    }

    /** @param list<string>|null $permissionCodes */
    public function update(Role $role, User $actor, ?string $name, ?string $description, ?array $permissionCodes): Role
    {
        return DB::transaction(function () use ($role, $actor, $name, $description, $permissionCodes) {
            if ($role->is_system) {
                throw new DomainException('System roles cannot be modified. Create a custom role instead.', 'SYSTEM_ROLE_IMMUTABLE', 403);
            }

            $before = ['name' => $role->name, 'permissions' => $role->permissions()->pluck('code')->sort()->values()->all()];

            if ($name !== null && $name !== $role->name) {
                $this->assertNameFree($role->scope, $role->tenant_id, $name, $role->id);
                $role->name = $name;
            }
            if ($description !== null) {
                $role->description = $description;
            }
            $role->save();

            if ($permissionCodes !== null) {
                $permissions = $this->resolvePermissions($role->scope, $permissionCodes);
                $this->assertActorHolds($actor, $role->scope, $role->tenant_id, $permissions->pluck('code')->all());
                $this->sync($role, $role->scope, $permissions);
            }

            $this->touch($role->scope, $role->tenant_id);
            $this->audit->record('role.updated', 'role', $role->id, $before,
                ['name' => $role->name, 'permissions' => $role->permissions()->pluck('code')->sort()->values()->all()], $role->tenant_id);

            return $role->load('permissions');
        });
    }

    public function delete(Role $role): void
    {
        DB::transaction(function () use ($role) {
            if ($role->is_system) {
                throw new DomainException('System roles cannot be deleted.', 'SYSTEM_ROLE_IMMUTABLE', 403);
            }

            $assigned = $role->scope === Role::PLATFORM
                ? DB::table('platform_role_assignments')->where('role_id', $role->id)->exists()
                : DB::table('tenant_user_roles')->where('role_id', $role->id)->exists();
            if ($assigned) {
                throw new DomainException('This role is still assigned to users.', 'ROLE_IN_USE', 409);
            }

            $this->audit->record('role.deleted', 'role', $role->id, ['name' => $role->name], null, $role->tenant_id);
            $role->delete();
            $this->touch($role->scope, $role->tenant_id);
        });
    }

    /** Throws unless $actor already holds every permission of $role (used when assigning roles). */
    public function assertActorCanAssign(User $actor, Role $role): void
    {
        $this->assertActorHolds($actor, $role->scope, $role->tenant_id, $role->permissions()->pluck('code')->all());
    }

    private function resolvePermissions(string $scope, array $codes)
    {
        $permissions = Permission::query()->where('scope', $scope)->whereIn('code', $codes)->get();
        if ($permissions->count() !== count(array_unique($codes))) {
            throw new DomainException('Unknown permission code.', 'UNKNOWN_PERMISSION');
        }

        return $permissions;
    }

    private function sync(Role $role, string $scope, $permissions): void
    {
        $role->permissions()->sync($permissions->mapWithKeys(fn ($p) => [$p->id => ['scope' => $scope]])->all());
    }

    private function assertNameFree(string $scope, ?string $tenantId, string $name, ?string $ignoreId): void
    {
        $query = Role::query()->where('scope', $scope)->whereRaw('lower(name) = ?', [strtolower($name)]);
        $scope === Role::PLATFORM ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
        if ($ignoreId) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->exists()) {
            throw new DomainException('A role with this name already exists.', 'ROLE_NAME_TAKEN');
        }
    }

    private function assertActorHolds(User $actor, string $scope, ?string $tenantId, array $codes): void
    {
        if ($scope === Role::PLATFORM) {
            $held = $this->access->platformPermissions($actor->id);
        } else {
            $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $actor->id)->first();
            $held = $membership ? $this->access->tenantPermissions($tenantId, $membership->id) : [];
        }

        $excess = array_values(array_diff($codes, $held));
        if ($excess) {
            throw new DomainException('You cannot grant permissions you do not hold.', 'PRIVILEGE_ESCALATION', 403, ['permissions' => $excess]);
        }
    }

    private function touch(string $scope, ?string $tenantId): void
    {
        $scope === Role::PLATFORM ? $this->cache->touchPlatform() : $this->cache->touchTenant($tenantId);
    }
}
