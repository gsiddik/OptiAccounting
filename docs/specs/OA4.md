# OPTIACCOUNTING OA4 — BUDGET, FIXED ASSET, TAX & MULTI-CURRENCY Implement: OA4 — Budget, Fixed Asset, Tax & Multi-Currency Target: OPTIACCOUNTING OA4 STATUS: COMMIT READY

1. SOURCE OF TRUTH

MASTER and OA0–OA3 are completed and committed. Treat them as stable baseline. Repository state is authoritative. Before implementation:
1. Read root CLAUDE.md.
2. Read only OA4-relevant architecture docs.
3. Read docs/status/OA3_STATUS.md.
4. Read docs/status/OA4_STATUS.md if it exists.
5. Inspect only existing services required by the current OA4 batch. Do NOT reconstruct previous phases from chat. Do NOT redesign or recreate:
- SaaS/Tenant/RBAC/Entitlement
- Accounting Profile
- Fiscal Period
- COA
- Dimensions
- Journal
- Posting Engine
- Posting Rules
- Account Mapping
- GL/Trial Balance
- AP/Expense/Cash-Bank
- AR/Revenue Reuse existing implementation. Use additive migrations only. Modify committed baseline only for:
- genuine bug; or
- minimal backward-compatible extension required by OA4.

2. PHASE OBJECTIVE

Extend OptiAccounting with: A. Budget Management B. Fixed Asset Accounting C. Tax Configuration & Calculation D. Multi-Currency Accounting All financial postings must continue through OA1: Business Transaction ↓ Accounting Event ↓ Posting Rule ↓ Account Mapping ↓ Posting Engine ↓ Posted Journal ↓ General Ledger OA4 must not introduce another financial truth or posting engine.

3. STRICT PHASE BOUNDARY

IN SCOPE: BUDGET
- Budget
- Budget Version
- Budget Lines
- Dimension-based budgeting
- Budget approval/activation
- Budget vs Actual FIXED ASSET
- Asset Category
- Fixed Asset Register
- Capitalization
- Depreciation Method
- Depreciation Schedule
- Depreciation Run
- Depreciation Posting
- Asset Disposal
- Fixed Asset ↔ GL Reconciliation TAX
- Tax Code
- Tax Rate
- Tax Type
- Tax Treatment
- Tax Account Mapping
- Transaction tax calculation
- Input/output tax foundation
- Tax posting integration
- Basic tax reporting data MULTI-CURRENCY
- Currency Master
- Functional Currency integration
- Transaction Currency
- Exchange Rate
- Rate Type/Source metadata
- Foreign/Base amounts
- Currency conversion
- Realized FX Gain/Loss foundation
- Unrealized FX/Revaluation foundation only if safely implemented CROSS-DOMAIN
- permissions
- entitlements
- data scope
- audit
- React UI
- tests/regression OUT OF SCOPE:
- Full financial statements
- Advanced period/year-end closing
- Budget forecasting/AI
- Procurement
- Inventory system
- Asset maintenance
- Payroll
- Full Indonesian tax filing/e-Faktur/e-Bupot integration
- Automatic statutory tax updates
- Tax filing/submission
- Complex deferred tax
- Hedge accounting
- Consolidation
- Intercompany accounting
- External FX provider integration
- OptiFleet integration
- External financial adapters
- MongoDB analytics
- Management Accounting analytics Do not start OA5.

4. ENTITLEMENTS

Reuse OA0 entitlement infrastructure. Modules: ACCOUNTING_BUDGET ACCOUNTING_FIXED_ASSET ACCOUNTING_TAX ACCOUNTING_MULTI_CURRENCY Each depends on ACCOUNTING_CORE. Additional dependency between OA4 modules must be data-driven and added only when genuinely required. Do not hardcode module checks. Support: ACTIVE READ_ONLY SUSPENDED DISABLED READ_ONLY must deny mutations/posting while preserving authorized historical access.

5. IMPLEMENTATION DISCIPLINE

OA4 contains four substantial domains. Complete one domain before expanding to the next: Budget → Fixed Asset → Tax → Multi-Currency → Cross-domain regression Do not inspect or modify all four domains simultaneously. Do not stop for owner approval between batches. Use OA4_STATUS.md as phase memory.

