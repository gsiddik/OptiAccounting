# OA2 Status — Accounts Payable, Expense & Cash/Bank

Branch: `claude/project-thread-b9jx47` (from `main` 1384d57, after OA1 merged). Status: ****COMMIT READY** (PR open, merge only on owner request)**.
Brief: `docs/specs/OA2.md`. Design: `docs/architecture/PAYABLES_CASH_BANK.md`. Gate traceability: `docs/status/oa2-qa/GATE_TRACEABILITY.md`.
OptiNexus and OptiFleet-v2 were not modified. OA3 has not been started.

## Completed batches
- A vendors + payment terms (document history table, DB document guards, 35 permissions, 3 features) · B vendor invoice: draft → submit → approve → post, duplicate-number control, scope, SoD
- C posting through `PostingEngine::postEvent`, reversal via the shared `ReversalService` · D vendor payments + allocations · E AP aging + AP-to-GL reconciliation
- F expense categories + expenses (payable path creates a POSTED `ap_invoices` row of origin EXPENSE; directly paid path credits cash/bank)
- G cash/bank accounts · H cash payments/receipts + manual bank reconciliation · I exports, operational summary, audit, default posting rules
- J React UI (vendors, invoices, payments, aging, reconciliations, expenses, cash/bank, statements; READ_ONLY aware) · K concurrency tests · L access matrix · M–N this release gate

## Important implementation decisions
- Outstanding is derived (invoice total − allocations of POSTED payments), never stored. A payment is allocated in full before approval (no vendor advance), so no payable goes negative.
- All postings use `PostingEngine::postEvent`; OA2 has no debit/credit engine. `CASH_BANK_ACCOUNT` / `DOCUMENT_ACCOUNT` roles are bound to the document; `ACCOUNTS_PAYABLE` may only be credited by AP events (`restricted_events`).
- A document touching another module needs it writable (`MODULE_NOT_AVAILABLE`); every posting/reversal also needs `ACCOUNTING_CORE` writable (`assertLedgerWritable`).
- READ_ONLY keeps reads and refuses mutations on module-bound routes only (OA0 decision); user, role and organization administration stays editable.
- Cash transactions have no approval step: creator prepares, a holder of `accounting.cash_transaction.post` posts (creator ≠ poster by profile flag).
- Bank reconciliation is manual and never writes the ledger; one book line is matched once (unique partial index + trigger). A difference is reported, never adjusted.
- Exports share the list query and `ListFilters`, are capped (`optiaccounting.export_max_rows`), audited, CSV-formula-safe and never contain bank numbers.
- `GET operational-summary`: a section is `null` unless the user passes the central resolver for that module and feature; `complete=false` for a non-tenant-wide scope.
- OA1 demo events (`EXPENSE_RECOGNIZED` rule that booked AP outside the subledger) were removed from `DemoAccountingSeeder`; `DemoOperationalSeeder` replaces them, AP↔GL reads MATCHED.
- List flags are sent as `1`/`0` by the frontend (Laravel `boolean` rejects the text `true`).
- History order: `audit_logs` and `journal_transitions` stored `occurred_at` with one-second resolution, so same-second events had no defined order (found by a flaky full-run test). 100010 widens them; `document_transitions` is created with microseconds. Older rows keep whole seconds.
- Frontend does no accounting math; every total, balance and aging bucket comes from the API.

## Migrations (additive, 10 files `2026_10_10_1000xx`)
100001 document history + guard functions · 100002 payment terms, expense categories, vendors · 100003 feature backfill · 100004 ap_invoices + lines + triggers ·
100005 account role binding · 100006 cash_bank_accounts · 100007 vendor_payments, allocations, deferred allocation check · 100008 expenses + guards ·
100009 cash_transactions, bank_statements, bank_statement_items + guards · 100010 history timestamps (`audit_logs`, `journal_transitions`) widened from seconds to microseconds (no table rewrite). `migrate:fresh --seed` ×2 from empty, DemoSeeder and re-seed idempotent.

