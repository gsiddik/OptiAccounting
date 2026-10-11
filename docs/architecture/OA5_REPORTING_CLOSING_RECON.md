# OA5 — Financial Reporting, Closing & Reconciliation (OptiEntry)

Design contract for OA5 (brief: `docs/specs/OA5.md`). Builds on `ACCOUNTING_CORE.md`, `ACCOUNTING_PRINCIPLES.md`, `OA4_BUDGET_ASSET_TAX_FX.md`.
Module: `ACCOUNTING_REPORTING` (depends on `ACCOUNTING_CORE`); features `FINANCIAL_STATEMENTS`, `PERIOD_CLOSING`, `RECONCILIATION`.

## 1. Principles
- POSTED journal lines stay the only financial truth. Reports are computed live; nothing here stores an amount that a report or a check relies on.
  No report snapshot is persisted (reports are reproducible from posted data + the report-definition version effective on the date).
  Evidence tables (`reconciliation_runs`, `closing_events.readiness_checks`) record *what a person saw*, are append-only, and are never read back as truth.
- One calculation path per statement: `BalanceCalculator::base()` (tenant + data scope + POSTED) → `ReportQuery` (balances as of / movement) → `StatementEngine`
  (definition tree + mapping) → controller / export / drill-down. React and controllers do no accounting math.
- Data scope applies to every figure including totals (`AccountingScope::restrictLines` inside `base()`); a report built from a narrower scope says `complete=false` and never claims it balances.
- Backward compatible: tenants without `ACCOUNTING_REPORTING` keep OA1–OA4 behaviour (legacy TB/GL/period routes unchanged, no mapping required).
- Closing journals (source_type `YEAR_END_CLOSING`, `Accounting\Support\SourceTypes`) are excluded from P&L, cash flow and period comparatives, included in TB, GL and balance sheet.

## 2. Ownership and layout
| Area | Namespace | Controllers | Routes file |
|---|---|---|---|
| Reporting (A–E) | `App\Domain\Reporting\{Models,Services,Support}` | `Api\App\Reporting\*` | `routes/oa5_reporting.php` |
| Reconciliation (F–H) | `App\Domain\Reconciliation\{Models,Services}` | `Api\App\Reconciliation\*` | `routes/oa5_reconciliation.php` |
| Closing (I–K) | `App\Domain\Closing\{Models,Services}` | `Api\App\Closing\*` | `routes/oa5_closing.php` |
Shared (already in place): `Entitlement\Services\ModuleAvailability` (is a module entitled now: NONE / READ_ONLY / FULL), `Reconciliation\Services\ReconciliationResult` (+ `ReconciliationEngine::compute(type, asOf)` / `computeAll(asOf)` contract used by close readiness), `Accounting\Support\SourceTypes`.

## 3. Migrations (additive, `2026_10_16_*`)
`100001` report_definitions, report_lines, report_line_mappings (DRAFT-only change, trigger-enforced; exclusion constraint = one version per date) ·
`100002` reconciliation_runs (append-only), reconciliation_exceptions · `100003` closing_settings, closing_policy_rules, closing_checklist_templates/items, closing_events (append-only),
year_end_closings (one POSTED per fiscal year), replaced `accounting_periods_guard` (CLOSED can be left only inside a transaction that wrote the matching `REOPEN` row in `closing_events`, matched by `txid_current()`), catalog row `YEAR_END_CLOSING`.

