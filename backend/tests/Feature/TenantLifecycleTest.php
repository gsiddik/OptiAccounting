<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Tenant provisioning and lifecycle (OA0 §33): DRAFT -> ACTIVE <-> SUSPENDED -> INACTIVE -> TERMINATED, with revocation and audit. */
class TenantLifecycleTest extends TestCase
{
    use Fixtures;

    private function create(array $body = [])
    {
        return $this->asPlatform()->postJson('/api/v1/platform/tenants', [
            'code' => 'newco', 'name' => 'NewCo', 'admin' => ['name' => 'Owner', 'email' => 'owner@newco.test', 'password' => self::PASSWORD], ...$body,
        ]);
    }

    private function move(string $id, string $status, string $reason = 'ops request')
    {
        return $this->asPlatform()->postJson("/api/v1/platform/tenants/{$id}/status", ['status' => $status, 'reason' => $reason]);
    }

    public function test_a_new_tenant_starts_as_draft_with_system_roles_and_an_administrator(): void
    {
        $tenant = $this->create()->assertCreated()->assertJsonPath('status', 'DRAFT')->json();

        $this->assertSame(2, $this->rows('roles', ['tenant_id' => $tenant['id'], 'is_system' => true]));
        $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant['id'])->firstOrFail();
        $this->assertSame('ACTIVE', $membership->status);
        $this->assertSame(1, $this->rows('data_scopes', ['tenant_id' => $tenant['id'], 'scope_type' => 'TENANT']));
        $this->assertSame(0, $this->rows('subscriptions', ['tenant_id' => $tenant['id']])); // commercial setup is a separate step
    }

    public function test_the_initial_password_is_hashed_and_a_weak_one_is_refused(): void
    {
        $this->create()->assertCreated();
        $hash = DB::table('users')->where('email', 'owner@newco.test')->value('password');
        $this->assertNotSame(self::PASSWORD, $hash);
        $this->assertTrue(password_verify(self::PASSWORD, $hash));

        $this->create(['code' => 'weak', 'admin' => ['name' => 'W', 'email' => 'w@weak.test', 'password' => 'short']])->assertStatus(422);
        $this->create(['code' => 'nopw', 'admin' => ['name' => 'W', 'email' => 'w2@weak.test']])->assertStatus(422)->assertJsonPath('code', 'PASSWORD_REQUIRED');
        $this->assertSame(0, $this->rows('tenants', ['code' => 'nopw'])); // the whole provisioning rolled back
    }

    public function test_code_is_unique_and_well_formed_and_status_cannot_be_chosen_on_creation(): void
    {
        $this->create()->assertCreated();

        $this->create(['admin' => null])->assertStatus(422);                  // duplicate code
        $this->create(['code' => 'Bad Code!', 'admin' => null])->assertStatus(422);
        $this->create(['code' => 'chosen', 'status' => 'ACTIVE', 'id' => '01a11ae8-43cf-7125-a892-1623b11cf6a8', 'admin' => null])
            ->assertCreated()->assertJsonPath('status', 'DRAFT');
        $this->assertNotSame('01a11ae8-43cf-7125-a892-1623b11cf6a8', Tenant::query()->where('code', 'chosen')->value('id'));
    }

    public function test_a_draft_tenant_is_unusable_until_it_is_activated(): void
    {
        $id = $this->create()->assertCreated()->json('id');
        $owner = User::query()->where('email', 'owner@newco.test')->firstOrFail();
        $token = $owner->createToken('t', ['tenant:'.$id])->plainTextToken;

        $this->as($token)->getJson('/api/v1/app/branches')->assertForbidden()->assertJsonPath('code', 'TENANT_INACTIVE');

        $this->move($id, 'ACTIVE')->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->as($token)->getJson('/api/v1/app/branches')->assertOk();
    }

    public function test_suspending_ends_open_sessions_and_reactivating_restores_sign_in(): void
    {
        $id = $this->create()->assertCreated()->json('id');
        $this->move($id, 'ACTIVE')->assertOk();

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'owner@newco.test', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('scope', 'tenant');
        $token = $login->json('token');
        $this->as($token)->getJson('/api/v1/app/branches')->assertOk();

        $this->move($id, 'SUSPENDED')->assertOk();
        $this->as($token)->getJson('/api/v1/app/branches')->assertUnauthorized(); // token deleted
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@newco.test', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('scope', 'identity');

        $this->move($id, 'ACTIVE')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@newco.test', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('scope', 'tenant');
    }

    public function test_only_documented_transitions_are_allowed_and_termination_is_final(): void
    {
        $id = $this->create()->assertCreated()->json('id');

        $this->move($id, 'SUSPENDED')->assertStatus(422)->assertJsonPath('code', 'INVALID_TRANSITION');   // DRAFT -> SUSPENDED
        $this->move($id, 'TERMINATED')->assertStatus(422);
        $this->move($id, 'ACTIVE')->assertOk();
        $this->move($id, 'TERMINATED')->assertStatus(422);                                              // must be INACTIVE first
        $this->move($id, 'INACTIVE')->assertOk();
        $this->move($id, 'TERMINATED')->assertOk();

        foreach (['ACTIVE', 'SUSPENDED', 'INACTIVE', 'DRAFT'] as $to) {
            $this->move($id, $to)->assertStatus(422);
        }
    }

    public function test_every_transition_needs_a_reason_and_is_audited_with_it(): void
    {
        $id = $this->create()->assertCreated()->json('id');

        $this->asPlatform()->postJson("/api/v1/platform/tenants/{$id}/status", ['status' => 'ACTIVE'])->assertStatus(422);
        $this->asPlatform()->postJson("/api/v1/platform/tenants/{$id}/status", ['status' => 'ACTIVE', 'reason' => 'x'])->assertStatus(422);
        $this->assertSame('DRAFT', Tenant::query()->whereKey($id)->value('status'));

        $this->move($id, 'ACTIVE', 'contract signed')->assertOk();
        $log = DB::table('audit_logs')->where('action', 'tenant.status_changed')->where('tenant_id', $id)->first();
        $this->assertStringContainsString('contract signed', $log->changes);
    }

    public function test_status_cannot_be_changed_through_the_plain_update(): void
    {
        $id = $this->create()->assertCreated()->json('id');

        $this->asPlatform()->patchJson("/api/v1/platform/tenants/{$id}", ['name' => 'Renamed', 'status' => 'ACTIVE', 'code' => 'hijack'])
            ->assertOk()->assertJsonPath('name', 'Renamed')->assertJsonPath('status', 'DRAFT')->assertJsonPath('code', 'newco');
    }

    public function test_deactivated_tenant_keeps_its_data(): void
    {
        $id = $this->create()->assertCreated()->json('id');
        $this->move($id, 'ACTIVE')->assertOk();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'owner@newco.test', 'password' => self::PASSWORD])->json('token');
        $this->as($token)->postJson('/api/v1/app/branches', ['code' => 'HQ', 'name' => 'HQ'])->assertCreated();

        $this->move($id, 'INACTIVE')->assertOk();
        $this->move($id, 'TERMINATED')->assertOk();

        $this->assertSame(1, $this->rows('branches', ['tenant_id' => $id]));
        $this->assertSame(1, $this->rows('tenant_users', ['tenant_id' => $id]));
        $this->asPlatform()->getJson("/api/v1/platform/tenants/{$id}")->assertOk()->assertJsonPath('status', 'TERMINATED');
    }

    public function test_the_platform_can_suspend_a_membership_which_ends_that_users_sessions_in_the_tenant(): void
    {
        $tenant = $this->tenant('alpha');
        [$user, $membership] = $this->member($tenant, ['organization.view']);
        $token = $this->tenantToken($user, $tenant);
        $this->as($token)->getJson('/api/v1/app/branches')->assertOk();

        $this->asPlatform()->postJson("/api/v1/platform/tenants/{$tenant->id}/members/{$membership->id}/status", ['status' => 'SUSPENDED'])->assertOk();

        $this->as($token)->getJson('/api/v1/app/branches')->assertUnauthorized();
        $members = $this->asPlatform()->getJson("/api/v1/platform/tenants/{$tenant->id}/members")->assertOk()->json('data');
        $this->assertSame('SUSPENDED', collect($members)->firstWhere('id', $membership->id)['status']);
    }

    public function test_the_platform_list_supports_status_and_search_filters(): void
    {
        $this->tenant('alpha');
        $draft = $this->create()->assertCreated()->json('id');

        $all = collect($this->asPlatform()->getJson('/api/v1/platform/tenants')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['alpha', 'newco'], $all->pluck('code')->all());

        $active = collect($this->asPlatform()->getJson('/api/v1/platform/tenants?status=ACTIVE')->json('data'));
        $this->assertSame(['alpha'], $active->pluck('code')->all());
        $this->assertSame([$draft], collect($this->asPlatform()->getJson('/api/v1/platform/tenants?search=newco')->json('data'))->pluck('id')->all());
    }
}
