# OA6 Status — Integration Platform & OptiFleet Connector (OptiEntry)

Branch: `claude/project-thread-b9jx47` (restarted from `main` b6e6817 after OA5 / PR #8 was merged). Status: **COMMIT READY, lean slice** (PR #9, merge only on owner request).
Brief: none in `docs/specs/` (roadmap: "no brief yet"). Scope = `docs/architecture/INTEGRATION_ARCHITECTURE.md` (§7.1 owner decision: OptiEntry **pulls** a cursor feed from OptiFleet-v2, OptiNexus unchanged) + `docs/specs/MASTER.md` §65 + project instruction 8. OA7 has not been started.
Owner started OA6 on 2026-10-11 (option C: lean, checkpointed because the weekly usage limit is almost spent).

## Completed
- **A Core**: migration `2026_10_17_100001` (connections, idempotent `external_events` with append-only envelope trigger, dimensions, source links), permissions `accounting.integration.view|manage|retry`, ConnectionService, EventInbox (idempotent on connection + source + event id, conflict detection, cutover), adapter contract + registry, EventProcessor (isolated failures, backoff, dead letter after 5), RetryService, FeedClient/PullService (cursor advances only after the page is stored, HTTP never inside a DB transaction), `integration:pull` and `integration:process` every 5 minutes, API `/api/v1/app/integration`.
- **B OptiFleet adapter** (`app/Domain/Integration/Adapters/OptiFleet`): `workshop_invoice.recorded` becomes an AP invoice through the existing AP services and Posting Engine (the adapter names no account), `.corrected` reverses and re-books, `.cancelled` reverses (`PAYMENT_ALREADY_APPLIED` when a payment is applied), `.payment_recorded` becomes a vendor payment, memo events are informational (POSTED, no journal). Connection `settings` (`payables_cash_bank_account_id`, `expense_category_id`, migration `2026_10_17_200001`) are required to activate; feature `OPTIFLEET_CONNECTOR` + writable AP are checked at activate/resume and in the processor.
- **C OptiFleet-v2 feed**: separate repo, **draft PR gsiddik/OptiFleet-v2#35** (CI green on 1e1bce5, never merged without the owner). New files only: tenant-scoped bearer feed `GET /api/v1/integration/accounting-feed`, gap-free sequence, append-only feed table, credential commands, `docs/integration/ACCOUNTING_FEED.md`.
- **D UI** (`frontend/src/pages/integration`): connections list and create, connection detail (summary, edit, OptiFleet settings, activate/pause/resume/disconnect, sync now), event inbox with filters and retry (reason required), event detail (envelope, read-only payload, error, source links). Menu group "Integrasi" and breadcrumbs. OA6 demo data (`DemoOa6Seeder`, `docs/DEMO.md`).

## Important decisions
- Recognition: only the workshop invoice posts; memo events are informational (ROADMAP open decision 3 default).
- **Actor**: the processor has no user and no system poster exists, so each tenant gets one credential-less service user (`optifleet.integration@<tenant code>.optientry.invalid`): no password, ACTIVE membership, no role or permission, TENANT scope, creation audited. It only prepares documents. Segregation of duties is not weakened: when approval is required the invoice stays SUBMITTED for a person; otherwise it posts only if the SoD policy lets the preparer post, else it stays DRAFT. A payment before its invoice is posted waits in NEEDS_REVIEW `INVOICE_NOT_POSTED` and a retry settles it. The user takes no seat in the user limit (CapacityService leaves out `*.optientry.invalid`).
- Vendor: `external_dimensions` mapping, then tax id (several matches = `VENDOR_AMBIGUOUS`), then code, else created once. Foreign currency is refused (`CURRENCY_NOT_SUPPORTED`, NEEDS_REVIEW). Every document has an `external_source_links` row.
- Source tenant ref and cutover are locked once events exist (`CONNECTION_LOCKED`); activation also needs a cutover date.

## Tests (executed in this session)
- Backend Feature + Unit full run: 1182 tests, 1179 passed, 3 failed. Two were `SeederAndBootstrapTest` expectations that were updated while the run was in progress (re-run: 16/16 passed). One was an order-dependent assertion in `DepreciationRunTest` (OA4, flaky: query without ORDER BY), made order-independent; 15/15 passed on four runs. The full suite was **not repeated** after these two fixes.
- Concurrency suite: 80 tests, passed twice (2243 and 2242 assertions), including 2 new integration race tests.
- New tests: 59 in `tests/Feature/Integration` (17 end-to-end adapter tests with the feed faked over HTTP, access matrix, tenant isolation, replay, conflict, closed period then retry), demo seeder test.
- Frontend: `tsc -b`, oxlint, 1097 vitest tests (37 new), production build and `npm audit --omit=dev` (0 vulnerabilities) clean. Pint clean.
- Screenshots of the four pages at 1440, 820 and 390 px (finance manager, and the READ_ONLY tenant): no horizontal overflow, no failed API call; one defect found and fixed (retry button cut off at 1440).
- OptiFleet-v2: 15 new + 128 existing tests passed locally and CI green on the PR.
- NOT RUN: a real pull between live OptiEntry and a live OptiFleet-v2 instance (the contract is tested on both sides separately; OptiEntry used `Http::fake` built from the contract), Docker image build, browser end-to-end script.

## Not in the lean slice / known gaps
- No push endpoint, webhooks, CSV adapter, outbox relay, MISSING reconciliation, historical backfill or gap-policy behaviour (the gap policy is stored, not applied). Foreign-currency invoices, branch/dimension mapping to journal lines, vendor invoices, goods receipt, stock issue and spare part sale are not handled (OptiFleet has no outbox events for the last four).
- The create dialog offers OptiFleet or a manual source code (no registry endpoint). Retry button visibility follows the status set, the API enforces it.
- `processDue` (batch of 200) could be starved by stuck unentitled events if more source systems exist.
- Demo: the integration user appears in the tenant's user list; "Sync now" on the demo connection fails on purpose (placeholder URL).

- **Pre-merge review fix (audit PR #10, 2026-10-18):** `corrected` and `cancelled` wait (`EARLIER_EVENT_UNRESOLVED`, NEEDS_REVIEW) while an earlier recorded/corrected/cancelled event of the same source invoice is undecided, so an older correction can no longer re-book an invoice the source cancelled. Tests: `OptiFleetAdapterTest` (two cases).

## Remaining after merge (deployment)
Re-register the manifest in OptiNexus (3 new permissions; `php artisan optientry:sync-permissions` on existing environments). In OptiFleet-v2: merge PR #35, run `accounting-feed:backfill` once and `accounting-feed:issue-credential` per tenant. In OptiEntry per tenant: create the OptiFleet connection, map the cash/bank account and expense category, activate.

## Next phase dependencies
OA7 is not started and needs explicit owner instruction.
