<?php

namespace App\Domain\Integration\Optinexus;

/**
 * The explicit set of permissions an Admin Tenant may hand out to custom roles from the OptiEntry UI (audit R08/A25).
 * The manifest sends it to OptiNexus as `delegable` per permission; OptiNexus applies it (together with the tenant's
 * entitlements) on every delegation call and an operator may widen or narrow it there. It is never derived from what an
 * Admin Tenant holds personally, and every permission that is not listed here is NOT delegable: a new catalog entry stays
 * out of reach until someone decides otherwise (DelegableSetTest fails if a listed code does not exist or a forbidden
 * family leaks in).
 *
 * Rationale (docs/integration/OPTINEXUS_ONBOARDING.md): delegable means day-to-day accounting work and read access. Never
 * delegable:
 *  1. administering who may do what (`access.*`: users, roles, permission list, data scopes) and the organisation structure;
 *  2. connecting or reviewing other systems (`accounting.integration.*`: connections, credentials, received events, retry);
 *  3. opening, closing or reopening accounting time (`accounting.period.*`, `accounting.closing.*`, `accounting.year_end.run`,
 *     posting into a soft-closed period) and the one-off opening balance posting;
 *  4. overriding a control (duplicate vendor invoice override, waiving closing items);
 *  5. defining what the books are made of and how business facts become ledger entries: accounting profile and activation,
 *     chart of accounts, posting rules, account mappings, tax codes, currencies and exchange rates, report definitions,
 *     expense and asset categories, cash and bank account masters.
 */
final class DelegableSet
{
    /** @var list<string> */
    private const CODES = [
        // Reading the books
        'organization.view', 'account.subscription.view', 'audit.view',
        'accounting.profile.view', 'accounting.period.view', 'accounting.coa.view', 'accounting.dimension.view',
        'accounting.posting_rule.view', 'accounting.account_mapping.view',
        'accounting.gl.view', 'accounting.trial_balance.view', 'accounting.report.export', 'accounting.opening_balance.view',
        'accounting.reporting.balance_sheet.view', 'accounting.reporting.pnl.view', 'accounting.reporting.cash_flow.view', 'accounting.report_definition.view',
        'accounting.year_end.view', 'accounting.tax.view', 'accounting.tax.report.view', 'accounting.currency.view', 'accounting.exchange_rate.view',
        'accounting.budget.view', 'accounting.asset.view', 'accounting.asset.reconciliation.view',
        'accounting.reconciliation.view', 'accounting.reconciliation.ap.view', 'accounting.reconciliation.ar.view', 'accounting.reconciliation.cash_bank.view',
        'accounting.vendor.view', 'accounting.ap_invoice.view', 'accounting.ap_payment.view', 'accounting.ap_aging.view',
        'accounting.customer.view', 'accounting.ar_invoice.view', 'accounting.ar_receipt.view', 'accounting.ar_credit_note.view', 'accounting.ar_aging.view',
        'accounting.expense.view', 'accounting.cash_bank.view', 'accounting.cash_transaction.view', 'accounting.bank_reconciliation.view',

        // Journals (segregation of duties between creator and approver is enforced by the workflow, not by the role)
        'accounting.journal.view', 'accounting.journal.create', 'accounting.journal.update', 'accounting.journal.submit',
        'accounting.journal.approve', 'accounting.journal.post', 'accounting.journal.reverse',

        // Payables
        'accounting.vendor.manage',
        'accounting.ap_invoice.create', 'accounting.ap_invoice.update', 'accounting.ap_invoice.submit', 'accounting.ap_invoice.approve', 'accounting.ap_invoice.post', 'accounting.ap_invoice.reverse',
        'accounting.ap_payment.create', 'accounting.ap_payment.submit', 'accounting.ap_payment.approve', 'accounting.ap_payment.post', 'accounting.ap_payment.reverse',

        // Receivables
        'accounting.customer.manage',
        'accounting.ar_invoice.create', 'accounting.ar_invoice.update', 'accounting.ar_invoice.submit', 'accounting.ar_invoice.approve', 'accounting.ar_invoice.post', 'accounting.ar_invoice.reverse',
        'accounting.ar_receipt.create', 'accounting.ar_receipt.submit', 'accounting.ar_receipt.approve', 'accounting.ar_receipt.post', 'accounting.ar_receipt.reverse',
        'accounting.ar_credit_note.create', 'accounting.ar_credit_note.submit', 'accounting.ar_credit_note.approve', 'accounting.ar_credit_note.post', 'accounting.ar_credit_note.reverse',

        // Expenses, cash and bank
        'accounting.expense.create', 'accounting.expense.update', 'accounting.expense.submit', 'accounting.expense.approve', 'accounting.expense.post', 'accounting.expense.reverse',
        'accounting.cash_transaction.create', 'accounting.cash_transaction.post', 'accounting.cash_transaction.reverse', 'accounting.bank_reconciliation.manage',

        // Budgets, fixed assets, reconciliation work, cost centers
        'accounting.budget.manage', 'accounting.budget.submit', 'accounting.budget.approve',
        'accounting.asset.manage', 'accounting.asset.capitalize', 'accounting.asset.depreciation.run', 'accounting.asset.depreciation.post',
        'accounting.asset.dispose', 'accounting.asset.disposal.approve', 'accounting.asset.disposal.post',
        'accounting.reconciliation.run', 'accounting.reconciliation.exception.manage', 'accounting.dimension.manage',
    ];

    /** True only for a code that is listed above. */
    public static function allows(string $permissionCode): bool
    {
        return isset(self::flipped()[$permissionCode]);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return self::CODES;
    }

    /** @return array<string,int> */
    private static function flipped(): array
    {
        static $flipped = null;

        return $flipped ??= array_flip(self::CODES);
    }
}
