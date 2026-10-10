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

## 2. Fixed assets (register, depreciation, disposal; ledger stays the truth)
- **Documents.** `fixed_assets` (register card), `asset_depreciation_runs` + `_run_lines`, `asset_disposals`. Every posting is one Posting Engine event
  (`ASSET_CAPITALIZED`, `DEPRECIATION_RECOGNIZED`, `ASSET_DISPOSED`; rules applied by `AssetSetupService`, kept separate from the OA2 setup so older tenants are
  not forced to map new roles). New roles `FIXED_ASSET`, `ACCUMULATED_DEPRECIATION`, `DEPRECIATION_EXPENSE`, `ASSET_DISPOSAL_GAIN_LOSS`; the first two are
  restricted to the asset events, so no ordinary rule can move the control accounts behind the register. The `UMUM_ID` template maps them (new account 4250
  for the disposal gain or loss); an existing tenant maps them itself. An asset stores the accounts it was capitalized with (frozen); a category only supplies
  defaults, so a later category change never moves a capitalized asset.
- **Capitalization** (`POST` mode) posts Dr asset / Cr the named source account (payable, bank or clearing). `REGISTER_ONLY` mode registers cost that an AP
  invoice line already posted (line must be POSTED, name an asset account, and the registered cost of all assets from one line may not exceed the line):
  no second journal, so the ledger is never doubled. Capitalization can be reversed until depreciation exists (journal reversed, schedule cancelled, asset INACTIVE).
- **Schedule** (`ScheduleBuilder`): deterministic monthly rows created at capitalization from the frozen terms (straight line, declining balance factor 1-4, none),
  HALF_UP at the currency scale, the last row absorbs rounding so the total is exactly cost - residual. Calendar month; start month = capitalization month or the
  next. Rows are immutable (trigger); only `status`/`run_line_id` change. New methods are one class in `DepreciationMethods`.
- **Run** = eligible ACTIVE assets inside data scope with PLANNED months up to the period end. Calculating (DRAFT) locks the assets in id order and claims the
  months (PLANNED > IN_RUN) so two runs can never hold one asset-month; missed months are caught up by the next run. Posting re-validates under locks, posts one
  journal (expense/accumulated lines grouped per account + branch + unit + cost center), marks rows POSTED, updates the asset and sets FULLY_DEPRECIATED at the
  basis, all in one transaction. Reversal is LIFO per asset (`DEPRECIATION_RUN_NOT_LAST`). Posted runs/lines/rows are immutable in the database.
- **Disposal** (sale or scrap) has its own workflow with approval and SoD. Posting needs depreciation posted up to the disposal date and no draft run holding the
  asset, snapshots cost / accumulated / book value / gain / loss (DB check: cost + gain = accumulated + proceeds + loss) and sets the asset DISPOSED; the asset
  stays in the register with its history. One live disposal per asset (partial unique index). Reversal restores ACTIVE or FULLY_DEPRECIATED.
- **Reconciliation** (`AssetReconciliationService`) compares register and ledger per asset / accumulated account as of a date (reproducible for past dates) and
  reports a difference as it is; nothing is adjusted. Scoped users get `complete = false`. The journal reverse endpoint refuses journals owned by an asset document.
- **Limits.** Book depreciation only (no tax depreciation law), monthly granularity, no revaluation/impairment, no component split, single functional currency
  until the multi-currency batch; asset transfer between branches is not modelled (an asset keeps its capitalization dimensions).
