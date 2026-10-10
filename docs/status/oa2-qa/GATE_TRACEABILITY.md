# OA2 release gate — traceability

Each line points at tests that ran green in the final full run (`OA2_STATUS.md`). Test classes are under `backend/tests/`:
**AL** `Feature/Payables/ApInvoiceLifecycleTest`, **AP** `…/ApInvoicePostingTest`, **VP** `…/VendorPaymentTest`, **VA** `…/VendorPaymentAccessTest`,
**AG** `…/ApAgingAndReconciliationTest`, **VN** `…/VendorAndPaymentTermTest`, **MX** `…/OperationalAccessMatrixTest`,
**EP** `Feature/Expense/ExpensePostingTest`, **EA** `…/ExpenseAccessTest`, **EC** `…/ExpenseCategoryTest`,
**CB** `Feature/CashBank/CashBankAccountTest`, **CT** `…/CashTransactionTest`, **BR** `…/BankReconciliationTest`,
**XE** `Feature/Operational/OperationalExportTest`, **XR** `…/OperationalRulesAndAuditTest`, **XS** `…/OperationalSummaryTest`,
**CO** `Concurrency/OperationalConcurrencyTest`, **SB** `Feature/SeederAndBootstrapTest`, **SEC** `Feature/SecurityTest`.

## §57 Critical AP tests
| # | Requirement | Tests |
|---|---|---|
| 1–4 | Draft / submitted / approved do not touch the GL; posting moves it once | AP `draft_submitted_and_approved_invoices_never_reach_the_ledger_and_posting_moves_it_exactly_once` |
| 5 | Correct AP subledger balance | AG `the_subledger_reconciles_to_the_control_account_and_a_difference_is_shown_not_hidden`; VP `partial_and_multiple_payments_reduce_the_outstanding_amount` |
| 6 | Posted invoice cannot be financially edited | AP `a_posted_invoice_cannot_be_changed_by_the_service_or_the_database` |
| 7 | Duplicate posting prevented | CO `the_same_approved_invoice_posted_by_several_requests_at_once_is_posted_exactly_once`; AL `duplicate_vendor_invoice_numbers_are_refused_unless_an_authorized_user_overrides_them` |
| 8 | CLOSED period cannot post | AP `a_posting_that_cannot_complete_leaves_no_trace_and_no_gap_in_the_numbers`, `a_reversal_into_a_closed_period_changes_nothing` |
| 9 | Cross-tenant account/dimension | AL `input_is_validated_against_the_tenants_own_masters_and_accounts`; VN `the_database_refuses_inconsistent_terms_and_cross_tenant_vendor_links` |
| 10–11 | Partial and multiple payments | VP `partial_and_multiple_payments_reduce_the_outstanding_amount` |
| 12 | Multi-invoice allocation | VP `one_payment_settles_several_invoices_and_relieves_the_account_each_was_booked_to`, `allocation_can_be_proposed_by_the_backend_oldest_due_first` |
| 13–14 | Allocation limits (service and database) | VP `allocations_are_validated_against_the_payment_the_invoice_and_the_vendor`, `the_database_alone_refuses_over_allocation_and_a_partly_allocated_posting` |
| 15 | Concurrent payments cannot over-allocate | CO `two_payments_cannot_settle_the_same_remaining_invoice_balance`, `many_payments_never_settle_an_invoice_for_more_than_it_is_worth`, `reversing_an_invoice_while_a_payment_is_being_posted…` |
| 16 | Posted payment affects the GL once | VP `only_posting_moves_the_ledger_and_it_moves_it_exactly_once`; CO `the_same_approved_payment_posted_several_times_at_once_pays_once` |
| 17–18 | Payment reversal restores outstanding; not repeatable | VP `reversal_restores_the_outstanding_amount_and_cannot_be_repeated`; CO `a_payment_reversed_by_several_requests_releases_its_allocations_once` |
| 19 | Aging by due date / as-of date | AG `aging_places_each_invoice_by_days_past_due_on_the_as_of_date`, `aging_replays_payments_and_their_reversals_by_posting_date` |
| 20 | AP subledger reconciles to the control account | AG `the_subledger_reconciles_to_the_control_account…`, `an_opening_balance_on_the_control_account_is_its_own_component`; SB `the_demo_books_carry_payables_expenses…` |

