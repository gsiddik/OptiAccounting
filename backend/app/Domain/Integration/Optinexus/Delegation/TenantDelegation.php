<?php

namespace App\Domain\Integration\Optinexus\Delegation;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Entitlement\Services\CapacityException;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Entitlement\Services\EntitlementSnapshot;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MembershipService;
use App\Domain\Identity\Services\ReservedAddress;
use App\Domain\Integration\Optinexus\DelegableSet;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use App\Domain\Integration\Services\OutboxPublisher;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Tenant administration from the OptiEntry UI in `optinexus` mode (audit R08/A25). OptiNexus is the only authority: every
 * change is sent through its delegation API on behalf of the signed-in Admin Tenant (acting user = their OptiNexus subject), every
 * list is an answer of OptiNexus, and nothing is granted locally. What stays here is a projection of those answers:
 *  - a local user and membership per person OptiNexus lists (so seats are counted, data scopes can be set and audit has a name);
 *  - the data scope, which OptiEntry owns;
 *  - an Entry audit record of every change and of every refusal.
 * Seats are checked here before a person is created (CapacityService) and again when the projection is written; if the second check
 * fails, the access just granted in OptiNexus is withdrawn again so the two never disagree.
 */
class TenantDelegation
{
    public function __construct(
        private readonly NexusDelegationClient $nexus,
        private readonly CapacityService $capacity,
        private readonly MembershipService $memberships,
        private readonly EntitlementSnapshot $entitlements,
        private readonly AccessCache $cache,
        private readonly AuditService $audit,
        private readonly OutboxPublisher $outbox,
    ) {}

    // ------------------------------------------------------------------ what is possible

    /**
     * Whether this installation, tenant and user can delegate at all, without calling OptiNexus.
     *
     * @return array{available:bool,reason:?string,mode:string}
     */
    public function status(string $tenantId, User $actor): array
    {
        $reason = match (true) {
            ! OptinexusSettings::active() => 'DELEGATION_NOT_AVAILABLE',
            OptinexusSettings::missingForService() !== [] => 'DELEGATION_NOT_CONFIGURED',
            ! $this->nexusTenant($tenantId) => 'TENANT_NOT_LINKED',
            ! $actor->optinexus_subject => 'ACTOR_NOT_LINKED',
            default => null,
        };

        return ['available' => $reason === null, 'reason' => $reason, 'mode' => OptinexusSettings::active() ? 'optinexus' : 'standalone'];
    }

    // ------------------------------------------------------------------ users

