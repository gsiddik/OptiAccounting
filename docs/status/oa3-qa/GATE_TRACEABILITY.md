# OA3 release gate — traceability

Each line points at tests that ran green in the final full run (`OA3_STATUS.md`). Test classes are under `backend/tests/`:
**IL** `Feature/Receivables/ArInvoiceLifecycleTest`, **IP** `…/ArInvoicePostingTest`, **RV** `…/ArRevenueTest`, **RC** `…/CustomerReceiptTest`,
**RA** `…/CustomerReceiptAccessTest`, **CN** `…/ArCreditNoteTest`, **AG** `…/ArAgingAndReconciliationTest`, **CU** `…/CustomerAndPaymentTermTest`,
**MX** `…/ArAccessMatrixTest`, **XE** `…/ArExportAndSummaryTest`, **CB** `…/ArCashBankIntegrationTest`, **CO** `Concurrency/ReceivablesConcurrencyTest`,
**SB** `Feature/SeederAndBootstrapTest`, **SEC** `Feature/SecurityTest`.

## §51 Critical AR tests
| # | Requirement | Tests |
|---|---|---|
| 1–4 | Draft / submitted / approved do not touch the GL; posting moves it once | IP `draft_submitted_and_approved_invoices_never_reach_the_ledger_and_posting_moves_it_exactly_once` |
| 5 | Correct AR subledger balance | AG `the_subledger_reconciles_to_the_control_account_and_a_difference_is_shown_not_hidden`; RC `partial_and_multiple_receipts_reduce_the_outstanding_amount` |
| 6 | Posted invoice is financially immutable | IP `a_posted_invoice_cannot_be_changed_by_the_service_or_the_database` |
| 7 | Duplicate posting prevented | CO `the_same_approved_invoice_posted_by_several_requests_at_once_is_posted_exactly_once`; IL `a_repeated_customer_reference_is_a_warning_and_never_a_refusal` (soft warning only) |
| 8 | Closed period blocks invoice posting | IP `a_posting_that_cannot_complete_leaves_no_trace_and_no_gap_in_the_numbers`, `a_reversal_into_a_closed_period_changes_nothing` |
| 9 | Cross-tenant account/dimension rejected | IL `input_is_validated_against_the_tenants_own_masters_and_accounts`; CU `the_database_refuses_inconsistent_terms_and_cross_tenant_customer_links`; RV `a_mapping_to_another_tenants_account_is_refused…` |
| 10–11 | Partial and multiple receipts | RC `partial_and_multiple_receipts_reduce_the_outstanding_amount` |
| 12 | Multi-invoice allocation | RC `one_receipt_settles_several_invoices_and_relieves_the_account_each_was_booked_to`, `allocation_can_be_proposed_by_the_backend_oldest_due_first` |
| 13–14 | Allocation limits (service and database) | RC `allocations_are_validated_against_the_receipt_the_invoice_and_the_customer`, `the_database_alone_refuses_over_allocation_and_a_partly_allocated_posting` |
| 15 | Concurrent receipts cannot over-allocate | CO `two_receipts_cannot_settle_the_same_remaining_invoice_balance`, `many_receipts_never_settle_an_invoice_for_more_than_it_is_worth`, `a_receipt_and_a_credit_note_cannot_both_take_the_same_outstanding` |
| 16 | Posted receipt affects the GL once | RC `only_posting_moves_the_ledger_and_it_moves_it_exactly_once`; CO `the_same_approved_receipt_posted_several_times_at_once_collects_once` |
| 17–18 | Receipt reversal restores outstanding; not repeatable | RC `reversal_restores_the_outstanding_amount_and_cannot_be_repeated`; CO `a_receipt_reversed_by_several_requests_releases_its_allocations_once` |
| 19–20 | Credit note reduces the receivable and does not mutate the invoice | CN `a_credit_note_posts_through_the_engine_reduces_the_receivable_and_leaves_the_invoice_untouched`, `a_posted_note_is_immutable_in_the_service_and_in_the_database` |
| 21 | Aging by due date / as-of date | AG `aging_places_each_invoice_by_days_past_due_on_the_as_of_date`, `aging_replays_receipts_and_their_reversals_by_posting_date`; CN `aging_and_the_reconciliation_follow_receipts_and_notes_by_posting_date` |
| 22 | AR subledger reconciles to the control account | AG `the_subledger_reconciles_to_the_control_account…`, `an_opening_balance_on_the_control_account_is_its_own_component`; SB demo books |

