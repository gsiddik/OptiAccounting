# OA2 Status — Accounts Payable, Expense & Cash/Bank

Branch: `claude/project-thread-b9jx47` (from `main` 1384d57, after OA1 merged). Status: **IN PROGRESS**.
Brief: `docs/specs/OA2.md`. OptiNexus and OptiFleet-v2 are not modified; OA3 is not started.

## Completed batches
- A Vendor + payment terms (+ expense category table, document history table, DB document guards, 35 permissions, 3 features)

## Important decisions
- All postings go through `PostingEngine::postEvent`; OA2 has no debit/credit engine. Execution order adjusted for table dependencies: cash/bank accounts (G) precede payments (D).
- Vendor identity and financial profile are written separately; profile changes are audited as `payables.vendor.financial_profile_changed`.
- Payment term gives a deterministic due date (net days / end of month + days); a different date only where the term allows it; CUSTOM needs an explicit date.
- New features VENDOR, AP_PAYMENT (ACCOUNTING_AP) and CASH_BANK_ACCOUNT (ACCOUNTING_CASH_BANK); migration `2026_10_10_100003` backfills them for tenants that already hold the module.

## Migrations (additive, `2026_10_10_1000xx`)
100001 document history + guard functions · 100002 payment terms, expense categories, vendors · 100003 feature backfill.

## Tests (executed this session)
| Check | Result |
|---|---|
| Batch A: `Feature/Payables/VendorAndPaymentTermTest` (8 tests) | PASS |
| OA1 access matrix + security tests with the new routes/permissions | PASS |

## Remaining
Batches B–N per brief §55.