6. BUDGET PRINCIPLE

Budget is planning data. Budget is NOT: Journal GL Actual financial transaction Creating/approving a budget must not create a journal unless a future explicit accounting use case requires it. Actual values always come from authoritative POSTED accounting data.

7. BUDGET

Implement tenant-owned Budget. Minimum concepts: code name fiscal year description currency status owner/responsible party where appropriate created/approved metadata Suggested lifecycle: DRAFT SUBMITTED APPROVED ACTIVE CLOSED CANCELLED Do not combine budget version state and budget lifecycle ambiguously.

8. BUDGET VERSION

Support multiple versions. Examples: Original Revision 1 Revision 2 Do not hardcode these names. Preserve historical approved versions. Budget revision must not silently rewrite the previously approved budget. Only one effective version per defined scope/date should be used for official Budget vs Actual comparison unless architecture explicitly supports scenarios.

9. BUDGET LINES

Budget Line should support: account/account grouping period budget amount applicable accounting dimensions description/notes Use DECIMAL/NUMERIC. Prevent cross-tenant: account period dimension budget relationships.

10. BUDGET DIMENSIONS

Reuse OA1 Accounting Dimensions. Budget may be defined by: Tenant Branch Business Unit Cost Center Account Architecture must remain compatible with future external dimensions. Do not create separate dimension architecture for Budget.

11. BUDGET VS ACTUAL

Actual must derive only from POSTED journal lines. Provide: Budget Actual Variance Variance % with appropriate handling of zero budget. Support: period/date account branch business unit cost center Respect tenant/Data Scope. Never maintain a manually editable "actual" amount.

12. BUDGET CONTROL BOUNDARY

OA4 may provide budget visibility and variance. Do NOT implement hard transaction blocking based on budget unless MASTER explicitly requires it. Future policy may support: WARNING SOFT_CONTROL HARD_CONTROL Keep architecture extensible without introducing premature enforcement.

13. FIXED ASSET PRINCIPLE

Fixed Asset module owns: Asset Register Capitalization metadata Depreciation Schedule Disposal lifecycle GL remains financial truth. Asset module never directly updates GL balances. Every financial event posts through OA1 Posting Engine.

14. ASSET CATEGORY

Implement tenant-owned Asset Category. Minimum configurable accounting semantics: asset account role/mapping accumulated depreciation role/mapping depreciation expense role/mapping gain/loss disposal role/mapping default useful life default depreciation method residual value policy where appropriate Do not hardcode account numbers.

15. FIXED ASSET REGISTER

Implement generic Fixed Asset. Minimum concepts: asset number name category acquisition date capitalization date acquisition cost residual value useful life depreciation method currency dimensions status source/reference capitalization journal reference disposal metadata where applicable Do NOT make Fixed Asset depend on Vehicle or OptiFleet. Vehicle may become an external asset dimension/reference later.

16. ASSET LIFECYCLE

Suggested lifecycle: DRAFT ACTIVE FULLY_DEPRECIATED DISPOSED INACTIVE Use controlled transitions. Do not allow arbitrary status mutation. A disposed asset cannot continue normal depreciation.

17. CAPITALIZATION

Support controlled asset capitalization. Conceptual posting: Dr Fixed Asset Cr configured source/clearing/payable account Actual accounts resolved through Posting Rules/Account Mapping. If asset originates from an already-posted AP transaction, avoid double-recognition. Use source references and accounting semantics to prevent duplicate capitalization.

18. DEPRECIATION METHODS

At minimum support: STRAIGHT_LINE Only add additional methods such as: DECLINING_BALANCE if they can be implemented correctly without destabilizing OA4. Methods must be strategy/config driven rather than scattered conditionals. Do not implement jurisdiction-specific tax depreciation rules as general book depreciation.

19. DEPRECIATION SCHEDULE

Generate deterministic depreciation schedule using: capitalization date depreciation start policy acquisition/depreciable basis residual value useful life method period calendar Store sufficient calculation snapshot for audit. Historical posted depreciation must not change because asset defaults later change.

20. DEPRECIATION PRECISION

