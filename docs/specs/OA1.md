# OPTIACCOUNTING OA1 — ACCOUNTING CORE & GENERAL LEDGER Implement: OA1 — Accounting Core & General Ledger Target: OPTIACCOUNTING OA1 STATUS: COMMIT READY

1. SOURCE OF TRUTH

OA0 — Standalone SaaS Foundation is completed and committed. Treat OA0 and MASTER architecture as stable baseline. Repository state is authoritative. Before implementation:
1. Read root CLAUDE.md.
2. Read only OA1-relevant documents under docs/architecture/.
3. Read docs/status/OA0_STATUS.md.
4. Read docs/status/OA1_STATUS.md if it exists.
5. Inspect only code relevant to Accounting Core and the OA0 services that OA1 must reuse. Do NOT reconstruct previous phases from chat. Do NOT redesign or recreate:
- Authentication
- Tenant
- Tenant Membership
- RBAC
- Data Scope
- Organization
- Module Catalog
- Subscription
- Entitlement
- Capacity
- Audit foundation
- Platform/Tenant application shell Reuse OA0 capabilities. Use additive migrations only. Do not modify committed OA0 behavior unless required to fix a genuine bug or to provide a small backward-compatible extension needed by OA1.

2. PHASE OBJECTIVE

Build the authoritative Accounting Core for OptiAccounting. At the end of OA1, an entitled tenant must be able to: Configure Accounting Profile ↓ Configure Fiscal Year / Accounting Period ↓ Create Chart of Accounts ↓ Configure Accounting Dimensions ↓ Configure Posting Rules / Account Mappings ↓ Create Manual Journal ↓ Submit / Approve ↓ Post ↓ Generate General Ledger ↓ Generate Trial Balance ↓ Reverse when correction is required Also support controlled Opening Balance initialization. OA1 establishes the financial posting foundation that OA2–OA7 must reuse. No future module may create independent debit/credit posting logic outside the Accounting Posting Engine.

3. STRICT PHASE BOUNDARY

IN SCOPE:
- Accounting Profile
- Accounting Framework metadata
- Functional Currency foundation
- Fiscal Year
- Accounting Period
- Period lifecycle/locking
- Chart of Accounts
- COA hierarchy
- Account Types
- Normal Balance
- Accounting Dimensions foundation
- Cost Center
- Manual Journal
- Journal Entry
- Journal Entry Line
- Journal lifecycle
- Journal Approval foundation
- Segregation of Duties foundation
- Journal Posting
- Journal Reversal
- Central Posting Engine
- Accounting Event foundation
- Posting Rule
- Account Mapping
- Opening Balance
- General Ledger
- Trial Balance
- Accounting document numbering integration
- Accounting audit integration
- ACCOUNTING_CORE entitlement
- OA1 permissions/data scopes
- Accounting Core React UI
- OA1 tests/regression OUT OF SCOPE:
- Vendor/AP
- Vendor Invoice
- AP Payment
- Expense transactions
- Cash/Bank transactions
- Customer/AR
- Customer Invoice
- Receipt
- Budget
- Fixed Asset
- Depreciation
- Full Tax engine
- Multi-Currency transaction accounting
- Foreign exchange gain/loss
- Financial Statements
- Cash Flow Statement
- Advanced Period Closing / Year-End
- AP/AR/Bank reconciliation
- OptiFleet integration
- External Financial Event adapters
- MongoDB analytics
- Financial Intelligence Do not start OA2.

4. ACCOUNTING CORE ENTITLEMENT

Reuse OA0 module/feature entitlement architecture. OA1 belongs to: ACCOUNTING_CORE Add only OA1 feature definitions that are actually required. Suggested features: ACCOUNTING_PROFILE FISCAL_PERIOD CHART_OF_ACCOUNTS ACCOUNTING_DIMENSION MANUAL_JOURNAL POSTING_ENGINE OPENING_BALANCE GENERAL_LEDGER TRIAL_BALANCE Do not hardcode module access in controllers or React. Effective access must reuse OA0 capability resolver. ACCOUNTING_CORE unavailable: Accounting Core unavailable. ACCOUNTING_CORE READ_ONLY: historical/read operations allowed according to policy, mutations/posting/configuration changes denied. Backend is authoritative.

