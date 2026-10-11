<?php

namespace Tests\Feature\Currency;

use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\ModuleAccessMatrix;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/** OA4: one sweep over every ACCOUNTING_MULTI_CURRENCY route for permission, tenant ownership and entitlement state. */
class CurrencyAccessMatrixTest extends TestCase
{
    use AccountingFixtures, Fixtures, FxFixtures, ModuleAccessMatrix, PayablesFixtures, ReceivablesFixtures, TaxFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->fxTenant('alpha');
        $this->bravo = $this->fxTenant('bravo');
        $this->admin = $this->memberToken($this->alpha);

        $this->as($this->admin);
        $rate = $this->rate('USD', '15500', '2026-03-01');
        $this->ids = ['currency' => (string) DB::table('currencies')->where('tenant_id', $this->alpha->id)->value('id'), 'rate' => $rate['id']];
    }

    protected function matrixModules(): array
    {
        return ['ACCOUNTING_MULTI_CURRENCY'];
    }

    protected function matrixFeatures(): array
    {
        return ['EXCHANGE_RATE'];
    }

    protected function fingerprintTables(): array
    {
        return ['currencies', 'exchange_rates', 'posting_rules', 'journal_entries', 'journal_lines', 'audit_logs'];
    }

    protected function pinnedPermissions(): array
    {
        return [
            'DELETE currencies/{currency}' => 'accounting.currency.manage',
            'DELETE exchange-rates/{rate}' => 'accounting.exchange_rate.manage',
            'GET currencies' => 'accounting.currency.view',
            'GET currencies/{currency}' => 'accounting.currency.view',
            'GET exchange-rates' => 'accounting.exchange_rate.view',
            'GET exchange-rates/lookup' => 'accounting.exchange_rate.view',
            'GET exchange-rates/{rate}' => 'accounting.exchange_rate.view',
            'GET fx-rules' => 'accounting.exchange_rate.view',
            'PATCH currencies/{currency}' => 'accounting.currency.manage',
            'PATCH exchange-rates/{rate}' => 'accounting.exchange_rate.manage',
            'POST currencies' => 'accounting.currency.manage',
            'POST currencies/{currency}/activate' => 'accounting.currency.manage',
            'POST currencies/{currency}/deactivate' => 'accounting.currency.manage',
            'POST exchange-rates' => 'accounting.exchange_rate.manage',
            'POST exchange-rates/{rate}/activate' => 'accounting.exchange_rate.manage',
            'POST exchange-rates/{rate}/deactivate' => 'accounting.exchange_rate.manage',
            'POST fx-rules/defaults' => 'accounting.posting_rule.manage',
        ];
    }
}
