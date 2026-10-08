<?php

namespace App\Domain\Identity\Services;

use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\RoleService;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;

/** Platform operators: global users that hold platform roles (no tenant context implied). */
class PlatformUserService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AccessCache $cache,
        private readonly RoleService $roles,
    ) {}

    /** @param list<string> $roleIds */
    public function create(User $actor, array $person, array $roleIds): User
    {
        return DB::transaction(function () use ($actor, $person, $roleIds) {
            $user = User::query()->whereRaw('lower(email) = ?', [strtolower($person['email'])])->first();
            if ($user) {
                throw new DomainException('A user with this e-mail already exists.', 'EMAIL_TAKEN');
            }

            $user = new User(['name' => $person['name'], 'email' => $person['email']]);
            $user->password = $person['password']; // hashed by the cast; never mass-assigned
            $user->status = User::ACTIVE;
            $user->save();

            $this->assign($user, $actor, $roleIds);
            $this->audit->record('platform_user.created', 'user', $user->id, null, ['email' => $user->email, 'roles' => $roleIds]);

            return $user;
        });
    }

    /** @param list<string> $roleIds */
    public function setRoles(User $user, User $actor, array $roleIds): User
    {
        return DB::transaction(function () use ($user, $actor, $roleIds) {
            $before = $user->platformRoles()->pluck('roles.id')->all();
            $this->assign($user, $actor, $roleIds);
            $this->audit->record('platform_user.roles_changed', 'user', $user->id, ['roles' => $before], ['roles' => $roleIds]);

            return $user;
        });
    }

    public function setStatus(User $user, User $actor, string $status): User
    {
        return DB::transaction(function () use ($user, $actor, $status) {
            if (! in_array($status, [User::ACTIVE, User::INACTIVE, User::SUSPENDED], true)) {
                throw new DomainException('Invalid user status.', 'INVALID_STATUS');
            }
            if ($user->id === $actor->id) {
                throw new DomainException('You cannot change your own status.', 'SELF_CHANGE_REFUSED', 403);
            }

            $before = $user->status;
            $user->status = $status;
            $user->save();

            if ($status !== User::ACTIVE) {
                $user->tokens()->delete(); // a deactivated account loses every session, in every tenant
            }

            $this->cache->touchPlatform();
            $this->audit->record('user.status_changed', 'user', $user->id, ['status' => $before], ['status' => $status]);

            return $user;
        });
    }

    private function assign(User $user, User $actor, array $roleIds): void
    {
        $roleIds = array_values(array_unique($roleIds));
        $roles = Role::query()->platform()->whereIn('id', $roleIds)->get();
        if ($roles->count() !== count($roleIds)) {
            throw new DomainException('Unknown platform role.', 'UNKNOWN_ROLE');
        }
        foreach ($roles as $role) {
            $this->roles->assertActorCanAssign($actor, $role);
        }

        $user->platformRoles()->sync($roles->pluck('id')->all());
        $this->cache->touchPlatform();
    }
}
