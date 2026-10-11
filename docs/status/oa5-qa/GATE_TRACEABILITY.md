# OA5 release gate - traceability (independent verification)

Test classes under `backend/tests/`. Existing: **RI** `Feature/Reporting/ReportingIntegrityTest`, **FS** `…/FinancialStatementsTest`, **CF** `…/CashFlowStatementTest`,
**CR** `…/ComparativeReportTest`, **RD** `…/ReportDefinitionTest`, **RX** `…/ReportExportTest`, **AL** `…/AdvancedLedgerReportsTest`, **RAM** `…/ReportingAccessMatrixTest`,
**PC** `Feature/Closing/PeriodClosingTest`, **CRd** `…/CloseReadinessTest`, **CRI** `…/CloseReadinessIntegrationTest`, **LP** `…/LegacyPeriodRoutesTest`,
**CAM** `…/ClosingAccessMatrixTest`, **YE** `…/YearEndClosingTest`, **YR** `…/YearEndReversalTest`, **RE** `Feature/Reconciliation/ReconciliationEngineTest`,
**RXC** `…/ReconciliationExceptionTest`, **REX** `…/ReconciliationExportTest`, **RTF** `…/ReconciliationTaxFxTest`, **RAM2** `…/ReconciliationAccessMatrixTest`,
**CCC** `Concurrency/ClosingConcurrencyTest`, **RCC** `Concurrency/ReconciliationConcurrencyTest`.
New (this verification, `Feature/Oa5/` and `Concurrency/`): **EF** `EntitlementFamiliesTest`, **IS** `IsolationAndScopeTest`, **FC** `FinancialCycleTest`,
**RG** `ReconciliationCloseGateTest`, **AT** `AuditTrailTest`, **AB** `AbuseCasesTest`, **QC** `Oa5ReportConcurrencyTest`. Method names without the `test_` prefix.

## 70 Concurrency
| # | Item | Tests |
|---|---|---|
| 1 | Posting vs hard close | CCC `a_hard_close_waits_for_a_posting…`, `a_posting_that_starts_while_a_hard_close_is_in_flight…`, `closing_a_period_while_many_postings_race_it…`, `source_documents_that_lock_their_period_first…` |
| 2 | Posting vs soft close | QC `a_soft_close_racing_postings_of_an_ordinary_user…` (new) |
| 3 | Reopen vs posting | CCC `a_posting_that_waited_behind_a_reopen_to_open…`, `…to_soft_closed_is_refused…` |
| 4 | Two simultaneous close attempts | CCC `two_simultaneous_hard_closes_of_the_same_period_succeed_once` |
| 5 | Two year-end runs | CCC `two_simultaneous_year_end_runs_post_exactly_one_closing_journal`, `a_year_end_run_racing_the_hard_close…` |
| 6 | Reconciliation while postings occur | RCC `a_reconciliation_computed_while_documents_post…`, `simultaneous_runs…`, `simultaneous_resolve_requests…` |
| 7 | Report generation during concurrent posting | QC `every_report_computed_while_postings_commit_is_one_consistent_snapshot`, `a_report_neither_waits_for_nor_shows_a_posting_that_is_in_flight` (new) |

