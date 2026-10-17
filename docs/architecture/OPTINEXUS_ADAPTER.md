# OptiNexus Adapter (OA0-N)

Status: implemented by OA0-N. Extends `SAAS_ARCHITECTURE.md` §1; the contract
comes from OptiNexus `docs/integration/INTEGRATION_GUIDE.md`. **OptiNexus is not
changed.** Everything the adapter needs from it already exists.

## 1. Where it lives

All product-specific code is under `app/Domain/Integration/Optinexus/` and the
`integration/optinexus` routes. Core code only sees two seams, selected once by
`OPTIENTRY_IDENTITY_MODE` in `AppServiceProvider`:

| Seam | `standalone` | `optinexus` |
|---|---|---|
| `PermissionSource` | `LocalPermissionSource` (roles in PostgreSQL) | `OptinexusPermissionSource` (pulled, cached ≤ 5 min) |
| Entitlement rows | edited in the Platform Portal | projected by `EntitlementProjector` (`source = OPTINEXUS`) |
| `EffectiveAccess` | unchanged | unchanged; never reads the mode |

## 2. Sign-in (OIDC)

Authorization code + PKCE S256. `state`, `nonce` and the verifier stay in the
server cache keyed by the hash of `state` (single use, 10 min). The `id_token`
is verified (RS256 via JWKS with `kid` match, `iss`, `aud`, `exp`, `iat`,
`nonce`) with `firebase/php-jwt`, then cross-checked with `/userinfo`.
Browser flow: `GET /auth/sso/redirect` → OptiNexus → `GET /auth/sso/callback`
→ SPA `/sso/callback?ticket=…` → `POST /auth/sso/exchange` (60 s one-time
ticket → Sanctum token). The token never travels in a URL. A sign-in always
lands in the one tenant named by the verified `tenant_id` claim.

Local password login stays only for users with platform access (break-glass,
`OPTINEXUS_BREAK_GLASS_LOGIN`, default on); tenant users cannot use it.

The SPA (`/login`) asks the public `GET /auth/sso/status` which doors exist:
"Masuk dengan OptiNexus" first, the password form collapsed under "Masuk sebagai
operator platform" only when allowed; if the status call fails it falls back to
the password form (the API stays the authority). `/sso/callback` redeems the
ticket once. Sign-out calls the local logout, then the OptiNexus
`end_session_endpoint` with `post_logout_redirect_uri` = SPA `/login` (honoured
only when registered on the OIDC client; otherwise OptiNexus shows its own
"signed out" page). Only `http(s)` end-session addresses are followed.

## 3. Linking (never from an unverified claim)

- **Tenant** `tenants.optinexus_tenant_id`. Unknown tenants are provisioned on
  first sign-in only when `OPTINEXUS_PROVISION_TENANTS=true` **and** the
  service account confirms (`commercial-context`) that the tenant is ACTIVE and
  has the `optiaccounting` application enabled. Otherwise sign-in is refused.
- **User** `users.optinexus_subject` (the `sub`). Without a subject, a verified
  e-mail may claim an existing local account that has no subject and no
  platform access; a different subject is a conflict, never re-bound.
- **Membership** is created ACTIVE when the verified `apps` claim contains
  `optiaccounting`. A new member takes a data scope from the widest scope
  OptiNexus grants (`TENANT`/`APPLICATION`/`CUSTOMER`/`GLOBAL` → `TENANT`,
  `OWN` → `OWN`); a local administrator may narrow it later (data scope stays
  local, later sign-ins never overwrite it).
- Capacity (`USER_LIMIT`) is enforced by the same `CapacityService`; a full
  tenant refuses the new member with `CAPACITY_EXCEEDED`.

## 4. Permissions

One request answers the whole catalog: `POST /authorization/effective-permissions` (schema `1.0`, ecosystem audit A03) with
`{user_id, tenant_id, application_code}` returns every permission the member holds in the tenant for **this** application, each with its
own scope (`GLOBAL|APPLICATION|TENANT`, `OWN` when OptiNexus defines it). The application is the one OptiNexus bound to our service
account, so a service account that is unbound or bound elsewhere gets `403 SERVICE_ACCOUNT_NOT_BOUND` / `APPLICATION_MISMATCH` and learns
nothing of other applications or tenants. The adapter accepts only exact codes of the local tenant catalog (wildcards, unknown keys,
malformed rows and unknown scopes are dropped) and refuses an answer whose `user_id`, `tenant_id`, `application_code` or `schema_version`
differs from the question. Result: cached per tenant + user for `OPTINEXUS_PERMISSION_TTL` seconds (default and maximum 300, never above
the `ttl_seconds` OptiNexus sends) under the tenant access version, so `touchTenant()` and a revocation event invalidate it at once;
**a grant revoked in OptiNexus therefore stops working here within 300 s, sooner on a back-channel/revocation event** (OptiNexus itself
caches nothing for this answer).

Scope is per permission: `EffectiveAccess` keeps `code => scope`, a permission granted at `OWN` covers only the member's own records however
wide the local data scope is, and a new membership's local data scope starts from the **narrowest** scope OptiNexus granted (never wider than
any single grant; a local administrator may change it).

