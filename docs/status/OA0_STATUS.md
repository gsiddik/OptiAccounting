# OA0 Status — Standalone SaaS foundation

Branch: `claude/project-thread-b9jx47`. Status: **COMMIT READY (draft PR, not merged)**. OA1 not started.

## Completed batches
- A Runtime/auth/tenant context · B tenant + membership + lifecycle · C dynamic RBAC + permission registry + data scope · D organization (branch, business unit)
- E module catalog + dependency engine · F bundles + subscription · G module/feature/capacity entitlement · H effective access resolver + READ_ONLY gating
- I audit + security controls · J Platform portal · K Tenant portal · L tests · M release gate

## Important implementation decisions
- Sanctum tokens carry one ability: `identity` (pick a place), `platform`, or `tenant:<uuid>`. Tenant context comes only from the token (`TenantContext`); scoping fails closed.
- One resolver (`EffectiveAccess`): user → tenant → membership → tenant match → subscription → module → feature → permission → data scope. Cached per tenant version + 300 s TTL; status gates are read fresh.
- Permissions are `resource.action` codes. System roles are immutable via API; handing out permissions or data scopes you do not hold is refused (`PRIVILEGE_ESCALATION`).
- Capacity (USER/BRANCH/BUSINESS_UNIT) is reserved under a tenant row lock; `null` limit = unlimited. Inactive users/branches/units do not count.
- Entitlement windows cannot overlap (`btree_gist` EXCLUDE). `audit_logs` is append-only (trigger).
- **READ_ONLY scope (owner may revisit):** mutation gating applies to module/feature-bound routes (financial resources, OA1+). Tenant administration (users, roles, organization) carries no module and stays writable while the subscription is overdue, so a tenant can still manage its own access. Locked by `test_tenant_administration_stays_writable_while_the_subscription_is_overdue`.
- Frontend: one capability hook (`useCapabilities().can/moduleMode/featureEnabled/readOnly`), no role names in UI, token in sessionStorage, Indonesian-only strings, tables become labelled cards ≤640 px, off-canvas menu ≤900 px, light/dark tokens.
- Demo logins: `docs/DEMO.md`. Production seeder creates no tenant/user/password; first operator via `optiaccounting:bootstrap-platform-admin`.

## Migrations (additive, 7 OA0 files)
`personal_access_tokens`, identity, access_control, organization, product_catalog, subscription_and_entitlement, audit_logs. PostgreSQL CHECKs, partial uniques, composite `(tenant_id,id)` FKs.

## Security / invariants
Cross-tenant, spoofed `tenant_id`, inactive user/membership/tenant, module/feature bypass, READ_ONLY mutation, capacity bypass, privilege escalation, mass assignment, secret exposure in audit are covered by tests (`SecurityTest`, `TenantIsolationTest`, `RbacTest`, `EntitlementAccessTest`, `CapacityTest`, `AuditTest`). No accounting business logic exists (OA1).

## Tests (executed this session)
| Check | Result |
|---|---|
| Backend `php artisan test` (159 tests, 1048 assertions, PostgreSQL) | PASS |
| Tenant isolation / RBAC + data scope / module dependency / entitlements / capacity (suites above) | PASS |
| Concurrency: 10 simultaneous add-user requests, 1 free seat, real HTTP server (8 workers) | PASS (1×201, 9×409, used = limit) |
| `migrate:fresh --seed`, seed run twice, DemoSeeder run twice (row counts unchanged) | PASS |
| Frontend `oxlint` (0 warnings), `npm test` (37 tests), `npm run build`, `npm audit` (0 vulnerabilities) | PASS |
| Screenshots 1440/820/390 on real backend + demo data, 85 captured, 17 kept in `oa0-qa/`; no overflow, console or HTTP errors in final run | PASS (manual review) |
| `docker compose config` (dummy secrets), Docker daemon start, pull of postgres/redis/nginx/php images | PASS |
| Backend image build, `docker compose up`, container health | NOT RUN: Alpine `apk` cannot verify TLS through this sandbox's egress proxy; trusting the proxy CA inside the build was refused by the session's permission policy |
| CI workflow | NOT RUN (first run on the PR) |

## Known issues (non-blocking)
- `audit_logs` blocks UPDATE/DELETE but not TRUNCATE (restrict DB privileges in PROD).
- A member with `access.user.manage` can remove roles from a higher-privileged member (only assigned roles are escalation-checked).
- Permission descriptions and module/feature names are English catalog data; only UI chrome is Indonesian.
- Audit viewers filter by action (platform also by tenant); no date range yet.
- Browser E2E is a manual Playwright script (not committed); unit/integration only in CI.
- The sandbox demo DB was reset with `migrate:fresh` after a manual API check created one throwaway branch.

## Remaining / next
- Run `docker compose up -d --build` once on a machine with Docker access (runtime NOT RUN above).
- OA0-N (OptiNexus adapter) follows OA0 per owner decision; OA1 only on explicit owner instruction.