## 71 Security (release blockers)
| # | Item | Tests |
|---|---|---|
| 1 | Cross-tenant report access | RI `another_tenants_statements_definitions_and_ledger_never_cross`; RAM/CAM/RAM2 `another_tenants_records_are_not_found_on_every_route…`; IS `another_tenants_ids_in_every_filter_and_body…` (new) |
| 2 | Aggregate financial leakage | IS `a_user_restricted_to_one_branch…` (statements, totals, unmapped balances, overview) (new); RI `a_branch_scoped_user_sees_only_their_branch…`; RXC `a_narrower_data_scope_leaves_evidence_only…` |
| 3 | Data Scope bypass | IS `a_user_restricted_to_one_branch…` (names the other branch in every filter) (new); RI `the_other_branch_and_a_scope_that_owns_nothing…`; AL `…follows_dimension_filters_and_data_scope`; PC `a_narrower_data_scope_cannot_evaluate_close_or_reopen`; RE `a_narrower_data_scope_is_incomplete…` |
| 4 | Report export leakage | IS `a_user_restricted_to_one_branch…` (every export) (new); REX `an_export_to_a_caller_with_a_narrower_data_scope…`, `another_tenants_export…`; RX `exports_follow_the_module_entitlement`, `every_export_is_audited…` |
| 5 | Closing permission bypass | PC `each_closing_action_needs_its_own_permission`; LP `the_legacy_routes_need_the_new_permission…`, `the_legacy_close_cannot_bypass_the_readiness_checks`; EF `the_ledger_module_underneath_also_closes_the_closing_actions` (defect fixed); AB `a_closed_period_stays_closed…` (new) |
| 6 | Period lock bypass | PC `a_hard_close_stops_every_later_posting_in_the_service_and_in_the_database`, `the_database_lets_a_period_leave_closed_only_with_a_reopen_row…`; AB `a_closed_period_stays_closed_to_the_generic_journal_and_period_routes` (new); CCC |
| 7 | Unauthorized reopen | PC `each_closing_action_needs_its_own_permission`, `the_person_who_closed_a_period_may_be_barred…`; YR `reversing_a_closed_year_without_the_reopen_permission…`; AB `a_reason_that_shows_nothing_is_no_reason…` (new) |
| 8 | Duplicate year-end closing | YE `a_second_run_for_the_same_year_is_refused_and_the_database_backs_it_up`; CCC `two_simultaneous_year_end_runs…`; FC step 4 (new) |
| 9 | Report mapping injection | AB `unsafe_operands_and_mappings_are_refused…` (SQL text, arrays, unknown/heading lines, duplicate and wrong-type accounts, all-or-nothing) (new); RD `the_mapping_is_validated_as_a_whole…`, `ids_of_another_tenant…`, `the_database_rejects_a_cross_tenant_mapping…` |
| 10 | Unsafe calculated expression execution | NOT APPLICABLE as an interpreter: lines carry a declarative operand list (sign + or -, code of another line), no formula parser exists. AB `a_definition_is_data…` (hostile text is stored and shown as text, CSV cells neutralised), `unsafe_operands_and_mappings…` (signs, self-reference, cycle); RD `a_line_list_with_a_missing_parent_a_duplicate_code_or_a_loop_is_refused_whole`; RX `text_that_a_spreadsheet_could_execute_is_neutralised` |
| 11 | Financial report manipulation | RD `a_definition_is_authored_validated_published_and_then_immutable`, `the_database_refuses_to_change_a_published_definition…`, `every_change_to_a_definition_is_audited…`; FS `an_account_the_definition_leaves_out_is_listed…`; AT (new); FC (statements equal the ledger at every step) (new) |
| 12 | Reconciliation false-match override | RXC `explaining_and_assigning_never_change_the_calculated_status`, `resolving_is_refused_while_the_recomputation_still_differs…`; RG both tests (forged body, tolerance edge, resolved exception that comes back) (new); CRd `an_incomplete_reconciliation_never_passes_a_blocking_check` |

## 77 Critical reporting tests
| # | Item | Tests |
|---|---|---|
| 1-3 | Draft / submitted / approved do not affect statements | RI `a_journal_that_is_not_posted_moves_no_report_at_any_stage_before_posting` |
| 4 | Posted journal affects statements once | RI `a_posted_journal_moves_every_report_by_exactly_its_amount_once` |
| 5 | Trial balance reconciles to GL | RI `the_trial_balance_ties_to_the_general_ledger_account_by_account_and_in_total`; AL `the_trial_balance_of_a_fiscal_year_reconciles…` |
| 6 | Balance sheet from posted data | FS `the_balance_sheet_is_built_from_posted_lines…`; RI `the_balance_sheet_balances_and_equals_the_account_balances_on_every_month_end` |
| 7 | Assets = liabilities + equity | FS (same); FC (before and after year end, reversal, next year); QC (under concurrent posting) (new) |
| 8 | P&L period boundaries | FS `the_profit_and_loss_of_a_range_counts_only_the_activity_inside_it`, `the_first_and_the_last_day_of_the_range_are_included…` |
| 9 | Prior-period activity excluded | FS (same), `a_range_across_years_adds_both_years…`, `the_opening_balance_journal_is_not_activity`, `the_closing_journals_of_a_year_never_erase_its_profit_and_loss` |
| 10 | Comparative calculations | CR `a_profit_and_loss_period_is_compared_with_the_period_before`, `the_same_period_a_year_earlier…`, `balance_sheets_are_compared_by_date…`, `a_cash_flow_is_compared…` |
| 11 | Budget comparison reuses Budget Actual | CR `the_budget_comparison_reuses_the_budget_versus_actual_figures`; FC `budget_versus_actual_and_the_budget_comparison_do_not_count_the_year_end_closing…` (new; fails when the closing exclusion is removed) |
| 12 | Cash flow opening + movement = closing | CF `the_indirect_cash_flow_reconciles_to_the_cash_and_bank_accounts_of_the_ledger`, `the_opening_balances_are_the_opening_cash…`; QC (new) |
| 13-14 | Historical functional amounts; FX rate does not rewrite | RI `a_foreign_currency_journal_is_reported_at_its_posted_functional_amount_and_a_later_rate_changes_nothing` |
| 15 | Mapping is tenant-safe | RD `ids_of_another_tenant_are_not_found_and_nothing_of_theirs_can_be_mapped`, `the_database_rejects_a_cross_tenant_mapping…`; IS (new) |
| 16 | Data Scope on every report | RI `a_branch_scoped_user_sees_only_their_branch_in_every_report_and_none_claims_to_be_complete`; CF/FS partial-scope tests; IS (comparative, drilldown, definition checks too) (new) |
| 17 | Export equals authorized scope | RX five `…export_equals_…_on_screen` tests; AL `the_csv_exports_cover_exactly_the_filtered_screen`; IS (scoped exports) (new) |