## 4. Reporting
**Definitions.** `report_definitions` (per statement type BALANCE_SHEET / PROFIT_LOSS / CASH_FLOW, versioned, DRAFT → ACTIVE → ARCHIVED) · `report_lines` tree (`parent_id`, `display_order`) ·
`report_line_mappings` (account → line, `include_descendants` for a header account). Line types: HEADER (label only), ACCOUNT / ACCOUNT_GROUP (amount from mapped accounts; `balance_side` DEBIT = debit−credit, CREDIT = credit−debit),
SUBTOTAL (sum of its child lines), CALCULATED_TOTAL (`operands` = [{line, sign}] over other line codes, no formula language), SYSTEM (closed list of `system_key`: CURRENT_RESULT, NET_RESULT, CASH_OPENING, CASH_CLOSING, NET_CASH_CHANGE, UNCLASSIFIED_CASH_FLOW).
Publishing sets the effective window and archives the previous version the day before. A report uses the version effective on `as_of` / `to` (or an explicit `definition_id`), so history stays interpretable.
**Validation** (`POST report-definitions/{id}/validate`; runs again on publish and before any statement is produced, structural errors → 422 `REPORT_DEFINITION_INVALID`): unknown / duplicate line code, parent cycle, operand to a missing line or a cycle,
SUBTOTAL/CALCULATED_TOTAL with no operands, mapping of an account of another tenant / inactive / non-postable without `include_descendants`, an account mapped twice or covered by two overlapping groups, wrong account type for the statement
(balance sheet: ASSET/LIABILITY/EQUITY; P&L: REVENUE/EXPENSE; cash flow: balance-sheet accounts only, never a cash/bank account nor a REVENUE/EXPENSE account), invalid cash flow category, missing required SYSTEM lines. Warnings: unmapped accounts that carry a balance.
**Defaults.** `POST report-definitions/defaults` builds DRAFT definitions from the tenant's own COA structure (roots by `account_type`, sections from the hierarchy, cash-flow categories from account roles and sibling groups) — never from code prefixes; the owner reviews and publishes.
**Temporal semantics.** Balance sheet = as-of (cumulative POSTED lines up to `as_of`, OPENING journals included). P&L and cash flow = period `[from, to]` (movement only, OPENING and closing journals excluded; `fiscal_year_id` / `period_id` shortcuts, YTD = fiscal-year start..to).
Nothing defaults to "today" silently except the tenant business date when no date is given, and the response always echoes the dates used.
**Balance sheet.** Sections from the mapping + synthetic equity line CURRENT_RESULT = cumulative REVENUE/EXPENSE accounts not yet closed (by `account_type`, so it is zero after year-end). `integrity`: assets, liabilities+equity, difference, `balanced` (null when the data scope is incomplete),
and `unmapped` accounts with a balance (then `reliable=false`). A difference is shown, never absorbed.
**Cash flow (indirect).** Cash accounts = accounts mapped to roles CASH / BANK in `account_mappings` plus the GL accounts of `cash_bank_accounts`. Net result (P&L accounts) ± the cash effect (−Δ debit-minus-credit) of every other balance-sheet account, classified by its report line
(OPERATING / INVESTING / FINANCING / NON_CASH / UNCLASSIFIED), closing journals excluded on both sides. `integrity`: opening cash + net change vs closing cash from the GL, difference visible; unclassified accounts with movement are listed.
**Comparative.** `mode` PRIOR_PERIOD | PRIOR_YEAR | BUDGET: columns current, comparison, variance, variance_pct (null when the denominator is zero). BUDGET reuses `Budget\Services\BudgetVsActualService` (only when `ACCOUNTING_BUDGET` is entitled); no second actual engine.
**Multi-currency.** Statements are in the functional currency from POSTED functional amounts. GL detail may show transaction currency, foreign amount and the rate snapshot stored on the line; nothing is revalued at today's rate.
**Dimensions.** `branch_id`, `business_unit_id`, `cost_center_id` filters go through `BalanceCalculator::base()` (the OA1 dimension framework).
**Drill-down.** statement line → accounts with amounts → existing GL (`account_id`, same range, same filters) → existing journal → source document; each hop is an existing authorized endpoint.
**Export.** CSV through `CsvExporter` with the same service, filters, permission (`accounting.report.export`) and data scope as the screen; audit `accounting.report.exported`. XLSX is NOT IMPLEMENTED (no spreadsheet library in the repository); PDF NOT IMPLEMENTED.
**Performance.** SQL aggregation (`GROUP BY account_id`) once per statement, no per-account queries; pagination server-side for GL; indexes inspected with EXPLAIN on a generated dataset (results in the status file). No cache (correctness first); if one is ever added it must key on tenant, filters, period and data scope.

