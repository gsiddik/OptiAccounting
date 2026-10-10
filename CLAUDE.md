# OptiEntry — Permanent Development Rules

Durable rules for every Claude session on this repository. Phase history and
release status live in `docs/status/*.md`, not here.

## Source of truth

- The repository is the authoritative source of truth. Chat history is not, and
  must never be used to reconstruct implementation state.
- Architecture: `docs/architecture/` (start with `SYSTEM_ARCHITECTURE.md`).
  Owner phase briefs: `docs/specs/` (MASTER, OA0–OA5). Phase status:
  `docs/status/{PHASE}_STATUS.md`.
- Starting a phase: read this file, the architecture documents relevant to the
  phase, the phase brief in `docs/specs/`, and the previous status file. Then
  inspect only relevant code and implement only the current phase.
- Do not start the next phase without explicit owner instruction, even when a
  status file says the current one is complete. Completed phases are not
  reopened or redesigned unless instructed.
- If a brief contradicts the architecture or the project instructions, record
  the contradiction in the relevant architecture document, pick the safest
  backward-compatible option, and stop only when an owner decision is required.

## Branching and delivery

- `main` is the baseline. Start every piece of work from the latest `main` on a
  new branch and return it by pull request. Never merge without the owner's
  explicit request. Do not keep working on a branch whose PR is merged.
- Commit and push at each checkpoint (one logical batch per commit).

## Product boundary

- OptiEntry owns financial truth (journal, GL, AP, AR, cash/bank, periods,
  financial statements). External systems (OptiFleet, ERP, POS, …) own their
  operational truth. Never create dual financial truth.
- OptiEntry must run, start and be sold without OptiFleet or OptiNexus.
- Two deployment identity modes from one codebase (`OPTIENTRY_IDENTITY_MODE`):
  `standalone` (local users/tenants/roles/permissions) and `optinexus`
  (OptiNexus is the authority for tenant, user, membership, role, permission,
  application subscription and events). See `SAAS_ARCHITECTURE.md`.
- Product name: OptiEntry (formerly OptiAccounting). Identifiers already registered in OptiNexus or persisted
  in databases keep the former name (application code, `optiaccounting.*` permission/event keys, database
  names); `OPTIACCOUNTING_*` env vars and `optiaccounting:*` artisan names still work as fallbacks. Do not
  rename them without an owner-approved migration plan (`docs/status/RENAME_OPTIENTRY.md`).

## Stack and conventions

- Backend: Laravel 13 (PHP 8.3), modular monolith under `backend/app/Domain/<Module>`.
  Thin controllers in `app/Http/Controllers/Api/{Platform,App,Integration}`;
  business logic in domain services; Form Requests for validation.
- APIs are versioned: `/api/v1/platform/*` (platform admin), `/api/v1/app/*`
  (tenant application), `/api/v1/integration/*` (machine-to-machine only).
- Frontend: React 19 + TypeScript + Vite in `frontend/`. Capability checks go
  through one central hook/service; never `if (role === '...')`. No accounting
  math in React: the backend computes every total, balance and tax.
- Database: PostgreSQL is authoritative for every accounting transaction. Redis
  for cache/queue. MongoDB only for later analytical projections (OA7), never
  authoritative, never written inside an operational transaction.
- Do not add microservices, Kafka, Kubernetes, extra abstraction layers
  (repositories, DTO layers, event buses) unless a concrete need is documented.

## Tenant isolation (P0)

- Shared database, shared schema; every tenant-owned table has `tenant_id`.
- Tenant context is resolved server-side (token/session/connection identity).
  Never trust a `tenant_id` from a request body, query, header or webhook payload.
- Use the shared tenant-scoping mechanism; do not hand-roll tenant filters per
  controller. Cross-tenant relationships must fail at the service and DB level.
- Cache keys that hold tenant data or access decisions include the tenant id.

## Authorization

- Effective access = tenant status AND subscription AND module entitlement AND
  feature entitlement AND user status AND membership status AND permission AND
  data scope AND resource tenant ownership AND accounting invariants. One
  central resolver; never re-implement the decision in controllers.
- Dynamic RBAC with atomic permissions named `resource.action`
  (e.g. `accounting.journal.post`). Never authorize by role name.
- Module/feature entitlement is enforced server-side. Frontend hiding is never
  authorization. READ_ONLY entitlement denies every mutation.
- Segregation of duties is policy-driven (e.g. creator ≠ approver), not role-named.

## Accounting invariants (never configurable away)

- Double-entry: every posted journal balances (Σ debit = Σ credit, per currency
  and in functional currency); a line is debit XOR credit, never negative.
- Money is `NUMERIC` in PostgreSQL and `brick/math` `BigDecimal` in PHP. Never
  float, never PHP arithmetic operators on money. Explicit rounding mode.
- Posted journals and posted source documents are immutable (enforced in the
  service layer AND by database triggers). Corrections use reversal and/or
  adjustment journals with reason, reference, actor and audit trail.
- Posting into a CLOSED period is forbidden; period checks happen inside the
  posting transaction under a row lock.
- Used master data (accounts, dimensions, tax codes) is deactivated, never
  hard-deleted. Posted history is never rewritten by configuration changes.
- GL and reports derive only from POSTED journal lines; there is no editable GL.
- All automatic postings go through the central Posting Engine (posting rule →
  account role → account mapping). No debit/credit rules in controllers or
  integration adapters; external systems send business facts, not accounts.
- Critical transitions (post, reverse, approve, pay, number, close) use DB
  transactions plus row locks / unique constraints / idempotency keys.
- Distinguish `document_date`, `transaction_date`, `posting_date`, `due_date`;
  never use `created_at` as an accounting date.

## Integration boundary

- No direct access to another product's database and no foreign keys into it.
  Integrations use versioned APIs/events (envelope with `event_id`,
  `event_type`, `schema_version`, `source_system`), idempotent on
  `(source_system, event_id)`. Outbound events use a transactional outbox.
- Generic names in core tables (`integration_connections`, `external_events`,
  `external_dimensions`); product-specific names only inside an adapter.
- Never auto back-post history when a connection activates (default:
  opening balance only + events from the cutover date).

## Migrations and seeders

- Additive migrations only once a phase is merged; never rewrite merged migrations.
- Use PostgreSQL constraints (FK, unique, check, triggers) for invariants.
- `DatabaseSeeder` is production-safe (catalogs, permissions, templates only;
  no fake tenants, no default production passwords). Demo data lives in a
  separate `DemoSeeder`; demo logins are documented in `docs/DEMO.md`.
- Never change real (non-demo) data without stopping first and asking the owner,
  with the financial impact explained.

## Testing and validation

- Add targeted tests per batch: unit, feature, tenant isolation, permission,
  financial invariant and concurrency tests. Full regression at release gates.
- Report PASS only for validation actually executed in this session; otherwise
  NOT RUN with the reason. Never fabricate results.
- UI changes: verify with screenshots at desktop (1440), tablet (820) and
  mobile (390) widths before calling them done.

## Output efficiency

- Do not print whole files, large diffs, full schemas or long logs in chat.
- Keep progress messages short; durable detail goes into the repository docs.
- Keep each status file ≤ 100 lines and this file ≤ 200 lines.
