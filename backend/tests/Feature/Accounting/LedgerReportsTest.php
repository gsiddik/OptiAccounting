<?php

namespace Tests\Feature\Accounting;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA1 batch J: general ledger and trial balance derive only from POSTED journal lines. */
class LedgerReportsTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    private const GL = '/api/v1/app/accounting/general-ledger';

    private const TB = '/api/v1/app/accounting/trial-balance';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->accountingTenant();
    }

    /**
     * Opening 1,000,000 (cash / paid-in capital) on 2026-01-01, a 100,000 cash sale on 2026-01-15 and 40,000 salary paid
     * in cash on 2026-02-10. Cash: 1,000,000 -> 1,100,000 -> 1,060,000.
     */
    private function ledger(): void
    {
        $client = $this->signedIn($this->tenant);
        $client->putJson('/api/v1/app/accounting/opening-balance', ['cutover_date' => '2026-01-01', 'lines' => [
            ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '1000000'], ['account_id' => $this->account($this->tenant, '3100')->id, 'credit' => '1000000'],
        ]])->assertOk();
        $client->postJson('/api/v1/app/accounting/opening-balance/post')->assertOk();
        $this->postedJournal($this->tenant, ['document_date' => '2026-01-15', 'posting_date' => '2026-01-15', 'lines' => $this->lines($this->tenant, '100000', '1110', '4100')]);
        $this->postedJournal($this->tenant, ['document_date' => '2026-02-10', 'posting_date' => '2026-02-10', 'lines' => $this->lines($this->tenant, '40000', '6100', '1110')]);
    }

    private function gl(array $query = [], ?string $path = null)
    {
        return $this->getJson(($path ?? self::GL).'?'.http_build_query($query));
    }

    // ------------------------------------------------------------------------------------------ general ledger

    public function test_the_ledger_has_opening_movement_and_closing_in_the_normal_balance_direction(): void
    {
        $this->ledger();
        $cash = $this->account($this->tenant, '1110')->id;
        $client = $this->signedIn($this->tenant);

        $jan = $this->gl(['account_id' => $cash, 'from' => '2026-01-01', 'to' => '2026-01-31'])->assertOk()->json();
        $this->assertSame(['normal_balance' => 'DEBIT', 'opening' => '1000000.0000', 'debit' => '100000.0000', 'credit' => '0.0000', 'closing' => '1100000.0000'], $jan['summary']);
        $this->assertCount(1, $jan['data'], 'the opening journal is the opening column, never a movement line');
        $this->assertSame('1100000.0000', $jan['data'][0]['running_balance']);
        $this->assertSame('SYSTEM', $jan['data'][0]['journal_type']);
        $this->assertTrue($jan['complete']);

        $feb = $this->gl(['account_id' => $cash, 'from' => '2026-02-01', 'to' => '2026-02-28'])->assertOk()->json();
        $this->assertSame(['normal_balance' => 'DEBIT', 'opening' => '1100000.0000', 'debit' => '0.0000', 'credit' => '40000.0000', 'closing' => '1060000.0000'], $feb['summary']);
        $this->assertSame('1060000.0000', $feb['data'][0]['running_balance']);

        // A credit-normal account reads positive on its own side.
        $sales = $this->gl(['account_id' => $this->account($this->tenant, '4100')->id, 'from' => '2026-01-01', 'to' => '2026-12-31'])->assertOk()->json();
        $this->assertSame('100000.0000', $sales['summary']['closing']);
        $this->assertSame('CREDIT', $sales['summary']['normal_balance']);
        $this->assertSame('100000.0000', $sales['data'][0]['running_balance']);

        // Before the cutover nothing exists yet.
        $before = $this->gl(['account_id' => $cash, 'from' => '2025-12-01', 'to' => '2025-12-31'])->assertOk()->json();
        $this->assertSame('0.0000', $before['summary']['closing']);
        $this->assertSame([], $before['data']);
        unset($client);
    }

    public function test_only_posted_journals_reach_the_ledger(): void
    {
        $this->ledger();
        $cash = $this->account($this->tenant, '1110')->id;
        $client = $this->signedIn($this->tenant);
        $baseline = $this->gl(['account_id' => $cash, 'from' => '2026-01-01', 'to' => '2026-12-31'])->json('summary');
        $tbBaseline = $this->gl([], self::TB)->json('totals');

        $draft = $this->draft($this->tenant, ['lines' => $this->lines($this->tenant, '777')])['id'];
        $submitted = $this->draft($this->tenant, ['lines' => $this->lines($this->tenant, '888')])['id'];
        $approved = $this->draft($this->tenant, ['lines' => $this->lines($this->tenant, '999')])['id'];
        $client->postJson("/api/v1/app/accounting/journals/{$submitted}/submit")->assertOk();
        $client->postJson("/api/v1/app/accounting/journals/{$approved}/submit")->assertOk();
        $approver = $this->signedIn($this->tenant);
        $approver->postJson("/api/v1/app/accounting/journals/{$approved}/approve")->assertOk();
        $cancelled = $this->draft($this->tenant, ['lines' => $this->lines($this->tenant, '555')])['id'];
        $approver->postJson("/api/v1/app/accounting/journals/{$cancelled}/cancel", ['reason' => 'x'])->assertOk();
        $statuses = DB::table('journal_entries')->whereIn('id', [$draft, $submitted, $approved, $cancelled])->pluck('status', 'id')->all();
        $this->assertSame(['DRAFT', 'SUBMITTED', 'APPROVED', 'CANCELLED'], [$statuses[$draft], $statuses[$submitted], $statuses[$approved], $statuses[$cancelled]]);

        $this->assertSame($baseline, $this->gl(['account_id' => $cash, 'from' => '2026-01-01', 'to' => '2026-12-31'])->json('summary'));
        $this->assertSame($tbBaseline, $this->gl([], self::TB)->json('totals'));
        $this->assertCount(2, $this->gl(['account_id' => $cash, 'from' => '2026-01-01', 'to' => '2026-12-31'])->json('data'));

        // Posting the approved one moves the figures by exactly its amount.
        $approver->postJson("/api/v1/app/accounting/journals/{$approved}/post")->assertOk();
        $after = $this->gl(['account_id' => $cash, 'from' => '2026-01-01', 'to' => '2026-12-31'])->json('summary');
        $this->assertSame('1060999.0000', $after['closing']);
    }

    public function test_the_ledger_equals_the_posted_journal_lines(): void
    {
        $this->ledger();
        $this->postedJournal($this->tenant, ['document_date' => '2026-03-05', 'posting_date' => '2026-03-05', 'lines' => $this->lines($this->tenant, '12345.67', '6200', '1110')]);
        $this->signedIn($this->tenant);

        $report = $this->gl(['from' => '2026-01-01', 'to' => '2026-12-31', 'per_page' => 200])->assertOk()->json();
        $expected = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.status', 'POSTED')->where('j.journal_type', '<>', 'OPENING')
            ->whereBetween('j.posting_date', ['2026-01-01', '2026-12-31'])->selectRaw('sum(l.debit) d, sum(l.credit) c')->first();
        $this->assertSame(number_format((float) $expected->d, 4, '.', ''), $report['summary']['debit']);
        $this->assertSame(number_format((float) $expected->c, 4, '.', ''), $report['summary']['credit']);
        $this->assertSame(DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.status', 'POSTED')->where('j.journal_type', '<>', 'OPENING')->count(), $report['meta']['total']);
    }

    public function test_running_balance_survives_paging_and_a_text_search_drops_it(): void
    {
        $this->ledger();
        foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $i => $date) {
            $this->postedJournal($this->tenant, ['document_date' => $date, 'posting_date' => $date, 'reference' => "REF-{$i}", 'lines' => $this->lines($this->tenant, (string) (1000 * ($i + 1)), '1110', '4100')]);
        }
        $this->signedIn($this->tenant);
        $cash = $this->account($this->tenant, '1110')->id;
        $query = ['account_id' => $cash, 'from' => '2026-01-01', 'to' => '2026-12-31', 'per_page' => 2];

        $page1 = $this->gl($query + ['page' => 1])->assertOk()->json();
        $page2 = $this->gl($query + ['page' => 2])->assertOk()->json();
        $this->assertSame(5, $page1['meta']['total']);
        $this->assertSame(3, $page1['meta']['last_page']);
        $all = array_merge($page1['data'], $page2['data'], $this->gl($query + ['page' => 3])->json('data'));
        $this->assertSame(['1100000.0000', '1060000.0000', '1061000.0000', '1063000.0000', '1066000.0000'], array_column($all, 'running_balance'));
        $this->assertSame('1066000.0000', $page1['summary']['closing']);

        $found = $this->gl($query + ['q' => 'ref-1'])->assertOk()->json();
        $this->assertCount(1, $found['data']);
        $this->assertNull($found['data'][0]['running_balance']);
        $this->gl(['from' => '2026-02-01', 'to' => '2026-01-01'])->assertStatus(422)->assertJsonPath('code', 'RANGE_INVALID');
        $this->gl(['account_id' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_a_reversal_cancels_the_original_in_the_ledger(): void
    {
        $this->ledger();
        $journal = $this->postedJournal($this->tenant, ['document_date' => '2026-03-05', 'posting_date' => '2026-03-05', 'lines' => $this->lines($this->tenant, '5000', '1110', '4100')]);
        $client = $this->signedIn($this->tenant);
        $cash = $this->account($this->tenant, '1110')->id;
        $before = $this->gl(['account_id' => $cash, 'from' => '2026-03-01', 'to' => '2026-03-31'])->json('summary');
        $this->assertSame('1065000.0000', $before['closing']);

        $client->postJson("/api/v1/app/accounting/journals/{$journal->id}/reverse", ['reason' => 'x', 'posting_date' => '2026-03-20'])->assertCreated();
        $after = $this->gl(['account_id' => $cash, 'from' => '2026-03-01', 'to' => '2026-03-31'])->json();
        $this->assertSame('1060000.0000', $after['summary']['closing']);
        $this->assertSame(['RV-FY2026-000001', 'SJ-FY2026-000003'], collect($after['data'])->pluck('journal_number')->sort()->values()->all());
        $this->assertTrue($this->gl([], self::TB)->json('reconciliation.reconciled'));
    }

    // ------------------------------------------------------------------------------------------ trial balance

    public function test_the_trial_balance_reconciles_and_ending_equals_opening_plus_movement(): void
    {
        $this->ledger();
        $this->signedIn($this->tenant);

        $tb = $this->gl(['from' => '2026-02-01', 'to' => '2026-02-28'], self::TB)->assertOk()->json();
        $rows = collect($tb['data'])->keyBy('code');
        $this->assertSame(['1110', '3100', '4100', '6100'], $rows->pluck('code')->sort()->values()->all());
        $this->assertSame('1100000.0000', $rows['1110']['opening_debit']);
        $this->assertSame('40000.0000', $rows['1110']['credit']);
        $this->assertSame('1060000.0000', $rows['1110']['ending_debit']);
        $this->assertSame('1000000.0000', $rows['3100']['ending_credit']);
        $this->assertSame('100000.0000', $rows['4100']['opening_credit']); // January's sale is this month's opening
        $this->assertSame('40000.0000', $rows['6100']['ending_debit']);

        $this->assertTrue($tb['reconciliation']['reconciled']);
        $this->assertTrue($tb['complete']);
        $this->assertSame('1100000.0000', $tb['reconciliation']['opening']['debit']);
        $this->assertSame('1100000.0000', $tb['reconciliation']['opening']['credit']);
        $this->assertSame('40000.0000', $tb['reconciliation']['movement']['debit']);
        $this->assertSame('1100000.0000', $tb['reconciliation']['ending']['debit']);
        $this->assertSame('0.0000', $tb['reconciliation']['ending']['difference']);

        foreach ($tb['data'] as $row) { // ending = opening + movement for every account, in net terms
            $net = fn (string $d, string $c) => BigDecimal::of($row[$d])->minus($row[$c]);
            $this->assertTrue($net('opening_debit', 'opening_credit')->plus($net('debit', 'credit'))->isEqualTo($net('ending_debit', 'ending_credit')), "account {$row['code']}");
        }
    }

    public function test_the_cutover_day_counts_the_opening_balance_as_opening_and_a_hierarchy_rolls_up(): void
    {
        $this->ledger();
        $this->signedIn($this->tenant);

        $flat = $this->gl(['from' => '2026-01-01', 'to' => '2026-01-31'], self::TB)->assertOk()->json();
        $cash = collect($flat['data'])->firstWhere('code', '1110');
        $this->assertSame('1000000.0000', $cash['opening_debit']);
        $this->assertSame('100000.0000', $cash['debit']);
        $this->assertSame([], array_filter($flat['data'], fn ($r) => $r['is_header']));

        $tree = $this->gl(['from' => '2026-01-01', 'to' => '2026-01-31', 'hierarchy' => 1], self::TB)->assertOk()->json();
        $rows = collect($tree['data'])->keyBy('code');
        $this->assertTrue($rows['1100']['is_header']);
        $this->assertSame('1100000.0000', $rows['1100']['ending_debit']); // current assets header = its children
        $this->assertSame('1000000.0000', $rows['1000']['opening_debit']); // assets root rolls up
        $this->assertGreaterThan($rows['1000']['depth'], $rows['1110']['depth']);
        $this->assertSame($flat['totals'], $tree['totals'], 'headers never add to the totals');
        $this->assertGreaterThan(count($flat['data']), count($tree['data']));

        $zero = $this->gl(['from' => '2026-01-01', 'to' => '2026-01-31', 'include_zero' => 1], self::TB)->json('data');
        $this->assertGreaterThan(count($flat['data']), count($zero));
    }

    public function test_an_empty_ledger_reconciles_trivially(): void
    {
        $this->signedIn($this->tenant);
        $tb = $this->gl([], self::TB)->assertOk()->json();
        $this->assertSame([], $tb['data']);
        $this->assertTrue($tb['reconciliation']['reconciled']);
        $this->assertSame('0.0000', $tb['totals']['ending_debit']);
    }

    // ------------------------------------------------------------------------------------------ scope, tenancy, permissions

    public function test_data_scope_and_dimension_filters_narrow_the_ledger_and_the_report_says_it_is_partial(): void
    {
        $org = app(OrganizationService::class);
        [$north, $south] = $this->inTenant($this->tenant, fn () => [
            $org->createBranch($this->tenant->id, ['code' => 'N', 'name' => 'North']), $org->createBranch($this->tenant->id, ['code' => 'S', 'name' => 'South']),
        ]);
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '300', '1110', '4100', ['branch_id' => $north->id])]);
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '700', '1110', '4100', ['branch_id' => $south->id])]);
        $cash = $this->account($this->tenant, '1110')->id;

        $admin = $this->signedIn($this->tenant);
        $this->assertSame('1000.0000', $this->gl(['account_id' => $cash, 'from' => '2026-03-01', 'to' => '2026-03-31'])->json('summary.debit'));
        $filtered = $this->gl(['branch_id' => $north->id, 'from' => '2026-03-01', 'to' => '2026-03-31'], self::TB)->assertOk()->json();
        $this->assertFalse($filtered['complete']);
        $this->assertNull($filtered['reconciliation']['reconciled']);
        $this->assertSame('300.0000', $filtered['totals']['debit']);

        [$user, $membership] = $this->member($this->tenant, ['accounting.gl.view', 'accounting.trial_balance.view', 'accounting.report.export']);
        DB::table('data_scopes')->where('tenant_user_id', $membership->id)->delete();
        DB::table('data_scopes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'tenant_user_id' => $membership->id, 'scope_type' => 'BRANCH', 'branch_id' => $north->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($this->tenantToken($user, $this->tenant));

        $scoped = $this->gl(['account_id' => $cash, 'from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk()->json();
        $this->assertSame('300.0000', $scoped['summary']['debit']);
        $this->assertCount(1, $scoped['data']);
        $this->assertFalse($scoped['complete']);
        $this->assertSame('300.0000', $this->gl(['from' => '2026-03-01', 'to' => '2026-03-31'], self::TB)->json('totals.debit'));
        $this->assertNull($this->gl([], self::TB)->json('reconciliation.reconciled'));

        // Asking for another branch explicitly yields nothing, not an error and not the other branch's figures.
        $this->assertSame([], $this->gl(['account_id' => $cash, 'branch_id' => $south->id, 'from' => '2026-03-01', 'to' => '2026-03-31'])->json('data'));
        $csv = $this->get(self::GL.'/export?from=2026-03-01&to=2026-03-31')->assertOk()->streamedContent();
        $this->assertStringContainsString('300.0000', $csv);
        $this->assertStringNotContainsString('700.0000', $csv);
        unset($admin);
    }

    public function test_reports_are_tenant_private_and_permission_gated(): void
    {
        $this->ledger();
        $beta = $this->accountingTenant('beta');
        $cashAlpha = $this->account($this->tenant, '1110')->id;

        $this->signedIn($beta);
        $this->gl(['account_id' => $cashAlpha])->assertNotFound();
        $this->assertSame([], $this->gl(['from' => '2026-01-01', 'to' => '2026-12-31'], self::TB)->json('data'));
        $this->assertSame([], $this->gl(['from' => '2026-01-01', 'to' => '2026-12-31'])->json('data'));

        $this->signedIn($this->tenant, ['accounting.gl.view']);
        $this->gl()->assertOk();
        $this->gl([], self::TB)->assertStatus(403);
        $this->gl([], self::GL.'/export')->assertStatus(403);
        $this->signedIn($this->tenant, ['accounting.trial_balance.view']);
        $this->gl([], self::TB)->assertOk();
        $this->gl()->assertStatus(403);
        $this->signedIn($this->tenant, ['accounting.journal.view']);
        $this->gl()->assertStatus(403);
        $this->gl([], self::TB.'/export')->assertStatus(403);
        $this->gl([], '/api/v1/app/accounting/accounts-export')->assertStatus(403);
    }

    // ------------------------------------------------------------------------------------------ export

    public function test_exports_are_csv_audited_and_safe_against_spreadsheet_formulas(): void
    {
        $this->ledger();
        $this->postedJournal($this->tenant, ['document_date' => '2026-03-05', 'posting_date' => '2026-03-05', 'description' => '=HYPERLINK("http://evil","x")', 'reference' => '@cmd',
            'lines' => [
                ['account_id' => $this->account($this->tenant, '6200')->id, 'debit' => '10', 'description' => '+SUM(A1)'], ['account_id' => $this->account($this->tenant, '1110')->id, 'credit' => '10', 'description' => '-2+3'],
            ]]);
        $this->signedIn($this->tenant, ['accounting.gl.view', 'accounting.trial_balance.view', 'accounting.report.export', 'accounting.coa.view']);

        $response = $this->get(self::GL.'/export?from=2026-03-01&to=2026-03-31')->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFTanggal,\"No. jurnal\"", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+SUM(A1)", $csv);
        $this->assertStringContainsString("'-2+3", $csv);
        $this->assertStringContainsString("'@cmd", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
        $this->assertMatchesRegularExpression('/,10\.0000,0\.0000,/', $csv); // amounts stay plain decimals

        $tb = $this->get(self::TB.'/export?from=2026-03-01&to=2026-03-31')->assertOk()->streamedContent();
        $this->assertStringContainsString('Total', $tb);
        $coa = $this->get('/api/v1/app/accounting/accounts-export')->assertOk()->streamedContent();
        $this->assertStringContainsString('1110,Kas', $coa);
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'accounting.report.exported')->count());
        $this->get(self::GL.'/export?from=bad')->assertStatus(422);
    }
}
