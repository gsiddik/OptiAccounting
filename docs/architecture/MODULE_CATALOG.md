# Module Catalog

Status: MASTER baseline. The catalog is **data** (seeded by OA0, editable in
the Platform Portal); this table is the initial content, not code. Bundles are
data-driven compositions of these modules and are never hardcoded.

| Code | Purpose | Requires | Initial features | Phase |
|---|---|---|---|---|
| `ACCOUNTING_CORE` | Profile, fiscal year, periods, COA, dimensions, journals, posting engine, GL, trial balance, opening balance | — | `ACCOUNTING_CONFIGURATION`, `JOURNAL`, `GENERAL_LEDGER`, `OPENING_BALANCE` | OA1 |
| `ACCOUNTING_AP` | Vendors, vendor invoices, AP subledger, AP aging | CORE | `VENDOR_INVOICE`, `AP_AGING` | OA2 |
| `ACCOUNTING_EXPENSE` | Expense categories and expense claims | CORE | `EXPENSE` | OA2 |
| `ACCOUNTING_CASH_BANK` | Cash/bank accounts, payments, receipts, bank transactions, bank reconciliation | CORE | `PAYMENT`, `RECEIPT`, `BANK_RECONCILIATION` | OA2 |
| `ACCOUNTING_AR` | Customers, customer invoices, credit/debit notes, receipts allocation, AR aging | CORE | `CUSTOMER_INVOICE`, `CREDIT_NOTE`, `AR_AGING` | OA3 |
| `ACCOUNTING_BUDGET` | Budgets, versions, budget vs actual | CORE | `BUDGET` | OA4 |
| `ACCOUNTING_FIXED_ASSET` | Asset register, depreciation, disposal | CORE | `ASSET_REGISTER`, `DEPRECIATION` | OA4 |
| `ACCOUNTING_TAX` | Tax codes, effective-dated rates, tax postings and reports | CORE | `TAX_CONFIGURATION`, `TAX_REPORT` | OA4 |
| `ACCOUNTING_MULTI_CURRENCY` | Currencies, rates, FX gain/loss, revaluation | CORE | `EXCHANGE_RATE`, `FX_REVALUATION` | OA4 |
| `ACCOUNTING_REPORTING` | Financial statements, report mapping, comparative reports, closing, reconciliation | CORE | `FINANCIAL_STATEMENTS`, `PERIOD_CLOSING`, `RECONCILIATION` | OA5 |
| `ACCOUNTING_INTEGRATION` | Integration connections, canonical events, adapters (OptiFleet first) | CORE | `INTEGRATION_CONNECTION`, `OPTIFLEET_CONNECTOR` | OA6 |
| `ACCOUNTING_ANALYTICS` | Management accounting, KPIs, analytical projections | CORE, REPORTING | `FINANCIAL_KPI`, `COST_ANALYSIS` | OA7 |

Notes:

- Basic period open/close needed for safe posting is part of `ACCOUNTING_CORE`
  (OA1); the closing *workflow* (checklists, soft/hard close, year-end) is
  `ACCOUNTING_REPORTING` (OA5).
- Cross-module features (e.g. "pay a vendor invoice" needs AP + CASH_BANK) are
  checked against both entitlements at the action, not by a merged module.
- A module that becomes READ_ONLY or DISABLED never deletes data; posted
  history stays reportable through `ACCOUNTING_CORE` reports.
- Permissions are prefixed by module area: `accounting.journal.*`,
  `accounting.coa.*`, `accounting.period.*`, `accounting.ap.*`,
  `accounting.ar.*`, `accounting.cash.*`, `accounting.tax.*`,
  `accounting.report.*`, `accounting.integration.*`. Each phase adds its
  permissions additively; OA0 seeds only platform/access/organization/audit.
- In `optinexus` mode the same module codes are registered as OptiNexus
  application capabilities so subscriptions there map 1:1 to entitlements here.

Example bundles (data, adjustable): *Starter* = CORE + CASH_BANK + REPORTING;
*Business* = Starter + AP + AR + EXPENSE + TAX; *Enterprise* = Business +
BUDGET + FIXED_ASSET + MULTI_CURRENCY + INTEGRATION + ANALYTICS.
