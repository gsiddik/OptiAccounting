# OA0-N Status — OptiNexus identity adapter

Branch: `claude/project-thread-b9jx47`. Status: **COMMIT READY (PR open)**. OA1 not started on this branch.
Design: `docs/architecture/OPTINEXUS_ADAPTER.md`. Operations: `docs/integration/OPTINEXUS_ONBOARDING.md`.
**OptiNexus was not modified** (it was run from a scratch copy for the E2E below).

## Completed batches
- A OIDC sign-in (code + PKCE S256, JWKS RS256, userinfo cross-check, one-time ticket → Sanctum token)
- B linking: tenant `optinexus_tenant_id` (JIT only when OptiNexus confirms ACTIVE + app enabled), user `optinexus_subject`, membership under capacity reserve
- C `PermissionSource` seam: permissions pulled per tenant+user through `POST /authorization/check`, cached ≤ 300 s, fail closed (503)
- D `EntitlementProjector`: subscription, modules, features, capacity limits → local tables with `source = OPTINEXUS`
- E Back-Channel Logout receiver (logout + access-revoked), deactivation markers, reactivation on next sign-in
- F transactional outbox + relay (`optiaccounting.*` events, security audit events), backoff, `--retry-failed`
- G guards (`409 MANAGED_BY_OPTINEXUS`), break-glass rule, manifest + check/sync/relay commands
- H frontend: SSO login door, `/sso/callback`, RP-initiated logout, `canEdit`/`managedExternally`, "dikelola di OptiNexus" notices, Indonesian error texts
- I real-OptiNexus E2E (below); docs

## Important implementation decisions
- Authorization code never reads the identity mode; only `PermissionSource` and the entitlement rows differ. `EffectiveAccess` unchanged.
- Data scope stays local: first provisioning derives TENANT/OWN from the widest OptiNexus scope, later sign-ins never overwrite local narrowing.
- Subscription = most restrictive live OptiNexus subscription (the machine API does not say which product a subscription belongs to).
- No live subscription ends module access but keeps the last known capacity limits (found in E2E: it used to become "unlimited").
- Sign-out ends the OptiNexus session too and returns to `/login` (`post_logout_redirect_uris` is part of the manifest).
- No batch permission endpoint: one `authorization/check` per catalog permission per active user per ≤ 5 min (a batch endpoint would be an OptiNexus change; not requested).

## Migrations (additive)
`2026_10_08_200001_add_optinexus_adapter`: `optinexus_deactivated_at` (users, tenant_users, tenants), `optinexus_synced_at` (tenants), `outbox_events` (CHECKs on kind/status).

## Security / invariants
Tenant only from the verified `tenant_id` claim; state/nonce/verifier server-side and single use; `iss`/`aud`/`exp`/`iat`/`nonce` checked; e-mail linking only when verified and never re-bound; logout token `typ`, `jti` replay, no `nonce`; secrets never in the manifest, audit or logs; ticket 60 s single use; unlinked tenants never touched by the relay. Covered by 109 adapter tests (mutation spot-checks on nonce, audience and e-mail-verified rules failed the right tests).

## Tests (executed this session)
| Check | Result |
|---|---|
| Backend `php artisan test` (268 tests, 1812 assertions, PostgreSQL), Pint, composer validate/audit | PASS |
| Adapter tests with an in-memory OptiNexus: sign-in 40, permissions 10, entitlements 17, logout 18, outbox 14, manifest/commands 6, guards 4 | PASS |
| Frontend `npm test` (55 tests, +18), `oxlint`, `npm run build`, `npm audit` (0 vulnerabilities) | PASS |
| Screenshots 1440/820/390 of 8 changed screens (API mocked), no horizontal overflow; kept in `oa0n-qa/` | PASS (manual review) |
| **E2E against a real OptiNexus** (Laravel app booted from a scratch copy, own PostgreSQL DB, real OptiAccounting backend + SPA, headless Chromium): | |
| – `optiaccounting:nexus:check` (discovery, JWKS, service token and scopes) | PASS |
| – registration through OptiNexus's REST API from the manifest (app, 39 capabilities, 11 permissions, 3 events, plan, subscription, tenant, 3 people, OIDC client, service account) | PASS |
| – browser sign-in: SPA → OptiNexus login → callback → ticket → dashboard; tenant and user provisioned, 12 modules / 27 features / limits 5-3-10 projected | PASS |
| – permissions: admin role gets all 11, viewer exactly the 7 `.view`, person without role refused (`no_permissions`) | PASS |
| – subscription suspend → sync → NONE (modules off), reactivate → sync → FULL | PASS |
| – outbox relay: 2 events + 1 audit accepted by OptiNexus; replay of a delivered event id is not duplicated | PASS |
| – central logout (`force-logout`): token 401, other users untouched; access removal: token 401, account INACTIVE + marker; access restored + sign-in: ACTIVE, marker cleared | PASS |
| – sign-out in the SPA: ends the OptiNexus session, returns to `/login`, next sign-in asks for a password | PASS |
| Docker image build / `compose up` | NOT RUN (unchanged from OA0: sandbox proxy TLS) |
| CI workflow | NOT RUN (first run on the PR) |
| Real OptiNexus: permission revocation waiting out the cache TTL, tenant picker for a person in several tenants, Back-Channel retry after downtime | NOT RUN (covered only by in-memory tests) |

E2E caveat: the sandbox has PHP 8.3 while OptiNexus's lock needs 8.4, so the scratch copy re-resolved its dependencies (`composer update`); OptiNexus code itself was unchanged.

## Known issues (non-blocking)
- `application_access_revoked` arrives with scope `user`, so removing one application access deactivates the account in every tenant (OptiNexus contract; documented).
- Platform operators stay local break-glass accounts; OptiNexus-authenticated operators are out of scope.
- Permission cost is N checks per active user per 5 min until OptiNexus offers a batch call.
- A manual `relay-events` run can overlap the scheduled one; the receiver is idempotent on `event_id`, so the worst case is a repeat send.

## Remaining / next
- Owner: decide whether a batch authorization endpoint should be requested from OptiNexus (changes OptiNexus; not done).
- OA1 (Accounting core & GL) only on explicit owner instruction; financial events (`optiaccounting.journal.*`) join the outbox there.
