<?php

namespace Tests\Feature\Optinexus;

use App\Domain\AccessControl\AccessDecision;
use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Domain\Entitlement\Services\EntitlementSnapshot;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Services\TenantService;
use App\Domain\Integration\Optinexus\EntitlementProjector;
use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** OA0-N: OptiNexus's commercial state is projected into the local entitlement rows (source OPTINEXUS). */
class EntitlementProjectionTest extends OptinexusTestCase
{
    private function capability(string $code, bool $value = true): array
    {
        return ['entitlement_type' => 'CAPABILITY', 'entitlement_key' => $code, 'value' => $value, 'source' => 'PLAN'];
    }

    private function limit(string $key, float|string $value): array
    {
        return ['entitlement_type' => 'LIMIT', 'entitlement_key' => $key, 'value' => $value, 'source' => 'PLAN'];
    }

    /** @param  array<string,mixed>  $over */
    private function nexusTenant(array $over): void
    {
        $this->nexus['tenants'][self::NEXUS_TENANT] = $this->nexusTenantState($over);
    }

    private function snapshot(Tenant $tenant): array
    {
        return app(EntitlementSnapshot::class)->forTenant($tenant->refresh());
    }

    private function decide(Tenant $tenant, string $permission, string $module, bool $mutating = false): AccessDecision
    {
        [$user] = $this->member($tenant);

        return app(EffectiveAccess::class)->evaluate(new AccessRequest($user, $tenant->id, null, module: $module, mutating: $mutating));
    }

    public function test_modules_features_capacity_and_subscription_are_projected_and_the_resolver_uses_them(): void
    {
        $tenant = $this->linkedTenant();
        $this->nexusTenant(['entitlements' => [
            $this->capability('ACCOUNTING_CORE'), $this->capability('ACCOUNTING_AP'),
            $this->limit('user_limit', 5.0), $this->limit('branch_limit', 'unlimited'),
        ]]);

        $result = app(EntitlementProjector::class)->sync($tenant);

        $this->assertTrue($result['changed']);
        $snapshot = $this->snapshot($tenant);
        $this->assertSame('FULL', $snapshot['subscription']['mode']);
        $this->assertSame(['ACCOUNTING_AP', 'ACCOUNTING_CORE'], collect($snapshot['modules'])->filter(fn ($m) => $m['mode'] === 'FULL')->keys()->sort()->values()->all());
        // A module carries its active features (same as a local bundle).
        $this->assertTrue($snapshot['features']['JOURNAL']['enabled']);
        $this->assertTrue($snapshot['features']['VENDOR_INVOICE']['enabled']);
        $this->assertArrayNotHasKey('BUDGET', $snapshot['features']);
        $this->assertSame(5, $snapshot['capacity']['USER_LIMIT']);
        $this->assertNull($snapshot['capacity']['BRANCH_LIMIT']);
        $this->assertNull($snapshot['capacity']['BUSINESS_UNIT_LIMIT'], 'a limit OptiNexus does not set is not a limit');

        $this->assertTrue($this->decide($tenant, 'x', 'ACCOUNTING_AP')->allowed);
        $this->assertSame(AccessDecision::MODULE_NOT_ENTITLED, $this->decide($tenant, 'x', 'ACCOUNTING_AR')->code);
        $this->assertNotNull($tenant->fresh()->optinexus_synced_at);
        $this->assertSame(['OPTINEXUS'], DB::table('tenant_module_entitlements')->distinct()->pluck('source')->all());
        $this->assertSame('sub-1', DB::table('subscriptions')->where('tenant_id', $tenant->id)->value('external_reference'));
    }

