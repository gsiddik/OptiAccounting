# OA6 Status — Integration Platform & OptiFleet Connector (OptiEntry)

Branch: `claude/project-thread-b9jx47` (restarted from `main` b6e6817 after OA5 / PR #8 was merged). Status: **IN PROGRESS** (draft PR, merge only on owner request).
Brief: none in `docs/specs/` (roadmap: "no brief yet"). Scope = `docs/architecture/INTEGRATION_ARCHITECTURE.md` (pipeline, §7.1 owner decision: OptiEntry **pulls** a cursor feed from OptiFleet-v2, OptiNexus unchanged) + `docs/specs/MASTER.md` §65 + project instruction 8.
Owner started OA6 on 2026-10-11 (option C: work in checkpoints, may stop midway because the weekly usage limit is almost spent). Integration should have come earlier, so keep it lean and essential.

## Plan (lean slice, in this order)
- A Core (OptiEntry): `integration_connections`, `external_events` (idempotent inbox, retry, dead letter), `external_dimensions`, `external_source_links`; connection lifecycle; ingest + event gate; adapter interface; scheduled pull + sync-now; permissions; audit
- B OptiFleet adapter: `optifleet.workshop_invoice.recorded/corrected/cancelled` and `.payment_recorded` become AP invoice / payment through the existing AP services (never accounts chosen by the adapter)
- C OptiFleet-v2: one read-only feed endpoint in new files, local branch only; **conflict check and report to Gunady before any PR there**
- D Lean UI (connections, event inbox with retry), targeted tests, gate, status

## Completed
- **A Core** (committed): migration `2026_10_17_100001` (connections, idempotent external_events with append-only envelope trigger, dimensions, source links), 3 permissions `accounting.integration.view|manage|retry`, ConnectionService, EventInbox (idempotent, conflict detection, cutover, tenant from connection), adapter contract + registry, EventProcessor (isolated failures, backoff, dead letter after 5), RetryService, FeedClient/PullService (cursor advances only after the page is stored), `integration:pull` and `integration:process` scheduled every 5 minutes, API under `/api/v1/app/integration`. Tests: 42 feature + 2 concurrency passed; impacted older suites passed; full regression NOT RUN yet.

- **C OptiFleet-v2 feed** (local branch `claude/oa6-accounting-feed` in OptiFleet-v2, commit 1e1bce5, **not pushed, no PR**: owner asked to be told first): one new migration (3 tables: gap-free sequence, append-only feed events, hashed credentials), `AccountingFeed` domain, `GET /api/v1/integration/accounting-feed?after=&limit=` with a tenant-scoped bearer token, commands `accounting-feed:issue-credential|revoke-credential|backfill`, `docs/integration/ACCOUNTING_FEED.md`; one line in `bootstrap/providers.php`. Open PRs there: none; 3 stale unmerged `claude/*` branches, no overlapping files. 15 new + 128 existing OptiFleet tests passed. Events: `optifleet.workshop_invoice.recorded|corrected|cancelled|payment_recorded` and informational `maintenance_memo.billed|paid`. Vendor invoices, goods receipt, stock issue and spare part sale are not in the feed (OptiFleet has no outbox events for them yet).

## Not done / next step
B OptiFleet adapter (in progress), D UI, gate, PR ready. Not in the lean slice: push endpoint, webhooks, CSV adapter, outbox relay, MISSING reconciliation, historical backfill, gap-policy behaviour. Waiting on the owner: permission to push the OptiFleet-v2 branch and open a draft PR there.

## Important decisions
None yet beyond `INTEGRATION_ARCHITECTURE.md` §7.1. Memo-vs-invoice recognition (ROADMAP open decision 3): default = recognise only at the workshop invoice; memo events are stored but not posted.

## Tests / Known issues
Not run yet.

## Next phase dependencies
OA7 is not started and needs explicit owner instruction.