    /** @return array{data:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function users(string $tenantId, User $actor, ?string $search, int $page, int $perPage): array
    {
        [$nexusTenant, $subject] = $this->link($tenantId, $actor);
        $answer = $this->guarded('delegation.user_list', $tenantId, fn () => $this->nexus->users($nexusTenant, $subject, $search, max(1, $page), max(1, min(100, $perPage))));

        $items = array_map(fn (array $row) => $this->userView($row), $answer['data']);
        $local = $this->localProjection($tenantId, array_column($items, 'id'));
        foreach ($items as &$item) {
            $item['local'] = $local[$item['id']] ?? null;
            $item['is_self'] = $item['id'] === $subject; // the screen never offers a person to remove or demote their own access
        }
        unset($item);

        return ['data' => $items, 'meta' => ['pagination' => $answer['pagination'], 'seats' => $this->seats($tenantId)]];
    }

    /**
     * Creates or links a person: OptiNexus creates the identity, the tenant membership and the access to this application (or finds
     * them, never a second identity for one e-mail), then the local projection and the data scope follow.
     *
     * @deprecated Compatibility path (OptiNexus deprecates `POST /users`, contract §2b). The console no longer offers it: a new person is
     *             invited ({@see self::invite()}) and sets their own password at OptiNexus. `initial_password` is still accepted and passed on
     *             exactly as before, never stored or logged here; do not build new screens on it.
     *
     * @param  array{name:string,email:string,initial_password?:?string,role_ids?:list<string>,data_scopes?:list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function addUser(string $tenantId, User $actor, array $input): array
    {
        ReservedAddress::assertHuman($input['email']); // before OptiNexus is asked to create anybody
        [$nexusTenant, $subject] = $this->link($tenantId, $actor, write: true);

        // Cheap refusal before anything is created anywhere. The authoritative check is repeated when the projection is written.
        if ($this->takesNewSeat($tenantId, null, $input['email'])) {
            $this->assertSeat($tenantId);
        }

        $payload = array_filter([
            'email' => $input['email'], 'name' => $input['name'],
            'initial_password' => $input['initial_password'] ?? null,
            'role_ids' => array_values(array_unique($input['role_ids'] ?? [])),
        ], fn ($value) => $value !== null);

        $answer = $this->guarded('delegation.user_add', $tenantId, fn () => $this->nexus->addUser($nexusTenant, $subject, $payload));
        $outcome = (string) ($answer['outcome'] ?? 'existing');
        $view = $this->userView($answer);

        try {
            $local = DB::transaction(function () use ($tenantId, $actor, $view, $outcome, $input) {
                $local = $this->project($tenantId, $actor, $view, $input['data_scopes'] ?? null);
                $this->cache->touchTenant($tenantId);
                $this->audit->record('delegation.user_added', 'user', $view['id'], null, [
                    'outcome' => $outcome, 'email' => $view['email'], 'nexus_user_id' => $view['id'],
                    'roles' => array_column($view['roles'], 'code'), 'projection' => $local['projection'],
                ], $tenantId);

                return $local;
            });
        } catch (DomainException $e) {
            if ($e->errorCode === 'CAPACITY_EXCEEDED') {
                $this->withdrawAfterSeatRace($tenantId, $nexusTenant, $subject, $view['id'], $outcome);
            }
            throw $e;
        }

        return $view + ['outcome' => $outcome, 'projection' => $local['projection'], 'local' => $local['row']];
    }

    /**
     * Replaces the application roles of a person. Roles are only ever those OptiNexus lists as assignable.
     *
     * @param  list<string>  $roleIds
     * @return array<string,mixed>
     */
    public function setUserRoles(string $tenantId, User $actor, string $nexusUserId, array $roleIds): array
    {
        [$nexusTenant, $subject] = $this->link($tenantId, $actor, write: true);
        $roleIds = array_values(array_unique($roleIds));

        $answer = $this->guarded('delegation.user_roles', $tenantId, fn () => $this->nexus->setUserRoles($nexusTenant, $subject, $nexusUserId, $roleIds), ['nexus_user_id' => $nexusUserId, 'role_ids' => $roleIds]);
        $view = $this->userView($answer);

        // The person's permissions changed in OptiNexus: the cached answer must not outlive this request.
        $this->cache->touchTenant($tenantId);
        $this->audit->record('delegation.user_roles_set', 'user', $nexusUserId, null, ['nexus_user_id' => $nexusUserId, 'roles' => array_column($view['roles'], 'code')], $tenantId);

        return $view + ['local' => $this->localProjection($tenantId, [$view['id']])[$view['id']] ?? null];
    }