Use decimal-safe calculation. Ensure cumulative depreciation never exceeds depreciable basis: acquisition cost
- residual value Handle rounding deterministically. Final depreciation period should correct permissible rounding residual. Never use floating point as accounting authority.

21. DEPRECIATION RUN

Implement controlled depreciation run per accounting period. Concept: Eligible Assets ↓ Calculate ↓ Review ↓ Post ↓ Accounting Event ↓ Posting Engine Typical semantic posting: Dr Depreciation Expense Cr Accumulated Depreciation Prevent duplicate depreciation for same asset/period.

22. DEPRECIATION POSTING

Depreciation must validate: asset ACTIVE period OPEN schedule eligible not already posted account mappings valid dimensions valid tenant ownership entitlement authorization Posting and asset schedule state update must be atomic.

23. ASSET DISPOSAL

Support controlled disposal foundation. Minimum cases: sale scrap/write-off Calculate where applicable: asset cost accumulated depreciation net book value proceeds gain/loss Post through Accounting Core. Do not silently delete asset or historical depreciation.

24. FIXED ASSET ↔ GL RECONCILIATION

Provide reconciliation between: Asset Register cost Accumulated Depreciation Net Book Value and corresponding GL control accounts. Mismatch must be observable. Never silently modify either side to force reconciliation.

25. TAX PRINCIPLE

Implement configurable accounting tax foundation. Do NOT claim automatic compliance with all Indonesian tax regulations. Do NOT hardcode statutory rates as permanent business logic. Tax rules/rates must be effective-dated and configurable. Historical posted tax must preserve the rate/calculation snapshot used at posting time.

26. TAX CODE

Implement tenant-owned Tax Code. Minimum concepts: code name tax type rate calculation method effective_from effective_until status input/output classification recoverable/non-recoverable metadata where appropriate account role/mapping inclusive/exclusive behavior Use decimal-safe percentages.

27. TAX TYPES

Architecture should support generic classifications such as: INPUT_TAX OUTPUT_TAX WITHHOLDING OTHER Do not hardcode Indonesian tax form/report behavior into Accounting Core. Tenant configuration may label tax codes for local requirements.

28. EFFECTIVE-DATED TAX RATE

Changing a tax rate must not alter historical posted transactions. Tax calculation resolves rate by applicable transaction/posting policy date. Persist resolved: tax code rate tax base tax amount treatment with transaction financial snapshot.

29. TAX CALCULATION

Implement centralized Tax Calculation service. Support at minimum: exclusive tax inclusive tax tax exempt / zero rate where configuration permits Do not duplicate tax formulas in: AP Expense AR React Backend Tax service is authoritative.

30. TAX INTEGRATION WITH OA2/OA3

Extend existing AP/Expense/AR transaction lines minimally to use Tax Code. Do not redesign OA2/OA3. Expected flow: Transaction Line ↓ Tax Calculation ↓ Accounting Event ↓ Posting Rule ↓ Tax Account Mapping ↓ Posting Engine Existing transactions without Tax Code must retain previous behavior.

31. TAX POSTING

Conceptual examples only: Purchase: Dr Expense/Asset Dr Recoverable Input Tax Cr AP/Cash Sale: Dr AR/Cash Cr Revenue Cr Output Tax Actual accounts must be resolved through mappings. No hardcoded GL account numbers.

32. TAX REPORTING FOUNDATION

Provide basic accounting tax data/report foundation: tax code tax base tax amount input/output classification document reference transaction date posting date counterparty where applicable Do not build statutory tax filing or government submission.

33. MULTI-CURRENCY PRINCIPLE

Accounting Profile functional currency remains the reporting/accounting base currency. A transaction may use: transaction currency while financial books retain: functional/base currency amount. Every foreign-currency posting must preserve the exchange-rate snapshot used at posting time.

34. CURRENCY MASTER

Implement/extend Currency Master. Minimum: ISO currency code name decimal precision status Do not hardcode only IDR/USD. Use standard ISO currency identifiers where appropriate. Do not rely on symbol as identity.

35. FUNCTIONAL CURRENCY

Reuse OA1 Accounting Profile functional currency. Once financial postings exist, changing functional currency must not be a simple unrestricted update. Safest baseline: prevent functional currency change after financial activity unless a future controlled migration process exists. Do not attempt automatic historical currency conversion.

