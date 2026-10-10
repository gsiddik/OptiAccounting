# Rename to OptiEntry, brand assets and page navigation

Branch: `claude/project-thread-b9jx47` (from `main` 9007a4c, after OA3 merged). Status: **READY FOR REVIEW** (merge only on owner request).
Not a roadmap phase: OA4 has not been started. OptiFleet-v2 was not modified. OptiNexus changed only in its own PR #8 (frontend: logo, icon, breadcrumb, menu, Back).

## What was renamed
- Product name in the UI, API texts, OptiNexus manifest (`application.name`), README, CLAUDE.md, architecture and integration docs, composer and npm package names, `APP_NAME`.
- Config file `backend/config/optiaccounting.php` → `backend/config/optientry.php`; keys are read as `config('optientry.*')`.
- Environment: `OPTIENTRY_IDENTITY_MODE`, `OPTIENTRY_DEMO_PASSWORD`, `OPTIENTRY_EXPORT_MAX_ROWS`, `OPTIENTRY_BOOTSTRAP_PASSWORD`.
- Artisan: `optientry:bootstrap-platform-admin`, `optientry:sync-permissions`, `optientry:nexus:{manifest,check,sync-entitlements,relay-events}`; schedule uses the new names.
- Health endpoint `service`: `optientry-api`. Local docker image tag `optientry-backend:local`.

## What deliberately keeps the old name (registered or persisted identifiers)
| Identifier | Why it stays | Change it by |
|---|---|---|
| OptiNexus application code `optiaccounting` (`OPTINEXUS_APPLICATION_CODE`) | Registered in OptiNexus; permissions, capabilities and tenant subscriptions hang on it | a new registration in OptiNexus, then the env value |
| `optiaccounting.*` permission keys and event types (`OutboxPublisher::EVENT_PREFIX`) | Registered in the OptiNexus catalog; role grants and delivered events use them | owner-approved data migration in OptiNexus |
| OIDC client ids, service-account credentials | Issued by OptiNexus | OptiNexus |
| Database, user, volume names (`optiaccounting`, `optiaccounting_test`) | An existing volume keeps its database; a new default name would not connect | set `DB_*` explicitly on a new install |
| GitHub repository `gsiddik/OptiAccounting` | The project's repository link depends on it | owner renames it on GitHub, then updates remotes |
| `docs/specs/*`, status files up to OA3 | Historical owner briefs and records | left as written |

## Compatibility for existing installations
- An `.env` with `OPTIACCOUNTING_*` keeps working (read as fallback; the `OPTIENTRY_*` name wins when both exist).
- The old artisan names are aliases of the new commands, so cron entries and runbooks keep working. Re-registering in OptiNexus is **not** required by the rename (same application code, permissions and events); the manifest now shows the display name OptiEntry, which an administrator may update in OptiNexus.
- OptiFleet-v2 has no reference to this product's name; no integration setting changed.

## Brand assets (`frontend/public/`, originals in `docs/brand/originals/`)
- Logo `brand/optientry-logo-{400,800,1200,1878}.png`, app icon `brand/optientry-icon-{32..512}.png`, `favicon.ico`, `apple-touch-icon.png`, `site.webmanifest`.
- Rasters, not SVG: automatic tracing of the supplied PNGs was visibly lumpy, so a transparent multi-resolution ladder is served with `srcset`; the largest file is the original pixel size. See `docs/brand/README.md`.
- The logo sits in its own container (`.brand-logo`, white, fixed height). The image fits the container (`object-fit: contain`) and the browser picks the width that is sharp for the container size and pixel ratio.

## Navigation (both portals, every page of `Shell`)
- Breadcrumb from one route table (`lib/breadcrumbs.ts`): Beranda › sidebar group › page; a detail page reads as its document number; the last step is `aria-current="page"`. A test compares the table with the routes in `App.tsx`.
- Sidebar groups expand/collapse (`aria-expanded`), remembered per portal in `localStorage`; the group of the current page always opens; "Ciutkan/Bentangkan semua".
- "Kembali" on every page that has somewhere to go: the previous page of this portal, else the parent page. The per-page "Kembali ke daftar" links were removed.
- Not covered: sign-in, tenant choice and SSO callback pages (outside the portal shell) have no breadcrumb or Back.

## Tests (executed this session)
| Check | Result |
|---|---|
| Backend full regression (`phpunit`, incl. `RenameCompatibilityTest` 5 and the OptiNexus adapter suite) | PASS: 594 tests, 36,785 assertions |
| Backend `pint --test`, `composer validate` | PASS |
| Frontend `vitest` (617 tests; new: breadcrumbs 10, shell 14), `oxlint`, `tsc -b`, `npm run build` | PASS |
| Screenshots 1440 / 820 / 390 in `rebrand-qa/` (30): no horizontal overflow; only `ERR_ABORTED` of requests cancelled by a page change | PASS (manual review) |
| Live OptiNexus (fresh DB, current OptiNexus code): renamed manifest registered (same application code, all old permission and event keys present), `optientry:nexus:check` and its old alias OK, SSO sign-in as a tenant user lands on the new shell, `sync-entitlements` and `relay-events` (3 delivered, 0 failed) | PASS |
| OptiFleet-v2 | No reference to the product name; integration not changed, not exercised again |
| Docker image build / `compose up` | NOT RUN (sandbox proxy TLS, unchanged since OA0) |

## Owner steps
1. Rename the GitHub repository when convenient (nothing in the code depends on its name).
2. Optional: change the application display name in OptiNexus to OptiEntry; keep the application code.
3. OptiNexus PR #8 (logo, icon, breadcrumb, menu, Back): review and merge on request.
