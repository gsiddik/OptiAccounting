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
- Seats are not counted here. OptiNexus alone counts and enforces them per tenant and application (`GOVERNANCE_CONTRACTS.md` §2d); it lets a
  person sign in only when it has given them the access, so the sign-in does no local `USER_LIMIT` reserve (it used to answer `capacity_exceeded`).
  The legacy `standalone` mode keeps its local `CapacityService`.

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

The local role, permission-grant and member lifecycle endpoints (`/app/users`, `/app/roles`, status, role assignment) are the **legacy
local authority** and answer `409 MANAGED_BY_OPTINEXUS` in this mode; an Admin Tenant administers users and custom roles through the
delegation routes of section 10 instead. Data scopes stay editable.
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

## 9. Mode status (owner decisions R01-R08, 2026-10-12)

`optinexus` is the target: OptiNexus is the authority for tenant, user, membership, role, permission, subscription and entitlement, for
single-product use as well. `standalone` (local users, roles, permissions, subscriptions) is **legacy**: it keeps working and keeps its
tests so existing installations are not broken, but it does not satisfy R01-R08. Everything that is local authority is marked in code
and docs: `LocalPermissionSource`, `MemberController`/`RoleController` writes, `Platform\*` subscription and entitlement writes,
`RequireLocalIdentity`. Moving a legacy installation to the target is a planned, mapped, operator-run migration, never an automatic one:
`docs/architecture/LEGACY_IDENTITY_MIGRATION.md`.

## 10. Tenant delegation: users and custom roles from the OptiEntry UI (R08 / audit A25)

An Admin Tenant (the flag exists only in OptiNexus and is set only by an operator) manages the people and roles of their organisation
from Settings > Users and Roles, without entering the OptiNexus portal. OptiEntry has **no second authority**: every list is an answer of
OptiNexus, every change goes through its delegation API (`GET/POST /delegation/tenants/{tenant}/users`, `PUT .../users/{user}/roles`,
`DELETE .../users/{user}`, `POST/PUT/DELETE .../roles`, `GET .../catalog`; contract in OptiNexus `docs/ecosystem/GOVERNANCE_CONTRACTS.md`
section 2), and permissions still come from `effective-permissions` (section 4).

| Piece | Where |
|---|---|
| Routes (tenant API, `access:access.user.view|manage`, `access.role.view|manage`) | `/api/v1/app/delegation/{status,users,assignable-roles,roles}` |
| Thin controllers, Form Requests | `Api/App/Delegation/Delegated{User,Role}Controller`, `Requests/Delegation/*` |
| Domain service (link, seats, projection, audit, refusal audit) | `Integration/Optinexus/Delegation/TenantDelegation` |
| HTTP client, error translation | `NexusDelegationClient`, `DelegationErrors` (every OptiNexus code -> one stable code here) |
| Token | `NexusApi::DELEGATION_SCOPES` = `authorization.check tenant.delegate`, its **own** cached token: a service account that may not (yet) delegate loses nothing it already does |

Rules the code keeps:

- **Identity of the call.** The tenant is the session tenant's `optinexus_tenant_id`, the acting user the signed-in user's
  `optinexus_subject`; neither is read from the request (a `tenant_id` or `acting_user_id` in a body or query is ignored). A person without
  a subject (break-glass operator) gets `ACTOR_NOT_LINKED`; OptiNexus answers `ACTOR_NOT_TENANT_ADMIN` for anyone who is not an active
  Admin Tenant of that tenant with access to this application (the page shows a "forbidden" state, not a partial list).
- **Delegable set.** OptiNexus enforces it on every role create/edit (`PERMISSION_NOT_DELEGABLE`, `details.permissions`) and on every
  assignment (`ROLE_NOT_ASSIGNABLE`, `details.role_ids`); OptiEntry never pre-judges it (an operator may widen or narrow it in OptiNexus)
  and surfaces the refusal in its own permission codes. Its default comes from the manifest (`delegable`, OPTINEXUS_ONBOARDING section 5).
  The catalog endpoint returns every local permission with `state`: `AVAILABLE`, `NOT_ENTITLED` (we mark it delegable, OptiNexus does not
  offer it to this tenant) or `NOT_DELEGABLE`; the UI shows the last two as unavailable.
- **Local projection.** After a successful create/link the person gets a local user (`optinexus_subject`, no password) and an `INVITED`
  membership with a data scope (default `OWN`, the narrowest; the Admin may choose another within their own reach), which the first
  verified sign-in activates (`AccountLinker`). An e-mail that already belongs to a local account is never claimed here (audit
  `delegation.projection_deferred`); only a verified sign-in links accounts. Roles are not copied: the permissions of the person are
  whatever OptiNexus answers. Removing a person ends the local membership (`INACTIVE` + `optinexus_deactivated_at`, tokens revoked, seat
  freed) so that adding them again undoes exactly that.
