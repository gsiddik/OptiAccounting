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
- Cash payments/receipts (`cash_transactions`) have no approval step: the creator prepares a DRAFT, a user with `accounting.cash_transaction.post` posts it (SoD creator ≠ poster by profile flag). Posting is `CASH_PAYMENT` (Dr counter, Cr cash/bank) / `CASH_RECEIPT` through `PostingEngine::postEvent` with `role_accounts` bound to the document; the counter account is explicit, usable, not a control account and not a cash/bank GL (`CASH_TRANSACTION_COUNTER_IS_CASH_BANK`); a DB trigger re-checks journal totals and the two lines on post.
- Bank reconciliation is manual and never writes the ledger: statement lines are signed from the book's view (deposit +, matches a debit of the account's GL line; withdrawal −, matches a credit); one book line is matched at most once (unique partial index) and a trigger verifies parent OPEN, same GL account, POSTED journal, same amount/direction. Equation: unexplained = (statement balance + unmatched book net) − (book balance + exception net); completion needs no UNMATCHED items and freezes the evidence columns. Statements exist for BANK accounts only.
- Cash/bank ↔ GL report compares documents with ledger lines (MATCHED/MISMATCH) per account and shows the latest statement state (IN_PROGRESS / RECONCILED / DIFFERENCE). Reading an account's movements (`cash-bank-accounts/{id}/transactions`, running balance, matched flag) needs `accounting.cash_bank.view`.
- Exports (`OperationalExportController`, `accounting.report.export` per module feature): vendors, AP invoices, vendor payments, expenses, cash payments / receipts, one account's bank movements, AP-to-GL and cash/bank-to-GL reconciliations, one bank statement (and the existing AP aging). Each runs the same service query and filter rules (`ListFilters`) as its list, so tenant, data scope and filters match the screen; capped at `optiaccounting.export_max_rows` (10.000, `EXPORT_TOO_LARGE`); audited as `payables|expense|cash_bank.report.exported` with filters and row count; CSV formulas neutralised; no bank account numbers.
- `GET operational-summary` (gate: journal view, like the home) returns AP outstanding / overdue / due within 7 days, pending invoice / payment / expense approvals and cash/bank book balance. A section is `null` unless the user passes the central resolver for that module, feature and list permission; figures use the list queries (scope) and POSTED data only; `complete=false` when the user's scope is not tenant-wide.
- Fixed in batch I: the `ACCOUNTS_PAYABLE` restriction (`restricted_events`) was only set by a migration that runs before the role exists on a fresh install; `AccountingCatalogSeeder` now sets it, so a rule crediting AP for a non-AP event is refused (`POSTING_RULE_ROLE_RESTRICTED`) on new and upgraded installs. OA1 `DashboardTest` no longer depends on the time of day (it failed between 17:00 and 24:00 UTC).
- New features VENDOR, AP_PAYMENT (ACCOUNTING_AP) and CASH_BANK_ACCOUNT (ACCOUNTING_CASH_BANK); migration `2026_10_10_100003` backfills them for tenants that already hold the module.

## Migrations (additive, `2026_10_10_1000xx`)
100001 document history + guard functions · 100002 payment terms, expense categories, vendors · 100003 feature backfill · 100004 ap_invoices + lines + triggers · 100005 account role binding · 100006 cash_bank_accounts + in-use guard · 100007 vendor_payments, ap_payment_allocations, deferred allocation check · 100008 expenses + guards (and the ap_invoices insert guard / source-unique index) · 100009 cash_transactions, bank_statements, bank_statement_items + guards.

## Tests (executed this session)
| Check | Result |
|---|---|
| Batch A: `Feature/Payables/VendorAndPaymentTermTest` (8 tests) | PASS |
| Batches B–C: `Feature/Payables` (lifecycle 7, posting 11, vendor 8) | PASS (26 tests) |
| OA1 accounting suite + security with the new routes/permissions (119 tests) and OA0/OA0-N RBAC, entitlement, seeder, adapter (170 tests) | PASS |
| Batches G, D: `Feature/CashBank` (7), `Feature/Payables/VendorPayment*` (15) incl. partial / multiple / multi-invoice, over-allocation (service + DB), reversal, closed period, immutability, SoD, scope, tenant isolation, audit | PASS |
| Batch E: `Feature/Payables/ApAgingAndReconciliationTest` (7) | PASS |
| Batch F: `Feature/Expense` (categories 5, posting 12, access 7): both paths, classification precedence, closed period, immutability + 12 DB tamper attempts, reversal, DB path guards, SoD, scope, tenant isolation, module states | PASS (24 tests) |
| Batch H: `Feature/CashBank/CashTransactionTest` (8) + `BankReconciliationTest` (7): both directions, closed period, immutability + DB tamper, reversal, counter-account rules, SoD, statement lifecycle, match/unmatch/exception, completion freeze, report vs GL (incl. simulated corruption), permissions, scope, tenant isolation, module states, no ledger writes, bank number not exposed | PASS (22 CashBank tests) |
| `Feature/Expense` + `Feature/Payables` + `Feature/CashBank` together | PASS (94 tests, 2209 assertions) |
| Batch I: `Feature/Operational` (exports 5, summary 3, rules/audit 4): every export's figures, filters, permission, audit, tenant + branch scope, cap, module/feature states, bank number never exposed; counters per section/scope/tenant; default rules idempotent, AP role restriction, document-bound roles unmappable, audit actions across the module | PASS (12 tests) |
| OA1 `Feature/Accounting` + `SeederAndBootstrapTest` with the new routes (incl. access matrix pinning `operational-summary`) | PASS |
| Pint | PASS |

## Remaining
Batch J (UI), K (concurrency), L (access matrix), M–N (regression, release gate) per brief §55.
