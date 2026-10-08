<?php

namespace Tests\Feature;

use App\Domain\AccessControl\AccessDecision;
use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Models\DataScope;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Organization\Services\OrganizationService;
use App\Support\TenantContext;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Data scope (OA0 §36): TENANT / BRANCH / BUSINESS_UNIT / OWN, no rows = no access, tenant match, escalation. */
class DataScopeTest extends TestCase
{
    use Fixtures;

    private Tenant $tenant;

    private Branch $north;

    private Branch $south;

    private BusinessUnit $northSales;

    private BusinessUnit $northOps;

    private BusinessUnit $southSales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->tenant('alpha');

        $org = app(OrganizationService::class);
        app(TenantContext::class)->runAs($this->tenant->id, function () use ($org) {
            $this->north = $org->createBranch($this->tenant->id, ['code' => 'N', 'name' => 'North']);
            $this->south = $org->createBranch($this->tenant->id, ['code' => 'S', 'name' => 'South']);
            $this->northSales = $org->createBusinessUnit($this->tenant->id, ['code' => 'NS', 'name' => 'North sales'], $this->north->id);
            $this->northOps = $org->createBusinessUnit($this->tenant->id, ['code' => 'NO', 'name' => 'North ops'], $this->north->id);
            $this->southSales = $org->createBusinessUnit($this->tenant->id, ['code' => 'SS', 'name' => 'South sales'], $this->south->id);
        });
    }

    /** @param list<array{scope_type:string,branch_id?:string,business_unit_id?:string}> $scopes */
    private function memberWith(array $scopes): array
    {
        [$user, $membership] = $this->member($this->tenant, ['organization.view']);
        $this->setScopes($membership, $scopes);

        return [$user, $membership];
    }

    private function setScopes(TenantUser $membership, array $scopes): void
    {
        app(TenantContext::class)->runAs($this->tenant->id, function () use ($membership, $scopes) {
            $membership->dataScopes()->delete();
            foreach ($scopes as $row) {
                $scope = new DataScope;
                $scope->tenant_id = $this->tenant->id;
                $scope->tenant_user_id = $membership->id;
                $scope->scope_type = $row['scope_type'];
                $scope->branch_id = $row['branch_id'] ?? null;
                $scope->business_unit_id = $row['business_unit_id'] ?? null;
                $scope->save();
            }
        });
        app(AccessCache::class)->touchTenant($this->tenant->id);
    }

    private function can(User $user, array $resource): bool
    {
        $decision = app(EffectiveAccess::class)->evaluate(new AccessRequest($user, $this->tenant->id, 'organization.view', resource: $resource));

        return $decision->allowed;
    }

    public function test_a_member_without_scope_rows_reaches_nothing(): void
    {
        [$user, $membership] = $this->memberWith([]);

        $scope = app(DataScopeService::class)->resolve($this->tenant->id, $membership->id);
        $this->assertFalse($scope['tenant']);
        $this->assertSame([], $scope['branch_ids']);
        $this->assertFalse($this->can($user, ['branch_id' => $this->north->id]));
        $this->assertFalse($this->can($user, ['business_unit_id' => $this->northSales->id]));
        $this->assertFalse($this->can($user, ['owner_id' => $user->id]));
        $this->assertFalse($this->can($user, []));
    }

    public function test_a_tenant_scope_reaches_every_record_of_the_tenant(): void
    {
        [$user] = $this->memberWith([['scope_type' => DataScope::TENANT]]);

        $this->assertTrue($this->can($user, ['branch_id' => $this->south->id]));
        $this->assertTrue($this->can($user, ['business_unit_id' => $this->southSales->id]));
        $this->assertTrue($this->can($user, ['branch_id' => null, 'business_unit_id' => null]));
    }

    public function test_a_branch_scope_covers_that_branch_and_its_business_units_only(): void
    {
        [$user] = $this->memberWith([['scope_type' => DataScope::BRANCH, 'branch_id' => $this->north->id]]);

        $this->assertTrue($this->can($user, ['branch_id' => $this->north->id]));
        $this->assertTrue($this->can($user, ['business_unit_id' => $this->northSales->id]));
        $this->assertTrue($this->can($user, ['business_unit_id' => $this->northOps->id]));
        $this->assertFalse($this->can($user, ['branch_id' => $this->south->id]));
        $this->assertFalse($this->can($user, ['business_unit_id' => $this->southSales->id]));
        $this->assertFalse($this->can($user, ['branch_id' => null, 'business_unit_id' => null]));
    }

    public function test_a_business_unit_scope_covers_only_that_unit(): void
    {
        [$user] = $this->memberWith([['scope_type' => DataScope::BUSINESS_UNIT, 'business_unit_id' => $this->northSales->id]]);

        $this->assertTrue($this->can($user, ['business_unit_id' => $this->northSales->id]));
        $this->assertFalse($this->can($user, ['business_unit_id' => $this->northOps->id]));
        $this->assertFalse($this->can($user, ['branch_id' => $this->north->id]));
    }

    public function test_an_own_scope_covers_only_records_owned_by_the_user(): void
    {
        [$user] = $this->memberWith([['scope_type' => DataScope::OWN]]);
        [$other] = $this->member($this->tenant);

        $this->assertTrue($this->can($user, ['owner_id' => $user->id]));
        $this->assertFalse($this->can($user, ['owner_id' => $other->id]));
        $this->assertFalse($this->can($user, ['branch_id' => $this->north->id]));
    }

    public function test_scopes_combine_as_a_union(): void
    {
        [$user] = $this->memberWith([
            ['scope_type' => DataScope::BRANCH, 'branch_id' => $this->north->id],
            ['scope_type' => DataScope::BUSINESS_UNIT, 'business_unit_id' => $this->southSales->id],
            ['scope_type' => DataScope::OWN],
        ]);

        $this->assertTrue($this->can($user, ['business_unit_id' => $this->northOps->id]));
        $this->assertTrue($this->can($user, ['business_unit_id' => $this->southSales->id]));
        $this->assertTrue($this->can($user, ['owner_id' => $user->id]));
        $this->assertFalse($this->can($user, ['branch_id' => $this->south->id]));
    }

    public function test_a_tenant_scope_never_reaches_a_record_of_another_tenant(): void
    {
        [$user] = $this->memberWith([['scope_type' => DataScope::TENANT]]);
        $beta = $this->tenant('beta');

        $decision = app(EffectiveAccess::class)->evaluate(new AccessRequest($user, $this->tenant->id, 'organization.view', resource: ['tenant_id' => $beta->id]));

        $this->assertFalse($decision->allowed);
        $this->assertSame(AccessDecision::TENANT_MISMATCH, $decision->code);
    }

    public function test_a_denied_scope_reports_the_data_scope_code(): void
    {
        [$user] = $this->memberWith([['scope_type' => DataScope::BRANCH, 'branch_id' => $this->north->id]]);

        $decision = app(EffectiveAccess::class)->evaluate(new AccessRequest($user, $this->tenant->id, 'organization.view', resource: ['branch_id' => $this->south->id]));

        $this->assertSame(AccessDecision::DATA_SCOPE_DENIED, $decision->code);
    }

    public function test_query_restriction_matches_the_scope(): void
    {
        $service = app(DataScopeService::class);
        $restrict = fn (array $scope) => app(TenantContext::class)->runAs($this->tenant->id, fn () => $service
            ->applyToQuery(BusinessUnit::query(), $scope, 'u', ['branch' => 'branch_id', 'business_unit' => 'id'])->orderBy('code')->pluck('code')->all());

        [, $northMember] = $this->memberWith([['scope_type' => DataScope::BRANCH, 'branch_id' => $this->north->id]]);
        [, $unitMember] = $this->memberWith([['scope_type' => DataScope::BUSINESS_UNIT, 'business_unit_id' => $this->southSales->id]]);
        [, $nothing] = $this->memberWith([]);
        [, $everything] = $this->memberWith([['scope_type' => DataScope::TENANT]]);

        $this->assertSame(['NO', 'NS'], $restrict($service->resolve($this->tenant->id, $northMember->id)));
        $this->assertSame(['SS'], $restrict($service->resolve($this->tenant->id, $unitMember->id)));
        $this->assertSame([], $restrict($service->resolve($this->tenant->id, $nothing->id)));
        $this->assertSame(['NO', 'NS', 'SS'], $restrict($service->resolve($this->tenant->id, $everything->id)));
    }

    public function test_owner_restriction_uses_the_owner_column(): void
    {
        $service = app(DataScopeService::class);
        [$user, $membership] = $this->memberWith([['scope_type' => DataScope::OWN]]);
        [, $other] = $this->member($this->tenant);

        $rows = app(TenantContext::class)->runAs($this->tenant->id, fn () => $service
            ->applyToQuery(TenantUser::query(), $service->resolve($this->tenant->id, $membership->id), $user->id, ['owner' => 'user_id', 'branch' => 'tenant_id', 'business_unit' => 'tenant_id'])
            ->pluck('id')->all());

        $this->assertSame([$membership->id], $rows);
        $this->assertNotContains($other->id, $rows);
    }

    public function test_changing_a_scope_applies_immediately_through_the_api(): void
    {
        $admin = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);
        [, $membership] = $this->memberWith([]);

        $this->as($admin)->putJson("/api/v1/app/users/{$membership->id}/data-scopes", ['data_scopes' => [['scope_type' => 'BRANCH', 'branch_id' => $this->north->id]]])
            ->assertOk()->assertJsonCount(1, 'data_scopes');

        $scope = app(DataScopeService::class)->resolve($this->tenant->id, $membership->id);
        $this->assertSame([$this->north->id], $scope['branch_ids']);
        $this->assertContains($this->northSales->id, $scope['business_unit_ids']);

        $this->as($admin)->putJson("/api/v1/app/users/{$membership->id}/data-scopes", ['data_scopes' => []])->assertOk();
        $this->assertSame([], app(DataScopeService::class)->resolve($this->tenant->id, $membership->id)['branch_ids']);
    }

    public function test_a_user_cannot_hand_out_a_wider_scope_than_their_own(): void
    {
        [$manager, $managerMembership] = $this->member($this->tenant, ['access.scope.manage', 'access.scope.view', 'access.user.view']);
        $this->setScopes($managerMembership, [['scope_type' => DataScope::BRANCH, 'branch_id' => $this->north->id]]);

        [, $target] = $this->memberWith([]);
        $call = fn (array $scopes) => $this->as($this->tenantToken($manager, $this->tenant))->putJson("/api/v1/app/users/{$target->id}/data-scopes", ['data_scopes' => $scopes]);

        $call([['scope_type' => 'TENANT']])->assertForbidden()->assertJsonPath('code', 'PRIVILEGE_ESCALATION');
        $call([['scope_type' => 'BRANCH', 'branch_id' => $this->south->id]])->assertForbidden()->assertJsonPath('code', 'PRIVILEGE_ESCALATION');
        $call([['scope_type' => 'BUSINESS_UNIT', 'business_unit_id' => $this->southSales->id]])->assertForbidden();
        $call([['scope_type' => 'BRANCH', 'branch_id' => $this->north->id]])->assertOk();
        $call([['scope_type' => 'BUSINESS_UNIT', 'business_unit_id' => $this->northOps->id]])->assertOk(); // inside the manager's branch
        $call([['scope_type' => 'OWN']])->assertOk();
    }

    public function test_invalid_scope_input_is_rejected(): void
    {
        $admin = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);
        [, $membership] = $this->memberWith([]);
        $put = fn (array $scopes) => $this->as($admin)->putJson("/api/v1/app/users/{$membership->id}/data-scopes", ['data_scopes' => $scopes]);

        $put([['scope_type' => 'EVERYTHING']])->assertStatus(422);
        $put([['scope_type' => 'BRANCH']])->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_BRANCH');
        $put([['scope_type' => 'BUSINESS_UNIT', 'business_unit_id' => 'not-a-uuid']])->assertStatus(422);
        $put([['scope_type' => 'BUSINESS_UNIT', 'business_unit_id' => '01a11ae8-43cf-7125-a892-1623b11cf6a8']])->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_BUSINESS_UNIT');
    }
}
