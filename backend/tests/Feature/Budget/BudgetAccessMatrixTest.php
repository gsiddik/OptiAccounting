<?php

namespace Tests\Feature\Budget;

use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\BudgetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\ModuleAccessMatrix;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA4: one sweep over every ACCOUNTING_BUDGET route for permission, tenant ownership and entitlement state. */
class BudgetAccessMatrixTest extends TestCase
{
    use AccountingFixtures, BudgetFixtures, Fixtures, ModuleAccessMatrix, PayablesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->budgetTenant('alpha');
        $this->bravo = $this->budgetTenant('bravo');
        $this->admin = $this->memberToken($this->alpha);

        $this->as($this->admin);
        $made = $this->activeBudget($this->alpha, [$this->bline($this->alpha, '6100', '2026-03', '1000000')]);
        $draft = $this->newVersion($made['budget']['id'], ['copy_from_version_id' => $made['version']['id']]);
        $this->ids = [
            'budget' => $made['budget']['id'], 'version' => $draft['id'],
            'line' => (string) DB::table('budget_lines')->where('budget_version_id', $draft['id'])->value('id'),
        ];
    }

    protected function matrixModules(): array
    {
        return ['ACCOUNTING_BUDGET'];
    }

    protected function matrixFeatures(): array
    {
        return ['BUDGET'];
    }

    protected function fingerprintTables(): array
    {
        return ['budgets', 'budget_versions', 'budget_lines', 'document_transitions', 'journal_entries', 'journal_lines', 'audit_logs'];
    }

    protected function pinnedPermissions(): array
    {
        return [
            'DELETE budget-versions/{version}/lines/{line}' => 'accounting.budget.manage',
            'GET budget-versions/{version}' => 'accounting.budget.view',
            'GET budget-vs-actual' => 'accounting.budget.view',
            'GET budget-vs-actual/export' => 'accounting.report.export',
            'GET budgets' => 'accounting.budget.view',
            'GET budgets/{budget}' => 'accounting.budget.view',
            'PATCH budget-versions/{version}' => 'accounting.budget.manage',
            'PATCH budget-versions/{version}/lines/{line}' => 'accounting.budget.manage',
            'PATCH budgets/{budget}' => 'accounting.budget.manage',
            'POST budget-versions/{version}/activate' => 'accounting.budget.approve',
            'POST budget-versions/{version}/approve' => 'accounting.budget.approve',
            'POST budget-versions/{version}/cancel' => 'accounting.budget.manage',
            'POST budget-versions/{version}/lines' => 'accounting.budget.manage',
            'POST budget-versions/{version}/reject' => 'accounting.budget.approve',
            'POST budget-versions/{version}/reopen' => 'accounting.budget.manage',
            'POST budget-versions/{version}/submit' => 'accounting.budget.submit',
            'POST budgets' => 'accounting.budget.manage',
            'POST budgets/{budget}/cancel' => 'accounting.budget.manage',
            'POST budgets/{budget}/close' => 'accounting.budget.approve',
            'POST budgets/{budget}/open' => 'accounting.budget.approve',
            'POST budgets/{budget}/versions' => 'accounting.budget.manage',
            'PUT budget-versions/{version}/lines' => 'accounting.budget.manage',
        ];
    }
}
