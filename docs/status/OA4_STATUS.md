# OA4 Status — Budget, Fixed Asset, Tax & Multi-Currency (OptiEntry)

Branch: `claude/project-thread-b9jx47` (from `main` f27bee3, after OA3 and the OptiEntry rebrand were merged). Status: **IN PROGRESS** (not merge-ready).
Brief: `docs/specs/OA4.md`. Design: `docs/architecture/OA4_BUDGET_ASSET_TAX_FX.md`. OptiNexus and OptiFleet-v2 are not modified. OA5 has not been started.

## Completed batches
- A Budget + versions + lines (lifecycle, activation windows, overlap rules) · B Budget vs Actual (+ CSV export, data scope) and budget tests
- C Asset categories, register, capitalization (POST / REGISTER_ONLY) · D Schedules and depreciation runs · E Disposal · F Reconciliation, asset rules setup, tests

## Important decisions
- Budget is planning data: no table references a journal. Versions are never rewritten; a revision is a new version and activation closes the previous window (DB-enforced).
- New permissions: `accounting.budget.{view,manage,submit,approve}`; routes under `module=ACCOUNTING_BUDGET,feature=BUDGET`.
- New permissions `accounting.asset.{view,manage,capitalize,dispose,reconciliation.view}`, `accounting.asset_category.manage`, `accounting.asset.depreciation.{run,post}`, `accounting.asset.disposal.{approve,post}`; routes under `module=ACCOUNTING_FIXED_ASSET`, features `ASSET_REGISTER` / `DEPRECIATION`.
- Generic access matrix trait `tests/Support/ModuleAccessMatrix.php` sweeps every route of a module for permission, tenant ownership and entitlement state.

## Migrations (additive, `2026_10_12_1000xx`)
100001 budgets, budget_versions, budget_lines (+ guards, exclusion constraint)
`2026_10_13_1000xx`: 100001 asset roles/events catalog · 100002 asset_categories, fixed_assets, asset_depreciation_schedules · 100003 asset_depreciation_runs/_run_lines · 100004 asset_disposals

## Accounting events added
`ASSET_CAPITALIZED`, `DEPRECIATION_RECOGNIZED`, `ASSET_DISPOSED` (roles FIXED_ASSET, ACCUMULATED_DEPRECIATION, DEPRECIATION_EXPENSE, ASSET_DISPOSAL_GAIN_LOSS)

## Financial invariants
Budget never creates or changes a journal (test); Budget vs Actual = POSTED lines only; approved versions immutable (service + triggers); a month is depreciated once, never beyond cost - residual, posted runs / schedule rows / disposals immutable (DB triggers); assets from one AP line never exceed it; cross-tenant account / dimension / period refused (service + composite FKs).

## Tests (executed this session)
| Check | Result |
|---|---|
| Budget feature tests (lifecycle 11, Budget vs Actual 8, access matrix 11) | PASS |
| Fixed asset: register 14, depreciation run 15, disposal 17, reconciliation 12, access matrix 11, unit schedule 7, concurrency 10 | PASS |
| SecurityTest, RbacTest, SeederAndBootstrapTest, Optinexus adapter, EntitlementAccessTest after the new permissions and routes | PASS |

## Known issues
none yet

## Remaining
Tax, multi-currency, React UI, cross-domain tests, regression, release gate.