5. ACCOUNTING PROFILE

Implement one effective Accounting Profile per tenant unless existing architecture defines a more flexible versioned model. Minimum concepts: tenant accounting framework functional currency fiscal year configuration posting configuration opening/cutover status accounting activation/readiness status relevant policy metadata Supported framework metadata foundation: SAK_GENERAL SAK_EP SAK_EMKM CUSTOM These are configuration metadata. Do NOT claim automatic accounting-standard compliance. Do NOT hardcode journal logic based on framework labels. Functional Currency is required before production posting. OA1 supports one functional currency. Full transaction multi-currency belongs to OA4.

6. ACCOUNTING READINESS

Implement a reusable Accounting Readiness service. Posting must not rely on scattered validation. Conceptually validate: Tenant allowed AND ACCOUNTING_CORE entitlement allowed AND Accounting Profile ready AND Functional Currency configured AND Fiscal configuration valid AND Posting Date belongs to OPEN period AND COA/account mappings valid where required AND Journal balanced AND Authorization valid Return actionable readiness reasons. Suggested states: NOT_CONFIGURED CONFIGURING READY LOCKED Use repository conventions where appropriate. Do not confuse Accounting readiness with Tenant lifecycle.

7. FISCAL YEAR

Implement tenant-specific Fiscal Year. Minimum concepts: name/code start_date end_date status Suggested lifecycle: DRAFT OPEN CLOSED Prevent: overlapping fiscal years; invalid date ranges; cross-tenant relationships. Support non-calendar fiscal years. Do not assume: January 1 → December 31.

8. ACCOUNTING PERIOD

Implement Accounting Period under Fiscal Year. Minimum: period number/code name start_date end_date status Suggested lifecycle: FUTURE OPEN SOFT_CLOSED CLOSED Semantics: OPEN: normal authorized posting allowed. SOFT_CLOSED: normal posting denied; exception posting requires dedicated permission/policy if supported. CLOSED: posting forbidden. OA5 will implement advanced closing/reopening. OA1 must enforce period state centrally in posting. Do not rely on frontend checks.

9. POSTING DATE

Financial posting must use explicit posting_date. Do not infer accounting period solely from created_at. Where applicable distinguish: document_date transaction_date posting_date created_at Posting Engine resolves accounting period from posting_date. Posting into an unavailable period must fail atomically.

10. CHART OF ACCOUNTS

Implement tenant-owned hierarchical Chart of Accounts. Minimum concepts: tenant code name parent account account type normal balance posting_allowed / control flag active state currency restriction foundation if useful metadata where justified Do not use mutable shared platform accounts directly in tenant journals. If platform COA templates exist, applying a template must create tenant-owned accounts.

11. ACCOUNT TYPES

Support canonical account classifications sufficient for later reporting. At minimum: ASSET LIABILITY EQUITY REVENUE EXPENSE Normal balance: DEBIT CREDIT Avoid deriving accounting semantics only from account code prefixes. Account code structure is tenant-configurable data. Do not hardcode: 1xxx = Asset 2xxx = Liability as accounting authority.

12. COA HIERARCHY

Prevent invalid hierarchy: self-parent circular parent chain cross-tenant parent posting into non-postable/control account where prohibited Used accounts must not be hard deleted. Use inactive/archive behavior according to architecture. An account referenced by posted journal history must remain historically resolvable.

13. COA TEMPLATE FOUNDATION

If useful, provide a minimal platform COA template mechanism. Applying a template: Platform Template ↓ Copy ↓ Tenant-owned COA Do not make tenant journals reference template account IDs. Keep templates minimal. Do not spend OA1 implementing a large Indonesian industry-specific COA catalog unless already required by repository architecture.

