<?php

namespace App\Domain\AccessControl;

/**
 * Permission registry (code = resource.action). OA0 registers only what OA0
 * needs; later phases add their permissions additively and call
 * `php artisan optientry:sync-permissions`.
 */
final class PermissionCatalog
{
    /** @return list<array{code:string,scope:string,group:string,description:string}> */
    public static function all(): array
    {
        $rows = [];
        foreach (self::definitions() as $scope => $groups) {
            foreach ($groups as $group => $permissions) {
                foreach ($permissions as $code => $description) {
                    $rows[] = compact('code', 'scope', 'group', 'description');
                }
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public static function codes(string $scope): array
    {
        return array_values(array_map(
            fn ($row) => $row['code'],
            array_filter(self::all(), fn ($row) => $row['scope'] === $scope),
        ));
    }

    private static function definitions(): array
    {
        return [
            'platform' => [
                'Tenants' => [
                    'platform.tenant.view' => 'View tenants',
                    'platform.tenant.create' => 'Create tenants',
                    'platform.tenant.update' => 'Update tenant details',
                    'platform.tenant.status.manage' => 'Change tenant lifecycle status',
                    'platform.membership.view' => 'View tenant memberships',
                    'platform.membership.manage' => 'Manage tenant memberships',
                ],
                'Product' => [
                    'platform.module.view' => 'View modules, dependencies and features',
                    'platform.module.manage' => 'Manage modules, dependencies and features',
                    'platform.bundle.view' => 'View bundles',
                    'platform.bundle.manage' => 'Manage bundles',
                ],
                'Commercial' => [
                    'platform.subscription.view' => 'View subscriptions',
                    'platform.subscription.manage' => 'Manage subscriptions',
                    'platform.entitlement.view' => 'View entitlements and capacity',
                    'platform.entitlement.manage' => 'Manage entitlements and capacity',
                ],
                'Access' => [
                    'platform.user.view' => 'View platform users',
                    'platform.user.manage' => 'Manage platform users',
                    'platform.role.view' => 'View platform roles',
                    'platform.role.manage' => 'Manage platform roles',
                    'platform.permission.view' => 'View platform permissions',
                ],
                'Audit' => [
                    'platform.audit.view' => 'View the platform audit log',
                ],
            ],
            'tenant' => [
                'Access' => [
                    'access.user.view' => 'View users and memberships',
                    'access.user.manage' => 'Invite and manage users',
                    'access.role.view' => 'View roles',
                    'access.role.manage' => 'Manage roles and their permissions',
                    'access.permission.view' => 'View the permission list',
                    'access.scope.view' => 'View data scopes',
                    'access.scope.manage' => 'Manage data scopes',
                ],
                'Organization' => [
                    'organization.view' => 'View branches and business units',
                    'organization.manage' => 'Manage branches and business units',
                ],
                'Account' => [
                    'account.subscription.view' => 'View subscription, modules, features and usage',
                ],
                'Accounting configuration' => [
                    'accounting.profile.view' => 'View the accounting profile and setup status',
                    'accounting.profile.manage' => 'Manage the accounting profile and activate accounting',
                    'accounting.period.view' => 'View fiscal years and accounting periods',
                    'accounting.period.manage' => 'Manage fiscal years and open or soft-close periods',
                    'accounting.period.close' => 'Close accounting periods',
                    'accounting.coa.view' => 'View the chart of accounts',
                    'accounting.coa.manage' => 'Manage the chart of accounts and apply a template',
                    'accounting.dimension.view' => 'View accounting dimensions and cost centers',
                    'accounting.dimension.manage' => 'Manage cost centers',
                    'accounting.posting_rule.view' => 'View posting rules',
                    'accounting.posting_rule.manage' => 'Manage posting rules',
                    'accounting.account_mapping.view' => 'View account mappings',
                    'accounting.account_mapping.manage' => 'Manage account mappings',
                ],
                'Journals' => [
                    'accounting.journal.view' => 'View journals',
                    'accounting.journal.create' => 'Create draft journals',
                    'accounting.journal.update' => 'Edit and cancel draft journals',
                    'accounting.journal.submit' => 'Submit journals for approval',
                    'accounting.journal.approve' => 'Approve or reject submitted journals',
                    'accounting.journal.post' => 'Post approved journals',
                    'accounting.journal.post_soft_closed' => 'Post into soft-closed periods',
                    'accounting.journal.reverse' => 'Reverse posted journals',
                    'accounting.opening_balance.view' => 'View the opening balance',
                    'accounting.opening_balance.manage' => 'Prepare the opening balance',
                    'accounting.opening_balance.post' => 'Post the opening balance',
                ],
                'Ledger and reports' => [
                    'accounting.gl.view' => 'View the general ledger',
                    'accounting.trial_balance.view' => 'View the trial balance',
                    'accounting.report.export' => 'Export the chart of accounts, general ledger and trial balance',
                ],
                'Payables' => [
                    'accounting.vendor.view' => 'View vendors and payment terms',
                    'accounting.vendor.manage' => 'Manage vendors, their financial profile and payment terms',
                    'accounting.ap_invoice.view' => 'View vendor invoices',
                    'accounting.ap_invoice.create' => 'Create draft vendor invoices',
                    'accounting.ap_invoice.update' => 'Edit and cancel draft vendor invoices',
                    'accounting.ap_invoice.submit' => 'Submit vendor invoices for approval',
                    'accounting.ap_invoice.approve' => 'Approve or reject vendor invoices',
                    'accounting.ap_invoice.post' => 'Post approved vendor invoices',
                    'accounting.ap_invoice.reverse' => 'Reverse posted vendor invoices',
                    'accounting.ap_invoice.override_duplicate' => 'Record a vendor invoice whose vendor invoice number already exists',
                    'accounting.ap_payment.view' => 'View vendor payments',
                    'accounting.ap_payment.create' => 'Create, edit and cancel draft vendor payments',
                    'accounting.ap_payment.submit' => 'Submit vendor payments for approval',
                    'accounting.ap_payment.approve' => 'Approve or reject vendor payments',
                    'accounting.ap_payment.post' => 'Post approved vendor payments',
                    'accounting.ap_payment.reverse' => 'Reverse posted vendor payments',
                    'accounting.ap_aging.view' => 'View the accounts payable subledger and aging',
                    'accounting.reconciliation.ap.view' => 'View the accounts payable to general ledger reconciliation',
                ],
                'Receivables' => [
                    'accounting.customer.view' => 'View customers',
                    'accounting.customer.manage' => 'Manage customers, their financial profile and payment terms',
                    'accounting.ar_invoice.view' => 'View customer invoices',
                    'accounting.ar_invoice.create' => 'Create draft customer invoices',
                    'accounting.ar_invoice.update' => 'Edit and cancel draft customer invoices',
                    'accounting.ar_invoice.submit' => 'Submit customer invoices for approval',
                    'accounting.ar_invoice.approve' => 'Approve or reject customer invoices',
                    'accounting.ar_invoice.post' => 'Post approved customer invoices',
                    'accounting.ar_invoice.reverse' => 'Reverse posted customer invoices',
                    'accounting.ar_receipt.view' => 'View customer receipts',
                    'accounting.ar_receipt.create' => 'Create, edit and cancel draft customer receipts',
                    'accounting.ar_receipt.submit' => 'Submit customer receipts for approval',
                    'accounting.ar_receipt.approve' => 'Approve or reject customer receipts',
                    'accounting.ar_receipt.post' => 'Post approved customer receipts',
                    'accounting.ar_receipt.reverse' => 'Reverse posted customer receipts',
                    'accounting.ar_credit_note.view' => 'View credit notes',
                    'accounting.ar_credit_note.create' => 'Create, edit and cancel draft credit notes',
                    'accounting.ar_credit_note.submit' => 'Submit credit notes for approval',
                    'accounting.ar_credit_note.approve' => 'Approve or reject credit notes',
                    'accounting.ar_credit_note.post' => 'Post approved credit notes',
                    'accounting.ar_credit_note.reverse' => 'Reverse posted credit notes',
                    'accounting.ar_aging.view' => 'View the accounts receivable subledger and aging',
                    'accounting.reconciliation.ar.view' => 'View the accounts receivable to general ledger reconciliation',
                ],
                'Expense' => [
                    'accounting.expense.view' => 'View expenses and expense categories',
                    'accounting.expense.create' => 'Create draft expenses',
                    'accounting.expense.update' => 'Edit and cancel draft expenses',
                    'accounting.expense.submit' => 'Submit expenses for approval',
                    'accounting.expense.approve' => 'Approve or reject expenses',
                    'accounting.expense.post' => 'Post approved expenses',
                    'accounting.expense.reverse' => 'Reverse posted expenses',
                    'accounting.expense_category.manage' => 'Manage expense categories',
                ],
                'Cash and bank' => [
                    'accounting.cash_bank.view' => 'View cash and bank accounts',
                    'accounting.cash_bank.manage' => 'Manage cash and bank accounts',
                    'accounting.cash_transaction.view' => 'View cash and bank transactions',
                    'accounting.cash_transaction.create' => 'Create draft cash and bank payments and receipts',
                    'accounting.cash_transaction.post' => 'Post cash and bank transactions',
                    'accounting.cash_transaction.reverse' => 'Reverse posted cash and bank transactions',
                    'accounting.bank_reconciliation.view' => 'View bank reconciliations',
                    'accounting.bank_reconciliation.manage' => 'Prepare and complete bank reconciliations',
                    'accounting.reconciliation.cash_bank.view' => 'View the cash and bank to general ledger reconciliation',
                ],
                'Budget' => [
                    'accounting.budget.view' => 'View budgets, their versions and budget versus actual',
                    'accounting.budget.manage' => 'Create budgets, prepare versions and lines, cancel',
                    'accounting.budget.submit' => 'Submit budget versions for approval',
                    'accounting.budget.approve' => 'Approve or reject budget versions, activate versions, open and close budgets',
                ],
                'Fixed assets' => [
                    'accounting.asset.view' => 'View the asset register, categories, schedules, depreciation runs and disposals',
                    'accounting.asset.manage' => 'Create and edit draft assets, discard drafts',
                    'accounting.asset_category.manage' => 'Create and edit asset categories',
                    'accounting.asset.capitalize' => 'Capitalize assets and reverse a capitalization',
                    'accounting.asset.depreciation.run' => 'Calculate and cancel depreciation runs',
                    'accounting.asset.depreciation.post' => 'Post and reverse depreciation runs',
                    'accounting.asset.dispose' => 'Prepare asset disposals and submit them for approval',
                    'accounting.asset.disposal.approve' => 'Approve or reject asset disposals',
                    'accounting.asset.disposal.post' => 'Post and reverse asset disposals',
                    'accounting.asset.reconciliation.view' => 'View the asset register to ledger reconciliation',
                ],
                'Tax' => [
                    'accounting.tax.view' => 'View tax codes, rates and tax transactions, and preview a tax calculation',
                    'accounting.tax.manage' => 'Create and edit tax codes, add rates, activate and deactivate codes',
                    'accounting.tax.report.view' => 'View the tax report',
                ],
                'Multi-currency' => [
                    'accounting.currency.view' => 'View the foreign currencies of the tenant',
                    'accounting.currency.manage' => 'Add and edit foreign currencies, activate and deactivate them',
                    'accounting.exchange_rate.view' => 'View exchange rates',
                    'accounting.exchange_rate.manage' => 'Enter, withdraw and delete exchange rates',
                ],
                'Audit' => [
                    'audit.view' => 'View the tenant audit log',
                ],
            ],
        ];
    }
}
