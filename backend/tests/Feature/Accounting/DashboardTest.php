<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
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
}
