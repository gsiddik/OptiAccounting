<?php

namespace Tests\Feature\Currency;

use App\Domain\FixedAsset\Services\AssetSetupService;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/** OA4 cross-domain: foreign currency next to fixed assets and the ledger-wide proofs. */
class CrossDomainTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, PayablesFixtures, ReceivablesFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->fxTenant();
        $this->inTenant($this->tenant, fn () => app(AssetSetupService::class)->applyDefaults('2026-01-01'));
        $this->signedIn($this->tenant);
        $this->rate('USD', '15500', '2026-03-01');
    }

    public function test_the_cost_of_a_foreign_invoice_line_cannot_be_registered_as_an_asset(): void
    {
        $vendor = $this->vendor($this->tenant);
        $invoice = $this->usdInvoice($vendor, '1000.00', ['lines' => [['description' => 'Mesin impor', 'amount' => '1000.00', 'account_id' => $this->account($this->tenant, '1220')->id]]]);
        $line = (string) DB::table('ap_invoice_lines')->where('ap_invoice_id', $invoice['id'])->value('id');
        $category = $this->newCategory();

        $body = $this->assetBody($this->tenant, $category, ['capitalization_mode' => 'REGISTER_ONLY', 'ap_invoice_line_id' => $line, 'acquisition_cost' => '500']);
        unset($body['source_account_id']);
        $this->postJson(self::FA.'/assets', $body)->assertStatus(422)->assertJsonPath('code', 'ASSET_SOURCE_FOREIGN');
        $this->assertSame(0, DB::table('fixed_assets')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_an_asset_capitalized_with_a_source_account_stays_reconciled_beside_foreign_activity(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->rate('USD', '15800', '2026-03-15');
        $invoice = $this->usdInvoice($vendor, '1000.00');
        $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '1000.00'], ['currency' => 'USD']); // books a realised FX loss in the same ledger

        $this->capitalizedAsset($this->tenant, $this->newCategory());
        $report = $this->getJson(self::FA.'/asset-reconciliation?as_of=2026-03-31')->assertOk()->json();

        $this->assertTrue($report['reconciled']);
        $this->assertSame('0.0000', $report['totals']['difference']);
    }

    public function test_every_posted_journal_of_a_mixed_ledger_balances_in_functional_currency(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->rate('USD', '15800', '2026-03-15');
        $invoice = $this->usdInvoice($vendor, '750.00');
        $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '300.00'], ['currency' => 'USD']);
        $this->capitalizedAsset($this->tenant, $this->newCategory());

        $unbalanced = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('l.tenant_id', $this->tenant->id)->where('j.status', 'POSTED')->groupBy('l.journal_entry_id')
            ->havingRaw('sum(l.debit) <> sum(l.credit)')->select('l.journal_entry_id')->get()->count();

        $this->assertSame(0, $unbalanced);
    }
}
