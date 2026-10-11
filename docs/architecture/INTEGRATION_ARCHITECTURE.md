# Integration Architecture

Status: MASTER baseline. Implementation: OA6 (generic infrastructure first,
then the OptiFleet adapter). Accounting Core never depends on an adapter, and
disabling `ACCOUNTING_INTEGRATION` never affects manual accounting.

## 1. Pipeline

```
External system
  → Integration Connection (lifecycle, tenant mapping, credentials)
  → Authentication (client credentials / signed webhook)
  → Adapter (OptiFleetAdapter, ERPAdapter, POSAdapter, CSVImportAdapter, …)
  → Canonical Financial Event (stored once in external_events)
  → Accounting Event Gate (idempotency, schema, period, entitlement, review)
  → Posting Rule → Account Mapping → Posting Engine → Journal / GL
```

Adapters translate business facts; they never choose accounts. Bad:
`debit_account=5110`. Good: `maintenance.external_cost.confirmed` with
amounts, tax, currency, vendor, work order and vehicle references.

## 2. Generic model (core tables, no product names)

- `integration_connections`: tenant, `source_system`, `source_tenant_reference`,
  status `DRAFT → CONFIGURING → READY → ACTIVE ⇄ PAUSED → ERROR → DISCONNECTED`
  (independent from the accounting subscription), cutover policy and date,
  gap policy, credential reference.
- `external_events`: inbox, `UNIQUE(connection_id, source_system, event_id)`,
  envelope + payload, status `RECEIVED → VALIDATED → POSTED | NEEDS_REVIEW |
  REJECTED | FAILED | DEAD_LETTER`, attempts, last error, resulting journal id.
- `external_dimensions`: `source_system, source_tenant_reference, entity_type
  (VEHICLE, WORK_ORDER, WORKSHOP, WAREHOUSE, VENDOR, PRODUCT, PROJECT, …),
  external_id, code, name, status, metadata` — references, not copies.
- `external_source_links`: journal/document ↔ `(source_system, source_type,
  source_id, source_document_number, source_event_id)` for traceability.
- External ids never become foreign keys and never appear as
  `optifleet_*` columns in core tables.

## 3. Event envelope and versioning

```json
{ "event_id": "uuid", "event_type": "maintenance.external_cost.confirmed",
  "schema_version": "1.0", "source_system": "optifleet",
  "source_tenant_reference": "…", "occurred_at": "ISO-8601",
  "correlation_id": "…", "source": {"type": "workshop_invoice", "id": "…", "number": "…"},
  "dimensions": {"vehicle": "…", "work_order": "…", "branch": "…"},
  "payload": { "amounts as decimal strings", "currency": "IDR" } }
```

- `event_type` + `schema_version`; additive changes stay in the major version,
  breaking changes need a new major. Unknown major → `NEEDS_REVIEW`, never guessed.
- Money is a decimal string, never a JSON number.
- Delivery is at-least-once; the receiver is idempotent on
  `(source_system, event_id)`; the same id with different content is a conflict
  (rejected and flagged), not a silent duplicate. No distributed exactly-once.
- The tenant comes from the authenticated connection, never from the payload;
  `source_tenant_reference` must match the connection's mapping.

## 4. Transport options

| Mode | Path | When |
|---|---|---|
| A. Via OptiNexus (preferred in `optinexus` mode) | Producer outbox → OptiNexus `POST /api/v1/events` → OptiNexus delivers to OptiEntry's inbound endpoint (or OptiEntry pulls a cursor feed through the OptiNexus API Gateway) | Ecosystem SaaS; tenant mapping comes from OptiNexus tenant ids |
| B. Direct API | Producer → `POST /api/v1/integration/events` with client-credentials token of an OptiEntry integration connection | Standalone installations, ERP/POS/custom systems |
| C. File import | CSVImportAdapter with the same canonical events | Migrations, legacy systems |

Gap recorded for OA6: OptiNexus today fans events out only to its own
workflows and notification rules (`event_deliveries.consumer_type` WORKFLOW /
NOTIFICATION; WEBHOOK is declared but not delivered). Mode A needs either an
application-consumer delivery in OptiNexus or a gateway cursor feed. That is
an OptiNexus change and an owner decision at OA6 start.

Outbound events from OptiEntry (e.g. `optiaccounting.journal.posted`,
`ap_invoice.paid` callbacks) use a transactional outbox written in the same DB
transaction as the business change; a relay delivers them after commit with
retry/backoff. No remote HTTP inside a financial DB transaction.

