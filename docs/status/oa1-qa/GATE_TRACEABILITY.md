# OA1 release gate — traceability

Each line points at tests that ran green in the final full run (`OA1_STATUS.md`). Class names are under `backend/tests/`:
**JL** `Feature/Accounting/JournalLifecycleTest`, **OR** `…/OpeningBalanceAndReversalTest`, **LR** `…/LedgerReportsTest`,
**PE** `…/PostingRulesAndEventsTest`, **PC** `…/ProfileAndCalendarTest`, **CA** `…/ChartOfAccountsTest`, **AM** `…/AccessMatrixTest`,
**SA** `…/ScopeAuditAndPayloadTest`, **CC** `Concurrency/AccountingConcurrencyTest`.

## §55 Critical financial tests
| # | Requirement | Tests |
|---|---|---|
| 1 | Unbalanced journal cannot post | JL `an_unbalanced_draft_can_never_post_not_even_without_approval`, `the_database_alone_refuses_an_unbalanced_or_invalid_posting`; OR `…changes_no_ledger_figure_until_posted` |
| 2 | Balanced journal posts atomically | JL `full_approval_flow_posts_with_a_number_and_a_complete_trail`, `closed_and_soft_closed_periods_reject_posting_atomically`; CC `a_posting_that_fails_gives_its_number_back…` |
| 3–5 | Draft / submitted / approved do not affect GL | LR `only_posted_journals_reach_the_ledger` (also cancelled; figures unchanged, posting moves them by exactly its amount) |
| 6 | Posted journal affects GL exactly once | CC `the_same_journal_posted_by_several_requests_at_once_is_posted_exactly_once`; LR `the_ledger_equals_the_posted_journal_lines` |
| 7–8 | Posted journal cannot be edited or deleted | JL `posted_journals_are_immutable_in_the_service_and_in_the_database` (API 409/405 and 9 raw-SQL tampering attempts refused by triggers) |
| 9 | Closed period rejects posting | JL `closed_and_soft_closed_periods_reject_posting_atomically`; PC `a_closed_period_cannot_be_changed_at_the_database_level`; OR `a_reversal_into_a_closed_period_fails_atomically` |
| 10 | Concurrent close/post cannot bypass the lock | CC `a_close_waits_for_a_posting…`, `a_posting_that_starts_while_a_close_is_in_flight…`, `closing_a_period_while_many_postings_race_it…` |
| 11 | Inactive / non-postable account rejected | JL `accounts_must_be_active_tenant_owned_posting_accounts`; CA `a_header_with_children_cannot_become_a_posting_account` |
| 12 | Cross-tenant account unusable | JL `accounts_must_be_active_tenant_owned_posting_accounts`; CA `cross_tenant_account_ids_are_not_found`; AM `another_tenants_records_are_not_found_on_every_route…` |
| 13 | Cross-tenant dimension unusable | JL `dimensions_must_belong_to_the_tenant_and_be_active`; CA `cost_centers_are_tenant_owned_and_tied_to_the_tenants_own_organization` |
| 14 | Duplicate posting prevented | CC `…posted_exactly_once`, `the_same_business_fact_arriving_twice_at_once_posts_one_journal`; PE `the_same_business_fact_posts_once_and_a_changed_fact_is_a_conflict` |
| 15–16 | Reversal is the opposite balanced journal; original unchanged | OR `a_reversal_is_a_new_balanced_journal_with_swapped_sides_and_the_original_stays_posted`; LR `a_reversal_cancels_the_original_in_the_ledger` |
| 17 | Duplicate reversal prevented | OR `a_journal_is_reversed_at_most_once…`, `…the_database_allows_one_reversal_only`; CC `concurrent_reversals_of_one_journal_create_exactly_one_reversal` |
| 18 | Opening balance posts through the core | OR `posting_initializes_the_ledger_once_and_sets_the_cutover_date`, `the_generic_journal_workflow_cannot_touch_the_opening_journal` |
| 19 | GL only from posted lines | LR `only_posted_journals_reach_the_ledger`, `the_ledger_equals_the_posted_journal_lines` |
| 20 | Trial balance agrees with the ledger | LR `the_trial_balance_reconciles_and_ending_equals_opening_plus_movement`, `an_empty_ledger_reconciles_trivially` |

## §56–§63 Cross-cutting
| Brief | Evidence |
|---|---|
| §56 Tenant isolation (all resources, list/filter/read/write/state change/export) | AM `another_tenants_records_are_not_found_on_every_route_and_nothing_of_theirs_changes` (every route with an id, row hashes of 10 tables unchanged); list/search/export isolation in CA, JL, LR, PE, OR, PC; OA0 `TenantIsolationTest` |
| §57 Entitlement (ACTIVE, READ_ONLY, SUSPENDED, DISABLED, expired, future) | AM `a_read_only_module…`, `an_overdue_subscription…`, `every_state_that_removes_the_module_closes_every_route…`, `a_disabled_feature_closes_only_its_own_routes` (all 60 routes) |
| §58 Permissions / SoD | AM `each_atomic_permission_guards_exactly_its_own_routes`, `the_permission_of_every_route_is_pinned`; JL `segregation_of_duties_follows_the_profile_policy_not_role_names`, `journal_permissions_are_checked_per_action`; OR `opening_balance_permissions_scope_segregation_and_tenancy` |
| §59 GL / TB reconciliation | LR (deterministic posted set; ledger debit = credit; TB from the same lines) |
| §60 OA0 regression | full suite includes every OA0 and OA0-N test (RBAC, scope, entitlement, capacity, audit, adapter) |
| §61 Frontend | vitest 70 tests (capability gating, READ_ONLY, validation, states), build, real-browser E2E 17/17, screenshots in this folder |
| §62 Performance | `Feature/Accounting/QueryBudgetTest` (7 read endpoints, same query count for 4 and 40 rows); indexes follow the ledger query patterns; volume benchmark NOT RUN |
| §63 Security | `SecurityTest` (mass assignment over every model, anonymous 401 on every route), SA `privileged_fields_in_accounting_payloads_are_ignored`, `malformed_input_is_a_client_error…`, `search_input_is_matched_literally…`, LR `exports_are_csv_audited_and_safe_against_spreadsheet_formulas`, SA audit tests |

## §64 Checklist
OA0 stable ✔ · ACCOUNTING_CORE entitlement ✔ · profile ✔ · readiness ✔ · functional currency foundation ✔ (`profile_currency_is_frozen_after_the_first_posting_lock`) ·
non-calendar fiscal year ✔ (`a_non_calendar_fiscal_year_gets_monthly_periods`) · period lifecycle ✔ · closed period blocks posting ✔ · COA hierarchy ✔ ·
circular hierarchy prevented ✔ (`cycles_and_self_parents_are_refused_by_the_service_and_the_database`) · used accounts keep history ✔ (`unused_accounts_can_be_deleted_but_used_ones_keep_their_meaning`) ·
cost center / dimensions ✔ · manual journal ✔ · workflow ✔ · SoD ✔ · concurrency-safe numbering ✔ (CC `parallel_postings_receive_unique_gapless_numbers`) ·
double entry ✔ · posted journal immutable ✔ · central posting engine ✔ (PE) · posting rules ✔ · account mapping ✔ · reversal ✔ · duplicate reversal prevented ✔ ·
opening balance ✔ · GL only from posted lines ✔ · trial balance reconciles ✔ · tenant isolation ✔.
