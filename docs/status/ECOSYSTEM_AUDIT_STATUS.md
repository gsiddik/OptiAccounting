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

- A03 consumer: `NexusPermissionSource` makes one `POST /authorization/effective-permissions` per user (was 136 `/authorization/check`),
  keeps `code => scope` per permission (`OWN` narrows a resource check; a new membership's data scope starts from the narrowest grant),
  accepts only exact catalog codes and a matching subject/tenant/application/schema; `NexusApi::send` renews a revoked token once
  (single-flight), honours `Retry-After` within a cap, repeats a read once on 502/503/504; no per-permission fallback. `optientry:nexus:check`
  reports an unbound account and an OptiNexus without the endpoint. Design: `OPTINEXUS_ADAPTER.md` section 4.

- A04/A05/A07/A13 container package: `.dockerignore` (no env files, vendor, caches, logs, tests or docs in the image), image without a baked-in
  environment, one `bootstrap` job (bounded database/Redis wait, migrate, production-safe seed) and runtime roles that wait for a current
  schema and never migrate, identical `environment:` block for api/worker/scheduler/bootstrap, no bind-mounted source (the nginx image gets
  only a template; the SPA image proxies `/api` on its own origin so it carries no URL), ports 8200 (API) / 5190 (console) published on
  127.0.0.1, listening on the same port inside the container so one `alias:port` URL works for browsers and other containers, required
  `APP_KEY`/`DB_PASSWORD`, project name and volume keys unchanged. `VITE_ALLOWED_HOSTS` for the dev server.

- E1 backend (R08/A25/A02): in `optinexus` mode an Admin Tenant creates/links users, sets role assignment, removes access and manages custom roles
  from Entry through the OptiNexus delegation API (`/app/delegation/*`, acting user = signed-in user's `optinexus_subject`). No second authority:
  lists are live Nexus answers, local rows are a projection (INVITED membership, default data scope OWN), permissions still come from the bulk
  effective-permissions call. Seat precheck + compensation on a lost race, every documented Nexus delegation error code translated (+ `details.reference`),
  `outcome_unknown` on write timeouts, per-role-name create lock, Entry audit entries `delegation.*`. Manifest v2 (`manifest_version`, content
  `revision`, per-permission `capability_code` + `delegable`, `tenant.delegate` scope) and the explicit delegable set (101/136; governance,
  credentials, period close, integration connections excluded; rationale in `OPTINEXUS_ONBOARDING.md` section 5). Standalone is labelled legacy;
  migration plan `docs/architecture/LEGACY_IDENTITY_MIGRATION.md` (no auto-migration). Design: `OPTINEXUS_ADAPTER.md` sections 9-10.
- E1 frontend: i18n layer (`frontend/src/i18n`: one `{id, en}` entry per key, typed keys, language switch in the top bar, default `id`, choice
  kept in `localStorage`); `describeError`, `statusLabel`, modals, pagination, scope editor already use it. Settings > Users (list from OptiNexus,
  add/link with optional initial password, role multiselect, data scope, remove access, seat banner) and Settings > Roles (templates + custom,
  editor grouped by module, non-delegable/not-entitled permissions shown unavailable, catalog tab) in `pages/tenant/delegation/`; legacy pages
  stay for `standalone` and as read-only fallback while delegation is not set up. States: loading, empty, forbidden, provider-unavailable,
  not-set-up, error, each with the `X-Request-Id` reference (`ApiError.requestId`, `lib/errors.ts` shared with the error boundaries).

## Tests (executed in this session)
- A03: `tests/Feature/Optinexus` 121 passed (21 in `NexusPermissionTest`: >120 permissions in one request, wildcard/unknown-key/mismatched-answer
  refusal, per-permission scope, 429 + Retry-After bounded, 502 repeat, revoked token, rotated secret, many users/tenants, unbound account,
  older OptiNexus, TTL expiry, outage fail-closed). Baseline reproduced on a real OptiNexus before the fix: 136 calls -> 119 x 200 + 17 x 429
  (no `Retry-After`), `pull()` = IdentityProviderUnavailable even from a cold start; after the fix 136 permissions in 1 request, 60-110 ms.
- `tests/Feature/Integration`: 94 passed (35 in `IntegrationHardeningTest`: 18-case malformed-page matrix, four gap policies, resolution
  PROCESS/DISMISS, source-head refusal, pause during a page, conflicts, snapshot basis, backlog fairness). Concurrency
  `IntegrationConcurrencyTest`: 4 passed (pause waits for processing in flight; retry during a committing pause is refused).
- E1 backend: `tests/Feature/Optinexus/TenantDelegationTest` (~50, incl. 21 error-code translations), `ManifestV2Test` (6), `ManifestAndCommandsTest`.
  Full backend suite, `OPTIENTRY_IDENTITY_MODE=standalone` (the sandbox `.env` forces `optinexus`, which makes the standalone-mode Auth/Rbac/
  Security/Capacity tests fail with 403 independent of any change): 1373 passed, 1 skipped (frontend sources not in the container), 1 failed
  (`SeederAndBootstrapTest` reads `../docs/DEMO.md`, which the backend-only container mount lacks; 16 passed with `docs/` mounted at `/docs`).
- E1 frontend (`cd frontend && npx vitest run`): 53 files, 1148 passed (24 in `Delegation.test.tsx`: list/create/outcomes/refusals/roles/states/English,
  8 in `i18n.test.ts`, 19 in `errors.test.ts`); `npx tsc -b` clean; `oxlint` only fast-refresh warnings. Backend
  `DelegationMessagesTest` (every error code the delegation layer emits has an id+en message; needs `frontend/` mounted at `/frontend`).
  Browser (Playwright/Chromium against the Vite dev server with the API mocked): Users/Roles/dialogs/states at 1440, 820, 390: no horizontal overflow.
- Pint clean.
- Container package (sandbox, Ubuntu php-fpm image instead of the Alpine one, which cannot be built here): `docker compose config` OK;
  bootstrap -> api/worker/scheduler/web/ui healthy; bootstrap re-run "Nothing to migrate"; runtime role refuses an unmigrated database after
  the bounded wait; bootstrap refuses `optinexus` mode without `OPTINEXUS_BASE_URL` and an unreachable database after the bounded wait;
  SPA built on the host and served through the real nginx template (`/`, `/login`, assets immutable, `index.html` no-store, `/api` proxied).
  Console bundle before A15: `index-*.js` 1,285,035 B (319.5 kB gzip), CSS 19,233 B.

## Not done yet / NOT RUN
- Production image build (`docker build` with Alpine) and a Windows Podman run: NOT RUN here (Alpine mirror blocked, no Podman).
- UI for gaps, conflicts, outcomes, dismiss and English/Indonesian labels (A26); OptiFleet-v2 must publish `high_water` before a paused connection
  can be switched on against it; full regression, frontend tests, screenshots and a live two-backend pull are pending (see central checkpoint).
