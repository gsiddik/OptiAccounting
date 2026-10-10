# OA4 — Budget, Fixed Asset, Tax and Multi-Currency (durable decisions)

Modules: `ACCOUNTING_BUDGET` (BUDGET), `ACCOUNTING_FIXED_ASSET` (ASSET_REGISTER, DEPRECIATION), `ACCOUNTING_TAX` (TAX_CONFIGURATION, TAX_REPORT),
`ACCOUNTING_MULTI_CURRENCY` (EXCHANGE_RATE, FX_REVALUATION). All depend on `ACCOUNTING_CORE`; every financial posting still goes through
`PostingEngine::postEvent`. Code: `app/Domain/{Budget,FixedAsset,Tax,Currency}`. Brief: `docs/specs/OA4.md`. Status: `docs/status/OA4_STATUS.md`.

## 1. Budget (planning data, never a journal)
- **Two lifecycles.** The budget (one fiscal year, functional currency) is DRAFT > ACTIVE > CLOSED, or CANCELLED (only while no version was ever approved).
  A version is DRAFT > SUBMITTED > APPROVED > ACTIVE > SUPERSEDED, with REJECTED (back to DRAFT) and CANCELLED. Approval is always required (the
  profile's `approval_required` does not skip it); segregation of duties follows the profile flags through the shared `DocumentWorkflow`.
  History is `document_transitions` (`budget`, `budget_version`) plus audit (`budget.*`, `budget.version.*`).
- **Versions are history.** Labels ("Original", "Revision 1") are free text. Only a DRAFT version has editable lines (service + `oa2_lines_guard`);
  a later revision is a new version (optionally copied). Activation runs under the budget row lock: the previous ACTIVE version becomes SUPERSEDED with
  `effective_until = new start - 1 day`. A partial unique index (one ACTIVE per budget) and a GiST exclusion constraint (non-overlapping
  `[effective_from, effective_until]` windows) make "exactly one version in force on a date" a database fact. A revision must start after the active one
  started and inside the fiscal year; the first version defaults to the fiscal year start.
- **Lines.** account (a header account stands for its whole subtree) x period (must be in the budget's fiscal year, trigger) x optional branch / business
  unit / cost center (empty = covers every value), amount NUMERIC(20,4) >= 0 in the account's normal-balance direction. Lines of one version never overlap
  (account ancestry + dimension wildcards, checked under the version lock; natural-key unique index in the database), so an actual matches at most one line.
- **Budget vs Actual** (`BudgetVsActualService`) reads POSTED journal lines only through `BalanceCalculator::base` (drafts and pending journals never count;
  OPENING journals are not period activity). The version is the one in force on `as_of` (default: last day of the reported period range) or an explicit
  approved version. Variance = actual - budget; variance % is null for a zero budget; `favorable` depends on account type (revenue above plan, expense
  below plan, none for balance-sheet accounts). P&L activity no line covers is reported as `unbudgeted`. Dimension filters are strict equality on both sides.
- **Data scope.** Lines and actuals are narrowed by the user's branch / business unit scope (a line without a branch or unit is tenant-wide); a scoped user
  gets `complete = false`, cannot replace all lines or copy a version (they cannot see all of it) and can write only lines inside their scope.
- **No budget control** (warning / soft / hard) is implemented; the report is visibility only, as the brief requires.
