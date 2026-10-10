# Receivables and Revenue (OA3)

The receivables subledger on the OA1 Accounting Core, the mirror of the OA2 payables design. It owns **documents** (customer, customer
invoice, customer receipt, credit note); the Core owns **financial truth** (journal, GL, periods). There is no debit/credit engine here.

```
document (draft → submitted → approved → posted → reversed)
   → accounting event → posting rule → account role → account mapping
   → PostingEngine::postEvent (period row lock, numbering, balance) → posted journal → GL
```

## Module and features

`ACCOUNTING_AR` (requires `ACCOUNTING_CORE`), code in `app/Domain/Receivables`:

| Feature | Covers | Tables |
|---|---|---|
| `CUSTOMER` | customers, financial profile, AR payment terms (the OA2 `payment_terms` table is shared) | `customers` |
| `CUSTOMER_INVOICE` | invoices, revenue recognition | `ar_invoices`, `ar_invoice_lines` |
| `AR_RECEIPT` | receipts and their allocations, open-invoice list, allocation suggestion | `customer_receipts`, `ar_receipt_allocations` |
| `CREDIT_NOTE` | credit notes | `ar_credit_notes`, `ar_credit_note_lines` |
| `AR_AGING` | aging and the AR-to-GL reconciliation | none (derived) |

A tenant without the module behaves exactly as under OA2. A receipt also needs `ACCOUNTING_CASH_BANK` writable (it draws on a cash/bank
account); every posting or reversal needs `ACCOUNTING_CORE` writable (`ActorAuthority::assertLedgerWritable`).

## Accounting events (all through `PostingEngine::postEvent`)

| Event | Journal |
|---|---|
| `AR_INVOICE_RECOGNIZED` | Dr `ACCOUNTS_RECEIVABLE` total, Cr revenue (per line) net, Cr `TAX_PAYABLE` tax |
| `CUSTOMER_RECEIPT` | Dr cash/bank (`CASH_BANK_ACCOUNT`, bound to the document), Cr the receivable account each invoice was booked to |
| `AR_CREDIT_NOTE_RECOGNIZED` | Dr `REVENUE_ADJUSTMENT` net, Dr `TAX_PAYABLE` tax, Cr `ACCOUNTS_RECEIVABLE` total |

Reversal of any document uses the shared `ReversalService` (a mirrored journal linked to the original); there are no `*_REVERSED` events.
Revenue account precedence for an invoice line: the line's account, the line's role, the customer's default revenue account, then the
`REVENUE` mapping. The receivable account is the customer's override or the `ACCOUNTS_RECEIVABLE` mapping, frozen on the invoice at posting.
New role `REVENUE_ADJUSTMENT` (template account 4150 Retur dan Potongan Penjualan); `ACCOUNTS_RECEIVABLE` is restricted to the three AR events
(`restricted_events`), so a rule cannot book a receivable that has no document behind it. `OperationalSetupService::applyDefaults` publishes
the default rules.

## AR subledger

Outstanding is **derived**, never stored: invoice total − effective allocations of POSTED receipts − POSTED credit notes
(`ArSubledgerService`). A receipt is allocated **in full** to posted invoices before approval (no customer advance in OA3), so no receivable can
go negative; over-allocation is refused in the service under an invoice row lock and by a deferred DB check. A credit note cannot exceed the
invoice's outstanding, cannot take off more net/tax than the invoice recognised, and must belong to the invoice's customer. An invoice cannot be
reversed while effective allocations or POSTED credit notes touch it. Aging (configurable buckets shared with AP through `AgingBuckets`, as-of
replay) and the AR-to-GL reconciliation read the same derivation; the reconciliation compares every control account an invoice was booked to
with the subledger and shows an opening balance posted straight to a control account as its own component. A difference is reported, never adjusted.
Credit limit is information only and never blocks posting in OA3.

## Cash and bank

`cash_bank_account_in_use()` now includes `customer_receipts`, so a cash/bank account used by a receipt keeps its GL mapping. The cash/bank-to-GL
reconciliation counts `customer_receipts` as a document kind of its own (net = receipts + customer receipts − payments − vendor payments − expenses),
and the account ledger shows the receipt's number.

## Database guards (in addition to the service layer)

The OA2 `oa2_document_guard` pattern: POSTED invoices, receipts and credit notes are immutable and only the documented transitions are allowed;
per-document post guards re-check totals and journal links; composite FKs keep every relation inside one tenant; an allocation trigger
refuses an allocation above the invoice's outstanding (credit notes included) and allows it only for the customer's own invoices; a deferred
constraint trigger requires a posted receipt to be allocated in full; one allocation per (receipt, invoice). Violations surface as
`23514` and are mapped to domain errors. Customers in use are deactivated, never deleted.

## Access

Every route is gated by `RequireAccess(permission, module, feature)`; 23 permissions (`accounting.customer.*`, `accounting.ar_invoice.*`,
`accounting.ar_receipt.*`, `accounting.ar_credit_note.*`, `accounting.ar_aging.view`, `accounting.reconciliation.ar.view`; exports use
`accounting.report.export`). SoD is policy-driven (profile flags). Lists, exports, aging, reconciliation and the operational summary share one
query path and `ListFilters`, so tenant and data scope always match the screen; exports are capped, audited and CSV-formula-safe.

## Not in OA3 (documented extensions)

Customer advances / unallocated receipts (over-allocation is rejected instead of faking a negative receivable), debit notes (an invoice
covers an increase; no separate document was needed), foreign-currency documents, dunning/collections, attachments, and every integration with
OptiFleet or an ERP (OA6, pull-based). Tax rules, fixed assets and budgets are OA4.
