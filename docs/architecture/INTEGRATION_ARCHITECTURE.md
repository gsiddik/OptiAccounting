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
  sources, MISSING reconciliation (needs source totals; the summary shows received vs processed only), historical backfill. (Gap-policy
  behaviour came with §10; the `OPTIFLEET_CONNECTOR` feature check with §9.)

## 9. OptiFleet adapter notes (OA6 batch B)

Code: `backend/app/Domain/Integration/Adapters/OptiFleet` (`OptiFleetAdapter`, `WorkshopInvoiceBooking`, `OptiFleetVendors`,
`OptiFleetActor`), tagged in `AppServiceProvider`. The adapter translates business facts into calls to the existing AP services; it
names no account and writes no journal (the Posting Engine does, `AP_INVOICE_RECOGNIZED` / `VENDOR_PAYMENT`). It holds no state: the
registry is a singleton and the tenant context is scoped, so every collaborator is resolved from the container per event.

- **Events (schema major 1).** `workshop_invoice.recorded` creates an AP invoice (document and posting date = `invoice_date`, due date =
  `due_date`, the payment term is not applied; lines from the payload, tax = `tax_total`; `line_total` is kept as the line amount,
  quantity and unit price go to the line metadata; `total_amount` that disagrees with the lines = `TOTAL_MISMATCH`). `corrected`
  retires the previous document of the same source invoice and books the corrected one; `cancelled` only retires it; `payment_recorded`
  creates a vendor payment allocated to the linked invoice. Retiring = reversal of a POSTED invoice (reversal date = the later of its
  posting date and the event's local date, so it is never dated before the invoice and a closed period still refuses it), cancellation of an unposted one. An invoice with a
  payment applied in OptiEntry is not touched: `PAYMENT_ALREADY_APPLIED`. A payment for an unknown invoice is `INVOICE_NOT_FOUND`,
  for an unposted one `INVOICE_NOT_POSTED` (both retry-able once the invoice exists). `maintenance_memo.billed|paid` is accepted POSTED
  with no journal and no link: the cost is recognised once, at the workshop invoice (a memo is information, never a second expense).
- **Currency.** Functional currency only; another currency is `CURRENCY_NOT_SUPPORTED` (NEEDS_REVIEW). Amounts are decimal strings.
- **Settings.** `integration_connections.settings` (jsonb, additive migration `2026_10_17_200001`): `payables_cash_bank_account_id`
  (credited by payments) and `expense_category_id` (classifies every line; its account decides the debit side). Both are validated on
  update (UUID, owned by the tenant, category ACTIVE, cash/bank usable), are required to activate, and an ACTIVE connection cannot lose
  them. Generic core stays product-free through the optional `Contracts\ConnectorRequirements` (`cleanSettings`, `missingSettings`,
  `entitlementProblems`); a source whose adapter lacks it accepts no settings (`CONNECTION_SETTINGS_UNSUPPORTED`).
- **Entitlement.** Feature `OPTIFLEET_CONNECTOR` of `ACCOUNTING_INTEGRATION` plus `ACCOUNTING_AP` writable, checked at activate and
  resume (`CONNECTOR_NOT_ENTITLED`, 403, `details.reasons`) and by `EventProcessor`: while not entitled an event stays RECEIVED, untouched
  and not flagged, and is processed when the entitlement returns.
- **Vendor.** Resolved through `external_dimensions` (entity `VENDOR`, metadata `vendor_id`), then by normalised tax id (several matches =
  `VENDOR_AMBIGUOUS`, never guessed), then by code unless the tax ids differ; otherwise created once (`external_source` = optifleet).
  An INACTIVE vendor is not replaced (`VENDOR_INACTIVE`).
- **Actor and segregation of duties.** No system actor existed and every AP service needs a user. Decision: one credential-less service
  principal per tenant (kind `OPTIFLEET_INTEGRATION`, user `optifleet.integration@<tenant code>.optientry.invalid`: no password so it cannot
  sign in, ACTIVE membership, no role, no permission, TENANT data scope). Since the ecosystem audit (A23) *what is a machine* is decided by the
  immutable registry `service_principals`, not by the address: only the platform operator issues a row (`optientry:service-principal:issue`
  or `POST /api/v1/platform/tenants/{tenant}/service-principals`, permission `platform.service_principal.manage`; the migration mapped the
  users of earlier releases once, idempotently), a tenant has no way to create or flag one, a row never changes except for its final
  revocation (service layer and trigger), and the principal's user can never get a password, an OptiNexus identity, a role, another address
  or a membership of another tenant (triggers). A person can never hold an address of the range `optientry.invalid` (`EMAIL_RESERVED`): users
  are refused on save, in the invite / platform-user / OptiNexus sign-in paths and by a commit-time database check. Only a live registry row
  exempts a user from the seat count (`CapacityService`); a person on the range counts like anyone. The adapter never creates the user: without
  a live principal the connector is *not available* to the tenant (activation and resume `CONNECTOR_NOT_ENTITLED`, events stay RECEIVED and
  unflagged until the operator issues it). It is set as the context user only while the adapter runs and prepares documents (`created_by`).
  It is never an approver and holds no soft-closed exception. With approval required the invoice is SUBMITTED and a person approves and posts;
  otherwise it posts when the SoD policy allows the preparer to post, else it stays DRAFT for a person. SoD is therefore never weakened; the
  payment follows the same path, and its event is NEEDS_REVIEW `INVOICE_NOT_POSTED` until the invoice is posted. A tenant can switch the user
  off by suspending its membership (`INTEGRATION_ACTOR_DISABLED`); the operator revokes it for good (membership and user INACTIVE, a replacement
  is a new user `optifleet.integration.2@...`, posted history keeps naming the old one). Issue/revoke are audited (`service_principal.issued`,
  `service_principal.revoked`).
- **Traceability.** Every document gets an `external_source_links` row: source type `workshop_invoice` -> `ap_invoice`, and
  `workshop_invoice_payment` -> `vendor_payment` (document, journal and event ids). A correction adds a row for the new document; the
  latest row is the current document of that source invoice.
- **Not covered.** Vendor (procurement) invoices and payments, goods receipt, stock issue and spare part sale (not in the feed yet),
  foreign currency, branch/dimension mapping to journal lines. An event that cannot be booked as stated is held for a person
  (NEEDS_REVIEW), never booked approximately.

## 10. Ecosystem audit hardening (A17, A18, A20, A21, A22, A27, A28; migration `2026_10_18_100001`)

The audit of 2026-10-12 found that the OA6 slice could book from data nobody had decided to book. These rules are additive to §8-§9;
none changes a merged migration, and no existing row is rewritten.

- **Feed contract (A22).** `FeedClient` validates every page before anything is stored: cursor and `sequence` are digit strings (or JSON
  integers) that fit 64 bits, no leading zeros; at most `limit` events; sequences are exactly `after+1, after+2, ...` (gap-free, ascending);
  `next_cursor` = last sequence; an empty page does not advance or say `has_more`; nothing beyond `high_water`. A page that breaks a rule
  is a failed pull (cursor unchanged, counted toward ERROR). The source states `high_water` (newest committed sequence) on every page.
- **Lifecycle boundary (A20).** `integration_connections.lifecycle_version` is bumped by every pause, resume, disconnect, activation, error and
  reconfiguration. A pull remembers the version it started with; each page is stored in a transaction that locks the connection row FOR UPDATE
  and stores nothing (`halted`) if status or version changed. `EventProcessor`, `RetryService` and `ConflictService` lock the connection FOR
  SHARE (then the event FOR UPDATE; the order is always connection, then event), so a pause waits for processing in flight and nothing starts
  after it commits. A retry of a NEEDS_REVIEW/FAILED/DEAD_LETTER event on a connection that is not ACTIVE is refused (`CONNECTION_NOT_ACTIVE`);
  dismissing (`REJECTED`, `REVIEW_DISMISSED`) stays possible. A failure of a pull that a pause overtook is not counted.
- **Gaps (A18).** `integration_gaps`: pausing or disconnecting an ACTIVE/ERROR/PAUSED connection opens a gap at the cursor (`sequence_at_open`);
  switching it on again probes the source head (`high_water`, outside any transaction; `SOURCE_HEAD_UNAVAILABLE` when it cannot say) and
  closes the gap at `ceiling_sequence` with the connection's policy at that moment. Events in (open, ceiling] carry `gap_id` and follow the
  policy at the inbox gate: `REQUIRES_REVIEW` -> NEEDS_REVIEW `GAP_REVIEW`; `BACKFILL_GAP` -> NEEDS_REVIEW `GAP_BACKFILL_REVIEW`;
  `IGNORE_GAP` -> REJECTED `GAP_IGNORED`; `START_NEW_CUTOVER` -> REJECTED `GAP_NEW_CUTOVER` and the cutover date moves to the day of
  reconnection (tenant time). A person decides the held ones for the whole gap (`POST gaps/{id}/resolution`: PROCESS through the normal retry
  path, so approval, SoD and closed periods still apply, or DISMISS), at most 100 per call. Nothing is auto-posted for a gap.
- **Snapshot basis (A17).** An event whose `payload.snapshot_basis` is not `event_time` (e.g. rebuilt from current state) waits NEEDS_REVIEW
  `SNAPSHOT_BASIS_REVIEW`; only a person releases it. The producer's repair/monitoring duties are in the OptiFleet-v2 feed document.
- **Conflicts (A21).** A known event id with different content keeps the stored event, records the disputed payload in
  `external_event_conflicts` (append-only evidence; a trigger forbids changing it, deleting it, or touching a resolved row), flags the event
  and, if it was not posted, holds it NEEDS_REVIEW `PAYLOAD_CONFLICT`. A flagged event is never processed or retried until
  `POST events/{id}/conflict-resolution` (`accounting.integration.manage`, reason required): USE_ORIGINAL (processed with the original) or
  REJECT (REJECTED `CONFLICT_REJECTED`, impossible once posted: reverse the document). A posted event with a conflict stays posted and shows
  the open incident. If the source really changes its mind it sends a new event id (a correction).
- **Outcome (A27).** `external_events.status = POSTED` means processed, not "in the ledger". `accounting_outcome` says what reached the books:
  `JOURNAL_POSTED`, `AWAITING_APPROVAL` (SUBMITTED), `DRAFT_DOCUMENT`, `INFORMATIONAL` (memos), `NO_EFFECT` (e.g. a never-posted invoice
  cancelled); `document_status` is the document's status then. Events processed before this release have NULL: the API derives
  `JOURNAL_POSTED` from `resulting_journal_id` and otherwise leaves it null (counted as `UNCLASSIFIED` in the summary), nothing is
  guessed or rewritten. The summary adds `processed` (= `posted`, the older name), `outcomes`, `backlog` and `open_gap`.
- **Scheduling (A28).** `integration:process` takes up to 200 due events per ACTIVE connection per run (oldest first) instead of 200 overall,
  and skips a connection whose connector reports entitlement problems without touching its events (counted `held`). The connection
  summary shows `backlog` (due, oldest age, `blocked_by` CONNECTION_NOT_ACTIVE | CONNECTOR_NOT_ENTITLED, `stalled` after 30 minutes).
- **Contradiction recorded.** §6 says reactivation never back-posts history; the stored `gap_policy` was not applied until now. The safest
  backward-compatible reading is used: the default `REQUIRES_REVIEW` holds, it never books.