Failure handling (`NexusApi::send`, all bounded): `401` renews the cached service token once (single-flight lock, so concurrent workers do
not each ask for a token); `429` waits `Retry-After` and repeats at most `OPTINEXUS_MAX_RETRIES` (2) times, never sleeping longer than
`OPTINEXUS_MAX_RETRY_WAIT` (5 s) - a longer wait is returned to the user as `503 IDENTITY_PROVIDER_UNAVAILABLE` with a `Retry-After`
header; a connection error or `502/503/504` is repeated once (the call is a read). If OptiNexus still cannot answer, nothing is cached and
the request fails closed: no wildcard, no local fallback, no stale grant. There is **no fallback to one `/authorization/check` per
permission**: that fan-out (136 calls against a 120/min limit) is the defect A03 fixed, and an OptiNexus without the bulk endpoint is reported
by `optientry:nexus:check`. The individual endpoint stays available in OptiNexus for other callers.

Compatibility: this release of OptiEntry needs an OptiNexus release that has `POST /authorization/effective-permissions` and a service
account with `application_id` set (see OPTINEXUS_ONBOARDING.md); the service account scope stays `authorization.check`.

Role, permission-grant and member lifecycle endpoints of the tenant portal
answer `409 MANAGED_BY_OPTINEXUS` in this mode; data scopes stay editable.
Platform permissions remain local (platform operators are local break-glass
accounts; OptiNexus-authenticated platform operators are out of scope).

## 5. Entitlements

`EntitlementProjector` reads `GET /tenants/{id}/commercial-context` (scope
`commercial.read`) and rewrites the tenant's OPTINEXUS rows in one transaction
under the tenant row lock: subscription (status mapped ACTIVE/TRIAL/GRACE_PERIOD
→ ACTIVE, PAST_DUE → PAST_DUE, SUSPENDED → SUSPENDED, others ended), module and
feature entitlements (OptiNexus capability codes **equal** the local module and
feature codes), capacity (`USER_LIMIT`, `BRANCH_LIMIT`, `BUSINESS_UNIT_LIMIT`
limit keys, `unlimited` → null). Runs at sign-in when older than
`OPTINEXUS_ENTITLEMENT_TTL` (300 s) and every 5 minutes by
`optientry:nexus:sync-entitlements`. If OptiNexus is down the last
projection stays (`tenants.optinexus_synced_at` shows its age); the
subscription end date still bounds it. Manual subscription and entitlement
edits answer `409 MANAGED_BY_OPTINEXUS`. When OptiNexus returns no live
subscription the subscription row ends (module access stops) but capacity limits
are **not** touched: no subscription says nothing about limits, so the last known
ones stay (fail closed). The UI hides the manage buttons through
`useCapabilities().canEdit()` (a central set of externally managed permissions)
and shows a "dikelola di OptiNexus" notice.

## 6. Back-Channel Logout

`POST /api/v1/integration/optinexus/backchannel-logout` (form `logout_token`):
signature, `typ`, `iss`, `aud`, `iat`/`exp` with skew, `events`, `sub`, no
`nonce`, `jti` replay protection. Ends every Sanctum token of the user; for
`access-revoked` also deactivates the account (`scope=user`) or the membership
of the named tenant (`scope=tenant`) and sets `optinexus_deactivated_at`; the
next successful sign-in reverses exactly such a deactivation. Unknown users are
answered `200`; invalid tokens `400`. Observed against a real OptiNexus: removing
a person's access to the application sends `scope=user` (OptiNexus sets no
tenant), so the whole local account is deactivated until the next sign-in; tenant
removal and tenant suspension send `scope=tenant`.

## 7. Outbox and relay

`outbox_events` (transactional, written in the caller's transaction, only in
`optinexus` mode until OA6 adds channels). `optientry:nexus:relay-events`
(scheduled every minute) sends rows with `event_id` = row id to `POST /events`
(`kind = EVENT`) or `POST /audit-events` (`kind = AUDIT`); backoff 2 min
doubling to 1 h, 20 attempts then `FAILED`; 422 `EVENT_INVALID` (type not in
the catalog yet) is retried, other 4xx fail at once. Initial event types:
`optiaccounting.tenant.linked`, `optiaccounting.membership.provisioned`,
`optiaccounting.membership.deactivated`; security audit events: `sso.login`,
`sso.login_refused`, `sso.access_revoked`. Financial events arrive with OA1+.

## 8. Operations

- `optientry:nexus:manifest` prints the registration data an OptiNexus
  administrator needs (application, capabilities, permissions, event catalog,
  service-account scopes, OIDC client settings). See
  `docs/integration/OPTINEXUS_ONBOARDING.md`.
- `optientry:nexus:check` verifies configuration, discovery, JWKS and the
  service-account token and scopes against the live OptiNexus.
- Failure behaviour: existing sessions work until the permission cache expires;
  new sign-ins fail closed; accounting data is never touched by an outage.

## 9. Not in scope (owner decisions if wanted)

Batch permission endpoint in OptiNexus; OptiNexus-authenticated platform
operators; mixed local/OptiNexus tenants in one installation (SAAS §1); a
stale-if-error permission grace window.
