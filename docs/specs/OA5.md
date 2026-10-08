# OPTIACCOUNTING OA5 — FINANCIAL REPORTING, CLOSING & RECONCILIATION Implement: OA5 — Financial Reporting, Closing & Reconciliation Target: OPTIACCOUNTING OA5 STATUS: COMMIT READY

1. SOURCE OF TRUTH

MASTER and OA0–OA4 are completed and committed. Treat them as stable baseline. Repository state is authoritative. Before implementation:
1. Read root CLAUDE.md.
2. Read only OA5-relevant architecture docs.
3. Read docs/status/OA4_STATUS.md.
4. Read docs/status/OA5_STATUS.md if it exists.
5. Inspect only existing GL, Trial Balance, Period, COA, dimensions, subledger reconciliation, Fixed Asset, Tax, Currency, Posting Engine, and reporting code required by the current batch. Do NOT reconstruct previous phases from chat. Do NOT redesign or recreate OA0–OA4. Use additive migrations only. Modify committed baseline only for:
- genuine bugs; or
- minimal backward-compatible extensions required by OA5.

2. PHASE OBJECTIVE

Build authoritative financial reporting, accounting period closing, and reconciliation controls. Target flow: Posted Journal Lines ↓ General Ledger ↓ Trial Balance ↓ Report Mapping ↓ Financial Statements Subledgers / Control Sources ↓ Reconciliation Engine ↓ Close Readiness ↓ Period Close OA5 must NOT introduce another financial truth. POSTED journal lines remain authoritative.

3. STRICT PHASE BOUNDARY

IN SCOPE: REPORTING
- Advanced Trial Balance
- General Ledger reporting
- Account Balance
- Statement of Financial Position / Balance Sheet
- Profit & Loss / Income Statement
- Cash Flow Statement
- Comparative reporting
- Dimension-based reporting
- Report Mapping
- Report configuration
- Export foundation CLOSING
- Close Readiness
- Pre-Close
- Soft Close
- Hard Close
- Controlled Reopen
- Closing checklist
- Closing journal foundation
- Year-end closing foundation
- Retained earnings closing foundation RECONCILIATION
- AP ↔ GL
- AR ↔ GL
- Cash/Bank ↔ GL
- Fixed Asset ↔ GL
- Tax ↔ GL where meaningful
- Multi-Currency/FX reconciliation where meaningful
- Reconciliation dashboard
- Reconciliation exceptions CROSS-CUTTING
- permissions
- entitlements
- data scope
- audit
- React UI
- tests
- concurrency
- regression OUT OF SCOPE:
- Consolidated financial statements across legal entities
- Intercompany elimination
- Full statutory filing
- Automated regulatory reporting
- Advanced tax filing
- Complex consolidation currency translation
- BI/Data Warehouse
- MongoDB analytics
- Forecasting/AI
- Management Accounting analytics
- OptiFleet integration
- External accounting adapters
- Public financial-report API integrations Do not start OA6.

4. ENTITLEMENT

Primary module: ACCOUNTING_REPORTING Dependency: ACCOUNTING_REPORTING → ACCOUNTING_CORE Reuse other module entitlements when report/reconciliation depends on: ACCOUNTING_AP ACCOUNTING_AR ACCOUNTING_CASH_BANK ACCOUNTING_FIXED_ASSET ACCOUNTING_TAX ACCOUNTING_MULTI_CURRENCY Do not expose unavailable subledger reports merely because Reporting is active. READ_ONLY should normally allow authorized report viewing/export, but deny configuration, close, reopen, and financial mutations. Backend capability resolver remains authoritative.

5. REPORTING PRINCIPLE

Financial reports derive from authoritative POSTED accounting data. Never use: DRAFT SUBMITTED APPROVED but unposted journals as financial actuals. Do not maintain independently editable report balances. Do not copy financial totals into report tables as new truth. If summaries/materialized projections are later required for performance, they must be rebuildable and reconcilable to posted journals.

6. REPORTING AS-OF SEMANTICS

Every financial report must have explicit temporal semantics. Support where appropriate: as_of_date or: from_date to_date Do not use current date implicitly when historical reporting is requested. Historical report results must be reproducible from historical posted data and configuration/version snapshots where required.

