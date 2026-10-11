<?php

namespace Tests\Support;

use App\Domain\Identity\Models\Tenant;

/** Test helpers for the OA4 budget module; combine with AccountingFixtures and Fixtures. All helpers act as the already-authenticated client. */
trait BudgetFixtures
{
    protected const BG = '/api/v1/app/accounting';

    /** An accounting tenant whose profile lets one person prepare and approve (single-user tests). */
    protected function budgetTenant(string $code = 'alpha', array $profile = []): Tenant
    {
        return $this->accountingTenant($code, profile: $profile + ['sod_creator_not_approver' => false]);
    }

    protected function periodId(Tenant $tenant, string $month): string
    {
        return $this->period($tenant, $month)->id;
    }

    protected function yearId(Tenant $tenant, string $code = 'FY2026'): string
    {
        return $this->fiscalYear($tenant, $code)->id;
    }

    /** @return array<string,mixed> the created budget */
    protected function newBudget(Tenant $tenant, array $override = []): array
    {
        return $this->postJson(self::BG.'/budgets', $override + ['code' => 'BGT-'.substr(uniqid(), -6), 'name' => 'Anggaran 2026', 'fiscal_year_id' => $this->yearId($tenant)])->assertCreated()->json();
    }

    /** @return array<string,mixed> a draft version (optionally a copy) */
    protected function newVersion(string $budgetId, array $override = []): array
    {
        return $this->postJson(self::BG."/budgets/{$budgetId}/versions", $override + ['label' => 'Original'])->assertCreated()->json();
    }

    /** @return array<string,mixed> one line body: account code, month ('2026-03') and amount, plus optional dimensions */
    protected function bline(Tenant $tenant, string $accountCode, string $month, string $amount, array $dims = []): array
    {
        return ['account_id' => $this->account($tenant, $accountCode)->id, 'accounting_period_id' => $this->periodId($tenant, $month), 'amount' => $amount] + $dims;
    }

    protected function putLines(string $versionId, array $lines): array
    {
        return $this->putJson(self::BG."/budget-versions/{$versionId}/lines", ['lines' => $lines])->assertOk()->json();
    }

    protected function approveVersion(string $versionId): array
    {
        $this->postJson(self::BG."/budget-versions/{$versionId}/submit")->assertOk();

        return $this->postJson(self::BG."/budget-versions/{$versionId}/approve")->assertOk()->json();
    }

    /** @return array<string,mixed> the activated version */
    protected function activateVersion(string $versionId, ?string $from = null): array
    {
        return $this->postJson(self::BG."/budget-versions/{$versionId}/activate", $from ? ['effective_from' => $from] : [])->assertOk()->json();
    }

    /**
     * An opened budget with one ACTIVE version holding $lines.
     *
     * @return array{budget:array<string,mixed>,version:array<string,mixed>}
     */
    protected function activeBudget(Tenant $tenant, array $lines, array $budget = [], ?string $from = null): array
    {
        $b = $this->newBudget($tenant, $budget);
        $this->postJson(self::BG."/budgets/{$b['id']}/open")->assertOk();
        $v = $this->newVersion($b['id']);
        $this->putLines($v['id'], $lines);
        $this->approveVersion($v['id']);

        return ['budget' => $b, 'version' => $this->activateVersion($v['id'], $from)];
    }

    /** @return array<string,mixed> */
    protected function bvaReport(array $query): array
    {
        return $this->getJson(self::BG.'/budget-vs-actual?'.http_build_query($query))->assertOk()->json();
    }
}