## 5. Reconciliation
Types `AP_GL`, `AR_GL`, `CASH_BANK_GL`, `FIXED_ASSET_GL`, `TAX_GL`, `FX_GL`; a type exists only when its module is entitled (`ModuleAvailability`) — otherwise `NOT_APPLICABLE`.
Statuses `MATCHED | MISMATCH | INCOMPLETE | NOT_APPLICABLE` (accounting vocabulary only). Both sides use the **same** cut-off (`as_of` date, or the end date of a period). Adapters wrap the OA2–OA4 services (`ApReconciliationService`, the AR one, `CashBankReconciliationService`,
`AssetReconciliationService`) and add `TAX_GL` (tax transactions vs configured tax control accounts) and `FX_GL` (foreign open balances vs functional carrying amounts and realised FX postings; revaluation is NOT IMPLEMENTED by owner decision and is never invented).
Tolerance = `closing_settings.reconciliation_tolerance` (default 0, strict). INCOMPLETE when the user's data scope is narrower than the tenant. Nothing posts an adjustment to force equality.
Exceptions: `run` stores a `reconciliation_runs` row and upserts the live exception of (type, as_of) with fresh numbers. `explain` / `assign` change only the work item; `resolve` is accepted only when a fresh recomputation shows no difference. A person can never set MATCHED.
Dashboard overview = live `computeAll`, no persistence. Legacy routes `reconciliation/{ap,ar,cash-bank}` and `asset-reconciliation` stay as they are; new generic routes use the plural `reconciliations/...`.