36. EXCHANGE RATE

Implement tenant-aware Exchange Rate. Minimum: from currency to currency / functional currency rate effective date rate type source metadata status Suggested rate types: SPOT DAILY MONTH_END MANUAL Do not integrate external FX provider in OA4. Manual/imported rates are sufficient foundation.

37. EXCHANGE RATE RESOLUTION

Implement centralized Exchange Rate Resolver. Given: tenant transaction currency functional currency rate date rate type/policy resolve deterministic rate. If currencies are identical: rate = 1 If required rate is missing: fail safely. Never silently assume rate = 1 for different currencies.

38. EXCHANGE RATE SNAPSHOT

At posting time preserve: transaction currency transaction amount functional currency exchange rate rate date rate type/source where relevant functional amount Historical journal must never dynamically recalculate using current rate.

39. JOURNAL MULTI-CURRENCY

Extend OA1 journal structures minimally and backward-compatibly. Functional-currency debit/credit remains authoritative for GL balancing. Where foreign currency applies, retain transaction-currency amounts and rate snapshot. Do not redesign journal architecture unnecessarily.

40. MULTI-CURRENCY AP

Extend OA2 Vendor Invoice/Payment to support transaction currency. Invoice recognition stores: foreign amount functional amount rate snapshot Payment may occur at a different exchange rate. Difference may create realized FX gain/loss. Use Posting Engine.

41. MULTI-CURRENCY AR

Extend OA3 Customer Invoice/Receipt similarly. Receipt at a different rate from invoice recognition may generate realized FX gain/loss. Do not modify original invoice exchange-rate snapshot.

42. REALIZED FX GAIN/LOSS

Implement realized FX difference for settled foreign-currency AP/AR where the existing architecture can support it safely. Concept: Original functional value vs Settlement functional value Difference → FX_GAIN or FX_LOSS account role Use Posting Rules/Account Mapping. Never force invoice historical functional amount to equal settlement value.

43. PARTIAL SETTLEMENT FX

For partial foreign-currency settlement: calculate realized FX only for the settled portion. Remaining outstanding retains appropriate original carrying basis according to implemented policy. Use deterministic decimal calculation. Explicitly test partial settlements.

44. UNREALIZED FX / REVALUATION

Implement foreign balance revaluation only if it can be completed safely within OA4. Concept: Open foreign monetary balance ↓ Closing Rate ↓ Revalued Functional Balance ↓ Unrealized Gain/Loss Adjustment If implemented:
- use dedicated accounting event;
- preserve original transactions;
- post adjustment journal;
- support reversal policy;
- prevent duplicate revaluation per scope/period. If safe implementation would materially expand OA4: document it as future extension. Do NOT fake support.

45. FX ACCOUNT ROLES

Add only required semantic roles: FX_GAIN FX_LOSS and, if implemented: UNREALIZED_FX_GAIN UNREALIZED_FX_LOSS Actual GL accounts remain tenant-configurable mappings.

46. CROSS-DOMAIN ACCOUNTING EVENTS

Add only required internal events. Examples: ASSET_CAPITALIZED DEPRECIATION_RECOGNIZED ASSET_DISPOSED TAX_RECOGNIZED only if separate event is actually necessary REALIZED_FX_GAIN_LOSS FX_REVALUATION if implemented Do not create duplicate events when existing AP/AR events can carry tax facts cleanly. These are internal events, not OA6 integration contracts.

47. PERIOD LOCKING

Reuse OA1 Period Guard. Period state must block unauthorized posting for: capitalization depreciation asset disposal tax-related posting FX settlement adjustment FX revaluation Budget itself is planning data and must not bypass accounting controls if it triggers any future financial action.

48. DOCUMENT NUMBERING

Reuse centralized numbering. Add only required document types, e.g.: BUDGET FIXED_ASSET DEPRECIATION_RUN ASSET_DISPOSAL Tax/FX should reuse source document numbers where possible. Do not create new numbering engines.

49. PERMISSIONS

