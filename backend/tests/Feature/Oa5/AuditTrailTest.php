<?php

namespace Tests\Feature\Oa5;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
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
 * OA5 verification (spec 71): every sensitive action of the reporting, reconciliation and closing areas leaves an audit row with the tenant, the
 * actor, the resource and - where the action carries one - the reason; the closing history keeps the same actor and reason. One signed-in person
 * performs the whole list, so a missing or anonymous row cannot hide behind another test's data.
 */
class AuditTrailTest extends TestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, Oa5Fixtures, PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, ReportingFixtures, TaxFixtures;

    private Tenant $tenant;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->oa5Tenant();
        $this->marchDocuments($this->tenant);
        $this->publishedDefinitions($this->tenant);
        $this->strayLine($this->tenant, '2110', 'CREDIT', '5000', '2026-01-20');
        [$this->actor] = $this->member($this->tenant);
    }

    /** @return list<string> the ids of every audit row so far */
    private function auditIds(): array
    {
        return DB::table('audit_logs')->pluck('id')->all();
    }

    public function test_every_sensitive_action_is_audited_with_tenant_actor_resource_and_reason(): void
    {
        $known = $this->auditIds();
        $this->as($this->tenantToken($this->actor, $this->tenant));
        $january = $this->period($this->tenant, '2026-01')->id;
        $year = $this->fiscalYear($this->tenant, 'FY2026')->id;
        $reasons = ['soft' => 'Penutupan sementara Januari', 'hard' => 'Penutupan final Januari', 'reopen' => 'Koreksi pencatatan Januari', 'waive' => 'Tidak relevan bulan ini',
            'year' => 'Penutupan tahun buku 2026', 'reverse' => 'Koreksi klasifikasi pendapatan'];

        // Closing policy and checklist.
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['separate_closer_and_reopener' => false, 'reconciliation_tolerance' => '0']])->assertOk();
        $this->postJson(self::CL.'/closing/checklist-templates', ['code' => 'BANK', 'title' => 'Cek mutasi bank', 'required' => false])->assertCreated();
        $this->postJson(self::CL.'/closing/checklist-templates', ['code' => 'PAJAK', 'title' => 'Cek pajak', 'required' => false])->assertCreated();
        $this->postJson(self::CL."/closing/periods/{$january}/checklist/BANK/complete", ['notes' => 'Sesuai mutasi'])->assertOk();
        $this->postJson(self::CL."/closing/periods/{$january}/checklist/PAJAK/waive", ['reason' => $reasons['waive']])->assertOk();
        $this->postJson(self::CL."/closing/periods/{$january}/checklist/BANK/reset")->assertOk();

        // Reconciliation: run, exception, owner, explanation, correction, resolution, export.
        $exception = $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-01-31'])->assertCreated()->json('exception.id');
        $this->postJson(self::RC."/reconciliation-exceptions/{$exception}/assign", ['owner_id' => $this->actor->id])->assertOk();
        $this->postJson(self::RC."/reconciliation-exceptions/{$exception}/explain", ['notes' => 'Jurnal manual salah akun'])->assertOk();
        $this->strayLine($this->tenant, '2110', 'DEBIT', '5000', '2026-01-21');
        $this->as($this->tenantToken($this->actor, $this->tenant));
        $this->postJson(self::RC."/reconciliation-exceptions/{$exception}/resolve", ['note' => 'Dikoreksi dengan jurnal balik'])->assertOk();
        $this->get(self::RC.'/reconciliations/export?as_of=2026-01-31')->assertOk()->streamedContent();

        // Report definitions: copy, publish (archives the previous), a scratch draft with lines and mappings, its deletion, an export.
        $active = (string) DB::table('report_definitions')->where('tenant_id', $this->tenant->id)->where('statement_type', 'PROFIT_LOSS')->where('status', 'ACTIVE')->value('id');
        $draft = $this->postJson(self::RP."/report-definitions/{$active}/new-version")->assertCreated()->json('id');
        $this->postJson(self::RP."/report-definitions/{$draft}/publish", ['effective_from' => '2026-02-01'])->assertOk();
        $scratch = $this->postJson(self::RP.'/report-definitions', ['statement_type' => 'CASH_FLOW', 'name' => 'Arus kas uji'])->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$scratch}/lines", ['lines' => [['code' => 'OP', 'label' => 'Operasi', 'line_type' => 'ACCOUNT_GROUP', 'balance_side' => 'DEBIT', 'cash_flow_category' => 'OPERATING']]])->assertOk();
        $this->putJson(self::RP."/report-definitions/{$scratch}/mappings", ['mappings' => [['line_code' => 'OP', 'account_id' => $this->account($this->tenant, '2110')->id]]])->assertOk();
        $this->deleteJson(self::RP."/report-definitions/{$scratch}")->assertSuccessful();
        $this->get(self::RP.'/reports/profit-loss/export?from=2026-01-01&to=2026-03-31')->assertOk()->streamedContent();

        // Closing: pre-close, soft close, hard close, reopen.
        $this->postJson(self::CL."/closing/periods/{$january}/pre-close")->assertOk();
        $this->postJson(self::CL."/closing/periods/{$january}/soft-close", ['reason' => $reasons['soft']])->assertOk();
        $this->postJson(self::CL."/closing/periods/{$january}/hard-close", ['reason' => $reasons['hard']])->assertOk();
        $this->postJson(self::CL."/closing/periods/{$january}/reopen", ['reason' => $reasons['reopen'], 'target' => 'OPEN'])->assertOk();

        // Year end: run and reverse.
        $this->postJson(self::CL."/year-end/{$year}/run", ['reason' => $reasons['year']])->assertCreated();
        $this->postJson(self::CL."/year-end/{$year}/reverse", ['reason' => $reasons['reverse']])->assertOk();

        $rows = DB::table('audit_logs')->whereNotIn('id', $known)->where('tenant_id', $this->tenant->id)->orderBy('occurred_at')->get();
        $byAction = $rows->groupBy('action');

        // action => text the row must carry in its changes (null: the row only needs its actor and resource)
        $expected = [
            'accounting.closing.policy.updated' => null, 'accounting.closing.checklist.completed' => 'Sesuai mutasi', 'accounting.closing.checklist.waived' => $reasons['waive'], 'accounting.closing.checklist.reset' => null,
            'accounting.reconciliation.run' => null, 'accounting.reconciliation.exception.opened' => null, 'accounting.reconciliation.exception.assigned' => null,
            'accounting.reconciliation.exception.explained' => 'Jurnal manual salah akun', 'accounting.reconciliation.exception.resolved' => 'Dikoreksi dengan jurnal balik',
            'accounting.report.exported' => null, 'accounting.report_definition.created' => null, 'accounting.report_definition.updated' => null, 'accounting.report_definition.published' => null,
            'accounting.report_definition.archived' => null, 'accounting.report_definition.deleted' => null, 'accounting.report_mapping.changed' => null,
            'accounting.period.pre_closed' => null, 'accounting.period.soft_closed' => $reasons['soft'], 'accounting.period.hard_closed' => $reasons['hard'], 'accounting.period.reopened' => $reasons['reopen'],
            'accounting.year_end.run' => $reasons['year'], 'accounting.year_end.reversed' => $reasons['reverse'],
        ];
        foreach ($expected as $action => $text) {
            $this->assertTrue($byAction->has($action), "no audit row for {$action}");
            foreach ($byAction[$action] as $row) {
                $this->assertSame($this->actor->id, $row->actor_user_id, "{$action}: the actor");
                $this->assertNotEmpty($row->resource_type, "{$action}: the resource type");
                $this->assertNotEmpty($row->resource_id, "{$action}: the resource id");
            }
            if ($text !== null) {
                $this->assertStringContainsString($text, $byAction[$action]->pluck('changes')->implode(' '), "{$action}: the reason or note is in the trail");
            }
        }
        $this->assertGreaterThanOrEqual(2, $byAction['accounting.report.exported']->count(), 'both exports');
        $this->assertSame(0, $rows->whereNull('actor_user_id')->count(), 'no anonymous row among the actions of this person');

        // The append-only closing history carries the same actor and reasons.
        $events = DB::table('closing_events')->where('tenant_id', $this->tenant->id)->get()->groupBy('event_type');
        foreach (['PRE_CLOSE' => null, 'SOFT_CLOSE' => $reasons['soft'], 'HARD_CLOSE' => $reasons['hard'], 'REOPEN' => $reasons['reopen'], 'YEAR_END_RUN' => $reasons['year'], 'YEAR_END_REVERSE' => $reasons['reverse']] as $type => $reason) {
            $this->assertTrue($events->has($type), "no closing event {$type}");
            $this->assertSame($this->actor->id, $events[$type]->last()->actor_id, "{$type}: the actor");
            if ($reason !== null) {
                $this->assertSame($reason, $events[$type]->last()->reason, "{$type}: the reason");
            }
        }
    }
}