## 78 Critical closing tests
| # | Item | Tests |
|---|---|---|
| 1 | Pre-Close repeatable | PC `pre_close_is_repeatable_idempotent_and_changes_no_state` |
| 2 | Reconciliation mismatch blocks close | CRd `a_reconciliation_mismatch_blocks_by_default…`; PC `a_reconciliation_mismatch_blocks_a_hard_close_when_the_policy_blocks…`; CRI; RG (new) |
| 3 | Pending journal blocks close | CRd `a_submitted_or_approved_journal_blocks_and_a_draft_journal_only_warns` |
| 4 | Hard Close prevents posting | PC `a_hard_close_stops_every_later_posting_in_the_service_and_in_the_database`; AB (generic routes) (new) |
| 5 | Concurrent posting cannot slip through | CCC the three hard-close vs posting tests |
| 6 | Unauthorized user cannot close | PC `each_closing_action_needs_its_own_permission`; CAM `each_atomic_permission_guards_exactly_its_own_routes`; EF (new) |
| 7 | Unauthorized user cannot reopen | PC (same), `the_person_who_closed_a_period_may_be_barred_from_reopening_it`; LP |
| 8 | Reopen requires reason/audit | PC `a_reopen_needs_a_reason_and_writes_history_and_audit`; AT (reason and actor on every sensitive action) (new); AB (new) |
| 9 | Reopen preserves history | PC `reopen_preserves_the_whole_history…`, `the_closing_history_is_append_only_in_the_database` |
| 10 | Closed-period journals immutable | PC `closed_period_journals_stay_immutable_before_after_a_close_and_after_a_reopen` |
| 11 | Two simultaneous close attempts | CCC `two_simultaneous_hard_closes…`; PC `a_second_hard_close_of_the_same_period_is_refused…` |
| 12 | READ_ONLY cannot close/reopen | PC `a_read_only_module_cannot_close_or_reopen_but_can_still_read_readiness`; CAM; EF (valid bodies, every state) (new) |

