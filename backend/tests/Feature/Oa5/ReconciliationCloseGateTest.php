<?php

namespace Tests\Feature\Oa5;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\Oa5Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\ReconciliationFixtures;
use Tests\Support\ReportingFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/**
 * OA5 verification (spec 78, 81): a reconciliation is never a false match. What a person writes on an exception (an owner, an explanation, even a
 * forged status in the body) does not change the calculated status, and the period close follows the live recomputation: only a recomputed zero
 * difference, or a difference inside the tenant's own tolerance, lets it pass.
 */
class ReconciliationCloseGateTest extends TestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, Oa5Fixtures, PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, ReportingFixtures, TaxFixtures;

    private Tenant $tenant;

    private string $january;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->oa5Tenant();
        $this->marchDocuments($this->tenant);
        $this->publishedDefinitions($this->tenant);
        $this->strayLine($this->tenant, '2110', 'CREDIT', '5000', '2026-01-20');
        $this->january = $this->period($this->tenant, '2026-01')->id;
    }

    /** @return array<string,mixed> the AP reconciliation check of the January readiness */
    private function apCheck(): array
    {
        $readiness = $this->getJson(self::CL."/closing/periods/{$this->january}/readiness")->assertOk()->json();

        return $this->checksOf($readiness) + ['result' => $readiness['result']];
    }

    private function assertBlocked(string $why): void
    {
        $checks = $this->apCheck();
        $this->assertSame(['FAIL', 'NOT_READY'], [$checks['AP_RECONCILIATION']['result'], $checks['result']], $why);
        $this->postJson(self::CL."/closing/periods/{$this->january}/hard-close")->assertStatus(409)->assertJsonPath('code', 'PERIOD_NOT_READY');
        $this->assertSame('OPEN', $this->periodStatus($this->tenant, '2026-01'), $why);
    }

    private function tolerance(string $value): void
    {
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['reconciliation_tolerance' => $value]])->assertOk();
    }

    public function test_only_the_recomputed_difference_decides_the_close_never_an_owner_an_explanation_or_a_forged_status(): void
    {
        $this->assertBlocked('a stray ledger line on the AP control account');
        $run = $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-01-31'])->assertCreated()->json();
        $this->assertSame(['MISMATCH', '-5000.0000'], [$run['result']['status'], $run['result']['difference']]);
        $id = $run['exception']['id'];

        // An owner, an explanation and a body that claims the reconciliation matched: the exception is explained, the calculation is not.
        [$owner] = $this->member($this->tenant);
        $this->postJson(self::RC."/reconciliation-exceptions/{$id}/assign", ['owner_id' => $owner->id])->assertOk();
        $explained = $this->postJson(self::RC."/reconciliation-exceptions/{$id}/explain", [
            'notes' => 'Selisih sudah dijelaskan', 'status' => 'RESOLVED', 'calculated_status' => 'MATCHED', 'difference' => '0.0000', 'resolved_at' => now()->toIso8601String(),
        ])->assertOk()->json();
        $this->assertSame(['EXPLAINED', 'MISMATCH', '-5000.0000'], [$explained['status'], $explained['calculated_status'], $explained['difference']]);
        $this->assertSame('MISMATCH', $this->reconResult('ap-gl', '2026-01-31')['status']);
        $this->assertBlocked('an explained exception');

        // Resolving is a request to verify, not a statement: refused while the recomputation differs.
        $this->postJson(self::RC."/reconciliation-exceptions/{$id}/resolve", ['note' => 'Sudah beres'])->assertStatus(409)->assertJsonPath('code', 'RECON_STILL_MISMATCHED');
        $this->assertBlocked('a refused resolve');

        // A tolerance one unit below the difference is no match; exactly the difference is, and the difference stays visible. The check follows the policy live.
        $this->tolerance('4999.9999');
        $this->assertSame(['MISMATCH', '4999.9999'], [$this->reconResult('ap-gl', '2026-01-31')['status'], $this->reconResult('ap-gl', '2026-01-31')['tolerance']]);
        $this->assertBlocked('a tolerance below the difference');
        $this->tolerance('5000');
        $inside = $this->reconResult('ap-gl', '2026-01-31');
        $this->assertSame(['MATCHED', '5000.0000', '-5000.0000'], [$inside['status'], $inside['tolerance'], $inside['difference']]);
        $this->assertSame(['PASS', 'READY'], [$this->apCheck()['AP_RECONCILIATION']['result'], $this->apCheck()['result']]);
        $this->tolerance('0');
        $this->assertBlocked('the tolerance taken back');

        // The cause is corrected in the ledger: the recomputed zero is what resolves the exception and lets the period close.
        $this->strayLine($this->tenant, '2110', 'DEBIT', '5000', '2026-01-21');
        $this->assertSame(['PASS', 'READY'], [$this->apCheck()['AP_RECONCILIATION']['result'], $this->apCheck()['result']]);
        $resolved = $this->postJson(self::RC."/reconciliation-exceptions/{$id}/resolve", ['note' => 'Dikoreksi dengan jurnal balik'])->assertOk()->json();
        $this->assertSame(['RESOLVED', 'MATCHED', '0.0000'], [$resolved['status'], $resolved['calculated_status'], $resolved['difference']]);
        $this->hardClose($this->tenant, '2026-01');

        // The evidence of every run is still there, in order, and nothing of it was edited.
        $runs = DB::table('reconciliation_runs')->where('tenant_id', $this->tenant->id)->where('reconciliation_type', 'AP_GL')->orderBy('created_at')->orderBy('id')->get(['status', 'difference']);
        $this->assertSame('MISMATCH', $runs->first()->status);
        $this->assertSame('MATCHED', $runs->last()->status);
    }

    public function test_a_resolved_exception_does_not_hide_a_difference_that_comes_back(): void
    {
        $id = $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-01-31'])->assertCreated()->json('exception.id');
        $this->strayLine($this->tenant, '2110', 'DEBIT', '5000', '2026-01-21');
        $this->postJson(self::RC."/reconciliation-exceptions/{$id}/resolve", ['note' => 'Dikoreksi'])->assertOk()->assertJsonPath('status', 'RESOLVED');
        $this->assertSame('PASS', $this->apCheck()['AP_RECONCILIATION']['result']);

        $this->strayLine($this->tenant, '2110', 'CREDIT', '700', '2026-01-25');

        $this->assertBlocked('a new stray line after the exception was resolved');
        $again = $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-01-31'])->assertCreated()->json();
        $this->assertNotSame($id, $again['exception']['id']);
        $this->assertSame(['OPEN', '-700.0000'], [$again['exception']['status'], $again['exception']['difference']]);
        $this->assertSame('RESOLVED', DB::table('reconciliation_exceptions')->where('id', $id)->value('status'), 'the history of the first exception is kept');
    }
}
