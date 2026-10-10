<?php

namespace Tests\Feature\FixedAsset;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA4 batch F: register to ledger reconciliation, assets registered from an AP invoice line, and the asset posting rules setup. */
class AssetReconciliationTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->assetTenant();
        $this->signedIn($this->tenant);
    }

    /** A posted AP invoice with one line of $amount on the account $code (the ledger holds the cost already). @return array{invoice:array<string,mixed>,line:string} */
    private function assetInvoice(string $amount = '20000000', string $code = '1220', bool $post = true): array
    {
        $vendor = $this->vendor($this->tenant);
        $body = $this->invoiceBody($vendor, ['lines' => [['description' => 'Truk', 'amount' => $amount, 'account_id' => $this->account($this->tenant, $code)->id]]]);
        $invoice = $post ? $this->postedInvoice($vendor, $body) : $this->postJson(self::AP.'/ap-invoices', $body)->assertCreated()->json();

        return ['invoice' => $invoice, 'line' => (string) DB::table('ap_invoice_lines')->where('ap_invoice_id', $invoice['id'])->value('id')];
    }

    private function registerBody(array $category, string $line, array $override = []): array
    {
        $body = $this->assetBody($this->tenant, $category, $override + ['capitalization_mode' => 'REGISTER_ONLY', 'ap_invoice_line_id' => $line, 'acquisition_cost' => '12000000']);
        unset($body['source_account_id']);

        return $body;
    }

    public function test_the_register_matches_the_ledger_through_capitalization_depreciation_and_disposal(): void
    {
        $category = $this->newCategory();
        $asset = $this->capitalizedAsset($this->tenant, $category);
        $report = $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-03-31')->assertOk()->json();
        $this->assertTrue($report['reconciled']);
        $this->assertTrue($report['complete']);
        $this->assertSame(['12000000.0000', '12000000.0000', '0.0000', '0.0000'], [$report['totals']['register_cost'], $report['totals']['ledger_cost'], $report['totals']['register_accumulated'], $report['totals']['ledger_accumulated']]);

        $this->postedRun($this->tenant, '2026-05');
        $report = $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-05-31')->assertOk()->json();
        $this->assertTrue($report['reconciled']);
        $this->assertSame(['3000000.0000', '3000000.0000', '9000000.0000', '0.0000'], [$report['totals']['register_accumulated'], $report['totals']['ledger_accumulated'], $report['totals']['register_net_book_value'], $report['totals']['difference']]);
        $this->assertSame(['1230'], array_column(array_column($report['cost'], 'account'), 'code'));
        $this->assertSame(['1290'], array_column(array_column($report['accumulated_depreciation'], 'account'), 'code'));

        // The state of an earlier date is reproducible after later movements.
        $this->postedRun($this->tenant, '2026-06');
        $this->postedDisposal($this->tenant, $asset, ['proceeds_amount' => '9000000']);
        $this->assertTrue($this->getJson(self::FA.'/asset-reconciliation?as_of=2026-05-31')->json('reconciled'));
        $this->assertSame('3000000.0000', $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-05-31')->json('totals.ledger_accumulated'));
        $after = $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-06-30')->json();
        $this->assertTrue($after['reconciled']);
        $this->assertSame(['0.0000', '0.0000'], [$after['totals']['register_cost'], $after['totals']['ledger_cost']]);
    }

    public function test_reversals_keep_the_register_and_the_ledger_together(): void
    {
        $asset = $this->capitalizedAsset($this->tenant, $this->newCategory());
        $run = $this->postedRun($this->tenant, '2026-04');
        $this->postJson(self::FA."/depreciation-runs/{$run['id']}/reverse", ['reason' => 'Salah'])->assertOk();
        $this->assertTrue($this->getJson(self::FA.'/asset-reconciliation?as_of=2026-04-30')->json('reconciled'));

        $this->postJson(self::FA."/assets/{$asset['id']}/reverse-capitalization", ['reason' => 'Salah akun'])->assertOk();
        $report = $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-04-30')->assertOk()->json();
        $this->assertTrue($report['reconciled']);
        $this->assertSame(['0.0000', '0.0000'], [$report['totals']['register_cost'], $report['totals']['ledger_cost']]);
    }

    public function test_a_difference_is_reported_as_it_is_and_never_adjusted(): void
    {
        $this->capitalizedAsset($this->tenant, $this->newCategory());
        // Somebody posts a manual journal straight to the asset account: the ledger moves, the register does not.
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '500000', '1230', '4100')]);

        $report = $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-03-31')->assertOk()->json();

        $this->assertFalse($report['reconciled']);
        $this->assertSame(['12000000.0000', '12500000.0000', '500000.0000', false], [$report['cost'][0]['register'], $report['cost'][0]['ledger'], $report['cost'][0]['difference'], $report['cost'][0]['matched']]);
        $this->assertSame('500000.0000', $report['totals']['difference']);
        $this->assertSame(1, DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->where('source_type', 'fixed_asset')->count(), 'nothing was posted to hide the difference');
    }

    public function test_an_asset_account_with_ledger_balance_and_no_register_is_listed_from_the_mapping(): void
    {
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '750000', '1230', '4100')]);

        $report = $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-03-31')->assertOk()->json();

        $this->assertFalse($report['reconciled']);
        $this->assertSame(['1230', '0.0000', '750000.0000'], [$report['cost'][0]['account']['code'], $report['cost'][0]['register'], $report['cost'][0]['ledger']]);
    }

    public function test_a_scoped_user_gets_a_partial_report_marked_incomplete(): void
    {
        [$jkt, $sby] = $this->branches($this->tenant, 'JKT', 'SBY');
        $category = $this->newCategory();
        $this->capitalizedAsset($this->tenant, $category, ['branch_id' => $jkt]);
        $this->capitalizedAsset($this->tenant, $category, ['branch_id' => $sby, 'name' => 'Mesin SBY', 'acquisition_cost' => '6000000']);
        $scoped = $this->scopedToken($this->tenant, ['accounting.asset.view', 'accounting.asset.reconciliation.view', 'accounting.report.export'], 'BRANCH', $jkt);

        $this->as($scoped);
        $report = $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-03-31')->assertOk()->json();

        $this->assertFalse($report['complete']);
        $this->assertSame('12000000.0000', $report['totals']['register_cost'], 'only the assets of the user\'s branch');
        $this->assertSame('12000000.0000', $report['totals']['ledger_cost'], 'and only its journal lines');
    }

    public function test_the_reconciliation_exports_as_csv_and_is_audited(): void
    {
        $this->capitalizedAsset($this->tenant, $this->newCategory());

        $response = $this->get(self::FA.'/asset-reconciliation/export?as_of=2026-03-31')->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('1230', $csv);
        $this->assertStringContainsString('12000000.0000', $csv);
        $this->assertSame(1, DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', 'fixed_asset.report.exported')->count());
        $this->getJson(self::FA.'/asset-reconciliation?as_of=31-03-2026')->assertStatus(422);
    }

    public function test_an_asset_can_be_registered_from_a_posted_ap_invoice_line_without_a_second_journal(): void
    {
        ['line' => $line] = $this->assetInvoice();
        $before = $this->glFigures($this->tenant);

        $category = $this->newCategory();
        $draft = $this->postJson(self::FA.'/assets', $this->registerBody($category, $line))->assertCreated()->json();
        $this->assertSame(['REGISTER_ONLY', 'AP_INVOICE_LINE', $line], [$draft['capitalization_mode'], $draft['source_type'], $draft['source_id']]);
        $done = $this->postJson(self::FA."/assets/{$draft['id']}/capitalize")->assertOk()->json();

        $this->assertSame(['ACTIVE', $this->account($this->tenant, '1220')->id], [$done['status'], $done['asset_account_id']], 'the asset account is the one the invoice posted to');
        $this->assertNull($this->assetRow($draft['id'])->capitalization_journal_id);
        $this->assertEquals($before, $this->glFigures($this->tenant), 'the invoice already posted the cost: registering posts nothing');
        $this->assertSame(0, DB::table('accounting_events')->where('source_type', 'fixed_asset')->count());
        $this->assertSame(12, DB::table('asset_depreciation_schedules')->where('fixed_asset_id', $draft['id'])->count());

        // The register covers 12,000,000 of the 20,000,000 line; the rest is a visible difference, not an adjustment.
        $report = $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-03-31')->json();
        $row = collect($report['cost'])->firstWhere('account.code', '1220');
        $this->assertSame(['12000000.0000', '20000000.0000', '8000000.0000'], [$row['register'], $row['ledger'], $row['difference']]);
    }

    public function test_assets_registered_from_one_line_can_never_exceed_the_line(): void
    {
        ['line' => $line] = $this->assetInvoice();
        $category = $this->newCategory();
        $this->postJson(self::FA.'/assets', $this->registerBody($category, $line))->assertCreated();

        $this->postJson(self::FA.'/assets', $this->registerBody($category, $line, ['acquisition_cost' => '8000001', 'name' => 'Terlalu besar']))
            ->assertStatus(409)->assertJsonPath('code', 'ASSET_SOURCE_EXCEEDED')->assertJsonPath('details.already_registered', '12000000.0000');
        $this->postJson(self::FA.'/assets', $this->registerBody($category, $line, ['acquisition_cost' => '8000000', 'name' => 'Pas']))->assertCreated();
        $this->assertSame(2, DB::table('fixed_assets')->where('source_id', $line)->count());
    }

    public function test_the_invoice_line_must_exist_be_posted_and_name_an_asset_account(): void
    {
        $category = $this->newCategory();
        $this->postJson(self::FA.'/assets', $this->registerBody($category, '00000000-0000-4000-8000-000000000000'))->assertStatus(422)->assertJsonPath('code', 'ASSET_SOURCE_NOT_FOUND');

        ['line' => $draftLine] = $this->assetInvoice(post: false);
        $this->postJson(self::FA.'/assets', $this->registerBody($category, $draftLine))->assertStatus(409)->assertJsonPath('code', 'ASSET_SOURCE_NOT_POSTED');

        $vendor = $this->vendor($this->tenant, 'V2');
        $plain = $this->postedInvoice($vendor);
        $plainLine = (string) DB::table('ap_invoice_lines')->where('ap_invoice_id', $plain['id'])->value('id');
        $code = $this->postJson(self::FA.'/assets', $this->registerBody($category, $plainLine))->assertStatus(422)->json('code');
        $this->assertContains($code, ['ASSET_SOURCE_ACCOUNT_MISSING', 'ACCOUNT_TYPE_INVALID']);

        $body = $this->registerBody($category, $draftLine);
        unset($body['ap_invoice_line_id']);
        $this->postJson(self::FA.'/assets', $body)->assertStatus(422)->assertJsonPath('code', 'ASSET_SOURCE_REQUIRED');
    }

    public function test_reversing_the_capitalization_of_a_registered_asset_frees_the_line_and_posts_nothing(): void
    {
        ['line' => $line] = $this->assetInvoice();
        $category = $this->newCategory();
        $asset = $this->postJson(self::FA.'/assets', $this->registerBody($category, $line))->assertCreated()->json();
        $this->postJson(self::FA."/assets/{$asset['id']}/capitalize")->assertOk();
        $before = $this->glFigures($this->tenant);

        $reversed = $this->postJson(self::FA."/assets/{$asset['id']}/reverse-capitalization", ['reason' => 'Salah daftar'])->assertOk()->json();

        $this->assertSame('INACTIVE', $reversed['status']);
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->postJson(self::FA.'/assets', $this->registerBody($category, $line, ['acquisition_cost' => '20000000', 'name' => 'Daftar ulang']))->assertCreated();
        $this->assertSame('0.0000', $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-03-31')->json('totals.register_cost'), 'a reversed registration and a draft are not on the books');
    }

    public function test_the_asset_rules_can_be_checked_and_applied_once_from_a_date(): void
    {
        $tenant = $this->payablesTenant('bravo');
        $this->signedIn($tenant);

        $status = $this->getJson(self::FA.'/asset-rules')->assertOk()->json();
        $this->assertSame(['ASSET_CAPITALIZED' => false, 'DEPRECIATION_RECOGNIZED' => false, 'ASSET_DISPOSED' => false], array_column($status['events'], 'ready', 'event_type'));
        $this->assertSame([], $status['unmapped_roles'], 'the chart of accounts template maps the asset roles');

        $applied = $this->postJson(self::FA.'/asset-rules/defaults', ['effective_from' => '2026-01-01'])->assertCreated()->json();
        $this->assertCount(3, $applied['created']);
        $this->assertSame([true, true, true], array_column($this->getJson(self::FA.'/asset-rules')->json('events'), 'ready'));

        $again = $this->postJson(self::FA.'/asset-rules/defaults', ['effective_from' => '2026-01-01'])->assertCreated()->json();
        $this->assertSame([], $again['created']);
        $this->assertSame(['ALREADY_PUBLISHED'], array_values(array_unique(array_column($again['skipped'], 'reason'))));
        $this->assertSame(3, DB::table('posting_rules')->where('tenant_id', $tenant->id)->whereIn('event_type', ['ASSET_CAPITALIZED', 'DEPRECIATION_RECOGNIZED', 'ASSET_DISPOSED'])->where('status', 'PUBLISHED')->count());
    }

    public function test_capitalization_is_refused_until_the_asset_rules_exist(): void
    {
        $tenant = $this->payablesTenant('bravo');
        $this->signedIn($tenant);
        $category = $this->newCategory();
        $asset = $this->newAsset($tenant, $category);
        $before = $this->glFigures($tenant);

        $this->postJson(self::FA."/assets/{$asset['id']}/capitalize")->assertStatus(422);

        $this->assertEquals($before, $this->glFigures($tenant));
        $this->assertSame('DRAFT', $this->assetRow($asset['id'])->status);
    }
}