## §52 Revenue
RV `revenue_is_recognised_only_by_the_posting_of_the_invoice_through_the_engine` (unposted is not revenue, journal from `AR_INVOICE_RECOGNIZED`, reversal keeps history),
`the_revenue_account_comes_from_the_tenants_mapping_and_a_new_mapping_never_rewrites_posted_history`, `a_branch_mapping_wins_…`, `a_mapping_to_another_tenants_account_is_refused…`,
`without_a_rule_in_force_the_invoice_stays_unposted_and_the_ledger_untouched`; IP `lines_are_classified_by_account_role_or_the_customer_default…`, `missing_rules_or_mappings_stop_the_posting_with_a_clear_error`.

## §53 Customer advance
Not implemented (documented extension). Over-allocation is rejected, never a negative receivable: RC `allocations_are_validated_against_the_receipt_the_invoice_and_the_customer`
(`AR_ALLOCATION_EXCEEDS_RECEIPT`, `RECEIPT_NOT_FULLY_ALLOCATED`), `the_database_alone_refuses_over_allocation_and_a_partly_allocated_posting`; CO over-allocation races.

## §54–§60 Cross-cutting
| Brief | Evidence |
|---|---|
| §54 Tenant isolation | MX `another_tenants_records_are_not_found_on_every_route_and_nothing_of_theirs_changes` (every bound route, 12 tables fingerprinted); IL/RA/CN/CU/AG/XE per-resource isolation tests |
| §55 Entitlement / READ_ONLY | MX `a_read_only_module…`, `every_state_that_removes_the_module…` (SUSPENDED, DISABLED, expired, future), `an_overdue_subscription…`, `a_disabled_feature…`, `a_lost_or_read_only_ledger_stops_every_receivables_document…` (parent ACCOUNTING_CORE), `a_read_only_cash_bank_module_stops_a_receipt…` |
| §56 Permissions / SoD | MX `the_permission_of_every_route_is_pinned`, `each_atomic_permission_guards_exactly_its_own_routes`; RA `each_step_needs_its_own_permission`; IL/RA/CN `…segregation_of_duties…` (profile policy, never role names) |
| §57 AR↔GL | AG (deterministic invoice, partial receipts, credit note, reversal; a forced difference is reported, never adjusted); demo books MATCHED incl. opening balance |
| §58 OA0–OA2 regression | full `Unit,Feature` suite and the Concurrency suite (`OA3_STATUS.md`) |
| §59 Frontend | `npx vitest run`, `npm run lint`, `npm run build`; browser flows and screenshots in this folder |
| §60 Security | SEC (anonymous 401 on every route incl. exports, mass-assignment scan of every model, permission declared on every route); MX; CO; XE (export scope leakage, CSV formulas) |
| §29–30 Cash/bank integrity | CB (ledger, cash-to-GL reconciliation, statement matching); RC `a_cash_account_that_a_receipt_uses_keeps_its_gl_mapping`; MX receipt needs `ACCOUNTING_CASH_BANK` writable |
| §39–40, §46 Scope, audit, export | RA/IL/CN scope tests; RA `the_lifecycle_and_the_allocations_are_audited`; XE (CSV, formulas, bank numbers, caps, audit, scope) |
| §44 Dashboard | XE `receivables_figures_come_from_posted_documents_and_the_ledger`, `a_receivables_section_appears_only_when_the_user_may_open_its_list`, `receivables_figures_are_scoped_…` |
| Migration / seed | MX `the_backfill_migration…`; SB (demo seeding, idempotent); `migrate:fresh --seed` twice (`OA3_STATUS.md`) |
