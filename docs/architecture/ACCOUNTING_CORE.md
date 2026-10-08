# Accounting Core (OA1) — durable decisions

Scope: module `ACCOUNTING_CORE` (features `ACCOUNTING_CONFIGURATION`, `JOURNAL`, `GENERAL_LEDGER`, `OPENING_BALANCE`).
Code: `backend/app/Domain/Accounting`, API `/api/v1/app/accounting/*`, UI `frontend/src/pages/accounting`.
Principles stay in `ACCOUNTING_PRINCIPLES.md`; this file records what OA1 decided on top of them.

## 1. One posting core
Manual journals, the opening balance, reversals and accounting events all end in `PostingEngine::post`. Lock order inside the
posting transaction (never reordered; concurrency tests depend on it):

1. journal row `FOR UPDATE` · 2. advisory **ledger gate** `oa1:ledger:{tenant}` in shared mode · 3. accounts `FOR SHARE` ·
4. period `FOR SHARE` (`PeriodGuard::resolveForPosting`) · 5. numbering row (`INSERT … ON CONFLICT DO UPDATE`, rolled back with the posting, so numbers are gapless).

A period close takes the period `FOR UPDATE` and refuses while SUBMITTED/APPROVED journals are pending, so a close either waits
for an in-flight posting or is seen by a later one (`PERIOD_CLOSED`). Database triggers repeat balance, immutability, period and
reversal-link checks; the service layer is never the only guard.

**Ledger gate.** The opening balance post takes the same advisory lock exclusively. It therefore waits for every posting in
flight and blocks new ones, which makes "no journal before the cutover date" a race-free check (`OPENING_BALANCE_HISTORY_EXISTS` /
`POSTING_BEFORE_CUTOVER`; exactly one of an opening post and an earlier-dated posting can win).

## 2. Periods
- Fiscal year: any first-of-month start, 1–18 monthly periods, status DRAFT → OPEN → CLOSED. Period: FUTURE → OPEN → SOFT_CLOSED → CLOSED.
- **CLOSED is terminal in OA1.** A controlled reopen with approval and audit is OA5; until then `PERIOD_INVALID_TRANSITION`.
- SOFT_CLOSED accepts postings only from users holding `accounting.journal.post_soft_closed` (checked through `EffectiveAccess`).
- A "period 13" (adjustment period) is deferred to OA5 together with year-end closing entries.
- Accounting dates are never derived from `created_at`: `document_date`, `transaction_date`, `posting_date` are explicit; the period is resolved from `posting_date`.

## 3. Journals and segregation of duties
- Types: `MANUAL`, `OPENING`, `REVERSAL`, `SYSTEM`. Workflow DRAFT → SUBMITTED → APPROVED → POSTED (+ REJECTED, CANCELLED). DRAFT → POSTED
  only when the profile has `approval_required = false`. Numbers `{prefix}-{fyCode}-{000000}` are issued at posting, not at draft.
- SoD is policy on the accounting profile, never role names: `sod_creator_not_approver` (default on), `sod_creator_not_poster`,
  `sod_approver_not_poster`. It guards MANUAL **and OPENING** journals; reversal and SYSTEM journals have their own permissions
  (`accounting.journal.reverse`, system authority). SoD never relaxes the double-entry rules.
- **Control accounts** (`is_control`): refuse MANUAL journals (`ACCOUNT_CONTROL_RESTRICTED`); OPENING, REVERSAL and SYSTEM journals may use them,
  because subledgers (OA2/OA3) will post through the engine.
- Reversal: new balanced journal with debit/credit swapped, posting date ≥ the original's, original keeps POSTED and gets only the one-time
  `reversed_by` link; a reversal is never reversed (row lock + unique index). Corrections are reversals, never edits.

## 4. Opening balance
One balanced `OPENING` journal per tenant, saved as a draft (`opening_balances`) and posted through the engine. Cutover date must not be after
the first posted journal. Reversing it frees the tenant to initialize again. In reports OPENING journals always count in the *opening* column
(up to the report's end date), never in period movement.

## 5. Ledger report semantics
GL and trial balance derive only from POSTED lines (`BalanceCalculator`); nothing is stored. opening = lines before `from` + OPENING journals ≤ `to`;
movement = lines in range except OPENING; ending = opening + movement, in the account's normal-balance direction. Data scope or a dimension filter
narrows the lines, so the response says `complete: false` and `reconciliation.reconciled = null`; only a complete scope proves Σ debit = Σ credit.
CSV exports (50 000 rows max) follow the same scope, neutralize spreadsheet formulas and are audited (`accounting.report.exported`).

## 6. Posting rules, mappings, events
Rules name **account roles**, never accounts; a tenant maps role → account (business unit > branch > default). Rules are versioned and effective-dated;
a published rule is immutable (service + trigger). `postEvent` is idempotent on `(source_type, source_id, purpose)`: a replay returns the original event,
the same key with a different payload hash is a conflict; a failure leaves a FAILED event row and no journal. The resolved rule version and
role → account facts are stored on the journal (`posting_snapshot`), so later configuration never reinterprets history.

## 7. Money and currency
`NUMERIC(20,4)` in PostgreSQL, `brick/math` in PHP, no floats. OA1 is **single functional currency** (profile `functional_currency`, scale 2 by default);
an account restricted to another currency is refused. Foreign currency, revaluation and FX gain/loss are OA4.

## 8. Authorization
Every route carries `access:{permission},module=ACCOUNTING_CORE,feature={F}`; the permission of each route is pinned in
`tests/Feature/Accounting/AccessMatrixTest.php`. READ_ONLY (module or subscription) refuses every mutating method and keeps every read; SUSPENDED/DISABLED/
expired/future/no subscription close all routes. Data scope (TENANT, BRANCH, BUSINESS_UNIT, OWN) applies to journals (a journal is visible when every
line is inside the scope, else 404), the dashboard, the dimension catalog, reports and exports.

## 9. Integration
`journal.posted` and `journal.reversed` go through the transactional outbox (`optiaccounting.*`, schema_version 1). The OptiNexus manifest is generated from
the permission table and `EventCatalog`, so OA1 adds its permissions and the two events automatically. **After deploying OA1 an OptiNexus
administrator must register the new manifest again** (`php artisan optiaccounting:nexus:manifest`, steps in `docs/integration/OPTINEXUS_ONBOARDING.md`);
until then OptiNexus cannot grant the new `accounting.*` permissions and, in `optinexus` mode, nobody holds them (fails closed). OptiNexus itself is not changed.
