<?php

namespace Tests\Support;

use App\Domain\Integration\Optinexus\OptinexusManifest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The tenant delegation API of OptiNexus (docs/ecosystem/GOVERNANCE_CONTRACTS.md §2) as an in-memory service, built from the
 * documented JSON: the same envelope, paths, error codes and checks as the real one, and fed with this repository's own manifest
 * (so the delegable flags and capability codes OptiEntry sends are exactly what the fake enforces). State lives in
 * $this->nexus['delegation'] and tests change it between calls. Invitations (§2b) are included: the token exists only inside this fake and in
 * its `mail` outbox (what OptiNexus e-mails), never in an answer, which is what the consumer tests rely on.
 */
trait FakesNexusDelegation
{
    protected const ADMIN_USER = '5a9d1f4e-7c3b-4b8a-8f60-2e1d9c0a2222'; // the default signed-in user (same as NEXUS_USER)

    /** Imports the manifest into the fake, the way OptiNexus imports it (additively), and resets the delegation state. */
    protected function nexusDelegationReset(): void
    {
        $permissions = [];
        foreach (app(OptinexusManifest::class)->build()['permissions'] as $row) {
            $permissions[$row['permission_key']] = ['name' => $row['name'], 'description' => $row['description'], 'capability' => $row['capability_code'], 'delegable' => $row['delegable'], 'status' => 'ACTIVE'];
        }

        $this->nexus['delegation'] = [
            'permissions' => $permissions,
            'entitled' => array_merge(DB::table('modules')->pluck('code')->all(), DB::table('features')->pluck('code')->all()),
            'admins' => [self::ADMIN_USER => true],
            'ecosystem_users' => [],
            'roles' => [
                // An application role template (no tenant): assignable when everything it carries is delegable.
                'tpl-accountant' => ['id' => '11111111-1111-4111-8111-111111111111', 'code' => 'ACCOUNTANT', 'name' => 'Accountant', 'description' => 'Day-to-day bookkeeping', 'tenant' => false, 'origin' => 'SEED',
                    'permissions' => ['optiaccounting.accounting.journal.view', 'optiaccounting.accounting.journal.create', 'optiaccounting.accounting.gl.view']],
                // A template carrying a non-delegable permission: never offered to the product.
                'tpl-owner' => ['id' => '22222222-2222-4222-8222-222222222222', 'code' => 'OWNER', 'name' => 'Owner', 'description' => 'Everything', 'tenant' => false, 'origin' => 'SEED',
                    'permissions' => ['optiaccounting.accounting.journal.view', 'optiaccounting.access.user.manage']],
            ],
            'users' => [
                self::ADMIN_USER => ['id' => self::ADMIN_USER, 'name' => 'Siti Aminah', 'email' => 'siti@example.test', 'status' => 'ACTIVE', 'membership_status' => 'ACTIVE', 'is_tenant_admin' => true, 'access' => 'ACTIVE', 'role_ids' => []],
            ],
            'identities' => [], // email => user id, for people that exist in OptiNexus without access to this application
            'user_roles' => [], // user id => [role ids] (also roles a delegation cannot manage)
            'audit' => [],
            'invitations' => [], // id => stored invitation (with its token, as OptiNexus would store only the hash)
            'mail' => [], // what OptiNexus e-mailed: the one place a token and a link exist
            'invitation_cooldown' => 60, 'invitation_resend_limit' => 3, 'invitation_ttl_hours' => 72,
            // The seat picture of this tenant and application as OptiNexus counts it (contract §2d): a state and, when LIMITED, the limit.
            'seat' => ['state' => 'LIMITED', 'limit' => 5],
        ];
    }

    /** @return array<string,mixed> */
    protected function nexusRole(string $key): array
    {
        return $this->nexus['delegation']['roles'][$key];
    }

    /** Puts an invitation straight into the fake (any state, any tenant or application) and returns its id; defaults: open, sent a day ago, of this tenant. */
    protected function nexusSeedInvitation(array $over = []): string
    {
        $id = (string) Str::uuid();
        $this->nexus['delegation']['invitations'][$id] = array_merge([
            'id' => $id, 'tenant' => self::NEXUS_TENANT, 'application' => 'optiaccounting', 'email' => 'seed'.substr($id, 0, 6).'@example.test', 'name' => 'Seeded Person', 'locale' => 'en', 'role_ids' => [],
            'status' => 'PENDING', 'invited_by' => self::ADMIN_USER, 'created_at' => now()->subDay()->toIso8601String(), 'expires_at' => now()->addDay(), 'last_sent_at' => now()->subDay()->toIso8601String(),
            'send_count' => 1, 'delivery_status' => 'SENT', 'delivery_attempted_at' => now()->subDay()->toIso8601String(), 'delivery_error_code' => null,
            'accepted_at' => null, 'revoked_at' => null, 'revoke_reason' => null, 'token' => 'tok'.bin2hex(random_bytes(16)),
        ], $over);

        return $id;
    }

