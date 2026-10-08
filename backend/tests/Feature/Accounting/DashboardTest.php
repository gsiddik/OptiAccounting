<?php

namespace Tests\Feature\Accounting;

use App\Domain\Organization\Services\OrganizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA1: the minimal accounting home. */
class DashboardTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    private const URL = '/api/v1/app/accounting/dashboard';

    public function test_it_shows_the_current_period_pending_journals_and_recent_postings_of_this_tenant_only(): void
    {
        $year = now()->year;
        $tenant = $this->accountingTenant('alpha', "{$year}-01-01");
        $other = $this->accountingTenant('beta', "{$year}-01-01");
        $today = now()->toDateString();
        $this->postedJournal($other, ['document_date' => $today, 'posting_date' => $today]);
        $this->postedJournal($tenant, ['document_date' => $today, 'posting_date' => $today]);
        $client = $this->signedIn($tenant);
        $this->draft($tenant, ['document_date' => $today, 'posting_date' => $today]);
        $submitted = $this->draft($tenant, ['document_date' => $today, 'posting_date' => $today])['id'];
        $client->postJson("/api/v1/app/accounting/journals/{$submitted}/submit")->assertOk();

        DB::table('tenants')->where('id', $tenant->id)->update(['timezone' => 'UTC']);
        $body = $this->getJson(self::URL)->assertOk()->json();

        $this->assertSame("FY{$year}", $body['fiscal_year']['code']);
        $this->assertSame(now('UTC')->format('Y-m'), $body['period']['code']);
        $this->assertSame('OPEN', $body['period']['status']);
        $this->assertSame(['draft' => 1, 'pending_approval' => 1, 'awaiting_posting' => 0], $body['journals']);
        $this->assertCount(1, $body['recent_posted']);
        $this->assertSame("SJ-FY{$year}-000001", $body['recent_posted'][0]['journal_number']);

        $this->signedIn($tenant, ['accounting.profile.view'])->getJson(self::URL)->assertStatus(403);
    }

    public function test_it_copes_with_a_date_outside_every_fiscal_year(): void
    {
        $tenant = $this->accountingTenant('alpha', (now()->year - 2).'-01-01'); // today lies outside every fiscal year
        $this->signedIn($tenant);

        $body = $this->getJson(self::URL)->assertOk()->json();
        $this->assertNull($body['period']);
        $this->assertNull($body['fiscal_year']);
        $this->assertSame(['draft' => 0, 'pending_approval' => 0, 'awaiting_posting' => 0], $body['journals']);
    }

    public function test_the_dimension_catalog_offers_only_active_dimensions_inside_the_users_data_scope(): void
    {
        $org = app(OrganizationService::class);
        $tenant = $this->accountingTenant('alpha');
        $other = $this->accountingTenant('beta');
        [$north, $south, $unit] = $this->inTenant($tenant, function () use ($org, $tenant) {
            $north = $org->createBranch($tenant->id, ['code' => 'N', 'name' => 'North']);
            $south = $org->createBranch($tenant->id, ['code' => 'S', 'name' => 'South']);

            return [$north, $south, $org->createBusinessUnit($tenant->id, ['code' => 'U-S', 'name' => 'South unit'], $south->id)];
        });
        $this->inTenant($other, fn () => $org->createBranch($other->id, ['code' => 'X', 'name' => 'Foreign']));

        $admin = $this->signedIn($tenant);
        $this->postJson('/api/v1/app/accounting/cost-centers', ['code' => 'CC1', 'name' => 'Marketing'])->assertCreated();
        $body = $this->getJson('/api/v1/app/accounting/dimensions')->assertOk()->json();
        $this->assertSame(['N', 'S'], array_column($body['branches'], 'code'));
        $this->assertSame(['U-S'], array_column($body['business_units'], 'code'));
        $this->assertSame(['CC1'], array_column($body['cost_centers'], 'code'));

        [$user, $membership] = $this->member($tenant, ['accounting.dimension.view']);
        DB::table('data_scopes')->where('tenant_user_id', $membership->id)->delete();
        DB::table('data_scopes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'tenant_user_id' => $membership->id, 'scope_type' => 'BRANCH', 'branch_id' => $north->id, 'created_at' => now(), 'updated_at' => now()]);
        $narrow = $this->as($this->tenantToken($user, $tenant))->getJson('/api/v1/app/accounting/dimensions')->assertOk()->json();
        $this->assertSame(['N'], array_column($narrow['branches'], 'code'));
        $this->assertSame([], $narrow['business_units']);

        $this->signedIn($tenant, ['accounting.journal.view'])->getJson('/api/v1/app/accounting/dimensions')->assertStatus(403);
        unset($admin, $unit);
    }
}
