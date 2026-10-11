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
- Nothing yet (this file is the first checkpoint).

## Not done / next step
Everything above. Next step: batch A.

## Important decisions
None yet beyond `INTEGRATION_ARCHITECTURE.md` §7.1. Memo-vs-invoice recognition (ROADMAP open decision 3): default = recognise only at the workshop invoice; memo events are stored but not posted.

## Tests / Known issues
Not run yet.

## Next phase dependencies
OA7 is not started and needs explicit owner instruction.
