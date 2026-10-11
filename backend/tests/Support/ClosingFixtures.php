<?php

namespace Tests\Support;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Closing\Services\YearEndSetupService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\Reconciliation\Services\ReconciliationEngine;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use Illuminate\Support\Facades\DB;

/**
 * Test helpers for the OA5 closing area (close readiness, period closing, year end); combine with AccountingFixtures, FixedAssetFixtures,
 * Fixtures and PayablesFixtures. Helpers that call the API act as the already-authenticated client.
 */
trait ClosingFixtures
{
    protected const CL = '/api/v1/app/accounting';

    /** A tenant with the OA2/OA3/OA4 rules, every module subscribed (ACCOUNTING_REPORTING included) and one person allowed to prepare, approve and post. */
    protected function closingTenant(string $code = 'alpha', array $profile = []): Tenant
    {
        return $this->assetTenant($code, $profile);
    }

    /** A closing tenant that also has the YEAR_END_CLOSING posting rule published from the start of its fiscal year (retained earnings = the template's 3200). */
    protected function yearEndTenant(string $code = 'alpha', array $profile = []): Tenant
    {
        $tenant = $this->closingTenant($code, $profile);
        $this->inTenant($tenant, fn () => app(YearEndSetupService::class)->applyDefaults('2026-01-01'));

        return $tenant;
    }

    /** Switch a module off for the tenant the way the entitlement sweep does (state change + cache invalidation). */
    protected function setModuleState(Tenant $tenant, string $module, string $state): void
    {
        DB::table('tenant_module_entitlements')->where('tenant_id', $tenant->id)->where('module_id', Module::query()->where('code', $module)->value('id'))->update(['state' => $state]);
        app(AccessCache::class)->touchTenant($tenant->id);
    }

    /**
     * Replace the reconciliation engine with one that answers from a table: type => status (default MATCHED). The readiness logic is then tested
     * on its own, and the real engine is exercised by the integration tests that do not call this.
     *
     * @param  array<string,string>  $statuses
     */
    protected function fakeReconciliations(array $statuses = []): void
    {
        $this->app->instance(ReconciliationEngine::class, new class($statuses) extends ReconciliationEngine
        {
            /** @param array<string,string> $statuses */
            public function __construct(private array $statuses) {}

            public function compute(string $type, string $asOf): ReconciliationResult
            {
                $status = $this->statuses[$type] ?? ReconciliationResult::MATCHED;
                $difference = $status === ReconciliationResult::MISMATCH ? '125.0000' : '0.0000';

                return new ReconciliationResult($type, $asOf, $status, '1000.0000', $status === ReconciliationResult::MISMATCH ? '875.0000' : '1000.0000', $difference);
            }

            public function computeAll(string $asOf): array
            {
                return array_combine(ReconciliationResult::TYPES, array_map(fn ($t) => $this->compute($t, $asOf), ReconciliationResult::TYPES));
            }
        });
    }

    /** A manual journal that is approved (or submitted) but not posted, dated $date. @return string the journal id */
    protected function pendingJournal(Tenant $tenant, string $date, string $until = 'APPROVED'): string
    {
        $id = $this->postJson(self::CL.'/journals', $this->journalBody($tenant, ['document_date' => $date, 'posting_date' => $date]))->assertCreated()->json('id');
        $this->postJson(self::CL."/journals/{$id}/submit")->assertOk();
        if ($until === 'APPROVED') {
            $this->postJson(self::CL."/journals/{$id}/approve")->assertOk();
        }

        return $id;
    }

    /** @return string the status of the period with this code */
    protected function periodStatus(Tenant $tenant, string $code): string
    {
        return (string) DB::table('accounting_periods')->where('tenant_id', $tenant->id)->where('code', $code)->value('status');
    }

    /** Move a period straight to a status in the database, bypassing the workflow (test arrangement only). */
    protected function forcePeriodStatus(Tenant $tenant, string $code, string $status): void
    {
        DB::table('accounting_periods')->where('tenant_id', $tenant->id)->where('code', $code)->update(['status' => $status]);
    }

    /** Hard-close a period through the closing API; the client must be authenticated and the period ready. */
    protected function hardClose(Tenant $tenant, string $month): void
    {
        $this->postJson(self::CL.'/closing/periods/'.$this->period($tenant, $month)->id.'/hard-close')->assertOk()->assertJsonPath('status', AccountingPeriod::CLOSED);
    }

    /** @return array<string,array<string,mixed>> the checks of a readiness answer keyed by code */
    protected function checksOf(array $readiness): array
    {
        return array_column($readiness['checks'], null, 'code');
    }

    /** @return list<array{string,string,string}> the lines of a journal as [account code, debit, credit] ordered by account code and amounts */
    protected function closingLines(string $journalId): array
    {
        return DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journalId)->orderBy('a.code')->orderBy('l.debit')
            ->get(['a.code', 'l.debit', 'l.credit'])->map(fn ($r) => [$r->code, $r->debit, $r->credit])->all();
    }
}
