# Ecosystem audit hardening (OptiEntry side)

Branch `claude/project-thread-0cf20u` (from `main` 20ee792). Owner brief 2026-10-12: fix A01-A29 / R01-R08 of the ecosystem audit across the five
repositories. The central traceability matrix and checkpoint live in the OptiNexus repository (`docs/ecosystem/`); this file covers only what
changed in OptiEntry. Merge only on owner request.

## Done in this repository
- A17 snapshot-basis hold, A18 gap lifecycle and policies, A20 pause/disconnect boundary (lifecycle version, lock order), A21 payload-conflict
  evidence and resolution, A22 strict feed page validation, A27 accounting outcome separate from processing status, A28 per-connection
  scheduling and backlog figures. Design: `docs/architecture/INTEGRATION_ARCHITECTURE.md` section 10.
- Migration `2026_10_18_100001_harden_integration_lifecycle` (additive; nothing existing is rewritten).
- API additions (no new permission, so the OptiNexus manifest is unchanged): `POST events/{id}/dismiss` (retry), `POST events/{id}/conflict-resolution`
  (manage), `GET gaps`, `GET gaps/{id}`, `POST gaps/{id}/resolution` (view / manage). Summary and event detail gain `outcomes`, `backlog`,
  `open_gap`, `accounting_outcome`, `document_status`, `gap_id`, `conflicts`.

## Tests (executed in this session)
- `tests/Feature/Integration`: 94 passed (35 in `IntegrationHardeningTest`: 18-case malformed-page matrix, four gap policies, resolution
  PROCESS/DISMISS, source-head refusal, pause during a page, conflicts, snapshot basis, backlog fairness). Concurrency
  `IntegrationConcurrencyTest`: 4 passed (pause waits for processing in flight; retry during a committing pause is refused).
- Pint clean.

## Not done yet / NOT RUN
- UI for gaps, conflicts, outcomes, dismiss and English/Indonesian labels (A26); OptiFleet-v2 must publish `high_water` before a paused connection
  can be switched on against it; full regression, frontend tests, screenshots and a live two-backend pull are pending (see central checkpoint).
