<?php

namespace Tests\Feature\Optinexus;

use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Domain\AccessControl\Services\LocalPermissionSource;
use App\Domain\AccessControl\Services\PermissionSource;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\NexusPermissionSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/** OA0-N: in optinexus mode OptiNexus decides what a member may do; the answer is cached briefly and fails closed. */
class NexusPermissionTest extends OptinexusTestCase
{
    public function test_the_mode_only_picks_the_source_of_permissions(): void
    {
        $this->assertInstanceOf(NexusPermissionSource::class, app(PermissionSource::class));

        config(['optientry.identity_mode' => 'standalone']);
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

    public function test_a_whole_catalog_is_one_request_with_the_registered_application(): void
    {
        $this->nexusGrant(); // every tenant permission of the catalog (more than the old 120 requests a minute)
        $this->nexusSignIn()->assertOk();

        $asked = $this->nexusRequests('/authorization/effective-permissions');
        $catalog = DB::table('permissions')->where('scope', 'tenant')->count();

        $this->assertGreaterThan(120, $catalog);
        $this->assertCount(1, $asked, 'one request for the whole catalog');
        $this->assertSame([], $this->nexusRequests('/authorization/check'), 'no request per permission');
        $this->assertSame(['user_id' => self::NEXUS_USER, 'tenant_id' => self::NEXUS_TENANT, 'application_code' => 'optiaccounting'], $asked[0]['body']);
        $this->assertSame('Bearer service-token-1', $asked[0]['auth']);
        $this->assertCount($catalog, app(NexusPermissionSource::class)->pull(self::NEXUS_TENANT, self::NEXUS_USER)['permissions']);
    }

    public function test_only_exact_catalog_permissions_are_accepted_from_the_answer(): void
    {
        $this->nexusGrant(permissions: ['organization.view']);
        $this->nexus['extra_rows'] = [
            ['permission' => 'optiaccounting.*', 'scope' => 'GLOBAL'],
            ['permission' => '*', 'scope' => 'GLOBAL'],
            ['permission' => 'optiaccounting.journal.*', 'scope' => 'TENANT'],
            ['permission' => 'optiaccounting.not.in.catalog', 'scope' => 'TENANT'],
            ['permission' => 'optifleet.vehicle.view', 'scope' => 'TENANT'],
            ['permission' => 'optiaccounting.audit.view', 'scope' => 'ROOT'],
            ['permission' => 'optiaccounting.audit.view'],
            'garbage',
        ];

        $pulled = app(NexusPermissionSource::class)->pull(self::NEXUS_TENANT, self::NEXUS_USER);

        $this->assertSame(['organization.view'], $pulled['permissions']);
    }

    public function test_the_scope_belongs_to_each_permission_and_the_local_scope_starts_from_the_narrowest(): void
    {
        $this->nexusGrant(permissions: ['organization.view'], scope: 'TENANT');
        $this->nexus['extra_rows'] = [['permission' => 'optiaccounting.audit.view', 'scope' => 'OWN']];

        $session = $this->signedIn(['organization.view']);

        $grants = app(NexusPermissionSource::class)->tenantGrants($session['tenant']->id, $session['membership']->id);
        $this->assertSame(['audit.view' => 'OWN', 'organization.view' => 'TENANT'], $grants);
        $this->assertSame('OWN', DB::table('data_scopes')->where('tenant_user_id', $session['membership']->id)->value('scope_type'));
    }

    public function test_a_permission_granted_at_own_scope_covers_only_own_records_even_with_a_tenant_wide_local_scope(): void
    {
        $this->nexusGrant(permissions: ['organization.view'], scope: 'TENANT');
        $this->nexus['extra_rows'] = [['permission' => 'optiaccounting.audit.view', 'scope' => 'OWN']];
        $session = $this->signedIn(['organization.view']);
        DB::table('data_scopes')->where('tenant_user_id', $session['membership']->id)->update(['scope_type' => 'TENANT']);
        app(AccessCache::class)->touchTenant($session['tenant']->id);
        $access = app(EffectiveAccess::class);

        $request = fn (string $permission, string $owner) => new AccessRequest($session['user'], $session['tenant']->id, permission: $permission, resource: ['tenant_id' => $session['tenant']->id, 'owner_id' => $owner]);

        $this->assertTrue($access->evaluate($request('organization.view', 'someone-else'))->allowed, 'a TENANT grant covers every record');
        $this->assertFalse($access->evaluate($request('audit.view', 'someone-else'))->allowed);
        $this->assertSame('DATA_SCOPE_DENIED', $access->evaluate($request('audit.view', 'someone-else'))->code);
        $this->assertTrue($access->evaluate($request('audit.view', $session['user']->id))->allowed);
    }

    public function test_an_answer_about_another_user_tenant_application_or_schema_is_refused(): void
    {
        $this->nexusGrant();
        $source = app(NexusPermissionSource::class);

        foreach ([
            'another user' => ['user_id' => '11111111-1111-4111-8111-111111111111'],
            'another tenant' => ['tenant_id' => '22222222-2222-4222-8222-222222222222'],
            'another application' => ['application_code' => 'optifleet'],
            'unknown schema' => ['schema_version' => '2.0'],
            'no list' => ['permissions' => 'all'],
        ] as $label => $answer) {
            $this->nexus['bulk_answer'] = $answer;
            try {
                $source->pull(self::NEXUS_TENANT, self::NEXUS_USER);
                $this->fail("{$label}: the answer was accepted");
            } catch (IdentityProviderUnavailable) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_throttled_request_waits_for_retry_after_and_is_repeated_a_bounded_number_of_times(): void
    {
        Sleep::fake();
        $this->nexusGrant(permissions: ['organization.view']);

        $this->nexus['throttle'] = 2;
        $this->nexus['retry_after'] = 3;
        $this->assertSame(['organization.view'], app(NexusPermissionSource::class)->pull(self::NEXUS_TENANT, self::NEXUS_USER)['permissions']);
        $this->assertCount(3, $this->nexusRequests('/authorization/effective-permissions'));
        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(3)->seconds(), Sleep::for(3)->seconds()]);

        // Still throttled after the last repeat: fail closed and say when to come back.
        $this->nexus['requests'] = [];
        $this->nexus['throttle'] = 99;
        try {
            app(NexusPermissionSource::class)->pull(self::NEXUS_TENANT, self::NEXUS_USER);
            $this->fail('a throttled answer must not grant anything');
        } catch (IdentityProviderUnavailable $e) {
            $this->assertSame(3, $e->retryAfter);
        }
        $this->assertCount(3, $this->nexusRequests('/authorization/effective-permissions'), '1 call + max_retries (2) repeats');
    }

    public function test_a_retry_after_longer_than_the_cap_is_not_slept_through(): void
    {
        Sleep::fake();
        $this->nexusGrant(permissions: ['organization.view']);
        $this->nexus['throttle'] = 1;
        $this->nexus['retry_after'] = 120;

        try {
            app(NexusPermissionSource::class)->pull(self::NEXUS_TENANT, self::NEXUS_USER);
            $this->fail('should fail closed');
        } catch (IdentityProviderUnavailable $e) {
            $this->assertSame(120, $e->retryAfter);
        }

        Sleep::assertNeverSlept();
        $this->assertCount(1, $this->nexusRequests('/authorization/effective-permissions'));
    }

    public function test_the_user_sees_when_to_come_back_while_optinexus_throttles(): void
    {
        Sleep::fake();
        $session = $this->signedIn(['organization.view']);
        $this->travel(301)->seconds(); // the cached answer has expired
        $this->nexus['throttle'] = 99;
        $this->nexus['retry_after'] = 40;

        $this->as($session['token'])->getJson('/api/v1/app/branches')
            ->assertStatus(503)->assertHeader('Retry-After', '40')->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE');
    }

    public function test_a_gateway_error_is_repeated_once_and_a_second_one_fails_closed(): void
    {
        Sleep::fake();
        $this->nexusGrant(permissions: ['organization.view']);
        $source = app(NexusPermissionSource::class);

        $this->nexus['failures'] = 1;
        $this->assertSame(['organization.view'], $source->pull(self::NEXUS_TENANT, self::NEXUS_USER)['permissions']);

        $this->nexus['failures'] = 2;
        $this->expectException(IdentityProviderUnavailable::class);
        $source->pull(self::NEXUS_TENANT, self::NEXUS_USER);
    }

    public function test_a_revoked_service_token_is_renewed_once_and_a_rotated_secret_fails_closed_until_configured(): void
    {
        $this->nexusGrant(permissions: ['organization.view']);
        $source = app(NexusPermissionSource::class);
        $source->pull(self::NEXUS_TENANT, self::NEXUS_USER); // token 1 is cached

        $this->nexus['revoked_below'] = 2; // OptiNexus revoked token 1
        $this->assertSame(['organization.view'], $source->pull(self::NEXUS_TENANT, self::NEXUS_USER)['permissions']);
        $this->assertSame(2, $this->nexus['service_tokens']);

        // The secret is rotated in OptiNexus while we still hold the old one: no token, nothing granted, nothing cached.
        config(['optientry.optinexus.service.client_secret' => 'old-secret']);
        Cache::forget('optinexus.service.token');
        try {
            $source->pull(self::NEXUS_TENANT, self::NEXUS_USER);
            $this->fail('the old secret must not grant anything');
        } catch (IdentityProviderUnavailable) {
            $this->assertSame(2, $this->nexus['service_tokens'], 'no token was issued for the old secret');
        }

        // The operator installs the new secret: the very next call recovers.
        config(['optientry.optinexus.service.client_secret' => 'svc-secret']);
        $this->assertSame(['organization.view'], $source->pull(self::NEXUS_TENANT, self::NEXUS_USER)['permissions']);
    }

    public function test_many_members_of_many_tenants_each_cost_one_request_and_never_see_each_others_grants(): void
    {
        $other = '8c2d6e1f-3a66-4f4f-8b69-1e4e3b7d3333';
        $second = '6e3c9a21-9d44-4c0a-a7a1-5b2f8d4e4444';
        $this->nexusGrant(permissions: ['organization.view']);
        $this->nexusGrant($other, self::NEXUS_TENANT, ['audit.view']);
        $this->nexusGrant(self::NEXUS_USER, $second, ['accounting.journal.post'], 'APPLICATION');
        $source = app(NexusPermissionSource::class);

        $this->assertSame(['organization.view'], $source->pull(self::NEXUS_TENANT, self::NEXUS_USER)['permissions']);
        $this->assertSame(['audit.view'], $source->pull(self::NEXUS_TENANT, $other)['permissions']);
        $this->assertSame(['accounting.journal.post'], $source->pull($second, self::NEXUS_USER)['permissions']);
        $this->assertSame([], $source->pull($second, $other)['permissions']);
        $this->assertCount(4, $this->nexusRequests('/authorization/effective-permissions'));
    }

    public function test_an_unbound_service_account_or_an_older_optinexus_fails_closed_without_falling_back_to_per_permission_checks(): void
    {
        $this->nexusGrant();
        $source = app(NexusPermissionSource::class);

        foreach (['service_unbound', 'bulk_missing'] as $problem) {
            $this->nexus[$problem] = true;
            try {
                $source->pull(self::NEXUS_TENANT, self::NEXUS_USER);
                $this->fail("{$problem}: nothing may be granted");
            } catch (IdentityProviderUnavailable) {
                $this->assertTrue(true);
            }
            $this->nexus[$problem] = false;
        }

        $this->assertSame([], $this->nexusRequests('/authorization/check'));
    }

    public function test_the_answer_is_reused_until_the_ttl_then_asked_again(): void
    {
        $session = $this->signedIn(['organization.view']); // sign-in primed the cache
        $asked = fn () => count($this->nexusRequests('/authorization/effective-permissions'));
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
        $this->assertSame([], $this->nexusRequests('/authorization/effective-permissions'), 'an unlinked member is not worth a question');
    }

    public function test_platform_permissions_stay_local(): void
    {
        $this->asPlatform()->getJson('/api/v1/platform/tenants')->assertOk();
        $this->assertSame([], $this->nexusRequests('/authorization/effective-permissions'));
    }
}