7. ADVANCED TRIAL BALANCE

Extend OA1 Trial Balance. Support: opening balance period debit period credit ending balance Filters: fiscal year accounting period date range account account type branch business unit cost center currency where applicable Only POSTED journals count. Respect normal balance semantics.

8. TRIAL BALANCE INVARIANT

For complete tenant accounting scope: Total Debit = Total Credit Trial Balance must reconcile to GL. Do not mask imbalance. Any imbalance is release-critical.

9. GENERAL LEDGER REPORT

Provide production-ready GL reporting. Support: account date range opening balance journal number source description debit credit running balance dimensions currency information where applicable source reference Filters must be server-side. Respect tenant and Data Scope.

10. ACCOUNT BALANCE REPORT

Provide account balance reporting for: single account account range/group account hierarchy period date dimensions Balance derives from posted journal lines. Do not persist manually editable account balances.

11. REPORT MAPPING PRINCIPLE

Financial statement structure must NOT depend on hardcoded account-code prefixes. Do NOT assume: 1xxx = Asset 2xxx = Liability 4xxx = Revenue Use configurable: COA → Report Mapping → Report Line

12. REPORT DEFINITION

Implement configurable report definition foundation. Concepts: report definition report section report line display order line type account mapping calculation/subtotal behavior sign/presentation rule effective/version metadata where justified Keep calculation rules declarative and safe. Do not implement arbitrary executable formulas/eval.

13. REPORT LINE TYPES

Support only required safe types such as: HEADER ACCOUNT ACCOUNT_GROUP SUBTOTAL CALCULATED_TOTAL Avoid creating a general programming language inside report configuration. Financial calculations must remain deterministic and testable.

14. REPORT MAPPING

Map tenant COA accounts/account groups to financial report lines. Support many accounts → one report line where appropriate. Prevent: cross-tenant mapping duplicate conflicting mapping where policy disallows it mapping inactive/invalid accounts Historical reports must not become unintelligible after mapping changes. Use version/effective-date strategy if necessary.

15. STATEMENT OF FINANCIAL POSITION

Implement Statement of Financial Position / Balance Sheet. At minimum: Assets Liabilities Equity Use configurable Report Mapping. Core invariant: Assets = Liabilities + Equity according to accounting/reporting configuration. If imbalance exists: show/report it clearly. Never silently insert a balancing value.

16. BALANCE SHEET TEMPORAL SEMANTICS

Balance Sheet is an AS-OF report. Use cumulative posted activity up to as_of_date according to accounting balance semantics. Do not treat it as a simple period-movement report.

17. PROFIT & LOSS

Implement Profit & Loss / Income Statement. At minimum support configurable sections such as: Revenue Cost/Expense Operating Result Other Income/Expense Net Profit/Loss Actual structure must come from Report Definition/Mapping. Do not hardcode one universal chart presentation.

18. P&L TEMPORAL SEMANTICS

P&L is a PERIOD report. Support: from_date to_date fiscal period year-to-date where appropriate Do not accidentally include historical pre-period revenue/expense balances.

19. COMPARATIVE REPORTING

Support comparison such as: Current Period vs Previous Period Current Year vs Prior Year Actual vs Budget where ACCOUNTING_BUDGET is available Provide: current comparison variance variance % Handle zero denominator safely. Do not duplicate Budget actual calculations.

20. BUDGET COMPARISON

Reuse OA4 Budget vs Actual. Financial reporting may consume its service/output. Do not create a second Budget Actual engine. Actual continues to derive from POSTED journals.

21. CASH FLOW STATEMENT

Implement Cash Flow Statement foundation. Preferred initial approach: Indirect Method unless repository architecture already defines otherwise. Typical conceptual structure: Net Profit ± Non-Cash Adjustments ± Working Capital Changes = Operating Cash Flow Investing Cash Flow Financing Cash Flow Net Change in Cash Do not hardcode account numbers.

22. CASH FLOW MAPPING

Cash Flow classification must be configurable. Support semantic categories such as: OPERATING INVESTING FINANCING NON_CASH UNCLASSIFIED Use account/report mapping and accounting metadata. Do not infer solely from account-code prefix.