14. ACCOUNTING DIMENSIONS

Implement reusable accounting dimension foundation. Minimum internal dimensions: BRANCH BUSINESS_UNIT COST_CENTER Reuse OA0 Branch and Business Unit. Implement Cost Center in OA1. Architecture must later support external dimensions such as: VEHICLE WORK_ORDER WORKSHOP WAREHOUSE PROJECT PRODUCT COMPONENT TIRE Do not implement external integrations yet.

15. COST CENTER

Implement tenant-owned Cost Center. Support: code name status optional organization relationship where useful Do not force every journal line to have a cost center. Dimension requirements may later be determined by posting/account policy. Prevent cross-tenant dimension assignment.

16. DIMENSION STORAGE

Choose a relational and extensible approach consistent with MASTER. Avoid: adding vehicle_id, workshop_id, project_id, etc. directly to journal_lines. Journal lines should support reusable accounting dimensions without schema changes for every future integration. Do not put core debit/credit/account/amount fields into JSONB. JSONB may be used only for controlled metadata where justified.

17. JOURNAL ENTRY

Journal Entry is the authoritative financial posting document. Minimum header concepts: tenant journal number journal type document_date transaction_date where applicable posting_date fiscal year accounting period description currency source status created_by submitted_by approved_by where applicable posted_by posted_at reversal references timestamps Use exact schema naming consistent with repository conventions.

18. JOURNAL LINE

Minimum line concepts: journal entry line sequence account description debit credit dimension assignments reference metadata where appropriate Money must use NUMERIC/DECIMAL. Never FLOAT. A line must not simultaneously contain non-zero Debit and Credit. A zero-value meaningless line must be rejected. Use appropriate precision determined by architecture/configuration.

19. DOUBLE-ENTRY INVARIANT

This is release-critical. For every POSTED journal: SUM(debit) = SUM(credit) and journal total must be meaningful/non-zero. Validate server-side. Use exact decimal arithmetic. Posting must be atomic. If any line fails: NO journal line may partially post.

20. JOURNAL LIFECYCLE

Implement controlled transitions. Baseline: DRAFT → SUBMITTED → APPROVED → POSTED Alternatives: SUBMITTED → REJECTED DRAFT → CANCELLED SUBMITTED → CANCELLED where policy permits POSTED cannot return to: DRAFT SUBMITTED APPROVED CANCELLED Corrections require reversal/adjustment. Do not allow arbitrary status PATCH. Use Journal Transition/Application Service.

21. JOURNAL APPROVAL

Implement OA1 approval foundation. Authorization must use permissions/policies. Do not hardcode: Accountant Finance Manager Admin Support maker-checker foundation. At minimum architecture must allow policy: creator != approver where enabled. Detailed configurable financial workflow can evolve later, but OA1 must not require rewriting journal posting to support it.

22. SEGREGATION OF DUTIES

Implement reusable SoD validation for critical journal actions. Potential policies: creator cannot approve own journal creator cannot post own journal approver and poster separation Make policy configurable where MASTER allows it. Do not weaken financial invariants even when SoD is disabled.

23. JOURNAL NUMBERING

Reuse centralized document numbering infrastructure if MASTER/OA0 already provides it. Otherwise implement the smallest reusable accounting numbering service required by OA1 without creating a module-specific max+1 pattern. Journal number must be: unique within configured scope; transactional; concurrency-safe; immutable once issued. Never use unsafe: SELECT MAX(number) + 1 Future documents must be able to reuse the numbering infrastructure.

24. POSTING ENGINE

