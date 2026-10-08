<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\AccessControl\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Organization\Services\OrganizationService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Tenant A against tenant B (OA0 §38): reads, search, writes, relationships, cache and DB constraints. */
class TenantIsolationTest extends TestCase
{
    use Fixtures;

    private Tenant $alpha;

    private Tenant $beta;

    private string $token; // alpha administrator

    private Branch $alphaBranch;

    private Branch $betaBranch;

    private BusinessUnit $betaUnit;

    private TenantUser $betaMember;

    private Role $betaRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->tenant('alpha');
        $this->beta = $this->tenant('beta');

        [$admin] = $this->member($this->alpha, null, 'admin@alpha.test');
        $this->token = $this->tenantToken($admin, $this->alpha);

        $org = app(OrganizationService::class);
        $ctx = app(TenantContext::class);
        $this->alphaBranch = $ctx->runAs($this->alpha->id, fn () => $org->createBranch($this->alpha->id, ['code' => 'HQ', 'name' => 'Alpha HQ']));
        $this->betaBranch = $ctx->runAs($this->beta->id, fn () => $org->createBranch($this->beta->id, ['code' => 'HQ', 'name' => 'Beta HQ']));
        $this->betaUnit = $ctx->runAs($this->beta->id, fn () => $org->createBusinessUnit($this->beta->id, ['code' => 'BU1', 'name' => 'Beta unit'], $this->betaBranch->id));