23. CASH FLOW RECONCILIATION

Cash Flow must reconcile conceptually: Opening Cash + Net Change in Cash = Closing Cash Closing cash should reconcile to relevant Cash/Bank GL accounts. Mismatch must be visible.

24. MULTI-CURRENCY REPORTING

Financial statements are primarily presented in tenant functional currency. Reuse OA4 functional-currency posted amounts. Where useful, GL/detail reports may also display: transaction currency foreign amount exchange rate snapshot Do not dynamically reconvert historical posted transactions using today's exchange rate.

25. REPORT DIMENSIONS

Support applicable report filtering/segmentation by: Branch Business Unit Cost Center and architecture-ready generic dimensions where existing OA1 dimension framework supports them. Do not create separate dimension logic for each report.

26. DATA SCOPE IN REPORTING

Data Scope applies to every report. A user limited to Branch A must not obtain Branch B financial information through: Balance Sheet P&L Cash Flow Trial Balance GL comparatives dashboard totals exports Do not leak unauthorized totals through aggregate calculations.

27. REPORT SNAPSHOT BOUNDARY

Do not persist snapshots by default merely for convenience. Reports should remain reproducible from authoritative data. If a formal finalized-period report snapshot is useful, implement only minimal immutable snapshot metadata/reference and ensure it is traceable to: period report definition version generation time actor Do not make snapshot the accounting truth.

28. EXPORT

Support export through existing infrastructure where possible. Priority: XLSX/CSV PDF only if existing infrastructure safely supports it Reports: Trial Balance GL Balance Sheet P&L Cash Flow AP Aging AR Aging Reconciliation Export must enforce the exact same: tenant entitlement permission Data Scope filters as screen/API.

29. CLOSING PRINCIPLE

Closing controls whether new financial posting is permitted. Closing must NEVER: delete history rewrite posted journals silently modify balances Closing is a controlled accounting workflow.

30. CLOSE READINESS

Implement centralized Close Readiness service. Evaluate at minimum: unposted journals pending journal approvals AP reconciliation AR reconciliation Cash/Bank reconciliation Fixed Asset reconciliation where module active depreciation completion where applicable tax reconciliation where applicable FX/revaluation status where applicable other critical accounting exceptions Return: READY NOT_READY plus structured reasons. Do not scatter close checks across controllers.

31. MODULE-AWARE CLOSE READINESS

Close checks must respect enabled modules. Example: If ACCOUNTING_FIXED_ASSET is disabled: do not require Fixed Asset reconciliation. If enabled and financial activity exists: apply required check. Do not force tenants to configure modules they do not subscribe to.

32. CLOSING CHECKLIST

Provide configurable/controlled closing checklist foundation. System-generated checks must not be manually marked PASS if underlying validation fails. Manual checklist items may support: pending completed waived with actor/reason/audit where policy allows. Do not allow checklist UI to bypass financial invariants.

33. PRE-CLOSE

Implement Pre-Close as validation stage. Pre-Close: runs readiness checks; identifies blockers/warnings; does not itself permanently lock period. Keep Pre-Close repeatable and idempotent.

34. SOFT CLOSE

Reuse/extend OA1 SOFT_CLOSED semantics. Normal financial posting is denied. Exceptional posting may require explicit permission/policy. Any exception must be auditable. Do not use role names.

35. HARD CLOSE

Hard Close moves period to CLOSED. Before close: Close Readiness must satisfy required policy. After close: normal and exceptional posting must be denied unless period is formally reopened. Closing must be concurrency-safe against simultaneous posting.

36. CLOSE VS POSTING RACE

This is release-critical. Prevent: Thread A checks OPEN Thread B closes period Thread A posts after close Use appropriate database transaction/locking/state validation. Final posting transaction must validate period state atomically.

37. CONTROLLED REOPEN

Implement controlled period reopen. Require dedicated permission. Capture: period previous state new state actor timestamp reason Reopening must be audited. Do not delete closing history. Suggested target: CLOSED → SOFT_CLOSED or OPEN according to policy. Do not allow arbitrary state mutation.

