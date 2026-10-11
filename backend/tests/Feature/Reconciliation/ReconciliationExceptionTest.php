<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Payables\Services\ApReconciliationService;
use App\Domain\Reconciliation\Services\ReconciliationRunService;
use App\Domain\Shared\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\ReconciliationFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/**
 * OA5 batch F: runs as append-only evidence and the exception lifecycle. The calculated status moves only with a recomputation: explaining,
 * assigning or asking to resolve can never turn a mathematical difference into MATCHED.
 */
class ReconciliationExceptionTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reconTenant();
        $this->signedIn($this->tenant);
    }

    /** An AP subledger of 700.000 and, when asked, a stray credit of 5.000 on the control account: the AP reconciliation of 31 March differs by 5.000. */
    private function apWithStrayLine(bool $stray = true): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $vendor = $this->vendor($this->tenant, 'V1');
        $invoice = $this->postedInvoice($vendor);
        $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '300000']);
        if ($stray) {
            $this->strayLine($this->tenant, '2110', 'CREDIT', '5000', '2026-03-25');
        }
    }

    /** @return array<string,mixed> */
    private function runOf(string $slug = 'ap-gl', string $asOf = '2026-03-31', array $extra = []): array
    {
        return $this->postJson(self::RC."/reconciliations/{$slug}/run", ['as_of' => $asOf] + $extra)->assertCreated()->json();
    }

    private function audited(string $action): int
    {
        return DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', $action)->count();
    }

    public function test_a_run_keeps_evidence_opens_one_live_exception_and_refreshes_it_with_new_numbers(): void
    {
        $this->apWithStrayLine();

        $first = $this->runOf();
        $this->assertSame(['MISMATCH', 'MANUAL', '-5000.0000', true], [$first['run']['status'], $first['run']['purpose'], $first['run']['difference'], $first['run']['scope_complete']]);
        $this->assertSame($first['result']['difference'], $first['run']['difference']);
        $this->assertSame('SUBLEDGER_VS_GL', $first['run']['components'][0]['code']);
        $exception = $first['exception'];
        $this->assertSame(['AP_GL', 'AP_GL', '2026-03-31', 'OPEN', 'MISMATCH', '700000.0000', '705000.0000', '-5000.0000', null, $first['run']['id']], [
            $exception['type'], $exception['reconciliation_type'], $exception['as_of_date'], $exception['status'], $exception['calculated_status'], $exception['source_balance'],
            $exception['gl_balance'], $exception['difference'], $exception['period'], $exception['last_run_id'],
        ]);
        $this->assertSame([1, 1, 1], [$this->audited('accounting.reconciliation.run'), $this->audited('accounting.reconciliation.exception.opened'), DB::table('reconciliation_runs')->count()]);

        // A second difference and a second run: the same live exception, fresh numbers; the first run stays as it was.
        $this->strayLine($this->tenant, '2110', 'CREDIT', '2000', '2026-03-26');
        $second = $this->runOf();
        $this->assertSame($exception['id'], $second['exception']['id']);
        $this->assertSame(['-7000.0000', $second['run']['id'], 'OPEN'], [$second['exception']['difference'], $second['exception']['last_run_id'], $second['exception']['status']]);
        $this->assertSame(1, DB::table('reconciliation_exceptions')->count());
        $this->assertSame(['-5000.0000', '-7000.0000'], DB::table('reconciliation_runs')->orderBy('created_at')->orderBy('id')->pluck('difference')->map(fn ($d) => (string) $d)->sort()->values()->reverse()->values()->all());
        $this->assertSame([2, 1], [$this->audited('accounting.reconciliation.run'), $this->audited('accounting.reconciliation.exception.opened')]);

        $runs = $this->getJson(self::RC.'/reconciliations/runs?type=ap-gl&status=MISMATCH')->assertOk()->json('data');
        $this->assertCount(2, $runs);
        $this->assertSame('AP_GL', $runs[0]['type']);
        $this->getJson(self::RC.'/reconciliations/runs?status=MATCHED')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::RC.'/reconciliations/runs?type=nope')->assertStatus(422)->assertJsonPath('code', 'RECONCILIATION_TYPE_UNKNOWN');
    }

    public function test_a_run_for_a_period_names_its_end_date_and_the_period(): void
    {
        $this->apWithStrayLine();
        $period = $this->period($this->tenant, '2026-03');

        $run = $this->runOf(extra: ['period_id' => $period->id]);
        $this->assertSame(['2026-03-31', $period->id, '2026-03'], [$run['run']['as_of_date'], $run['run']['accounting_period_id'], $run['exception']['period']['code']]);
        $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['period_id' => $period->id, 'as_of' => '2026-03-30'])->assertStatus(422)->assertJsonPath('code', 'AS_OF_PERIOD_CONFLICT');
        $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['period_id' => '00000000-0000-4000-8000-000000000000'])->assertStatus(404)->assertJsonPath('code', 'PERIOD_NOT_FOUND');
        $this->assertSame('2026-03-31', $this->getJson(self::RC.'/reconciliations/ap-gl?period_id='.$period->id)->assertOk()->json('as_of'));
        $this->assertSame($this->tenant->businessDate(), $this->getJson(self::RC.'/reconciliations/ap-gl')->assertOk()->json('as_of'), 'no date given: the tenant business date, echoed');
    }

    public function test_explaining_and_assigning_never_change_the_calculated_status(): void
    {
        $this->apWithStrayLine();
        $id = $this->runOf()['exception']['id'];
        [$owner] = $this->member($this->tenant);

        $assigned = $this->postJson(self::RC."/reconciliation-exceptions/{$id}/assign", ['owner_id' => $owner->id])->assertOk()->json();
        $this->assertSame([$owner->id, $owner->name, 'OPEN'], [$assigned['owner_id'], $assigned['owner']['name'], $assigned['status']]);

        $explained = $this->postJson(self::RC."/reconciliation-exceptions/{$id}/explain", ['notes' => 'Jurnal manual salah akun, akan dikoreksi', 'status' => 'RESOLVED', 'calculated_status' => 'MATCHED', 'difference' => '0'])->assertOk()->json();
        $this->assertSame(['EXPLAINED', 'MISMATCH', '-5000.0000', 'Jurnal manual salah akun, akan dikoreksi'], [$explained['status'], $explained['calculated_status'], $explained['difference'], $explained['notes']]);
        $this->assertNotNull($explained['explained_by']);
        $this->assertNotNull($explained['explained_at']);
        $this->assertSame('MISMATCH', $this->reconResult('ap-gl', '2026-03-31')['status']); // the live computation does not care what anyone wrote

        $this->assertSame([1, 1], [$this->audited('accounting.reconciliation.exception.assigned'), $this->audited('accounting.reconciliation.exception.explained')]);
        $after = DB::table('reconciliation_exceptions')->where('id', $id)->first();
        $this->assertSame(['EXPLAINED', 'MISMATCH', '-5000.0000'], [$after->status, $after->calculated_status, (string) $after->difference]);
    }

    public function test_an_owner_must_be_an_active_member_of_this_organization(): void
    {
        $this->apWithStrayLine();
        $id = $this->runOf()['exception']['id'];
        $other = $this->accountingTenant('bravo');
        [$stranger] = $this->member($other);
        [$suspended, $membership] = $this->member($this->tenant);
        DB::table('tenant_users')->where('id', $membership->id)->update(['status' => 'SUSPENDED']);

        foreach ([$stranger->id, $suspended->id, '00000000-0000-4000-8000-000000000000'] as $owner) {
            $this->postJson(self::RC."/reconciliation-exceptions/{$id}/assign", ['owner_id' => $owner])->assertStatus(422)->assertJsonPath('code', 'RECON_OWNER_INVALID');
        }
        $this->postJson(self::RC."/reconciliation-exceptions/{$id}/assign", ['owner_id' => 'not-a-uuid'])->assertStatus(422);
        $this->assertNull(DB::table('reconciliation_exceptions')->where('id', $id)->value('owner_id'));
        $this->assertSame(0, $this->audited('accounting.reconciliation.exception.assigned'));
    }

    public function test_resolving_is_refused_while_the_recomputation_still_differs_and_accepted_once_it_matches(): void
    {
        $this->apWithStrayLine();
        $id = $this->runOf()['exception']['id'];
        $this->postJson(self::RC."/reconciliation-exceptions/{$id}/explain", ['notes' => 'Akan dikoreksi'])->assertOk();

        // A person asks to resolve: the recomputation still differs, so the answer is 409 and the exception stays open with the fresh numbers.
        $runs = DB::table('reconciliation_runs')->count();
        $this->postJson(self::RC."/reconciliation-exceptions/{$id}/resolve", ['note' => 'Sudah beres'])->assertStatus(409)->assertJsonPath('code', 'RECON_STILL_MISMATCHED')
            ->assertJsonPath('details.difference', '-5000.0000')->assertJsonPath('details.status', 'MISMATCH');
        $open = DB::table('reconciliation_exceptions')->where('id', $id)->first();
        $this->assertSame(['EXPLAINED', 'MISMATCH', null, null], [$open->status, $open->calculated_status, $open->resolved_at, $open->resolved_by]);
        $this->assertSame($runs + 1, DB::table('reconciliation_runs')->count(), 'the verification itself is kept as evidence');
        $this->assertSame(0, $this->audited('accounting.reconciliation.exception.resolved'));

        // The cause is corrected in the ledger (a reversing journal), then the same request is accepted.
        $this->strayLine($this->tenant, '2110', 'DEBIT', '5000', '2026-03-26');
        $resolved = $this->postJson(self::RC."/reconciliation-exceptions/{$id}/resolve", ['note' => 'Dikoreksi dengan jurnal balik'])->assertOk()->json();
        $user = DB::table('audit_logs')->where('action', 'accounting.reconciliation.exception.resolved')->first();
        $this->assertSame(['RESOLVED', 'MATCHED', '0.0000', 'Dikoreksi dengan jurnal balik'], [$resolved['status'], $resolved['calculated_status'], $resolved['difference'], $resolved['resolution_note']]);
        $this->assertNotNull($resolved['resolved_by']);
        $this->assertNotNull($resolved['resolved_at']);
        $this->assertSame($resolved['resolved_by'], $user->actor_user_id);
        $this->assertFalse(json_decode($user->changes, true)['after']['automatic']);

        // Resolved is history: nothing more to do on it, and a new difference of the same date opens a new exception.
        $this->postJson(self::RC."/reconciliation-exceptions/{$id}/resolve", ['note' => 'Lagi'])->assertStatus(409)->assertJsonPath('code', 'RECON_EXCEPTION_RESOLVED');
        $this->postJson(self::RC."/reconciliation-exceptions/{$id}/explain", ['notes' => 'Lagi'])->assertStatus(409)->assertJsonPath('code', 'RECON_EXCEPTION_RESOLVED');
        $this->strayLine($this->tenant, '2110', 'CREDIT', '900', '2026-03-27');
        $again = $this->runOf();
        $this->assertNotSame($id, $again['exception']['id']);
        $this->assertSame(['OPEN', '-900.0000'], [$again['exception']['status'], $again['exception']['difference']]);
        $this->assertSame(['RESOLVED', 'OPEN'], collect($this->getJson(self::RC.'/reconciliation-exceptions?type=ap-gl')->json('data'))->pluck('status')->sort()->reverse()->values()->all());
    }

    public function test_a_later_matching_run_resolves_the_live_exception_with_a_system_note(): void
    {
        $this->apWithStrayLine();
        $id = $this->runOf()['exception']['id'];
        $this->strayLine($this->tenant, '2110', 'DEBIT', '5000', '2026-03-26');

        $matched = $this->runOf();
        $this->assertSame(['MATCHED', $id, 'RESOLVED', 'MATCHED', null], [$matched['result']['status'], $matched['exception']['id'], $matched['exception']['status'], $matched['exception']['calculated_status'], $matched['exception']['resolved_by']]);
        $this->assertStringContainsString('Resolved automatically', $matched['exception']['resolution_note']);
        $audit = json_decode(DB::table('audit_logs')->where('action', 'accounting.reconciliation.exception.resolved')->value('changes'), true);
        $this->assertTrue($audit['after']['automatic']);

        // A matching run with no live exception opens nothing.
        $quiet = $this->runOf('ap-gl', '2026-03-15');
        $this->assertSame(['MATCHED', null], [$quiet['result']['status'], $quiet['exception']]);
        $this->assertSame(1, DB::table('reconciliation_exceptions')->count());
    }

    public function test_an_incomplete_comparison_opens_an_incomplete_exception_that_a_matching_run_resolves(): void
    {
        $this->mock(ApReconciliationService::class, fn ($mock) => $mock->shouldReceive('report')->andReturn([
            'as_of' => '2026-03-31', 'control_accounts' => [], 'gl_balance' => '0.0000', 'opening_balance_component' => '0.0000', 'gl_transactional_balance' => '0.0000',
            'subledger_balance' => '120000.0000', 'difference' => '-120000.0000', 'status' => 'MISMATCH', 'vendors' => [], 'complete' => true,
        ]));
        $result = $this->runOf();
        $this->assertSame(['INCOMPLETE', '120000.0000', null, null, false], [$result['result']['status'], $result['result']['source_balance'], $result['result']['gl_balance'], $result['result']['difference'], $result['result']['complete']]);
        $this->assertStringContainsString('no accounts payable control account is mapped', $result['result']['notes'][0]);
        $this->assertSame(['INCOMPLETE', 'OPEN'], [$result['exception']['calculated_status'], $result['exception']['status']]);
        $this->postJson(self::RC."/reconciliation-exceptions/{$result['exception']['id']}/resolve", ['note' => 'Sudah'])->assertStatus(409)->assertJsonPath('details.status', 'INCOMPLETE');
    }

    public function test_runs_are_append_only(): void
    {
        $this->apWithStrayLine();
        $run = $this->runOf()['run']['id'];

        foreach ([fn () => DB::table('reconciliation_runs')->where('id', $run)->update(['status' => 'MATCHED', 'difference' => 0]), fn () => DB::table('reconciliation_runs')->where('id', $run)->delete()] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('the database accepted a change to a reconciliation run');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame(['MISMATCH', '-5000.0000'], [DB::table('reconciliation_runs')->where('id', $run)->value('status'), (string) DB::table('reconciliation_runs')->where('id', $run)->value('difference')]);
    }

    public function test_the_database_keeps_one_live_exception_per_type_and_date(): void
    {
        $this->apWithStrayLine();
        $this->runOf();
        $row = (array) DB::table('reconciliation_exceptions')->first();
        $row['id'] = (string) Str::uuid();
        try {
            DB::transaction(fn () => DB::table('reconciliation_exceptions')->insert($row));
            $this->fail('two live exceptions of one type and date');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => DB::table('reconciliation_exceptions')->update(['status' => 'RESOLVED']));
            $this->fail('a resolved exception without a resolution time');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
    }

    public function test_a_narrower_data_scope_leaves_evidence_only_and_sees_no_amounts(): void
    {
        $this->apWithStrayLine();
        $live = $this->runOf()['exception'];
        [$branch] = $this->branches($this->tenant, 'JKT');
        $scoped = $this->scopedToken($this->tenant, ['accounting.reconciliation.view', 'accounting.reconciliation.run', 'accounting.reconciliation.exception.manage'], 'BRANCH', $branch);

        $run = $this->as($scoped)->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-03-31'])->assertCreated()->json();
        $this->assertSame(['INCOMPLETE', false, null, null], [$run['run']['status'], $run['run']['scope_complete'], $run['run']['difference'], $run['result']['difference']]);
        $this->assertSame($live['id'], $run['exception']['id']);
        $this->assertSame([null, null, null, 'MISMATCH'], [$run['exception']['source_balance'], $run['exception']['gl_balance'], $run['exception']['difference'], $run['exception']['calculated_status']]); // masked, and unchanged

        $stored = DB::table('reconciliation_exceptions')->where('id', $live['id'])->first();
        $this->assertSame(['MISMATCH', '-5000.0000'], [$stored->calculated_status, (string) $stored->difference]); // the narrower run did not overwrite the tenant-wide numbers
        $list = $this->getJson(self::RC.'/reconciliation-exceptions')->assertOk()->json('data.0');
        $this->assertSame([null, null, null], [$list['source_balance'], $list['gl_balance'], $list['difference']]);
        $runs = $this->getJson(self::RC.'/reconciliations/runs')->assertOk()->json('data');
        $this->assertSame([null, null], [$runs[0]['difference'], $runs[1]['components']]);
        $this->postJson(self::RC."/reconciliation-exceptions/{$live['id']}/resolve", ['note' => 'Coba'])->assertStatus(409)->assertJsonPath('code', 'RECON_STILL_MISMATCHED');

        $full = $this->as($this->memberToken($this->tenant))->getJson(self::RC.'/reconciliation-exceptions')->json('data.0');
        $this->assertSame('-5000.0000', $full['difference']);
    }

    public function test_exceptions_and_runs_of_another_tenant_do_not_exist_for_the_caller(): void
    {
        $this->apWithStrayLine();
        $mine = $this->runOf()['exception']['id'];

        $bravo = $this->reconTenant('bravo');
        $this->signedIn($bravo);
        $this->assertSame([], $this->getJson(self::RC.'/reconciliation-exceptions')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson(self::RC.'/reconciliations/runs')->assertOk()->json('data'));
        [$owner] = $this->member($bravo);
        foreach (['assign' => ['owner_id' => $owner->id], 'explain' => ['notes' => 'Bukan milik saya'], 'resolve' => ['note' => 'Bukan milik saya']] as $action => $body) {
            $this->postJson(self::RC."/reconciliation-exceptions/{$mine}/{$action}", $body)->assertStatus(404)->assertJsonPath('code', 'RECONCILIATION_EXCEPTION_NOT_FOUND');
        }
        $this->postJson(self::RC.'/reconciliation-exceptions/not-a-uuid/explain', ['notes' => 'xx yy'])->assertStatus(404);
        $row = DB::table('reconciliation_exceptions')->where('id', $mine)->first();
        $this->assertSame(['OPEN', null, null], [$row->status, $row->owner_id, $row->notes]);
        $this->assertSame(0, $this->audited('accounting.reconciliation.exception.explained'));
        // The comparison of bravo is its own: nothing of alpha's ledger appears.
        $this->assertSame(['MATCHED', '0.0000'], [$this->reconResult('ap-gl', '2026-03-31')['status'], $this->reconResult('ap-gl', '2026-03-31')['gl_balance']]);
    }

    public function test_the_run_purpose_is_validated_and_a_type_without_its_module_cannot_be_run(): void
    {
        $this->apWithStrayLine();
        $this->assertSame('MANUAL', $this->runOf()['run']['purpose']);
        $service = app(ReconciliationRunService::class);
        $this->expectExceptionObject(new DomainException('The purpose of a run must be MANUAL or CLOSE_CHECK.', 'RECONCILIATION_PURPOSE_INVALID', 422));
        $service->run('AP_GL', '2026-03-31', 'WHATEVER');
    }
}