    public function test_a_second_run_with_nothing_new_writes_nothing(): void
    {
        $tenant = $this->linkedTenant();
        $projector = app(EntitlementProjector::class);
        $projector->sync($tenant);
        $audits = DB::table('audit_logs')->where('action', 'entitlement.projected')->count();
        $version = app(AccessCache::class)->tenantVersion($tenant->id);
        $rows = DB::table('tenant_module_entitlements')->get()->map(fn ($r) => (array) $r)->all();

        $this->assertFalse($projector->sync($tenant)['changed']);

        $this->assertSame($audits, DB::table('audit_logs')->where('action', 'entitlement.projected')->count());
        $this->assertSame($version, app(AccessCache::class)->tenantVersion($tenant->id), 'no change, no cache invalidation');
        $this->assertSame($rows, DB::table('tenant_module_entitlements')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_a_module_taken_away_is_disabled_not_deleted_and_access_stops(): void
    {
        $tenant = $this->linkedTenant();
        $projector = app(EntitlementProjector::class);
        $projector->sync($tenant);
        $this->assertTrue($this->decide($tenant, 'x', 'ACCOUNTING_AR')->allowed);

        $this->nexusTenant(['entitlements' => [$this->capability('ACCOUNTING_CORE')]]);
        $result = $projector->sync($tenant);

        $this->assertContains('module ACCOUNTING_AR: ACTIVE -> DISABLED', $result['changes']);
        $this->assertSame('DISABLED', DB::table('tenant_module_entitlements as e')->join('modules as m', 'm.id', '=', 'e.module_id')->where('m.code', 'ACCOUNTING_AR')->value('e.state'));
        $this->assertSame(AccessDecision::MODULE_NOT_ENTITLED, $this->decide($tenant, 'x', 'ACCOUNTING_AR')->code);
        $this->assertTrue($this->decide($tenant, 'x', 'ACCOUNTING_CORE')->allowed);

        // ... and back: the same row is switched on again (no overlapping windows).
        $this->nexusTenant([]);
        $projector->sync($tenant);
        $this->assertTrue($this->decide($tenant, 'x', 'ACCOUNTING_AR')->allowed);
        $this->assertSame(1, DB::table('tenant_module_entitlements as e')->join('modules as m', 'm.id', '=', 'e.module_id')->where('m.code', 'ACCOUNTING_AR')->count());
    }

    public function test_a_module_without_its_required_module_is_not_granted(): void
    {
        $tenant = $this->linkedTenant();
        $this->nexusTenant(['entitlements' => [$this->capability('ACCOUNTING_AP'), $this->capability('ACCOUNTING_ANALYTICS')]]);

        app(EntitlementProjector::class)->sync($tenant);

        $snapshot = $this->snapshot($tenant);
        $this->assertSame([], collect($snapshot['modules'])->filter(fn ($m) => $m['mode'] !== 'NONE')->keys()->all(), 'AP and ANALYTICS both need CORE');
        $this->assertSame([], collect($snapshot['features'])->filter(fn ($f) => $f['enabled'])->keys()->all());
    }

    public function test_a_feature_named_false_is_off_while_the_rest_of_its_module_stays_on(): void
    {
        $tenant = $this->linkedTenant();
        $this->nexusTenant(['entitlements' => [$this->capability('ACCOUNTING_CORE'), $this->capability('OPENING_BALANCE', false)]]);

        app(EntitlementProjector::class)->sync($tenant);

        $features = $this->snapshot($tenant)['features'];
        $this->assertTrue($features['JOURNAL']['enabled']);
        $this->assertArrayNotHasKey('OPENING_BALANCE', $features, 'a feature that was never on has no row');

        // Enabled first, then switched off by OptiNexus: the row is disabled in place.
        $this->nexusTenant(['entitlements' => [$this->capability('ACCOUNTING_CORE')]]);
        app(EntitlementProjector::class)->sync($tenant);
        $this->assertTrue($this->snapshot($tenant)['features']['OPENING_BALANCE']['enabled']);
        $this->nexusTenant(['entitlements' => [$this->capability('ACCOUNTING_CORE'), $this->capability('OPENING_BALANCE', false)]]);
        app(EntitlementProjector::class)->sync($tenant);
        $this->assertFalse($this->snapshot($tenant)['features']['OPENING_BALANCE']['enabled']);
    }

    public function test_subscription_states_map_to_full_read_only_or_none(): void
    {
        $tenant = $this->linkedTenant();
        $projector = app(EntitlementProjector::class);

        foreach (['ACTIVE' => 'FULL', 'TRIAL' => 'FULL', 'GRACE_PERIOD' => 'FULL', 'PAST_DUE' => 'READ_ONLY'] as $status => $mode) {
            $this->nexusTenant(['subscriptions' => [['id' => 's', 'status' => $status]]]);
            $projector->sync($tenant);
            $this->assertSame($mode, $this->snapshot($tenant)['subscription']['mode'], $status);
        }

        // The most restrictive live subscription wins.
        $this->nexusTenant(['subscriptions' => [['id' => 'a', 'status' => 'ACTIVE'], ['id' => 'b', 'status' => 'PAST_DUE']]]);
        $projector->sync($tenant);
        $this->assertSame('READ_ONLY', $this->snapshot($tenant)['subscription']['mode']);
        $this->assertSame(AccessDecision::SUBSCRIPTION_READ_ONLY, $this->decide($tenant, 'x', 'ACCOUNTING_CORE', mutating: true)->code);
        $this->assertTrue($this->decide($tenant, 'x', 'ACCOUNTING_CORE')->allowed, 'reads stay open');

        // Nothing live any more: the local subscription ends and access stops.
        $this->nexusTenant(['subscriptions' => [['id' => 'z', 'status' => 'CANCELLED']]]);
        $projector->sync($tenant);
        $this->assertSame('EXPIRED', DB::table('subscriptions')->where('tenant_id', $tenant->id)->value('status'));
        $this->assertSame(AccessDecision::SUBSCRIPTION_INACTIVE, $this->decide($tenant, 'x', 'ACCOUNTING_CORE')->code);
        $this->assertSame(1, DB::table('subscriptions')->where('tenant_id', $tenant->id)->count());
    }

    public function test_an_application_that_is_not_enabled_in_optinexus_grants_nothing(): void
    {
        $tenant = $this->linkedTenant();
        app(EntitlementProjector::class)->sync($tenant);
        $this->nexusTenant(['applications' => []]);

        app(EntitlementProjector::class)->sync($tenant);

        $this->assertSame(AccessDecision::SUBSCRIPTION_INACTIVE, $this->decide($tenant, 'x', 'ACCOUNTING_CORE')->code);
    }

    public function test_capacity_follows_optinexus_and_is_enforced_by_the_same_capacity_service(): void
    {
        $tenant = $this->linkedTenant();
        $projector = app(EntitlementProjector::class);
        $this->nexusTenant(['entitlements' => [$this->capability('ACCOUNTING_CORE'), $this->limit('user_limit', 3.0)]]);
        $projector->sync($tenant);

        $this->assertSame(3, DB::table('tenant_capacity_limits')->where('tenant_id', $tenant->id)->where('limit_code', 'USER_LIMIT')->value('limit_value'));
        $this->assertSame('OPTINEXUS', DB::table('tenant_capacity_limits')->where('tenant_id', $tenant->id)->value('source'));

        $this->nexusTenant(['entitlements' => [$this->capability('ACCOUNTING_CORE'), $this->limit('user_limit', 'unlimited')]]);
        $result = $projector->sync($tenant);
        $this->assertContains('capacity USER_LIMIT: 3 -> unlimited', $result['changes']);
        $this->assertNull(DB::table('tenant_capacity_limits')->where('tenant_id', $tenant->id)->where('limit_code', 'USER_LIMIT')->value('limit_value'));
    }

    public function test_tenant_status_follows_optinexus_but_an_operators_own_suspension_is_respected(): void
    {
        $tenant = $this->linkedTenant();
        $projector = app(EntitlementProjector::class);
        $session = $this->tenantToken($this->member($tenant)[0], $tenant);

        $this->nexusTenant(['status' => 'SUSPENDED']);
        $projector->sync($tenant);
        $this->assertSame(Tenant::SUSPENDED, $tenant->fresh()->status);
        $this->assertNotNull($tenant->fresh()->optinexus_deactivated_at);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('abilities', 'like', '%'.$tenant->id.'%')->count() * 0 + DB::table('personal_access_tokens')->where('id', explode('|', $session)[0])->count(), 'open sessions of the tenant end');

        $this->nexusTenant([]);
        $projector->sync($tenant);
        $this->assertSame(Tenant::ACTIVE, $tenant->fresh()->status, 'OptiNexus switched it off, OptiNexus switches it on');
        $this->assertNull($tenant->fresh()->optinexus_deactivated_at);

        // An operator suspends locally (no marker): OptiNexus saying "active" does not undo it.
        app(TenantService::class)->transition($tenant, Tenant::SUSPENDED, 'operator');
        $projector->sync($tenant);
        $this->assertSame(Tenant::SUSPENDED, $tenant->fresh()->status);

        $tenant2 = $this->linkedTenantTwo();
        $this->nexus['tenants']['cccccccc-0000-4000-8000-000000000002'] = $this->nexusTenantState(['status' => 'TERMINATED']);
        $projector->sync($tenant2);
        $this->assertSame(Tenant::INACTIVE, $tenant2->fresh()->status);
    }

    private function linkedTenantTwo(): Tenant
    {
        $tenant = $this->tenant('second-co', subscribed: false);
        DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_tenant_id' => 'cccccccc-0000-4000-8000-000000000002']);

        return $tenant->refresh();
    }

    public function test_a_tenant_optinexus_no_longer_knows_is_treated_as_terminated(): void
    {
        $tenant = $this->linkedTenant();
        unset($this->nexus['tenants'][self::NEXUS_TENANT]);

        app(EntitlementProjector::class)->sync($tenant);

        $this->assertSame(Tenant::INACTIVE, $tenant->fresh()->status);
    }

    public function test_sign_in_time_sync_runs_only_when_the_projection_is_stale(): void
    {
        $tenant = $this->linkedTenant();
        $projector = app(EntitlementProjector::class);
        $asked = fn () => count($this->nexusRequests('/commercial-context'));

        $projector->syncIfStale($tenant);
        $this->assertSame(1, $asked());

        $projector->syncIfStale($tenant->refresh());
        $this->assertSame(1, $asked(), 'fresh enough');

        $this->travel(301)->seconds();
        $projector->syncIfStale($tenant->refresh());
        $this->assertSame(2, $asked());
    }

    public function test_when_optinexus_is_down_the_last_projection_stays(): void
    {
        $tenant = $this->linkedTenant();
        $projector = app(EntitlementProjector::class);
        $projector->sync($tenant);
        $syncedAt = $tenant->fresh()->optinexus_synced_at;
        $this->nexus['down'] = true;

        try {
            $projector->sync($tenant);
            $this->fail('an outage must surface');
        } catch (IdentityProviderUnavailable) {
        }

        $this->assertTrue($this->decide($tenant, 'x', 'ACCOUNTING_CORE')->allowed);
        $this->assertEquals($syncedAt, $tenant->fresh()->optinexus_synced_at);
    }

    public function test_a_leftover_local_subscription_is_left_alone_and_reported(): void
    {
        $tenant = $this->tenant('legacy-co'); // a LOCAL bundle subscription, as after a mode switch without its migration
        DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_tenant_id' => self::NEXUS_TENANT]);
        $subscription = DB::table('subscriptions')->where('tenant_id', $tenant->id)->first();
        $modules = DB::table('tenant_module_entitlements')->where('tenant_id', $tenant->id)->count();

        $result = app(EntitlementProjector::class)->sync($tenant->refresh());

        $this->assertSame('local subscription', $result['skipped']);
        $this->assertFalse($result['changed']);
        $this->assertEquals($subscription, DB::table('subscriptions')->where('tenant_id', $tenant->id)->first());
        $this->assertSame($modules, DB::table('tenant_module_entitlements')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, DB::table('tenant_module_entitlements')->where('source', 'OPTINEXUS')->count());

        $this->artisan('optiaccounting:nexus:sync-entitlements')->assertSuccessful()->expectsOutputToContain('skipped, local subscription present');
    }

    public function test_a_stray_local_module_window_blocks_only_that_module(): void
    {
        $tenant = $this->linkedTenant();
        $core = DB::table('modules')->where('code', 'ACCOUNTING_CORE')->value('id');
        DB::table('tenant_module_entitlements')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'module_id' => $core, 'state' => 'ACTIVE', 'source' => 'MANUAL_OVERRIDE',
            'effective_from' => '2020-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(EntitlementProjector::class)->sync($tenant); // must not fail on the exclusion constraint

        $this->assertSame(0, DB::table('tenant_module_entitlements')->where('module_id', $core)->where('source', 'OPTINEXUS')->count());
        $this->assertGreaterThan(0, DB::table('tenant_module_entitlements')->where('source', 'OPTINEXUS')->count(), 'the other modules are projected');
    }

    public function test_the_sync_command_projects_every_linked_tenant_and_reports_an_outage(): void
    {
        $linked = $this->linkedTenant();
        $this->tenant('unlinked-co', subscribed: false);

        $this->artisan('optiaccounting:nexus:sync-entitlements')->assertSuccessful()->expectsOutputToContain('acme-id: ');
        $this->assertGreaterThan(0, DB::table('tenant_module_entitlements')->where('tenant_id', $linked->id)->count());

        $this->nexus['down'] = true;
        $this->artisan('optiaccounting:nexus:sync-entitlements')->assertFailed();

        config(['optiaccounting.identity_mode' => 'standalone']);
        $this->artisan('optiaccounting:nexus:sync-entitlements')->assertSuccessful()->expectsOutputToContain('nothing to sync');
    }
}
