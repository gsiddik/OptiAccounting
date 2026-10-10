# System Architecture

Status: MASTER baseline. Owner brief: `docs/specs/MASTER.md`.

## 1. What OptiEntry is

A double-entry accounting platform delivered from one codebase as:

1. a multi-tenant SaaS inside the OptiNexus ecosystem (identity, tenants,
   subscriptions and events governed by OptiNexus), and
2. a standalone product (own users, tenants, roles, subscriptions), sold and
   operated without OptiNexus, OptiFleet or any other system.

It is integration-ready: OptiFleet is the first planned source of financial
facts; ERP, POS, TMS, WMS, HRIS, e-commerce and banking can follow through the
same generic adapter model (`INTEGRATION_ARCHITECTURE.md`).

## 2. Ownership boundary

| OptiEntry owns (financial truth) | External systems own (operational truth) |
|---|---|
| Accounting profile, fiscal years, periods | Vehicles, work orders, maintenance (OptiFleet) |
| Chart of accounts, dimensions, cost centers | Inventory quantity and operational valuation source |
| Journals, general ledger, opening balances | Procurement documents (PR/PO/GR) |
| AP, AR, expense, cash & bank | Tire/component lifecycle, warranty |
| Budget, fixed assets, tax configuration, currency | Identity, tenants, subscriptions (OptiNexus, in `optinexus` mode) |
| Financial reports, closing, reconciliation, accounting audit | |

An integration never makes OptiEntry the operational source of truth for
an external domain, and never lets an external system decide debit/credit.

## 3. Stack

| Layer | Choice | Reason |
|---|---|---|
| Backend | Laravel 13, PHP 8.3 | Same family as OptiFleet-v2 / OptiNexus; shared conventions and skills |
| Frontend | React 19 + TypeScript + Vite, React Router | Same as OptiFleet-v2 / OptiNexus |
| Transactional DB | PostgreSQL 16 | Constraints, triggers, `NUMERIC`, row locks, partial indexes |
| Cache / queue | Redis 7 | Laravel cache, queue, rate limiting |
| Money | `brick/math` `BigDecimal` | No float arithmetic (also used by OptiFleet) |
| Auth (humans) | Laravel Sanctum tokens; OIDC (OptiNexus) in `optinexus` mode | Tenant encoded server-side in the token |
| Auth (machines) | OAuth2 client credentials (OptiNexus service accounts) or local revocable integration credentials | `SECURITY_INVARIANTS.md` |
| Analytics | MongoDB, only from OA7 if justified | Never authoritative |

Style: **modular monolith**. No microservices, Kafka, Kubernetes or event
streaming platforms until a requirement justifies them.

## 4. Repository layout

```
backend/                 Laravel API
  app/Domain/<Module>/   Models, Services, Policies, Events per bounded context
  app/Http/Controllers/Api/{Platform,App,Integration}/   thin controllers
  app/Support/           cross-cutting helpers (Money, TenantContext, …)
  database/migrations    additive migrations
  database/seeders       DatabaseSeeder (production-safe) + DemoSeeder
  tests/{Unit,Feature}
frontend/                React SPA (platform portal + tenant portal)
docs/architecture/       this folder — durable decisions
docs/specs/              owner phase briefs (MASTER, OA0–OA5)
docs/status/             phase checkpoints
docker-compose.yml       local runtime (postgres, redis, backend, frontend)
```

## 5. Bounded contexts (backend `app/Domain`)

| Context | Phase | Notes |
|---|---|---|
| `Identity` | OA0 | users, tenants, tenant_users; local or OptiNexus-backed (`SAAS_ARCHITECTURE.md`) |
| `AccessControl` | OA0 | permissions, roles, role assignment, data scope, effective access resolver |
| `Organization` | OA0 | branches, business units |
| `ProductCatalog` / `Entitlement` / `Subscription` | OA0 | modules, dependencies, features, bundles, subscriptions, capacity |
| `Audit` | OA0 | append-only audit trail |
| `Numbering` | OA1 | one concurrency-safe document numbering service for every module |
| `Ledger` | OA1 | profile, fiscal year, period, COA, dimensions, journal, posting engine, GL, TB |
| `Payables` / `Expense` / `CashBank` | OA2 | AP subledger, payments, expense, cash & bank (`PAYABLES_CASH_BANK.md`) |
| `Receivables` | OA3 | AR subledger, customer invoices, receipts, credit notes (`RECEIVABLES.md`) |
| `Budget` / `FixedAsset` / `Tax` / `Currency` | OA4 | |
| `Reporting` / `Closing` / `Reconciliation` | OA5 | |
| `Integration` | OA6 | connections, canonical events, adapters (OptiFleet first) |
| `Analytics` | OA7 | projections only |

Rule: a context calls another through its services, never by writing the
other's tables. Every subledger posts through `Ledger`'s Posting Engine.

## 6. Central posting flow

```
Business document / manual entry / canonical external event
  → Accounting Event Gate (validate, idempotency, period open?, entitlement)
  → Posting Rule (versioned: which account ROLES are debited/credited)
  → Account Mapping (role → tenant account, by dimension/effective date)
  → Posting Engine (build lines, balance check, numbering, lock, insert)
  → Journal (POSTED, immutable) → General Ledger (read model of posted lines)
```

Details and invariants: `ACCOUNTING_PRINCIPLES.md`.

## 7. Request path

```
SPA ─▶ /api/v1/app/*  ─▶ auth (Sanctum) ─▶ tenant context (from token)
     ─▶ effective access resolver (tenant, subscription, module, feature,
        user, membership, permission, data scope) ─▶ controller ─▶ service
        ─▶ DB transaction (locks, constraints) ─▶ audit ─▶ outbox (if needed)
```

## 8. Architecture validation (MASTER §80)

| Question | Answer / where |
|---|---|
| Runs without OptiFleet? | Yes. No runtime dependency; integration is a module (`ACCOUNTING_INTEGRATION`) that can be off. §2, `INTEGRATION_ARCHITECTURE.md` |
| Sold independently? | Yes. `standalone` identity mode with its own platform portal and subscriptions. `SAAS_ARCHITECTURE.md` |
| OptiFleet integrates without DB sharing? | Yes. Versioned events/API via adapter. `INTEGRATION_ARCHITECTURE.md` §2–4 |
| Another ERP/POS/TMS without redesign? | Yes. Generic connection, envelope, external dimensions; adapters only translate. |
| Financial truth only in OptiEntry? | Yes. §2; external events carry facts, never accounts. |
| Operational truth outside? | Yes. §2; external references are `external_dimensions`, no copies of external domains. |
| Double-entry protected? | Service + DB trigger + tests. `ACCOUNTING_PRINCIPLES.md` §2 |
| Posted journals immutable? | Service + DB trigger; reversal only. `ACCOUNTING_PRINCIPLES.md` §4 |
| Tenant isolation explicit? | `SECURITY_INVARIANTS.md` §1, `SAAS_ARCHITECTURE.md` §3 |
| Module and feature entitlement? | `SAAS_ARCHITECTURE.md` §5, `MODULE_CATALOG.md` |
| Integration versioned and idempotent? | `INTEGRATION_ARCHITECTURE.md` §3 |
| Cutover/history defined? | `INTEGRATION_ARCHITECTURE.md` §6 |
| Phases separated? | `ROADMAP.md` |
| Invariants documented? | `ACCOUNTING_PRINCIPLES.md`, `SECURITY_INVARIANTS.md` |
| Continue from repo alone? | `CLAUDE.md` + this folder + `docs/specs` + `docs/status` |
