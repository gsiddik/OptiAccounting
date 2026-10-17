# Ecosystem audit hardening (OptiEntry side)

Branch `claude/project-thread-0cf20u` (from `main` 20ee792). Owner brief 2026-10-12: fix A01-A29 / R01-R08 of the ecosystem audit across the five
repositories. The central traceability matrix and checkpoint live in the OptiNexus repository (`docs/ecosystem/`); this file covers only what
changed in OptiEntry. Merge only on owner request. Scope update 2026-10-17: the active batch is the Fleet->Entry financial feed (A01, A17-A22, A27,
A28) and its OA6 UI (A26); A12/A15 (error boundaries, lazy loading, bundle) are BACKLOG and not started.

## Done in this repository
- A17 snapshot-basis hold, A18 gap lifecycle and policies, A20 pause/disconnect boundary (lifecycle version, lock order), A21 payload-conflict
  evidence and resolution, A22 strict feed page validation, A27 accounting outcome separate from processing status, A28 per-connection
  scheduling and backlog figures. Design: `docs/architecture/INTEGRATION_ARCHITECTURE.md` section 10. Migration `2026_10_18_100001` (additive).
  API additions without a new permission (manifest unchanged): `POST events/{id}/dismiss`, `POST events/{id}/conflict-resolution`, `GET gaps`,
  `GET gaps/{id}`, `POST gaps/{id}/resolution`; summary/event detail gain `outcomes`, `backlog`, `open_gap`, `accounting_outcome`, `conflicts`.
- A03 consumer: one `POST /authorization/effective-permissions` per user (was 136 checks), `code => scope` kept, exact catalog codes and matching
  subject/tenant/application/schema only; `NexusApi::send` renews a revoked token once, honours `Retry-After` within a cap, repeats a read once on
  502/503/504; no per-permission fallback. `optientry:nexus:check` reports an unbound account. Design: `OPTINEXUS_ADAPTER.md` section 4. Baseline
  before the fix on a real OptiNexus: 136 calls -> 119 x 200 + 17 x 429, `pull()` failed even from a cold start; after: 136 permissions in 1 request, 60-110 ms.
- A04/A05/A07/A13 container package: `.dockerignore`, no baked-in environment, one `bootstrap` job and waiting runtime roles, identical
  `environment:` block, no bind-mounted source, ports 8200/5190 on 127.0.0.1, required `APP_KEY`/`DB_PASSWORD`, `VITE_ALLOWED_HOSTS`.
- E1 (R08/A25/A02): in `optinexus` mode an Admin Tenant manages users and custom roles from Entry through the OptiNexus delegation API
  (`/app/delegation/*`); no second authority (live Nexus lists, local rows are a projection), seat precheck + compensation, every Nexus error code
  translated, manifest v2 with the explicit delegable set (101/136). Standalone is labelled legacy; plan `LEGACY_IDENTITY_MIGRATION.md`.
  Frontend: i18n layer (`frontend/src/i18n`, `{id, en}` per key, default `id`, switch in the top bar), Settings > Users / Roles in `pages/tenant/delegation/`.
- E2 (A29): `GET /app/product-context` (cache <= 300 s, fail closed, refusals never cached, foreign answers rejected), `allowed=false` ends the session,
  `tenant_hint` validated on SSO start, product switcher. Design: ADAPTER section 11.
- E3 (A23): immutable registry `service_principals` replaces the `*.optientry.invalid` seat exemption; rows only from `optientry:service-principal:issue|list|
  revoke` and the platform API; persons can never hold the reserved range; `OptiFleetActor` only looks up (no live principal = `CONNECTOR_NOT_ENTITLED`,
  events wait). Migration `2026_10_19_100001`. New tenants need the operator issue step.
