<?php

namespace Tests\Feature\FixedAsset;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA4 batch E: asset disposal (sale or scrap), its approval workflow, the gain or loss it posts and its reversal. */
class AssetDisposalTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->assetTenant();
        $this->signedIn($this->tenant);
    }

    /** A capitalized 12 month asset of 12,000,000 (monthly 1,000,000) depreciated through June: book value 8,000,000. */
    private function depreciatedAsset(array $override = [], string $through = '2026-06'): array
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory(), $override);
        $this->postedRun($this->tenant, $through);

        return $asset;
    }

    private function draftDisposal(array $asset, array $override = []): array
    {
        return $this->postJson(self::FA.'/asset-disposals', $override + [
            'fixed_asset_id' => $asset['id'], 'disposal_type' => 'SALE', 'disposal_date' => '2026-06-30', 'proceeds_amount' => '10000000',
            'proceeds_account_id' => $this->account($this->tenant, '1120')->id, 'reason' => 'Dijual',
        ])->assertCreated()->json();
    }

    /** @return list<array{string,string,string}> sorted, so the test does not depend on the order of the rule's lines */
    private function sortedLines(string $journalId): array
    {
        $lines = $this->journalLines($journalId);
        usort($lines, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $lines;
    }

    public function test_a_sale_above_book_value_posts_the_gain_and_removes_the_asset_from_the_books(): void
    {
        $asset = $this->depreciatedAsset();

        $posted = $this->postedDisposal($this->tenant, $asset);

        $this->assertSame(['POSTED', 'AD-FY2026-000001'], [$posted['status'], $posted['document_number']]);
        $this->assertSame(['12000000.0000', '4000000.0000', '8000000.0000', '2000000.0000', '0.0000'],
            [$posted['cost_amount'], $posted['accumulated_depreciation'], $posted['book_value'], $posted['gain_amount'], $posted['loss_amount']]);
        $journal = DB::table('asset_disposals')->where('id', $posted['id'])->value('journal_entry_id');
        $this->assertSame([
            ['1120', '10000000.0000', '0.0000'], ['1230', '0.0000', '12000000.0000'], ['1290', '4000000.0000', '0.0000'], ['4250', '0.0000', '2000000.0000'],
        ], $this->sortedLines($journal));
        $this->assertSame('SYSTEM', DB::table('journal_entries')->where('id', $journal)->value('journal_type'));

        $row = $this->assetRow($asset['id']);
        $this->assertSame(['DISPOSED', $posted['id']], [$row->status, $row->disposal_id]);
        $this->assertSame('2026-06-30', substr((string) $row->disposed_on, 0, 10));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1230'), 'the cost left the asset account');
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1290'), 'the accumulated depreciation left with it');
        $this->assertSame('10000000.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertSame('-2000000.0000', $this->glBalance($this->tenant, '4250'));
        // The asset keeps its depreciation history.
        $this->assertSame(4, DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $asset['id'])->where('status', 'POSTED')->count());
    }

    public function test_a_sale_below_book_value_posts_the_loss(): void
    {
        $asset = $this->depreciatedAsset();

        $posted = $this->postedDisposal($this->tenant, $asset, ['proceeds_amount' => '5000000']);

        $this->assertSame(['0.0000', '3000000.0000'], [$posted['gain_amount'], $posted['loss_amount']]);
        $journal = DB::table('asset_disposals')->where('id', $posted['id'])->value('journal_entry_id');
        $this->assertSame([
            ['1120', '5000000.0000', '0.0000'], ['1230', '0.0000', '12000000.0000'], ['1290', '4000000.0000', '0.0000'], ['4250', '3000000.0000', '0.0000'],
        ], $this->sortedLines($journal));
    }

    public function test_a_sale_at_book_value_has_neither_gain_nor_loss(): void
    {
        $asset = $this->depreciatedAsset();

        $posted = $this->postedDisposal($this->tenant, $asset, ['proceeds_amount' => '8000000']);

        $this->assertSame(['0.0000', '0.0000'], [$posted['gain_amount'], $posted['loss_amount']]);
        $journal = DB::table('asset_disposals')->where('id', $posted['id'])->value('journal_entry_id');
        $this->assertSame([['1120', '8000000.0000', '0.0000'], ['1230', '0.0000', '12000000.0000'], ['1290', '4000000.0000', '0.0000']], $this->sortedLines($journal));
    }

    public function test_scrapping_writes_off_the_book_value_as_a_loss_without_proceeds(): void
    {
        $asset = $this->depreciatedAsset();

        $posted = $this->postedDisposal($this->tenant, $asset, ['disposal_type' => 'SCRAP', 'proceeds_amount' => '0', 'proceeds_account_id' => null, 'reason' => 'Rusak total']);

        $this->assertSame(['SCRAP', '0.0000', '8000000.0000'], [$posted['disposal_type'], $posted['gain_amount'], $posted['loss_amount']]);
        $journal = DB::table('asset_disposals')->where('id', $posted['id'])->value('journal_entry_id');
        $this->assertSame([['1230', '0.0000', '12000000.0000'], ['1290', '4000000.0000', '0.0000'], ['4250', '8000000.0000', '0.0000']], $this->sortedLines($journal));
    }

    public function test_the_draft_previews_the_gain_or_loss_from_the_register_and_validates_its_terms(): void
    {
        $asset = $this->depreciatedAsset();
        $draft = $this->draftDisposal($asset, ['proceeds_amount' => '7000000']);
        $this->assertSame(['8000000.0000', '0.0000', '1000000.0000'], [$draft['preview']['book_value'], $draft['preview']['gain'], $draft['preview']['loss']]);

        $body = ['fixed_asset_id' => $asset['id'], 'disposal_type' => 'SALE', 'disposal_date' => '2026-06-30', 'proceeds_amount' => '1', 'proceeds_account_id' => $this->account($this->tenant, '1120')->id, 'reason' => 'x'];
        $this->postJson(self::FA.'/asset-disposals', ['proceeds_amount' => '0'] + $body)->assertStatus(422)->assertJsonPath('code', 'ASSET_DISPOSAL_PROCEEDS_REQUIRED');
        $this->postJson(self::FA.'/asset-disposals', ['disposal_date' => '2026-02-28'] + $body)->assertStatus(422)->assertJsonPath('code', 'ASSET_DISPOSAL_BEFORE_CAPITALIZATION');
        $this->postJson(self::FA.'/asset-disposals', ['posting_date' => '2026-06-01'] + $body)->assertStatus(422)->assertJsonPath('code', 'ASSET_DISPOSAL_DATE_INVALID');
        $this->postJson(self::FA.'/asset-disposals', ['proceeds_account_id' => $this->account($this->tenant, '6100')->id] + $body)->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID');
        $this->postJson(self::FA.'/asset-disposals', ['reason' => '  '] + $body)->assertStatus(422);
        $this->postJson(self::FA.'/asset-disposals', ['proceeds_amount' => '10.123'] + $body)->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson(self::FA.'/asset-disposals', ['fixed_asset_id' => '00000000-0000-4000-8000-000000000000'] + $body)->assertStatus(422)->assertJsonPath('code', 'ASSET_NOT_FOUND');
        $this->postJson(self::FA.'/asset-disposals', ['disposal_type' => 'DONATE'] + $body)->assertStatus(422);
    }

    public function test_an_asset_that_is_not_capitalized_cannot_be_disposed(): void
    {
        $draftAsset = $this->newAsset($this->tenant, $this->newCategory());

        $this->postJson(self::FA.'/asset-disposals', [
            'fixed_asset_id' => $draftAsset['id'], 'disposal_type' => 'SCRAP', 'disposal_date' => '2026-06-30', 'reason' => 'x',
        ])->assertStatus(409)->assertJsonPath('code', 'ASSET_NOT_DISPOSABLE');
    }

    public function test_depreciation_up_to_the_disposal_date_must_be_posted_first(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $this->postedRun($this->tenant, '2026-04'); // March and April only; May and June are pending
        $draft = $this->draftDisposal($asset);

        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/submit")->assertStatus(409)->assertJsonPath('code', 'ASSET_DEPRECIATION_PENDING')
            ->assertJsonPath('details.pending_months', 2)->assertJsonPath('details.first_pending_period', '2026-05-01');

        $held = $this->newRun($this->tenant, '2026-06');
        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/submit")->assertStatus(409)->assertJsonPath('code', 'ASSET_DEPRECIATION_IN_RUN');
        $this->postJson(self::FA."/depreciation-runs/{$held['id']}/post")->assertOk();

        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/submit")->assertOk()->assertJsonPath('status', 'SUBMITTED');
        $this->assertSame('ACTIVE', $this->assetRow($asset['id'])->status);
    }

    public function test_months_after_the_disposal_date_need_no_depreciation_and_a_disposed_asset_leaves_the_runs(): void
    {
        $asset = $this->depreciatedAsset();
        $this->postedDisposal($this->tenant, $asset);

        $this->postJson(self::FA.'/depreciation-runs', ['accounting_period_id' => $this->period($this->tenant, '2026-09')->id])->assertStatus(422)->assertJsonPath('code', 'DEPRECIATION_NOTHING_ELIGIBLE');
        $this->assertSame('4000000.0000', $this->assetRow($asset['id'])->accumulated_depreciation);
    }

    public function test_the_approval_workflow_and_segregation_of_duties(): void
    {
        $asset = $this->depreciatedAsset();
        DB::table('accounting_profiles')->where('tenant_id', $this->tenant->id)->update(['approval_required' => true, 'sod_creator_not_approver' => true, 'sod_approver_not_poster' => true]);
        $creator = $this->memberToken($this->tenant);
        $approver = $this->memberToken($this->tenant);
        $this->as($creator);
        $draft = $this->draftDisposal($asset);

        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/post")->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_APPROVAL_REQUIRED');
        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/submit")->assertOk();
        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/approve")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');

        $this->as($approver)->postJson(self::FA."/asset-disposals/{$draft['id']}/reject", ['reason' => 'Harga terlalu rendah'])->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/post")->assertStatus(409);
        $this->as($creator)->postJson(self::FA."/asset-disposals/{$draft['id']}/reopen")->assertOk()->assertJsonPath('status', 'DRAFT');
        $this->patchJson(self::FA."/asset-disposals/{$draft['id']}", ['proceeds_amount' => '11000000'])->assertOk()->assertJsonPath('preview.gain', '3000000.0000');
        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/submit")->assertOk();

        $this->as($approver)->postJson(self::FA."/asset-disposals/{$draft['id']}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/post")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->as($creator)->postJson(self::FA."/asset-disposals/{$draft['id']}/post")->assertOk()->assertJsonPath('status', 'POSTED')->assertJsonPath('gain_amount', '3000000.0000');
        $this->assertSame(['DRAFT', 'SUBMITTED', 'REJECTED', 'DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED'], DB::table('document_transitions')->where('document_id', $draft['id'])->orderBy('occurred_at')->orderBy('id')->pluck('to_status')->all());
    }

    public function test_one_live_disposal_per_asset_and_a_cancelled_one_frees_it(): void
    {
        $asset = $this->depreciatedAsset();
        $first = $this->draftDisposal($asset);

        $this->postJson(self::FA.'/asset-disposals', [
            'fixed_asset_id' => $asset['id'], 'disposal_type' => 'SCRAP', 'disposal_date' => '2026-06-30', 'reason' => 'Lagi',
        ])->assertStatus(409)->assertJsonPath('code', 'ASSET_DISPOSAL_EXISTS');

        $this->postJson(self::FA."/asset-disposals/{$first['id']}/cancel", ['reason' => 'Batal jual'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $posted = $this->postedDisposal($this->tenant, $asset, ['proceeds_amount' => '9000000']);

        $this->assertSame('POSTED', $posted['status']);
        $this->assertSame(['CANCELLED', 'POSTED'], DB::table('asset_disposals')->where('fixed_asset_id', $asset['id'])->orderBy('created_at')->pluck('status')->all());
    }

    public function test_a_posted_disposal_blocks_a_second_disposal_and_a_reversal_of_the_depreciation_behind_it(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $run = $this->postedRun($this->tenant, '2026-06');
        $this->postedDisposal($this->tenant, $asset);

        $this->postJson(self::FA.'/asset-disposals', ['fixed_asset_id' => $asset['id'], 'disposal_type' => 'SCRAP', 'disposal_date' => '2026-06-30', 'reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'ASSET_NOT_DISPOSABLE');
        $this->postJson(self::FA."/depreciation-runs/{$run['id']}/reverse", ['reason' => 'Coba'])->assertStatus(409)->assertJsonPath('code', 'DEPRECIATION_ASSET_NOT_ON_BOOKS');
        $this->postJson(self::FA."/assets/{$asset['id']}/reverse-capitalization", ['reason' => 'Coba'])->assertStatus(409);
        $this->assertSame('DISPOSED', $this->assetRow($asset['id'])->status);
    }

    public function test_reversing_a_disposal_puts_the_asset_back_on_the_books_with_its_depreciation(): void
    {
        $asset = $this->depreciatedAsset();
        $posted = $this->postedDisposal($this->tenant, $asset);

        $reversed = $this->postJson(self::FA."/asset-disposals/{$posted['id']}/reverse", ['reason' => 'Pembeli batal'])->assertOk()->json();

        $this->assertSame('REVERSED', $reversed['status']);
        $row = $this->assetRow($asset['id']);
        $this->assertSame(['ACTIVE', null, null, '4000000.0000'], [$row->status, $row->disposed_on, $row->disposal_id, $row->accumulated_depreciation]);
        $this->assertSame('12000000.0000', $this->glBalance($this->tenant, '1230'));
        $this->assertSame('-4000000.0000', $this->glBalance($this->tenant, '1290'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '4250'));

        $this->postJson(self::FA."/asset-disposals/{$posted['id']}/reverse", ['reason' => 'Lagi'])->assertStatus(409)->assertJsonPath('code', 'ASSET_DISPOSAL_ALREADY_REVERSED');
        // The asset can be disposed again and depreciation continues for the months after.
        $again = $this->postedDisposal($this->tenant, $asset, ['proceeds_amount' => '8500000']);
        $this->assertSame('500000.0000', $again['gain_amount']);
    }

    public function test_reversing_the_disposal_of_a_fully_depreciated_asset_restores_that_state(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory(), ['useful_life_months' => 2]);
        $this->postedRun($this->tenant, '2026-04');
        $posted = $this->postedDisposal($this->tenant, $asset, ['disposal_date' => '2026-04-30', 'proceeds_amount' => '1000000']);
        $this->assertSame('12000000.0000', $posted['accumulated_depreciation']);
        $this->assertSame('DISPOSED', $this->assetRow($asset['id'])->status);

        $this->postJson(self::FA."/asset-disposals/{$posted['id']}/reverse", ['reason' => 'Salah'])->assertOk();

        $this->assertSame('FULLY_DEPRECIATED', $this->assetRow($asset['id'])->status);
    }

    public function test_a_closed_period_blocks_posting_and_leaves_the_asset_and_the_ledger_untouched(): void
    {
        $asset = $this->depreciatedAsset();
        $draft = $this->draftDisposal($asset);
        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/submit")->assertOk();
        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/approve")->assertOk();
        $this->closePeriod($this->tenant, '2026-06');
        $before = $this->glFigures($this->tenant);

        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');

        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame('ACTIVE', $this->assetRow($asset['id'])->status);
        $this->assertSame('APPROVED', DB::table('asset_disposals')->where('id', $draft['id'])->value('status'));
        $this->assertNull(DB::table('asset_disposals')->where('id', $draft['id'])->value('document_number'));
    }

    public function test_a_posted_disposal_is_immutable_in_the_database_and_its_journal_cannot_be_reversed_elsewhere(): void
    {
        $asset = $this->depreciatedAsset();
        $posted = $this->postedDisposal($this->tenant, $asset);
        $journal = DB::table('asset_disposals')->where('id', $posted['id'])->value('journal_entry_id');
        $refused = function (callable $statement) {
            try {
                DB::transaction($statement);
                $this->fail('the database accepted the change');
            } catch (QueryException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        };

        $refused(fn () => DB::table('asset_disposals')->where('id', $posted['id'])->update(['proceeds_amount' => '1']));
        $refused(fn () => DB::table('asset_disposals')->where('id', $posted['id'])->update(['gain_amount' => '0', 'loss_amount' => '0']));
        $refused(fn () => DB::table('asset_disposals')->where('id', $posted['id'])->delete());
        $refused(fn () => DB::table('fixed_assets')->where('id', $asset['id'])->update(['status' => 'ACTIVE']));
        $this->postJson(self::FA."/journals/{$journal}/reverse", ['reason' => 'Coba'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_OWNED_BY_DOCUMENT');
        $this->assertSame('DISPOSED', $this->assetRow($asset['id'])->status);
    }

    public function test_the_database_refuses_a_second_live_disposal_and_a_disposal_before_capitalization(): void
    {
        $asset = $this->depreciatedAsset();
        $draft = $this->draftDisposal($asset);
        $copy = (array) DB::table('asset_disposals')->where('id', $draft['id'])->first();
        $copy['id'] = (string) Str::uuid();

        try {
            DB::transaction(fn () => DB::table('asset_disposals')->insert($copy));
            $this->fail('a second live disposal was accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('asset_disposals_one_live', $e->getMessage());
        }
        $this->postJson(self::FA."/asset-disposals/{$draft['id']}/cancel", ['reason' => 'x'])->assertOk();
        $copy['disposal_date'] = '2026-01-01';
        $copy['posting_date'] = '2026-01-01';
        try {
            DB::transaction(fn () => DB::table('asset_disposals')->insert($copy));
            $this->fail('a disposal before capitalization was accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('before its capitalization', $e->getMessage());
        }
    }

    public function test_the_list_filters_by_status_asset_type_and_text(): void
    {
        $a = $this->depreciatedAsset();
        $b = $this->capitalizedAsset($this->tenant, $this->newCategory(), ['name' => 'Mesin B']);
        $this->postedRun($this->tenant, '2026-06');
        $this->postedDisposal($this->tenant, $a);
        $scrap = $this->draftDisposal($b, ['disposal_type' => 'SCRAP', 'proceeds_amount' => '0', 'proceeds_account_id' => null, 'reason' => 'Rusak']);

        $this->assertSame(2, $this->getJson(self::FA.'/asset-disposals')->json('total'));
        $this->assertSame([$scrap['id']], collect($this->getJson(self::FA.'/asset-disposals?status=DRAFT')->json('data'))->pluck('id')->all());
        $this->assertSame([$scrap['id']], collect($this->getJson(self::FA.'/asset-disposals?disposal_type=SCRAP')->json('data'))->pluck('id')->all());
        $this->assertSame(1, $this->getJson(self::FA.'/asset-disposals?fixed_asset_id='.$a['id'])->json('total'));
        $this->getJson(self::FA.'/asset-disposals?status=NOPE')->assertStatus(422);
    }
}
