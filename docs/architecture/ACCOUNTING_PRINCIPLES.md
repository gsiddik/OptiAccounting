# Accounting Principles

Protected invariants. No tenant configuration, workflow, role or integration
can override them (MASTER §11). Detailed implementation belongs to OA1+.

## 1. Standards stance

- The tenant Accounting Profile records the framework it follows:
  `SAK_GENERAL` (SAK Indonesia/IFRS-based), `SAK_EP` (SAK Entitas Privat,
  replacing SAK ETAP from 1 Jan 2025), `SAK_EMKM`, `CUSTOM`.
- The engine is standards-*capable*, not a compliance engine: policies, judgment,
  disclosure and tax treatment stay with the tenant and its accountants. The
  product never claims automatic compliance and never hardcodes paragraph numbers.

## 2. Double entry

- A journal has ≥ 2 lines. Each line is either a debit or a credit, strictly
  positive, never both, never zero, never negative.
- A journal can be POSTED only when Σ debit = Σ credit in functional currency
  and, for each transaction currency, Σ debit = Σ credit in that currency.
- Enforced three times: posting service (before insert), database constraint
  trigger on status → POSTED (deferred, checks the sums), and tests.

## 3. Money and precision

- PostgreSQL `NUMERIC(20,4)` for amounts, `NUMERIC(20,10)` for exchange rates,
  `NUMERIC(9,6)` for tax rates/percentages. PHP uses `brick/math` `BigDecimal`.
- Amounts are rounded to the currency's minor units (IDR 2, USD 2, JPY 0, …)
  with an explicit rounding mode (HALF_UP unless a policy says otherwise) at
  defined points: line amount, tax amount, FX conversion. Rounding differences
  on FX are posted to a dedicated rounding account role, never absorbed silently.
- Each journal line stores: `transaction_currency`, `transaction_debit/credit`,
  `exchange_rate` (snapshot), `functional_debit/credit`. Single-currency
  tenants have rate 1. This keeps OA4 multi-currency additive.

## 4. Journal lifecycle and immutability

```
DRAFT → SUBMITTED → APPROVED → POSTED → (REVERSED by a new journal)
   ↘ CANCELLED       ↘ REJECTED → DRAFT
```

- Approval steps are policy-driven; a tenant may post directly from DRAFT if its
  approval policy allows (still recorded as a transition).
- POSTED → anything except "reversed-by link" is forbidden. No `POSTED → DRAFT`.
- Once POSTED, header and lines are read-only: application layer refuses, and
  database triggers reject UPDATE/DELETE of posted journals and their lines
  (only the reversal link columns are writable, once).
- Number is issued at posting time by the central numbering service (gapless
  per tenant/sequence/fiscal year, row-locked), immutable after issue.

## 5. Corrections

- Reversal: a new journal with the same accounts, debit/credit swapped, linked
  `reverses_journal_id`, with a mandatory reason and reference, posted to an
  open period (original date if open, otherwise a chosen open date).
  One reversal per journal (unique constraint); reversal is idempotent.
- Adjustment: a new journal referencing the original, with reason.
- Source documents (AP/AR invoices, payments) follow the same rule: void or
  credit/debit note, never edit after posting.

## 6. Periods and closing

- Fiscal year (default Jan–Dec, configurable) split into periods (monthly by
  default), plus an optional adjustment period 13 for year-end entries.
- Period status: `OPEN → SOFT_CLOSED → CLOSED` (and controlled reopen).
  SOFT_CLOSED: only users with a specific permission may post (closing
  adjustments). CLOSED: nobody posts. Reopen requires permission, reason and
  audit, and may require approval (maker-checker).
- The posting transaction locks the period row (`SELECT … FOR SHARE`) and the
  close transaction locks it `FOR UPDATE`, so close and post cannot race.
- Year-end close posts closing entries of income/expense to retained earnings
  (account role `RETAINED_EARNINGS`) idempotently (OA5).

## 7. Chart of accounts and dimensions

- Tenant-owned, hierarchical (header vs postable accounts), typed:
  `ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE` with sub-types (e.g.
  `CURRENT_ASSET`, `CASH_BANK`, `RECEIVABLE`, `PAYABLE`, `COGS`,
  `OTHER_INCOME`…) and a normal balance side.
- Platform templates (e.g. Indonesian general SME, transport/fleet company)
  are copied into tenant-owned accounts on selection; journals never reference
  platform template rows.
- Used accounts cannot be deleted or change type; they can be deactivated.
  Control accounts (AP/AR/cash/bank) accept postings only from their subledger.
- Analysis is not forced into account codes: lines carry dimensions
  (branch, business unit, cost center, plus extension dimensions and
  external reference dimensions such as vehicle or work order).

## 8. Posting engine, rules and account mapping

- All automatic postings go through one Posting Engine. Subledgers never write
  journal lines directly.
- Posting rules describe semantics with **account roles** (e.g.
  `AP_CONTROL`, `INPUT_VAT`, `MAINTENANCE_EXPENSE`, `INVENTORY_ASSET`,
  `CASH_BANK`), versioned (`DRAFT/PUBLISHED/ARCHIVED`, `effective_from/to`).
- Account mapping resolves role → tenant account, optionally by dimension.
- A posted journal stores the rule version and resolved accounts it used;
  later configuration changes never alter it.

## 9. Subledgers

- AP, AR, cash/bank, fixed assets reconcile to their GL control accounts.
  Reconciliation (OA5) reports differences; it never "fixes" the GL by edit.
- Open item balances (outstanding invoice amounts) are derived from posted
  documents and allocations, never typed in.

## 10. Opening balances and cutover

- Opening balances are a dedicated journal type posted into the first open
  period (or a dedicated opening period), must balance, and are controlled by
  permission; subledger opening items (open AP/AR invoices) reconcile to the
  opening GL control balances.

## 11. Tax (Indonesia first, generic engine)

- Tax is its own domain: tax codes, effective-dated rates, tax account roles.
  No tax formulas inside AP/AR code.
- Must be able to express: PPN output/input (11%; 12% with DPP nilai lain
  11/12 from 2025), non-creditable PPN capitalized into cost, PPh 23 / PPh 4(2)
  / PPh 21 withholding (liability on the payer side, prepaid tax on the
  receiving side), tax-inclusive vs exclusive amounts, and e-Faktur/Coretax
  reference fields. Rates are data, not code.

## 12. Dates

`document_date`, `transaction_date`, `posting_date` (decides the period),
`due_date`, `payment_date`, plus `created_at/updated_at` for audit only.
Business dates are tenant-timezone dates (`DATE`), timestamps are UTC.

## 13. Reports

Trial balance, GL, balance sheet, P&L, cash flow, AP/AR aging derive only from
POSTED data. Report layout uses configurable report mappings (versioned), not
account-code prefixes. Derived projections and balance caches can be rebuilt
from posted lines at any time and are verified against them in tests.
