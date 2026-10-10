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

## 3. Tax (configuration, one calculator, a frozen snapshot per document line)
- **Master data.** `tax_codes` (type INPUT_TAX / OUTPUT_TAX / WITHHOLDING / OTHER, EXCLUSIVE or INCLUSIVE, STANDARD / ZERO_RATED / EXEMPT, recoverable flag, account role or
  explicit account) with effective-dated `tax_rates` (rate % with six decimals). Nothing is a statutory rule: the tenant enters the codes and rates it needs. A rate change is a
  new rate from a date (the previous one closes the day before, the timeline only moves forward, never behind a date a posted tax used). A used code is deactivated, never deleted.
- **One calculator** (`TaxCalculator`, pure `BigDecimal`): EXCLUSIVE tax = base x rate / 100; INCLUSIVE tax = entered x rate / (100 + rate) and base = entered - tax; HALF_UP at the
  currency scale, once per line. Zero-rated, exempt or a zero rate give no tax. AP, expense, AR, the API preview and the tests all call it; no controller or React code repeats it.
- **Documents** (`TaxDocumentService`): a line naming a tax code gets base + tax at the rate in force on the tax date (document date, not posting date). DRAFT `tax_transactions` follow the
  saved lines; approval and posting re-check that the configuration still gives the same amounts (`TAX_CONFIGURATION_CHANGED` otherwise: reopen and recalculate, no silent drift).
  Posting holds the code row FOR SHARE and the configuration change takes it FOR UPDATE, so a posting sees the old configuration whole or finds the new one. At posting the
  snapshot (code, type, rate, method, base, tax, account role / account, document number, journal) is frozen with the journal; reversal marks it REVERSED.
- **Posting.** Output and recoverable input tax go to the code's account role through the Posting Engine (`TAX_PAYABLE` / `TAX_RECEIVABLE`, or the explicit account). A non-recoverable
  input tax is folded into the cost of its line. Controllers and the tax module never name an account number.
- **Report** (`TaxReportService`): sums POSTED tax snapshots (reversals as negative movements) by code and rate, output vs input recoverable vs input non-recoverable, on
  posting-date or tax-date basis; CSV export under `accounting.report.export`; data scope applies through the owning document. Amounts are functional (see §4).
- **Known limitations.** WITHHOLDING codes can be configured but are not usable on documents yet; a header discount cannot be combined with tax codes (per-line tax needs per-line
  discount first); the tax of an AR credit note stays a manual header amount, shown as informational in the report; an APPROVED document whose tax configuration changed must be
  cancelled or reopened (it cannot post).

## 4. Multi-currency (functional ledger, foreign snapshot, realised difference)
- **Principle.** The ledger, the GL and every report stay in the functional currency (`accounting_profile`, frozen after the first posting, and refused while currencies or rates
  exist). A foreign amount is a snapshot beside the functional amount, never a second ledger. `journal_lines` carry `transaction_currency / transaction_debit / transaction_credit /
  exchange_rate`; a posted journal balances in functional currency AND in every transaction currency (DB trigger `journal_entries_guard`).
- **Masters.** `currencies` (tenant rows, ISO code, 0-4 decimal places; code and, once rates or documents exist, precision are fixed; deactivated, never deleted when used) and
  `exchange_rates` (from a currency into the functional one; value, date and type never change; a correction withdraws the rate and enters another; one live rate per
  currency / date / type is a partial unique index; a rate cited by a document cannot be deleted). Permissions `accounting.currency.{view,manage}`, `accounting.exchange_rate.{view,manage}`;
  routes under `module=ACCOUNTING_MULTI_CURRENCY,feature=EXCHANGE_RATE`.
- **One resolver** (`ExchangeRateResolver`). Latest active rate on or before the document date, no older than `OPTIENTRY_FX_RATE_MAX_AGE_DAYS` (31); same date: MANUAL, SPOT, DAILY,
  MONTH_END unless a type is asked for. Never assumes 1 for two different currencies: no rate means `EXCHANGE_RATE_NOT_FOUND` and nothing is saved. The chosen rate (id, value, date,
  type) is stored on the document. Submit, approve and post re-resolve it (posting holds the rate row FOR SHARE): a rate withdrawn or replaced in between gives
  `EXCHANGE_RATE_NOT_FOUND` / `EXCHANGE_RATE_CHANGED` instead of a silent change. After posting the document owns its rate; the master can change freely.
- **Conversion.** `ResolvedRate::convert` is the only place a foreign amount becomes functional (HALF_UP at the functional scale). An invoice converts each part (net lines, tax) on its
  own; the control line is the converted document total; the few smallest units of difference are added to the largest net part, never to a tax part, so the tax a report shows is
  the tax the ledger holds (`ForeignPostingService::invoice`). The rules and the engine only ever see functional amounts; the foreign leg travels as `transaction` on each part.
- **Invoices.** AP / AR invoices accept `currency` + `exchange_rate_type`; stored: `exchange_rate`, `exchange_rate_id/date/type`, `functional_total_amount` (NULL = functional document).
  The DB guards compare the posted journal with the functional total. Outstanding is kept in the invoice currency (`outstanding_amount`) and in functional carrying value
  (`outstanding_functional`); aging buckets, reconciliation and dashboard totals use the functional figure, aging detail also shows the foreign amount and rate.
- **Settlement and realised difference.** A foreign payment / receipt settles invoices of its own currency only (`*_ALLOCATION_CURRENCY_MISMATCH`) at its own rate (the rate for
  its posting date). At posting, under the invoice locks, `ForeignPostingService::settle` computes per allocation the value released from the invoice (`carrying_amount`: remaining
  carrying x foreign amount / foreign outstanding, all of it when the invoice is settled in full, so no rounding residue) and the value the bank moves
  (`settlement_amount`: the payment's functional amount spread by largest remainder). `fx_difference = settlement - carrying`. Events `VENDOR_PAYMENT_FX` /
  `CUSTOMER_RECEIPT_FX` (rules from `FxSetupService`, applied explicitly like the asset rules) debit / credit the control account by `carrying`, cash / bank by `settlement` and
  `FX_LOSS` / `FX_GAIN` (functional-only lines, `is_fx_difference`) by the difference. AP: payment above carrying = loss; AR: receipt above carrying = gain. The DB checks that a
  posted foreign payment's effective allocations add up to its functional amount and its difference. Reversal mirrors the journal with the original rates and releases the allocations.
- **Entitlement.** A foreign document needs `ACCOUNTING_MULTI_CURRENCY` / `EXCHANGE_RATE` writable (READ_ONLY, SUSPENDED, DISABLED refuse it, including reversal); functional documents never
  touch it, so single-currency tenants and old data behave exactly as before.
- **Contradiction recorded.** The brief lists FX revaluation; OA4 implements realised differences only. **FX Revaluation is NOT IMPLEMENTED** (feature `FX_REVALUATION` exists in the catalog
  for a later phase): unrealised gain / loss on open balances needs period-end revaluation journals with auto-reversal, which would be a financial feature without the owner's accounting-policy decision.
- **Known limitations.** Credit notes, expenses, cash / bank transactions, bank accounts and manual journals are functional only (a credit note against a foreign invoice is refused:
  `AR_CREDIT_NOTE_FOREIGN_INVOICE`); the settlement rate comes from the rate master (enter a MANUAL rate for the actual bank rate); a payment cannot span currencies; foreign tax codes
  use the invoice rate; no automatic rate feed.
