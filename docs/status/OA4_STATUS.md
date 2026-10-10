# OA4 Status — Budget, Fixed Asset, Tax & Multi-Currency (OptiEntry)

Branch: `claude/project-thread-b9jx47` (from `main` f27bee3, after OA3 and the OptiEntry rebrand were merged). Status: **COMMIT READY** (PR open, merge only on owner request).
Brief: `docs/specs/OA4.md`. Design: `docs/architecture/OA4_BUDGET_ASSET_TAX_FX.md`. Gate traceability: `docs/status/oa4-qa/GATE_TRACEABILITY.md`.
OptiNexus and OptiFleet-v2 were not modified. OA5 has not been started. **FX Revaluation: NOT IMPLEMENTED** (owner decision needed, see Remaining).

## Completed batches
- A–B Budget (versions, lines, lifecycle, activation windows, Budget vs Actual + CSV) · C–F Fixed assets (categories, register, capitalization, schedules, depreciation runs, disposal, reconciliation)
- G–I Tax (codes, effective-dated rates, one calculator, AP / expense / AR integration, tax transactions, tax report) · J–M Multi-currency (currencies, rates, resolver, foreign AP / AR, realised FX)
- N React UI for all four areas, tax code per line and currency in the AP / AR / expense editors, OA4 menus and breadcrumbs, READ_ONLY aware · O OA4 demo data · P cross-domain tests and regression · Q this gate

## Important implementation decisions
- Budget is planning data: no table references a journal; a revision is a new version and activation closes the previous window (DB-enforced).
- Asset register is a sub-ledger; capitalization, depreciation and disposal post only through `PostingEngine` (events `ASSET_CAPITALIZED`, `DEPRECIATION_RECOGNIZED`, `ASSET_DISPOSED`). A foreign-currency invoice line cannot be registered as an asset (`ASSET_SOURCE_FOREIGN`).
- Tax: one calculator (inclusive / exclusive / zero-rated / exempt, HALF_UP), a frozen snapshot per document line, accounts by role mapping; a document with tax codes refuses the manual header tax and discount.
- Multi-currency: the ledger stays functional; a foreign amount is a snapshot beside it. Rate resolver: latest active rate on or before the date, at most 31 days old, never assumes 1; posting re-checks the rate (`EXCHANGE_RATE_CHANGED`). Realised difference via `VENDOR_PAYMENT_FX` / `CUSTOMER_RECEIPT_FX` into `FX_GAIN` / `FX_LOSS`.
- Single-currency tenants and old documents behave exactly as before (no FX setup needed). Credit notes against a foreign invoice are refused; vendors and customers keep a functional default currency.
- 21 new permissions (budget 4, asset 10, tax 3, currency 4), four modules and their features; a generic access-matrix trait sweeps every route per module.
- Defect found in the gate and fixed: the cash/bank-to-GL reconciliation summed the foreign amount of payments and receipts; it now uses the functional amount the bank moved.

## Migrations (additive, 7 files)
`2026_10_12_100001` budgets · `2026_10_13_1000{01..04}` asset catalog, assets and schedules, depreciation runs, disposals · `2026_10_14_100001` tax · `2026_10_15_100001` multi-currency (currencies, rates, snapshots, journal line foreign legs, guards).
`migrate:fresh --seed` and DemoSeeder run twice from empty: second run changes nothing.

## Financial invariants (service AND database, each tested)
Budget never creates a journal; Budget vs Actual = POSTED lines only · a month is depreciated once and never beyond cost minus residual; posted runs, schedule rows and disposals immutable · assets from one AP line never exceed it ·
tax recorded once per posted line, closed period blocks · a posted journal balances in functional currency AND in every transaction currency · foreign settlement allocations add up to the payment's functional amount and difference ·
cross-tenant references fail (composite FKs, 404 on every route) · READ_ONLY / SUSPENDED / DISABLED refuse module changes.

## Tests (executed this session)
| Check | Result |
|---|---|
| Backend `phpunit --testsuite Feature,Unit` (PostgreSQL 16): 816 tests, 73 589 assertions, 0 failures, 0 warnings | PASS |
| – OA4: Budget 19, Fixed asset 58, Tax 45, Currency 43 feature tests; Unit 14 (schedule, calculator); access matrices for the four modules; cross-domain 3 | PASS |
| – OA0–OA3 regression with new routes, permissions, catalog, FX columns (Accounting, Payables, Expense, CashBank, Receivables, RBAC, entitlement, security, seeders) | PASS |
| Concurrency suite (OA1–OA3 42 + OA4 19 tests: asset capitalization / depreciation runs, tax, rate withdrawal vs posting, two payments on one USD invoice), run twice: 61/61 both times (1 662 and 1 660 assertions), 0 failures | PASS |
| Pint `--test` | PASS |
| Frontend `tsc -b`, `oxlint`, `vitest` 812 tests / 36 files, `npm run build`, `npm audit --omit=dev` (0) | PASS |
| 78 screenshots at 1440 / 820 / 390 (39 kept in `oa4-qa/`): no failed request, console error or page-level horizontal overflow; lapsed tenant shows no change buttons | PASS (manual review) |
| Demo books (maju-jaya): 64 posted journals balanced, AP / AR / asset / cash-bank reconciliations MATCHED | PASS |
| Docker image build / `compose up` | NOT RUN (sandbox proxy TLS, unchanged since OA0) |
| Load / volume benchmark of budget vs actual, depreciation runs, tax report | NOT RUN (indexes inspected, no load test) |
| Browser E2E script of a full OA4 flow | NOT RUN (pages checked with screenshots and component tests) |
| CI workflow on the PR | see the PR |

## Known issues (non-blocking)
- No FX revaluation of open foreign balances; credit notes, expenses, cash / bank transactions, bank accounts and manual journals are functional only; a payment cannot span currencies; no automatic rate feed.
- Wide tables (asset allocation, foreign payment allocations) scroll inside their container at 1440 and below. Frontend still ships one large chunk (Vite warning).
- Concurrency tests truncate and reseed the database (CI runs sequentially). OA0 leftovers unchanged (audit_logs allows TRUNCATE; `access.user.manage` can revoke higher-role members; permission texts English only).

## Remaining / operations
- Owner: after deploying to a SaaS installation, re-register the manifest in OptiNexus (21 new permissions; the four modules and their features were catalogued since OA0, no new events): `OPTINEXUS_ONBOARDING.md` §4. Per tenant, apply the asset and FX posting-rule defaults (`asset-rules/defaults`, `fx-rules/defaults`).
- Owner decisions: FX revaluation policy (period-end journals, auto-reversal); whether vendors / customers should default to a foreign currency; when to merge (the thread does not merge without the request).

## Next phase dependencies (OA5)
Reuses `PostingEngine`, the document workflow, tax transactions and the Budget vs Actual service for reporting. Not started: needs explicit owner instruction. Does not need OptiFleet.
