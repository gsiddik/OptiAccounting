<?php

namespace Tests\Feature\Closing;

use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\ModuleAccessMatrix;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/**
 * OA5: one sweep over every closing and year-end route of ACCOUNTING_REPORTING (the reporting and reconciliation routes of the same module have
 * their own matrices) for permission, entitlement state and tenant ownership.
 */
class ClosingAccessMatrixTest extends TestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures;
    use ModuleAccessMatrix {
        routes as private everyReportingRoute;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->yearEndTenant('alpha');
        $this->bravo = $this->yearEndTenant('bravo');
        $this->admin = $this->memberToken($this->alpha);

        $this->as($this->admin);
        $this->fakeReconciliations();
        $template = $this->postJson(self::CL.'/closing/checklist-templates', ['code' => 'BANK_STATEMENTS', 'title' => 'Rekening koran lengkap', 'required' => true])->assertCreated()->json('id');
        $this->ids = [
            'period' => $this->period($this->alpha, '2026-03')->id, 'code' => 'BANK_STATEMENTS', 'template' => $template, 'fiscalYear' => $this->fiscalYear($this->alpha, 'FY2026')->id,
        ];
    }

    protected function matrixModules(): array
    {
        return ['ACCOUNTING_REPORTING'];
    }

    protected function matrixFeatures(): array
    {
        return ['PERIOD_CLOSING'];
    }

    protected function fingerprintTables(): array
    {
        return [
            'accounting_periods', 'fiscal_years', 'closing_settings', 'closing_policy_rules', 'closing_checklist_templates', 'closing_checklist_items', 'closing_events',
            'year_end_closings', 'posting_rules', 'journal_entries', 'journal_lines', 'audit_logs',
        ];
    }

    /** Only the closing and year-end routes of the module. */
    protected function routes(): array
    {
        return array_values(array_filter($this->everyReportingRoute(), fn ($route) => str_contains($route['uri'], '/closing/') || str_contains($route['uri'], '/year-end')));
    }

    protected function pinnedPermissions(): array
    {
        return [
            'GET closing/overview' => 'accounting.period.view',
            'GET closing/history' => 'accounting.period.view',
            'GET closing/policy' => 'accounting.period.view',
            'PUT closing/policy' => 'accounting.closing.policy.manage',
            'GET closing/checklist-templates' => 'accounting.period.view',
            'POST closing/checklist-templates' => 'accounting.closing.policy.manage',
            'PATCH closing/checklist-templates/{template}' => 'accounting.closing.policy.manage',
            'DELETE closing/checklist-templates/{template}' => 'accounting.closing.policy.manage',
            'GET closing/periods/{period}/readiness' => 'accounting.period.view',
            'GET closing/periods/{period}/checklist' => 'accounting.period.view',
            'POST closing/periods/{period}/checklist/{code}/complete' => 'accounting.closing.checklist.manage',
            'POST closing/periods/{period}/checklist/{code}/waive' => 'accounting.closing.checklist.manage',
            'POST closing/periods/{period}/checklist/{code}/reset' => 'accounting.closing.checklist.manage',
            'POST closing/periods/{period}/pre-close' => 'accounting.period.preclose',
            'POST closing/periods/{period}/soft-close' => 'accounting.period.soft_close',
            'POST closing/periods/{period}/hard-close' => 'accounting.period.hard_close',
            'POST closing/periods/{period}/reopen' => 'accounting.period.reopen',
            'GET year-end' => 'accounting.year_end.view',
            'GET year-end/rule-status' => 'accounting.year_end.view',
            'POST year-end/rule-defaults' => 'accounting.posting_rule.manage',
            'GET year-end/{fiscalYear}' => 'accounting.year_end.view',
            'GET year-end/{fiscalYear}/preview' => 'accounting.year_end.view',
            'POST year-end/{fiscalYear}/run' => 'accounting.year_end.run',
            'POST year-end/{fiscalYear}/reverse' => 'accounting.year_end.run',
        ];
    }
}
