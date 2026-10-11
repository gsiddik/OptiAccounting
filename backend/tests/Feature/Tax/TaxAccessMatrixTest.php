<?php

namespace Tests\Feature\Tax;

use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\ModuleAccessMatrix;
use Tests\Support\PayablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/** OA4: one sweep over every ACCOUNTING_TAX route for permission, tenant ownership and entitlement state. */
class TaxAccessMatrixTest extends TestCase
{
    use AccountingFixtures, Fixtures, ModuleAccessMatrix, PayablesFixtures, TaxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->taxTenant('alpha');
        $this->bravo = $this->taxTenant('bravo');
        $this->admin = $this->memberToken($this->alpha);

        $this->as($this->admin);
        $code = $this->taxCode();
        $this->ids = ['code' => $code['id']];
    }

    protected function matrixModules(): array
    {
        return ['ACCOUNTING_TAX'];
    }

    protected function matrixFeatures(): array
    {
        return ['TAX_CONFIGURATION', 'TAX_REPORT'];
    }

    protected function fingerprintTables(): array
    {
        return ['tax_codes', 'tax_rates', 'tax_transactions', 'journal_entries', 'journal_lines', 'audit_logs'];
    }

    protected function pinnedPermissions(): array
    {
        return [
            'DELETE tax-codes/{code}' => 'accounting.tax.manage',
            'GET tax-codes' => 'accounting.tax.view',
            'GET tax-codes/{code}' => 'accounting.tax.view',
            'GET tax-report' => 'accounting.tax.report.view',
            'GET tax-report/export' => 'accounting.report.export',
            'GET tax-transactions' => 'accounting.tax.report.view',
            'PATCH tax-codes/{code}' => 'accounting.tax.manage',
            'POST tax-codes' => 'accounting.tax.manage',
            'POST tax-codes/{code}/activate' => 'accounting.tax.manage',
            'POST tax-codes/{code}/deactivate' => 'accounting.tax.manage',
            'POST tax-codes/{code}/preview' => 'accounting.tax.view',
            'POST tax-codes/{code}/rates' => 'accounting.tax.manage',
        ];
    }
}
