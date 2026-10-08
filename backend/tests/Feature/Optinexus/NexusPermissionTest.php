<?php

namespace Tests\Feature\Optinexus;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\LocalPermissionSource;
use App\Domain\AccessControl\Services\PermissionSource;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Optinexus\NexusPermissionSource;
use Illuminate\Support\Facades\DB;

/** OA0-N: in optinexus mode OptiNexus decides what a member may do; the answer is cached briefly and fails closed. */
class NexusPermissionTest extends OptinexusTestCase
{
    public function test_the_mode_only_picks_the_source_of_permissions(): void
    {
        $this->assertInstanceOf(NexusPermissionSource::class, app(PermissionSource::class));

        config(['optiaccounting.identity_mode' => 'standalone']);
        $this->assertInstanceOf(LocalPermissionSource::class, app(PermissionSource::class));
    }

    public function test_only_what_optinexus_grants_is_effective_and_local_roles_are_ignored(): void
    {
        $session = $this->signedIn(['organization.view', 'audit.view']);

        // A local role that grants everything must not matter.
        $role = Role::query()->forceCreate(['tenant_id' => $session['tenant']->id, 'scope' => 'tenant', 'name' => 'Local all', 'is_system' => false]);
        $role->permissions()->sync(Permission::query()->where('scope', 'tenant')->pluck('id')->mapWithKeys(fn ($id) => [$id => ['scope' => 'tenant']])->all());
        $session['membership']->roles()->attach($role->id, ['tenant_id' => $session['tenant']->id]);
        app(AccessCache::class)->touchTenant($session['tenant']->id);

        $this->as($session['token'])->getJson('/api/v1/app/branches')->assertOk();
        $this->as($session['token'])->postJson('/api/v1/app/branches', ['code' => 'JKT', 'name' => 'Jakarta'])->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->as($session['token'])->getJson('/api/v1/app/capabilities')->assertOk()->assertJsonPath('permissions', ['audit.view', 'organization.view']);
    }

    public function test_one_authorization_check_per_catalog_permission_with_the_registered_key(): void
    {
        $this->nexusGrant(permissions: ['organization.view']);
        $this->nexusSignIn()->assertOk();

        $checks = $this->nexusRequests('/authorization/check');
        $catalog = DB::table('permissions')->where('scope', 'tenant')->pluck('code')->all();

        $this->assertCount(count($catalog), $checks);
        $this->assertEqualsCanonicalizing(array_map(fn ($c) => "optiaccounting.{$c}", $catalog), array_column(array_column($checks, 'body'), 'permission'));
        $this->assertSame(
            ['user_id' => self::NEXUS_USER, 'tenant_id' => self::NEXUS_TENANT, 'application_code' => 'optiaccounting'],
            array_intersect_key($checks[0]['body'], array_flip(['user_id', 'tenant_id', 'application_code'])),
        );
        $this->assertSame('Bearer service-token-1', $checks[0]['auth'], 'one service token serves the whole batch');
    }

    public function test_the_answer_is_reused_until_the_ttl_then_asked_again(): void
    {
        $session = $this->signedIn(['organization.view']); // sign-in primed the cache
        $asked = fn () => count($this->nexusRequests('/authorization/check'));
        $before = $asked();

        $this->as($session['token'])->getJson('/api/v1/app/branches')->assertOk();
        $this->as($session['token'])->getJson('/api/v1/app/branches')->assertOk();
        $this->assertSame($before, $asked(), 'within the TTL nothing is asked again');

        // OptiNexus revokes the permission; the cached answer lives at most 5 minutes.
        $this->nexusGrant(permissions: []);
        $this->as($session['token'])->getJson('/api/v1/app/branches')->assertOk();

        $this->travel(301)->seconds();
        $this->as($session['token'])->getJson('/api/v1/app/branches')->assertForbidden();
        $this->assertGreaterThan($before, $asked());
    }

    public function test_a_tenant_version_bump_drops_the_cached_answer_at_once(): void
    {
        $session = $this->signedIn(['organization.view']);
        $this->nexusGrant(permissions: []);
        app(AccessCache::class)->touchTenant($session['tenant']->id); // what a revocation event does

        $this->as($session['token'])->getJson('/api/v1/app/branches')->assertForbidden();
    }

    public function test_the_ttl_can_never_exceed_five_minutes(): void
    {
        $cache = app(AccessCache::class);
        $calls = 0;
        $compute = function () use (&$calls) {
            return ++$calls;
        };

        $cache->rememberForTenant('t', 'x', $compute, 99999);
        $this->travel(299)->seconds();
        $cache->rememberForTenant('t', 'x', $compute, 99999);
        $this->assertSame(1, $calls);

        $this->travel(2)->seconds();
        $cache->rememberForTenant('t', 'x', $compute, 99999);
        $this->assertSame(2, $calls);
    }

    public function test_when_optinexus_cannot_answer_access_fails_closed_and_nothing_is_cached(): void
    {
        $session = $this->signedIn(['organization.view']);
        $this->travel(301)->seconds(); // the cached answer has expired

        foreach (['unreachable' => fn () => $this->nexus['down'] = true, 'server error' => function () {
            $this->nexus['down'] = false;
            $this->nexus['service_scopes_ok'] = false; // 403 from the check endpoint
        }] as $label => $break) {
            $break();
            $this->as($session['token'])->getJson('/api/v1/app/branches')
                ->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE');
        }

        // Back again: the very next request is answered from OptiNexus, nothing stale was kept.
        $this->nexus['service_scopes_ok'] = true;
        $this->as($session['token'])->getJson('/api/v1/app/branches')->assertOk();
    }

    public function test_existing_sessions_keep_working_until_the_cache_expires_while_optinexus_is_down(): void
    {
        $session = $this->signedIn(['organization.view']);
        $this->nexus['down'] = true;

        $this->as($session['token'])->getJson('/api/v1/app/branches')->assertOk();
        $this->assertSame(0, User::query()->where('status', '<>', 'ACTIVE')->count());
    }

    public function test_a_member_that_is_not_linked_to_optinexus_holds_nothing(): void
    {
        $tenant = $this->linkedTenant('local-co');
        [$user] = $this->member($tenant, null, 'unlinked@example.test'); // local roles say "everything", but no OptiNexus subject

        $this->as($this->tenantToken($user, $tenant))->getJson('/api/v1/app/branches')->assertForbidden();
        $this->assertSame([], $this->nexusRequests('/authorization/check'), 'an unlinked member is not worth a question');
    }

    public function test_platform_permissions_stay_local(): void
    {
        $this->asPlatform()->getJson('/api/v1/platform/tenants')->assertOk();
        $this->assertSame([], $this->nexusRequests('/authorization/check'));
    }
}