## Security / invariants (service AND database, each tested)
POSTED documents immutable (`oa2_document_guard`, per-document post guards) · over-allocation refused under a row lock and by a deferred DB check · composite FKs keep every relation in one
tenant · a payable of origin EXPENSE needs a POSTED expense of the same journal and total · one reversal per document · no posting into CLOSED periods · tenant id never taken from the client ·
anonymous 401, permission declared on every route, mass-assignment scan (`SecurityTest`) · only a masked bank number is stored.

## Tests (executed this session)
| Check | Result |
|---|---|
| Backend full suite `phpunit --testsuite Unit,Feature` (PostgreSQL 16): 497 tests, 24 286 assertions, 0 failures, 0 warnings | PASS |
| – OA2 feature tests, 120: Payables 61 (incl. the access matrix), Expense 24, CashBank 22, Operational 13 (every workflow state, posting, reversal, closed period, immutability + DB tamper attempts, allocation limits, reconciliation, SoD, scope, exports, summary, audit) | PASS |
| – Access matrix, 13 of those tests / 102 route-method pairs: pinned permission, 403/404 for unknown and foreign ids, per-module READ_ONLY / SUSPENDED / DISABLED / expired, PAST_DUE, disabled feature, lost ACCOUNTING_CORE, no trace across 16 tables | PASS |
| – OA0, OA0-N and OA1 regression with the new routes and permissions: Accounting 108, OptiNexus adapter 109, root Feature tests 158 (RBAC, scope, entitlement, security, seeders), Unit 2 | PASS |
| Concurrency suite (OA1 13 + OA2 14 tests, real parallel PHP processes on one database), run twice | PASS (27 tests, 630 assertions) |
| – OA2 races: one invoice/payment/expense/cash document posted or reversed by several requests, gapless numbers, two payments for one balance, invoice reversal vs payment posting, one book line matched twice, completion vs match | PASS |
| Pint `--test`, `composer validate`, `composer audit` | PASS |
| Frontend `oxlint`, `vitest` 354 tests / 18 files, `npm run build` (tsc + vite), `npm audit --omit=dev` (0) | PASS |
| Real-browser E2E (headless Chromium, real API; 13 steps: invoice → payment → settled, expense, cash receipt, statement matching, READ_ONLY), 87 screenshots at 1440 / 820 / 390 in `oa2-qa/`: no failed request, console error or horizontal overflow | PASS (manual review) |
| `migrate:fresh --seed` ×2, DemoSeeder ×2 (idempotent), `db:seed` again, rollback + re-apply of migration 100010 on a seeded database (366 audit rows) | PASS |
| Demo books: AP control = subledger + opening balance (MATCHED), every journal balanced | PASS |
| Docker image build / `compose up` | NOT RUN (sandbox proxy TLS, unchanged since OA0) |
| Load / volume benchmark of AP aging, reconciliations and exports | NOT RUN (query-count guards only) |
| CI workflow on the PR | PENDING (first run on the PR) |

## Known issues (non-blocking)
- No attachments: the repository has no secure file storage, documents carry a `supporting_document` reference only.
- Single functional currency (`CURRENCY_NOT_SUPPORTED`); no vendor advances / prepayments; one approver per document; no bank-feed import or auto-matching.
- Frontend ships one large chunk (Vite warning); route-level code splitting is a later task. Concurrency tests truncate and reseed the database (CI runs sequentially).
- OA0 leftovers unchanged: audit_logs does not block TRUNCATE; `access.user.manage` can revoke higher-role members; permission texts English only.

## Remaining / operations
- Owner: after deploying to a SaaS installation, re-register the manifest in OptiNexus (35 new permissions, 3 features, no new events): `OPTINEXUS_ONBOARDING.md` §4.
- Owner: decide when to merge; the thread does not merge without the request. OA3 starts only on explicit instruction.

## Next phase dependencies (OA3 — receivables and revenue)
Reuses `PostingEngine`, document workflow and `document_history`, `cash_bank_accounts` (receipts), `ListFilters`, `ApSubledgerService` pattern. Does not need OptiFleet.
