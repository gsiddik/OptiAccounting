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

/** OA4 batch C: asset categories, the register and capitalization. */
class FixedAssetRegisterTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->assetTenant();
        $this->signedIn($this->tenant);
    }

    public function test_a_category_validates_its_accounts_method_and_residual_policy(): void
    {
        $expense = $this->account($this->tenant, '6600')->id;

        $this->postJson(self::FA.'/asset-categories', ['code' => 'bad code', 'name' => 'X'])->assertStatus(422)->assertJsonPath('code', 'ASSET_CATEGORY_CODE_INVALID');
        $this->postJson(self::FA.'/asset-categories', ['code' => 'X1', 'name' => 'X', 'asset_account_id' => $expense])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID');
        $this->postJson(self::FA.'/asset-categories', ['code' => 'X1', 'name' => 'X', 'asset_account_id' => $this->account($this->tenant, '1130')->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_CONTROL_RESTRICTED');
        $this->postJson(self::FA.'/asset-categories', ['code' => 'X1', 'name' => 'X', 'default_method' => 'MAGIC'])->assertStatus(422);
        $this->postJson(self::FA.'/asset-categories', ['code' => 'X1', 'name' => 'X', 'default_residual_type' => 'PERCENT', 'default_residual_value' => '120'])->assertStatus(422)->assertJsonPath('code', 'ASSET_RESIDUAL_POLICY_INVALID');
        $this->postJson(self::FA.'/asset-categories', ['code' => 'X1', 'name' => 'X', 'default_residual_type' => 'NONE', 'default_residual_value' => '5'])->assertStatus(422)->assertJsonPath('code', 'ASSET_RESIDUAL_POLICY_INVALID');

        $land = $this->postJson(self::FA.'/asset-categories', ['code' => 'LAND', 'name' => 'Tanah', 'default_method' => 'NONE', 'default_useful_life_months' => 60])->assertCreated()->json();
        $this->assertNull($land['default_useful_life_months'], 'a method that never depreciates has no life');
        $this->postJson(self::FA.'/asset-categories', ['code' => 'LAND', 'name' => 'Lagi'])->assertStatus(422)->assertJsonPath('code', 'ASSET_CATEGORY_CODE_TAKEN');

        $this->getJson(self::FA.'/asset-categories')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_an_inactive_category_takes_no_new_assets_and_a_used_category_is_never_deleted(): void
    {
        $category = $this->newCategory();
        $this->postJson(self::FA."/asset-categories/{$category['id']}/deactivate")->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->postJson(self::FA.'/assets', $this->assetBody($this->tenant, $category))->assertStatus(422)->assertJsonPath('code', 'ASSET_CATEGORY_INACTIVE');

        $this->postJson(self::FA."/asset-categories/{$category['id']}/activate")->assertOk();
        $this->newAsset($this->tenant, $category);
        $this->deleteJson(self::FA."/asset-categories/{$category['id']}")->assertStatus(409)->assertJsonPath('code', 'ASSET_CATEGORY_IN_USE');
    }

    public function test_a_draft_asset_takes_its_terms_from_the_category_and_previews_a_deterministic_schedule(): void
    {
        $category = $this->newCategory(['default_useful_life_months' => 24, 'default_residual_type' => 'PERCENT', 'default_residual_value' => '10']);
        $body = $this->assetBody($this->tenant, $category);
        unset($body['useful_life_months']);

        $asset = $this->postJson(self::FA.'/assets', $body)->assertCreated()->json();
        $this->assertSame([24, '1200000.0000', 'DRAFT', null, 'IDR'], [$asset['useful_life_months'], $asset['residual_value'], $asset['status'], $asset['asset_number'], $asset['currency']]);

        $preview = $this->getJson(self::FA."/assets/{$asset['id']}/schedule")->assertOk()->json();
        $this->assertTrue($preview['preview']);
        $this->assertCount(24, $preview['rows']);
        $this->assertSame('2026-03-01', $preview['rows'][0]['period_start']);
        $this->assertSame('2028-02-29', $preview['rows'][23]['period_end']);
        $this->assertSame('10800000.0000', $preview['rows'][23]['accumulated_after']); // cost - residual
        $this->assertSame($preview, $this->getJson(self::FA."/assets/{$asset['id']}/schedule")->json());

        $this->patchJson(self::FA."/assets/{$asset['id']}", ['acquisition_cost' => '24000000', 'useful_life_months' => 48])->assertOk();
        $this->assertCount(48, $this->getJson(self::FA."/assets/{$asset['id']}/schedule")->json('rows'));
    }

    public function test_the_basis_life_and_dates_are_validated(): void
    {
        $category = $this->newCategory();
        $this->postJson(self::FA.'/assets', $this->assetBody($this->tenant, $category, ['acquisition_cost' => '0']))->assertStatus(422)->assertJsonPath('code', 'ASSET_COST_INVALID');
        $this->postJson(self::FA.'/assets', $this->assetBody($this->tenant, $category, ['residual_value' => '13000000']))->assertStatus(422)->assertJsonPath('code', 'ASSET_RESIDUAL_INVALID');
        $this->postJson(self::FA.'/assets', $this->assetBody($this->tenant, $category, ['useful_life_months' => 0]))->assertStatus(422);
        $this->postJson(self::FA.'/assets', $this->assetBody($this->tenant, $category, ['capitalization_date' => '2026-03-01']))->assertStatus(422)->assertJsonPath('code', 'ASSET_DATE_INVALID');
        $this->postJson(self::FA.'/assets', $this->assetBody($this->tenant, $category, ['acquisition_cost' => '10.123']))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson(self::FA.'/assets', $this->assetBody($this->tenant, $category, ['acquisition_cost' => 1000.5]))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson(self::FA.'/assets', $this->assetBody($this->tenant, $category, ['name' => '  ']))->assertStatus(422);
    }

    public function test_capitalization_posts_exactly_once_through_the_engine_with_mapped_accounts(): void
    {
        $category = $this->newCategory();
        $asset = $this->newAsset($this->tenant, $category);
        $before = $this->glFigures($this->tenant);

        $done = $this->postJson(self::FA."/assets/{$asset['id']}/capitalize")->assertOk()->json();
        $this->assertSame(['ACTIVE', 'FA-FY2026-000001', '0.0000', '12000000.0000'], [$done['status'], $done['asset_number'], $done['accumulated_depreciation'], $done['net_book_value']]);
        $this->assertSame($this->account($this->tenant, '1230')->id, $done['asset_account_id'], 'the asset account came from the FIXED_ASSET mapping');
        $this->assertSame($this->account($this->tenant, '1290')->id, $done['accumulated_account_id']);
        $this->assertSame($this->account($this->tenant, '6600')->id, $done['expense_account_id']);

        $journal = DB::table('fixed_assets')->where('id', $asset['id'])->value('capitalization_journal_id');
        $this->assertSame([['1230', '12000000.0000', '0.0000'], ['2140', '0.0000', '12000000.0000']], $this->journalLines($journal));
        $this->assertSame('SYSTEM', DB::table('journal_entries')->where('id', $journal)->value('journal_type'));
        $after = $this->glFigures($this->tenant);
        $this->assertSame([$before->lines + 2], [(int) $after->lines]);

        $listed = collect($this->getJson(self::FA.'/assets')->assertOk()->json('data'))->firstWhere('id', $asset['id']);
        $this->assertSame('12000000.0000', $listed['net_book_value'], 'the register list carries the book value');

        // A second attempt is refused and posts nothing; the event exists once.
        $this->postJson(self::FA."/assets/{$asset['id']}/capitalize")->assertStatus(409)->assertJsonPath('code', 'ASSET_ALREADY_CAPITALIZED');
        $this->assertEquals($after, $this->glFigures($this->tenant));
        $this->assertSame(1, DB::table('accounting_events')->where('source_type', 'fixed_asset')->where('source_id', $asset['id'])->count());

        // The schedule: deterministic, twelve months from the capitalization month, adding up to the basis exactly.
        $schedule = $this->getJson(self::FA."/assets/{$asset['id']}/schedule")->assertOk()->json();
        $this->assertFalse($schedule['preview']);
        $this->assertCount(12, $schedule['rows']);
        $this->assertSame('12000000.0000', number_format((float) DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->sum('amount'), 4, '.', ''));
        $this->assertSame(['PLANNED'], array_values(array_unique(array_column($schedule['rows'], 'status'))));
        $this->assertSame(12, $done['schedule_summary']['rows']);
        $this->assertSame(12, $done['schedule_snapshot']['rows']);
        $this->assertSame('HALF_UP', $done['schedule_snapshot']['rounding']);
    }

    public function test_a_category_account_overrides_the_mapping_and_later_category_changes_never_move_a_capitalized_asset(): void
    {
        $vehicles = $this->account($this->tenant, '1220')->id;
        $category = $this->newCategory(['asset_account_id' => $vehicles]);
        $asset = $this->capitalizedAsset($this->tenant, $category);
        $this->assertSame($vehicles, $asset['asset_account_id']);
        $this->assertSame('1220', $this->journalLines(DB::table('fixed_assets')->where('id', $asset['id'])->value('capitalization_journal_id'))[0][0]);

        $this->patchJson(self::FA."/asset-categories/{$category['id']}", ['asset_account_id' => $this->account($this->tenant, '1210')->id])->assertOk();
        $this->assertSame($vehicles, $this->getJson(self::FA."/assets/{$asset['id']}")->json('asset_account_id'));
    }

    public function test_the_financial_terms_of_a_capitalized_asset_are_immutable_in_the_service_and_the_database(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());

        $this->patchJson(self::FA."/assets/{$asset['id']}", ['acquisition_cost' => '1'])->assertStatus(409)->assertJsonPath('code', 'ASSET_NOT_EDITABLE');
        $this->patchJson(self::FA."/assets/{$asset['id']}", ['name' => 'Mesin Pencetak Warna'])->assertOk()->assertJsonPath('name', 'Mesin Pencetak Warna');

        // Each attempt runs in its own savepoint: a refused statement aborts the surrounding transaction otherwise.
        $refused = function (callable $statement, string $needle) {
            try {
                DB::transaction($statement);
                $this->fail('the database accepted the change (expected: '.$needle.')');
            } catch (QueryException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        };
        foreach ([['acquisition_cost' => '1'], ['useful_life_months' => 6], ['residual_value' => '5'], ['method' => 'DECLINING_BALANCE'], ['capitalization_date' => '2026-04-01']] as $change) {
            $refused(fn () => DB::table('fixed_assets')->where('id', $asset['id'])->update($change), 'cannot change');
        }
        $refused(fn () => DB::table('fixed_assets')->where('id', $asset['id'])->delete(), 'only a draft');
        $refused(fn () => DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->where('sequence_no', 1)->update(['amount' => '1']), 'cannot change');
    }

    public function test_accumulated_depreciation_can_never_exceed_the_basis_in_the_database(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory(), ['residual_value' => '2000000']);
        $this->expectException(QueryException::class);
        DB::table('fixed_assets')->where('id', $asset['id'])->update(['accumulated_depreciation' => '10000000.0001']);
    }

    public function test_a_closed_period_blocks_capitalization_and_leaves_nothing_behind(): void
    {
        $asset = $this->newAsset($this->tenant, $this->newCategory());
        $this->closePeriod($this->tenant, '2026-03');
        $before = $this->glFigures($this->tenant);

        $this->postJson(self::FA."/assets/{$asset['id']}/capitalize")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');

        $this->assertEquals($before, $this->glFigures($this->tenant));
        $row = $this->assetRow($asset['id']);
        $this->assertSame(['DRAFT', null], [$row->status, $row->asset_number]);
        $this->assertSame(0, DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->count());
        $this->assertSame(0, DB::table('accounting_events')->where('status', 'POSTED')->where('source_id', $asset['id'])->count());
    }

    public function test_capitalization_needs_a_source_account_mapped_roles_and_respects_segregation_of_duties(): void
    {
        $category = $this->newCategory();
        $body = $this->assetBody($this->tenant, $category);
        unset($body['source_account_id']);
        $asset = $this->postJson(self::FA.'/assets', $body)->assertCreated()->json();
        $this->postJson(self::FA."/assets/{$asset['id']}/capitalize")->assertStatus(422)->assertJsonPath('code', 'ASSET_SOURCE_ACCOUNT_REQUIRED');

        $this->patchJson(self::FA."/assets/{$asset['id']}", ['source_account_id' => $this->account($this->tenant, '2110')->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_CONTROL_RESTRICTED');
        $this->patchJson(self::FA."/assets/{$asset['id']}", ['source_account_id' => $this->account($this->tenant, '6100')->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID');

        // The same person who prepared the asset may not capitalize it where the policy says so.
        DB::table('accounting_profiles')->where('tenant_id', $this->tenant->id)->update(['sod_creator_not_poster' => true]);
        $this->patchJson(self::FA."/assets/{$asset['id']}", ['source_account_id' => $this->account($this->tenant, '2140')->id])->assertOk();
        $this->postJson(self::FA."/assets/{$asset['id']}/capitalize")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $other = $this->memberToken($this->tenant);
        $this->as($other)->postJson(self::FA."/assets/{$asset['id']}/capitalize")->assertOk()->assertJsonPath('status', 'ACTIVE');
    }

    public function test_a_discarded_draft_stays_in_the_history_and_cannot_be_capitalized(): void
    {
        $asset = $this->newAsset($this->tenant, $this->newCategory());
        $this->postJson(self::FA."/assets/{$asset['id']}/discard", ['reason' => 'Salah input'])->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->postJson(self::FA."/assets/{$asset['id']}/capitalize")->assertStatus(409)->assertJsonPath('code', 'ASSET_INACTIVE');
        $this->patchJson(self::FA."/assets/{$asset['id']}", ['name' => 'x'])->assertStatus(409);
        $this->assertSame(['DRAFT', 'INACTIVE'], DB::table('document_transitions')->where('document_id', $asset['id'])->orderBy('occurred_at')->pluck('to_status')->all());
    }

    public function test_capitalization_can_be_reversed_until_depreciation_starts(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $reversed = $this->postJson(self::FA."/assets/{$asset['id']}/reverse-capitalization", ['reason' => 'Salah akun'])->assertOk()->json();
        $this->assertSame('INACTIVE', $reversed['status']);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1230'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2140'));
        $this->assertSame(['CANCELLED'], DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->distinct()->pluck('status')->all());

        // After depreciation it is refused: reverse the run first.
        $second = $this->capitalizedAsset($this->tenant, $this->newCategory(), ['name' => 'Mesin kedua']);
        $this->postedRun($this->tenant, '2026-03');
        $this->postJson(self::FA."/assets/{$second['id']}/reverse-capitalization", ['reason' => 'Terlambat'])->assertStatus(409)->assertJsonPath('code', 'ASSET_HAS_DEPRECIATION');
    }

    public function test_the_journal_api_refuses_to_reverse_a_journal_that_belongs_to_an_asset_document(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $journal = DB::table('fixed_assets')->where('id', $asset['id'])->value('capitalization_journal_id');

        $this->postJson(self::FA."/journals/{$journal}/reverse", ['reason' => 'Coba'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_OWNED_BY_DOCUMENT');
        $this->assertSame('ACTIVE', $this->assetRow($asset['id'])->status);
    }

    public function test_the_register_lists_and_filters_by_status_category_and_text(): void
    {
        $category = $this->newCategory(['code' => 'EQ1']);
        $other = $this->newCategory(['code' => 'EQ2']);
        $this->capitalizedAsset($this->tenant, $category, ['name' => 'Kompresor']);
        $this->newAsset($this->tenant, $other, ['name' => 'Genset']);

        $this->assertSame(2, $this->getJson(self::FA.'/assets')->json('total'));
        $this->assertSame(['Kompresor'], collect($this->getJson(self::FA.'/assets?status=ACTIVE')->json('data'))->pluck('name')->all());
        $this->assertSame(['Genset'], collect($this->getJson(self::FA.'/assets?asset_category_id='.$other['id'])->json('data'))->pluck('name')->all());
        $this->assertSame(['Kompresor'], collect($this->getJson(self::FA.'/assets?q=kompres')->json('data'))->pluck('name')->all());
        $this->getJson(self::FA.'/assets?status=NOPE')->assertStatus(422);
    }
}