## §58 Critical expense tests
Draft does not affect the GL, both paths post through the Core, immutability, reversal keeps history: EP `a_payable_expense_reaches_the_ledger_only_when_posted_and_creates_one_payable`,
`a_directly_paid_expense_credits_the_chosen_account_and_creates_no_payable`, `a_posted_expense_and_its_payable_cannot_be_changed_by_the_service_or_the_database`,
`reversal_uses_the_shared_mechanism_for_both_paths_and_keeps_history`. Period lock: EP `a_closed_period_stops_the_posting…`, `a_reversal_into_a_closed_period_changes_nothing`.
Permission/SoD: EA `each_step_needs_its_own_permission`, `approval_is_required_by_policy_and_segregation_follows_the_profile_not_role_names`.
Cross-tenant accounts: EC `a_category_classifies_only_to_a_usable_account_or_an_allowed_role`, EP `drafts_are_validated…`, EA `another_tenant_sees_and_changes_nothing`.

## §59 Critical cash/bank tests
Mapping to a valid tenant GL account and cross-tenant refusal: CB `the_gl_mapping_must_be_a_usable_asset_account_of_the_same_tenant`. Posts once, closed period, immutability, reversal:
CT `a_payment_reaches_the_ledger_only_when_posted_and_moves_it_exactly_once`, `a_closed_period_stops_the_posting_without_trace_or_gap_in_the_numbers`,
`a_posted_transaction_is_immutable_in_the_service_and_the_database`, `reversal_uses_the_shared_mechanism_and_keeps_history`; CO `cash_transactions_post_once_and_take_unique_numbers_in_their_own_sequences`.
Book balance from posted GL: CB `the_book_balance_is_read_from_posted_journals_only`. Reconciliation never alters the GL: BR `a_statement_is_prepared_matched_and_completed_without_touching_the_ledger`,
`a_difference_is_reported_and_recorded_never_adjusted`; CO `completing_a_reconciliation_races_matching…`. Bank data: CB `the_full_bank_number_is_never_stored_returned_or_audited`, XE `filters_of_the_list_apply_to_its_export_and_formulas_and_bank_numbers_are_neutralised`.

## §60–§64 Cross-cutting
| Brief | Evidence |
|---|---|
| §60 Tenant isolation | MX `another_tenants_records_are_not_found_on_every_route_and_nothing_of_theirs_changes` (every bound route, 16 tables fingerprinted); AL/VA/EA/CB/BR/XE/XS per-resource isolation tests |
| §61 Entitlement / READ_ONLY | MX `a_read_only_module…`, `every_state_that_removes_a_module…` (SUSPENDED, DISABLED, expired, future), `an_overdue_subscription…`, `a_disabled_feature…`, `a_lost_or_read_only_ledger_stops_every_document…` (parent ACCOUNTING_CORE) |
| Permissions / data scope / SoD | MX `the_permission_of_every_route_is_pinned`, `each_atomic_permission_guards_exactly_its_own_routes`; AL/VA/EA/CT `…segregation_of_duties…`, `…a_branch_scoped_user…`; XS/XE scope tests |
| §63 Frontend | `npx vitest run` (354 tests, includes READ_ONLY navigation, invoice workflow, allocation, aging filters, expense, cash, reconciliation states), `npm run build`; browser flows and screenshots in this folder |
| §64 Security | SEC (anonymous 401 on every route incl. exports, mass-assignment scan of every model, permission declared on every route), MX, CO, XE |
| Migration / seed | MX `the_backfill_migration…`; SB (demo seeding, idempotent); `migrate:fresh --seed` twice (`OA2_STATUS.md`) |