- E4 backend, consumer side of the Fleet contract (A19, A17, A27; migration `2026_10_19_200001`; design `INTEGRATION_ARCHITECTURE.md` section 11):
  `pairing.nexus_tenant_id` of every page vs the organization's `optinexus_tenant_id` (`OPTIENTRY_FEED_PAIRING=auto|required|off`; auto = required in
  `optinexus` mode); REFUSED (not counted toward ERROR, 15 min back-off) vs UNAVAILABLE (counted, `Retry-After` <= 1 h) vs INVALID (incl.
  `FEED_CURSOR_AHEAD`) vs OptiEntry holding the connection (`access.entry`); activation probe; `high_water`/lag; `review.required` -> NEEDS_REVIEW
  `SOURCE_REVIEW_REQUIRED` (not in a gap's batch decision); live `books` state (`IN_LEDGER` only is in the GL); `ConnectionHealth` alerts and
  `integration:health [--json]` (hourly, critical log + exit 1 on ALERT, read-only). Event/summary/connection APIs gain `books_state`, `books`, `access`, `source`, `health`.
- E4 frontend (A26/A27): connection and event screens, gap decision, nav labels in English and Indonesian (`i18n/messages/integration.ts`, `nav.ts`);
  access/pairing/health/source/books cards, gaps card with PROCESS/DISMISS, content-conflict diff, "Selesai diproses" instead of "Diposting".

## Tests (executed in this session)
- Full backend (`docker run ... php artisan test`, `OPTIENTRY_IDENTITY_MODE=standalone`, Postgres 16, `docs/` and `frontend/` mounted, includes
  `tests/Concurrency`): **1485 passed, 0 failed, 0 skipped** (121,473 assertions, 1762 s) at `a80f1d2`. `vendor/bin/pint --test`: PASS (624 files).
  The sandbox `.env` forces `optinexus`; run in that mode the standalone-mode Auth/Rbac/Integration tests fail with 403/pairing independent of any change.
- Frontend at `a80f1d2` (`cd frontend && npx vitest run`): 55 files, **1206 passed**; `npx tsc -b` clean; `npx oxlint` clean (the one
  `productContext.ts` ref warning of E2 is fixed).
- E4 targeted: `FeedPairingTest` 32 (pairing match/missing/other tenant/other applications, not linked, off/auto matrix, ten source answers apart,
  back-off and scheduler, since stays, cursor ahead, activation probe/refusal, resume, quarantine hold/retry/dismiss, gap interplay, DB checks),
  `IntegrationHealthTest` 13 (books states, one query set per page, every alert, command exit code + critical log, read-only, schedule); all
  `tests/Feature/Integration` + `IntegrationConcurrencyTest` 143 passed. Frontend `integration.feed.test.tsx` 38, `integration.test.tsx` 36.
- E3 `ServicePrincipalTest` 29, `SsoSignInTest` 41, `Sso.test.tsx` 19; concurrency suites 82 passed after dropping the TRUNCATE trigger (the suites reset with
  `truncate tenants cascade`). E2 `ProductContextTest` 16, `ProductContextContractTest` 5 (real socket), `TenantHintTest` 13, `ProductSwitcher.test.tsx` 20.
  E1 `TenantDelegationTest` ~50, `ManifestV2Test` 6, `Delegation.test.tsx` 24, `i18n.test.ts` (catalog parity of every key). A03 `NexusPermissionTest` 21.
- Browser (Playwright/Chromium vs the Vite dev server, API answered from fixtures): connection list, connection detail (refused/held, Indonesian and
  English), gap decision dialog, event inbox, event detail (quarantine, draft vs ledger, content conflict + decision dialog) at 1440, 820, 390:
  no horizontal page overflow (wide tables scroll inside their card); screenshots kept outside the repository.
- Container package (sandbox): `docker compose config` OK; bootstrap -> api/worker/scheduler/web/ui healthy; bootstrap re-run "Nothing to migrate";
  runtime role refuses an unmigrated database; SPA served through the real nginx template.

## Not done yet / NOT RUN
- Production image build (Alpine) and a Windows Podman run: NOT RUN here (Alpine mirror blocked, no Podman).
- Live two-backend Fleet->Entry pull against a real OptiNexus: NOT RUN (no OptiNexus/OptiFleet stack reachable for the Entry worker); wire and contract
  covered by the faked-HTTP tests above. Entry-side commands for it: `INTEGRATION_ARCHITECTURE.md` section 11.
- A12/A15 (error boundaries, lazy-loaded routes, bundle size; console bundle before: `index-*.js` 1,285,035 B): BACKLOG.
- OptiNexus contract gaps: `MANIFEST_IMPORT` importer and the service-account scopes `tenant.delegate` + `authorization.check` must exist before R08 works live.
