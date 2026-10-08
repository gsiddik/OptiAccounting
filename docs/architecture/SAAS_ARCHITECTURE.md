# SaaS Architecture

Status: MASTER baseline. OA0 implements it (`docs/specs/OA0.md`).

## 1. Two identity modes, one codebase

Project instruction: in SaaS mode, tenant, user, role, permission and events are
managed in **OptiNexus**; in standalone mode OptiAccounting uses its own user,
role and permission management. The MASTER/OA0 briefs (written before
OptiNexus existed) describe the standalone model. Both are kept:

| Concern | `standalone` | `optinexus` |
|---|---|---|
| Config | `OPTIACCOUNTING_IDENTITY_MODE=standalone` (default) | `OPTIACCOUNTING_IDENTITY_MODE=optinexus` + `OPTINEXUS_*` |
| Login | Local password (Sanctum) | OIDC (authorization code + PKCE) against OptiNexus; local password only as break-glass for platform admins |
| Tenants | Created in the local Platform Portal | Authority: OptiNexus. Local `tenants` row is a projection linked by `optinexus_tenant_id`, created by an admin link or provisioning, never from an unverified claim |
| Users / memberships | Local | Authority: OptiNexus (`sub`, `tenant_id` claims). Local rows are projections linked by `optinexus_subject`; deactivation via Back-Channel Logout `access-revoked` |
| Roles / permissions | Local Role Editor | OptiAccounting registers its permission catalog as OptiNexus application capabilities; role composition and assignment happen in OptiNexus; effective permissions are pulled (`/auth/context` or `/authorization/check`) and cached per user+tenant with short TTL and event-driven invalidation. Local Role Editor is read-only |
| Subscription / module entitlement | Local subscription + entitlements | Authority: OptiNexus subscriptions/entitlements (`/entitlements/check`), projected into local entitlement rows with `source = OPTINEXUS` |
| Data scope (branch/BU/cost center) | Local | Local (accounting-specific organizational scope), unless OptiNexus later exposes an equivalent scope contract |
| Business events | Local outbox (consumers: integrations) | Local outbox relayed to OptiNexus `POST /api/v1/events` (`optiaccounting.*` keys), like OptiFleet D9 |
| Audit | Local audit trail | Local audit trail; security-relevant events also sent to OptiNexus `POST /api/v1/audit-events` |

Design rules:

- Authorization code never branches on the mode. It asks one
  `EffectiveAccessResolver`; the mode only changes where the **inputs**
  (membership, permissions, entitlements) come from, behind an
  `IdentityDirectory` / `EntitlementSource` pair with a `Local` and an
  `Optinexus` implementation. Accounting data is identical in both modes.
  Implemented by OA0-N as `PermissionSource` (`Local`/`Optinexus`) plus a projection
  of OptiNexus entitlements into the local rows; see `OPTINEXUS_ADAPTER.md`.
- In `optinexus` mode OptiNexus being down must not corrupt accounting data:
  existing sessions keep working until token/permission cache expiry; new
  logins fail closed; nothing is posted on behalf of OptiNexus.
- Local projections are keyed by external ids and never trust a claim that was
  not verified (signature, `iss`, `aud`, `exp`, `nonce`).
- Accounting-specific permissions (`accounting.*`) are defined in this repo and
  published to OptiNexus; OptiNexus does not invent accounting semantics.
- Switching a running installation between modes is a migration with a
  documented plan, not a config flip.

Decision recorded: identity mode is per **installation**, not per tenant. A
mixed installation (some tenants local, some via OptiNexus) is not supported
in OA0; revisit only on owner request.

## 2. Logical scopes

- **Platform scope** (`/api/v1/platform/*`, Platform Portal): SaaS operator —
  tenants, modules, bundles, subscriptions, entitlements, platform users.
  In `optinexus` mode the tenant/subscription screens become read-only views
  of OptiNexus state plus linking tools.
- **Tenant scope** (`/api/v1/app/*`, Tenant Portal): tenant-owned data.
- Platform permissions never grant tenant operational context implicitly;
  support access to a tenant is an explicit, audited, time-boxed action.

## 3. Multi-tenancy

- Shared database, shared schema; `tenant_id` (UUID) on every tenant-owned
  table, included in unique constraints and composite foreign keys
  (`(tenant_id, id)`) so a row cannot reference another tenant's row.
- Identity: `users` (global), `tenants`, `tenant_users` (membership with its own
  status). A user may belong to several tenants; switching tenant issues a new
  token whose tenant is fixed server-side (pattern proven in OptiFleet:
  token ability `tenant:<uuid>`).
- A `TenantContext` is resolved once per request from the token and used by a
  global scope on tenant models plus explicit checks in services.
- Cross-tenant leakage is a P0 defect; every OA phase ships cross-tenant tests.

## 4. Tenant and membership lifecycle

- Tenant: `DRAFT → ACTIVE → SUSPENDED → INACTIVE → TERMINATED` (reactivation
  from SUSPENDED). Not overloaded with accounting setup status (that is the
  Accounting Profile readiness in OA1).
- Membership: `INVITED → ACTIVE → SUSPENDED → INACTIVE`.
- Expired/suspended access never deletes financial data; it removes or limits
  access (READ_ONLY) only.

## 5. Commercial model

```
Module catalog (+ dependencies, features)
  → Bundle (data-driven composition, versioned when published)
  → Subscription (tenant, status, start/end, source)
  → Tenant module entitlement (ACTIVE | READ_ONLY | SUSPENDED | DISABLED,
     source BUNDLE | ADD_ON | CUSTOM_CONTRACT | MANUAL_OVERRIDE | OPTINEXUS,
     effective_from/until)
  → Tenant feature entitlement (feature usable only if its module is usable)
  → Capacity entitlement (USER_LIMIT, BRANCH_LIMIT, … ; null = unlimited)
```

- Subscription statuses: `PENDING, ACTIVE, PAST_DUE, SUSPENDED, EXPIRED, CANCELLED`.
- Pricing, contracts and SaaS billing are **not** OA0 scope; in the OptiNexus
  ecosystem they belong to OptiNexus (its D2 decision). Standalone billing can
  be added later as its own module without touching entitlements.
- Module dependencies are data (`module_dependencies`): activation checks
  transitive requirements, deactivation checks active dependents, cycles are
  rejected on write.
- Entitlement evaluation uses the business date in the tenant timezone and is
  computed at request time (a scheduler may pre-mark expiry, but never
  disagrees with request-time evaluation).

## 6. RBAC and data scope

- `permissions` (code `resource.action`, module code, scope type),
  `roles` (tenant-owned; platform roles separate), `role_permissions`,
  `tenant_user_roles` (many roles per membership).
- Data scope (`TENANT, BRANCH, BUSINESS_UNIT, COST_CENTER, OWN`, extensible)
  is attached to role assignments; permission answers *what*, scope answers
  *which records*. One shared mechanism for every module.
- Seeded roles are bootstrap data only; code never checks role names.

## 7. Caching

Access decisions may be cached in Redis with tenant-aware keys
(`t:{tenant}:u:{user}:access:v{version}`) and a version counter bumped on any
role, permission, membership, entitlement, subscription or tenant status change.
A stale cache must never keep a revoked privilege beyond the TTL (≤ 5 min).