## 5. Failure isolation

Retry with backoff, dead-letter after N attempts, observable status per event,
manual authorized retry (permission + audit), and reconciliation of source vs
received/processed events: `MATCHED, MISSING, DUPLICATE, FAILED, REVERSED,
AMOUNT_MISMATCH`. An external outage never blocks manual accounting.

## 6. Cutover and reactivation

- Cutover policies: `OPENING_BALANCE_ONLY` (default) + events with
  `occurred_at ≥ cutover_date`; `START_FROM_CUTOVER_DATE`; `HISTORICAL_BACKFILL`
  (explicit, reviewed workflow only). Never auto back-post history.
- Reactivation gap policies: `REQUIRES_REVIEW` (default), `IGNORE_GAP`,
  `BACKFILL_GAP`, `START_NEW_CUTOVER`.

## 7. OptiFleet boundary (first adapter)

OptiFleet owns inventory quantity/valuation source, maintenance, work orders,
procurement documents, vehicles, tire/component lifecycle and warranty.
OptiEntry owns recognition, journals, GL, AP, AR, cash/bank, periods and
statements. Project instruction 8: Cost, Invoice, AP and Payment facts from a
tenant that also subscribes to OptiFleet-v2 are posted as double-entry journals
with references back to the source document.

What OptiFleet-v2 has today (read on 2026-10-08, `main` 7d063c7):

| OptiFleet fact | Source | Event today | Accounting meaning (via posting rules) |
|---|---|---|---|
| External workshop invoice recorded / corrected / cancelled | `WorkshopInvoice*` | `optifleet.workshop_invoice.recorded/.corrected/.cancelled` (outbox → OptiNexus) | AP invoice: Dr `MAINTENANCE_EXPENSE` (+ `INPUT_VAT`) / Cr `AP_CONTROL`; correction = reversal + new; cancel = reversal |
| Workshop invoice payment | `WorkshopInvoicePayment` | `optifleet.workshop_invoice.payment_recorded` | Dr `AP_CONTROL` / Cr `CASH_BANK` (or AP settlement awaiting bank confirmation, per tenant policy) |
| Maintenance memo billed / paid | `WorkOrderExternalService` | `optifleet.maintenance_memo.billed/.paid` | Memo accrual vs invoice: policy decided at OA6 to avoid double recognition |
| Vendor (procurement) invoice + payment | `VendorInvoiceReference`, `VendorInvoicePayment` | none yet | AP invoice against GR (Dr `INVENTORY_ASSET` or `GRNI_CLEARING` / Cr `AP_CONTROL`); payment as above |
| Goods receipt, stock issue to work order, adjustments | Procurement / Inventory | none yet | Dr `INVENTORY_ASSET` / Cr `GRNI_CLEARING`; Dr `MAINTENANCE_EXPENSE` / Cr `INVENTORY_ASSET` |
| Spare part sale | `SparePartSale` | none yet | AR invoice + revenue + COGS |

The missing events are OptiFleet-side work (outbox entries) agreed per event
type at OA6. Payments recorded in OptiFleet are operational records; whether
they post cash directly or wait for bank confirmation in OptiEntry is a
tenant policy (default: post, reconcile in bank reconciliation).

### 7.1 Owner decision (2026-10-08): direct path, OptiNexus unchanged

- OptiNexus is **not modified** for this integration. OptiFleet-v2 and OptiEntry talk directly.
  OptiFleet's existing outbox events to OptiNexus are unaffected; OptiEntry's own outbound
  events for OptiNexus remain a separate OA0-N matter.
- **Chosen shape: OptiEntry pulls from OptiFleet-v2 on a schedule** (OA6). Reasons:
  1. Smallest OptiFleet change: a read-only feed endpoint in new, isolated files; no consumer
     secret, webhook receiver or retry logic inside OptiFleet.
  2. Failure isolation: an OptiFleet outage only delays the next pull; manual accounting never blocks.
  3. Idempotent by design: monotonic sequence cursor per connection, at-least-once delivery,
     dedupe on `(source_system, event_id)`, "sync now" for urgent cases.
  Trade-off: latency equals the schedule interval (minutes, not seconds).
