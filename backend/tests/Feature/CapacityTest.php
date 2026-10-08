<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Capacity limits (OA0 §37): below / at / above the limit, unlimited, per tenant, and the row lock that serialises them. */
class CapacityTest extends TestCase
{
    use Fixtures;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->tenant('alpha');
        $this->token = $this->tenantToken($this->member($this->tenant, null, 'admin@alpha.test')[0], $this->tenant);
    }

    private function limit(string $code, ?int $value, ?Tenant $tenant = null): void
    {
        $tenant ??= $this->tenant;
        $this->asPlatform()->putJson("/api/v1/platform/tenants/{$tenant->id}/capacity/{$code}", ['limit_value' => $value, 'source' => 'MANUAL_OVERRIDE'])->assertOk();
    }

    private function branch(string $code)
    {
        return $this->as($this->token)->postJson('/api/v1/app/branches', ['code' => $code, 'name' => "Branch {$code}"]);
    }

    private function user(string $email)
    {
        return $this->as($this->token)->postJson('/api/v1/app/users', ['name' => 'Staff', 'email' => $email, 'password' => self::PASSWORD, 'role_ids' => []]);
    }

    public function test_branches_are_allowed_up_to_the_limit_and_refused_beyond_it(): void
    {
        $this->limit('BRANCH_LIMIT', 2);

        $this->branch('B1')->assertCreated();
        $this->branch('B2')->assertCreated(); // exactly at the limit
        $this->branch('B3')->assertStatus(409)->assertJsonPath('code', 'CAPACITY_EXCEEDED')
            ->assertJsonPath('details.limit_code', 'BRANCH_LIMIT')->assertJsonPath('details.limit', 2)->assertJsonPath('details.used', 2);

        $this->assertSame(2, $this->rows('branches', ['tenant_id' => $this->tenant->id]));
    }

    public function test_no_limit_and_a_null_limit_mean_unlimited_while_zero_blocks_everything(): void
    {
        foreach (['U1', 'U2', 'U3', 'U4', 'U5'] as $code) { // no limit row at all
            $this->branch($code)->assertCreated();
        }

        $this->limit('BRANCH_LIMIT', null);
        $this->branch('U6')->assertCreated();

        $this->limit('BRANCH_LIMIT', 0);
        $this->branch('U7')->assertStatus(409)->assertJsonPath('code', 'CAPACITY_EXCEEDED');
    }

    public function test_inactive_records_free_their_slot_and_reactivation_respects_the_limit(): void
    {
        $this->limit('BRANCH_LIMIT', 1);
        $first = $this->branch('B1')->assertCreated()->json('id');
        $this->branch('B2')->assertStatus(409);

        $this->as($this->token)->postJson("/api/v1/app/branches/{$first}/status", ['status' => 'INACTIVE'])->assertOk();
        $second = $this->branch('B2')->assertCreated()->json('id');

        $this->as($this->token)->postJson("/api/v1/app/branches/{$first}/status", ['status' => 'ACTIVE'])
            ->assertStatus(409)->assertJsonPath('code', 'CAPACITY_EXCEEDED');
        $this->assertNotNull($second);
    }

    public function test_business_unit_and_user_limits_are_enforced_independently(): void
    {
        $this->limit('BUSINESS_UNIT_LIMIT', 1);
        $this->as($this->token)->postJson('/api/v1/app/business-units', ['code' => 'U1', 'name' => 'U1'])->assertCreated();
        $this->as($this->token)->postJson('/api/v1/app/business-units', ['code' => 'U2', 'name' => 'U2'])->assertStatus(409)->assertJsonPath('details.limit_code', 'BUSINESS_UNIT_LIMIT');
        $this->branch('FREE')->assertCreated(); // other limits are unaffected

        $this->limit('USER_LIMIT', 2); // the administrator already counts as one
        $this->user('one@alpha.test')->assertCreated();
        $this->user('two@alpha.test')->assertStatus(409)->assertJsonPath('details.limit_code', 'USER_LIMIT');
        $this->assertSame(0, $this->rows('users', ['email' => 'two@alpha.test']));
    }

    public function test_deactivating_a_member_frees_a_user_slot(): void
    {
        $this->limit('USER_LIMIT', 2);
        $membership = $this->user('one@alpha.test')->assertCreated()->json('id');
        $this->user('two@alpha.test')->assertStatus(409);

        $this->as($this->token)->postJson("/api/v1/app/users/{$membership}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->user('two@alpha.test')->assertCreated();
        $this->as($this->token)->postJson("/api/v1/app/users/{$membership}/status", ['status' => 'ACTIVE'])->assertStatus(409);
    }

    public function test_lowering_a_limit_below_current_usage_keeps_data_but_blocks_new_records(): void
    {
        $this->branch('B1')->assertCreated();
        $this->branch('B2')->assertCreated();
        $this->branch('B3')->assertCreated();

        $this->limit('BRANCH_LIMIT', 1);

        $this->assertSame(3, $this->rows('branches', ['tenant_id' => $this->tenant->id]));
        $this->branch('B4')->assertStatus(409)->assertJsonPath('details.used', 3);
    }

    public function test_limits_are_per_tenant(): void
    {
        $beta = $this->tenant('beta');
        $betaToken = $this->tenantToken($this->member($beta)[0], $beta);
        $this->limit('BRANCH_LIMIT', 1);
        $this->limit('BRANCH_LIMIT', 3, $beta);

        $this->branch('A1')->assertCreated();
        $this->branch('A2')->assertStatus(409);

        foreach (['B1', 'B2', 'B3'] as $code) {
            $this->as($betaToken)->postJson('/api/v1/app/branches', ['code' => $code, 'name' => $code])->assertCreated();
        }
        $this->as($betaToken)->postJson('/api/v1/app/branches', ['code' => 'B4', 'name' => 'B4'])->assertStatus(409);
    }

    public function test_the_usage_view_reports_limit_used_and_remaining(): void
    {
        $this->limit('BRANCH_LIMIT', 3);
        $this->branch('B1')->assertCreated();

        $usage = collect($this->as($this->token)->getJson('/api/v1/app/account/usage')->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(['code' => 'BRANCH_LIMIT', 'limit' => 3, 'used' => 1, 'remaining' => 2], $usage['BRANCH_LIMIT']);
        $this->assertNull($usage['USER_LIMIT']['limit']);
        $this->assertNull($usage['USER_LIMIT']['remaining']);
        $this->assertSame(1, $usage['USER_LIMIT']['used']);
    }

    public function test_a_bundle_sets_the_capacity_of_the_tenant_it_is_subscribed_to(): void
    {
        $this->asPlatform()->postJson('/api/v1/platform/bundles', [
            'code' => 'TINY', 'name' => 'Tiny', 'modules' => ['ACCOUNTING_CORE'], 'capacities' => ['BRANCH_LIMIT' => 1, 'USER_LIMIT' => null],
        ])->assertCreated();
        $tenant = $this->tenant('gamma', subscribed: false);
        $this->asPlatform()->postJson("/api/v1/platform/tenants/{$tenant->id}/subscriptions", [
            'bundle_code' => 'TINY', 'starts_on' => $tenant->businessDate(), 'status' => 'ACTIVE',
        ])->assertCreated();

        $token = $this->tenantToken($this->member($tenant)[0], $tenant);
        $this->as($token)->postJson('/api/v1/app/branches', ['code' => 'G1', 'name' => 'G1'])->assertCreated();
        $this->as($token)->postJson('/api/v1/app/branches', ['code' => 'G2', 'name' => 'G2'])->assertStatus(409);
    }

    public function test_unknown_capacity_codes_are_refused(): void
    {
        $this->asPlatform()->putJson("/api/v1/platform/tenants/{$this->tenant->id}/capacity/NOT_A_LIMIT", ['limit_value' => 1, 'source' => 'MANUAL_OVERRIDE'])
            ->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_CAPACITY');
    }

    public function test_the_reservation_takes_a_row_lock_on_the_tenant_before_counting(): void
    {
        $this->limit('BRANCH_LIMIT', 5);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->branch('LOCK')->assertCreated();

        $lock = collect($queries)->search(fn ($sql) => str_contains($sql, 'from "tenants"') && str_contains($sql, 'for update'));
        $count = collect($queries)->search(fn ($sql) => str_contains($sql, 'count(*)') && str_contains($sql, '"branches"'));
        $insert = collect($queries)->search(fn ($sql) => str_starts_with($sql, 'insert into "branches"'));

        $this->assertNotFalse($lock, 'the tenant row must be locked');
        $this->assertLessThan($count, $lock);
        $this->assertLessThan($insert, $count);
    }
}