Implement one centralized Accounting Posting Engine. All automatic financial postings in future phases must reuse it. Concept: Accounting Event / Posting Request ↓ Validation ↓ Posting Rule Resolution where applicable ↓ Account Mapping Resolution ↓ Journal Construction ↓ Double-Entry Validation ↓ Period Validation ↓ Authorization / System Authority ↓ Atomic Posting ↓ Audit Manual journals may bypass event-to-rule resolution but must still use the same posting validation/posting core. Do not create separate GL posting implementations.

25. ACCOUNTING EVENT FOUNDATION

Create only the internal Accounting Event foundation required for future subledgers. This is NOT OA6 external integration. An Accounting Event represents a financial business fact that can be mapped into a journal. Examples for future phases: AP_INVOICE_RECOGNIZED VENDOR_PAYMENT AR_INVOICE_RECOGNIZED CUSTOMER_RECEIPT EXPENSE_RECOGNIZED Do not implement those transactions now. Keep the foundation generic.

26. POSTING RULE

Implement configurable Posting Rule foundation. Posting Rule determines accounting semantics for a supported accounting event. Example concept: event: EXPENSE_RECOGNIZED Debit role: EXPENSE_ACCOUNT Credit role: PAYABLE_ACCOUNT Do not hardcode actual tenant account numbers in domain code. Rules must be tenant-safe and auditable. Do not implement arbitrary scripting/eval.

27. ACCOUNT ROLE / MAPPING

Implement a reusable Account Role / Account Mapping concept. Examples of future semantic roles: CASH BANK ACCOUNTS_RECEIVABLE ACCOUNTS_PAYABLE INVENTORY_ASSET EXPENSE REVENUE TAX_RECEIVABLE TAX_PAYABLE RETAINED_EARNINGS Only seed roles needed for OA1 plus minimal future-ready catalog if architecture requires it. Tenant mapping resolves: semantic account role → tenant COA account Do not embed account IDs into application source code.

28. POSTING RULE VERSION SAFETY

Financial configuration must not silently rewrite history. Posted journal stores sufficient resolved accounting facts so later changes to Posting Rules or Account Mappings do not alter historical journals. If versioning is implemented now, keep it concise. At minimum: audit changes; preserve posted journal facts; never dynamically reinterpret historical journal lines.

29. MANUAL JOURNAL

Implement tenant UI/API for Manual Journal. Minimum capability: create draft add/remove lines assign accounts assign allowed dimensions enter debit/credit description/reference select posting date save draft submit approve post reject/cancel where valid view reverse posted journal Validate entitlement, permission, scope and accounting readiness.

30. MANUAL JOURNAL SAFETY

Manual journal must not bypass Accounting Core. Enforce: tenant ownership active/postable account valid posting period balanced journal decimal precision dimension tenant ownership workflow permissions SoD policy read-only entitlement Never trust frontend-calculated journal totals.

31. REVERSAL

Posted journal correction uses a new reversal journal. Reversal must: reference original journal; reverse debit/credit amounts; use authorized reversal posting date; validate target period; preserve original journal; be atomic; be auditable. Original journal remains POSTED. Track reversal relationship/status without mutating financial history.

32. REVERSAL IDEMPOTENCY

Prevent accidental duplicate full reversal. A journal already fully reversed must not be fully reversed again unless a future explicit accounting use case supports controlled partial/secondary adjustment. Concurrency must not create duplicate reversal journals.

33. OPENING BALANCE

Implement controlled Opening Balance initialization. Opening Balance is financial posting, not arbitrary account balance storage. Preferred model: Opening Balance Input ↓ Validation ↓ Balanced Opening Journal ↓ Posting Engine ↓ GL Do not maintain a separately editable account balance table as financial truth.

34. OPENING BALANCE CONTROL

Opening balance must support: cutover date account debit/credit dimensions where appropriate source/reference validation posting Prevent accidental repeated initialization. If adjustment is required after posting, use controlled adjustment/reversal. Document opening balance semantics concisely.

35. GENERAL LEDGER

