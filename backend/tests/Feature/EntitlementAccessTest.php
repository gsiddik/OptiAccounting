<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Feature;
use App\Domain\ProductCatalog\Models\Module;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Entitlement matrix (OA0 §39): module state, windows, features, subscription and tenant status,
 * all through the one EffectiveAccess resolver and a real route gate.
 */
class EntitlementAccessTest extends TestCase
{
    use Fixtures;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $gate = ['api', 'auth:sanctum', 'context:tenant'];
        Route::middleware([...$gate, 'access:organization.view,module=ACCOUNTING_CORE,feature=JOURNAL'])
            ->match(['GET', 'POST'], '/api/v1/_test/journal', fn () => ['ok' => true]);
        Route::middleware([...$gate, 'access:organization.view,module=ACCOUNTING_CORE'])
            ->match(['GET', 'POST'], '/api/v1/_test/core', fn () => ['ok' => true]);

        $this->tenant = $this->tenant('alpha');
        [$user] = $this->member($this->tenant, ['organization.view']);
        $this->token = $this->tenantToken($user, $this->tenant);
    }

    private function module(string $code, array $attrs): void
    {
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)
            ->where('module_id', Module::query()->where('code', $code)->value('id'))->update($attrs);
        app(AccessCache::class)->touchTenant($this->tenant->id);
    }

    private function feature(string $code, array $attrs): void
    {
        DB::table('tenant_feature_entitlements')->where('tenant_id', $this->tenant->id)
            ->where('feature_id', Feature::query()->where('code', $code)->value('id'))->update($attrs);
        app(AccessCache::class)->touchTenant($this->tenant->id);
    }

    private function subscription(array $attrs): void
    {
        DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->update($attrs);
        app(AccessCache::class)->touchTenant($this->tenant->id);
    }

    private function gate(string $path = 'journal')
    {
        return $this->as($this->token)->getJson("/api/v1/_test/{$path}");
    }

    private function write(string $path = 'journal')
    {
        return $this->as($this->token)->postJson("/api/v1/_test/{$path}");
    }

    public function test_active_module_and_feature_allow_reads_and_writes(): void
    {
        $this->gate()->assertOk();
        $this->write()->assertOk();
    }

    public function test_read_only_module_allows_reads_and_blocks_every_mutation(): void
    {
        $this->module('ACCOUNTING_CORE', ['state' => 'READ_ONLY']);

        $this->gate()->assertOk();
        $this->gate('core')->assertOk();
        $this->write()->assertForbidden()->assertJsonPath('code', 'MODULE_READ_ONLY');
        $this->write('core')->assertForbidden()->assertJsonPath('code', 'MODULE_READ_ONLY');
    }

    public function test_suspended_and_disabled_modules_are_denied(): void
    {
        foreach (['SUSPENDED', 'DISABLED'] as $state) {
            $this->module('ACCOUNTING_CORE', ['state' => $state]);
            $this->gate()->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
            $this->gate('core')->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
        }
    }

    public function test_module_that_was_never_granted_is_denied(): void
    {
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->delete();
        app(AccessCache::class)->touchTenant($this->tenant->id);

        $this->gate('core')->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
    }

    public function test_expired_and_future_windows_are_not_usable_and_the_boundaries_are_inclusive(): void
    {
        $today = $this->tenant->businessDate();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();
        $tomorrow = Carbon::parse($today)->addDay()->toDateString();

        $this->module('ACCOUNTING_CORE', ['effective_from' => '2020-01-01', 'effective_until' => $yesterday]);
        $this->gate('core')->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
        $this->write('core')->assertForbidden();

        $this->module('ACCOUNTING_CORE', ['effective_until' => $today]); // last usable day
        $this->write('core')->assertOk();

        $this->module('ACCOUNTING_CORE', ['effective_from' => $tomorrow, 'effective_until' => null]);
        $this->gate('core')->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');

        $this->module('ACCOUNTING_CORE', ['effective_from' => $today]); // first usable day
        $this->gate('core')->assertOk();
    }

    public function test_expiry_is_decided_on_the_tenant_business_date_not_utc(): void
    {
        // 17:30 UTC on Oct 8 is already Oct 9 in Jakarta (UTC+7).
        Carbon::setTestNow('2026-10-08 17:30:00');
        $this->module('ACCOUNTING_CORE', ['effective_from' => '2026-01-01', 'effective_until' => '2026-10-08']);
        $this->subscription(['starts_on' => '2026-01-01', 'ends_on' => null]);

        $this->assertSame('2026-10-09', $this->tenant->fresh()->businessDate());
        $this->gate('core')->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');

        Carbon::setTestNow();
    }

    public function test_feature_enabled_disabled_and_missing(): void
    {
        $this->gate()->assertOk();

        $this->feature('JOURNAL', ['state' => 'DISABLED']);
        $this->gate()->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
        $this->gate('core')->assertOk(); // the module itself is unaffected

        DB::table('tenant_feature_entitlements')->where('tenant_id', $this->tenant->id)->delete();
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->gate()->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
    }

    public function test_feature_is_unusable_when_its_parent_module_is_unavailable(): void
    {
        $this->module('ACCOUNTING_CORE', ['state' => 'DISABLED']);

        // The feature row itself is still ACTIVE.
        $this->assertSame('ACTIVE', DB::table('tenant_feature_entitlements')->where('tenant_id', $this->tenant->id)->value('state'));
        $this->gate()->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
    }

    public function test_feature_follows_a_read_only_parent_module(): void
    {
        $this->module('ACCOUNTING_CORE', ['state' => 'READ_ONLY']);

        $this->gate()->assertOk();
        $this->write()->assertForbidden()->assertJsonPath('code', 'MODULE_READ_ONLY');
    }

    public function test_inactive_module_in_the_catalog_is_unavailable_to_everyone(): void
    {
        Module::query()->where('code', 'ACCOUNTING_CORE')->update(['status' => 'INACTIVE']);
        app(AccessCache::class)->touchTenant($this->tenant->id);

        $this->gate('core')->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
    }

    public function test_subscription_states(): void
    {
        $this->subscription(['status' => 'PENDING']);
        $this->gate('core')->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');

        $this->subscription(['status' => 'ACTIVE']);
        $this->write('core')->assertOk();

        $this->subscription(['status' => 'PAST_DUE']);
        $this->gate('core')->assertOk();
        $this->write('core')->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_READ_ONLY');

        foreach (['SUSPENDED', 'EXPIRED', 'CANCELLED'] as $status) {
            $this->subscription(['status' => $status]);
            $this->gate('core')->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');
        }
    }

    /**
     * OA0 decision: READ_ONLY gates module-bound routes (financial resources arrive in OA1).
     * Administration routes carry no module, so an overdue tenant can still manage its own access.
     */
    public function test_tenant_administration_stays_writable_while_the_subscription_is_overdue(): void
    {
        [$admin] = $this->member($this->tenant, ['organization.view', 'organization.manage'], 'admin@overdue.test');
        $adminToken = $this->tenantToken($admin, $this->tenant);
        $this->subscription(['status' => 'PAST_DUE']);

        $this->as($adminToken)->postJson('/api/v1/app/branches', ['code' => 'LATE', 'name' => 'Cabang saat menunggak'])->assertCreated();
        $this->write('core')->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_READ_ONLY');
    }

    public function test_active_subscription_past_its_end_date_is_not_writable_without_any_scheduler(): void
    {
        $yesterday = Carbon::parse($this->tenant->businessDate())->subDay()->toDateString();
        $this->subscription(['status' => 'ACTIVE', 'starts_on' => '2020-01-01', 'ends_on' => $yesterday]);

        $this->write('core')->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');
    }

    public function test_tenant_without_a_subscription_has_no_module_access_but_keeps_foundation_access(): void
    {
        $bare = $this->tenant('bare', subscribed: false);
        [$user] = $this->member($bare, ['organization.view']);
        $token = $this->tenantToken($user, $bare);

        $this->as($token)->getJson('/api/v1/_test/core')->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');
        $this->as($token)->getJson('/api/v1/app/branches')->assertOk(); // not module-gated
    }

    public function test_inactive_tenant_is_denied_everywhere(): void
    {
        $this->tenant->forceFill(['status' => Tenant::SUSPENDED])->save();

        $this->gate('core')->assertForbidden()->assertJsonPath('code', 'TENANT_INACTIVE');
        $this->as($this->token)->getJson('/api/v1/app/branches')->assertForbidden()->assertJsonPath('code', 'TENANT_INACTIVE');
    }

    public function test_capabilities_endpoint_agrees_with_the_gate(): void
    {
        $this->module('ACCOUNTING_CORE', ['state' => 'READ_ONLY']);
        $this->module('ACCOUNTING_AP', ['state' => 'DISABLED']);
        $this->feature('JOURNAL', ['state' => 'DISABLED']);

        $caps = $this->as($this->token)->getJson('/api/v1/app/capabilities')->assertOk()->json();

        $this->assertSame('READ_ONLY', $caps['modules']['ACCOUNTING_CORE']);
        $this->assertSame('NONE', $caps['modules']['ACCOUNTING_AP']);
        $this->assertFalse($caps['features']['JOURNAL']);
        $this->assertTrue($caps['features']['GENERAL_LEDGER']);
        $this->assertContains('organization.view', $caps['permissions']);
        $this->assertNotContains('organization.manage', $caps['permissions']);
        $this->assertSame('ACTIVE', $caps['subscription']['status']);
        // ...and the gate says the same thing.
        $this->gate()->assertForbidden();
        $this->gate('core')->assertOk();
    }

    public function test_changes_take_effect_immediately_despite_caching(): void
    {
        $this->gate('core')->assertOk(); // warms the snapshot cache
        DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->update(['status' => 'SUSPENDED']);

        // Direct SQL bypasses invalidation, so within the TTL the cache may still answer...
        // ...but every service-level change bumps the version and is visible at once:
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->gate('core')->assertForbidden();
    }
}
