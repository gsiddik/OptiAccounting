<?php

namespace Tests\Feature\Closing;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReconciliationFixtures;
use Tests\TestCase;

/**
 * OA5: the close readiness against the real reconciliation engine (the unit-level tests bind a fake). The data is real: a posted vendor invoice,
 * a stray journal on the payables control account, a capitalized asset with depreciation due.
 */
class CloseReadinessIntegrationTest extends TestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures, ReconciliationFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->closingTenant();
        $this->as($this->memberToken($this->tenant));
    }

    private function readiness(string $month = '2026-03'): array
    {
        return $this->getJson(self::CL.'/closing/periods/'.$this->period($this->tenant, $month)->id.'/readiness')->assertOk()->json();
    }

    public function test_a_tenant_without_subledger_data_is_ready_and_every_reconciliation_agrees(): void
    {
        $checks = $this->checksOf($this->readiness());

        $this->assertSame('READY', $this->readiness()['result']);
        foreach (['AP_RECONCILIATION', 'AR_RECONCILIATION', 'CASH_BANK_RECONCILIATION', 'FIXED_ASSET_RECONCILIATION', 'TAX_RECONCILIATION', 'FX_RECONCILIATION'] as $code) {
            $this->assertContains($checks[$code]['result'], ['PASS', 'SKIPPED'], "{$code}: ".json_encode($checks[$code]));
        }
    }

    public function test_a_payables_difference_found_by_the_engine_blocks_the_close_until_it_is_explained_away_or_downgraded(): void
    {
        $this->postedInvoice($this->vendor($this->tenant, 'V1'));
        $this->assertSame('PASS', $this->checksOf($this->readiness())['AP_RECONCILIATION']['result'], 'the invoice posted both sides');

        $this->strayLine($this->tenant, '2110', 'CREDIT', '5000', '2026-03-25'); // a journal on the control account that no invoice explains
        $ap = $this->checksOf($this->readiness())['AP_RECONCILIATION'];
        $this->assertSame(['BLOCK', 'FAIL'], [$ap['severity'], $ap['result']]);

        $this->postJson(self::CL.'/closing/periods/'.$this->period($this->tenant, '2026-03')->id.'/hard-close')->assertStatus(409)
            ->assertJsonPath('code', 'PERIOD_NOT_READY')->assertJsonPath('details.blocking.0.code', 'AP_RECONCILIATION');
        $this->assertSame('OPEN', $this->periodStatus($this->tenant, '2026-03'));

        $this->putJson(self::CL.'/closing/policy', ['settings' => ['reconciliation_tolerance' => '5000']])->assertOk();
        $this->assertSame('PASS', $this->checksOf($this->readiness())['AP_RECONCILIATION']['result'], 'a difference within the policy tolerance matches');
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['reconciliation_tolerance' => '0']])->assertOk();

        $this->putJson(self::CL.'/closing/policy', ['rules' => [['check_code' => 'AP_RECONCILIATION', 'severity' => 'WARN']]])->assertOk();
        $this->assertSame('WARN', $this->checksOf($this->readiness())['AP_RECONCILIATION']['result']);
        $this->postJson(self::CL.'/closing/periods/'.$this->period($this->tenant, '2026-03')->id.'/hard-close')->assertOk()->assertJsonPath('status', 'CLOSED');
    }

    public function test_the_asset_register_and_its_depreciation_are_read_through_the_engine_and_the_run_table(): void
    {
        $this->capitalizedAsset($this->tenant, $this->newCategory());
        $before = $this->checksOf($this->readiness());
        $this->assertSame('FAIL', $before['DEPRECIATION_COMPLETE']['result']);
        $this->assertSame('PASS', $before['FIXED_ASSET_RECONCILIATION']['result']);

        $this->postedRun($this->tenant, '2026-03');

        $after = $this->checksOf($this->readiness());
        $this->assertSame(['PASS', 'PASS', 'READY'], [$after['DEPRECIATION_COMPLETE']['result'], $after['FIXED_ASSET_RECONCILIATION']['result'], $this->readiness()['result']]);

        $this->strayLine($this->tenant, '1230', 'DEBIT', '9000', '2026-03-20', '1110'); // fixed assets moved in the ledger without the register
        $this->assertSame('FAIL', $this->checksOf($this->readiness())['FIXED_ASSET_RECONCILIATION']['result']);
        $this->assertSame(0, DB::table('closing_events')->count(), 'reading the readiness writes no history');
    }
}