        [, $this->betaMember] = $this->member($this->beta, ['organization.view'], 'secret@beta.test');
        $this->betaRole = Role::query()->forTenant($this->beta->id)->firstOrFail();
    }

    private function api(): static
    {
        return $this->as($this->token);
    }

    public function test_lists_only_show_the_callers_tenant(): void
    {
        $branches = $this->api()->getJson('/api/v1/app/branches')->assertOk()->json('data');
        $this->assertSame([$this->alphaBranch->id], array_column($branches, 'id'));

        $this->assertSame([], $this->api()->getJson('/api/v1/app/business-units')->json('data'));

        $emails = array_column(array_column($this->api()->getJson('/api/v1/app/users')->json('data'), 'user'), 'email');
        $this->assertNotContains('secret@beta.test', $emails);

        $roleIds = array_column($this->api()->getJson('/api/v1/app/roles')->json('data'), 'id');
        $this->assertNotContains($this->betaRole->id, $roleIds);
    }

    public function test_search_and_filters_do_not_reach_other_tenants(): void
    {
        $this->api()->getJson('/api/v1/app/users?search=secret@beta.test')->assertOk()->assertJsonCount(0, 'data');
        $this->api()->getJson('/api/v1/app/users?search=beta')->assertOk()->assertJsonCount(0, 'data');
        $this->api()->getJson('/api/v1/app/users?status=ACTIVE&search=%25')->assertOk();
        $this->api()->getJson('/api/v1/app/audit-logs?action=')->assertOk();
    }

    public function test_other_tenants_records_are_not_found_for_read_update_and_deactivate(): void
    {
        $this->api()->patchJson("/api/v1/app/branches/{$this->betaBranch->id}", ['name' => 'hacked'])->assertNotFound();
        $this->api()->postJson("/api/v1/app/branches/{$this->betaBranch->id}/status", ['status' => 'INACTIVE'])->assertNotFound();
        $this->api()->patchJson("/api/v1/app/business-units/{$this->betaUnit->id}", ['name' => 'hacked'])->assertNotFound();
        $this->api()->postJson("/api/v1/app/business-units/{$this->betaUnit->id}/status", ['status' => 'INACTIVE'])->assertNotFound();

        $this->api()->postJson("/api/v1/app/users/{$this->betaMember->id}/status", ['status' => 'SUSPENDED'])->assertNotFound();
        $this->api()->putJson("/api/v1/app/users/{$this->betaMember->id}/roles", ['role_ids' => []])->assertNotFound();
        $this->api()->putJson("/api/v1/app/users/{$this->betaMember->id}/data-scopes", ['data_scopes' => []])->assertNotFound();

        $this->api()->patchJson("/api/v1/app/roles/{$this->betaRole->id}", ['name' => 'hacked'])->assertNotFound();
        $this->api()->deleteJson("/api/v1/app/roles/{$this->betaRole->id}")->assertNotFound();

        $this->assertSame('Beta HQ', $this->betaBranch->fresh()->name);
        $this->assertSame('ACTIVE', $this->betaMember->fresh()->status);
    }

    public function test_own_records_resolve_through_route_binding_after_the_tenant_context_is_set(): void
    {
        $this->api()->patchJson("/api/v1/app/branches/{$this->alphaBranch->id}", ['name' => 'Alpha Head Office'])
            ->assertOk()->assertJsonPath('name', 'Alpha Head Office');

        $unit = $this->api()->postJson('/api/v1/app/business-units', ['code' => 'OPS', 'name' => 'Ops', 'branch_id' => $this->alphaBranch->id])
            ->assertCreated()->json('id');
        $this->api()->patchJson("/api/v1/app/business-units/{$unit}", ['name' => 'Operations'])->assertOk()->assertJsonPath('name', 'Operations');
        $this->api()->postJson("/api/v1/app/business-units/{$unit}/status", ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('status', 'INACTIVE');
    }

    public function test_cross_tenant_relationships_cannot_be_created(): void
    {
        $this->api()->postJson('/api/v1/app/business-units', ['code' => 'X', 'name' => 'X', 'branch_id' => $this->betaBranch->id])
            ->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_BRANCH');

        $own = $this->api()->postJson('/api/v1/app/business-units', ['code' => 'OWN', 'name' => 'Own'])->assertCreated()->json('id');
        $this->api()->patchJson("/api/v1/app/business-units/{$own}", ['branch_id' => $this->betaBranch->id])
            ->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_BRANCH');

        [, $alphaMember] = $this->member($this->alpha, ['organization.view']);
        $this->api()->putJson("/api/v1/app/users/{$alphaMember->id}/roles", ['role_ids' => [$this->betaRole->id]])
            ->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_ROLE');
        $this->api()->putJson("/api/v1/app/users/{$alphaMember->id}/data-scopes", ['data_scopes' => [['scope_type' => 'BRANCH', 'branch_id' => $this->betaBranch->id]]])
            ->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_BRANCH');

        $this->assertSame(0, DB::table('tenant_user_roles')->where('tenant_user_id', $alphaMember->id)->where('role_id', $this->betaRole->id)->count());
    }

    public function test_tenant_id_in_a_payload_is_ignored(): void
    {
        $created = $this->api()->postJson('/api/v1/app/branches', [
            'code' => 'EVIL', 'name' => 'Evil', 'tenant_id' => $this->beta->id, 'status' => 'INACTIVE',
        ])->assertCreated()->json();

        $this->assertSame($this->alpha->id, $created['tenant_id']);
        $this->assertSame('ACTIVE', $created['status']);
        $this->assertSame(0, Branch::withoutGlobalScopes()->where('tenant_id', $this->beta->id)->where('code', 'EVIL')->count());
    }

    public function test_account_audit_and_capacity_views_are_tenant_specific(): void
    {
        $this->api()->getJson('/api/v1/app/branches'); // warm caches

        $usage = collect($this->api()->getJson('/api/v1/app/account/usage')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(1, $usage['BRANCH_LIMIT']['used']); // alpha has one branch; beta's is not counted

        $subscriptions = $this->api()->getJson('/api/v1/app/account/subscription')->assertOk()->json('history');
        $this->assertCount(1, $subscriptions);

        $audit = $this->api()->getJson('/api/v1/app/audit-logs')->assertOk()->json('data');
        $this->assertNotEmpty($audit);
        foreach ($audit as $row) {
            $this->assertSame($this->alpha->id, $row['tenant_id']);
        }

        $this->assertNotEmpty(DB::table('audit_logs')->where('tenant_id', $this->beta->id)->get());
    }

    public function test_models_fail_closed_without_a_tenant_context(): void
    {
        $this->assertSame(0, Branch::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(0, TenantUser::query()->count());

        $this->expectException(LogicException::class);
        (new Branch(['code' => 'NOPE', 'name' => 'No context']))->save();
    }

    public function test_writing_for_another_tenant_inside_a_context_is_refused(): void
    {
        $this->expectException(LogicException::class);

        app(TenantContext::class)->runAs($this->alpha->id, function () {
            $branch = new Branch(['code' => 'X', 'name' => 'X']);
            $branch->tenant_id = $this->beta->id;
            $branch->save();
        });
    }

    public function test_database_constraints_reject_cross_tenant_links_even_for_raw_sql(): void
    {
        [, $alphaMember] = $this->member($this->alpha, ['organization.view']);

        $this->expectException(QueryException::class);
        // alpha membership + beta role: the composite foreign key (tenant_id, role_id) has no such row.
        DB::table('tenant_user_roles')->insert([
            'tenant_id' => $this->alpha->id, 'tenant_user_id' => $alphaMember->id, 'role_id' => $this->betaRole->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_a_business_unit_pointing_at_another_tenants_branch(): void
    {
        $this->expectException(QueryException::class);
        DB::table('business_units')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->alpha->id, 'branch_id' => $this->betaBranch->id,
            'code' => 'RAW', 'name' => 'Raw', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_a_data_scope_for_another_tenants_branch(): void
    {
        [, $alphaMember] = $this->member($this->alpha, ['organization.view']);

        $this->expectException(QueryException::class);
        DB::table('data_scopes')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->alpha->id, 'tenant_user_id' => $alphaMember->id,
            'scope_type' => DataScope::BRANCH, 'branch_id' => $this->betaBranch->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_access_cache_is_tenant_aware(): void
    {
        // Same permission code, different tenants: a viewer in beta has no rights in alpha through caching.
        [$betaViewer] = $this->member($this->beta, ['organization.view'], 'viewer@beta.test');
        $this->as($this->tenantToken($betaViewer, $this->beta))->getJson('/api/v1/app/branches')->assertOk();

        [$alphaNobody] = $this->member($this->alpha, []);
        $this->as($this->tenantToken($alphaNobody, $this->alpha))->getJson('/api/v1/app/branches')
            ->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_a_user_in_both_tenants_only_gets_the_token_tenant(): void
    {
        [$user] = $this->member($this->alpha, ['organization.view']);
        $this->member($this->beta, ['organization.view'], user: $user);

        $names = array_column($this->as($this->tenantToken($user, $this->alpha))->getJson('/api/v1/app/branches')->json('data'), 'name');
        $this->assertSame(['Alpha HQ'], $names);
    }
}