- **Seats (OptiNexus contract §2d).** OptiEntry keeps no seat counter and does no pre-check or compensation. Every addition (add user, invite,
  resend of an expired invitation) goes to OptiNexus, which counts per tenant and application under a lock and refuses with `SEAT_LIMIT_REACHED`,
  `SEAT_OVER_LIMIT`, `SEAT_ENTITLEMENT_MISSING|INVALID` (409) or `SEAT_ENTITLEMENT_UNAVAILABLE`, `SEAT_CONTENTION` (503, `retry_after`). The refusal is passed on
  with OptiNexus' own `limit`, `used`, `reserved`, `available`, `over_limit` and `recovery` (whole numbers, booleans and a code only); nothing was
  granted, so nothing is withdrawn. The users and invitations lists carry `meta.seats` (state, limit, used, reserved by open invitations, available, over-limit,
  `can_add`), shown on both tabs; a limit below usage is "over limit" (never a negative number), and a missing, invalid or unreadable limit blocks additions
  and is never shown as unlimited. An older OptiNexus without figures shows nothing. `GET /app/account/usage` omits the `USER_LIMIT` row in this mode.
  A read-only or inactive subscription refuses every change (`SUBSCRIPTION_READ_ONLY` / `SUBSCRIPTION_INACTIVE`).
- **Retry safety.** A read is repeated once on a transient failure; a write is never repeated by the transport (only 401 token renewal and
  429, where OptiNexus certainly did not process it). A write whose answer is lost is reported with `details.outcome_unknown = true`, so
  the UI tells the person to refresh before repeating. Creating a person is idempotent in OptiNexus (same e-mail, one identity); creating a
  role refuses an equal name (`ROLE_NAME_TAKEN`) and a double submit (`DELEGATION_IN_PROGRESS`, cache lock).
- **Error vocabulary** (all carry `details.reference` = the `X-Request-Id`, which is also sent to OptiNexus as `X-Correlation-Id`, so one
  id finds the call in both audit logs): OptiNexus codes that a person can act on pass through (`ACTOR_NOT_TENANT_ADMIN`,
  `TENANT_NOT_ACTIVE`, `APPLICATION_NOT_ACTIVE`, `APPLICATION_NOT_SUBSCRIBED`, `PERMISSION_NOT_DELEGABLE`, `ROLE_NOT_ASSIGNABLE`,
  `ROLE_NOT_EDITABLE`, `ROLE_IN_USE`, `USER_NOT_IN_TENANT`, `TENANT_ADMIN_PROTECTED`, `IDENTITY_NOT_LINKABLE`, `MEMBERSHIP_NOT_ACTIVE`,
  `SELF_REVOCATION_DENIED`); installation problems (`SERVICE_ACCOUNT_NOT_BOUND`, `APPLICATION_NOT_DELEGABLE`, `TENANT_MISMATCH`, missing
  scope, inactive account) become `DELEGATION_NOT_CONFIGURED` (503); an unknown tenant `DELEGATION_TENANT_UNKNOWN` (409); 429 and 5xx
  `IDENTITY_PROVIDER_UNAVAILABLE` (503, `retry_after`); validation `422` with field errors; anything else `DELEGATION_UNEXPECTED_RESPONSE`
  (502). Local codes: `DELEGATION_NOT_AVAILABLE` (409, standalone), `TENANT_NOT_LINKED`, `ACTOR_NOT_LINKED`, `UNKNOWN_PERMISSION`,
  `ROLE_NAME_TAKEN`, `DELEGATION_IN_PROGRESS`. The `SEAT_*` codes of §2d are passed through (see Seats below); `CAPACITY_EXCEEDED` is the legacy local code.
- **Audit.** Entry audit actions: `delegation.user_added|user_roles_set|user_removed|user_withdrawn|user_withdraw_failed|role_created|
  role_updated|role_deleted|projection_deferred` and `delegation.refused` (every refusal OptiNexus decided, with its code and the
  permissions or roles it named). OptiNexus audits the same calls as `delegation.*` with the Admin Tenant as actor.
- **Not reachable from OptiEntry, by design:** Admin Tenant accounts (`TENANT_ADMIN_PROTECTED`), other tenants and applications, ecosystem
  roles, plans, entitlements, subscriptions, tenant-wide membership. `optientry:nexus:check` verifies the scope and the endpoint.

### 10a. Invitations: the way to add a NEW person (OptiNexus contract section 2b)

