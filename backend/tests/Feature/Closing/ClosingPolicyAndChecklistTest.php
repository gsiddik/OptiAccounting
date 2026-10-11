<?php

namespace Tests\Feature\Closing;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA5 batch I: the data-driven closing policy (with its locked checks) and the closing checklist (spec 32, 56, 57, 62). */
class ClosingPolicyAndChecklistTest extends TestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->closingTenant();
        $this->signedIn($this->tenant);
        $this->fakeReconciliations();
    }

    private function march(): string
    {
        return $this->period($this->tenant, '2026-03')->id;
    }

    private function checklist(): array
    {
        return $this->getJson(self::CL.'/closing/periods/'.$this->march().'/checklist')->assertOk()->json();
    }

    private function item(string $code): array
    {
        return array_column($this->checklist()['manual_items'], null, 'code')[$code];
    }

    private function template(string $code = 'REVIEW', array $override = []): array
    {
        return $this->postJson(self::CL.'/closing/checklist-templates', $override + ['code' => $code, 'title' => 'Tinjau akrual', 'required' => true])->assertCreated()->json();
    }

    // ------------------------------------------------------------------------------------------ policy

    public function test_without_any_row_the_policy_is_the_documented_default(): void
    {
        $policy = $this->getJson(self::CL.'/closing/policy')->assertOk()->json();
        $severity = array_column($policy['checks'], 'severity', 'check_code');

        $this->assertSame([
            'TRIAL_BALANCE' => 'BLOCK', 'PENDING_JOURNALS' => 'BLOCK', 'DRAFT_JOURNALS' => 'WARN', 'PENDING_DOCUMENTS' => 'WARN', 'AP_RECONCILIATION' => 'BLOCK', 'AR_RECONCILIATION' => 'BLOCK',
            'CASH_BANK_RECONCILIATION' => 'BLOCK', 'FIXED_ASSET_RECONCILIATION' => 'BLOCK', 'DEPRECIATION_COMPLETE' => 'BLOCK', 'TAX_RECONCILIATION' => 'WARN', 'FX_RECONCILIATION' => 'WARN',
            'PREVIOUS_PERIODS_CLOSED' => 'WARN', 'REPORT_MAPPING' => 'WARN', 'CHECKLIST' => 'BLOCK',
        ], $severity);
        $this->assertSame(['TRIAL_BALANCE', 'PENDING_JOURNALS', 'CHECKLIST'], array_column(array_filter($policy['checks'], fn ($c) => $c['locked']), 'check_code'));
        $this->assertSame([
            'reconciliation_tolerance' => '0.0000', 'reopen_max_periods_back' => null, 'reopen_requires_later_open' => true, 'separate_closer_and_reopener' => false, 'require_soft_close_first' => false,
        ], $policy['settings']);
        $this->assertSame(0, DB::table('closing_policy_rules')->count());
        $this->assertSame(0, DB::table('closing_settings')->count(), 'reading the policy stores nothing');
    }

    public function test_a_change_is_stored_audited_with_old_and_new_and_a_reset_removes_the_row(): void
    {
        $this->putJson(self::CL.'/closing/policy', [
            'rules' => [['check_code' => 'AP_RECONCILIATION', 'severity' => 'WARN'], ['check_code' => 'DRAFT_JOURNALS', 'severity' => 'BLOCK']],
            'settings' => ['reconciliation_tolerance' => '10', 'reopen_max_periods_back' => 2, 'separate_closer_and_reopener' => true, 'require_soft_close_first' => true],
        ])->assertOk();

        $policy = $this->getJson(self::CL.'/closing/policy')->json();
        $severity = array_column($policy['checks'], 'severity', 'check_code');
        $this->assertSame(['WARN', 'BLOCK'], [$severity['AP_RECONCILIATION'], $severity['DRAFT_JOURNALS']]);
        $this->assertSame(['10.0000', 2, true, true, true], [
            $policy['settings']['reconciliation_tolerance'], $policy['settings']['reopen_max_periods_back'], $policy['settings']['separate_closer_and_reopener'],
            $policy['settings']['require_soft_close_first'], $policy['settings']['reopen_requires_later_open'],
        ]);

        $log = DB::table('audit_logs')->where('action', 'accounting.closing.policy.updated')->latest('occurred_at')->first();
        $changes = json_decode($log->changes, true);
        $this->assertSame('BLOCK', $changes['before']['severities']['AP_RECONCILIATION']);
        $this->assertSame('WARN', $changes['after']['severities']['AP_RECONCILIATION']);
        $this->assertSame('0.0000', $changes['before']['settings']['reconciliation_tolerance']);
        $this->assertSame('10.0000', $changes['after']['settings']['reconciliation_tolerance']);

        // Back to the default severity: the override row disappears (absent row = code default).
        $this->putJson(self::CL.'/closing/policy', ['rules' => [['check_code' => 'AP_RECONCILIATION', 'severity' => 'BLOCK']]])->assertOk();
        $this->assertSame(['DRAFT_JOURNALS'], DB::table('closing_policy_rules')->pluck('check_code')->all());
    }

    public function test_an_unchanged_policy_writes_no_audit_row(): void
    {
        $this->putJson(self::CL.'/closing/policy', ['rules' => [['check_code' => 'AP_RECONCILIATION', 'severity' => 'BLOCK']], 'settings' => []])->assertOk();

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'accounting.closing.policy.updated')->count());
    }

    public function test_the_locked_checks_cannot_be_relaxed_by_any_row(): void
    {
        foreach (['TRIAL_BALANCE', 'PENDING_JOURNALS', 'CHECKLIST'] as $code) {
            foreach (['WARN', 'OFF'] as $severity) {
                $this->putJson(self::CL.'/closing/policy', ['rules' => [['check_code' => $code, 'severity' => $severity]]])
                    ->assertStatus(422)->assertJsonPath('code', 'CLOSING_CHECK_LOCKED');
            }
        }
        $this->putJson(self::CL.'/closing/policy', ['rules' => [['check_code' => 'PENDING_JOURNALS', 'severity' => 'BLOCK']]])->assertOk();
        $this->assertSame(0, DB::table('closing_policy_rules')->count());

        // Even a row written behind the service's back does not relax a locked check.
        DB::table('closing_policy_rules')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'check_code' => 'PENDING_JOURNALS', 'severity' => 'OFF', 'created_at' => now(), 'updated_at' => now()]);
        $this->pendingJournal($this->tenant, '2026-03-10');
        $readiness = $this->getJson(self::CL.'/closing/periods/'.$this->march().'/readiness')->assertOk()->json();
        $this->assertSame(['BLOCK', 'FAIL'], [$this->checksOf($readiness)['PENDING_JOURNALS']['severity'], $this->checksOf($readiness)['PENDING_JOURNALS']['result']]);
    }

    public function test_an_unknown_check_a_bad_severity_and_bad_settings_are_refused(): void
    {
        $this->putJson(self::CL.'/closing/policy', ['rules' => [['check_code' => 'NO_SUCH_CHECK', 'severity' => 'WARN']]])->assertStatus(422)->assertJsonPath('code', 'CLOSING_CHECK_UNKNOWN');
        $this->putJson(self::CL.'/closing/policy', ['rules' => [['check_code' => 'AP_RECONCILIATION', 'severity' => 'MAYBE']]])->assertStatus(422)->assertJsonPath('code', 'CLOSING_SEVERITY_INVALID');
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['reconciliation_tolerance' => '-1']])->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['reopen_max_periods_back' => 'x']])->assertStatus(422);
        $this->assertSame(0, DB::table('closing_policy_rules')->count() + DB::table('closing_settings')->count());
    }

    // ------------------------------------------------------------------------------------------ checklist

    public function test_templates_are_managed_by_code_and_a_system_check_code_is_reserved(): void
    {
        $this->template('review', ['title' => 'Tinjau akrual']);
        $this->assertSame(['REVIEW'], array_column($this->getJson(self::CL.'/closing/checklist-templates')->json('data'), 'code'), 'codes are stored upper case');

        $this->postJson(self::CL.'/closing/checklist-templates', ['code' => 'REVIEW', 'title' => 'x'])->assertStatus(422)->assertJsonPath('code', 'CHECKLIST_CODE_TAKEN');
        $this->postJson(self::CL.'/closing/checklist-templates', ['code' => 'AP_RECONCILIATION', 'title' => 'x'])->assertStatus(422)->assertJsonPath('code', 'CHECKLIST_CODE_RESERVED');
        $this->postJson(self::CL.'/closing/checklist-templates', ['code' => 'bad code!', 'title' => 'x'])->assertStatus(422);

        $id = DB::table('closing_checklist_templates')->value('id');
        $this->patchJson(self::CL."/closing/checklist-templates/{$id}", ['title' => 'Tinjau akrual dan cadangan', 'required' => false, 'code' => 'OTHER'])->assertOk()
            ->assertJsonPath('title', 'Tinjau akrual dan cadangan')->assertJsonPath('required', false)->assertJsonPath('code', 'REVIEW');
        $this->deleteJson(self::CL."/closing/checklist-templates/{$id}")->assertNoContent();
        $this->assertSame(0, DB::table('closing_checklist_templates')->count());
    }

    public function test_a_required_manual_item_blocks_the_close_until_completed_or_waived(): void
    {
        $this->template('REVIEW');
        $this->template('SIGNOFF', ['title' => 'Tanda tangan', 'sort_order' => 200]);
        $this->template('OPTIONAL', ['title' => 'Opsional', 'required' => false]);
        $readiness = fn () => $this->getJson(self::CL.'/closing/periods/'.$this->march().'/readiness')->assertOk()->json();

        $before = $this->checksOf($readiness())['CHECKLIST'];
        $this->assertSame(['BLOCK', 'FAIL', 2], [$before['severity'], $before['result'], $before['details']['pending_count']]);
        $this->assertSame(0, DB::table('closing_checklist_items')->count(), 'reading writes nothing: the items are virtual until someone acts');

        $this->postJson(self::CL.'/closing/periods/'.$this->march().'/checklist/REVIEW/complete', ['notes' => 'Selesai direview'])->assertOk()
            ->assertJsonPath('status', 'COMPLETED')->assertJsonPath('notes', 'Selesai direview');
        $this->assertSame(3, DB::table('closing_checklist_items')->count(), 'the first action generates the items of every active template');
        $this->assertSame(['FAIL', 1], [$this->checksOf($readiness())['CHECKLIST']['result'], $this->checksOf($readiness())['CHECKLIST']['details']['pending_count']]);

        $this->postJson(self::CL.'/closing/periods/'.$this->march().'/checklist/SIGNOFF/waive', ['reason' => 'Tidak diperlukan bulan ini'])->assertOk()->assertJsonPath('status', 'WAIVED');
        $this->assertSame('PASS', $this->checksOf($readiness())['CHECKLIST']['result']);
        $this->assertSame('READY', $readiness()['result']);
    }

    public function test_a_template_without_an_item_still_counts_as_pending_for_the_close(): void
    {
        $this->template('REVIEW');
        $this->postJson(self::CL.'/closing/periods/'.$this->march().'/checklist/REVIEW/complete')->assertOk();
        $this->template('LATE', ['title' => 'Ditambahkan kemudian']);

        $this->assertSame('FAIL', $this->checksOf($this->getJson(self::CL.'/closing/periods/'.$this->march().'/readiness')->json())['CHECKLIST']['result']);
        $this->postJson(self::CL.'/closing/periods/'.$this->march().'/hard-close'.'x')->assertNotFound();
        $this->postJson(self::CL.'/closing/periods/'.$this->march().'/hard-close')->assertStatus(409)->assertJsonPath('code', 'PERIOD_NOT_READY');
    }

    public function test_the_listing_shows_system_checks_read_only_next_to_the_manual_items(): void
    {
        $this->template('REVIEW');

        $checklist = $this->checklist();

        $this->assertSame('2026-03', $checklist['period']['code']);
        $this->assertContains('TRIAL_BALANCE', array_column($checklist['system_checks'], 'code'));
        $this->assertSame([false], array_values(array_unique(array_column($checklist['system_checks'], 'settable'))));
        $this->assertSame([['REVIEW', 'PENDING', false]], array_map(fn ($i) => [$i['code'], $i['status'], $i['generated']], $checklist['manual_items']));
        $this->assertSame(['required' => 1, 'pending_required' => 1, 'completed' => 0, 'waived' => 0], $checklist['summary']);
    }

    public function test_a_system_check_cannot_be_set_by_hand(): void
    {
        foreach (['complete', 'waive', 'reset'] as $action) {
            $this->postJson(self::CL.'/closing/periods/'.$this->march()."/checklist/AP_RECONCILIATION/{$action}", ['reason' => 'Dipaksa lolos'])
                ->assertStatus(422)->assertJsonPath('code', 'CHECKLIST_SYSTEM_CHECK');
        }
        $this->assertSame(0, DB::table('closing_checklist_items')->count());
    }

    public function test_a_waiver_needs_a_reason_and_is_audited_with_it(): void
    {
        $this->template('REVIEW');
        $url = self::CL.'/closing/periods/'.$this->march().'/checklist/REVIEW';

        $this->postJson("{$url}/waive", [])->assertStatus(422);
        $this->postJson("{$url}/waive", ['reason' => '   '])->assertStatus(422);
        $this->postJson("{$url}/waive", ['reason' => 'abc'])->assertStatus(422);
        $this->assertSame('PENDING', $this->item('REVIEW')['status']);

        $this->postJson("{$url}/waive", ['reason' => 'Tidak berlaku untuk periode ini'])->assertOk()->assertJsonPath('waive_reason', 'Tidak berlaku untuk periode ini');
        $log = DB::table('audit_logs')->where('action', 'accounting.closing.checklist.waived')->first();
        $this->assertSame('Tidak berlaku untuk periode ini', json_decode($log->changes, true)['after']['waive_reason']);
        $this->assertNotNull($log->actor_user_id);
    }

    public function test_an_item_moves_pending_completed_pending_and_never_skips_a_state(): void
    {
        $this->template('REVIEW');
        $url = self::CL.'/closing/periods/'.$this->march().'/checklist/REVIEW';

        $this->postJson("{$url}/reset")->assertStatus(409)->assertJsonPath('code', 'CHECKLIST_ITEM_PENDING');
        $this->postJson("{$url}/complete")->assertOk();
        $this->postJson("{$url}/complete")->assertStatus(409)->assertJsonPath('code', 'CHECKLIST_ITEM_NOT_PENDING');
        $this->postJson("{$url}/waive", ['reason' => 'Setelah selesai'])->assertStatus(409)->assertJsonPath('code', 'CHECKLIST_ITEM_NOT_PENDING');
        $this->postJson("{$url}/reset")->assertOk()->assertJsonPath('status', 'PENDING')->assertJsonPath('completed_by', null);
        $this->postJson(self::CL.'/closing/periods/'.$this->march().'/checklist/UNKNOWN/complete')->assertStatus(404)->assertJsonPath('code', 'CHECKLIST_ITEM_NOT_FOUND');

        $this->assertSame(['completed' => 1, 'reset' => 1], DB::table('audit_logs')->whereIn('action', ['accounting.closing.checklist.completed', 'accounting.closing.checklist.reset'])
            ->selectRaw('split_part(action, \'.\', 4) as a, count(*) as c')->groupBy('a')->pluck('c', 'a')->all());
    }

    public function test_the_checklist_of_a_closed_period_is_evidence_and_cannot_change(): void
    {
        $this->template('REVIEW');
        $url = self::CL.'/closing/periods/'.$this->march().'/checklist/REVIEW';
        $this->postJson("{$url}/complete")->assertOk();
        $this->hardClose($this->tenant, '2026-03');

        $this->postJson("{$url}/reset")->assertStatus(409)->assertJsonPath('code', 'CHECKLIST_PERIOD_CLOSED');
        $this->postJson("{$url}/complete")->assertStatus(409)->assertJsonPath('code', 'CHECKLIST_PERIOD_CLOSED');
        $this->postJson("{$url}/waive", ['reason' => 'Sudah ditutup'])->assertStatus(409)->assertJsonPath('code', 'CHECKLIST_PERIOD_CLOSED');
        $this->assertSame('COMPLETED', $this->item('REVIEW')['status']);

        $this->template('NEWER'); // a template added later does not appear in a closed period
        $this->assertSame(['REVIEW'], array_column($this->checklist()['manual_items'], 'code'));
    }

    public function test_a_template_that_produced_an_item_is_deactivated_not_deleted(): void
    {
        $template = $this->template('REVIEW');
        $this->postJson(self::CL.'/closing/periods/'.$this->march().'/checklist/REVIEW/complete')->assertOk();

        $this->deleteJson(self::CL."/closing/checklist-templates/{$template['id']}")->assertStatus(409)->assertJsonPath('code', 'CHECKLIST_TEMPLATE_IN_USE');
        $this->patchJson(self::CL."/closing/checklist-templates/{$template['id']}", ['status' => 'INACTIVE'])->assertOk();

        $april = $this->period($this->tenant, '2026-04')->id;
        $this->assertSame([], $this->getJson(self::CL."/closing/periods/{$april}/checklist")->json('manual_items'), 'an inactive template adds nothing to later periods');
        $this->assertSame('COMPLETED', $this->item('REVIEW')['status'], 'but the item already generated stays');
    }

    public function test_an_item_snapshots_its_template_so_a_later_edit_does_not_rewrite_it(): void
    {
        $template = $this->template('REVIEW', ['title' => 'Judul lama']);
        $this->postJson(self::CL.'/closing/periods/'.$this->march().'/checklist/REVIEW/complete')->assertOk();

        $this->patchJson(self::CL."/closing/checklist-templates/{$template['id']}", ['title' => 'Judul baru', 'required' => false])->assertOk();

        $this->assertSame(['Judul lama', true], [$this->item('REVIEW')['title'], $this->item('REVIEW')['required']]);
        $april = $this->period($this->tenant, '2026-04')->id;
        $this->assertSame('Judul baru', $this->getJson(self::CL."/closing/periods/{$april}/checklist")->json('manual_items.0.title'));
    }
}