General Ledger derives from POSTED journal lines. Do NOT create an independently editable GL. Provide query/service/API/UI capable of: date range account branch business unit cost center journal/source reference opening balance period debit period credit running/closing balance where appropriate Respect tenant and data scope.

36. GL BALANCE SEMANTICS

Balance calculation must respect account normal balance. Do not use inconsistent frontend formulas. Centralize financial balance calculation. Ensure: Debit-normal account: debit increases balance. Credit-normal account: credit increases balance. Keep raw debit/credit visible independently from presented balance.

37. TRIAL BALANCE

Implement Trial Balance based only on POSTED journal lines. Support at minimum: period/date range account hierarchy where useful opening balance debit movement credit movement ending balance Validate: total debit movement = total credit movement where mathematically applicable to selected complete ledger scope. Do not include DRAFT/SUBMITTED/APPROVED journals in authoritative balance.

38. BALANCE QUERY ARCHITECTURE

Avoid persisting mutable account balances as the primary source of truth. Posted journal lines are authoritative. If performance requires summary/balance tables later: they are projections; they must be rebuildable; they must reconcile to posted journal lines. OA1 should prefer correctness before premature aggregation.

39. ACCOUNTING CORE API

Use versioned tenant APIs following repository conventions. Logical resources: accounting/profile accounting/fiscal-years accounting/periods accounting/accounts accounting/dimensions accounting/cost-centers accounting/journals accounting/posting-rules accounting/account-mappings accounting/opening-balances accounting/general-ledger accounting/trial-balance Do not force exact routes if repository conventions already define a better consistent structure. Keep controllers thin.

40. PERMISSIONS

Add only OA1 atomic permissions required by implemented functionality. Examples: accounting.profile.view accounting.profile.manage accounting.period.view accounting.period.manage accounting.period.close accounting.coa.view accounting.coa.manage accounting.dimension.view accounting.dimension.manage accounting.journal.view accounting.journal.create accounting.journal.update accounting.journal.submit accounting.journal.approve accounting.journal.post accounting.journal.reverse accounting.posting_rule.view accounting.posting_rule.manage accounting.account_mapping.view accounting.account_mapping.manage accounting.opening_balance.view accounting.opening_balance.manage accounting.opening_balance.post accounting.gl.view accounting.trial_balance.view accounting.report.export Use repository naming if equivalent permissions already exist. No role-name authorization.

41. DATA SCOPE

Reuse OA0 Data Scope. Accounting queries must honor: TENANT BRANCH BUSINESS_UNIT COST_CENTER where OA1 extends scope support OWN only where semantically valid Be careful: A user restricted to Branch A must not obtain Branch B financial details via: journal detail GL Trial Balance filter options export dimension lookup Server-side scope enforcement is mandatory.

42. ACCOUNTING AUDIT

Reuse OA0 audit infrastructure. Audit at minimum: Accounting Profile changes Fiscal Year changes Period status changes COA changes Cost Center changes Posting Rule changes Account Mapping changes Journal create/submit/approve/reject/post Journal reversal Opening Balance actions critical exports where architecture supports it Do not log sensitive secrets or excessive payloads. Posted financial audit must remain traceable.

43. CONCURRENCY

OA1 must explicitly handle concurrency for: journal numbering journal posting journal approval/post race period close vs posting journal reversal opening balance posting Use database transactions/locking/constraints where appropriate. Prevent: double posting duplicate reversal posting after period closes duplicate journal number partial journal posting

44. DATABASE INTEGRITY

Use PostgreSQL constraints where appropriate. Protect: tenant relationships unique account code per tenant/scope invalid parent account invalid fiscal ranges overlapping fiscal periods where feasible invalid journal line values invalid state relationships duplicate reversal linkage journal number uniqueness Application validation complements database integrity. It does not replace it.

45. FINANCIAL PRECISION

Use NUMERIC/DECIMAL for all monetary values. Determine precision/scale consistently. Never: FLOAT DOUBLE JavaScript floating-point arithmetic as accounting authority Frontend may display/calculate previews but backend recalculates authoritative totals using decimal-safe handling.