38. REOPEN POLICY

Allow architecture to restrict: who can reopen how far back whether subsequent periods must be reopened required reason required approval where configured Implement only necessary safe foundation. Do not build an excessive workflow engine.

39. CLOSING HISTORY

Maintain immutable/append-oriented closing history. Track: pre-close run soft close hard close reopen readiness result actor timestamp reason/reference Do not overwrite previous closing events.

40. YEAR-END CLOSING

Implement safe year-end closing foundation. Purpose: close temporary income/expense balances and transfer net result according to configured equity/retained earnings mapping. Do not hardcode retained earnings account number.

41. YEAR-END POSTING

Year-end closing financial impact must use OA1 Posting Engine. Concept: Revenue/Expense temporary accounts ↓ Closing Journal ↓ Configured Retained Earnings / Equity Account Closing journal is a normal immutable posted accounting journal with special source/type metadata. Do not directly update account balances.

42. RETAINED EARNINGS MAPPING

Use semantic Account Role/Mapping. Example: RETAINED_EARNINGS Tenant maps this role to tenant-owned COA account. Never hardcode account codes.

43. YEAR-END IDEMPOTENCY

Prevent duplicate year-end closing for same fiscal year/scope. If year-end must be corrected: use controlled reversal/reopen/reclose semantics. Do not generate duplicate closing journals accidentally.

44. NEXT FISCAL YEAR

Balance Sheet accounts carry forward according to accounting semantics. Revenue/Expense temporary accounts begin new fiscal year according to year-end closing policy. Do not create independently editable carry-forward balances. Use posted accounting history/opening logic consistently.

45. RECONCILIATION ENGINE

Implement/reuse a centralized reconciliation framework. Concept: Reconciliation Type ↓ Source/Subledger Balance vs GL Control Balance ↓ Difference ↓ Status ↓ Exception Avoid unrelated reconciliation implementations per module if existing OA2–OA4 services can be standardized safely. Do not perform speculative large refactor.

46. RECONCILIATION TYPES

Support applicable: AP_GL AR_GL CASH_BANK_GL FIXED_ASSET_GL TAX_GL FX_GL Only enable a type when corresponding module/data is available.

47. RECONCILIATION STATUS

Suggested statuses: MATCHED MISMATCH INCOMPLETE NOT_APPLICABLE Keep accounting reconciliation statuses distinct from future OA6 external integration reconciliation statuses. Do not reuse integration-specific statuses incorrectly.

48. RECONCILIATION AS-OF

Reconciliation must be reproducible for explicit: as_of_date or accounting period Both source/subledger and GL sides must use consistent temporal cutoff. Do not compare balances from different effective dates.

49. AP ↔ GL

Reuse OA2 AP reconciliation. Productionize it for closing. Verify: AP Subledger = AP Control Account GL as of the same date/scope. Mismatch becomes closing exception according to policy.

50. AR ↔ GL

Reuse OA3 AR reconciliation. Verify: AR Subledger = AR Control Account GL as of the same date/scope. Mismatch must remain visible.

51. CASH/BANK ↔ GL

Reuse OA2 Cash/Bank reconciliation. Distinguish: Book/GL Balance Operational Cash/Bank Balance External Statement Balance where available Do not automatically post adjustments to force equality.

52. FIXED ASSET ↔ GL

Reuse OA4 Fixed Asset reconciliation. Verify where applicable: Asset Cost Register ↔ Asset GL Accumulated Depreciation Register ↔ Accumulated Depreciation GL Net Book Value consistency. Mismatch is a closing exception.

53. TAX ↔ GL

Where ACCOUNTING_TAX is active, provide tax reconciliation foundation. Compare accounting tax transaction records/snapshots against configured tax control accounts. Do not claim statutory tax-return reconciliation.

54. FX RECONCILIATION

Where Multi-Currency is active, verify relevant: foreign open balances realized FX postings revaluation postings if OA4 implemented them functional-currency GL balances Do not invent revaluation if OA4 explicitly left it NOT IMPLEMENTED.

55. RECONCILIATION EXCEPTIONS

