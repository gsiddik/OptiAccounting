<?php

namespace Tests\Feature\Closing;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/**
 * OA5: the OA1 period routes (periods/{p}/soft-close, close, open) cannot be used to get around the closing workflow of a tenant that has
 * ACCOUNTING_REPORTING, and behave exactly as before for a tenant that has not (spec 82).
 */
class LegacyPeriodRoutesTest extends TestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->closingTenant();
        $this->admin = $this->memberToken($this->tenant);
        $this->as($this->admin);
        $this->fakeReconciliations();
    }

    private function legacy(string $month, string $action): string
    {
        return self::CL.'/periods/'.$this->period($this->tenant, $month)->id."/{$action}";
    }

    private function events(): array
    {
        return DB::table('closing_events')->where('tenant_id', $this->tenant->id)->orderBy('created_at')->orderBy('id')->get()->map(fn ($e) => "{$e->event_type} {$e->from_status}>{$e->to_status}")->all();
    }

    private function audit(string $action): int
    {
        return DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', $action)->count();
    }

    // ------------------------------------------------------------------------------------------ a tenant with ACCOUNTING_REPORTING

    public function test_the_legacy_close_goes_through_the_closing_workflow_and_keeps_its_response_and_audit_action(): void
    {
        $period = $this->postJson($this->legacy('2026-03', 'close'), ['reason' => 'Penutupan lewat rute lama'])->assertOk()->json();

        $this->assertSame(['CLOSED', '2026-03'], [$period['status'], $period['code']]); // the same period JSON as before
        $this->assertNotNull($period['closed_at']);
        $this->assertSame(['HARD_CLOSE OPEN>CLOSED'], $this->events());
        $this->assertSame('Penutupan lewat rute lama', DB::table('closing_events')->value('reason'));
        $this->assertSame([1, 1], [$this->audit('accounting.period.status_changed'), $this->audit('accounting.period.hard_closed')]);
        $this->postJson($this->legacy('2026-03', 'close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_INVALID_TRANSITION');
    }

    public function test_the_legacy_close_cannot_bypass_the_readiness_checks(): void
    {
        $this->pendingJournal($this->tenant, '2026-03-10');
        $this->postJson($this->legacy('2026-03', 'close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_NOT_READY')->assertJsonPath('details.blocking.0.code', 'PENDING_JOURNALS');

        $this->fakeReconciliations([ReconciliationResult::AP_GL => ReconciliationResult::MISMATCH]);
        $this->postJson($this->legacy('2026-04', 'close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_NOT_READY')->assertJsonPath('details.blocking.0.code', 'AP_RECONCILIATION');

        $this->assertSame(['OPEN', 'OPEN'], [$this->periodStatus($this->tenant, '2026-03'), $this->periodStatus($this->tenant, '2026-04')]);
        $this->assertSame([], $this->events());
    }

    public function test_the_legacy_close_honours_the_closing_policy(): void
    {
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['require_soft_close_first' => true]])->assertOk();

        $this->postJson($this->legacy('2026-03', 'close'))->assertStatus(409)->assertJsonPath('code', 'SOFT_CLOSE_REQUIRED');
        $this->postJson($this->legacy('2026-03', 'soft-close'))->assertOk()->assertJsonPath('status', 'SOFT_CLOSED');
        $this->postJson($this->legacy('2026-03', 'close'))->assertOk()->assertJsonPath('status', 'CLOSED');
        $this->assertSame(['SOFT_CLOSE OPEN>SOFT_CLOSED', 'HARD_CLOSE SOFT_CLOSED>CLOSED'], $this->events());
    }

    public function test_the_legacy_routes_need_the_new_permission_as_well_as_the_old_one(): void
    {
        $old = $this->memberToken($this->tenant, ['accounting.period.view', 'accounting.period.manage', 'accounting.period.close']); // what the OA1 routes asked for
        $this->as($old);

        $this->postJson($this->legacy('2026-03', 'close'))->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED')->assertJsonPath('details.permission', 'accounting.period.hard_close');
        $this->postJson($this->legacy('2026-03', 'soft-close'))->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED')->assertJsonPath('details.permission', 'accounting.period.soft_close');
        $this->assertSame('OPEN', $this->periodStatus($this->tenant, '2026-03'));

        $this->as($this->memberToken($this->tenant, ['accounting.period.view', 'accounting.period.manage', 'accounting.period.close', 'accounting.period.soft_close', 'accounting.period.hard_close']));
        $this->postJson($this->legacy('2026-03', 'soft-close'))->assertOk();
        $this->postJson($this->legacy('2026-03', 'close'))->assertOk();
        $this->postJson($this->legacy('2026-03', 'open'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_INVALID_TRANSITION'); // a closed period is reopened through the controlled reopen only
        $this->assertSame('CLOSED', $this->periodStatus($this->tenant, '2026-03'));
    }

    public function test_the_legacy_step_back_from_a_soft_close_is_a_recorded_reopen(): void
    {
        $this->postJson($this->legacy('2026-03', 'soft-close'))->assertOk();
        $manager = $this->memberToken($this->tenant, ['accounting.period.view', 'accounting.period.manage', 'accounting.period.soft_close']);
        $this->as($manager)->postJson($this->legacy('2026-03', 'open'))->assertStatus(403)->assertJsonPath('details.permission', 'accounting.period.reopen');

        $this->as($this->admin)->postJson($this->legacy('2026-03', 'open'), ['reason' => 'Masih ada transaksi susulan'])->assertOk()->assertJsonPath('status', 'OPEN');

        $this->assertSame(['SOFT_CLOSE OPEN>SOFT_CLOSED', 'REOPEN SOFT_CLOSED>OPEN'], $this->events());
        $this->assertSame('Masih ada transaksi susulan', DB::table('closing_events')->where('event_type', 'REOPEN')->value('reason'));
        $this->postJson($this->legacy('2026-03', 'open'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_INVALID_TRANSITION');
    }

    public function test_opening_a_future_period_is_still_the_plain_oa1_move(): void
    {
        $this->forcePeriodStatus($this->tenant, '2026-12', 'FUTURE');

        $this->postJson($this->legacy('2026-12', 'open'))->assertOk()->assertJsonPath('status', 'OPEN');

        $this->assertSame([], $this->events());
        $this->assertSame(1, $this->audit('accounting.period.status_changed'));
    }

    public function test_the_legacy_routes_need_a_writable_reporting_module_and_a_whole_ledger_scope(): void
    {
        $this->setModuleState($this->tenant, 'ACCOUNTING_REPORTING', 'READ_ONLY');
        $this->as($this->admin);
        foreach (['close', 'soft-close'] as $action) { // the route gate is ACCOUNTING_CORE; the closing service adds the reporting module and names the reason the resolver gave
            $this->postJson($this->legacy('2026-03', $action))->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_AVAILABLE')->assertJsonPath('details.reason', 'MODULE_READ_ONLY');
        }
        $this->setModuleState($this->tenant, 'ACCOUNTING_REPORTING', 'ACTIVE');

        $branch = $this->branches($this->tenant, 'BR1')[0];
        $scoped = $this->scopedToken($this->tenant, ['accounting.period.view', 'accounting.period.manage', 'accounting.period.close', 'accounting.period.soft_close', 'accounting.period.hard_close'], 'BRANCH', $branch);
        $this->as($scoped)->postJson($this->legacy('2026-03', 'close'))->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        $this->assertSame('OPEN', $this->periodStatus($this->tenant, '2026-03'));
        $this->assertSame([], $this->events());
    }

    // ------------------------------------------------------------------------------------------ a tenant without the module

    public function test_a_tenant_without_the_reporting_module_keeps_the_oa1_behaviour_exactly(): void
    {
        $this->setModuleState($this->tenant, 'ACCOUNTING_REPORTING', 'DISABLED');
        $oldManager = $this->memberToken($this->tenant, ['accounting.period.view', 'accounting.period.manage', 'accounting.period.close']);
        $pending = $this->as($this->admin)->pendingJournal($this->tenant, '2026-03-10');

        // The OA1 refusal, with the OA1 code and no readiness evaluation.
        $this->as($oldManager)->postJson($this->legacy('2026-03', 'close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_HAS_PENDING_JOURNALS')->assertJsonPath('details.pending', 1);

        $this->as($this->admin)->postJson(self::CL."/journals/{$pending}/post")->assertOk();
        $this->as($oldManager)->postJson($this->legacy('2026-03', 'soft-close'))->assertOk()->assertJsonPath('status', 'SOFT_CLOSED'); // no new permission needed
        $this->postJson($this->legacy('2026-03', 'close'))->assertOk()->assertJsonPath('status', 'CLOSED');
        $this->postJson($this->legacy('2026-03', 'open'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_INVALID_TRANSITION');

        $this->assertSame([], $this->events(), 'no closing history without the module');
        $this->assertSame([2, 0, 0], [$this->audit('accounting.period.status_changed'), $this->audit('accounting.period.soft_closed'), $this->audit('accounting.period.hard_closed')]);
        $this->as($this->admin)->postJson(self::CL.'/closing/periods/'.$this->period($this->tenant, '2026-04')->id.'/hard-close')->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
    }

    public function test_a_tenant_whose_reporting_module_is_suspended_is_not_held_to_the_closing_workflow(): void
    {
        $this->setModuleState($this->tenant, 'ACCOUNTING_REPORTING', 'SUSPENDED');

        $this->postJson($this->legacy('2026-03', 'close'))->assertOk()->assertJsonPath('status', 'CLOSED');
        $this->assertSame([], $this->events());
    }
}