46. REACT TENANT MENU

Add Accounting Core tenant navigation only when entitled. Suggested: Accounting ├── Dashboard / Setup Status ├── Accounting Profile ├── Fiscal Year & Period ├── Chart of Accounts ├── Dimensions / Cost Center ├── Manual Journal ├── Opening Balance ├── General Ledger └── Trial Balance Configuration subsection if appropriate: Accounting Configuration ├── Posting Rules └── Account Mapping Use existing navigation architecture. Do not expose OA2+ menus.

47. ACCOUNTING SETUP EXPERIENCE

Provide a concise setup/readiness experience. Example:
1. Accounting Profile
2. Functional Currency
3. Fiscal Year
4. Accounting Period
5. Chart of Accounts
6. Required Account Mappings
7. Opening Balance if applicable
8. Ready Do not create a separate wizard framework if existing React patterns can handle this simply. Show actionable missing configuration.

48. ACCOUNTING CORE DASHBOARD

Keep OA1 dashboard minimal. Useful indicators: Accounting readiness Current fiscal period Period status Unposted journals Journals pending approval Recent posted journals Do not build OA7 analytics.

49. EXPORT

If repository already provides export infrastructure, allow controlled export for: Chart of Accounts General Ledger Trial Balance Use CSV/XLSX only if supported without unnecessary new dependencies. Export must enforce the exact same tenant/permission/data scope as screen/API. Do not prioritize export over Accounting Core correctness.

50. BACKWARD COMPATIBILITY

OA0 tenants without ACCOUNTING_CORE must behave exactly as before. OA1 must not make Accounting setup mandatory for: authentication tenant management organization management access management subscription/account pages Accounting is a capability, not a prerequisite for SaaS operation.

51. OA1 DOCUMENTATION

Create/update: docs/status/OA1_STATUS.md Maximum approximately 100 lines. Include only: Status Completed Batches Important Decisions Migrations Accounting Invariants Tests Known Issues Remaining Next Phase Dependencies Update architecture documents only if implementation creates a durable decision not already documented. Do not rewrite MASTER documentation.

52. TOKEN EFFICIENCY — STRICT

Repository state is authoritative. DO NOT print: complete files large diffs migration contents full schemas full API payloads full test logs dependency logs long accounting tutorials repeated MASTER/OA0 architecture restatement of this prompt Do not narrate routine implementation. Inspect only files needed for the current batch. Do not repeatedly scan the full repository. Use OA1_STATUS.md as continuation memory. Progress output <= approximately 15 lines.

53. IMPLEMENTATION BATCHES

Execute sequentially: A — Accounting Profile + Readiness B — Fiscal Year + Accounting Period + Period Guard C — Chart of Accounts + Hierarchy D — Accounting Dimensions + Cost Center E — Journal + Journal Lines + Lifecycle F — Approval + SoD + Numbering G — Posting Engine + Accounting Event Foundation H — Posting Rules + Account Roles + Account Mapping I — Reversal + Opening Balance J — General Ledger + Trial Balance K — React Accounting Core UI L — Financial Integrity + Concurrency Tests M — OA0 Regression + Security/Data Scope Tests N — Release Gate Do not stop for approval between batches. For each batch:
1. inspect only relevant code;
2. implement;
3. run targeted tests;
4. fix failures;
5. update OA1_STATUS.md concisely;
6. continue. Do not run full regression after every batch.

54. TARGETED TEST STRATEGY

During each batch run only relevant tests. Examples: AccountingProfileTest AccountingReadinessTest FiscalYearTest AccountingPeriodTest PeriodPostingGuardTest ChartOfAccountsTest AccountHierarchyTest AccountingDimensionTest JournalEntryTest JournalTransitionTest JournalApprovalTest SegregationOfDutiesTest JournalNumberConcurrencyTest PostingEngineTest DoubleEntryInvariantTest AccountMappingTest JournalReversalTest OpeningBalanceTest GeneralLedgerTest TrialBalanceTest AccountingTenantIsolationTest AccountingDataScopeTest Use actual repository test naming conventions. Do not create redundant tests solely to match these names.

