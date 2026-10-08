<?php

namespace Tests\Feature\Optinexus;

use App\Domain\AccessControl\Models\Role;
use Illuminate\Support\Facades\DB;

/** OA0-N: what OptiNexus owns is read-only here; what OptiAccounting owns stays editable. */
class ManagedByOptinexusTest extends OptinexusTestCase
{
    public function test_members_and_roles_are_managed_in_optinexus_for_everyone_even_a_full_administrator(): void
    {
        $session = $this->signedIn();
        [, $other] = $this->member($session['tenant']);
        $role = Role::query()->forceCreate(['tenant_id' => $session['tenant']->id, 'scope' => 'tenant', 'name' => 'Local', 'is_system' => false]);
        $token = $session['token'];

        $calls = [
            ['postJson', '/api/v1/app/users', ['name' => 'X', 'email' => 'x@example.test', 'password' => self::PASSWORD, 'role_ids' => [], 'data_scopes' => []]],
            ['postJson', "/api/v1/app/users/{$other->id}/status", ['status' => 'SUSPENDED']],
            ['putJson', "/api/v1/app/users/{$other->id}/roles", ['role_ids' => []]],
            ['postJson', '/api/v1/app/roles', ['name' => 'New', 'permissions' => []]],
            ['patchJson', "/api/v1/app/roles/{$role->id}", ['name' => 'Renamed']],
            ['deleteJson', "/api/v1/app/roles/{$role->id}", []],
        ];

        foreach ($calls as [$method, $url, $body]) {
            $this->as($token)->{$method}($url, $body)->assertStatus(409)->assertJsonPath('code', 'MANAGED_BY_OPTINEXUS');
        }
        $this->assertSame('Local', $role->fresh()->name);
    }

    public function test_subscriptions_entitlements_and_local_tenant_creation_are_managed_in_optinexus(): void
    {
        $tenant = $this->linkedTenant();
        $platform = $this->asPlatform();
        $someId = '01a11ae8-43cf-7125-a892-1623b11cf6a8';

        $calls = [
            ['postJson', '/api/v1/platform/tenants'],
            ['postJson', "/api/v1/platform/tenants/{$tenant->id}/subscriptions"],
            ['postJson', "/api/v1/platform/tenants/{$tenant->id}/subscriptions/{$someId}/status"],
            ['patchJson', "/api/v1/platform/tenants/{$tenant->id}/subscriptions/{$someId}"],
            ['postJson', "/api/v1/platform/tenants/{$tenant->id}/entitlements/modules"],
            ['patchJson', "/api/v1/platform/tenants/{$tenant->id}/entitlements/modules/{$someId}"],
            ['postJson', "/api/v1/platform/tenants/{$tenant->id}/entitlements/features"],
            ['patchJson', "/api/v1/platform/tenants/{$tenant->id}/entitlements/features/{$someId}"],
            ['putJson', "/api/v1/platform/tenants/{$tenant->id}/capacity/USER_LIMIT"],
        ];

        foreach ($calls as [$method, $url]) {
            $platform->{$method}($url, [])->assertStatus(409)->assertJsonPath('code', 'MANAGED_BY_OPTINEXUS');
        }
        $this->assertSame(0, DB::table('subscriptions')->count());
    }

    public function test_what_optiaccounting_owns_stays_editable(): void
    {
        $session = $this->signedIn();
        [, $other] = $this->member($session['tenant']);

        // Data scope and organization are accounting-specific and local.
        $this->as($session['token'])->putJson("/api/v1/app/users/{$other->id}/data-scopes", ['data_scopes' => [['scope_type' => 'OWN']]])->assertOk();
        $this->as($session['token'])->postJson('/api/v1/app/branches', ['code' => 'JKT', 'name' => 'Jakarta'])->assertCreated();
        $this->as($session['token'])->getJson('/api/v1/app/users')->assertOk();
        $this->as($session['token'])->getJson('/api/v1/app/roles')->assertOk();

        // Platform: tenant details, module catalog and bundles are local; reads are open.
        $platform = $this->asPlatform();
        $platform->patchJson("/api/v1/platform/tenants/{$session['tenant']->id}", ['timezone' => 'Asia/Makassar'])->assertOk();
        $platform->getJson("/api/v1/platform/tenants/{$session['tenant']->id}/subscriptions")->assertOk();
        $platform->getJson("/api/v1/platform/tenants/{$session['tenant']->id}/entitlements")->assertOk();
    }

    public function test_standalone_installations_are_unaffected(): void
    {
        config(['optiaccounting.identity_mode' => 'standalone']);
        $tenant = $this->tenant('plain-co');
        $platform = $this->asPlatform();

        $platform->postJson("/api/v1/platform/tenants/{$tenant->id}/entitlements/modules", [])->assertStatus(422); // validation, not the guard
        $this->asMember($tenant)->postJson('/api/v1/app/roles', [])->assertStatus(422);
    }
}