Provide exception representation: type period/as_of_date source balance GL balance difference status notes owner where useful resolution metadata Do not allow manual "MATCHED" status to override a mathematically existing difference. Manual explanation does not change calculated reconciliation result.

56. CLOSING POLICY

Implement configurable closing policy foundation. Examples of configurable behavior: AP mismatch blocks close AR mismatch blocks close Cash mismatch blocks close Fixed Asset mismatch blocks close pending journal blocks close unposted depreciation blocks close Keep policy data-driven. Financial invariants remain non-bypassable.

57. MATERIALITY FOUNDATION

If useful, allow reconciliation tolerance/materiality configuration. Example: absolute tolerance currency-specific rounding tolerance Do not use tolerance to hide material accounting differences. Default should remain strict unless explicitly configured.

58. REPORT CONFIGURATION VERSIONING

Changes to Report Definitions/Mappings must not make finalized historical reports impossible to interpret. Implement minimal effective/version strategy where necessary. Avoid overengineering full document version-control infrastructure.

59. REPORT VALIDATION

Provide configuration validation before financial report use. Detect: unmapped required accounts duplicate/conflicting mappings invalid subtotal structure circular calculation references cross-tenant mappings inactive accounts invalid cash flow classifications Fail clearly rather than producing misleading statements.

60. PERMISSIONS

Add only required atomic permissions. Examples: accounting.reporting.trial_balance.view accounting.reporting.gl.view accounting.reporting.balance_sheet.view accounting.reporting.pnl.view accounting.reporting.cash_flow.view accounting.reporting.export accounting.report_definition.view accounting.report_definition.manage accounting.reconciliation.view accounting.reconciliation.run accounting.reconciliation.exception.manage accounting.period.preclose accounting.period.soft_close accounting.period.hard_close accounting.period.reopen accounting.year_end.view accounting.year_end.run Reuse equivalent existing permission codes. No role-name authorization.

61. SEGREGATION OF DUTIES

Reuse existing SoD foundation. Support policies such as: person preparing reconciliation != approver/closer period closer != period reopener where configured year-end preparer != approver/poster where configured Do not create a separate OA5 workflow engine unless required.

62. AUDIT

Audit at minimum: Report Definition changes Report Mapping changes Financial report snapshot/finalization if implemented Reconciliation runs Reconciliation exception actions Closing policy changes Pre-Close Soft Close Hard Close Reopen Year-End closing Closing journal critical financial exports Preserve reason/actor for sensitive closing actions.

63. REACT UI

Extend tenant Accounting navigation by entitlement. Suggested: Accounting ├── Reports │ ├── Trial Balance │ ├── General Ledger │ ├── Account Balance │ ├── Balance Sheet │ ├── Profit & Loss │ ├── Cash Flow │ └── Comparative Reports │ ├── Reconciliation │ ├── Overview │ ├── AP vs GL │ ├── AR vs GL │ ├── Cash/Bank vs GL │ ├── Fixed Asset vs GL │ └── Other Active Reconciliations │ ├── Closing │ ├── Close Readiness │ ├── Closing Checklist │ ├── Period Closing │ ├── Closing History │ └── Year-End │ └── Configuration └── Financial Report Mapping Do not add OA6 Integration UI.

64. REPORT UX

Financial reports should support: clear report title tenant report period/as-of date functional currency filters generated timestamp where appropriate comparative columns drill-down where existing architecture makes it safe export Do not prioritize visual complexity over correctness.

65. DRILL-DOWN

Where practical: Financial Statement Line → Account → GL Entries → Journal → Source Document Reuse existing source references. Every drill-down must enforce tenant/permission/Data Scope. Do not create direct links that bypass authorization.

66. CLOSING UI

Closing screen should clearly distinguish: READY / NOT READY Blockers Warnings Reconciliation status Pending journals Period state Last close/reopen action Do not let frontend state determine whether close is allowed. Backend revalidates on action.

67. PERFORMANCE

Inspect actual query performance for: Trial Balance GL Balance Sheet P&L Cash Flow Comparatives Reconciliation Close Readiness Use: appropriate indexes SQL aggregation server-side pagination efficient eager loading Avoid N+1. Do not introduce MongoDB/Data Warehouse in OA5.