55. CRITICAL FINANCIAL TESTS

Release gate must prove at minimum:
1. Unbalanced journal cannot post.
2. Balanced journal posts atomically.
3. Draft journal does not affect GL.
4. Submitted journal does not affect GL.
5. Approved but unposted journal does not affect GL.
6. Posted journal affects GL exactly once.
7. Posted journal cannot be edited.
8. Posted journal cannot be deleted.
9. Closed period rejects posting.
10. Concurrent close/post cannot bypass period lock.
11. Inactive/non-postable account rejects posting.
12. Cross-tenant account cannot be used.
13. Cross-tenant dimension cannot be used.
14. Duplicate posting is prevented.
15. Reversal creates opposite balanced journal.
16. Original posted journal remains unchanged.
17. Duplicate reversal is prevented.
18. Opening balance posts through Accounting Core.
19. GL derives only from posted journals.
20. Trial Balance agrees with posted journal ledger.

56. TENANT ISOLATION TESTS

Tenant A must never access Tenant B: Accounting Profile Fiscal Years Periods COA Cost Centers Journals Journal Lines Posting Rules Account Mappings Opening Balance General Ledger Trial Balance Exports Test where relevant: GET LIST FILTER/SEARCH CREATE relationship UPDATE DELETE/deactivate POST APPROVE REVERSE EXPORT Cross-tenant financial access is P0.

57. ENTITLEMENT TESTS

Validate ACCOUNTING_CORE: ACTIVE READ_ONLY SUSPENDED DISABLED expired future-effective READ_ONLY must prevent at minimum: profile mutation COA mutation journal create/update/submit/approve/post reversal opening balance mutation/post posting rule/account mapping mutation while authorized historical viewing remains available according to policy.

58. PERMISSION / SOD TESTS

Test: unauthorized journal create unauthorized submit unauthorized approve unauthorized post unauthorized reverse unauthorized period management unauthorized COA management unauthorized GL access unauthorized Trial Balance access Where maker-checker policy is active: creator cannot perform prohibited approval/post action.

59. GL / TRIAL BALANCE RECONCILIATION TEST

Create a deterministic set of posted journals. Verify: journal debit total = journal credit total and aggregated ledger debit = aggregated ledger credit for the complete selected tenant scope. Verify Trial Balance output against the same authoritative posted journal lines. Do not test only frontend rendering.

60. OA0 REGRESSION

OA1 must not break: authentication tenant switching tenant membership RBAC data scope organization module dependency subscription entitlement feature entitlement capacity audit platform portal tenant portal Run targeted OA0 regression during development. Run appropriate broader OA0 regression at OA1 release gate.

61. FRONTEND VALIDATION

Verify: Accounting menu entitlement visibility permission-based actions READ_ONLY behavior setup/readiness states journal validation UX period lock UX COA hierarchy GL filters Trial Balance filters loading/error/empty states production build Frontend must never be the only financial control.

62. PERFORMANCE

Inspect obvious performance risks for: COA tree journal list journal detail GL query Trial Balance permission/capability resolution Avoid N+1. Add indexes based on actual query patterns. Do not prematurely add MongoDB or analytical infrastructure.

63. SECURITY

Treat as release blockers: cross-tenant financial access permission bypass entitlement bypass READ_ONLY bypass journal tampering posted journal mutation period lock bypass duplicate posting duplicate reversal unsafe mass assignment cross-tenant dimension injection financial export scope leakage Do not expose internal stack traces or sensitive configuration.

64. RELEASE GATE

