# OA1 Status — Accounting Core & General Ledger

Branch: `claude/project-thread-b9jx47`. Status: **COMMIT READY** (PR #3 open, CI green; merge only on owner request).
Brief: `docs/specs/OA1.md`. Design: `docs/architecture/ACCOUNTING_CORE.md`. Gate traceability: `docs/status/oa1-qa/GATE_TRACEABILITY.md`.
OptiNexus and OptiFleet-v2 were not modified. OA2 has not been started.

## Completed batches
- A profile + readiness · B fiscal years (1–18 months, any start month), periods, period guard · C chart of accounts, templates, hierarchy
- D branch / business unit / cost-center dimensions · E manual journals + lifecycle · F approval, SoD policy, gapless numbering
- G central posting engine + accounting events · H posting rules, account roles, account mapping · I reversal + opening balance
- J general ledger + trial balance (+ CSV exports) · K React UI (12 screens, READ_ONLY aware) · L concurrency tests (real parallel processes)
- M access/tenant/entitlement/scope/audit/payload tests, N+1 guard · N this release gate

## Important implementation decisions
- One posting core; fixed lock order (journal → ledger gate → accounts → period → numbering); DB triggers repeat every invariant.
- CLOSED period is terminal until OA5; period 13, year-end entries, multi-currency are out of scope (OA5 / OA4).
- Opening balance = one OPENING journal posted under the exclusive ledger gate, so "no history before cutover" is race-free.
- SoD is profile policy (never role names) and covers MANUAL and OPENING journals. Control accounts refuse MANUAL journals only.
- Reports derive only from POSTED lines; scoped/filtered reports say `complete: false`. Exports are capped at 50 000 rows and audited.
- READ_ONLY (module or subscription) keeps every read, refuses every mutation (OA0 decision: module-bound routes only).
- `journal.posted` / `journal.reversed` use the OA0-N transactional outbox. Details: `ACCOUNTING_CORE.md`.

## Migrations (additive, 6 files `2026_10_09_1000xx`)
20 tables (profile, fiscal years, periods, accounts, COA templates ×2, dimension types, cost centers, sequences, journals + lines + line dimensions +
transitions, account roles, event types, mappings, posting rules + lines, events, opening balances) with FK, unique, CHECK constraints and 15 trigger/function definitions.
`migrate:fresh --seed` ran twice from empty; DemoSeeder and a re-run of `DatabaseSeeder` on top ran clean.

## Accounting invariants (enforced in service AND database, each with tests)
Σ debit = Σ credit per journal · a line is debit XOR credit, never negative · NUMERIC(20,4), `brick/math`, no floats ·
POSTED journals/lines immutable, undeletable, cancel/edit refused · no posting into CLOSED periods (row lock) · active, postable,
same-tenant accounts and dimensions only · numbers unique and gapless · one reversal per journal, a reversal is never reversed ·
event posting idempotent on `(source_type, source_id, purpose)` · GL/TB only from POSTED lines · tenant id never taken from the client.

## Tests (executed this session)
| Check | Result |
|---|---|
| Backend full suite `phpunit` (PostgreSQL 16): 389 tests, 5203 assertions, 0 warnings in junit | PASS |
| – Accounting feature tests 108 (profile/calendar, COA, journals, rules/events, opening/reversal, ledger, dashboard, matrix, scope/audit, N+1) | PASS |
| – OA0 + OA0-N regression (RBAC, data scope, entitlement, tenant isolation, security, capacity, adapter 109) | PASS |
| – Concurrency suite, 13 tests, separate PHP processes on one database (see list below) | PASS |
| Access matrix: pinned permission per route (60 routes), each permission opens exactly its routes, cross-tenant ids 404 with no trace, 12 module/subscription/tenant states and per-feature closure on every route | PASS |
| Mutation spot-checks (dropped audit action, scope bypass) failed the right tests, then reverted | PASS |
| Pint (`--test`), `composer validate`, `composer audit` | PASS |
| Frontend `oxlint` (0 findings), `vitest` 70 tests / 9 files, `npm run build` (tsc + vite), `npm audit --omit=dev` (0) | PASS |
| Real-browser E2E (headless Chromium, real API, 2 personas), 17/17 checks, screenshots 1440/820/390 of 20 screens in `oa1-qa/` | PASS (manual review) |
| `migrate:fresh --seed` ×2, DemoSeeder, re-seed | PASS |
| Docker image build / `compose up` | NOT RUN (sandbox proxy TLS, unchanged from OA0) |
| Load / volume benchmark of GL and trial balance | NOT RUN (only a query-count guard exists) |
| CI workflow on the PR | PASS on d0fc234 (backend 4 min, frontend 24 s; first run) |

Concurrency cases: double post, gapless numbers, failed posting returns its number, post vs cancel, duplicate event, two contents for one fact,
concurrent reversals, close waits for an in-flight posting, posting during a close is refused, close vs 6 postings, two closes, opening vs
postings (both directions), opening vs earlier-dated posting (4 rounds). Lock-order cases hold a lock on a raw connection and wait for a
real waiter in `pg_stat_activity` (`pg_stat_clear_snapshot()` per poll; the view is cached inside a transaction).

## Known issues (non-blocking)
- Frontend ships one 531 kB chunk (Vite warning); route-level code splitting is a later task.
- Concurrency tests truncate and reseed the database in `tearDown`; they must not share a database with parallel test workers (CI runs sequentially).
- Single functional currency; CLOSED periods cannot be reopened before OA5; exports stop at 50 000 rows.
- OA0 leftovers: audit_logs does not block TRUNCATE; `access.user.manage` can revoke higher-role members; permission texts English only.

## Remaining / operations
- Owner: after deploying to a SaaS installation, re-register the manifest in OptiNexus (27 new permissions, 2 events): `OPTINEXUS_ONBOARDING.md` §4.
- Owner: decide when OA1 is merged; the thread does not merge without the request.

## Next phase dependencies (OA2 — AP, expense, cash & bank)
Needs from OA1: `is_control` accounts (AP, cash), account roles + mappings, posting rules and `postEvent`, dimensions, SoD policy, ledger gate, outbox.
Start OA2 only on explicit owner instruction.
