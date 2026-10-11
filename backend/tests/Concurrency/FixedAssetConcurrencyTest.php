<?php

namespace Tests\Concurrency;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\RaceHelpers;
use Tests\Support\RaceRunner;

/**
 * OA4 release gate: the fixed asset invariants under genuinely concurrent requests. Workers are separate PHP processes hitting the real
 * application, so the database sees real parallel transactions. After every race the register and the books are checked as a whole:
 * journals balance, an asset's accumulated depreciation equals the schedule months posted, no month is held by two runs, nothing is
 * capitalized, depreciated, disposed or reversed twice, and the register still reconciles to the ledger.
 */
class FixedAssetConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures, RaceHelpers;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->assetTenant();
        [$user] = $this->member($this->tenant);
        $this->token = $this->tenantToken($user, $this->tenant);
        $this->as($this->token);
    }

    private function http()
    {
        return $this->as($this->token);
    }

    private function assetOn(array $category, array $override = []): array
    {
        $this->http();

        return $this->capitalizedAsset($this->tenant, $category, $override);
    }

    private function category(): array
    {
        $this->http();

        return $this->newCategory();
    }

    /** The whole register is still sound after a race. */
    private function assertBooksSound(): void
    {
        $tenant = $this->tenant->id;
        $unbalanced = DB::table('journal_entries as j')->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')->where('j.tenant_id', $tenant)->where('j.status', 'POSTED')
            ->groupBy('j.id')->havingRaw('sum(l.debit) <> sum(l.credit)')->select('j.id')->get()->count();
        $this->assertSame(0, $unbalanced, 'every posted journal balances');

        // An asset's accumulated depreciation is exactly the schedule months posted for it.
        $drift = DB::selectOne("select count(*) as n from fixed_assets a where a.tenant_id = ? and a.asset_number is not null
            and a.accumulated_depreciation <> coalesce((select sum(s.amount) from asset_depreciation_schedules s where s.fixed_asset_id = a.id and s.status = 'POSTED'), 0)", [$tenant])->n;
        $this->assertSame(0, (int) $drift, 'accumulated depreciation equals the posted schedule months');

        // A month is POSTED only through a posted run, IN_RUN only through a draft run, and PLANNED months belong to no line.
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from asset_depreciation_schedules s left join asset_depreciation_run_lines l on l.id = s.run_line_id left join asset_depreciation_runs r on r.id = l.run_id
            where s.tenant_id = ? and ((s.status = 'POSTED' and coalesce(r.status, '') <> 'POSTED') or (s.status = 'IN_RUN' and coalesce(r.status, '') <> 'DRAFT') or (s.status in ('PLANNED','CANCELLED') and s.run_line_id is not null))", [$tenant])->n, 'schedule state follows the run state');
        $this->assertSame(0, DB::table('asset_depreciation_run_lines')->where('tenant_id', $tenant)->groupBy('fixed_asset_id', 'run_id')->havingRaw('count(*) > 1')->select('run_id')->get()->count());

        // One journal per document, and one live disposal per asset.
        foreach (['fixed_assets' => 'capitalization_journal_id', 'asset_depreciation_runs' => 'journal_entry_id', 'asset_disposals' => 'journal_entry_id'] as $table => $column) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $tenant)->whereNotNull($column)->groupBy($column)->havingRaw('count(*) > 1')->select($column)->get()->count(), "{$table}: one journal per document");
        }
        $this->assertSame(0, DB::table('asset_disposals')->where('tenant_id', $tenant)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED'])->groupBy('fixed_asset_id')->havingRaw('count(*) > 1')->select('fixed_asset_id')->get()->count());

        // A disposed asset has no month held by a draft run and none posted after the disposal date.
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from fixed_assets a join asset_depreciation_schedules s on s.fixed_asset_id = a.id where a.tenant_id = ? and a.status = 'DISPOSED'
            and (s.status = 'IN_RUN' or (s.status = 'POSTED' and s.period_start > a.disposed_on))", [$tenant])->n, 'a disposed asset is not depreciated further');

        $report = $this->http()->getJson(self::FA.'/asset-reconciliation?as_of=2026-12-31')->assertOk()->json();
        $this->assertTrue($report['reconciled'], 'the register reconciles to the ledger: '.json_encode($report['totals']));
    }

    /** @return list<string> */
    private function numbers(string $table): array
    {
        $column = $table === 'fixed_assets' ? 'asset_number' : 'document_number';

        return DB::table($table)->where('tenant_id', $this->tenant->id)->whereNotNull($column)->orderBy($column)->pluck($column)->all();
    }

    // ------------------------------------------------------------------------------------------ capitalization

    public function test_the_same_draft_asset_capitalized_by_several_requests_at_once_is_capitalized_exactly_once(): void
    {
        $this->http();
        $asset = $this->newAsset($this->tenant, $this->newCategory());

        $results = (new RaceRunner)->start(array_fill(0, 6, $this->job('POST', self::FA."/assets/{$asset['id']}/capitalize")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(5, $outcomes['409:ASSET_ALREADY_CAPITALIZED'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('accounting_events')->where('source_type', 'fixed_asset')->where('source_id', $asset['id'])->count());
        $this->assertSame(12, DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->count(), 'one schedule, not six');
        $this->assertSame('12000000.0000', $this->glBalance($this->tenant, '1230'));
        $this->assertSame(['FA-FY2026-000001'], $this->numbers('fixed_assets'));
        $this->assertBooksSound();
    }

    public function test_assets_capitalized_in_parallel_get_unique_gapless_numbers(): void
    {
        $this->http();
        $category = $this->newCategory();
        $ids = array_map(fn ($n) => $this->newAsset($this->tenant, $category, ['name' => "Mesin {$n}", 'acquisition_cost' => '1200000'])['id'], range(1, 8));

        $results = (new RaceRunner)->start(array_map(fn ($id) => $this->job('POST', self::FA."/assets/{$id}/capitalize"), $ids), delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 8], $this->outcomes($results));
        $numbers = $this->numbers('fixed_assets');
        $this->assertCount(8, array_unique($numbers));
        $first = (int) substr($numbers[0], -6);
        $this->assertSame(range($first, $first + 7), array_map(fn ($n) => (int) substr($n, -6), $numbers), 'no gap and no duplicate');
        $this->assertSame('9600000.0000', $this->glBalance($this->tenant, '1230'));
        $this->assertBooksSound();
    }

    // ------------------------------------------------------------------------------------------ depreciation runs

    public function test_runs_calculated_at_once_for_the_same_period_hold_every_month_exactly_once(): void
    {
        $category = $this->category();
        foreach (range(1, 4) as $n) {
            $this->assetOn($category, ['name' => "Mesin {$n}"]);
        }
        $period = $this->period($this->tenant, '2026-04')->id;

        $results = (new RaceRunner)->start(array_fill(0, 5, $this->job('POST', self::FA.'/depreciation-runs', ['accounting_period_id' => $period])), delay: 3.0)->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['201:'] ?? 0, json_encode($outcomes));
        $this->assertSame(4, array_sum(array_filter($outcomes, fn ($k) => str_starts_with($k, '422:DEPRECIATION_NOTHING_ELIGIBLE') || str_starts_with($k, '409:DEPRECIATION_ROWS_TAKEN'), ARRAY_FILTER_USE_KEY)), json_encode($outcomes));
        $this->assertSame(1, DB::table('asset_depreciation_runs')->where('status', 'DRAFT')->count());
        $this->assertSame(8, DB::table('asset_depreciation_schedules')->where('status', 'IN_RUN')->count(), 'four assets x two months, each held once');
        $this->assertSame(4, DB::table('asset_depreciation_run_lines')->count());
        $this->assertBooksSound();
    }

    public function test_the_same_draft_run_posted_by_several_requests_at_once_depreciates_once(): void
    {
        $category = $this->category();
        $asset = $this->assetOn($category);
        $this->http();
        $run = $this->newRun($this->tenant, '2026-03');

        $results = (new RaceRunner)->start(array_fill(0, 6, $this->job('POST', self::FA."/depreciation-runs/{$run['id']}/post")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('accounting_events')->where('source_type', 'depreciation_run')->where('source_id', $run['id'])->count());
        $this->assertSame('1000000.0000', DB::table('fixed_assets')->where('id', $asset['id'])->value('accumulated_depreciation'));
        $this->assertSame('1000000.0000', $this->glBalance($this->tenant, '6600'));
        $this->assertSame(['DEP-FY2026-000001'], $this->numbers('asset_depreciation_runs'));
        $this->assertBooksSound();
    }

    public function test_a_posted_run_reversed_by_several_requests_is_reversed_once(): void
    {
        $category = $this->category();
        $asset = $this->assetOn($category);
        $this->http();
        $run = $this->postedRun($this->tenant, '2026-03');

        $results = (new RaceRunner)->start(array_map(fn ($n) => $this->job('POST', self::FA."/depreciation-runs/{$run['id']}/reverse", ['reason' => "Koreksi {$n}"]), range(1, 5)))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('journal_entries')->where('reverses_journal_id', DB::table('asset_depreciation_runs')->where('id', $run['id'])->value('journal_entry_id'))->count());
        $this->assertSame('0.0000', DB::table('fixed_assets')->where('id', $asset['id'])->value('accumulated_depreciation'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '6600'));
        $this->assertSame(12, DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->where('status', 'PLANNED')->count());
        $this->assertBooksSound();
    }

    public function test_a_run_and_the_reversal_of_the_capitalization_cannot_both_win(): void
    {
        $category = $this->category();
        $asset = $this->assetOn($category);

        $results = (new RaceRunner)->start([
            $this->job('POST', self::FA.'/depreciation-runs', ['accounting_period_id' => $this->period($this->tenant, '2026-03')->id]),
            $this->job('POST', self::FA."/assets/{$asset['id']}/reverse-capitalization", ['reason' => 'Salah akun']),
        ], delay: 3.0)->results();

        $this->assertNoServerErrors($results);
        $row = DB::table('fixed_assets')->where('id', $asset['id'])->first();
        $held = DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->whereIn('status', ['IN_RUN', 'POSTED'])->count();
        $this->assertTrue(($row->status === 'INACTIVE' && $held === 0) || ($row->status === 'ACTIVE' && $held === 1), "reversed XOR depreciated: {$row->status} / {$held} / ".json_encode($this->outcomes($results)));
        $this->assertBooksSound();
    }

    // ------------------------------------------------------------------------------------------ disposals

    public function test_one_disposal_per_asset_even_when_several_are_created_at_once(): void
    {
        $category = $this->category();
        $asset = $this->assetOn($category);
        $body = ['fixed_asset_id' => $asset['id'], 'disposal_type' => 'SCRAP', 'disposal_date' => '2026-03-31', 'reason' => 'Rusak'];

        $results = (new RaceRunner)->start(array_fill(0, 5, $this->job('POST', self::FA.'/asset-disposals', $body)))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['201:'] ?? 0, json_encode($outcomes));
        $this->assertSame(4, $outcomes['409:ASSET_DISPOSAL_EXISTS'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('asset_disposals')->where('fixed_asset_id', $asset['id'])->count());
        $this->assertBooksSound();
    }

    public function test_the_same_approved_disposal_posted_by_several_requests_disposes_once(): void
    {
        $category = $this->category();
        $asset = $this->assetOn($category);
        $this->http();
        $this->postedRun($this->tenant, '2026-06');
        $disposal = $this->postJson(self::FA.'/asset-disposals', [
            'fixed_asset_id' => $asset['id'], 'disposal_type' => 'SALE', 'disposal_date' => '2026-06-30', 'proceeds_amount' => '9000000',
            'proceeds_account_id' => $this->account($this->tenant, '1120')->id, 'reason' => 'Dijual',
        ])->assertCreated()->json();
        $this->postJson(self::FA."/asset-disposals/{$disposal['id']}/submit")->assertOk();
        $this->postJson(self::FA."/asset-disposals/{$disposal['id']}/approve")->assertOk();

        $results = (new RaceRunner)->start(array_fill(0, 6, $this->job('POST', self::FA."/asset-disposals/{$disposal['id']}/post")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'asset_disposal')->where('source_id', $disposal['id'])->count());
        $this->assertSame('DISPOSED', DB::table('fixed_assets')->where('id', $asset['id'])->value('status'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1230'));
        $this->assertSame('9000000.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertSame('-1000000.0000', $this->glBalance($this->tenant, '4250'));
        $this->assertBooksSound();
    }

    public function test_a_disposal_and_a_depreciation_run_never_both_take_the_same_asset(): void
    {
        $category = $this->category();
        $asset = $this->assetOn($category);
        $this->http();
        $this->postedRun($this->tenant, '2026-06');
        $disposal = $this->postJson(self::FA.'/asset-disposals', [
            'fixed_asset_id' => $asset['id'], 'disposal_type' => 'SCRAP', 'disposal_date' => '2026-06-30', 'reason' => 'Rusak',
        ])->assertCreated()->json();
        $this->postJson(self::FA."/asset-disposals/{$disposal['id']}/submit")->assertOk();
        $this->postJson(self::FA."/asset-disposals/{$disposal['id']}/approve")->assertOk();

        $results = (new RaceRunner)->start([
            $this->job('POST', self::FA."/asset-disposals/{$disposal['id']}/post"),
            $this->job('POST', self::FA.'/depreciation-runs', ['accounting_period_id' => $this->period($this->tenant, '2026-07')->id]),
        ], delay: 3.0)->results();

        $this->assertNoServerErrors($results);
        $status = DB::table('asset_disposals')->where('id', $disposal['id'])->value('status');
        $held = DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->where('status', 'IN_RUN')->count();
        $this->assertTrue(($status === 'POSTED' && $held === 0) || ($status === 'APPROVED' && $held === 1), "disposed XOR held by a run: {$status} / {$held} / ".json_encode($this->outcomes($results)));
        $this->assertBooksSound();
    }

    public function test_a_disposal_reversed_by_several_requests_is_reversed_once(): void
    {
        $category = $this->category();
        $asset = $this->assetOn($category);
        $this->http();
        $this->postedRun($this->tenant, '2026-06');
        $posted = $this->postedDisposal($this->tenant, $asset);

        $results = (new RaceRunner)->start(array_map(fn ($n) => $this->job('POST', self::FA."/asset-disposals/{$posted['id']}/reverse", ['reason' => "Batal {$n}"]), range(1, 5)))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame('ACTIVE', DB::table('fixed_assets')->where('id', $asset['id'])->value('status'));
        $this->assertSame(1, DB::table('journal_entries')->where('reverses_journal_id', DB::table('asset_disposals')->where('id', $posted['id'])->value('journal_entry_id'))->count());
        $this->assertSame('12000000.0000', $this->glBalance($this->tenant, '1230'));
        $this->assertBooksSound();
    }
}