Before COMMIT READY verify: [ ] OA0 remains stable [ ] ACCOUNTING_CORE entitlement enforced [ ] Accounting Profile works [ ] Accounting Readiness works [ ] Functional Currency foundation works [ ] Non-calendar Fiscal Year supported [ ] Accounting Period lifecycle works [ ] Closed period blocks posting [ ] COA hierarchy works [ ] Invalid/circular COA hierarchy prevented [ ] Used accounts preserve history [ ] Cost Center/dimension foundation works [ ] Manual Journal works [ ] Journal workflow works [ ] SoD foundation works [ ] Journal numbering is concurrency-safe [ ] Double-entry invariant enforced [ ] Posted journal immutable [ ] Central Posting Engine works [ ] Posting Rules work [ ] Account Mapping works [ ] Reversal works [ ] Duplicate reversal prevented [ ] Opening Balance works [ ] GL derives only from posted journal lines [ ] Trial Balance reconciles [ ] Tenant isolation passes [ ] Data Scope passes [ ] Permissions pass [ ] READ_ONLY passes [ ] Concurrency tests pass [ ] Accounting audit works [ ] React Accounting Core UI works [ ] Frontend production build passes [ ] Fresh migration/seed passes [ ] No OA2 functionality accidentally implemented [ ] No dependency on OptiFleet introduced [ ] No P0/P1 issue remains

65. DEFINITION OF DONE

OA1 is COMMIT READY only when:
- Accounting Core implementation is complete;
- financial invariants are enforced server-side;
- migrations and seeds are valid;
- targeted tests pass;
- OA0 regression passes;
- tenant isolation passes;
- financial integrity tests pass;
- concurrency-critical tests pass;
- frontend build passes;
- OA1_STATUS.md is current;
- no fake PASS exists;
- no TODO/mock implementation is counted as complete;
- OA2 has not been started.

66. BLOCKER POLICY

Do not ask for approval for ordinary implementation decisions. Use: CLAUDE.md repository architecture OA0 implementation existing conventions Stop only for a genuine blocker requiring owner decision. If a test/tool cannot run because of environment limitations: record NOT RUN and the exact concise reason. Never convert NOT RUN into PASS.

67. PROGRESS OUTPUT

During implementation output only: OptiAccounting OA1 Progress Batch: <batch> Completed:
- ... Validation:
- ... Remaining:
- ... Blocker:
- none / ... Keep output <= approximately 15 lines.

68. FINAL OUTPUT

When OA1 release gate completes output only: OPTIACCOUNTING OA1 — ACCOUNTING CORE RELEASE GATE STATUS: COMMIT READY / NOT READY Completed:
- maximum 10 concise items Financial Integrity:
- Double Entry: PASS/FAIL/NOT RUN
- Journal Immutability: PASS/FAIL/NOT RUN
- Period Locking: PASS/FAIL/NOT RUN
- Reversal: PASS/FAIL/NOT RUN
- GL Reconciliation: PASS/FAIL/NOT RUN
- Trial Balance: PASS/FAIL/NOT RUN Validation:
- Backend: PASS/FAIL/NOT RUN
- OA0 Regression: PASS/FAIL/NOT RUN
- Tenant Isolation: PASS/FAIL/NOT RUN
- RBAC/Data Scope: PASS/FAIL/NOT RUN
- Entitlement/READ_ONLY: PASS/FAIL/NOT RUN
- Concurrency: PASS/FAIL/NOT RUN
- Security: PASS/FAIL/NOT RUN
- Frontend Build: PASS/FAIL/NOT RUN
- Migration/Seed: PASS/FAIL/NOT RUN Critical Issues:
- none / ... Known Non-Blocking Issues:
- none / ... OA1_STATUS.md:
- UPDATED / NOT UPDATED Next Phase: OA2 — Accounts Payable, Expense & Cash/Bank Suggested Commit: feat: implement OptiAccounting accounting core and general ledger Do not start OA2. Stop and wait for explicit instruction.