## 6. Closing
**Checks** (`Closing\Services\CloseReadinessService`, module-aware, one place) — default severity, policy rows override unless locked:
`TRIAL_BALANCE` BLOCK (locked) · `PENDING_JOURNALS` SUBMITTED/APPROVED journals dated in the period, BLOCK (locked, OA1 invariant) · `DRAFT_JOURNALS` WARN · `PENDING_DOCUMENTS` submitted/approved unposted AP/AR/expense/cash documents of active modules, WARN ·
`AP_RECONCILIATION` / `AR_RECONCILIATION` / `CASH_BANK_RECONCILIATION` / `FIXED_ASSET_RECONCILIATION` BLOCK · `DEPRECIATION_COMPLETE` BLOCK (fixed asset module) · `TAX_RECONCILIATION` / `FX_RECONCILIATION` WARN ·
`PREVIOUS_PERIODS_CLOSED` WARN · `REPORT_MAPPING` WARN (active definitions leave balances unmapped) · `CHECKLIST` required manual items, BLOCK for hard close (locked). A module that is not entitled is SKIPPED, never required.
Result `READY | NOT_READY` + per check `{code, title, severity, result PASS|FAIL|WARN|SKIPPED, message, details}`; READY means no failing BLOCK check. System checks are computed live and cannot be marked PASS by hand; manual checklist items (`closing_checklist_items`, from templates) are `PENDING | COMPLETED | WAIVED` (waive needs a reason).
All closing actions need a **tenant-wide data scope** (otherwise `CLOSING_REQUIRES_FULL_SCOPE`, 403): readiness over a narrower scope would be a lie.
**Actions** (all recorded in the append-only `closing_events` with actor, reason, readiness evidence, and audited): `pre-close` (repeatable, idempotent, locks nothing) · `soft-close` (OPEN → SOFT_CLOSED, no readiness gate, evidence recorded) ·
`hard-close` (OPEN | SOFT_CLOSED → CLOSED) · `reopen` (CLOSED → SOFT_CLOSED | OPEN, reason required, dedicated permission; policy: `reopen_max_periods_back`, `reopen_requires_later_open`, `separate_closer_and_reopener`; refused with `YEAR_END_POSTED` while the fiscal year has a POSTED year-end closing).
**Race safety.** hard-close / reopen / year-end take, in this order: the tenant ledger gate (`pg_advisory_xact_lock(hashtextextended(PostingEngine::ledgerGate(tenant), 0))`, exclusive; postings hold it shared) → the period row `FOR UPDATE` (postings hold it `FOR SHARE`) → evaluate readiness *inside* the lock → transition + history + audit.
Same lock order as `PostingEngine::post`, so no deadlock; a posting that wins commits before the close sees it, one that loses sees CLOSED (service) and the `journal_entries_guard` trigger re-checks the period. Two simultaneous closes serialize; the second finds CLOSED.
**Legacy routes** `periods/{p}/soft-close|close|open` remain; for a tenant with `ACCOUNTING_REPORTING` entitled they delegate to the same closing service (readiness, history, concurrency identical) and additionally require the new permission, so they are not a bypass.
Soft-closed exceptional posting (`accounting.journal.post_soft_closed`) additionally writes audit `accounting.journal.posted_in_soft_closed_period`.
**Policy.** `closing_policy_rules` (check_code → BLOCK|WARN|OFF; locked checks cannot be relaxed) and `closing_settings` (tolerance, reopen limits, SoD, `require_soft_close_first`); absent rows = code defaults. Changes are audited.
**Year-end.** `YearEndClosingService`: preview → run → (reverse). Closes the **cumulative** balance of every REVENUE / EXPENSE account as of the closing date (the last day of the fiscal year) into the account mapped to role `RETAINED_EARNINGS`, per branch / business unit group so dimension reports stay intact.
It posts through `PostingEngine::postEvent('YEAR_END_CLOSING', source_type YEAR_END_CLOSING, source_id "<fiscal_year_id>:<run_no>")` with the posting rule `YEAR_END_CLOSING` (lines: DEBIT `debit_close` and CREDIT `credit_close` on the accounts named in the distribution, CREDIT `profit` / DEBIT `loss` on role RETAINED_EARNINGS);
defaults for the rule are applied with `POST year-end/rule-defaults`. No balance is ever updated directly. Preconditions: fiscal year OPEN, last period OPEN or SOFT_CLOSED (a soft-closed one is an audited exceptional posting by the permission `accounting.year_end.run`), no pending journals in the year, mapping + rule present, `year_end_closings` has no POSTED run for the year (partial unique index).
Correction = `reverse` (reason required, needs `accounting.year_end.run` and, if a reopen is needed, `accounting.period.reopen`): reopens the last period / fiscal year under the controlled-reopen rules, reverses the closing journal through `ReversalService`, marks the run REVERSED; then a new run is allowed. History is never deleted.

## 7. Permissions (new, 16) and routes
`accounting.reporting.{balance_sheet,pnl,cash_flow}.view` · `accounting.report_definition.{view,manage}` · `accounting.reconciliation.{view,run,exception.manage}` · `accounting.closing.{checklist,policy}.manage` ·
`accounting.period.{preclose,soft_close,hard_close,reopen}` · `accounting.year_end.{view,run}`. Reused: `accounting.trial_balance.view`, `accounting.gl.view` (also account balance), `accounting.report.export`, `accounting.period.view`, `accounting.budget.view`.
READ_ONLY entitlement allows the GET report/export routes and denies every POST/PUT/PATCH/DELETE (central resolver). A subledger report/reconciliation also requires that subledger's module.
Routes (prefix `/api/v1/app/accounting`, `access:<perm>,module=ACCOUNTING_REPORTING,feature=<F>`; TB/GL stay on ACCOUNTING_CORE and are only extended):
`reports/{account-balance,balance-sheet,profit-loss,cash-flow,comparative}` (+`/export`, `reports/drilldown`) · `report-definitions` (+`/{id}`, `/{id}/lines`, `/{id}/mappings`, `/{id}/validate`, `/{id}/new-version`, `/{id}/publish`, `/defaults`) ·
`reconciliations`, `reconciliations/{type}`, `reconciliations/{type}/run`, `reconciliations/runs`, `reconciliation-exceptions` (+assign/explain/resolve) · `closing/{overview,policy,checklist-templates,history}`, `closing/periods/{period}/{readiness,pre-close,checklist,soft-close,hard-close,reopen}` ·
`year-end`, `year-end/{fiscalYear}/{preview,run,reverse}`, `year-end/rule-defaults`.

