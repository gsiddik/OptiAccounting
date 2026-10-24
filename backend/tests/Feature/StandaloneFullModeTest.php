<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Owner decision K1 (2026-10-24): `standalone` is a full, supported identity mode with a local Superadmin (platform operator) who
 * owns tenants, subscriptions and users; it is not read-only and not a legacy path.
 */
class StandaloneFullModeTest extends TestCase
{
    use Fixtures;

    public function test_the_local_superadmin_runs_a_tenant_from_provisioning_to_a_working_administrator(): void
    {
        $this->assertSame('standalone', config('optientry.identity_mode'));
        $this->bundleAll();

        $platform = $this->asPlatform();
        $tenant = $platform->postJson('/api/v1/platform/tenants', [
            'code' => 'solo', 'name' => 'Solo Corp', 'admin' => ['name' => 'Owner', 'email' => 'owner@solo.test', 'password' => self::PASSWORD],
        ])->assertCreated()->json();
        $platform->postJson("/api/v1/platform/tenants/{$tenant['id']}/status", ['status' => 'ACTIVE', 'reason' => 'go live'])->assertOk();
        $platform->postJson("/api/v1/platform/tenants/{$tenant['id']}/subscriptions", [
            'bundle_code' => 'ALL', 'starts_on' => now()->toDateString(), 'status' => 'ACTIVE',
        ])->assertCreated();

        $login = $this->postJson('/api/v1/auth/login', ['tenant' => 'solo', 'email' => 'owner@solo.test', 'password' => self::PASSWORD])->assertOk();
        $admin = $this->as($login->json('token'));

        $admin->getJson('/api/v1/app/capabilities')->assertOk()->assertJsonPath('identity.managed_externally', false);

        $roleId = $admin->postJson('/api/v1/app/roles', ['name' => 'Kasir', 'permissions' => ['access.user.view']])->assertCreated()->json('id');
        $admin->postJson('/api/v1/app/users', [
            'name' => 'Kasir Satu', 'email' => 'kasir@solo.test', 'password' => self::PASSWORD, 'role_ids' => [$roleId], 'data_scopes' => [],
        ])->assertCreated();

        $this->assertSame(1, DB::table('users')->where('email', 'kasir@solo.test')->count());
        $this->assertSame(0, DB::table('users')->whereNotNull('optinexus_subject')->count()); // no OptiNexus anywhere
    }
}
