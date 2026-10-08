<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Entitlement\Services\EntitlementSnapshot;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Subscription lifecycle and bundle provisioning (OA0 §37). */
class SubscriptionTest extends TestCase
{
    use Fixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bundleAll();
        $this->tenant = $this->tenant('alpha', subscribed: false);
    }

    private function subscribe(array $body = [], ?Tenant $tenant = null)
    {
        $tenant ??= $this->tenant;

        return $this->asPlatform()->postJson("/api/v1/platform/tenants/{$tenant->id}/subscriptions", [
            'bundle_code' => 'ALL', 'starts_on' => $tenant->businessDate(), 'status' => 'ACTIVE', ...$body,
        ]);
    }

    private function move(string $id, string $status, string $reason = 'test change')
    {
        return $this->asPlatform()->postJson("/api/v1/platform/tenants/{$this->tenant->id}/subscriptions/{$id}/status", ['status' => $status, 'reason' => $reason]);
    }

    private function snapshot(): array
    {
        app(AccessCache::class)->touchTenant($this->tenant->id);

        return app(EntitlementSnapshot::class)->forTenant($this->tenant->fresh());
    }

    public function test_a_bundle_subscription_provisions_every_module_feature_and_capacity_for_its_window(): void
    {
        $this->asPlatform()->postJson('/api/v1/platform/bundles', ['code' => 'LTD', 'name' => 'Limited', 'modules' => ['ACCOUNTING_CORE'], 'capacities' => ['USER_LIMIT' => 5]])->assertCreated();
        $start = $this->tenant->businessDate();
        $end = date('Y-m-d', strtotime($start.' +30 days'));

        $this->subscribe(['bundle_code' => 'LTD', 'ends_on' => $end])->assertCreated()->assertJsonPath('status', 'ACTIVE');

        $module = DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->get();
        $this->assertCount(1, $module);
        $this->assertSame(['ACTIVE', 'BUNDLE', $start, $end], [$module[0]->state, $module[0]->source, $module[0]->effective_from, $module[0]->effective_until]);
        $this->assertGreaterThan(0, $this->rows('tenant_feature_entitlements', ['tenant_id' => $this->tenant->id, 'source' => 'BUNDLE', 'state' => 'ACTIVE']));
        $this->assertSame(5, (int) DB::table('tenant_capacity_limits')->where('tenant_id', $this->tenant->id)->where('limit_code', 'USER_LIMIT')->value('limit_value'));

        $snapshot = $this->snapshot();
        $this->assertSame('FULL', $snapshot['subscription']['mode']);
        $this->assertSame('FULL', $snapshot['modules']['ACCOUNTING_CORE']['mode']);
        $this->assertSame('NONE', $snapshot['modules']['ACCOUNTING_AP']['mode'] ?? 'NONE'); // not in the bundle
    }

    public function test_a_tenant_has_at_most_one_live_subscription(): void
    {
        $first = $this->subscribe(['status' => 'PENDING'])->assertCreated()->json('id');

        $this->subscribe()->assertStatus(409)->assertJsonPath('code', 'SUBSCRIPTION_EXISTS');
        $this->assertSame(1, $this->rows('subscriptions', ['tenant_id' => $this->tenant->id]));

        $this->move($first, 'CANCELLED')->assertOk();
        $this->subscribe()->assertCreated(); // history stays, a new live one is allowed
        $this->assertSame(2, $this->rows('subscriptions', ['tenant_id' => $this->tenant->id]));
    }

    public function test_only_documented_transitions_are_allowed(): void
    {
        $id = $this->subscribe(['status' => 'PENDING'])->assertCreated()->json('id');

        $this->move($id, 'PAST_DUE')->assertStatus(422)->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->move($id, 'ACTIVE')->assertOk();
        $this->move($id, 'PAST_DUE')->assertOk();
        $this->move($id, 'ACTIVE')->assertOk();
        $this->move($id, 'SUSPENDED')->assertOk();
        $this->move($id, 'PAST_DUE')->assertStatus(422)->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->move($id, 'ACTIVE')->assertOk();
        $this->move($id, 'EXPIRED')->assertOk();

        foreach (['ACTIVE', 'CANCELLED', 'PENDING', 'EXPIRED'] as $to) { // a finished subscription never comes back
            $this->move($id, $to)->assertStatus(422);
        }
    }

    public function test_a_transition_needs_a_reason_and_is_audited(): void
    {
        $id = $this->subscribe()->assertCreated()->json('id');

        $this->asPlatform()->postJson("/api/v1/platform/tenants/{$this->tenant->id}/subscriptions/{$id}/status", ['status' => 'SUSPENDED'])->assertStatus(422);
        $this->assertSame('ACTIVE', DB::table('subscriptions')->where('id', $id)->value('status'));

        $this->move($id, 'SUSPENDED', 'unpaid invoice 42')->assertOk();
        $log = DB::table('audit_logs')->where('action', 'subscription.status_changed')->where('resource_id', $id)->first();
        $this->assertStringContainsString('unpaid invoice 42', $log->changes);
    }

    public function test_status_decides_the_access_mode(): void
    {
        $id = $this->subscribe()->assertCreated()->json('id');
        $this->assertSame('FULL', $this->snapshot()['subscription']['mode']);

        $this->move($id, 'PAST_DUE')->assertOk();
        $this->assertSame('READ_ONLY', $this->snapshot()['subscription']['mode']);

        $this->move($id, 'SUSPENDED')->assertOk();
        $this->assertSame('NONE', $this->snapshot()['subscription']['mode']);
    }

    public function test_expiring_closes_the_provisioned_windows_but_keeps_history(): void
    {
        $start = date('Y-m-d', strtotime($this->tenant->businessDate().' -10 days'));
        $id = $this->subscribe(['starts_on' => $start])->assertCreated()->json('id');
        $before = $this->rows('tenant_module_entitlements', ['tenant_id' => $this->tenant->id]);

        $this->move($id, 'EXPIRED')->assertOk();

        $yesterday = date('Y-m-d', strtotime($this->tenant->businessDate().' -1 day'));
        $this->assertSame($before, $this->rows('tenant_module_entitlements', ['tenant_id' => $this->tenant->id]));
        $this->assertSame(0, DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('effective_until', '<>', $yesterday)->count());
        $this->assertSame(0, DB::table('tenant_feature_entitlements')->where('tenant_id', $this->tenant->id)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $yesterday))->count());

        $snapshot = $this->snapshot();
        $this->assertSame('NONE', $snapshot['subscription']['mode']);
        $this->assertSame('NONE', $snapshot['modules']['ACCOUNTING_CORE']['mode'] ?? 'NONE');
    }

    public function test_cancelling_a_subscription_that_never_started_removes_its_windows(): void
    {
        $future = date('Y-m-d', strtotime($this->tenant->businessDate().' +20 days'));
        $id = $this->subscribe(['starts_on' => $future, 'status' => 'PENDING'])->assertCreated()->json('id');

        $this->move($id, 'CANCELLED')->assertOk();

        $this->assertSame(0, $this->rows('tenant_module_entitlements', ['tenant_id' => $this->tenant->id]));
        $this->assertSame(0, $this->rows('tenant_feature_entitlements', ['tenant_id' => $this->tenant->id]));
        $this->assertSame('NONE', $this->snapshot()['subscription']['mode']);
    }

    public function test_a_future_subscription_gives_no_access_until_its_start_date(): void
    {
        $future = date('Y-m-d', strtotime($this->tenant->businessDate().' +5 days'));
        $this->subscribe(['starts_on' => $future])->assertCreated();

        $snapshot = $this->snapshot();
        $this->assertSame('NONE', $snapshot['subscription']['mode']);
        $this->assertSame('NONE', $snapshot['modules']['ACCOUNTING_CORE']['mode'] ?? 'NONE');
    }

    public function test_rescheduling_moves_the_provisioned_windows_with_it(): void
    {
        $id = $this->subscribe()->assertCreated()->json('id');
        $start = $this->tenant->businessDate();
        $end = date('Y-m-d', strtotime($start.' +90 days'));

        $this->asPlatform()->patchJson("/api/v1/platform/tenants/{$this->tenant->id}/subscriptions/{$id}", ['starts_on' => $start, 'ends_on' => $end])->assertOk();

        $this->assertSame(0, DB::table('tenant_module_entitlements')->where('subscription_id', $id)->where(fn ($q) => $q->where('effective_until', '<>', $end)->orWhereNull('effective_until'))->count());
        $this->asPlatform()->patchJson("/api/v1/platform/tenants/{$this->tenant->id}/subscriptions/{$id}", ['starts_on' => $end, 'ends_on' => $start])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_WINDOW');

        $this->move($id, 'CANCELLED')->assertOk();
        $this->asPlatform()->patchJson("/api/v1/platform/tenants/{$this->tenant->id}/subscriptions/{$id}", ['starts_on' => $start, 'ends_on' => $end])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_STATUS');
    }

    public function test_an_inactive_bundle_and_a_reversed_window_are_refused(): void
    {
        $bundleId = DB::table('bundles')->where('code', 'ALL')->value('id');
        $this->asPlatform()->patchJson("/api/v1/platform/bundles/{$bundleId}", ['status' => 'INACTIVE'])->assertOk();
        $this->subscribe()->assertStatus(422)->assertJsonPath('code', 'BUNDLE_INACTIVE');

        $this->asPlatform()->patchJson("/api/v1/platform/bundles/{$bundleId}", ['status' => 'ACTIVE'])->assertOk();
        $this->subscribe(['starts_on' => '2027-02-01', 'ends_on' => '2027-01-01'])->assertStatus(422)->assertJsonPath('code', 'INVALID_WINDOW');
    }

    public function test_a_subscription_without_a_bundle_is_allowed_and_grants_no_modules(): void
    {
        $this->subscribe(['bundle_code' => null])->assertCreated();

        $this->assertSame(0, $this->rows('tenant_module_entitlements', ['tenant_id' => $this->tenant->id]));
        $this->assertSame('FULL', $this->snapshot()['subscription']['mode']);
    }

    public function test_changing_the_bundle_later_does_not_touch_an_existing_subscription(): void
    {
        $this->subscribe()->assertCreated();
        $before = $this->rows('tenant_module_entitlements', ['tenant_id' => $this->tenant->id]);
        $bundleId = DB::table('bundles')->where('code', 'ALL')->value('id');

        $this->asPlatform()->patchJson("/api/v1/platform/bundles/{$bundleId}", ['modules' => ['ACCOUNTING_CORE']])->assertOk();

        $this->assertSame($before, $this->rows('tenant_module_entitlements', ['tenant_id' => $this->tenant->id]));
        $this->assertSame(Module::query()->count(), $before);
    }

    public function test_the_tenant_sees_only_its_own_subscription_history(): void
    {
        $this->subscribe()->assertCreated();
        $beta = $this->tenant('beta', subscribed: false);
        $this->subscribe([], $beta)->assertCreated();
        $token = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);

        $history = $this->as($token)->getJson('/api/v1/app/account/subscription')->assertOk()->json('history');

        $this->assertCount(1, $history);
        $this->assertSame($this->tenant->id, $history[0]['tenant_id']);
    }

    public function test_a_platform_user_without_the_permission_cannot_manage_subscriptions(): void
    {
        $viewer = $this->platformToken($this->platformUser(['platform.subscription.view']));

        $this->as($viewer)->getJson("/api/v1/platform/tenants/{$this->tenant->id}/subscriptions")->assertOk();
        $this->as($viewer)->postJson("/api/v1/platform/tenants/{$this->tenant->id}/subscriptions", [
            'bundle_code' => 'ALL', 'starts_on' => $this->tenant->businessDate(),
        ])->assertForbidden();
    }
}