68. REPORT QUERY ARCHITECTURE

Prefer dedicated query/read services for reporting rather than loading transaction models into application memory. Use PostgreSQL aggregation where appropriate. Do not create duplicated financial calculation logic across React, controllers, exports, and report services. One authoritative calculation path per report type.

69. CACHING

Cache only if justified by actual report cost. Any cache must be: tenant-aware filter-aware period-aware permission/Data Scope safe Do not allow stale cache to expose unauthorized or obsolete financial data. Correctness first.

70. CONCURRENCY

Explicitly test/protect: posting vs hard close posting vs soft close reopen vs posting two simultaneous close attempts two year-end runs reconciliation while postings occur report generation during concurrent posting Use transaction boundaries/locking/snapshot semantics appropriately.

71. SECURITY

Treat as release blockers: cross-tenant report access aggregate financial leakage Data Scope bypass report export leakage closing permission bypass period lock bypass unauthorized reopen duplicate year-end closing report mapping injection unsafe calculated expression execution financial report manipulation reconciliation false-match override

72. BACKWARD COMPATIBILITY

OA0–OA4 tenants must retain previous behavior. OA5 must not modify historical journals to produce reports. Existing: AP AR Expense Cash/Bank Budget Fixed Asset Tax Multi-Currency must remain operational. Tenants without ACCOUNTING_REPORTING must not be forced to configure report mappings merely to continue transactional accounting.

73. OA5 STATUS FILE

Create/update: docs/status/OA5_STATUS.md Keep <= approximately 100 lines. Include only: Status Completed Batches Important Decisions Migrations Report/Closing Invariants Tests Known Issues Remaining Next Phase Dependencies Do not rewrite prior docs unnecessarily.

74. TOKEN EFFICIENCY — STRICT

Repository state is authoritative. DO NOT print: complete files large diffs migration contents full schemas full report payloads full financial statements full test logs dependency logs long accounting explanations previous phase summaries restatement of this prompt Do not narrate routine edits. Inspect only code required for CURRENT batch. Do not repeatedly inspect all OA0–OA4 modules. Reuse existing reconciliation/query/posting abstractions. Do not perform speculative refactoring. Use OA5_STATUS.md as continuation memory. Progress output <= approximately 15 lines.

75. IMPLEMENTATION BATCHES

Execute sequentially: A — Reporting Query Foundation + Advanced Trial Balance + GL B — Report Definition + COA Report Mapping C — Balance Sheet + P&L D — Cash Flow + Comparative Reporting E — Reporting Integrity + Report Tests F — Central Reconciliation Framework G — AP/AR/Cash-Bank/Fixed-Asset Reconciliation Integration H — Tax/FX Reconciliation where applicable I — Close Readiness + Closing Policy + Checklist J — Soft Close + Hard Close + Reopen + Closing History K — Year-End Closing + Retained Earnings L — React Reporting/Reconciliation/Closing UI M — Financial Integrity + Concurrency + Security Tests N — OA0–OA4 Regression O — Release Gate Complete one batch before expanding the next. Do not stop for approval between batches.

76. TARGETED TEST POLICY

During each batch:
1. inspect only relevant implementation;
2. implement;
3. run targeted tests;
4. fix failures;
5. update OA5_STATUS.md;
6. continue. Do not run full regression after every batch. Run broad regression only at release gate.

77. CRITICAL REPORTING TESTS

Prove:
1. Draft journal does not affect financial statements.
2. Submitted journal does not affect financial statements.
3. Approved but unposted journal does not affect statements.
4. Posted journal affects statements exactly once.
5. Trial Balance reconciles to GL.
6. Balance Sheet derives from posted accounting data.
7. Assets = Liabilities + Equity for valid complete configuration.
8. P&L period boundaries are correct.
9. Prior-period P&L activity is not accidentally included.
10. Comparative calculations are correct.
11. Budget comparison reuses authoritative Budget Actual.
12. Cash Flow reconciles opening cash + movement = closing cash.
13. Functional-currency reporting uses historical posted amounts.
14. Current FX rate does not rewrite historical report values.
15. Report mapping is tenant-safe.
16. Data Scope applies to every report.
17. Export equals authorized on-screen/query scope.

