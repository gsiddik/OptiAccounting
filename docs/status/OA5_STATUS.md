# OA5 Status — Financial Reporting, Closing & Reconciliation (OptiEntry)

Branch: `claude/project-thread-b9jx47` (from `main` a46bbd7, after OA4 / PR #7 was merged). Status: **COMMIT READY** (PR open, merge only on owner request).
Brief: `docs/specs/OA5.md`. Design: `docs/architecture/OA5_REPORTING_CLOSING_RECON.md`. Gate traceability: `docs/status/oa5-qa/GATE_TRACEABILITY.md`.
OptiNexus and OptiFleet-v2 were not modified. OA6 has not been started. **FX revaluation: NOT IMPLEMENTED** (owner decision 2026-10-11, realised FX only).

## Completed batches
- A–B Reporting foundation (advanced trial balance and GL filters, covering index), versioned report definitions and COA mapping · C–E balance sheet, P&L, cash flow (indirect), comparatives (prior period, prior year, Budget), drill-down, CSV export, integrity tests
- F–H Central reconciliation (AP, AR, cash/bank, fixed asset, tax, FX vs GL) with runs and exceptions · I–K close readiness, closing policy and checklist, pre/soft/hard close, controlled reopen, closing history, year-end closing
- L React UI for reports, report mapping, reconciliation, closing, year end; trial balance and GL filters; fiscal calendar links to the closing pages · M independent integrity, security and concurrency tests · N regression · O this gate · OA5 demo data (`DemoOa5Seeder`)

## Important implementation decisions
- Reports are computed live from POSTED journal lines through one query path per statement; no stored balances, no hardcoded account prefixes; layouts are tenant report definitions (declarative operands, no expression interpreter).
- Year-end and closing journals carry `source_type YEAR_END_CLOSING` and are excluded from P&L, cash flow, comparatives and Budget vs Actual (defect found in the gate: Budget vs Actual now excludes them).
- Closing needs a tenant-wide data scope and a writable `ACCOUNTING_REPORTING` and `ACCOUNTING_CORE` (defect found in the gate: a read-only core ledger could still be closed). Locked checks (trial balance, pending journals, required checklist) cannot be relaxed by policy.
- Hard close, reopen and year end take the ledger gate, then the period row, and re-evaluate readiness inside the lock; a NOT_READY hard close writes no history row.
- Legacy period routes delegate to the same closing service for a tenant with `ACCOUNTING_REPORTING`; other tenants keep OA1 behaviour. Year end posts only through `PostingEngine` into the mapped `RETAINED_EARNINGS` account; a second POSTED run per fiscal year is blocked by a partial unique index.
- A reconciliation is recomputed every time; explaining or assigning an exception never changes the calculated status, and RESOLVED needs a recomputed difference of zero.

## Migrations (additive, 4 files)
`2026_10_16_100001` report definitions · `100002` reconciliation (runs append-only, exceptions) · `100003` closing (policy, checklist, history, year end, controlled-reopen guard) · `2026_10_16_200001` covering index on journal lines.
`migrate:fresh --seed` and DemoSeeder run twice from empty: the second run changes nothing (3 report definitions, 6 reconciliation runs, 2 closing events, 2 checklist templates).

## Report / closing invariants (each tested in the service and, where stated, in the database)
- Draft, submitted and approved journals never reach a statement; a posted journal counts exactly once; trial balance = GL; assets = liabilities + equity; cash flow opening + movement = closing cash.
- Closing journal balanced and immutable (triggers); history append-only (trigger); reopen needs a reason and the dedicated permission, records an event and is refused while the year has a POSTED year end.
- Posting versus hard close, soft close, reopen and year end are race-safe (real parallel requests); two simultaneous closes or year-end runs leave one result.
- Every report, export and drill-down enforces tenant, module, permission and data scope; READ_ONLY allows reads and exports and denies every mutation.

## Tests (all executed in this session)
- Backend Feature + Unit full run: 1123 tests, 1122 passed, 1 failed (SecurityTest: `status` was mass-assignable on the checklist template). Fixed; SecurityTest + Closing + Oa5 re-run: 158 passed. The full suite was not repeated after the fix.
- Concurrency suite: 78 tests, 2216 assertions, passed twice in a row (2216 and 2218 assertions).
- Frontend: `tsc -b`, oxlint, 1060 vitest tests, production build and `npm audit --omit=dev` (0 vulnerabilities) all clean; the accounting and closing folders (106 tests) re-run after the last UI fix.
- Pint clean. Screenshots of 18 OA5 pages at 1440, 820 and 390 px with demo data: no horizontal page overflow, no failed API call; two narrow-screen layout defects found and fixed.
- NOT RUN: Docker image build (no registry access in the sandbox), load test of reports beyond the 2 x 80,000 line query-plan check in the reporting batch, browser end-to-end script.

## Known issues (non-blocking)
- CSV export only (no XLSX or PDF). Comparative balance sheet or cash flow also needs `accounting.reporting.pnl.view` (one route gate). Budget comparison is P&L only. Closing history has no actor filter.
- Year-end index and show run a full-ledger preview aggregation per fiscal year. `closeFiscalYear` does not require a year-end run (as briefed).
- OA1 behaviour left as is: `ReversalService` authorises soft-closed posting with the caller's own permission; `POST journals/{id}/reverse` can reverse a year-end journal (the next year-end reverse catches such a run up); the GL export with another tenant's `account_id` returns a header-only CSV (no data exposed).
- Year end carries functional-currency amounts only and posts retained earnings per branch and business unit (not per cost center).

## Remaining
Nothing for OA5. Per tenant after deployment: apply the default report definitions (`report-definitions/defaults`), the year-end rule (`year-end/rule-defaults`) and map `RETAINED_EARNINGS`.

## Next phase dependencies
OA6 (Integration Platform & OptiFleet Connector) is not started and needs explicit owner instruction. SaaS installs re-register the manifest in OptiNexus (16 new permissions, nothing else), `docs/integration/OPTINEXUS_ONBOARDING.md` section 4.
