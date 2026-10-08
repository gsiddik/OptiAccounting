# Roadmap

Each phase starts only on explicit owner instruction, from the latest `main`,
on a new branch, with its brief in `docs/specs/` and its checkpoint file in
`docs/status/`. A phase ends at its release gate (tests actually run, status
file updated, PR opened, merge only on owner request).

| Phase | Name | Scope (summary) | Brief |
|---|---|---|---|
| MASTER | Architecture foundation | Architecture docs, CLAUDE.md, status structure, minimal Laravel/React/Docker scaffold. No business features. | `specs/MASTER.md` |
| OA0 | Standalone SaaS foundation | Auth, tenant, membership, platform/tenant portals, dynamic RBAC, data scope, organization (branch, business unit), module catalog + dependencies, features, bundles, subscription, module/feature/capacity entitlement, effective access resolver, READ_ONLY gating, audit, Docker runtime. Identity-mode seam (`standalone` implemented; `optinexus` adapter contract defined). | `specs/OA0.md` |
| OA0-N | OptiNexus identity adapter | OIDC login, tenant/user linking, permission and entitlement sync from OptiNexus, Back-Channel Logout, outbox relay of `optiaccounting.*` events. Scheduled right after OA0 so SaaS mode is real before accounting data exists. | project instruction 1 (no separate brief; design in `OPTINEXUS_ADAPTER.md`, status `status/OA0-N_STATUS.md`) |
| OA1 | Accounting core & GL | Accounting profile, fiscal year, periods, COA (+ templates), cost centers/dimensions, journals (lifecycle, approval, SoD, numbering), posting engine, posting rules, account mapping, reversal, opening balance, GL, trial balance. | `specs/OA1.md` |
| OA2 | AP, expense, cash & bank | Vendors, vendor invoices, AP subledger, payments + allocation, expense, cash/bank accounts and transactions, AP aging, bank reconciliation foundation. | `specs/OA2.md` |
| OA3 | AR & revenue | Customers, customer invoices, credit/debit notes, receipts + allocation, customer advances, AR aging, revenue foundation. | `specs/OA3.md` |
| OA4 | Budget, fixed asset, tax, multi-currency | Budgets, asset register + depreciation + disposal, tax codes/rates (PPN, PPh), currencies, rates, realized/unrealized FX. | `specs/OA4.md` |
| OA5 | Reporting, closing, reconciliation | Financial statements via report mappings, comparative reports, cash flow, soft/hard close, controlled reopen, year-end, reconciliation engine. | `specs/OA5.md` |
| OA6 | Integration platform & OptiFleet connector | Connections, M2M auth, tenant mapping, external dimensions, canonical events, event gate, idempotency, adapter framework, webhooks, retry/dead letter, reconciliation, OptiFleet adapter (Cost, Invoice, AP, Payment). | no brief yet |
| OA7 | Analytics & management accounting | KPIs, cost analysis, budget analysis, analytical projections (MongoDB only if justified). | no brief yet |
| PROD | Production hardening & go-live | Release gate: financial integrity, isolation, security, concurrency, performance, backup/restore, DR, queues, schedulers, observability, CI/CD, staging, UAT. Not a feature phase. | — |

Seeders policy across phases: production-safe `DatabaseSeeder` (catalogs,
permissions, COA templates) and a separate `DemoSeeder` (demo tenant, users,
sample transactions) whose logins are documented in `docs/DEMO.md`.

Open owner decisions (not blocking MASTER):

1. ~~OA0-N placement~~ — decided by the owner: right after OA0.
2. ~~OA6 transport for OptiFleet events~~ — decided by the owner (2026-10-08):
   direct between the apps, OptiNexus unchanged; OptiAccounting pulls a
   cursor feed on a schedule (`INTEGRATION_ARCHITECTURE.md` §7.1).
3. Memo vs invoice recognition for OptiFleet maintenance costs (accrue at memo
   billing, or recognize only at workshop invoice).
