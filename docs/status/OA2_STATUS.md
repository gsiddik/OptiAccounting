# OA2 Status — Accounts Payable, Expense & Cash/Bank

Branch: `claude/project-thread-b9jx47` (from `main` 1384d57, after OA1 merged). Status: **IN PROGRESS**.
Brief: `docs/specs/OA2.md`. OptiNexus and OptiFleet-v2 are not modified; OA3 is not started.

## Completed batches
- A Vendor + payment terms (+ expense category table, document history table, DB document guards, 35 permissions, 3 features)
- B Vendor invoice (`ap_invoices` + lines, also the payable of an expense via `origin`): draft → submit → approve → post, duplicate-number control with override permission, scope and SoD
- C Posting through `PostingEngine::postEvent` (`AP_INVOICE_RECOGNIZED`, per-line classification, pro-rata discount / other charges, vendor payable override), reversal via the shared `ReversalService`
- G Cash/bank accounts (`cash_bank_accounts`): mapped to one usable GL asset account, one active account per GL account, masked bank number only, book balance read from posted lines, GL mapping frozen once a document uses the account
- E AP aging (configurable buckets, as-of replay, CSV export) and AP-to-GL reconciliation by control account and vendor (`ApAgingService`, `ApReconciliationService`)
- F Expense categories + expenses with an explicit path: `PAYABLE` posts `EXPENSE_RECOGNIZED` and creates a POSTED `ap_invoices` row (origin EXPENSE) sharing the journal, settled by ordinary vendor payments; `DIRECT_PAID` posts `EXPENSE_PAID` crediting the chosen cash/bank account. Reversal reverses both; refused while payments settle the payable
- D Vendor payments + allocations: allocation to posted invoices (manual or `auto_allocate` oldest-due-first), posting `VENDOR_PAYMENT` (debits the payable account each invoice was booked to), reversal releases allocations; outstanding is derived (`ApSubledgerService`)

## Important decisions
- A payment is allocated in full before approval (no vendor advance in OA2), so no payable can go negative. A distribution key `component@ROLE` lets two rule lines share a component.
- All postings go through `PostingEngine::postEvent`; OA2 has no debit/credit engine. Execution order adjusted for table dependencies: cash/bank accounts (G) precede payments (D).
- Vendor identity and financial profile are written separately; profile changes are audited as `payables.vendor.financial_profile_changed`.
- Payment term gives a deterministic due date (net days / end of month + days); a different date only where the term allows it; CUSTOM needs an explicit date.
- Operational account roles (`CASH_BANK_ACCOUNT`, `DOCUMENT_ACCOUNT`) are bound to the document, not to the tenant mapping; `ACCOUNTS_PAYABLE` may only be credited by AP events (`restricted_events`).
- Stored generated columns (`vendor_invoice_key`) are NULL in `NEW` inside BEFORE triggers; `oa2_document_guard` ignores the columns it is told about.
- A document that touches another module (payable expense → AP, paid expense / vendor payment → cash & bank) needs that module writable at submit/approve/post (`MODULE_NOT_AVAILABLE`); every posting and reversal also needs `ACCOUNTING_CORE` writable (`ActorAuthority::assertLedgerWritable`), so a child module that stays ACTIVE cannot write to a lost ledger.
- Expense category names an account or a role (never both); roles that belong to a subledger or cash/bank (`FORBIDDEN_DESTINATION_ROLES`) are refused. The payable of an expense keeps the expense number; the database refuses a payable of origin EXPENSE without a POSTED payable expense of the same journal and total.
- New features VENDOR, AP_PAYMENT (ACCOUNTING_AP) and CASH_BANK_ACCOUNT (ACCOUNTING_CASH_BANK); migration `2026_10_10_100003` backfills them for tenants that already hold the module.

## Migrations (additive, `2026_10_10_1000xx`)
100001 document history + guard functions · 100002 payment terms, expense categories, vendors · 100003 feature backfill · 100004 ap_invoices + lines + triggers · 100005 account role binding · 100006 cash_bank_accounts + in-use guard · 100007 vendor_payments, ap_payment_allocations, deferred allocation check · 100008 expenses + guards (and the ap_invoices insert guard / source-unique index).

## Tests (executed this session)
| Check | Result |
|---|---|
| Batch A: `Feature/Payables/VendorAndPaymentTermTest` (8 tests) | PASS |
| Batches B–C: `Feature/Payables` (lifecycle 7, posting 11, vendor 8) | PASS (26 tests) |
| OA1 accounting suite + security with the new routes/permissions (119 tests) and OA0/OA0-N RBAC, entitlement, seeder, adapter (170 tests) | PASS |
| Batches G, D: `Feature/CashBank` (7), `Feature/Payables/VendorPayment*` (15) incl. partial / multiple / multi-invoice, over-allocation (service + DB), reversal, closed period, immutability, SoD, scope, tenant isolation, audit | PASS |
| Batch E: `Feature/Payables/ApAgingAndReconciliationTest` (7) | PASS |
| Batch F: `Feature/Expense` (categories 5, posting 12, access 7): both paths, classification precedence, closed period, immutability + 12 DB tamper attempts, reversal, DB path guards, SoD, scope, tenant isolation, module states | PASS (24 tests) |
| `Feature/Expense` + `Feature/Payables` + `Feature/CashBank` together (after the ledger-module check) | PASS (79 tests, 1701 assertions) |
| Pint | PASS |

## Remaining
Batches H (cash transactions, bank reconciliation), I (rules/exports/dashboard), J (UI), K (concurrency), L (access matrix), M–N (regression, release gate) per brief §55.