Add only required atomic OA4 permissions. Examples: accounting.budget.view accounting.budget.manage accounting.budget.submit accounting.budget.approve accounting.asset.view accounting.asset.manage accounting.asset.capitalize accounting.asset.depreciation.run accounting.asset.depreciation.post accounting.asset.dispose accounting.asset.reconciliation.view accounting.tax.view accounting.tax.manage accounting.tax.report.view accounting.currency.view accounting.currency.manage accounting.exchange_rate.view accounting.exchange_rate.manage accounting.fx_revaluation.run Use equivalent existing codes when available. No role-name authorization.

50. DATA SCOPE

Reuse existing Data Scope: TENANT BRANCH BUSINESS_UNIT COST_CENTER and other existing scopes where applicable. Apply scope to: budgets budget actuals assets depreciation tax reports foreign currency transactions reconciliation exports Do not leak aggregate financial data outside authorized scope.

51. AUDIT

Reuse existing Audit infrastructure. Audit at minimum: Budget lifecycle/version changes Asset Category changes Asset capitalization Depreciation run/post Asset disposal Tax Code/rate changes Tax mapping changes Currency/rate changes FX adjustment/revaluation critical reports/exports Preserve before/after values for critical configuration where appropriate.

52. DATABASE INTEGRITY

Use PostgreSQL constraints where appropriate. Protect: cross-tenant relationships invalid budget period/account/dimension invalid asset basis/life/residual values duplicate depreciation per asset/period invalid tax effective dates invalid tax rates duplicate/conflicting exchange rates where policy disallows them invalid currency relationships duplicate FX adjustment/revaluation invalid posting references Application validation complements DB integrity.

53. CONCURRENCY

Explicitly protect/test: Budget approval/version activation race Asset capitalization double posting Concurrent depreciation runs Depreciation vs disposal Duplicate depreciation posting Tax configuration change vs transaction posting Exchange-rate update vs posting AP/AR settlement race with FX calculation Duplicate revaluation Use DB transactions/locking/constraints as appropriate.

54. REACT UI

Extend Accounting navigation by entitlement. Suggested: Accounting ├── Budget │ ├── Budgets │ └── Budget vs Actual │ ├── Fixed Assets │ ├── Asset Register │ ├── Asset Categories │ ├── Depreciation │ └── Reconciliation │ ├── Tax │ ├── Tax Codes │ └── Tax Transactions / Report │ └── Currency ├── Currencies ├── Exchange Rates └── Revaluation [only if implemented] Do not add OA5 menus.

55. FRONTEND CALCULATION POLICY

Frontend may display calculation previews for: budget variance depreciation tax currency conversion Backend remains authoritative. Do not use JavaScript floating-point calculation as accounting truth. Server must recalculate before persistence/posting.

56. BACKWARD COMPATIBILITY

Existing OA0–OA3 behavior must remain valid. Single-currency tenants must continue operating without mandatory FX setup. Transactions without Tax Code must retain previous behavior where valid. Tenants without: Budget Fixed Asset Tax Multi-Currency must not be forced to configure those modules. No OptiFleet dependency may be introduced.

57. OA4 STATUS FILE

Create/update: docs/status/OA4_STATUS.md Keep <= approximately 100 lines. Include only: Status Completed Batches Important Decisions Migrations Accounting Events Added Financial Invariants Tests Known Issues Remaining Next Phase Dependencies Do not rewrite previous docs unnecessarily.

58. TOKEN EFFICIENCY — STRICT

Repository state is authoritative. DO NOT print: complete files large diffs migration contents full schemas full API payloads full test logs dependency logs long accounting explanations previous phase summaries restatement of this prompt Do not narrate routine edits. Inspect only code required for CURRENT batch. Do not scan all OA4 domains repeatedly. Use existing OA1–OA3 abstractions. Do not perform speculative refactoring. Use OA4_STATUS.md as continuation memory. Progress output <= approximately 15 lines.

59. IMPLEMENTATION BATCHES