78. CRITICAL CLOSING TESTS

Prove:
1. Pre-Close is repeatable.
2. Required reconciliation mismatch blocks close when configured.
3. Pending journal blocks close when configured.
4. Hard Close prevents subsequent posting.
5. Concurrent posting cannot slip through Hard Close.
6. Unauthorized user cannot close.
7. Unauthorized user cannot reopen.
8. Reopen requires reason/audit.
9. Reopen preserves closing history.
10. Closed-period journal history remains immutable.
11. Two simultaneous close attempts remain consistent.
12. READ_ONLY entitlement cannot perform close/reopen.

79. CRITICAL YEAR-END TESTS

Prove:
1. Year-End uses Posting Engine.
2. No direct balance mutation occurs.
3. Revenue/Expense closing calculation is correct.
4. Retained Earnings account is mapping-driven.
5. Closing journal is balanced.
6. Closing journal is immutable.
7. Duplicate Year-End run is prevented.
8. Closed period/year policies are respected.
9. Reopen/correction preserves historical traceability.
10. New fiscal year balance semantics are correct.

80. CRITICAL RECONCILIATION TESTS

Create deterministic accounting scenarios and prove: AP Subledger = AP GL AR Subledger = AR GL Cash/Bank Book = mapped GL where applicable Fixed Asset Register = Asset GL Accumulated Depreciation Register = Accumulated Depreciation GL Tax reconciliation works where active FX reconciliation works where implemented Mismatch must result in MISMATCH. Manual action must not falsely turn a mathematical mismatch into MATCHED.

81. TENANT ISOLATION

Tenant A must never access Tenant B: financial reports report definitions report mappings reconciliations exceptions closing status closing history year-end journals exports Test relevant: GET LIST FILTER CREATE/UPDATE configuration RUN CLOSE REOPEN EXPORT DRILL-DOWN Cross-tenant financial reporting access is P0.

82. ENTITLEMENT / READ_ONLY

Test ACCOUNTING_REPORTING: ACTIVE READ_ONLY SUSPENDED DISABLED expired future-effective READ_ONLY may allow authorized reports/exports according to policy. READ_ONLY must deny: Report Mapping mutation Closing Policy mutation Close Reopen Year-End posting other financial mutation Module-aware reports must honor underlying module availability.

83. PRIOR PHASE REGRESSION

OA5 must not break OA0–OA4. Pay special attention to: Journal posting Period Guard GL Trial Balance AP AR Payment/Receipt allocation Cash/Bank Budget Actual Fixed Asset depreciation Tax posting Multi-Currency Realized FX Run impacted targeted tests during development. Run appropriate broader OA0–OA5 regression at release gate.

84. FRONTEND VALIDATION

Verify: entitlement-based navigation permission-based actions READ_ONLY behavior report filters as-of/period semantics comparative columns drill-down authorization reconciliation states close readiness closing actions reopen UX year-end UX exports loading/error/empty states production build Backend remains accounting authority.

85. RELEASE GATE

Before COMMIT READY verify: [ ] OA0–OA4 remain stable [ ] Advanced Trial Balance works [ ] GL reporting works [ ] Report Definition/Mapping works [ ] No hardcoded account prefixes drive reports [ ] Balance Sheet works [ ] Balance Sheet reconciles [ ] P&L works [ ] P&L period semantics are correct [ ] Cash Flow works [ ] Cash Flow reconciles to Cash/Bank [ ] Comparative reporting works [ ] Budget comparison reuses OA4 [ ] Functional-currency reporting works [ ] Historical FX snapshots are respected [ ] Reconciliation framework works [ ] AP ↔ GL works [ ] AR ↔ GL works [ ] Cash/Bank ↔ GL works [ ] Fixed Asset ↔ GL works [ ] Tax/FX reconciliation handled according to enabled capability [ ] Close Readiness works [ ] Closing Policy works [ ] Pre-Close works [ ] Soft Close works [ ] Hard Close works [ ] Posting vs close race is prevented [ ] Controlled Reopen works [ ] Closing History is preserved [ ] Year-End Closing works [ ] Retained Earnings is mapping-driven [ ] Duplicate Year-End prevented [ ] Tenant isolation passes [ ] RBAC/Data Scope passes [ ] Entitlement/READ_ONLY passes [ ] Audit works [ ] React UI works [ ] Frontend production build passes [ ] Fresh migrations/seeds pass [ ] OA0–OA4 regression passes [ ] No OA6 integration functionality accidentally implemented [ ] No P0/P1 issue remains