- Draft feed contract: `GET /api/v1/integration/accounting-feed?after=<cursor>&limit=<n>` returns canonical
  envelope events ordered by a monotonic sequence (never by timestamp). Authentication is a read-only
  machine credential issued by OptiFleet and scoped to one tenant mapping. Events missing today
  (vendor invoice/payment, goods receipt, stock issue, spare part sale) are added to the feed as
  additive entries, agreed per event type.
- **Conflict protocol before touching OptiFleet-v2:** (1) list open PRs, branches and recent `main` commits;
  (2) list the files they change; (3) put the work in new files only (controller, route file/section,
  service, migration with its own timestamp, tests), with at most one registration line in a shared
  file; (4) branch from the latest `main`, small draft PR; (5) report the check result to the owner
  before opening the PR; never merge. Nothing in OptiFleet-v2 is changed during OA0.

## 8. OA6 implementation notes (batch A: integration core)

Code: `backend/app/Domain/Integration/{Models,Services,Contracts}`, API `/api/v1/app/integration/*` (`routes/oa6_integration.php`),
tables from `2026_10_17_100001_create_integration_core.php`. Module `ACCOUNTING_INTEGRATION`, feature `INTEGRATION_CONNECTION`,
permissions `accounting.integration.view|manage|retry`, audit actions `accounting.integration.*`.

- **Connection lifecycle.** DRAFT / CONFIGURING / READY follow the configuration (credential, base URL, source tenant reference and
  cutover date all set = READY); a person then activates, pauses, resumes or disconnects; five failed pulls in a row set ERROR and
  `resume` leaves it. Activation also requires the cutover date (a null cutover would let the first pull back-post history). Source
  tenant reference and cutover cannot change once an event was received. The credential is write-only (encrypted cast, hidden,
  never audited). HISTORICAL_BACKFILL is refused; gap policy is stored, not applied. Reconfiguring a DISCONNECTED connection reconnects
  it to CONFIGURING/READY (one row per source tenant: `UNIQUE(tenant, source_system, source_tenant_reference)`).
- **Inbox (`EventInbox::receive`, the only writer of `external_events`).** Tenant and source come from the connection. An envelope
  that cannot be identified (event_id, event_type, schema_version `major.minor`, ISO-8601 `occurred_at`, object payload) or that
  belongs to another source is a `DomainException` and nothing is stored, so a pulled page containing it fails whole and its cursor
  stays. An identified event is always stored: REJECTED (`BEFORE_CUTOVER` in the tenant's local date; `MONEY_NOT_DECIMAL_STRING` for a
  JSON number under a key ending `amount|total|tax`), NEEDS_REVIEW (`UNSUPPORTED_EVENT`: the source's adapter does not support the
  type at that schema major), else RECEIVED. Same id and same canonical hash (`sequence` excluded) = duplicate (counted); same id with
  other content = `conflict` flag + audit, stored content untouched. `ON CONFLICT DO NOTHING` on the unique key makes parallel
  deliveries one row; a trigger forbids changing the envelope or deleting a row.
- **Processing.** `IntegrationAdapter` (`sourceSystem`, `supports(type, major)`, `process(event): ProcessOutcome`), tagged
  `AdapterRegistry::TAG` in the container. `EventProcessor` locks the event, runs the adapter plus source links plus the POSTED mark in
  one nested transaction. DomainException = NEEDS_REVIEW (business code kept); any other throwable = FAILED with backoff 1, 5, 15, 60
  minutes; the 5th failed attempt = DEAD_LETTER (audited). No adapter = NEEDS_REVIEW `NO_ADAPTER`. `RetryService` (permission
  `accounting.integration.retry`, reason required, audited) resets attempts and processes at once; REJECTED is final.
- **Pull.** `FeedClient` reads `GET {base_url}/api/v1/integration/accounting-feed?after=&limit=100` (bearer, 15 s, no redirects, `after`
  omitted on the first pull). `PullService`: HTTP outside any transaction, one transaction per page (ingest + cursor), at most 20
  pages per run, advisory lock per connection, new events then processed one by one. `integration:pull` and `integration:process`
  run every five minutes for ACTIVE tenants whose module and feature are writable; processing covers ACTIVE connections only.
- **Not implemented (deliberately):** push endpoint (`POST /api/v1/integration/events`), webhooks, CSV import adapter, outbox relay to
  sources, MISSING reconciliation (needs source totals; the summary shows received vs processed only), historical backfill, gap-policy
  behaviour, the `OPTIFLEET_CONNECTOR` feature check (belongs to the OptiFleet adapter batch).