Execute sequentially: A — Budget + Version + Lines B — Budget vs Actual + Budget Tests C — Asset Category + Asset Register + Capitalization D — Depreciation Schedule + Run + Posting E — Asset Disposal + Fixed Asset ↔ GL Reconciliation F — Fixed Asset Tests G — Tax Code + Effective Rates + Tax Calculator H — AP/Expense/AR Tax Integration + Tax Posting I — Tax Tests J — Currency Master + Exchange Rate Resolver K — Journal/AP/AR Multi-Currency Extension L — Realized FX Gain/Loss M — Unrealized FX/Revaluation only if safely in scope N — OA4 React UI + Permissions + Audit O — Cross-Domain Financial/Concurrency/Security Tests P — OA0–OA3 Regression Q — Release Gate Finish each domain before expanding the next. Do not stop for approval between batches.

60. TARGETED VALIDATION

During each batch:
1. inspect relevant implementation;
2. implement;
3. run targeted tests;
4. fix failures;
5. update OA4_STATUS.md;
6. continue. Do not run full regression after every batch. Broad regression belongs to release gate.

61. CRITICAL BUDGET TESTS

Prove: Budget does not affect GL. Budget version history is preserved. Only valid/effective approved version is used. Cross-tenant account/dimension rejected. Budget Actual derives only from POSTED journals. Draft/unposted journal does not affect Actual. Variance calculation is deterministic. Data Scope applies to Budget vs Actual.

62. CRITICAL FIXED ASSET TESTS

Prove: Capitalization posts exactly once. Asset account resolved through mapping. Depreciation schedule is deterministic. Depreciation never exceeds depreciable basis. Rounding is handled deterministically. Duplicate asset-period depreciation prevented. Closed period blocks depreciation. Posted depreciation is immutable. Disposed asset stops normal depreciation. Disposal calculates NBV/gain/loss correctly. Fixed Asset Register reconciles to GL.

63. CRITICAL TAX TESTS

Prove: Tax rates are effective-dated. Historical posted tax does not change after rate change. Inclusive calculation is correct. Exclusive calculation is correct. Zero/exempt handling works where configured. Tax accounts are mapped, not hardcoded. AP tax works. Expense tax works. AR tax works. Closed period blocks tax-related posting. Cross-tenant Tax Code rejected.

64. CRITICAL MULTI-CURRENCY TESTS

Prove: Same currency resolves rate
1. Missing required FX rate fails safely. Foreign transaction stores rate snapshot. Changing rate master does not alter posted transaction. Journal remains balanced in functional currency. AP foreign invoice works. AR foreign invoice works. Foreign payment/receipt works. Realized FX gain/loss is correctly posted. Partial settlement FX is correct. Cross-tenant exchange rate rejected. Functional currency cannot be casually changed after posting exists. If revaluation implemented: duplicate period/scope revaluation prevented; revaluation preserves original transaction; reversal policy works.

65. TENANT / ENTITLEMENT / SECURITY TESTS

Tenant A must not access Tenant B: budgets assets depreciation tax codes tax transactions currencies/rates where tenant-owned FX adjustments reports/exports Test relevant: GET LIST FILTER CREATE UPDATE APPROVE POST REVERSE DISPOSE RUN EXPORT Also test: ACTIVE READ_ONLY SUSPENDED DISABLED for each OA4 module. Cross-tenant financial access is P0.

66. PRIOR PHASE REGRESSION

OA4 must not break: OA0 SaaS foundation OA1 Accounting Core/GL OA2 AP/Expense/Cash-Bank OA3 AR/Revenue Especially verify after Tax/Currency extensions: existing single-currency AP existing single-currency AR existing no-tax AP/AR Manual Journal GL Trial Balance AP ↔ GL AR ↔ GL Cash/Bank Existing behavior must remain backward-compatible.

67. PERFORMANCE

Inspect actual query risks for: Budget vs Actual Depreciation schedule/run Asset reconciliation Tax report Exchange-rate resolution Avoid N+1. Use indexes based on real query patterns. Do not add MongoDB. Do not create premature analytics projections.

68. RELEASE GATE

