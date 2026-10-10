<?php

namespace Tests\Feature\FixedAsset;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA4 batch D: the depreciation run (calculate, post, reverse) and the schedule state it keeps. */
class DepreciationRunTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->assetTenant();
        $this->signedIn($this->tenant);
    }

    private function scheduleStatuses(string $assetId): array
    {
        return DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $assetId)->orderBy('sequence_no')->pluck('status')->all();
    }

    public function test_calculating_a_run_holds_the_months_and_posts_nothing_until_it_is_posted(): void
    {
        $category = $this->newCategory();
        $a = $this->capitalizedAsset($this->tenant, $category);
        $b = $this->capitalizedAsset($this->tenant, $category, ['name' => 'Mesin B', 'acquisition_cost' => '6000000']);
        $before = $this->glFigures($this->tenant);

        $run = $this->newRun($this->tenant, '2026-03');

        $this->assertSame(['DRAFT', null, 2, '1500000.0000', '2026-03-31'], [$run['status'], $run['document_number'], $run['asset_count'], $run['total_amount'], substr($run['posting_date'], 0, 10)]);
        $this->assertSame(['1000000.0000', '500000.0000'], collect($run['lines'])->pluck('amount')->sort()->reverse()->values()->all());
        $this->assertSame(['IN_RUN', 'PLANNED'], array_values(array_unique(array_slice($this->scheduleStatuses($a['id']), 0, 2))));
        $this->assertEquals($before, $this->glFigures($this->tenant), 'a calculated run is not an accounting entry');
        $this->assertSame('0.0000', $this->assetRow($a['id'])->accumulated_depreciation);
    }

    public function test_posting_creates_one_journal_through_the_engine_and_updates_the_register_atomically(): void
    {
        $a = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $run = $this->newRun($this->tenant, '2026-03');

        $posted = $this->postJson(self::FA."/depreciation-runs/{$run['id']}/post")->assertOk()->json();

        $this->assertSame(['POSTED', 'DEP-FY2026-000001'], [$posted['status'], $posted['document_number']]);
        $journalId = DB::table('asset_depreciation_runs')->where('id', $run['id'])->value('journal_entry_id');
        $this->assertSame([['6600', '1000000.0000', '0.0000'], ['1290', '0.0000', '1000000.0000']], $this->journalLines($journalId));
        $this->assertSame('SYSTEM', DB::table('journal_entries')->where('id', $journalId)->value('journal_type'));
        $this->assertSame('2026-03-31', DB::table('journal_entries')->where('id', $journalId)->value('posting_date'));
        $this->assertSame(1, DB::table('accounting_events')->where('source_type', 'depreciation_run')->where('source_id', $run['id'])->where('event_type', 'DEPRECIATION_RECOGNIZED')->count());

        $this->assertSame(['POSTED', 'PLANNED'], array_values(array_unique(array_slice($this->scheduleStatuses($a['id']), 0, 2))));
        $row = $this->assetRow($a['id']);
        $this->assertSame(['1000000.0000', 'ACTIVE'], [$row->accumulated_depreciation, $row->status]);
        $this->assertSame('1000000.0000', $this->glBalance($this->tenant, '6600'));
        $this->assertSame('-1000000.0000', $this->glBalance($this->tenant, '1290'));

        // Posting again changes nothing.
        $figures = $this->glFigures($this->tenant);
        $this->postJson(self::FA."/depreciation-runs/{$run['id']}/post")->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_ALREADY_POSTED');
        $this->assertEquals($figures, $this->glFigures($this->tenant));
    }

    public function test_a_month_can_never_be_depreciated_twice(): void
    {
        $this->capitalizedAsset($this->tenant, $this->newCategory());

        $first = $this->newRun($this->tenant, '2026-03');
        // While the first draft holds the month, a second run finds nothing to take.
        $this->postJson(self::FA.'/depreciation-runs', ['accounting_period_id' => $this->period($this->tenant, '2026-03')->id])
            ->assertStatus(422)->assertJsonPath('code', 'DEPRECIATION_NOTHING_ELIGIBLE');

        $this->postJson(self::FA."/depreciation-runs/{$first['id']}/post")->assertOk();
        $this->postJson(self::FA.'/depreciation-runs', ['accounting_period_id' => $this->period($this->tenant, '2026-03')->id])
            ->assertStatus(422)->assertJsonPath('code', 'DEPRECIATION_NOTHING_ELIGIBLE');
        $this->assertSame(1, DB::table('asset_depreciation_runs')->where('status', 'POSTED')->count());
    }

    public function test_cancelling_a_draft_releases_its_months_for_another_run(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $run = $this->newRun($this->tenant, '2026-03');
        $this->assertSame('IN_RUN', $this->scheduleStatuses($asset['id'])[0]);

        $this->postJson(self::FA."/depreciation-runs/{$run['id']}/cancel", ['reason' => 'Hitung ulang'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertSame('PLANNED', $this->scheduleStatuses($asset['id'])[0]);
        $this->assertNull(DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->where('sequence_no', 1)->value('run_line_id'));
        $this->postJson(self::FA."/depreciation-runs/{$run['id']}/post")->assertStatus(409);

        $again = $this->newRun($this->tenant, '2026-03');
        $this->assertSame('1000000.0000', $again['total_amount']);
        $this->postJson(self::FA."/depreciation-runs/{$again['id']}/cancel", ['reason' => 'x'])->assertOk();
        $posted = $this->postedRun($this->tenant, '2026-03');
        $this->postJson(self::FA."/depreciation-runs/{$posted['id']}/cancel", ['reason' => 'Terlambat'])->assertStatus(409)->assertJsonPath('code', 'DEPRECIATION_RUN_NOT_DRAFT');
    }

    public function test_a_missed_month_is_caught_up_by_the_next_run(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());

        $run = $this->newRun($this->tenant, '2026-05');

        $this->assertSame([3, '3000000.0000'], [$run['lines'][0]['schedule_rows'], $run['total_amount']]);
        $this->postJson(self::FA."/depreciation-runs/{$run['id']}/post")->assertOk();
        $this->assertSame(['POSTED', 'POSTED', 'POSTED', 'PLANNED'], array_slice($this->scheduleStatuses($asset['id']), 0, 4));
        $this->assertSame('3000000.0000', $this->assetRow($asset['id'])->accumulated_depreciation);
        $this->assertSame('2026-05-31', substr($run['posting_date'], 0, 10));
    }

    public function test_an_asset_that_is_fully_depreciated_leaves_the_runs_but_stays_on_the_books(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory(), ['useful_life_months' => 3, 'residual_value' => '1000000']);

        $this->postedRun($this->tenant, '2026-04');
        $this->assertSame('ACTIVE', $this->assetRow($asset['id'])->status);
        $this->postedRun($this->tenant, '2026-05');

        $row = $this->assetRow($asset['id']);
        $this->assertSame(['FULLY_DEPRECIATED', '11000000.0000'], [$row->status, $row->accumulated_depreciation]);
        $this->assertSame(['POSTED', 'POSTED', 'POSTED'], $this->scheduleStatuses($asset['id']));
        $this->assertSame(1, DB::table('document_transitions')->where('document_id', $asset['id'])->where('from_status', 'ACTIVE')->where('to_status', 'FULLY_DEPRECIATED')->count());
        $this->postJson(self::FA.'/depreciation-runs', ['accounting_period_id' => $this->period($this->tenant, '2026-06')->id])->assertStatus(422)->assertJsonPath('code', 'DEPRECIATION_NOTHING_ELIGIBLE');
        $this->assertSame('1000000.0000', $this->getJson(self::FA."/assets/{$asset['id']}")->json('net_book_value'));
    }

    public function test_a_closed_or_future_period_takes_no_run_and_the_posting_date_must_lie_in_the_period(): void
    {
        $this->capitalizedAsset($this->tenant, $this->newCategory());
        $march = $this->period($this->tenant, '2026-03')->id;

        $this->postJson(self::FA.'/depreciation-runs', ['accounting_period_id' => $march, 'posting_date' => '2026-04-01'])->assertStatus(422)->assertJsonPath('code', 'DEPRECIATION_DATE_INVALID');
        $this->postJson(self::FA.'/depreciation-runs', ['accounting_period_id' => '00000000-0000-4000-8000-000000000000'])->assertStatus(422)->assertJsonPath('code', 'PERIOD_NOT_FOUND');
        $this->closePeriod($this->tenant, '2026-03');
        $before = $this->glFigures($this->tenant);
        $this->postJson(self::FA.'/depreciation-runs', ['accounting_period_id' => $march])->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame(0, DB::table('asset_depreciation_runs')->count());
    }

    public function test_a_period_closed_after_calculation_blocks_posting_and_leaves_the_draft_intact(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $run = $this->newRun($this->tenant, '2026-03');
        $this->closePeriod($this->tenant, '2026-03');
        $before = $this->glFigures($this->tenant);

        $this->postJson(self::FA."/depreciation-runs/{$run['id']}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');

        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame('DRAFT', DB::table('asset_depreciation_runs')->where('id', $run['id'])->value('status'));
        $this->assertSame('IN_RUN', $this->scheduleStatuses($asset['id'])[0]);
        $this->assertSame('0.0000', $this->assetRow($asset['id'])->accumulated_depreciation);
    }

    public function test_reversal_restores_the_register_and_the_most_recent_run_is_reversed_first(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $march = $this->postedRun($this->tenant, '2026-03');
        $april = $this->postedRun($this->tenant, '2026-04');

        $this->postJson(self::FA."/depreciation-runs/{$march['id']}/reverse", ['reason' => 'Salah'])->assertStatus(409)->assertJsonPath('code', 'DEPRECIATION_RUN_NOT_LAST');
        $this->assertSame('2000000.0000', $this->assetRow($asset['id'])->accumulated_depreciation);

        $reversed = $this->postJson(self::FA."/depreciation-runs/{$april['id']}/reverse", ['reason' => 'Salah bulan'])->assertOk()->json();
        $this->assertSame('REVERSED', $reversed['status']);
        $this->assertSame('1000000.0000', $this->assetRow($asset['id'])->accumulated_depreciation);
        $this->assertSame(['POSTED', 'PLANNED', 'PLANNED'], array_slice($this->scheduleStatuses($asset['id']), 0, 3));
        $this->assertSame('1000000.0000', $this->glBalance($this->tenant, '6600'));

        $this->postJson(self::FA."/depreciation-runs/{$april['id']}/reverse", ['reason' => 'Lagi'])->assertStatus(409)->assertJsonPath('code', 'DEPRECIATION_RUN_ALREADY_REVERSED');
        $this->postJson(self::FA."/depreciation-runs/{$march['id']}/reverse", ['reason' => 'Salah'])->assertOk();
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '6600'));
        $this->assertSame('0.0000', $this->assetRow($asset['id'])->accumulated_depreciation);

        // The freed month can be depreciated again, once.
        $again = $this->postedRun($this->tenant, '2026-03');
        $this->assertSame('1000000.0000', $again['total_amount']);
    }

    public function test_reversing_a_run_that_fully_depreciated_an_asset_makes_it_active_again(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory(), ['useful_life_months' => 2]);
        $run = $this->postedRun($this->tenant, '2026-04');
        $this->assertSame('FULLY_DEPRECIATED', $this->assetRow($asset['id'])->status);

        $this->postJson(self::FA."/depreciation-runs/{$run['id']}/reverse", ['reason' => 'Salah'])->assertOk();

        $this->assertSame(['ACTIVE', '0.0000'], [$this->assetRow($asset['id'])->status, $this->assetRow($asset['id'])->accumulated_depreciation]);
    }

    public function test_a_reversal_into_a_closed_period_is_refused_and_changes_nothing(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $run = $this->postedRun($this->tenant, '2026-03');
        $this->closePeriod($this->tenant, '2026-03');
        $before = $this->glFigures($this->tenant);

        $this->postJson(self::FA."/depreciation-runs/{$run['id']}/reverse", ['reason' => 'Salah', 'posting_date' => '2026-03-31'])->assertStatus(422);

        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame('POSTED', DB::table('asset_depreciation_runs')->where('id', $run['id'])->value('status'));
        $this->assertSame('1000000.0000', $this->assetRow($asset['id'])->accumulated_depreciation);
        $this->assertSame('POSTED', $this->scheduleStatuses($asset['id'])[0]);
    }

    public function test_a_posted_run_its_lines_and_its_schedule_rows_are_immutable_in_the_database(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $run = $this->postedRun($this->tenant, '2026-03');
        $refused = function (callable $statement) {
            try {
                DB::transaction($statement);
                $this->fail('the database accepted the change');
            } catch (QueryException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        };

        $refused(fn () => DB::table('asset_depreciation_runs')->where('id', $run['id'])->update(['total_amount' => '1']));
        $refused(fn () => DB::table('asset_depreciation_runs')->where('id', $run['id'])->delete());
        $refused(fn () => DB::table('asset_depreciation_run_lines')->where('run_id', $run['id'])->update(['amount' => '1']));
        $refused(fn () => DB::table('asset_depreciation_run_lines')->where('run_id', $run['id'])->delete());
        $refused(fn () => DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->where('sequence_no', 1)->update(['amount' => '1']));
        $refused(fn () => DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->where('sequence_no', 1)->update(['status' => 'PLANNED', 'run_line_id' => null]));
        $refused(fn () => DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->delete());
        $refused(fn () => DB::table('journal_entries')->where('id', DB::table('asset_depreciation_runs')->where('id', $run['id'])->value('journal_entry_id'))->update(['description' => 'x']));
        $this->assertSame('POSTED', DB::table('asset_depreciation_runs')->where('id', $run['id'])->value('status'));
    }

    public function test_each_run_line_carries_the_branch_of_its_asset_into_the_journal(): void
    {
        [$jkt, $sby] = $this->branches($this->tenant, 'JKT', 'SBY');
        $category = $this->newCategory();
        $this->capitalizedAsset($this->tenant, $category, ['name' => 'Mesin Jakarta', 'branch_id' => $jkt]);
        $this->capitalizedAsset($this->tenant, $category, ['name' => 'Mesin Surabaya A', 'branch_id' => $sby, 'acquisition_cost' => '6000000']);
        $this->capitalizedAsset($this->tenant, $category, ['name' => 'Mesin Surabaya B', 'branch_id' => $sby, 'acquisition_cost' => '3600000']);

        $run = $this->postedRun($this->tenant, '2026-03');

        $journalId = DB::table('asset_depreciation_runs')->where('id', $run['id'])->value('journal_entry_id');
        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journalId)
            ->get(['a.code', 'l.branch_id', 'l.debit', 'l.credit']);
        $this->assertCount(4, $lines, 'one expense and one accumulated line per branch; the two Surabaya assets share theirs');
        $byBranch = $lines->where('code', '6600')->mapWithKeys(fn ($l) => [$l->branch_id => $l->debit])->all();
        $this->assertSame([$jkt => '1000000.0000', $sby => '800000.0000'], $byBranch);
        $accumulated = $lines->where('code', '1290')->mapWithKeys(fn ($l) => [$l->branch_id => $l->credit])->all();
        $this->assertSame($byBranch, $accumulated);
    }

    public function test_a_scoped_user_only_sees_the_runs_they_created_and_cannot_open_anothers(): void
    {
        $this->capitalizedAsset($this->tenant, $this->newCategory());
        $run = $this->newRun($this->tenant, '2026-03');
        [$jkt] = $this->branches($this->tenant, 'JKT');
        $scoped = $this->scopedToken($this->tenant, ['accounting.asset.view', 'accounting.asset.depreciation.run'], 'BRANCH', $jkt);

        $this->as($scoped);
        $this->getJson(self::FA.'/depreciation-runs')->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::FA."/depreciation-runs/{$run['id']}")->assertStatus(404);
    }

    public function test_a_depreciation_journal_cannot_be_reversed_through_the_journal_api(): void
    {
        $this->capitalizedAsset($this->tenant, $this->newCategory());
        $run = $this->postedRun($this->tenant, '2026-03');
        $journalId = DB::table('asset_depreciation_runs')->where('id', $run['id'])->value('journal_entry_id');

        $this->postJson(self::FA."/journals/{$journalId}/reverse", ['reason' => 'Coba'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_OWNED_BY_DOCUMENT');
        $this->assertSame('POSTED', DB::table('asset_depreciation_runs')->where('id', $run['id'])->value('status'));
    }
}
