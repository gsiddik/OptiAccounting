# Security Invariants

Release-critical (MASTER §73). Every phase tests the ones it touches.

## 1. Tenant isolation

- Tenant context comes from the authenticated identity (token ability,
  verified OIDC claim mapped to a linked tenant, or integration connection),
  never from client-supplied ids, headers or payloads.
- Every tenant-owned query is scoped; composite `(tenant_id, id)` foreign keys
  make cross-tenant references impossible in the database.
- Responses for another tenant's resource are `404`, not `403` (no existence leak).
- Cache keys, queue jobs, exports, audit records and analytical documents all
  carry the tenant id; jobs re-resolve tenant context, they do not inherit it.

## 2. Authorization

- Atomic permissions + data scope + entitlement through one resolver; no
  role-name checks; frontend checks are cosmetic.
- Mass assignment of privileged fields (`tenant_id`, `status`, `posted_at`,
  `number`, `created_by`, amounts that the server computes) is blocked: the
  server sets them.
- Segregation of duties is evaluated server-side from policies.
- Platform admins do not get tenant operational access implicitly.

## 3. Financial integrity

- Posted journals/documents: no UPDATE/DELETE (service + DB trigger).
- Period lock checked inside the posting transaction under row lock.
- Duplicate posting prevented by idempotency keys
  (`UNIQUE(tenant_id, source_type, source_id, posting_purpose)`,
  `UNIQUE(source_system, event_id)`, one reversal per journal).
- Numbering is unique, transactional, and immutable after issue.
- Server recomputes every total; client totals are ignored.

## 4. Authentication and credentials

- Humans: Sanctum tokens (rate-limited login, inactive user/membership and
  tenant enforcement, token revocation on logout/deactivation); OIDC in
  `optinexus` mode with signature/`iss`/`aud`/`exp`/`nonce` checks and
  Back-Channel Logout (`logout+jwt`, `jti` single use).
- Machines: client credentials (OptiNexus service accounts or local integration
  credentials) — scoped, revocable, rotatable, hashed at rest, never logged.
  Never a human username/password for background integrations.
- Webhooks: HMAC signature, timestamp window, replay protection (nonce/event id),
  idempotency, connection resolution, schema validation.
- Secrets live in environment/secret store; never committed, never in audit
  payloads or logs. `.env` is git-ignored; `.env.example` holds no real values.

## 5. Configuration safety

- Tenant configuration is declarative and validated (posting rules, mappings,
  report layouts). No `eval`, tenant scripts or raw SQL configuration.
- Financially meaningful configuration is versioned; changes never rewrite
  posted history.

## 6. Audit

Append-only audit records (tenant, actor, action, resource, before/after
summary, request/correlation id, IP/user agent where relevant) for:
authentication/security events, tenant/module/entitlement changes, accounting
configuration, COA, posting rules, mappings, journal lifecycle, reversals,
period close/reopen, AP/AR/payment actions, integration processing, manual
retries, reconciliation and critical exports. The audit table rejects
UPDATE/DELETE at the database level.

## 7. AI / automation

Any financial intelligence is advisory. Automation never autonomously posts
journals, approves invoices or payments, reopens periods, modifies the GL or
changes accounting policy. Anomaly flags are not fraud findings.
