<?php

namespace App\Domain\Identity\Services;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\AccessControl\Services\RoleService;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Entitlement\Services\CapacityException;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Tenant membership: who belongs to the tenant, with which roles and data scope.
 * All methods run in the tenant context (TenantUser is tenant-scoped); capacity
 * and escalation rules are enforced here, not in controllers.
 */
class MembershipService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AccessCache $cache,
        private readonly CapacityService $capacity,
        private readonly RoleService $roles,
        private readonly DataScopeService $scopes,
    ) {}

    /**
     * @param  array{name:string,email:string,password?:string}  $person
     * @param  list<string>  $roleIds
     * @param  list<array{scope_type:string,branch_id?:?string,business_unit_id?:?string}>  $dataScopes
     */
    public function add(string $tenantId, User $actor, array $person, array $roleIds, array $dataScopes): TenantUser
    {
        return DB::transaction(function () use ($tenantId, $actor, $person, $roleIds, $dataScopes) {
            $this->reserveUserSlot($tenantId);

            $user = User::query()->whereRaw('lower(email) = ?', [strtolower($person['email'])])->first();
            $isNew = $user === null;

            if ($isNew) {
                if (empty($person['password'])) {
                    throw new DomainException('A password is required for a new user.', 'PASSWORD_REQUIRED');
                }
                $user = new User(['name' => $person['name'], 'email' => $person['email'], 'password' => $person['password']]);
                $user->status = User::ACTIVE;
                $user->save();
            } elseif (TenantUser::query()->where('user_id', $user->id)->exists()) {
                throw new DomainException('This user is already a member of the organization.', 'MEMBER_EXISTS', 409);
            }

            $membership = new TenantUser;
            $membership->tenant_id = $tenantId;
            $membership->user_id = $user->id;
            // An existing account must accept the invitation itself; a brand-new account is created active.
            $membership->status = $isNew ? TenantUser::ACTIVE : TenantUser::INVITED;
            $membership->joined_at = $isNew ? now() : null;
            $membership->save();

            $this->assignRoles($membership, $actor, $roleIds);
            $this->replaceDataScopes($membership, $actor, $dataScopes);

            $this->cache->touchTenant($tenantId);
            $this->audit->record('membership.created', 'tenant_user', $membership->id, null,
                ['user_id' => $user->id, 'email' => $user->email, 'status' => $membership->status, 'roles' => $roleIds], $tenantId);

            return $membership;
        });
    }

    public function setStatus(TenantUser $membership, User $actor, string $status): TenantUser
    {
        return DB::transaction(function () use ($membership, $actor, $status) {
            if (! in_array($status, TenantUser::STATUSES, true) || $status === TenantUser::INVITED) {
                throw new DomainException('Invalid membership status.', 'INVALID_STATUS');
            }
            if ($membership->user_id === $actor->id) {
                throw new DomainException('You cannot change your own membership status.', 'SELF_CHANGE_REFUSED', 403);
            }

            $before = $membership->status;
            if ($before === TenantUser::INACTIVE && $status === TenantUser::ACTIVE) {
                $this->reserveUserSlot($membership->tenant_id);
            }

            $membership->status = $status;
            $membership->joined_at ??= $status === TenantUser::ACTIVE ? now() : null;
            $membership->save();

            if ($status !== TenantUser::ACTIVE) {
                $this->revokeTenantTokens($membership->user_id, $membership->tenant_id);
            }

            $this->cache->touchTenant($membership->tenant_id);
            $this->audit->record('membership.status_changed', 'tenant_user', $membership->id, ['status' => $before], ['status' => $status], $membership->tenant_id);

            return $membership;
        });
    }

    /** The invited user accepts their own pending invitation. */
    public function acceptInvitation(User $user, string $tenantId): TenantUser
    {
        return DB::transaction(function () use ($user, $tenantId) {
            $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $user->id)
                ->where('status', TenantUser::INVITED)->lockForUpdate()->first();
            if (! $membership) {
                throw new DomainException('No pending invitation.', 'NO_INVITATION', 404);
            }

            $membership->status = TenantUser::ACTIVE;
            $membership->joined_at = now();
            $membership->save();

            $this->cache->touchTenant($tenantId);
            $this->audit->record('membership.invitation_accepted', 'tenant_user', $membership->id, ['status' => 'INVITED'], ['status' => 'ACTIVE'], $tenantId);

            return $membership;
        });
    }

    /** @param list<string> $roleIds */
    public function setRoles(TenantUser $membership, User $actor, array $roleIds): TenantUser
    {
        return DB::transaction(function () use ($membership, $actor, $roleIds) {
            $before = $membership->roles()->pluck('roles.id')->all();
            $this->assignRoles($membership, $actor, $roleIds);
            $this->cache->touchTenant($membership->tenant_id);
            $this->audit->record('membership.roles_changed', 'tenant_user', $membership->id, ['roles' => $before], ['roles' => $roleIds], $membership->tenant_id);

            return $membership;
        });
    }

    /** @param list<array{scope_type:string,branch_id?:?string,business_unit_id?:?string}> $dataScopes */
    public function setDataScopes(TenantUser $membership, User $actor, array $dataScopes): TenantUser
    {
        return DB::transaction(function () use ($membership, $actor, $dataScopes) {
            $before = $membership->dataScopes()->get(['scope_type', 'branch_id', 'business_unit_id'])->toArray();
            $this->replaceDataScopes($membership, $actor, $dataScopes);
            $this->cache->touchTenant($membership->tenant_id);
            $this->audit->record('membership.data_scope_changed', 'tenant_user', $membership->id, ['scopes' => $before], ['scopes' => $dataScopes], $membership->tenant_id);

            return $membership;
        });
    }

    private function reserveUserSlot(string $tenantId): void
    {
        try {
            $this->capacity->reserve($tenantId, CapacityService::USER_LIMIT);
        } catch (CapacityException $e) {
            throw new DomainException('The user limit of this subscription has been reached.', 'CAPACITY_EXCEEDED', 409,
                ['limit_code' => $e->limitCode, 'limit' => $e->limit, 'used' => $e->used]);
        }
    }

    private function assignRoles(TenantUser $membership, User $actor, array $roleIds): void
    {
        $roleIds = array_values(array_unique($roleIds));
        $roles = Role::query()->forTenant($membership->tenant_id)->whereIn('id', $roleIds)->get();
        if ($roles->count() !== count($roleIds)) {
            throw new DomainException('Unknown role.', 'UNKNOWN_ROLE');
        }

        foreach ($roles as $role) {
            $this->roles->assertActorCanAssign($actor, $role);
        }

        $membership->roles()->sync($roles->mapWithKeys(fn ($r) => [$r->id => ['tenant_id' => $membership->tenant_id]])->all());
    }

    private function replaceDataScopes(TenantUser $membership, User $actor, array $dataScopes): void
    {
        $actorMembership = TenantUser::query()->where('user_id', $actor->id)->first();
        $actorScope = $actorMembership ? $this->scopes->resolve($membership->tenant_id, $actorMembership->id) : null;

        foreach ($dataScopes as $row) {
            $type = $row['scope_type'];
            if (! in_array($type, DataScope::TYPES, true)) {
                throw new DomainException('Invalid data scope type.', 'INVALID_SCOPE');
            }
            // Scopes can only be handed out within one's own reach.
            $reach = $actorScope === null
                || $actorScope['tenant']
                || ($type === DataScope::OWN)
                || ($type === DataScope::BRANCH && in_array($row['branch_id'] ?? null, $actorScope['branch_ids'], true))
                || ($type === DataScope::BUSINESS_UNIT && in_array($row['business_unit_id'] ?? null, $actorScope['business_unit_ids'], true));
            if (! $reach) {
                throw new DomainException('You cannot assign a data scope wider than your own.', 'PRIVILEGE_ESCALATION', 403);
            }
        }

        $membership->dataScopes()->delete();
        foreach ($dataScopes as $row) {
            $scope = new DataScope;
            $scope->tenant_id = $membership->tenant_id;
            $scope->tenant_user_id = $membership->id;
            $scope->scope_type = $row['scope_type'];
            $scope->branch_id = $row['scope_type'] === DataScope::BRANCH ? ($row['branch_id'] ?? null) : null;
            $scope->business_unit_id = $row['scope_type'] === DataScope::BUSINESS_UNIT ? ($row['business_unit_id'] ?? null) : null;
            $scope->save(); // composite foreign keys reject a branch/unit of another tenant
        }
    }

    private function revokeTenantTokens(string $userId, string $tenantId): void
    {
        DB::table('personal_access_tokens')->where('tokenable_id', $userId)
            ->where('abilities', 'like', '%"tenant:'.$tenantId.'"%')->delete();
    }
}
