<?php

namespace Database\Seeders;

use App\Domain\ProductCatalog\Models\Feature;
use App\Domain\ProductCatalog\Models\Module;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Initial module catalog, dependencies and feature catalog (docs/architecture/MODULE_CATALOG.md).
 * Idempotent and production-safe; afterwards the catalog is managed as data in the Platform Portal.
 * Seeding never overwrites names/descriptions an operator has edited.
 */
class ModuleCatalogSeeder extends Seeder
{
    private const MODULES = [
        // code => [name, description, requires[], features[code => name]]
        'ACCOUNTING_CORE' => ['Accounting Core', 'Profile, fiscal year, periods, chart of accounts, journals, general ledger, trial balance', [], [
            'ACCOUNTING_CONFIGURATION' => 'Accounting configuration', 'JOURNAL' => 'Journal', 'GENERAL_LEDGER' => 'General ledger', 'OPENING_BALANCE' => 'Opening balance']],
        'ACCOUNTING_AP' => ['Accounts Payable', 'Vendors, vendor invoices, AP subledger and aging', ['ACCOUNTING_CORE'], [
            'VENDOR_INVOICE' => 'Vendor invoice', 'AP_AGING' => 'AP aging']],
        'ACCOUNTING_EXPENSE' => ['Expense', 'Expense categories and expense claims', ['ACCOUNTING_CORE'], ['EXPENSE' => 'Expense']],
        'ACCOUNTING_CASH_BANK' => ['Cash & Bank', 'Cash and bank accounts, payments, receipts, bank reconciliation', ['ACCOUNTING_CORE'], [
            'PAYMENT' => 'Payment', 'RECEIPT' => 'Receipt', 'BANK_RECONCILIATION' => 'Bank reconciliation']],
        'ACCOUNTING_AR' => ['Accounts Receivable', 'Customers, customer invoices, credit/debit notes, AR aging', ['ACCOUNTING_CORE'], [
            'CUSTOMER_INVOICE' => 'Customer invoice', 'CREDIT_NOTE' => 'Credit note', 'AR_AGING' => 'AR aging']],
        'ACCOUNTING_BUDGET' => ['Budget', 'Budgets, versions and budget versus actual', ['ACCOUNTING_CORE'], ['BUDGET' => 'Budget']],
        'ACCOUNTING_FIXED_ASSET' => ['Fixed Asset', 'Asset register, depreciation and disposal', ['ACCOUNTING_CORE'], [
            'ASSET_REGISTER' => 'Asset register', 'DEPRECIATION' => 'Depreciation']],
        'ACCOUNTING_TAX' => ['Tax', 'Tax codes, effective-dated rates, tax postings and reports', ['ACCOUNTING_CORE'], [
            'TAX_CONFIGURATION' => 'Tax configuration', 'TAX_REPORT' => 'Tax report']],
        'ACCOUNTING_MULTI_CURRENCY' => ['Multi-Currency', 'Currencies, exchange rates, FX gain/loss and revaluation', ['ACCOUNTING_CORE'], [
            'EXCHANGE_RATE' => 'Exchange rate', 'FX_REVALUATION' => 'FX revaluation']],
        'ACCOUNTING_REPORTING' => ['Reporting & Closing', 'Financial statements, report mapping, closing and reconciliation', ['ACCOUNTING_CORE'], [
            'FINANCIAL_STATEMENTS' => 'Financial statements', 'PERIOD_CLOSING' => 'Period closing', 'RECONCILIATION' => 'Reconciliation']],
        'ACCOUNTING_INTEGRATION' => ['Integration', 'Integration connections, canonical events and adapters (OptiFleet first)', ['ACCOUNTING_CORE'], [
            'INTEGRATION_CONNECTION' => 'Integration connection', 'OPTIFLEET_CONNECTOR' => 'OptiFleet connector']],
        'ACCOUNTING_ANALYTICS' => ['Analytics', 'Management accounting, KPIs and analytical projections', ['ACCOUNTING_CORE', 'ACCOUNTING_REPORTING'], [
            'FINANCIAL_KPI' => 'Financial KPI', 'COST_ANALYSIS' => 'Cost analysis']],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $order = 10;
            foreach (self::MODULES as $code => [$name, $description, , $features]) {
                $module = Module::query()->firstOrNew(['code' => $code]);
                if (! $module->exists) {
                    $module->fill(['name' => $name, 'description' => $description, 'sort_order' => $order]);
                    $module->status = Module::ACTIVE;
                    $module->save();
                }
                $order += 10;

                $featureOrder = 10;
                foreach ($features as $featureCode => $featureName) {
                    $feature = Feature::query()->firstOrNew(['code' => $featureCode]);
                    if (! $feature->exists) {
                        $feature->fill(['name' => $featureName, 'sort_order' => $featureOrder]);
                        $feature->module_id = $module->id;
                        $feature->status = 'ACTIVE';
                        $feature->save();
                    }
                    $featureOrder += 10;
                }
            }

            $ids = Module::query()->pluck('id', 'code');
            foreach (self::MODULES as $code => [, , $requires]) {
                foreach ($requires as $required) {
                    DB::table('module_dependencies')->insertOrIgnore([
                        'module_id' => $ids[$code], 'requires_module_id' => $ids[$required], 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }
}