    /** Takes OptiEntry away from a person. The identity and the membership in OptiNexus stay; here the membership ends and the seat is freed. */
    public function removeUser(string $tenantId, User $actor, string $nexusUserId): void
    {
        [$nexusTenant, $subject] = $this->link($tenantId, $actor, write: true);

        if ($nexusUserId === $subject) {
            throw new DelegationFailure('You cannot remove your own access.', 'SELF_REVOCATION_DENIED', 409, ['reference' => $this->reference()]);
        }

        $this->guarded('delegation.user_remove', $tenantId, fn () => $this->nexus->revokeUser($nexusTenant, $subject, $nexusUserId), ['nexus_user_id' => $nexusUserId]);

        DB::transaction(function () use ($tenantId, $nexusUserId) {
            $user = User::query()->where('optinexus_subject', $nexusUserId)->first();
            $membership = $user ? TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $user->id)->lockForUpdate()->first() : null;

            if ($membership && $membership->status !== TenantUser::INACTIVE) {
                $membership->forceFill(['status' => TenantUser::INACTIVE, 'optinexus_deactivated_at' => now()])->save();
                DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->where('abilities', 'like', '%"tenant:'.$tenantId.'"%')->delete();
                $this->outbox->event('membership.deactivated', $tenantId, ['user_subject' => $nexusUserId, 'reason' => 'application_access_revoked']);
            }

            $this->cache->touchTenant($tenantId);
            $this->audit->record('delegation.user_removed', 'user', $nexusUserId, null, ['nexus_user_id' => $nexusUserId, 'local_membership' => $membership?->id], $tenantId);
        });
    }

    // ------------------------------------------------------------------ invitations (contract §2b)

    /**
     * The invitations of this organisation and application, newest first: OptiNexus' answer, rendered as it is (status, delivery,
     * expiry, `can_resend`/`can_revoke`). Nothing is stored here, so there is nothing to keep in step.
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function invitations(string $tenantId, User $actor, ?string $status, ?string $search, int $page, int $perPage): array
    {
        [$nexusTenant, $subject] = $this->link($tenantId, $actor);
        $answer = $this->guarded('delegation.invitation_list', $tenantId, fn () => $this->nexus->invitations($nexusTenant, $subject, $status, $search, max(1, $page), max(1, min(100, $perPage))));

        return ['data' => array_map(fn (array $row) => $this->invitationView($row), $answer['data']), 'meta' => ['pagination' => $answer['pagination']]];
    }

    /**
     * Invites a NEW person. Nothing is granted now: OptiNexus e-mails the link, the invitee sets their own password there, and identity,
     * membership, application access and exactly these roles are granted when they accept. No password and no token passes through here;
     * the first sign-in through OIDC then creates the local membership (AccountLinker), so no local row is written at invitation time.
     *
     * @param  array{name:string,email:string,locale:string,role_ids?:list<string>}  $input
     * @return array<string,mixed> the invitation view
     */
    public function invite(string $tenantId, User $actor, array $input): array
    {
        ReservedAddress::assertHuman($input['email']); // before OptiNexus is asked to invite anybody
        [$nexusTenant, $subject] = $this->link($tenantId, $actor, write: true);

        // The invitee needs a seat at their first sign-in (AccountLinker); say so now rather than after they accepted. A person who already holds one needs none.
        if ($this->takesNewSeat($tenantId, null, $input['email'])) {
            $this->assertSeat($tenantId);
        }

        $payload = [
            'email' => $input['email'], 'name' => $input['name'],
            'role_ids' => array_values(array_unique($input['role_ids'] ?? [])),
            'locale' => $input['locale'],
        ];

        $answer = $this->guarded('delegation.invitation_create', $tenantId, fn () => $this->nexus->invite($nexusTenant, $subject, $payload), ['role_ids' => $payload['role_ids']]);
        $view = $this->invitationView($answer);

        $this->audit->record('delegation.invitation_created', 'invitation', $view['id'], null, [
            'email' => $view['email'], 'locale' => $view['locale'], 'roles' => array_column($view['roles'], 'code'), 'expires_at' => $view['expires_at'],
        ], $tenantId);

        return $view;
    }

    /** New link and expiry (the old link dies at once) and the e-mail is queued again. @return array<string,mixed> */
    public function resendInvitation(string $tenantId, User $actor, string $invitationId): array
    {
        [$nexusTenant, $subject] = $this->link($tenantId, $actor, write: true);

        $answer = $this->guarded('delegation.invitation_resend', $tenantId, fn () => $this->nexus->resendInvitation($nexusTenant, $subject, $invitationId), ['invitation_id' => $invitationId]);
        $view = $this->invitationView($answer);

        $this->audit->record('delegation.invitation_resent', 'invitation', $invitationId, null, ['email' => $view['email'], 'send_count' => $view['send_count'], 'expires_at' => $view['expires_at']], $tenantId);

        return $view;
    }

    /** The link stops working. Revoking an invitation that is already revoked is answered by OptiNexus unchanged. @return array<string,mixed> */
    public function revokeInvitation(string $tenantId, User $actor, string $invitationId, ?string $reason): array
    {
        [$nexusTenant, $subject] = $this->link($tenantId, $actor, write: true);

        $answer = $this->guarded('delegation.invitation_revoke', $tenantId, fn () => $this->nexus->revokeInvitation($nexusTenant, $subject, $invitationId, $reason), ['invitation_id' => $invitationId]);
        $view = $this->invitationView($answer);

        $this->audit->record('delegation.invitation_revoked', 'invitation', $invitationId, null, ['email' => $view['email'], 'status' => $view['status'], 'reason' => $reason], $tenantId);

        return $view;
    }

    // ------------------------------------------------------------------ roles and catalog

    /**
     * What can be assigned (roles) and what can be put into a role (permissions with their state).
     *
     * @return array{permissions:list<array<string,mixed>>,roles:list<array<string,mixed>>}
     */
    public function catalog(string $tenantId, User $actor): array
    {
        [$nexusTenant, $subject] = $this->link($tenantId, $actor);
        $answer = $this->guarded('delegation.catalog', $tenantId, fn () => $this->nexus->catalog($nexusTenant, $subject));

        $offered = [];
        foreach ((array) ($answer['delegable_permissions'] ?? []) as $row) {
            $offered[$this->nexus->code((string) ($row['permission'] ?? ''))] = $row['capability'] ?? null;
        }

        $permissions = [];
        foreach (DB::table('permissions')->where('scope', 'tenant')->orderBy('group')->orderBy('code')->get(['code', 'group', 'description']) as $row) {
            // OptiNexus decides. A permission it does not offer is shown as unavailable, with the best explanation this side has.
            $state = array_key_exists($row->code, $offered) ? 'AVAILABLE' : (DelegableSet::allows($row->code) ? 'NOT_ENTITLED' : 'NOT_DELEGABLE');
            $permissions[] = ['code' => $row->code, 'group' => $row->group, 'description' => $row->description, 'state' => $state, 'capability' => $offered[$row->code] ?? null];
        }

        return ['permissions' => $permissions, 'roles' => array_map(fn (array $role) => $this->roleView($role), array_values((array) ($answer['roles'] ?? [])))];
    }

    /**
     * @param  array{name:string,description?:?string,permissions:list<string>}  $input
     * @return array<string,mixed>
     */
    public function createRole(string $tenantId, User $actor, array $input): array
    {
        [$nexusTenant, $subject] = $this->link($tenantId, $actor, write: true);
        $codes = $this->knownCodes($input['permissions']);

        // A double click or a repeated request must not create the role twice (OptiNexus allows equal names).
        $lock = Cache::lock("delegation:role-create:{$tenantId}:".hash('sha256', mb_strtolower($input['name'])), 15);
        if (! $lock->get()) {
            throw new DelegationFailure('This role is already being created.', 'DELEGATION_IN_PROGRESS', 409, ['reference' => $this->reference()]);
        }

        try {
            $existing = $this->guarded('delegation.role_create', $tenantId, fn () => $this->nexus->catalog($nexusTenant, $subject));
            foreach ((array) ($existing['roles'] ?? []) as $role) {
                if (mb_strtolower((string) ($role['name'] ?? '')) === mb_strtolower($input['name'])) {
                    throw new DelegationFailure('A role with this name already exists.', 'ROLE_NAME_TAKEN', 409, ['reference' => $this->reference()]);
                }
            }

            $answer = $this->guarded('delegation.role_create', $tenantId, fn () => $this->nexus->createRole($nexusTenant, $subject, [
                'name' => $input['name'], 'description' => $input['description'] ?? null, 'permissions' => array_map(fn (string $code) => $this->nexus->key($code), $codes),
            ]), ['permissions' => $codes]);
        } finally {
            $lock->release();
        }

        $role = $this->roleView($answer);
        $this->cache->touchTenant($tenantId);
        $this->audit->record('delegation.role_created', 'role', $role['id'], null, ['name' => $role['name'], 'code' => $role['code'], 'permissions' => $role['permissions']], $tenantId);

        return $role;
    }

    /**
     * @param  array{name?:string,description?:?string,permissions?:list<string>}  $input
     * @return array<string,mixed>
     */
    public function updateRole(string $tenantId, User $actor, string $roleId, array $input): array
    {
        [$nexusTenant, $subject] = $this->link($tenantId, $actor, write: true);

        $body = [];
        if (array_key_exists('name', $input)) {
            $body['name'] = $input['name'];
        }
        if (array_key_exists('description', $input)) {
            $body['description'] = $input['description'];
        }
        if (array_key_exists('permissions', $input)) {
            $body['permissions'] = array_map(fn (string $code) => $this->nexus->key($code), $this->knownCodes($input['permissions']));
        }

        $answer = $this->guarded('delegation.role_update', $tenantId, fn () => $this->nexus->updateRole($nexusTenant, $subject, $roleId, $body), ['role_id' => $roleId]);
        $role = $this->roleView($answer);

        // Everyone holding this role may have gained or lost permissions.
        $this->cache->touchTenant($tenantId);
        $this->audit->record('delegation.role_updated', 'role', $roleId, null, ['name' => $role['name'], 'permissions' => $role['permissions']], $tenantId);

        return $role;
    }

    public function deleteRole(string $tenantId, User $actor, string $roleId): void
    {
        [$nexusTenant, $subject] = $this->link($tenantId, $actor, write: true);

        $this->guarded('delegation.role_delete', $tenantId, fn () => $this->nexus->deleteRole($nexusTenant, $subject, $roleId), ['role_id' => $roleId]);

        $this->cache->touchTenant($tenantId);
        $this->audit->record('delegation.role_deleted', 'role', $roleId, null, null, $tenantId);
    }

    // ------------------------------------------------------------------ projection

    /**
     * Writes what OptiNexus said about a person into the local tables. Only a missing person gets a user row (no password, signs in at
     * OptiNexus only) and an INVITED membership that the first sign-in activates; an e-mail that already belongs to another local
     * account is never claimed here, because only a verified sign-in may link accounts (AccountLinker).
     *
     * @param  array<string,mixed>  $view
     * @param  list<array<string,mixed>>|null  $dataScopes
     * @return array{projection:string,row:?array<string,mixed>}
     */
    private function project(string $tenantId, User $actor, array $view, ?array $dataScopes): array
    {
        $subject = (string) $view['id'];
        $user = User::query()->where('optinexus_subject', $subject)->first();

        if (! $user) {
            $sameEmail = User::query()->whereRaw('lower(email) = ?', [mb_strtolower((string) $view['email'])])->first();
            if ($sameEmail) {
                // A local account already uses this address. Linking is left to the person's first verified sign-in.
                $this->audit->record('delegation.projection_deferred', 'user', $subject, null, ['reason' => 'email_in_use_locally', 'local_user_id' => $sameEmail->id], $tenantId);

                return ['projection' => 'DEFERRED', 'row' => null];
            }

            $user = new User(['name' => (string) $view['name'], 'email' => mb_strtolower((string) $view['email'])]);
            $user->status = User::ACTIVE;
            $user->optinexus_subject = $subject;
            $user->save();
        }

        $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $user->id)->lockForUpdate()->first();
        $projection = 'EXISTING';

        if (! $membership) {
            $this->reserveSeat($tenantId);

            $membership = new TenantUser;
            $membership->tenant_id = $tenantId;
            $membership->user_id = $user->id;
            $membership->status = TenantUser::INVITED; // the first verified sign-in activates it
            $membership->save();
            $this->memberships->setDataScopes($membership, $actor, $dataScopes ?? [['scope_type' => DataScope::OWN]]);
            $this->outbox->event('membership.provisioned', $tenantId, ['user_subject' => $subject, 'membership_id' => $membership->id]);
            $projection = 'CREATED';
        } elseif ($membership->status === TenantUser::INACTIVE && $membership->optinexus_deactivated_at !== null) {
            // Removed earlier through OptiNexus and now added again: undo exactly that.
            $this->reserveSeat($tenantId);
            $membership->forceFill(['status' => $membership->joined_at ? TenantUser::ACTIVE : TenantUser::INVITED, 'optinexus_deactivated_at' => null])->save();
            $projection = 'REACTIVATED';
        }

        return ['projection' => $projection, 'row' => $this->localProjection($tenantId, [$subject])[$subject] ?? null];
    }

    /**
     * @param  list<string>  $subjects
     * @return array<string,array<string,mixed>> OptiNexus user id => local membership facts
     */
    private function localProjection(string $tenantId, array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        $users = User::query()->whereIn('optinexus_subject', $subjects)->get(['id', 'optinexus_subject', 'last_login_at'])->keyBy('id');
        $members = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('user_id', $users->keys())->with('dataScopes')->get();

        $out = [];
        foreach ($members as $member) {
            $user = $users->get($member->user_id);
            $out[(string) $user->optinexus_subject] = [
                'member_id' => $member->id,
                'status' => $member->status,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'data_scopes' => $member->dataScopes->map(fn ($scope) => $scope->only(['id', 'scope_type', 'branch_id', 'business_unit_id']))->values()->all(),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ seats

    /** @return array{limit:?int,used:int,remaining:?int} */
    private function seats(string $tenantId): array
    {
        $limit = $this->capacity->limit($tenantId, CapacityService::USER_LIMIT);
        $used = $this->capacity->used($tenantId, CapacityService::USER_LIMIT);

        return ['limit' => $limit, 'used' => $used, 'remaining' => $limit === null ? null : max(0, $limit - $used)];
    }

    /** True unless the person already holds a seat (an existing, not deactivated membership) in this tenant. */
    private function takesNewSeat(string $tenantId, ?string $subject, string $email): bool
    {
        return ! DB::table('tenant_users as tu')->join('users as u', 'u.id', '=', 'tu.user_id')
            ->where('tu.tenant_id', $tenantId)->where('tu.status', '<>', TenantUser::INACTIVE)
            ->where(fn ($q) => $q->whereRaw('lower(u.email) = ?', [mb_strtolower($email)])->when($subject, fn ($w) => $w->orWhere('u.optinexus_subject', $subject)))
            ->exists();
    }

    private function assertSeat(string $tenantId): void
    {
        DB::transaction(fn () => $this->reserveSeat($tenantId));
    }

    /** Call inside a transaction: locks the tenant row, so two concurrent creations near the limit cannot both pass. */
    private function reserveSeat(string $tenantId): void
    {
        try {
            $this->capacity->reserve($tenantId, CapacityService::USER_LIMIT);
        } catch (CapacityException $e) {
            throw new DomainException('The user limit of this subscription has been reached.', 'CAPACITY_EXCEEDED', 409, ['limit_code' => $e->limitCode, 'limit' => $e->limit, 'used' => $e->used]);
        }
    }

    /** The seat ran out between the check and the write: take the access just granted back, so OptiNexus and OptiEntry agree. */
    private function withdrawAfterSeatRace(string $tenantId, string $nexusTenant, string $actorSubject, string $nexusUserId, string $outcome): void
    {
        if ($outcome === 'existing') {
            return; // the person had access before this request; nothing was granted
        }

        try {
            $this->nexus->revokeUser($nexusTenant, $actorSubject, $nexusUserId);
            $this->audit->record('delegation.user_withdrawn', 'user', $nexusUserId, null, ['reason' => 'user_limit_reached', 'outcome' => $outcome], $tenantId);
        } catch (\Throwable $e) {
            Log::warning('Delegation: could not withdraw access after the user limit was reached.', ['tenant_id' => $tenantId, 'nexus_user_id' => $nexusUserId, 'error' => $e->getMessage()]);
            $this->audit->record('delegation.user_withdraw_failed', 'user', $nexusUserId, null, ['reason' => 'user_limit_reached', 'outcome' => $outcome], $tenantId);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  bool  $write  a change: refused while the subscription is read-only or inactive, like every other mutation
     * @return array{0:string,1:string} [OptiNexus tenant id, acting OptiNexus user id]
     */
    private function link(string $tenantId, User $actor, bool $write = false): array
    {
        $status = $this->status($tenantId, $actor);
        $reference = $this->reference();

        $failure = match ($status['reason']) {
            null => null,
            'DELEGATION_NOT_AVAILABLE' => new DelegationFailure('Users and roles are managed in this application when it is the identity authority.', 'DELEGATION_NOT_AVAILABLE', 409, ['reference' => $reference]),
            'DELEGATION_NOT_CONFIGURED' => new DelegationFailure('Delegation is not set up for this installation in OptiNexus.', 'DELEGATION_NOT_CONFIGURED', 503, ['reference' => $reference]),
            'TENANT_NOT_LINKED' => new DelegationFailure('This organization is not linked to OptiNexus.', 'TENANT_NOT_LINKED', 409, ['reference' => $reference]),
            default => new DelegationFailure('Only a person who signed in through OptiNexus can manage users and roles.', 'ACTOR_NOT_LINKED', 403, ['reference' => $reference]),
        };
        if ($failure) {
            throw $failure;
        }

        if ($write) {
            $mode = $this->entitlements->forTenant(Tenant::query()->findOrFail($tenantId))['subscription']['mode'];
            if ($mode === EntitlementSnapshot::READ_ONLY) {
                throw new DelegationFailure('The subscription is read-only: nothing can be changed.', 'SUBSCRIPTION_READ_ONLY', 403, ['reference' => $reference]);
            }
            if ($mode === EntitlementSnapshot::NONE) {
                throw new DelegationFailure('The subscription is not active.', 'SUBSCRIPTION_INACTIVE', 403, ['reference' => $reference]);
            }
        }

        return [(string) $this->nexusTenant($tenantId), (string) $actor->optinexus_subject];
    }

    private function nexusTenant(string $tenantId): ?string
    {
        $value = DB::table('tenants')->where('id', $tenantId)->value('optinexus_tenant_id');

        return $value === null ? null : (string) $value;
    }

    private function reference(): string
    {
        $id = request()->attributes->get('request_id');

        return is_string($id) && $id !== '' ? $id : '';
    }

    /**
     * Runs one OptiNexus call. A refusal OptiNexus decided (not an outage, not bad input) is recorded in the audit log, because a
     * request for a grant outside the delegable set or for someone else's role is exactly what an auditor looks for.
     *
     * @template T
     *
     * @param  callable():T  $call
     * @param  array<string,mixed>  $context
     * @return T
     */
    private function guarded(string $action, string $tenantId, callable $call, array $context = []): mixed
    {
        try {
            return $call();
        } catch (ValidationException $e) {
            throw $e;
        } catch (DelegationFailure $e) {
            if ($e->status >= 400 && $e->status < 500) {
                $this->audit->record('delegation.refused', 'delegation', null, null, ['action' => $action, 'code' => $e->errorCode, 'details' => array_diff_key($e->details, ['reference' => 1])] + $context, $tenantId);
            }
            throw $e;
        }
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function knownCodes(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        $known = DB::table('permissions')->where('scope', 'tenant')->whereIn('code', $codes)->pluck('code')->all();
        $unknown = array_values(array_diff($codes, $known));
        if ($unknown !== []) {
            throw new DelegationFailure('Unknown permission code.', 'UNKNOWN_PERMISSION', 422, ['permissions' => $unknown, 'reference' => $this->reference()]);
        }

        return $codes;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function roleView(array $row): array
    {
        $prefix = OptinexusSettings::applicationCode().'.';
        $codes = array_values(array_map(
            fn ($key) => $this->nexus->code((string) $key),
            array_filter((array) ($row['permissions'] ?? []), fn ($key) => is_string($key) && str_starts_with($key, $prefix)),
        ));
        sort($codes);

        return [
            'id' => (string) $row['id'], 'code' => (string) ($row['code'] ?? ''), 'name' => (string) ($row['name'] ?? ''), 'description' => $row['description'] ?? null,
            'template' => (bool) ($row['template'] ?? false), 'editable' => (bool) ($row['editable'] ?? false),
            'permissions' => $codes, 'permission_count' => count($codes),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function userView(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? ''), 'email' => (string) ($row['email'] ?? ''), 'status' => $row['status'] ?? null,
            'membership_status' => $row['membership_status'] ?? null, 'is_tenant_admin' => (bool) ($row['is_tenant_admin'] ?? false),
            'application_access' => $row['application_access'] ?? null,
            'roles' => array_map(fn (array $role) => $this->roleView($role), array_values((array) ($row['roles'] ?? []))),
        ];
    }

    /**
     * An invitation as OptiNexus describes it, field by field: anything else in the answer (a token or its hash must never be part of it,
     * contract §2b) is not passed on.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function invitationView(array $row): array
    {
        $delivery = (array) ($row['delivery'] ?? []);
        $by = $row['invited_by'] ?? null;

        return [
            'id' => (string) $row['id'], 'email' => (string) ($row['email'] ?? ''), 'name' => (string) ($row['name'] ?? ''),
            'status' => isset($row['status']) ? strtoupper((string) $row['status']) : null, 'locale' => $row['locale'] ?? null,
            'role_ids' => array_values(array_map('strval', (array) ($row['role_ids'] ?? []))),
            'roles' => array_values(array_map(
                fn (array $role) => ['id' => (string) ($role['id'] ?? ''), 'code' => (string) ($role['code'] ?? ''), 'name' => (string) ($role['name'] ?? '')],
                array_filter((array) ($row['roles'] ?? []), 'is_array'),
            )),
            'invited_by' => is_array($by) ? ['id' => (string) ($by['id'] ?? ''), 'name' => (string) ($by['name'] ?? '')] : null,
            'created_at' => $row['created_at'] ?? null, 'expires_at' => $row['expires_at'] ?? null, 'last_sent_at' => $row['last_sent_at'] ?? null,
            'send_count' => (int) ($row['send_count'] ?? 0),
            'delivery' => ['status' => isset($delivery['status']) ? strtoupper((string) $delivery['status']) : null, 'attempted_at' => $delivery['attempted_at'] ?? null, 'error_code' => $delivery['error_code'] ?? null],
            'accepted_at' => $row['accepted_at'] ?? null, 'revoked_at' => $row['revoked_at'] ?? null,
            'can_resend' => (bool) ($row['can_resend'] ?? false), 'can_revoke' => (bool) ($row['can_revoke'] ?? false),
        ];
    }
}
