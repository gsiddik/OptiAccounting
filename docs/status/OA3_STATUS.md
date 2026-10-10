# OA3 Status — Accounts Receivable & Revenue

Branch: `claude/project-thread-b9jx47` (from `main` 8fcdc8d, after OA2 merged). Status: **COMMIT READY** (PR open, merge only on owner request).
Brief: `docs/specs/OA3.md`. Design: `docs/architecture/RECEIVABLES.md`. Gate traceability: `docs/status/oa3-qa/GATE_TRACEABILITY.md`.
OptiNexus and OptiFleet-v2 were not modified. OA4 has not been started.

## Completed batches
- A customers + customer profile + payment terms reused from OA2 · B customer invoice: draft → submit → approve → post, soft duplicate-reference warning, scope, SoD
- C posting through `PostingEngine::postEvent` (revenue, tax, receivable), reversal via the shared `ReversalService` · D AR subledger (derived) · E customer receipts + allocation + reversal
- F credit notes against one posted invoice · G AR aging (as-of replay) + AR-to-GL reconciliation · H cash/bank integration (ledger, cash-to-GL reconciliation, statement matching) + default posting rules
- I audit, 23 permissions, scope, entitlements, exports, operational summary · J React UI (customers, invoices, receipts, credit notes, aging, reconciliation; READ_ONLY aware)
- K integrity and concurrency tests · L tenant/security/access matrix · M OA0–OA2 regression and this release gate

## Important implementation decisions
- Outstanding is derived (invoice total − effective allocations of POSTED receipts − POSTED credit notes), never stored. A receipt is allocated in full before approval; no customer advance, so no receivable goes negative.
- New module `ACCOUNTING_AR` with features CUSTOMER, CUSTOMER_INVOICE, AR_RECEIPT, CREDIT_NOTE, AR_AGING. A receipt additionally needs `ACCOUNTING_CASH_BANK` writable; every posting needs `ACCOUNTING_CORE` writable.
- Three events, all through the engine: `AR_INVOICE_RECOGNIZED`, `CUSTOMER_RECEIPT`, `AR_CREDIT_NOTE_RECOGNIZED`; no `*_REVERSED` events. `ACCOUNTS_RECEIVABLE` may be booked only by AR events (`restricted_events`).
- Revenue account per line: line account → line role → customer default → `REVENUE` mapping (branch mapping wins). The receivable account is frozen on the invoice at posting; a later mapping change never rewrites history.
- An invoice cannot be reversed while effective allocations or POSTED credit notes touch it; a credit note cannot exceed the invoice's outstanding.
- Credit limit is information only. Debit notes and customer advances are documented extensions, not implemented.
- AR reconciliation shows an opening balance posted straight to the control account as its own component; a difference is reported, never adjusted.
- Frontend does no accounting math. List flags are sent as `1`/`0`. Customer table folds the two account columns into one so the action buttons stay visible at 1440.
- OA2 defect fixed here: Laravel formats Carbon bindings with whole seconds, so history `timestamp(6)` columns lost microseconds. `Micros::now()` (string) is now used by audit, document, journal and bootstrap history writes; older rows keep whole seconds.

## Migrations (additive, 6 files `2026_10_11_1000xx`)
100001 customers · 100002 catalog prep (features CUSTOMER and AR_RECEIPT, role `REVENUE_ADJUSTMENT`, event `AR_CREDIT_NOTE_RECOGNIZED`, restricted events) · 100003 ar_invoices + lines + guards ·
100004 customer_receipts + allocations + allocation guards (row trigger, deferred full-allocation check) · 100005 ar_credit_notes + lines + guards · 100006 `cash_bank_account_in_use()` includes receipts.
`migrate:fresh --seed` ×2 from empty, DemoSeeder and re-seed idempotent; rollback + re-apply on a seeded database.

## Security / invariants (service AND database, each tested)
POSTED invoices, receipts and credit notes immutable (service + triggers) · over-allocation refused under a row lock and by a DB trigger · a posted receipt is allocated in full (deferred DB check) · composite FKs keep
every relation in one tenant · one reversal per document · no posting into CLOSED periods · no number consumed by a refused posting · tenant id never taken from the client · anonymous 401, permission declared on every route, mass-assignment scan (`SecurityTest`).

## Tests (executed this session)
| Check | Result |
|---|---|
| Backend full suite `phpunit --testsuite Unit,Feature` (PostgreSQL 16): 589 tests, 36 760 assertions, 0 failures, 0 warnings, 0 skipped | PASS |
| – OA3 feature tests (Receivables 11 classes, 90 tests): lifecycle, posting, revenue, receipts, credit notes, aging/reconciliation, cash/bank, exports/summary, access matrix, tenant isolation | PASS |
| – OA0, OA0-N, OA1, OA2 regression with the new routes, permissions, catalog and Micros change (Accounting, OptiNexus adapter, Payables, Expense, CashBank, Operational, RBAC, scope, entitlement, security, seeders) | PASS |
| Concurrency suite (OA1 13 + OA2 14 + OA3 15 tests, real parallel PHP processes on one database), run twice: 42/42, 1 134 and 1 131 assertions, 0 failures | PASS |
| – OA3 races: one invoice/receipt/credit note posted or reversed by several requests, gapless numbers, two receipts for one balance, receipt vs credit note, many receipts vs one invoice | PASS |
| Pint `--test`, `composer validate`, `composer audit` (no advisories) | PASS |
| Frontend `oxlint`, `vitest` 593 tests / 24 files, `npm run build` (tsc + vite), `npm audit --omit=dev` (0) | PASS |
| Real-browser E2E (headless Chromium, real API, 11 steps: invoice → posted → receipt → credit note → settled → AR and cash/bank reconciliation MATCHED) | PASS |
| 50 screenshots (39 page views at 1440 / 820 / 390, 11 E2E steps) in `oa3-qa/`: no failed request, console error or page-level horizontal overflow | PASS (manual review) |
| Demo books: AR control = subledger + opening balance (MATCHED), AP unchanged, every journal balanced | PASS |
| Docker image build / `compose up` | NOT RUN (sandbox proxy TLS, unchanged since OA0) |
| Load / volume benchmark of AR aging, reconciliation and exports | NOT RUN (query-count guards only) |
| CI workflow on the PR | PASS on 6fb6bb6 (backend 16 min, frontend 1 min; first run) |

## Known issues (non-blocking)
- No attachments: documents carry a `supporting_document` reference only. Single functional currency (`CURRENCY_NOT_SUPPORTED`); no customer advances, debit notes or dunning; one approver per document.
- Demo AR data (4 customers, 7 invoices, 3 receipts, 1 credit note) appears only for tenants seeded after this change.
- Frontend ships one large chunk (Vite warning). Concurrency tests truncate and reseed the database (CI runs sequentially). Wide tables scroll inside their container at 820 and 390.
- OA0 leftovers unchanged: audit_logs does not block TRUNCATE; `access.user.manage` can revoke higher-role members; permission texts English only.

## Remaining / operations
- Owner: after deploying to a SaaS installation, re-register the manifest in OptiNexus (23 new permissions, 2 new features, no new events): `OPTINEXUS_ONBOARDING.md` §4.
- Owner: decide when to merge; the thread does not merge without the request. OA4 starts only on explicit instruction.

## Next phase dependencies (OA4 — budget, fixed asset, tax and multi-currency)
Reuses `PostingEngine`, document workflow and `document_transitions`, `AgingBuckets`, `ListFilters`. Tax rules would replace the manual tax amounts on AP and AR documents; foreign currency lifts `CURRENCY_NOT_SUPPORTED`. Does not need OptiFleet.