## 8. Audit actions
`accounting.report_definition.{created,updated,published,archived,deleted}` · `accounting.report_mapping.changed` · `accounting.report.exported` · `accounting.reconciliation.run` · `accounting.reconciliation.exception.{opened,assigned,explained,resolved}` ·
`accounting.closing.policy.updated` · `accounting.closing.checklist.{completed,waived,reset}` · `accounting.period.{pre_closed,soft_closed,hard_closed,reopened}` · `accounting.year_end.{run,reversed}` · `accounting.journal.posted_in_soft_closed_period`.

## 9. Decisions recorded
- Trial balance and GL stay on their OA1 routes/permissions (backward compatible) and gain filters; new statements are `ACCOUNTING_REPORTING`.
- Closing journal = SYSTEM journal with a `source_type` marker (no new journal type, no CHECK change). Year-end closes cumulative balances, so an unclosed earlier year is swept too.
- FX revaluation stays NOT IMPLEMENTED (owner decision 2026-10-11); `FX_GL` reconciles realised FX and foreign open balances only.
- Cash flow classification is configuration (line category), with defaults derived from account roles and hierarchy; unclassified movement is shown, never hidden.
- Closing is blocked for a narrower data scope; locked checks (trial balance, pending journals, required checklist) cannot be relaxed by policy.
- Closing implementation notes: `ClosingLocks` takes the period `FOR UPDATE NOWAIT` in a savepoint and retries (about 3 s, then 409 `PERIOD_BUSY`) because document services lock their period before the engine's shared ledger gate. A NOT_READY hard close writes no history row (it rolls back); pre-close and successful closes keep their evidence. Checklist items are virtual until the first action.
- Legacy period routes for a reporting tenant: close with pending journals answers 409 `PERIOD_NOT_READY` (was `PERIOD_HAS_PENDING_JOURNALS`); soft-close/close also need `accounting.period.soft_close|hard_close`; READ_ONLY module answers 403 `MODULE_NOT_AVAILABLE` (`details.reason MODULE_READ_ONLY`). `FiscalCalendarService::transitionPeriod` stays untouched for programmatic callers.
- Year-end: routes use feature `PERIOD_CLOSING`; retained earnings is the DEFAULT-scope `RETAINED_EARNINGS` mapping (one account per tenant), profit/loss is posted per branch + business-unit group, cost center is not carried. Extra blockers: `YEAR_END_LATER_YEAR_POSTED` (also for reversing an earlier year), `YEAR_END_RULE_INVALID`, `YEAR_END_ACCOUNT_INACTIVE`, `YEAR_END_NOTHING_TO_CLOSE`, `YEAR_END_FUTURE_PERIODS`. Reverse reopens the last period to SOFT_CLOSED only when the actor holds `accounting.journal.post_soft_closed`, else OPEN. A closing journal reversed through the generic journal route is caught up by the next year-end reverse (run marked REVERSED).
- Known OA1 behaviour left as is: `ReversalService` authorises soft-closed posting with the caller's own `accounting.journal.post_soft_closed`; `closeFiscalYear` does not require a year-end run.