    protected function nexusDelegationRespond(Request $request, string $path): mixed
    {
        $d = &$this->nexus['delegation'];
        $token = substr((string) ($request->header('Authorization')[0] ?? ''), 7);

        if (! str_contains((string) ($this->nexus['token_scopes'][$token] ?? ''), 'tenant.delegate') || ($d['scope_revoked'] ?? false)) {
            return $this->nexusFail('UNAUTHORIZED', 'The token is missing the required [tenant.delegate] scope.', 403);
        }
        if (! preg_match('#^/api/v1/delegation/tenants/([^/]+)(/.*)$#', $path, $m)) {
            return $this->nexusFail('RESOURCE_NOT_FOUND', 'The requested resource could not be found.', 404);
        }

        [, $tenantId, $rest] = $m;
        $method = $request->method();
        $input = $method === 'GET' || $method === 'DELETE' ? $this->nexusQuery($request) : $request->data();
        $actingId = $input['acting_user_id'] ?? null;

        if (! is_string($actingId) || ! Str::isUuid($actingId)) {
            return $this->nexusFail('VALIDATION_ERROR', 'The request contains invalid data.', 422, ['acting_user_id' => ['The acting user id field is required.']]);
        }
        if ($d['unbound'] ?? false) {
            return $this->nexusFail('SERVICE_ACCOUNT_NOT_BOUND', 'This service account is not bound to an application.', 403);
        }
        if (! isset($this->nexus['tenants'][$tenantId])) {
            return $this->nexusFail('RESOURCE_NOT_FOUND', 'The requested resource could not be found.', 404);
        }
        $tenant = $this->nexus['tenants'][$tenantId];
        if ($tenant['status'] !== 'ACTIVE') {
            return $this->nexusFail('TENANT_NOT_ACTIVE', 'The tenant is not active.', 403);
        }
        if (! in_array('optiaccounting', $tenant['applications'], true)) {
            return $this->nexusFail('APPLICATION_NOT_SUBSCRIBED', 'The tenant has no active subscription to this application.', 403);
        }
        $actor = $d['users'][$actingId] ?? null;
        if (! ($d['admins'][$actingId] ?? false) || ! $actor || $actor['status'] !== 'ACTIVE' || ($actor['access'] ?? null) !== 'ACTIVE') {
            return $this->nexusFail('ACTOR_NOT_TENANT_ADMIN', 'The acting user is not an Admin Tenant of this tenant for this application.', 403);
        }

        $d['audit'][] = ['method' => $method, 'path' => $rest, 'actor' => $actingId, 'correlation' => $request->header('X-Correlation-Id')[0] ?? null];

        // A test can make the next verified call fail the way OptiNexus would: ['status' => 409, 'code' => 'ROLE_IN_USE', 'details' => [...]].
        if ($forced = $d['force'] ?? null) {
            unset($d['force']);

            return $this->nexusFail($forced['code'], 'forced', $forced['status'], $forced['details'] ?? null, $forced['headers'] ?? []);
        }

        return match (true) {
            $method === 'GET' && $rest === '/catalog' => $this->nexusOk($this->nexusCatalog()),
            $method === 'GET' && $rest === '/users' => $this->nexusUsers($input),
            $method === 'POST' && $rest === '/users' => $this->nexusAddUser($input),
            $method === 'PUT' && preg_match('#^/users/([^/]+)/roles$#', $rest, $u) === 1 => $this->nexusSetRoles($u[1], $input),
            $method === 'DELETE' && preg_match('#^/users/([^/]+)$#', $rest, $u) === 1 => $this->nexusRevoke($u[1], $actingId),
            $method === 'POST' && $rest === '/roles' => $this->nexusCreateRole($input),
            $method === 'PUT' && preg_match('#^/roles/([^/]+)$#', $rest, $r) === 1 => $this->nexusUpdateRole($r[1], $input),
            $method === 'DELETE' && preg_match('#^/roles/([^/]+)$#', $rest, $r) === 1 => $this->nexusDeleteRole($r[1]),
            $method === 'GET' && $rest === '/invitations' => $this->nexusInvitations($input),
            $method === 'POST' && $rest === '/invitations' => $this->nexusInvite($input, $actingId),
            $method === 'POST' && preg_match('#^/invitations/([^/]+)/resend$#', $rest, $i) === 1 => $this->nexusResendInvitation($i[1]),
            $method === 'POST' && preg_match('#^/invitations/([^/]+)/revoke$#', $rest, $i) === 1 => $this->nexusRevokeInvitation($i[1], $input),
            default => $this->nexusFail('RESOURCE_NOT_FOUND', 'The requested resource could not be found.', 404),
        };
    }