86. DEFINITION OF DONE

OA5 is COMMIT READY only when:
- authoritative financial reports derive from POSTED journals;
- Trial Balance reconciles to GL;
- Balance Sheet/P&L/Cash Flow are deterministic;
- reporting is mapping-driven;
- reconciliation framework is operational;
- enabled subledgers reconcile to GL or expose mismatch;
- close readiness is reliable;
- closing is concurrency-safe;
- closed period cannot be bypassed;
- controlled reopen is audited;
- year-end uses Posting Engine;
- no balances are directly mutated;
- tenant isolation passes;
- RBAC/Data Scope passes;
- entitlement/READ_ONLY passes;
- frontend production build passes;
- migrations/seeds pass;
- prior-phase regression passes;
- OA5_STATUS.md is current;
- no fake PASS exists;
- no TODO/mock is counted as complete;
- OA6 has not started.

87. BLOCKER POLICY

Do not ask questions for ordinary implementation decisions. Use: CLAUDE.md architecture docs OA0–OA4 implementation repository conventions Prefer reuse over duplication. Stop only for a genuine owner-level blocker. If validation cannot run: report NOT RUN with concise reason. Never convert NOT RUN into PASS.

88. PROGRESS OUTPUT

During implementation output only: OptiAccounting OA5 Progress Batch: <batch> Completed:
- ... Validation:
- ... Remaining:
- ... Blocker:
- none / ... Keep <= approximately 15 lines.

89. FINAL OUTPUT

When release gate completes output only: OPTIACCOUNTING OA5 — RELEASE GATE STATUS: COMMIT READY / NOT READY Completed:
- maximum 10 concise items Financial Reporting:
- Trial Balance ↔ GL: PASS/FAIL/NOT RUN
- Balance Sheet: PASS/FAIL/NOT RUN
- Profit & Loss: PASS/FAIL/NOT RUN
- Cash Flow: PASS/FAIL/NOT RUN
- Comparative Reporting: PASS/FAIL/NOT RUN Closing:
- Close Readiness: PASS/FAIL/NOT RUN
- Soft/Hard Close: PASS/FAIL/NOT RUN
- Posting vs Close Concurrency: PASS/FAIL/NOT RUN
- Controlled Reopen: PASS/FAIL/NOT RUN
- Year-End Closing: PASS/FAIL/NOT RUN Reconciliation:
- AP ↔ GL: PASS/FAIL/NOT RUN
- AR ↔ GL: PASS/FAIL/NOT RUN
- Cash/Bank ↔ GL: PASS/FAIL/NOT RUN
- Fixed Asset ↔ GL: PASS/FAIL/NOT RUN
- Tax/FX: PASS/FAIL/NOT RUN/NOT APPLICABLE Validation:
- Backend: PASS/FAIL/NOT RUN
- OA0–OA4 Regression: PASS/FAIL/NOT RUN
- Tenant Isolation: PASS/FAIL/NOT RUN
- RBAC/Data Scope: PASS/FAIL/NOT RUN
- Entitlement/READ_ONLY: PASS/FAIL/NOT RUN
- Concurrency: PASS/FAIL/NOT RUN
- Security: PASS/FAIL/NOT RUN
- Frontend Build: PASS/FAIL/NOT RUN
- Migration/Seed: PASS/FAIL/NOT RUN Critical Issues:
- none / ... Known Non-Blocking Issues:
- none / ... OA5_STATUS.md:
- UPDATED / NOT UPDATED Next Phase: OA6 — Integration Platform & OptiFleet Connector Suggested Commit: feat: implement financial reporting closing and reconciliation Do not start OA6. Stop and wait for explicit instruction.
