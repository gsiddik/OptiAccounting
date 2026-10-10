# OA4 Status — Budget, Fixed Asset, Tax & Multi-Currency (OptiEntry)

Branch: `claude/project-thread-b9jx47` (from `main` f27bee3, after OA3 and the OptiEntry rebrand were merged). Status: **IN PROGRESS** (not merge-ready).
Brief: `docs/specs/OA4.md`. Design: `docs/architecture/OA4_BUDGET_ASSET_TAX_FX.md`. OptiNexus and OptiFleet-v2 are not modified. OA5 has not been started.

## Completed batches
- A Budget + versions + lines (lifecycle, activation windows, overlap rules) · B Budget vs Actual (+ CSV export, data scope) and budget tests

## Important decisions
- Budget is planning data: no table references a journal. Versions are never rewritten; a revision is a new version and activation closes the previous window (DB-enforced).
- New permissions: `accounting.budget.{view,manage,submit,approve}`; routes under `module=ACCOUNTING_BUDGET,feature=BUDGET`.
- Generic access matrix trait `tests/Support/ModuleAccessMatrix.php` sweeps every route of a module for permission, tenant ownership and entitlement state.

## Migrations (additive, `2026_10_12_1000xx`)
100001 budgets, budget_versions, budget_lines (+ guards, exclusion constraint)

## Accounting events added
none yet

## Financial invariants
Budget never creates or changes a journal (test); Budget vs Actual = POSTED lines only; approved versions immutable (service + triggers); cross-tenant account / dimension / period refused (service + composite FKs).

## Tests (executed this session)
| Check | Result |
|---|---|
| Budget feature tests (lifecycle 11, Budget vs Actual 8, access matrix 11) | PASS |
| SecurityTest, RbacTest, SeederAndBootstrapTest, Optinexus adapter, EntitlementAccessTest after the new permissions and routes | PASS |

## Known issues
none yet

## Remaining
Fixed asset, tax, multi-currency, React UI, cross-domain tests, regression, release gate.
