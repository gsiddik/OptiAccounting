# OA2 Status — Accounts Payable, Expense & Cash/Bank

Branch: `claude/project-thread-b9jx47` (from `main` 1384d57, after OA1 merged). Status: **IN PROGRESS**.
Brief: `docs/specs/OA2.md`. OptiNexus and OptiFleet-v2 are not modified; OA3 is not started.

## Completed batches
- A Vendor + payment terms (+ expense category table, document history table, DB document guards, 35 permissions, 3 features)
- B Vendor invoice (`ap_invoices` + lines, also the payable of an expense via `origin`): draft → submit → approve → post, duplicate-number control with override permission, scope and SoD
- C Posting through `PostingEngine::postEvent` (`AP_INVOICE_RECOGNIZED`, per-line classification, pro-rata discount / other charges, vendor payable override), reversal via the shared `ReversalService`

## Important decisions
- All postings go through `PostingEngine::postEvent`; OA2 has no debit/credit engine. Execution order adjusted for table dependencies: cash/bank accounts (G) precede payments (D).
- Vendor identity and financial profile are written separately; profile changes are audited as `payables.vendor.financial_profile_changed`.
- Payment term gives a deterministic due date (net days / end of month + days); a different date only where the term allows it; CUSTOM needs an explicit date.
- Operational account roles (`CASH_BANK_ACCOUNT`, `DOCUMENT_ACCOUNT`) are bound to the document, not to the tenant mapping; `ACCOUNTS_PAYABLE` may only be credited by AP events (`restricted_events`).
- Stored generated columns (`vendor_invoice_key`) are NULL in `NEW` inside BEFORE triggers; `oa2_document_guard` ignores the columns it is told about.
- New features VENDOR, AP_PAYMENT (ACCOUNTING_AP) and CASH_BANK_ACCOUNT (ACCOUNTING_CASH_BANK); migration `2026_10_10_100003` backfills them for tenants that already hold the module.

## Migrations (additive, `2026_10_10_1000xx`)
100001 document history + guard functions · 100002 payment terms, expense categories, vendors · 100003 feature backfill · 100004 ap_invoices + lines + triggers · 100005 account role binding.

## Tests (executed this session)
| Check | Result |
|---|---|
| Batch A: `Feature/Payables/VendorAndPaymentTermTest` (8 tests) | PASS |
| Batches B–C: `Feature/Payables` (lifecycle 7, posting 11, vendor 8) | PASS (26 tests) |
| OA1 accounting suite + security with the new routes/permissions (119 tests) and OA0/OA0-N RBAC, entitlement, seeder, adapter (170 tests) | PASS |
| Pint | PASS |

## Remaining
Batches B–N per brief §55.