`POST /delegation/.../users` (with `initial_password`) is deprecated by OptiNexus and kept here only for compatibility; the console no longer
offers it. Settings > Users > **Invite user** sends `POST /invitations` (`email`, `name`, `role_ids[]`, `locale` = `en`|`id`, default the UI
language); OptiNexus e-mails the link and the invitee sets their own password on a OptiNexus page, then signs in here through the normal OIDC
flow (first sign-in creates the local membership, section 3). **No password and no token passes through OptiEntry**: the request has none,
the answer is whitelisted field by field (`TenantDelegation::invitationView`), audit and logs never see one.

- Routes: `GET|POST /app/delegation/invitations`, `POST .../invitations/{id}/resend|revoke` (`access.user.view` to list, `access.user.manage`
  to change; tenant and acting user from the session as in §10; the id is a UUID that is only ever sent under the session's tenant, so another
  tenant's id answers `INVITATION_NOT_FOUND`). Read-only subscription refuses every change; a new invitation reserves a seat in OptiNexus (§10 Seats).
- No copy: nothing is stored locally (no user, membership or seat until acceptance + first sign-in); the list is OptiNexus' answer (status
  PENDING/ACCEPTED/REVOKED/EXPIRED, delivery QUEUED/SENT/FAILED + `error_code`, expiry, inviter, `can_resend`, `can_revoke`).
- Errors pass through: `INVITATION_PENDING` (`details.invitation_id`), `ALREADY_MEMBER`, `ROLE_NOT_ASSIGNABLE`, `INVITATION_NOT_FOUND`,
  `INVITATION_NOT_PENDING` (`details.status`), `INVITATION_RESEND_LIMIT`, and the two throttles `INVITATION_RESEND_COOLDOWN` / `INVITATION_RATE_LIMITED`
  (429 with `details.retry_after` and the HTTP `Retry-After` header). A change is never repeated after a lost connection (`outcome_unknown`).
- Audit: `delegation.invitation_created|resent|revoked` (e-mail, roles, reason; never a token) and `delegation.refused`.

## 11. Product switcher and session revalidation (audit A29)

`GET /api/v1/app/product-context` (every signed-in user, ungated; the question is about the session's own user and tenant, never a request
value) asks OptiNexus `POST /authorization/product-context {user_id, tenant_id}` (scope `authorization.check`, schema 1.x) and returns the
products the tenant subscribes to AND the person was assigned, each with a `switch_url` the API builds (`launch_url` + `tenant_hint=<tenant
id>`, existing query kept; only absolute http(s) addresses without credentials; the governance portal `cgo` is dropped even if returned).

- **Cache.** One entry per tenant + user, for at most `ttl_seconds` and never more than 300 s; one worker asks while others wait (lock). A
  cached answer is never used after it expires: when OptiNexus cannot be asked then, the call is `IDENTITY_PROVIDER_UNAVAILABLE` (503) and the
  switcher shows no list (fail closed). A malformed answer, one about another user/tenant/application or another schema major is not an
  answer (same 503, nothing cached). A refusal is never cached.
- **Revalidation.** `allowed=false` ends the session at once: the API deletes the person's tenant tokens, audits
  `auth.session_ended_by_optinexus` with the `deny_reason`, and answers `session_ended: true`; the console drops its token and the sign-in
  page explains the reason (`session.ended.*`, both languages). An outage never ends a session (the permission cache already fails closed
  for data); an OptiNexus without the endpoint, or a service account without the scope, answers `available:false` (switcher hidden, nothing
  ended). The console asks on load, when the tab becomes visible, every `ttl_seconds`, whenever the menu opens and once more right before it
  navigates to another product (no navigation on a failed or negative answer).
- **Sign-in start.** `GET /auth/sso/redirect?tenant_hint=` forwards the hint to `/oidc/authorize` only when it is a tenant id (uuid) or a
  tenant code (`[A-Za-z0-9][A-Za-z0-9._-]{0,63}`); anything else is dropped and sign-in still starts. The console's sign-in page starts SSO by
  itself when it is opened with a valid hint (at most once a minute, never after an `sso_error`), so arriving from another product's
  switcher needs no second click. A direct visit to a product the person does not hold is refused by OptiNexus at `/oidc/authorize` and by
  `AccountLinker` (`tenant_not_entitled`, `membership_inactive`), exactly as before.
- **Contract test.** `ProductContextContractTest` runs the HTTP client against `tests/Support/fake-nexus/router.php`, a stand-alone server
  that answers only the documented JSON (real socket, no `Http::fake`); `optientry:nexus:check` has a product-context step.

## 12. Not in scope (owner decisions if wanted)

Batch permission endpoint in OptiNexus; OptiNexus-authenticated platform
operators; mixed local/OptiNexus tenants in one installation (SAAS §1); a
stale-if-error permission grace window.
