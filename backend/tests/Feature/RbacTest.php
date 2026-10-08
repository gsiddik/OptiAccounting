<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Dynamic RBAC (OA0 §36): permissions decide, role names never do; no escalation; platform and tenant stay apart. */
class RbacTest extends TestCase
{
    use Fixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->tenant('alpha');
    }

    private function roleNamed(string $name, array $permissions): Role
    {
        $role = Role::query()->forceCreate(['tenant_id' => $this->tenant->id, 'scope' => 'tenant', 'name' => $name, 'is_system' => false]);
        $role->permissions()->sync(Permission::query()->where('scope', 'tenant')->whereIn('code', $permissions)->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['scope' => 'tenant']])->all());

        return $role;
    }

    public function test_a_role_called_administrator_without_permissions_gets_nothing(): void
    {
        [$user, $membership] = $this->member($this->tenant, ['organization.view']);
        $membership->roles()->detach();
        $membership->roles()->attach($this->roleNamed('Super Administrator', [])->id, ['tenant_id' => $this->tenant->id]);

        $this->as($this->tenantToken($user, $this->tenant))
            ->getJson('/api/v1/app/branches')->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_a_role_with_a_meaningless_name_works_when_it_holds_the_permission(): void
    {
        [$user, $membership] = $this->member($this->tenant, []);
        $membership->roles()->attach($this->roleNamed('zzz-1', ['organization.view'])->id, ['tenant_id' => $this->tenant->id]);

        $this->as($this->tenantToken($user, $this->tenant))->getJson('/api/v1/app/branches')->assertOk();
    }

    public function test_permissions_are_the_union_of_all_roles_of_the_member(): void
    {
        [$user, $membership] = $this->member($this->tenant, ['organization.view']);
        $token = $this->tenantToken($user, $this->tenant);

        $this->as($token)->postJson('/api/v1/app/branches', ['code' => 'A', 'name' => 'A'])->assertForbidden();

        $membership->roles()->attach($this->roleNamed('second', ['organization.manage'])->id, ['tenant_id' => $this->tenant->id]);
        app(AccessCache::class)->touchTenant($this->tenant->id);

        $this->as($token)->postJson('/api/v1/app/branches', ['code' => 'A', 'name' => 'A'])->assertCreated();
    }

    public function test_system_roles_cannot_be_changed_or_deleted_through_the_api(): void
    {
        $admin = $this->asMember($this->tenant);
        $system = collect($admin->getJson('/api/v1/app/roles')->assertOk()->json('data'))->firstWhere('is_system', true);
        $this->assertNotNull($system);

        $this->asMember($this->tenant)->patchJson("/api/v1/app/roles/{$system['id']}", ['permissions' => []])
            ->assertForbidden()->assertJsonPath('code', 'SYSTEM_ROLE_IMMUTABLE');
        $this->asMember($this->tenant)->patchJson("/api/v1/app/roles/{$system['id']}", ['name' => 'Renamed'])
            ->assertForbidden()->assertJsonPath('code', 'SYSTEM_ROLE_IMMUTABLE');
        $this->asMember($this->tenant)->deleteJson("/api/v1/app/roles/{$system['id']}")
            ->assertForbidden()->assertJsonPath('code', 'SYSTEM_ROLE_IMMUTABLE');

        $this->assertTrue(Role::query()->whereKey($system['id'])->exists());
    }

    public function test_custom_roles_can_be_created_changed_and_deleted_with_audit(): void
    {
        $token = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);

        $id = $this->as($token)->postJson('/api/v1/app/roles', ['name' => 'Auditor', 'permissions' => ['audit.view', 'organization.view']])
            ->assertCreated()->json('id');
        $this->as($token)->patchJson("/api/v1/app/roles/{$id}", ['permissions' => ['audit.view']])->assertOk();
        $this->assertSame(['audit.view'], Role::query()->findOrFail($id)->permissions()->pluck('code')->all());

        $this->as($token)->deleteJson("/api/v1/app/roles/{$id}")->assertOk();
        $this->assertFalse(Role::query()->whereKey($id)->exists());

        foreach (['role.created', 'role.updated', 'role.deleted'] as $action) {
            $this->assertSame(1, $this->rows('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => $action]), $action);
        }
    }

    public function test_role_names_are_unique_per_tenant_ignoring_case_but_reusable_in_another_tenant(): void
    {
        $token = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);
        $this->as($token)->postJson('/api/v1/app/roles', ['name' => 'Cashier', 'permissions' => ['organization.view']])->assertCreated();
        $this->as($token)->postJson('/api/v1/app/roles', ['name' => 'cashier', 'permissions' => ['organization.view']])
            ->assertStatus(422)->assertJsonPath('code', 'ROLE_NAME_TAKEN');

        $beta = $this->tenant('beta');
        $betaToken = $this->tenantToken($this->member($beta)[0], $beta);
        $this->as($betaToken)->postJson('/api/v1/app/roles', ['name' => 'Cashier', 'permissions' => ['organization.view']])->assertCreated();
    }

    public function test_unknown_and_platform_permission_codes_are_rejected_for_tenant_roles(): void
    {
        $token = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);

        foreach (['not.a.permission', 'platform.tenant.view'] as $code) {
            $this->as($token)->postJson('/api/v1/app/roles', ['name' => 'R '.$code, 'permissions' => [$code]])
                ->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_PERMISSION');
        }
    }

    public function test_a_user_cannot_put_a_permission_they_lack_into_a_role(): void
    {
        [$user] = $this->member($this->tenant, ['access.role.view', 'access.role.manage', 'organization.view']);
        $token = $this->tenantToken($user, $this->tenant);

        $this->as($token)->postJson('/api/v1/app/roles', ['name' => 'Sneaky', 'permissions' => ['organization.view', 'audit.view']])
            ->assertForbidden()->assertJsonPath('code', 'PRIVILEGE_ESCALATION')->assertJsonPath('details.permissions.0', 'audit.view');

        $ok = $this->as($token)->postJson('/api/v1/app/roles', ['name' => 'Fine', 'permissions' => ['organization.view']])->assertCreated()->json('id');
        $this->as($token)->patchJson("/api/v1/app/roles/{$ok}", ['permissions' => ['organization.view', 'access.user.manage']])
            ->assertForbidden()->assertJsonPath('code', 'PRIVILEGE_ESCALATION');
        $this->assertSame(['organization.view'], Role::query()->findOrFail($ok)->permissions()->pluck('code')->all());
    }

    public function test_a_user_cannot_assign_a_role_that_is_stronger_than_their_own(): void
    {
        [$manager] = $this->member($this->tenant, ['access.user.manage', 'access.user.view', 'organization.view']);
        [, $target] = $this->member($this->tenant, ['organization.view']);
        $strong = $this->roleNamed('Strong', ['organization.view', 'audit.view', 'access.role.manage']);

        $this->as($this->tenantToken($manager, $this->tenant))
            ->putJson("/api/v1/app/users/{$target->id}/roles", ['role_ids' => [$strong->id]])
            ->assertForbidden()->assertJsonPath('code', 'PRIVILEGE_ESCALATION');

        $this->assertFalse(DB::table('tenant_user_roles')->where('tenant_user_id', $target->id)->where('role_id', $strong->id)->exists());
    }

    public function test_a_new_member_cannot_be_given_a_system_admin_role_by_a_lesser_user(): void
    {
        [$manager] = $this->member($this->tenant, ['access.user.manage', 'access.user.view']);
        $adminRole = Role::query()->forTenant($this->tenant->id)->where('is_system', true)->get()->first(fn ($r) => $r->permissions()->count() > 5);

        $this->as($this->tenantToken($manager, $this->tenant))->postJson('/api/v1/app/users', [
            'name' => 'New', 'email' => 'new@alpha.test', 'password' => self::PASSWORD, 'role_ids' => [$adminRole->id],
        ])->assertForbidden()->assertJsonPath('code', 'PRIVILEGE_ESCALATION');

        $this->assertSame(0, $this->rows('users', ['email' => 'new@alpha.test']));
    }

    public function test_revoking_a_permission_takes_effect_on_the_next_request(): void
    {
        $admin = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);
        $roleId = $this->as($admin)->postJson('/api/v1/app/roles', ['name' => 'Reader', 'permissions' => ['organization.view']])->json('id');
        [$user, $membership] = $this->member($this->tenant, []);
        $this->as($admin)->putJson("/api/v1/app/users/{$membership->id}/roles", ['role_ids' => [$roleId]])->assertOk();

        $token = $this->tenantToken($user, $this->tenant);
        $this->as($token)->getJson('/api/v1/app/branches')->assertOk();

        $this->as($admin)->patchJson("/api/v1/app/roles/{$roleId}", ['permissions' => ['audit.view']])->assertOk();
        $this->as($token)->getJson('/api/v1/app/branches')->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');

        $this->as($admin)->putJson("/api/v1/app/users/{$membership->id}/roles", ['role_ids' => []])->assertOk();
        $this->as($token)->getJson('/api/v1/app/audit-logs')->assertForbidden();
    }

    public function test_a_role_that_is_still_assigned_cannot_be_deleted(): void
    {
        $admin = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);
        $roleId = $this->as($admin)->postJson('/api/v1/app/roles', ['name' => 'Used', 'permissions' => ['organization.view']])->json('id');
        [, $membership] = $this->member($this->tenant, []);
        $this->as($admin)->putJson("/api/v1/app/users/{$membership->id}/roles", ['role_ids' => [$roleId]])->assertOk();

        $this->as($admin)->deleteJson("/api/v1/app/roles/{$roleId}")->assertStatus(409)->assertJsonPath('code', 'ROLE_IN_USE');
    }

    public function test_suspending_a_member_revokes_their_tokens_at_once(): void
    {
        $admin = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);
        [$user, $membership] = $this->member($this->tenant, ['organization.view']);
        $token = $this->tenantToken($user, $this->tenant);
        $this->as($token)->getJson('/api/v1/app/branches')->assertOk();

        $this->as($admin)->postJson("/api/v1/app/users/{$membership->id}/status", ['status' => 'SUSPENDED'])->assertOk();

        $this->as($token)->getJson('/api/v1/app/branches')->assertUnauthorized();
        $this->assertSame(TenantUser::SUSPENDED, $membership->fresh()->status);
    }

    public function test_a_user_cannot_change_their_own_membership_status(): void
    {
        [$user, $membership] = $this->member($this->tenant);

        $this->as($this->tenantToken($user, $this->tenant))
            ->postJson("/api/v1/app/users/{$membership->id}/status", ['status' => 'INACTIVE'])
            ->assertForbidden()->assertJsonPath('code', 'SELF_CHANGE_REFUSED');
    }

    public function test_platform_and_tenant_tokens_do_not_cross_over(): void
    {
        $platform = $this->platformToken($this->platformUser());
        [$user] = $this->member($this->tenant);
        $tenantToken = $this->tenantToken($user, $this->tenant);

        // a platform administrator has no implicit tenant operational access
        $this->as($platform)->getJson('/api/v1/app/branches')->assertForbidden()->assertJsonPath('code', 'TOKEN_SCOPE');
        $this->as($platform)->getJson('/api/v1/app/users')->assertForbidden();
        // and a tenant administrator is not a platform user
        $this->as($tenantToken)->getJson('/api/v1/platform/tenants')->assertForbidden()->assertJsonPath('code', 'TOKEN_SCOPE');
        $this->as($tenantToken)->getJson('/api/v1/platform/audit-logs')->assertForbidden();
    }

    public function test_tenant_permissions_do_not_open_platform_routes_and_the_reverse(): void
    {
        $limited = $this->platformUser(['platform.tenant.view']);
        $token = $this->platformToken($limited);

        $this->as($token)->getJson('/api/v1/platform/tenants')->assertOk();
        $this->as($token)->postJson('/api/v1/platform/tenants', ['code' => 'x', 'name' => 'X'])->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->as($token)->getJson('/api/v1/platform/modules')->assertForbidden();
    }

    public function test_a_platform_user_cannot_grant_platform_permissions_they_lack(): void
    {
        $actor = $this->platformUser(['platform.role.view', 'platform.role.manage', 'platform.permission.view']);

        $this->as($this->platformToken($actor))->postJson('/api/v1/platform/roles', ['name' => 'Escalated', 'permissions' => ['platform.tenant.create']])
            ->assertForbidden()->assertJsonPath('code', 'PRIVILEGE_ESCALATION');
        $this->as($this->platformToken($actor))->postJson('/api/v1/platform/roles', ['name' => 'Mine', 'permissions' => ['platform.role.view']])
            ->assertCreated();
    }

    public function test_deactivated_and_unauthenticated_callers_are_refused(): void
    {
        $this->getJson('/api/v1/app/branches')->assertUnauthorized();
        $this->withToken('1|not-a-token')->getJson('/api/v1/app/branches')->assertUnauthorized();

        [$user] = $this->member($this->tenant);
        $token = $this->tenantToken($user, $this->tenant);
        $user->forceFill(['status' => 'INACTIVE'])->save();

        $this->as($token)->getJson('/api/v1/app/branches')->assertForbidden()->assertJsonPath('code', 'USER_INACTIVE');
    }
}