    // ---------------------------------------------------------------- the delegable set

    /** @return list<string> keys of the delegable permissions of this tenant (delegable, active, capability entitled) */
    private function nexusDelegableKeys(): array
    {
        $d = $this->nexus['delegation'];
        $keys = [];
        foreach ($d['permissions'] as $key => $p) {
            if ($p['delegable'] && $p['status'] === 'ACTIVE' && ($p['capability'] === null || in_array($p['capability'], $d['entitled'], true))) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }

    /** @return array<string,array<string,mixed>> id => role: tenant roles and templates whose permissions are all delegable */
    private function nexusManageableRoles(): array
    {
        $delegable = $this->nexusDelegableKeys();
        $out = [];
        foreach ($this->nexus['delegation']['roles'] as $role) {
            if (array_diff($role['permissions'], $delegable) === []) {
                $out[$role['id']] = $role;
            }
        }
        uasort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    private function nexusRoleView(array $role): array
    {
        $keys = $role['permissions'];
        sort($keys);

        return ['id' => $role['id'], 'code' => $role['code'], 'name' => $role['name'], 'description' => $role['description'], 'template' => ! $role['tenant'],
            'editable' => $role['origin'] === 'DELEGATION' && $role['tenant'], 'permissions' => $keys];
    }

    private function nexusCatalog(): array
    {
        $d = $this->nexus['delegation'];

        return [
            'tenant_id' => self::NEXUS_TENANT, 'application_code' => 'optiaccounting',
            'delegable_permissions' => array_map(fn ($key) => ['permission' => $key, 'name' => $d['permissions'][$key]['name'], 'description' => $d['permissions'][$key]['description'], 'capability' => $d['permissions'][$key]['capability']], $this->nexusDelegableKeys()),
            'roles' => array_map(fn ($role) => $this->nexusRoleView($role), array_values($this->nexusManageableRoles())),
        ];
    }

    // ---------------------------------------------------------------- users

    private function nexusUserView(string $id): array
    {
        $d = $this->nexus['delegation'];
        $u = $d['users'][$id];
        $roles = array_values(array_filter($d['roles'], fn ($r) => in_array($r['id'], $d['user_roles'][$id] ?? [], true)));

        return ['id' => $id, 'name' => $u['name'], 'email' => $u['email'], 'status' => $u['status'], 'membership_status' => $u['membership_status'], 'is_tenant_admin' => $u['is_tenant_admin'],
            'application_access' => $u['access'], 'roles' => array_map(fn ($r) => $this->nexusRoleView($r), $roles)];
    }

    private function nexusUsers(array $input): mixed
    {
        $d = $this->nexus['delegation'];
        $search = mb_strtolower((string) ($input['search'] ?? ''));
        $rows = array_values(array_filter($d['users'], fn ($u) => $u['access'] !== null && ($search === '' || str_contains(mb_strtolower($u['name'].' '.$u['email']), $search))));
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $per = max(1, min(100, (int) ($input['per_page'] ?? 25)));
        $page = max(1, (int) ($input['page'] ?? 1));
        $slice = array_slice($rows, ($page - 1) * $per, $per);

        return $this->nexusOk(array_map(fn ($u) => $this->nexusUserView($u['id']), $slice), ['pagination' => ['current_page' => $page, 'per_page' => $per, 'total' => count($rows), 'last_page' => max(1, (int) ceil(count($rows) / $per))], 'seats' => $this->nexusSeatStatus()]);
    }

    private function nexusAddUser(array $input): mixed
    {
        $d = &$this->nexus['delegation'];
        $errors = [];
        if (! filter_var($input['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['The email must be a valid email address.'];
        }
        if (! is_string($input['name'] ?? null) || $input['name'] === '') {
            $errors['name'] = ['The name field is required.'];
        }
        if (isset($input['initial_password']) && mb_strlen((string) $input['initial_password']) < 12) {
            $errors['initial_password'] = ['The initial password must be at least 12 characters.'];
        }
        if ($errors) {
            return $this->nexusFail('VALIDATION_ERROR', 'The request contains invalid data.', 422, $errors);
        }

        $roleIds = array_values(array_unique($input['role_ids'] ?? []));
        $manageable = $this->nexusManageableRoles();
        if ($refused = array_values(array_filter($roleIds, fn ($id) => ! isset($manageable[$id])))) {
            return $this->nexusFail('ROLE_NOT_ASSIGNABLE', 'A role is unknown, belongs to another tenant or application, or carries permissions that are not delegable.', 422, ['role_ids' => $refused]);
        }

        $email = mb_strtolower(trim($input['email']));
        $existingId = collect($d['users'])->first(fn ($u) => $u['email'] === $email)['id'] ?? ($d['identities'][$email] ?? null);

        if ($existingId) {
            if (in_array($existingId, $d['ecosystem_users'], true) || ($d['users'][$existingId]['status'] ?? 'ACTIVE') === 'DISABLED') {
                return $this->nexusFail('IDENTITY_NOT_LINKABLE', 'This email belongs to an identity that cannot be added from a product.', 409);
            }
            if (($d['users'][$existingId]['membership_status'] ?? 'ACTIVE') !== 'ACTIVE') {
                return $this->nexusFail('MEMBERSHIP_NOT_ACTIVE', 'The tenant membership of this user was suspended by an operator.', 409);
            }
            $already = isset($d['users'][$existingId]) && $d['users'][$existingId]['access'] === 'ACTIVE';
            if (! $already && ($refusal = $this->nexusSeatRefusal(take: true, email: $email))) {
                return $refusal;
            }
            $d['users'][$existingId] ??= ['id' => $existingId, 'name' => $input['name'], 'email' => $email, 'status' => 'ACTIVE', 'membership_status' => 'ACTIVE', 'is_tenant_admin' => false, 'access' => null, 'role_ids' => []];
            $d['users'][$existingId]['access'] = 'ACTIVE';
            $outcome = $already ? 'existing' : 'linked';
            $id = $existingId;
        } else {
            if ($refusal = $this->nexusSeatRefusal(take: true, email: $email)) {
                return $refusal;
            }
            $id = (string) Str::uuid();
            $d['users'][$id] = ['id' => $id, 'name' => $input['name'], 'email' => $email, 'status' => ! empty($input['initial_password']) ? 'ACTIVE' : 'INVITED', 'membership_status' => 'ACTIVE', 'is_tenant_admin' => false, 'access' => 'ACTIVE', 'role_ids' => []];
            $outcome = 'created';
        }

        if ($roleIds !== []) {
            $d['user_roles'][$id] = array_values(array_unique([...($d['user_roles'][$id] ?? []), ...$roleIds]));
        }
        if ($hook = $d['after_add_user'] ?? null) {
            $hook($id); // a test can race another request into the window between OptiNexus' answer and the local write
        }

        return $this->nexusOk(['outcome' => $outcome] + $this->nexusUserView($id), [], $outcome === 'created' ? 201 : 200);
    }

    private function nexusTarget(string $id): mixed
    {
        $u = $this->nexus['delegation']['users'][$id] ?? null;
        if (! $u || $u['access'] === null) {
            return $this->nexusFail('USER_NOT_IN_TENANT', 'The user does not belong to this tenant and application.', 404);
        }
        if ($u['is_tenant_admin']) {
            return $this->nexusFail('TENANT_ADMIN_PROTECTED', 'An Admin Tenant can only be changed from the ecosystem portal.', 409);
        }

        return null;
    }

    private function nexusSetRoles(string $id, array $input): mixed
    {
        if ($refusal = $this->nexusTarget($id)) {
            return $refusal;
        }
        $roleIds = array_values(array_unique($input['role_ids'] ?? []));
        $manageable = $this->nexusManageableRoles();
        if ($refused = array_values(array_filter($roleIds, fn ($rid) => ! isset($manageable[$rid])))) {
            return $this->nexusFail('ROLE_NOT_ASSIGNABLE', 'A role is unknown, belongs to another tenant or application, or carries permissions that are not delegable.', 422, ['role_ids' => $refused]);
        }
        $d = &$this->nexus['delegation'];
        $kept = array_values(array_filter($d['user_roles'][$id] ?? [], fn ($rid) => ! isset($manageable[$rid]))); // roles a delegation cannot manage stay
        $d['user_roles'][$id] = array_values(array_unique([...$kept, ...$roleIds]));

        return $this->nexusOk($this->nexusUserView($id));
    }

    private function nexusRevoke(string $id, string $actingId): mixed
    {
        if ($refusal = $this->nexusTarget($id)) {
            return $refusal;
        }
        if ($id === $actingId) {
            return $this->nexusFail('SELF_REVOCATION_DENIED', 'You cannot remove your own access.', 409);
        }
        $d = &$this->nexus['delegation'];
        $d['users'][$id]['access'] = null;
        $d['user_roles'][$id] = [];
        $d['identities'][$d['users'][$id]['email']] = $id;
        $this->nexus['revoked_users'][] = $id; // a back-channel logout is queued in the real service

        return $this->nexusOk(null);
    }

    // ---------------------------------------------------------------- invitations (contract §2b)

    /** PENDING past its expiry is reported as EXPIRED, like the real service does. */
    private function nexusInvitationStatus(array $i): string
    {
        return $i['status'] === 'PENDING' && now()->greaterThan($i['expires_at']) ? 'EXPIRED' : $i['status'];
    }

    /** The invitation view of the contract: no token, no token hash. */
    private function nexusInvitationView(array $i): array
    {
        $d = $this->nexus['delegation'];
        $status = $this->nexusInvitationStatus($i);
        $open = in_array($status, ['PENDING', 'EXPIRED'], true);
        $roles = array_values(array_filter($d['roles'], fn ($r) => in_array($r['id'], $i['role_ids'], true)));
        $inviter = $d['users'][$i['invited_by']] ?? null;

        return [
            'id' => $i['id'], 'email' => $i['email'], 'name' => $i['name'], 'status' => $status, 'locale' => $i['locale'], 'role_ids' => $i['role_ids'],
            'roles' => array_map(fn ($r) => ['id' => $r['id'], 'code' => $r['code'], 'name' => $r['name']], $roles),
            'invited_by' => $inviter ? ['id' => $inviter['id'], 'name' => $inviter['name']] : null,
            'created_at' => $i['created_at'], 'expires_at' => $i['expires_at']->toIso8601String(), 'last_sent_at' => $i['last_sent_at'], 'send_count' => $i['send_count'],
            'delivery' => ['status' => $i['delivery_status'], 'attempted_at' => $i['delivery_attempted_at'], 'error_code' => $i['delivery_error_code']],
            'accepted_at' => $i['accepted_at'], 'revoked_at' => $i['revoked_at'],
            'can_resend' => $open && $i['send_count'] < $d['invitation_resend_limit'], 'can_revoke' => $open,
        ] + ($d['invitation_leak'] ?? false ? ['token' => $i['token'], 'token_hash' => hash('sha256', $i['token'])] : []); // a misbehaving OptiNexus, to prove the consumer drops it
    }

    /** @return array<string,mixed>|null the invitation of THIS tenant and application, or null (also for another tenant's id) */
    private function nexusOwnInvitation(string $id): ?array
    {
        $i = $this->nexus['delegation']['invitations'][$id] ?? null;

        return $i && $i['tenant'] === self::NEXUS_TENANT && $i['application'] === 'optiaccounting' ? $i : null;
    }

    private function nexusMail(array $i): void
    {
        $this->nexus['delegation']['mail'][] = ['to' => $i['email'], 'locale' => $i['locale'], 'link' => self::NEXUS_URL.'/invitations/'.$i['token']];
    }

    private function nexusInvite(array $input, string $actingId): mixed
    {
        $d = &$this->nexus['delegation'];
        $errors = [];
        if (! filter_var($input['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['The email must be a valid email address.'];
        }
        if (! is_string($input['name'] ?? null) || $input['name'] === '') {
            $errors['name'] = ['The name field is required.'];
        }
        if (isset($input['locale']) && ! in_array($input['locale'], ['en', 'id'], true)) {
            $errors['locale'] = ['The selected locale is invalid.'];
        }
        if (isset($input['initial_password']) || isset($input['password']) || isset($input['token'])) {
            $errors['password'] = ['An invitation never carries a password or token.']; // the real service has no such field
        }
        if ($errors) {
            return $this->nexusFail('VALIDATION_ERROR', 'The request contains invalid data.', 422, $errors);
        }

        $roleIds = array_values(array_unique($input['role_ids'] ?? []));
        $manageable = $this->nexusManageableRoles();
        if ($refused = array_values(array_filter($roleIds, fn ($id) => ! isset($manageable[$id])))) {
            return $this->nexusFail('ROLE_NOT_ASSIGNABLE', 'A role is unknown, belongs to another tenant or application, or carries permissions that are not delegable.', 422, ['role_ids' => $refused]);
        }

        $email = mb_strtolower(trim($input['email']));
        if (collect($d['users'])->contains(fn ($u) => $u['email'] === $email && $u['access'] === 'ACTIVE' && $u['membership_status'] === 'ACTIVE')) {
            return $this->nexusFail('ALREADY_MEMBER', 'This person is already a member with access to this application.', 409);
        }
        foreach ($d['invitations'] as $other) {
            if ($other['tenant'] === self::NEXUS_TENANT && $other['application'] === 'optiaccounting' && $other['email'] === $email && $other['status'] === 'PENDING') {
                return $this->nexusFail('INVITATION_PENDING', 'An invitation for this e-mail is already open. Resend it instead.', 409, ['invitation_id' => $other['id']]);
            }
        }

        if ($refusal = $this->nexusSeatRefusal(take: false, email: $email)) {
            return $refusal;
        }

        $id = (string) Str::uuid();
        $i = ['id' => $id, 'tenant' => self::NEXUS_TENANT, 'application' => 'optiaccounting', 'email' => $email, 'name' => $input['name'], 'locale' => $input['locale'] ?? 'en', 'role_ids' => $roleIds,
            'status' => 'PENDING', 'invited_by' => $actingId, 'created_at' => now()->toIso8601String(), 'expires_at' => now()->addHours($d['invitation_ttl_hours']),
            'last_sent_at' => now()->toIso8601String(), 'send_count' => 1, 'delivery_status' => 'QUEUED', 'delivery_attempted_at' => null, 'delivery_error_code' => null,
            'accepted_at' => null, 'revoked_at' => null, 'revoke_reason' => null, 'token' => 'tok'.bin2hex(random_bytes(16))];
        $d['invitations'][$id] = $i;
        $this->nexusMail($i);

        return $this->nexusOk($this->nexusInvitationView($i), [], 201);
    }

    private function nexusInvitations(array $input): mixed
    {
        $d = $this->nexus['delegation'];
        $search = mb_strtolower((string) ($input['search'] ?? ''));
        $status = strtoupper((string) ($input['status'] ?? ''));
        $rows = array_values(array_filter($d['invitations'], fn ($i) => $i['tenant'] === self::NEXUS_TENANT && $i['application'] === 'optiaccounting'
            && ($status === '' || $this->nexusInvitationStatus($i) === $status)
            && ($search === '' || str_contains(mb_strtolower($i['name'].' '.$i['email']), $search))));
        usort($rows, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['id'], $a['id']));

        $per = max(1, min(100, (int) ($input['per_page'] ?? 25)));
        $page = max(1, (int) ($input['page'] ?? 1));
        $slice = array_slice($rows, ($page - 1) * $per, $per);

        return $this->nexusOk(array_map(fn ($i) => $this->nexusInvitationView($i), $slice), ['pagination' => ['current_page' => $page, 'per_page' => $per, 'total' => count($rows), 'last_page' => max(1, (int) ceil(count($rows) / $per))], 'seats' => $this->nexusSeatStatus()]);
    }

    private function nexusResendInvitation(string $id): mixed
    {
        $d = &$this->nexus['delegation'];
        if (! $this->nexusOwnInvitation($id)) {
            return $this->nexusFail('INVITATION_NOT_FOUND', 'The invitation could not be found.', 404);
        }
        $i = &$d['invitations'][$id];
        $status = $this->nexusInvitationStatus($i);
        if (! in_array($status, ['PENDING', 'EXPIRED'], true)) {
            return $this->nexusFail('INVITATION_NOT_PENDING', 'The invitation is not open any more.', 409, ['status' => $status]);
        }
        $wait = $d['invitation_cooldown'] - (now()->timestamp - Carbon::parse($i['last_sent_at'])->timestamp);
        if ($wait > 0) {
            return $this->nexusFail('INVITATION_RESEND_COOLDOWN', 'Please wait before sending again.', 429, null, ['Retry-After' => (string) $wait]);
        }
        if ($i['send_count'] >= $d['invitation_resend_limit']) {
            return $this->nexusFail('INVITATION_RESEND_LIMIT', 'This invitation was sent the maximum number of times.', 409);
        }

        if ($status === 'EXPIRED' && ($refusal = $this->nexusSeatRefusal(take: false, email: $i['email']))) {
            return $refusal; // an expired invitation holds no seat: opening it again must find one
        }

        $i['token'] = 'tok'.bin2hex(random_bytes(16)); // the old link stops working at once
        $i['status'] = 'PENDING';
        $i['expires_at'] = now()->addHours($d['invitation_ttl_hours']);
        $i['last_sent_at'] = now()->toIso8601String();
        $i['send_count']++;
        $i['delivery_status'] = 'QUEUED';
        $i['delivery_error_code'] = null;
        $this->nexusMail($i);

        return $this->nexusOk($this->nexusInvitationView($i));
    }

    private function nexusRevokeInvitation(string $id, array $input): mixed
    {
        $d = &$this->nexus['delegation'];
        if (! $this->nexusOwnInvitation($id)) {
            return $this->nexusFail('INVITATION_NOT_FOUND', 'The invitation could not be found.', 404);
        }
        $i = &$d['invitations'][$id];
        $status = $this->nexusInvitationStatus($i);
        if ($status === 'ACCEPTED') {
            return $this->nexusFail('INVITATION_NOT_PENDING', 'The invitation is not open any more.', 409, ['status' => $status]);
        }
        if ($status !== 'REVOKED') {
            $i['status'] = 'REVOKED';
            $i['revoked_at'] = now()->toIso8601String();
            $i['revoke_reason'] = $input['reason'] ?? null;
        }

        return $this->nexusOk($this->nexusInvitationView($i));
    }

    // ---------------------------------------------------------------- seats (contract 2d)

    /** Whether somebody with this e-mail holds an ACTIVE assignment to this application in the fake. */
    private function nexusHoldsSeat(string $email): bool
    {
        return collect($this->nexus['delegation']['users'])->contains(fn ($u) => $u['email'] === $email && $u['access'] === 'ACTIVE');
    }

    /** @return list<string> the e-mails that hold a reservation: an open, unexpired invitation whose recipient holds no assignment yet */
    private function nexusReservedEmails(): array
    {
        $emails = [];
        foreach ($this->nexus['delegation']['invitations'] as $i) {
            if ($i['tenant'] === self::NEXUS_TENANT && $i['application'] === 'optiaccounting' && $this->nexusInvitationStatus($i) === 'PENDING' && ! $this->nexusHoldsSeat($i['email'])) {
                $emails[$i['email']] = true;
            }
        }

        return array_keys($emails);
    }

    /** @return array<string,mixed> what OptiNexus answers in `meta.seats`; the seat state of the fake is changed by the test ($this->nexus['delegation']['seat']) */
    private function nexusSeatStatus(): array
    {
        $seat = $this->nexus['delegation']['seat'];
        $state = $seat['state'];
        $limited = $state === 'LIMITED';
        $limit = $limited ? (int) $seat['limit'] : null;
        $used = collect($this->nexus['delegation']['users'])->where('access', 'ACTIVE')->count();
        $reserved = count($this->nexusReservedEmails());
        $overBy = $limited ? max(0, $used + $reserved - $limit) : 0;
        $available = $limited ? max(0, $limit - $used - $reserved) : null;
        $blocked = match (true) {
            $state === 'ENTITLEMENT_MISSING' => 'SEAT_ENTITLEMENT_MISSING',
            $state === 'ENTITLEMENT_INVALID' => 'SEAT_ENTITLEMENT_INVALID',
            $state === 'ENTITLEMENT_UNAVAILABLE' => 'SEAT_ENTITLEMENT_UNAVAILABLE',
            $limited && $overBy > 0 => 'SEAT_OVER_LIMIT',
            $limited && $available === 0 => 'SEAT_LIMIT_REACHED',
            default => null,
        };

        return ['application_code' => 'optiaccounting', 'controlled' => $state !== 'NOT_CONTROLLED', 'state' => $state, 'limit' => $limit, 'unlimited' => $state === 'UNLIMITED',
            'used' => $used, 'reserved' => $reserved, 'available' => $available, 'over_limit' => $overBy > 0, 'over_by' => $overBy, 'can_add' => $blocked === null, 'blocked_reason' => $blocked,
            'limit_source' => $limited ? 'SUBSCRIPTION' : null];
    }

    /**
     * The refusal OptiNexus gives an addition that does not fit, or null when it does. `take` is a person getting the assignment now (direct
     * add, operator, accept); otherwise it is an invitation reserving a seat. Somebody who already holds the seat, or (for a reservation)
     * already has one, needs nothing; a person with a reservation turns it into usage, which asks only that usage alone fits.
     */
    private function nexusSeatRefusal(bool $take, string $email): mixed
    {
        $status = $this->nexusSeatStatus();
        if (! in_array($status['state'], ['NOT_CONTROLLED', 'LIMITED', 'UNLIMITED'], true)) {
            $code = $status['blocked_reason'];
            $status503 = $code === 'SEAT_ENTITLEMENT_UNAVAILABLE';

            return $this->nexusFail($code, 'The seat limit could not be decided.', $status503 ? 503 : 409, ['application_code' => 'optiaccounting', 'recovery' => $status503 ? 'RETRY' : 'SET_ENTITLEMENT'], $status503 ? ['Retry-After' => '5'] : []);
        }
        if ($status['state'] !== 'LIMITED' || $this->nexusHoldsSeat($email)) {
            return null;
        }

        $reservedHere = in_array($email, $this->nexusReservedEmails(), true);
        if (! $take && $reservedHere) {
            return null;
        }
        $fits = $take && $reservedHere ? $status['limit'] >= $status['used'] + 1 : $status['limit'] >= $status['used'] + $status['reserved'] + 1;
        if ($fits) {
            return null;
        }

        $over = $status['limit'] < $status['used'] + $status['reserved'];

        return $this->nexusFail($over ? 'SEAT_OVER_LIMIT' : 'SEAT_LIMIT_REACHED', $over ? 'This application is over its seat limit.' : 'No seat is left for this application.', 409,
            ['application_code' => 'optiaccounting', 'limit' => $status['limit'], 'used' => $status['used'], 'reserved' => $status['reserved'], 'available' => $status['available'], 'over_limit' => $over,
                'recovery' => $over ? 'REDUCE_USAGE_OR_RAISE_LIMIT' : 'FREE_SEAT_OR_RAISE_LIMIT']);
    }

    // ---------------------------------------------------------------- roles

    private function nexusResolveDelegable(array $keys): mixed
    {
        $delegable = $this->nexusDelegableKeys();
        if ($refused = array_values(array_filter(array_unique($keys), fn ($k) => ! in_array($k, $delegable, true)))) {
            return $this->nexusFail('PERMISSION_NOT_DELEGABLE', 'A permission is unknown, outside this application, not delegable or not covered by the tenant entitlements.', 422, ['permissions' => $refused]);
        }

        return null;
    }

    private function nexusCreateRole(array $input): mixed
    {
        $errors = [];
        if (! is_string($input['name'] ?? null) || mb_strlen($input['name']) < 1) {
            $errors['name'] = ['The name field is required.'];
        }
        if (! is_array($input['permissions'] ?? null) || $input['permissions'] === []) {
            $errors['permissions'] = ['The permissions field is required.'];
        }
        if ($errors) {
            return $this->nexusFail('VALIDATION_ERROR', 'The request contains invalid data.', 422, $errors);
        }
        if ($refusal = $this->nexusResolveDelegable($input['permissions'])) {
            return $refusal;
        }

        $id = (string) Str::uuid();
        $this->nexus['delegation']['roles'][$id] = ['id' => $id, 'code' => strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $input['name'])).'_'.strtoupper(Str::random(4)), 'name' => $input['name'],
            'description' => $input['description'] ?? null, 'tenant' => true, 'origin' => 'DELEGATION', 'permissions' => array_values(array_unique($input['permissions']))];

        return $this->nexusOk($this->nexusRoleView($this->nexus['delegation']['roles'][$id]), [], 201);
    }

    private function nexusEditable(string $id): mixed
    {
        $role = $this->nexus['delegation']['roles'][$id] ?? null;
        if (! $role || $role['origin'] !== 'DELEGATION' || ! $role['tenant']) {
            return $this->nexusFail('ROLE_NOT_EDITABLE', 'This role cannot be changed from a product.', 404);
        }

        return null;
    }

    private function nexusUpdateRole(string $id, array $input): mixed
    {
        if ($refusal = $this->nexusEditable($id)) {
            return $refusal;
        }
        if (array_key_exists('permissions', $input) && ($refusal = $this->nexusResolveDelegable((array) $input['permissions']))) {
            return $refusal;
        }
        $role = &$this->nexus['delegation']['roles'][$id];
        foreach (['name', 'description'] as $field) {
            if (isset($input[$field])) {
                $role[$field] = $input[$field];
            }
        }
        if (isset($input['permissions'])) {
            $role['permissions'] = array_values(array_unique($input['permissions']));
        }

        return $this->nexusOk($this->nexusRoleView($role));
    }

    private function nexusDeleteRole(string $id): mixed
    {
        if ($refusal = $this->nexusEditable($id)) {
            return $refusal;
        }
        foreach ($this->nexus['delegation']['user_roles'] as $roleIds) {
            if (in_array($id, $roleIds, true)) {
                return $this->nexusFail('ROLE_IN_USE', 'The role is still assigned to users.', 409);
            }
        }
        unset($this->nexus['delegation']['roles'][$id]);

        return $this->nexusOk(null);
    }

    // ---------------------------------------------------------------- envelope

    /** @return array<string,mixed> */
    private function nexusQuery(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    private function nexusOk(mixed $data, array $meta = [], int $status = 200): mixed
    {
        return Http::response(['success' => true, 'data' => $data] + ($meta ? ['meta' => $meta] : []), $status);
    }

    private function nexusFail(string $code, string $message, int $status, mixed $details = null, array $headers = []): mixed
    {
        return Http::response(['success' => false, 'error' => ['code' => $code, 'message' => $message, 'details' => $details]], $status, $headers);
    }
}