## 79 Critical year-end tests
| # | Item | Tests |
|---|---|---|
| 1 | Uses the Posting Engine | YE `a_profit_year_is_closed_into_retained_earnings_through_the_posting_engine` |
| 2 | No direct balance mutation | YE (same: only the closing journal's lines were added); FC (new) |
| 3 | Revenue/expense closing correct | YE `a_profit_year…`, `a_loss_year_debits_retained_earnings`, `a_contra_account_is_closed_against_its_own_side`; FC (real documents) (new) |
| 4 | Retained earnings mapping-driven | YE `the_retained_earnings_account_is_whatever_the_tenant_mapped…`, `an_unmapped_role_and_an_unpublished_rule…` |
| 5 | Closing journal balanced | YE `a_rule_that_does_not_produce_a_balanced_journal_is_caught_before_posting`, `a_profit_year…` |
| 6 | Closing journal immutable | YE `the_closing_journal_is_immutable_like_any_posted_journal` |
| 7 | Duplicate run prevented | YE `a_second_run_for_the_same_year…`; CCC `two_simultaneous_year_end_runs…`; FC (new) |
| 8 | Closed period/year policies | YE `a_future_period_or_a_year_that_is_not_open…`, `a_closed_last_period_stops_the_run…`, `a_soft_closed_last_period…`; YR `reversing_a_closed_year_reopens_the_last_period…` |
| 9 | Reopen/correction traceable | YR `a_reversal_restores_the_balances…`, `after_a_reversal_a_new_run_is_possible…`, `a_posted_year_end_closing_blocks_the_reopen…`; FC reverse and rerun (new) |
| 10 | New fiscal year balance semantics | YE `the_next_fiscal_year_starts_with_zero_temporary_accounts_and_carries_the_rest`, `a_later_year_end_sweeps…`; FC step 7 (new) |

## 80 Critical reconciliation tests
| # | Item | Tests |
|---|---|---|
| 1 | AP subledger = AP GL | RE `ap_subledger_equals_the_ap_control_account…`; FC (real documents) (new) |
| 2 | AR subledger = AR GL | RE `ar_subledger_equals_the_ar_control_account…`; FC (new) |
| 3 | Cash/Bank book = mapped GL | RE `cash_bank_documents_equal_their_ledger_lines…`, `a_document_that_disagrees_with_its_ledger_lines_is_a_cash_bank_mismatch`; FC (new) |
| 4-5 | Asset register and accumulated depreciation = GL | RE `the_asset_register_and_its_accumulated_depreciation_equal_the_ledger…` (COST and ACCUMULATED_DEPRECIATION components); FC (new) |
| 6 | Tax reconciliation | RTF `recorded_tax_equals_the_tax_control_accounts…`, `a_record_or_ledger_line_that_breaks_equality_is_a_tax_mismatch` |
| 7 | FX reconciliation | RTF `realised_fx_and_the_open_foreign_balances_equal_the_ledger…`, `a_difference_between_a_settlement_and_its_journal_is_an_fx_mismatch` |
| 8 | Mismatch is MISMATCH | RE stray-line tests (AP, AR, asset), RTF mismatch tests, RE `the_tolerance_comes_from_the_closing_settings_and_is_reported`; RG tolerance edge (new) |
| 9 | Manual action never turns a mismatch into MATCHED | RXC `explaining_and_assigning_never_change_the_calculated_status`, `resolving_is_refused_while…`; RG (forged status in the body, close stays blocked, resolved exception that returns) (new) |

## 81 Tenant isolation
| Resource / operation | Tests |
|---|---|
| Financial reports, drill-down, exports | RI `another_tenants_statements…`; REX `another_tenants_export…`; IS `another_tenants_ids_in_every_filter_and_body…` (read routes of every family with foreign ids and every dimension filter) (new) |
| Report definitions, report mappings (create/update) | RD `ids_of_another_tenant…`, `the_database_rejects_a_cross_tenant_mapping…`; RAM; IS (bodies naming foreign accounts and lines; row hashes unchanged) (new) |
| Reconciliations, exceptions (run, assign) | RAM2, RXC `exceptions_and_runs_of_another_tenant…`; IS (new) |
| Closing status, history (close, reopen) | PC `every_closing_route_answers_not_found_for_another_tenants_period`; CAM; IS `the_closing_and_reconciliation_views…`, `…another_tenants_ids…` (hard-close, reopen) (new) |
| Year-end journals (run, reverse) | YR `another_tenants_fiscal_year_is_not_found_on_every_year_end_route`; IS (new) |
| Database level | IS `the_database_refuses_closing_and_reconciliation_rows_that_point_across_tenants` (new) |

## 82 Entitlement / READ_ONLY
| Item | Tests |
|---|---|
| ACTIVE, READ_ONLY, SUSPENDED, DISABLED, expired, future-effective on every family | CAM/RAM/RAM2 (`ModuleAccessMatrix`: `a_read_only_module_keeps_its_reads_open…`, `every_state_that_removes_the_module_closes_all_of_its_routes…`); EF `every_family_keeps_reading_and_refuses_every_valid_mutation_in_each_entitlement_state…` (about 30 reads and 24 valid-body mutations per state) (new) |
| READ_ONLY allows reports/exports | EF (reads incl. exports); RX `exports_follow_the_module_entitlement` |
| READ_ONLY denies mapping, closing policy, close, reopen, year-end, other mutations | EF (every mutation, valid bodies, refusal code MODULE_READ_ONLY); PC `a_read_only_module_cannot_close_or_reopen…`; YR `a_read_only_module_keeps_the_reads_and_refuses_run_reverse_and_defaults`; overdue subscription: EF `an_overdue_subscription_…` |
| Module-aware reports honor the underlying module | EF `each_reconciliation_needs_its_own_module…`, `the_ledger_module_underneath_also_closes_the_closing_actions`; RAM2 `the_views_work_for_a_read_only_subledger_module…`; RE `a_type_whose_module_is_not_entitled_is_not_applicable…`; CR `the_budget_needs_the_budget_module…`; CRd `a_module_the_tenant_is_not_entitled_to_is_skipped…` |
| Spec 72: tenants without a writable reporting module keep working | EF `the_transactional_core_keeps_working_without_a_writable_reporting_module`; LP `a_tenant_without_the_reporting_module_keeps_the_oa1_behaviour_exactly`, `a_tenant_whose_reporting_module_is_suspended…` |

## Findings and limits
- Defect fixed: closing and year-end actions required only a writable ACCOUNTING_REPORTING module; a tenant that lost a writable ACCOUNTING_CORE could still close periods of the ledger. `ClosingAccess::authorize` now also requires a writable ACCOUNTING_CORE (EF test).
- OA1 (not changed): `general-ledger/export` with a foreign `account_id` answers a header-only CSV (200) while the JSON report answers 404/422; no data of another tenant is exposed.
- OA0 (not changed): the doc comment of `ActorAuthority::assertModuleWritable` claims a parent-module check that `EffectiveAccess` does not perform.
- Not run: browser/UI checks (frontend workers), full-suite regression (release gate), performance benchmark.
