# Payables, Expense and Cash/Bank (OA2)

Subledgers that sit on the OA1 Accounting Core. They own **documents** (vendor, invoice, payment, expense, cash transaction, bank
statement); the Core owns **financial truth** (journal, GL, periods). Nothing here has a debit/credit engine of its own.

```
document (draft → submitted → approved → posted → reversed)
   → accounting event → posting rule → account role → account mapping
   → PostingEngine::postEvent (period row lock, numbering, balance) → posted journal → GL
```

## Modules and features

| Module | Features | Code | Tables |
|---|---|---|---|
| `ACCOUNTING_AP` | `VENDOR`, `VENDOR_INVOICE`, `AP_PAYMENT`, `AP_AGING` | `app/Domain/Payables` | `payment_terms`, `vendors`, `ap_invoices` (+ `_lines`), `vendor_payments`, `ap_payment_allocations` |
| `ACCOUNTING_EXPENSE` | `EXPENSE` | `app/Domain/Expense` | `expense_categories`, `expenses` |
| `ACCOUNTING_CASH_BANK` | `CASH_BANK_ACCOUNT`, `PAYMENT`, `RECEIPT`, `BANK_RECONCILIATION` | `app/Domain/CashBank` | `cash_bank_accounts`, `cash_transactions`, `bank_statements`, `bank_statement_items` |

All three require `ACCOUNTING_CORE`. A tenant without them behaves exactly as under OA1. Setup is never mandatory for sign-in, tenant
management, manual journals, GL or trial balance.

## Accounting events (all through `PostingEngine::postEvent`)

`AP_INVOICE_RECOGNIZED` (Dr expense/asset per line, Dr tax, Cr payable), `VENDOR_PAYMENT` (Dr the payable account each invoice was booked to,
Cr cash/bank), `EXPENSE_RECOGNIZED` (payable expense), `EXPENSE_PAID` (Dr expense, Cr cash/bank), `CASH_PAYMENT`, `CASH_RECEIPT`.
`OperationalSetupService::applyDefaults` publishes the default rules. Reversal of any document uses the shared `ReversalService` (a mirrored
journal linked to the original); there are no separate `*_REVERSED` event types. Documents name the accounts that vary per document through
the roles `CASH_BANK_ACCOUNT` and `DOCUMENT_ACCOUNT`, bound to the document and never to the tenant mapping. `ACCOUNTS_PAYABLE` may be
credited only by AP events (`restricted_events`), so a rule cannot book a payable that has no document behind it.

## Boundary: Expense vs Vendor Invoice

An expense is the simple internal document; a vendor invoice is the formal payable. A **payable expense** does not create a second payable
ledger: posting it also creates a POSTED `ap_invoices` row (`origin = EXPENSE`, `source_type = 'expense'`) that shares the expense's journal and
number, is paid by ordinary vendor payments and appears in the same aging. A **directly paid expense** credits the chosen cash/bank account
and creates no payable. Reversing an expense reverses both and is refused while payments settle the payable.

## AP subledger

Outstanding is **derived**, never stored: invoice total minus the effective allocations of POSTED payments (`ApSubledgerService`). A payment is
allocated in full to posted invoices before it is approved (no vendor advance in OA2), so no payable can go negative. Over-allocation is refused
in the service under a row lock and by a deferred DB check. Aging (configurable buckets, as-of replay) and the AP-to-GL reconciliation read the
same derivation. The reconciliation compares the control accounts (the AP role, vendor overrides, every account an invoice was booked to) with the
subledger; an opening balance posted straight to a control account is shown as its own component. A difference is reported, never adjusted.

## Cash and bank

`cash_bank_accounts` map one usable GL asset account each (one active account per GL account; mapping frozen once a document uses it; only
a masked bank number is stored). Book balance is read from POSTED journal lines. Cash payments/receipts (`cash_transactions`) have no approval
step: the creator prepares a draft, a holder of `accounting.cash_transaction.post` posts it, creator ≠ poster by profile policy. The counter
account is explicit, usable, not a control account and not a cash/bank GL. **Bank reconciliation is manual and never writes the ledger**:
statement lines are signed from the book's view and matched one-to-one to a posted book line (unique partial index; a trigger re-checks
account, status, amount and direction). `unexplained = (statement balance + unmatched book net) − (book balance + exception net)`; completion
needs no UNMATCHED line and freezes the evidence. Statements exist for BANK accounts only.

## Database guards (in addition to the service layer)

`oa2_document_guard` makes POSTED documents immutable and permits only the documented transitions; per-document post guards re-check totals
and journal links; composite FKs keep every relation inside one tenant; a payable of origin EXPENSE needs a POSTED expense of the same journal
and total; a duplicate vendor invoice number per vendor is controlled (recording it anyway needs `accounting.ap_invoice.override_duplicate` and a reason). Violations surface as `23514` and are mapped to domain errors. Used master data is deactivated, never deleted.

## Access

Every route is gated by `RequireAccess(permission, module, feature)`; the central resolver decides tenant status, subscription, module and
feature entitlement, READ_ONLY (mutations only), membership, permission and data scope. SoD is policy-driven (profile flags, never role names).
A document that touches another module (payable expense → AP, paid expense or vendor payment → cash/bank) needs that module writable, and every
posting or reversal also needs `ACCOUNTING_CORE` writable (`ActorAuthority::assertLedgerWritable`), so a child module that stays ACTIVE cannot
write to a lost ledger. Lists, exports and the operational summary share one query path and the same `ListFilters`, so tenant, scope and filters
match the screen; exports are capped (`optientry.export_max_rows`), audited and never contain bank numbers.

## Not in OA2

Vendor advances / prepayments, foreign-currency documents (`CURRENCY_NOT_SUPPORTED`), automatic bank-feed import or auto-matching, attachments
(the repository has no secure file storage; documents carry a `supporting_document` reference), approval chains beyond one approver, and every
integration with OptiFleet or an ERP (OA6, pull-based). Receivables and revenue are OA3.
