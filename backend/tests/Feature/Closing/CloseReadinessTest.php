<?php

namespace Tests\Feature\Closing;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Reconciliation\Services\ReconciliationEngine;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA5 batch I: the close readiness service, check by check (OA5 spec 30, 31, 56; design doc 6). */
class CloseReadinessTest extends TestCase
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

    /** @return array<string,mixed> */
    private function readiness(string $month = '2026-03', string $target = 'HARD'): array
    {
        return $this->getJson(self::CL.'/closing/periods/'.$this->period($this->tenant, $month)->id."/readiness?target={$target}")->assertOk()->json();
    }

    private function policy(array $rules, array $settings = []): void
    {
        $this->putJson(self::CL.'/closing/policy', ['rules' => $rules, 'settings' => $settings])->assertOk();
    }

    public function test_a_clean_period_is_ready_and_every_check_carries_its_structure(): void
    {
        $this->postedJournal($this->tenant);

        $readiness = $this->readiness();

        $this->assertSame(['2026-03', 'HARD', 'READY', 0], [$readiness['period_code'], $readiness['target'], $readiness['result'], $readiness['blocking_count']]);
        $this->assertSame([
            'TRIAL_BALANCE', 'PENDING_JOURNALS', 'DRAFT_JOURNALS', 'PENDING_DOCUMENTS', 'AP_RECONCILIATION', 'AR_RECONCILIATION', 'CASH_BANK_RECONCILIATION',
            'FIXED_ASSET_RECONCILIATION', 'DEPRECIATION_COMPLETE', 'TAX_RECONCILIATION', 'FX_RECONCILIATION', 'PREVIOUS_PERIODS_CLOSED', 'REPORT_MAPPING', 'CHECKLIST',
        ], array_column($readiness['checks'], 'code'));
        foreach ($readiness['checks'] as $check) {
            $this->assertSame(['code', 'title', 'severity', 'result', 'message', 'details'], array_keys($check));
            $this->assertContains($check['severity'], ['BLOCK', 'WARN']);
            $this->assertContains($check['result'], ['PASS', 'FAIL', 'WARN', 'SKIPPED']);
        }

        $checks = $this->checksOf($readiness);
        $this->assertSame('PASS', $checks['TRIAL_BALANCE']['result']);
        $this->assertSame('100000.0000', $checks['TRIAL_BALANCE']['details']['total_debit']);
        $this->assertSame('0.0000', $checks['TRIAL_BALANCE']['details']['difference']);
        $this->assertSame('SKIPPED', $checks['REPORT_MAPPING']['result'], 'no report definition applies yet');
    }

    public function test_a_submitted_or_approved_journal_blocks_and_a_draft_journal_only_warns(): void
    {
        $this->pendingJournal($this->tenant, '2026-03-10');
        $this->pendingJournal($this->tenant, '2026-03-12', until: 'SUBMITTED');
        $this->draft($this->tenant, ['document_date' => '2026-03-15', 'posting_date' => '2026-03-15']);
        $this->pendingJournal($this->tenant, '2026-04-02'); // another period: does not count

        $readiness = $this->readiness();
        $checks = $this->checksOf($readiness);

        $this->assertSame(['NOT_READY', 1], [$readiness['result'], $readiness['blocking_count']]);
        $this->assertSame(['BLOCK', 'FAIL', 2], [$checks['PENDING_JOURNALS']['severity'], $checks['PENDING_JOURNALS']['result'], $checks['PENDING_JOURNALS']['details']['count']]);
        $this->assertSame(['APPROVED' => 1, 'SUBMITTED' => 1], $checks['PENDING_JOURNALS']['details']['by_status']);
        $this->assertCount(2, $checks['PENDING_JOURNALS']['details']['sample']);
        $this->assertSame(['WARN', 'WARN', 1], [$checks['DRAFT_JOURNALS']['severity'], $checks['DRAFT_JOURNALS']['result'], $checks['DRAFT_JOURNALS']['details']['count']]);
        $this->assertSame('PASS', $this->checksOf($this->readiness('2026-02'))['PENDING_JOURNALS']['result']);
    }

    public function test_a_draft_journal_alone_never_stops_a_close(): void
    {
        $this->draft($this->tenant);

        $readiness = $this->readiness();

        $this->assertSame('READY', $readiness['result']);
        $this->assertSame('WARN', $this->checksOf($readiness)['DRAFT_JOURNALS']['result']);
        $this->assertContains('DRAFT_JOURNALS', array_column(array_filter($readiness['checks'], fn ($c) => $c['result'] === 'WARN'), 'code'));
    }

    public function test_a_reconciliation_mismatch_blocks_by_default_and_only_warns_when_the_policy_says_warn(): void
    {
        $this->fakeReconciliations([ReconciliationResult::AP_GL => ReconciliationResult::MISMATCH]);

        $blocked = $this->readiness();
        $check = $this->checksOf($blocked)['AP_RECONCILIATION'];
        $this->assertSame(['NOT_READY', 'BLOCK', 'FAIL'], [$blocked['result'], $check['severity'], $check['result']]);
        $this->assertSame(['MISMATCH', '125.0000'], [$check['details']['status'], $check['details']['difference']]);

        $this->policy([['check_code' => 'AP_RECONCILIATION', 'severity' => 'WARN']]);
        $warned = $this->readiness();
        $this->assertSame(['READY', 'WARN', 'WARN'], [$warned['result'], $this->checksOf($warned)['AP_RECONCILIATION']['severity'], $this->checksOf($warned)['AP_RECONCILIATION']['result']]);

        $this->policy([['check_code' => 'AP_RECONCILIATION', 'severity' => 'OFF']]);
        $off = $this->checksOf($this->readiness())['AP_RECONCILIATION'];
        $this->assertSame(['OFF', 'SKIPPED'], [$off['severity'], $off['result']]);
    }

    public function test_an_incomplete_reconciliation_never_passes_a_blocking_check(): void
    {
        $this->fakeReconciliations([ReconciliationResult::CASH_BANK_GL => ReconciliationResult::INCOMPLETE]);

        $readiness = $this->readiness();

        $this->assertSame('NOT_READY', $readiness['result']);
        $this->assertSame('FAIL', $this->checksOf($readiness)['CASH_BANK_RECONCILIATION']['result']);
    }

    public function test_a_not_applicable_reconciliation_is_skipped_and_never_counts(): void
    {
        $this->fakeReconciliations([ReconciliationResult::TAX_GL => ReconciliationResult::NOT_APPLICABLE]);

        $readiness = $this->readiness();

        $this->assertSame('SKIPPED', $this->checksOf($readiness)['TAX_RECONCILIATION']['result']);
        $this->assertSame('READY', $readiness['result']);
    }

    public function test_a_module_the_tenant_is_not_entitled_to_is_skipped_and_never_asked(): void
    {
        $this->app->instance(ReconciliationEngine::class, new class extends ReconciliationEngine
        {
            public function __construct() {}

            public function compute(string $type, string $asOf): ReconciliationResult
            {
                if (in_array($type, [ReconciliationResult::FIXED_ASSET_GL, ReconciliationResult::TAX_GL], true)) {
                    throw new \LogicException("{$type} must not be asked for a tenant without its module");
                }

                return new ReconciliationResult($type, $asOf, ReconciliationResult::MATCHED, '0.0000', '0.0000', '0.0000');
            }
        });
        $this->setModuleState($this->tenant, 'ACCOUNTING_FIXED_ASSET', 'DISABLED');
        $this->setModuleState($this->tenant, 'ACCOUNTING_TAX', 'DISABLED');

        $checks = $this->checksOf($this->readiness());

        foreach (['FIXED_ASSET_RECONCILIATION', 'DEPRECIATION_COMPLETE', 'TAX_RECONCILIATION'] as $code) {
            $this->assertSame('SKIPPED', $checks[$code]['result'], $code);
        }
        $this->assertSame('PASS', $checks['AP_RECONCILIATION']['result']);
    }

    public function test_depreciation_that_is_due_but_not_posted_blocks_until_the_run_is_posted(): void
    {
        $this->capitalizedAsset($this->tenant, $this->newCategory());

        $blocked = $this->checksOf($this->readiness())['DEPRECIATION_COMPLETE'];
        $this->assertSame(['BLOCK', 'FAIL', 1, 1, '1000000.0000'], [$blocked['severity'], $blocked['result'], $blocked['details']['months'], $blocked['details']['assets'], $blocked['details']['amount']]);
        $this->assertSame('PASS', $this->checksOf($this->readiness('2026-02'))['DEPRECIATION_COMPLETE']['result'], 'the asset is not capitalized yet in February');

        $run = $this->newRun($this->tenant, '2026-03'); // a calculated draft still holds the month
        $this->assertSame('FAIL', $this->checksOf($this->readiness())['DEPRECIATION_COMPLETE']['result']);

        $this->postJson(self::FA."/depreciation-runs/{$run['id']}/post")->assertOk();
        $this->assertSame('PASS', $this->checksOf($this->readiness())['DEPRECIATION_COMPLETE']['result']);
    }

    public function test_source_documents_that_wait_for_posting_warn_for_the_active_modules_only(): void
    {
        $vendor = $this->vendor($this->tenant);
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();

        $check = $this->checksOf($this->readiness())['PENDING_DOCUMENTS'];
        $this->assertSame(['WARN', 'WARN', 1, ['AP_INVOICE' => 1]], [$check['severity'], $check['result'], $check['details']['count'], $check['details']['by_document']]);

        $this->setModuleState($this->tenant, 'ACCOUNTING_AP', 'DISABLED');
        $this->assertSame('PASS', $this->checksOf($this->readiness())['PENDING_DOCUMENTS']['result'], 'a module the tenant lost is not asked');
    }

    public function test_earlier_periods_that_are_not_closed_warn_and_stop_warning_once_closed(): void
    {
        $april = $this->checksOf($this->readiness('2026-04'))['PREVIOUS_PERIODS_CLOSED'];
        $this->assertSame(['WARN', 'WARN', 3], [$april['severity'], $april['result'], $april['details']['count']]);
        $this->assertSame('PASS', $this->checksOf($this->readiness('2026-01'))['PREVIOUS_PERIODS_CLOSED']['result']);

        foreach (['2026-01', '2026-02', '2026-03'] as $month) {
            $this->hardClose($this->tenant, $month);
        }
        $this->assertSame('PASS', $this->checksOf($this->readiness('2026-04'))['PREVIOUS_PERIODS_CLOSED']['result']);
    }

    public function test_the_trial_balance_check_reads_posted_lines_only(): void
    {
        $this->postedJournal($this->tenant, ['posting_date' => '2026-03-05', 'document_date' => '2026-03-05']);
        $this->draft($this->tenant); // a draft is not in the ledger

        $details = $this->checksOf($this->readiness())['TRIAL_BALANCE']['details'];

        $this->assertSame(['100000.0000', '100000.0000', '100000.0000', '100000.0000', '0.0000'], [
            $details['total_debit'], $details['total_credit'], $details['period_debit'], $details['period_credit'], $details['period_difference'],
        ]);
        $this->assertSame('2026-03-31', $details['as_of']);
    }

    /** A published BALANCE_SHEET definition; with $mappedAccount that header (and everything below it) is mapped to its one line. */
    private function publishedBalanceSheet(?string $mappedAccount = null): void
    {
        $definition = (string) Str::uuid();
        DB::table('report_definitions')->insert([
            'id' => $definition, 'tenant_id' => $this->tenant->id, 'statement_type' => 'BALANCE_SHEET', 'code' => 'BS', 'name' => 'Neraca', 'version' => 1, 'status' => 'DRAFT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($mappedAccount !== null) {
            $line = (string) Str::uuid();
            DB::table('report_lines')->insert(['id' => $line, 'tenant_id' => $this->tenant->id, 'report_definition_id' => $definition, 'code' => 'ASSETS', 'label' => 'Aset', 'line_type' => 'ACCOUNT_GROUP', 'balance_side' => 'DEBIT', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('report_line_mappings')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'report_definition_id' => $definition, 'report_line_id' => $line,
                'account_id' => $this->account($this->tenant, $mappedAccount)->id, 'include_descendants' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('report_definitions')->where('id', $definition)->update(['status' => 'ACTIVE']);
    }

    public function test_a_report_definition_that_leaves_a_balance_unmapped_warns(): void
    {
        $this->postedJournal($this->tenant, ['posting_date' => '2026-03-05', 'document_date' => '2026-03-05']);
        $this->publishedBalanceSheet();

        $check = $this->checksOf($this->readiness())['REPORT_MAPPING'];

        $this->assertSame(['WARN', 'WARN', 1], [$check['severity'], $check['result'], $check['details']['unmapped_count']]);
        $this->assertSame('1110', $check['details']['sample'][0]['account_code'], 'cash is a balance sheet account; revenue belongs to the P&L definition that does not exist');
    }

    public function test_a_mapped_header_account_covers_the_accounts_below_it(): void
    {
        $this->postedJournal($this->tenant, ['posting_date' => '2026-03-05', 'document_date' => '2026-03-05']);
        $this->publishedBalanceSheet(mappedAccount: '1000');

        $check = $this->checksOf($this->readiness())['REPORT_MAPPING'];

        $this->assertSame(['PASS', 0], [$check['result'], $check['details']['unmapped_count']]);
    }

    public function test_a_soft_close_target_does_not_gate_on_the_manual_checklist(): void
    {
        $this->postJson(self::CL.'/closing/checklist-templates', ['code' => 'REVIEW', 'title' => 'Tinjau akrual', 'required' => true])->assertCreated();

        $hard = $this->checksOf($this->readiness(target: 'HARD'))['CHECKLIST'];
        $soft = $this->checksOf($this->readiness(target: 'SOFT'))['CHECKLIST'];

        $this->assertSame(['BLOCK', 'FAIL'], [$hard['severity'], $hard['result']]);
        $this->assertSame(['WARN', 'WARN'], [$soft['severity'], $soft['result']]);
    }

    public function test_readiness_is_computed_live_from_the_ledger_not_remembered(): void
    {
        $id = $this->pendingJournal($this->tenant, '2026-03-10');
        $this->assertSame('NOT_READY', $this->readiness()['result']);

        $this->postJson(self::CL."/journals/{$id}/post")->assertOk();

        $this->assertSame('READY', $this->readiness()['result']);
        $this->assertSame(0, DB::table('closing_events')->count(), 'viewing readiness writes no history');
    }
}
