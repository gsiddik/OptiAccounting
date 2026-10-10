# OA4 release gate — traceability

Each line points at tests that ran green in the final full run (`OA4_STATUS.md`). Classes are under `backend/tests/`:
**BL** `Feature/Budget/BudgetLifecycleTest`, **BV** `…/BudgetVsActualTest`, **FR** `Feature/FixedAsset/FixedAssetRegisterTest`, **DR** `…/DepreciationRunTest`,
**AD** `…/AssetDisposalTest`, **AR** `…/AssetReconciliationTest`, **DS** `Unit/DepreciationScheduleTest`, **TC** `Feature/Tax/TaxCodeTest`, **TD** `…/TaxDocumentTest`,
**TR** `…/TaxReportTest`, **TU** `Unit/TaxCalculatorTest`, **FI** `Feature/Currency/ForeignInvoiceTest`, **FS** `…/ForeignSettlementTest`, **CR** `…/CurrencyAndRateTest`,
**XD** `…/CrossDomainTest`, **MX** the four `*AccessMatrixTest` (Budget, FixedAsset, Tax, Currency), **CO** `Concurrency/{FixedAsset,Tax,Currency}ConcurrencyTest`.

## §61 Budget
| Requirement | Tests |
|---|---|
| Budget does not affect the GL | BL `a_budget_never_creates_or_changes_a_journal` |
| Version history preserved; only the effective approved version counts | BL `activating_a_revision_supersedes_the_previous_version_without_rewriting_it`, `the_database_keeps_one_active_version…`; BV `the_version_in_force_decides_the_budget_and_only_approved_versions_are_compared` |
| Cross-tenant account / dimension rejected | BL `a_tenant_cannot_use_accounts_dimensions_budgets_or_versions_of_another_tenant`, `budget_lines_validate_account_period_dimensions_and_amount_inside_the_tenant` |
| Actual from POSTED journals only; deterministic variance; data scope | BV `actual_comes_only_from_posted_journals_and_variance_is_deterministic`, `data_scope_narrows_the_budget_lines_and_the_actuals_a_user_sees` |

## §62 Fixed asset
| Requirement | Tests |
|---|---|
| Capitalization once; account via mapping | FR `capitalization_posts_exactly_once_through_the_engine_with_mapped_accounts`; CO `the_same_draft_asset_capitalized_by_several_requests_at_once_is_capitalized_exactly_once` |
| Deterministic schedule, rounding, never above the basis | DS (7 tests); FR `accumulated_depreciation_can_never_exceed_the_basis_in_the_database` |
| Duplicate asset-period depreciation prevented | DR `a_month_can_never_be_depreciated_twice`; CO `runs_calculated_at_once_for_the_same_period_hold_every_month_exactly_once` |
| Closed period blocks; posted depreciation immutable | DR `a_closed_or_future_period_takes_no_run…`, `a_posted_run_its_lines_and_its_schedule_rows_are_immutable_in_the_database` |
| Disposed asset stops depreciating; NBV, gain, loss | AD `a_sale_above_book_value…`, `a_sale_below_book_value…`, `scrapping_writes_off…`, `months_after_the_disposal_date_need_no_depreciation…` |
| Register reconciles to the GL | AR `the_register_matches_the_ledger_through_capitalization_depreciation_and_disposal`, `a_difference_is_reported_as_it_is_and_never_adjusted` |

## §63 Tax
| Requirement | Tests |
|---|---|
| Effective-dated rates; history unchanged after a rate change | TC `the_rate_timeline_only_moves_forward…`, `a_rate_cannot_be_added_back_to_a_date_a_posted_transaction_already_used`; TD `a_rate_change_never_moves_a_posted_transaction…`; TR `a_later_rate_change_does_not_move_a_past_period` |
| Inclusive / exclusive / zero / exempt | TU (5 tests); TD `an_inclusive_code_carves_the_tax_out…`, `zero_rated_and_exempt_codes_record_the_transaction_with_no_tax` |
| Tax accounts mapped, not hardcoded | TD `the_tax_posts_to_the_account_the_code_names_and_not_a_hardcoded_one`, TC `a_code_is_created_with_its_first_rate_and_the_default_account_role_of_its_type` |
| AP, expense, AR tax | TD `an_exclusive_input_tax…`, `a_payable_expense_passes_its_tax…`, `an_output_tax_is_posted_to_the_tax_payable_account` |
| Closed period; cross-tenant code | TD `a_closed_period_blocks_posting_and_leaves_the_tax_in_draft`, `a_tax_code_of_another_tenant_cannot_be_used`; TC `codes_are_isolated_per_tenant` |

## §64 Multi-currency
| Requirement | Tests |
|---|---|
| Same currency resolves rate 1 (no setup); missing rate fails safely | FI `a_functional_invoice_is_unchanged_by_all_of_this`, `a_foreign_invoice_without_a_rate_fails_safely_and_leaves_nothing_behind` |
| Rate snapshot kept; master change never alters a posted document | FI `the_posted_invoice_keeps_its_own_rate_when_the_master_changes_later`; CR `a_rate_that_a_document_cites_is_withdrawn_not_deleted` |
| Journal balanced in functional currency (and per transaction currency) | FS `a_journal_that_does_not_balance_in_its_own_transaction_currency_is_refused_by_the_database`; XD `every_posted_journal_of_a_mixed_ledger_balances_in_functional_currency` |
| AP / AR foreign invoice; foreign payment / receipt | FI `a_vendor_invoice_in_usd…`, `a_customer_invoice_in_usd…`; FS `paying_a_usd_invoice…`, `receiving_a_usd_invoice…` |
| Realised FX gain / loss; partial settlement | FS `paying_a_usd_invoice_at_a_higher_rate_books_a_realised_loss`, `paying_at_a_lower_rate_books_a_realised_gain`, `partial_payments_release_the_invoice_in_proportion…`; CO `partial_payments_racing_release_exactly_what_fits…` |
| Cross-tenant rate rejected | CR `currencies_and_rates_of_another_tenant_are_invisible_and_unusable` |
| Functional currency not casually changed | CR `the_functional_currency_is_frozen_after_the_first_posting`, `…cannot_change_underneath_the_currencies_and_rates` |
| Exchange-rate update vs posting; settlement race with FX | CO `a_posting_racing_the_withdrawal_of_its_rate…`, `two_payments_racing_to_settle_the_same_usd_invoice…` |
| Cash/bank-to-GL reconciliation reads what the bank moved (functional) for foreign settlements | FS `the_cash_to_gl_reconciliation_uses_what_the_bank_moved_in_functional_currency` |
| Revaluation | NOT IMPLEMENTED (documented decision; no revaluation table, route, permission or menu exists) |

## §65–§66 Tenant, entitlement, regression
| Requirement | Evidence |
|---|---|
| Tenant A vs B on every OA4 route (list, filter, create, update, approve, post, reverse, dispose, run, export) | MX: every route of the module read from the router, another tenant's ids return 404 and the fingerprinted tables are unchanged |
| ACTIVE / READ_ONLY / SUSPENDED / DISABLED per module | MX entitlement sweep for `ACCOUNTING_BUDGET`, `ACCOUNTING_FIXED_ASSET`, `ACCOUNTING_TAX`, `ACCOUNTING_MULTI_CURRENCY` |
| Cross-domain | XD (foreign invoice line cannot be registered as an asset; asset reconciliation beside FX; mixed ledger balanced) |
| OA0–OA3 regression | full `Feature` + `Unit` suites and the Concurrency suite, see `OA4_STATUS.md` |