Before COMMIT READY verify: [ ] OA0–OA3 remain stable [ ] Budget lifecycle works [ ] Budget versioning works [ ] Budget does not affect GL [ ] Budget vs Actual uses posted GL [ ] Fixed Asset Register works [ ] Capitalization uses Posting Engine [ ] Depreciation works [ ] Duplicate depreciation prevented [ ] Disposal works [ ] Fixed Asset ↔ GL reconciles [ ] Tax configuration works [ ] Tax rates are effective-dated [ ] Historical tax snapshot preserved [ ] AP/Expense/AR tax integration works [ ] Currency Master works [ ] Exchange Rate Resolver works [ ] Rate snapshot preserved [ ] Functional currency GL remains balanced [ ] Multi-currency AP works [ ] Multi-currency AR works [ ] Realized FX works [ ] Partial settlement FX works [ ] Revaluation either works fully or is explicitly NOT IMPLEMENTED [ ] Period locking works [ ] Posted financial history remains immutable [ ] Tenant isolation passes [ ] RBAC/Data Scope passes [ ] Entitlement/READ_ONLY passes [ ] Concurrency tests pass [ ] Audit works [ ] React UI works [ ] Frontend production build passes [ ] Fresh migrations/seeds pass [ ] OA0–OA3 regression passes [ ] No OA5 functionality accidentally implemented [ ] No OptiFleet/external dependency introduced [ ] No P0/P1 issue remains

69. DEFINITION OF DONE

OA4 is COMMIT READY only when:
- Budget is complete and uses GL actuals;
- Fixed Asset financial posting uses Accounting Core;
- Fixed Asset reconciles to GL;
- Tax calculations are centralized/configurable/effective-dated;
- historical tax facts are preserved;
- Multi-Currency preserves transaction and functional amounts;
- exchange-rate snapshots are immutable historically;
- realized FX is correct;
- all financial posting uses OA1 Posting Engine;
- period locking and journal immutability remain enforced;
- tenant isolation passes;
- RBAC/Data Scope passes;
- entitlement/READ_ONLY passes;
- critical concurrency tests pass;
- frontend production build passes;
- migrations/seeds pass;
- prior-phase regression passes;
- OA4_STATUS.md is current;
- no fake PASS exists;
- no TODO/mock is counted as completed;
- OA5 has not started.

70. BLOCKER POLICY

Do not ask questions for ordinary implementation decisions. Use: CLAUDE.md architecture docs OA0–OA3 implementation repository conventions Prefer reuse over duplication. Stop only for a genuine owner-level blocker. If a validation cannot run: report NOT RUN with concise reason. Never convert NOT RUN into PASS.

71. PROGRESS OUTPUT

During implementation output only: OptiAccounting OA4 Progress Batch: <batch> Completed:
- ... Validation:
- ... Remaining:
- ... Blocker:
- none / ... Keep <= approximately 15 lines.

72. FINAL OUTPUT

When release gate completes output only: OPTIACCOUNTING OA4 — RELEASE GATE STATUS: COMMIT READY / NOT READY Completed:
- maximum 10 concise items Domain Integrity:
- Budget vs Actual: PASS/FAIL/NOT RUN
- Fixed Asset ↔ GL: PASS/FAIL/NOT RUN
- Depreciation: PASS/FAIL/NOT RUN
- Tax Calculation: PASS/FAIL/NOT RUN
- Tax Historical Snapshot: PASS/FAIL/NOT RUN
- Multi-Currency: PASS/FAIL/NOT RUN
- Realized FX: PASS/FAIL/NOT RUN
- FX Revaluation: PASS/FAIL/NOT RUN/NOT IMPLEMENTED Validation:
- Backend: PASS/FAIL/NOT RUN
- OA0–OA3 Regression: PASS/FAIL/NOT RUN
- Tenant Isolation: PASS/FAIL/NOT RUN
- RBAC/Data Scope: PASS/FAIL/NOT RUN
- Entitlement/READ_ONLY: PASS/FAIL/NOT RUN
- Concurrency: PASS/FAIL/NOT RUN
- Security: PASS/FAIL/NOT RUN
- Frontend Build: PASS/FAIL/NOT RUN
- Migration/Seed: PASS/FAIL/NOT RUN Critical Issues:
- none / ... Known Non-Blocking Issues:
- none / ... OA4_STATUS.md:
- UPDATED / NOT UPDATED Next Phase: OA5 — Financial Reporting, Closing & Reconciliation Suggested Commit: feat: implement budget fixed asset tax and multi-currency accounting Do not start OA5. Stop and wait for explicit instruction.
