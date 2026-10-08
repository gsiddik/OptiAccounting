# OPTIACCOUNTING — MASTER ARCHITECTURE & DEVELOPMENT FOUNDATION You are establishing the permanent architecture and development rules for a new product named: OptiAccounting This repository is independent from OptiFleet. OptiAccounting must be designed as:
1. A standalone multi-tenant Accounting SaaS product that can be sold and operated without OptiFleet.
2. An integration-ready Accounting platform that can integrate with OptiFleet.
3. A generic accounting platform that can later integrate with other systems such as ERP, POS, TMS, WMS, HRIS, CRM, e-commerce, banking, or custom enterprise applications.
4. A financially reliable system based on double-entry accounting and strong auditability.
5. A modular SaaS platform whose capabilities can be sold through modules, bundles, add-ons, feature entitlements, and tenant-specific commercial arrangements. This MASTER task establishes architecture and repository development rules only. DO NOT implement OA0–OA7 business features in this task. Target: OPTIACCOUNTING MASTER STATUS: ARCHITECTURE READY

1. EXISTING PRODUCT CONTEXT

OptiFleet already exists as a separate production-ready product. OptiFleet Phase 1–7 and Production Hardening & Go-Live are completed and committed in a different repository. Treat OptiFleet as an external system. DO NOT:
- copy OptiFleet into this repository;
- create runtime dependency on OptiFleet;
- access OptiFleet database directly;
- reference OptiFleet ORM/models;
- require OptiFleet for OptiAccounting startup;
- make OptiFleet-specific entities mandatory in Accounting Core. OptiAccounting must work perfectly without OptiFleet. Future integration must use stable APIs/events/contracts.

2. PRODUCT BOUNDARY

OptiAccounting owns financial truth. Primary domain ownership: Accounting Profile Fiscal Year Accounting Period Chart of Accounts Accounting Dimensions Cost Centers Journal General Ledger Accounts Payable Accounts Receivable Expense Cash & Bank Budget Fixed Asset Tax Configuration Currency Financial Reporting Closing Reconciliation Accounting Audit External systems own their operational truth. For example: OptiFleet owns: Vehicle Maintenance Work Order Inventory Quantity Stock Movement Procurement Operational Documents Tire Lifecycle Component Lifecycle Warranty Operational Data OptiAccounting must NEVER become the operational source of truth for those domains merely because an integration exists.

3. CORE ARCHITECTURE

Preferred stack: Backend: Laravel Frontend: ReactJS Primary transactional database: PostgreSQL Queue/cache: Redis Analytical database: MongoDB only when analytical workloads justify it in a later phase. Architecture style: Modular Monolith Do NOT introduce microservices, Kafka, Kubernetes, event streaming platforms, or distributed infrastructure unless a future requirement clearly justifies them. Design module boundaries cleanly enough that extraction remains possible later. PostgreSQL remains authoritative for all accounting transactions.

4. MULTI-TENANT SAAS

OptiAccounting is multi-tenant. Use shared database/shared schema unless repository constraints strongly justify otherwise. Every tenant-owned resource must be tenant-isolated. Recommended identity structure: users tenants tenant_users Do not permanently bind a user to exactly one tenant. A user may eventually belong to multiple tenants. Never trust tenant_id supplied by frontend requests. Tenant context must be resolved server-side. Cross-tenant data leakage is a P0 security defect.

5. SAAS PRODUCT MODEL

Design the architecture to support: Module Catalog Module Dependency Bundle Add-On Pricing Contract Subscription Tenant Entitlement Feature Entitlement Capacity Entitlement Do not implement all commercial functionality in MASTER. OA0 will implement the SaaS foundation. MASTER only defines the architectural contract.

6. MODULE ARCHITECTURE

Initial logical product capabilities: ACCOUNTING_CORE ACCOUNTING_AP ACCOUNTING_AR ACCOUNTING_EXPENSE ACCOUNTING_CASH_BANK ACCOUNTING_BUDGET ACCOUNTING_FIXED_ASSET ACCOUNTING_TAX ACCOUNTING_MULTI_CURRENCY ACCOUNTING_REPORTING ACCOUNTING_INTEGRATION ACCOUNTING_ANALYTICS Do not hardcode bundle composition. Bundles must be data-driven. Do not hardcode commercial availability inside controllers.

7. FEATURE ENTITLEMENT

Module entitlement and feature entitlement are separate concepts. Example: ACCOUNTING_CORE = ACTIVE Features: JOURNAL = ACTIVE GENERAL_LEDGER = ACTIVE OPENING_BALANCE = ACTIVE A future tenant may have: ACCOUNTING_AP = ACTIVE ACCOUNTING_AR = INACTIVE Backend authorization must enforce effective entitlement. Frontend visibility is not authorization.

8. EFFECTIVE ACCESS MODEL

Architect effective access around: Tenant Status AND Subscription State AND Module Entitlement AND Feature Entitlement AND User Status AND Permission AND Data Scope AND Resource Tenant Ownership AND Accounting Business Invariants Do not authorize by role name. Do not use patterns such as: if user.role == "accountant" Use atomic permissions.

9. RBAC

Use dynamic RBAC. Permission naming convention: resource.action Examples: accounting.journal.view accounting.journal.create accounting.journal.submit accounting.journal.approve accounting.journal.post accounting.journal.reverse accounting.coa.view accounting.coa.manage accounting.period.view accounting.period.manage accounting.report.view accounting.report.export Roles are tenant-configurable collections of permissions. Backend authorization is authoritative.

10. DATA SCOPE

Permission and Data Scope are different concepts. Architecture must support organizational/dimensional scopes such as: TENANT BRANCH BUSINESS_UNIT COST_CENTER OWN Extension-ready for other dimensions. Do not create independent authorization systems for every Accounting module.

11. ACCOUNTING PRINCIPLES

These are permanent protected invariants.
1. Double-entry accounting.
2. Every posted journal must balance: Total Debit = Total Credit
3. Monetary values use DECIMAL/NUMERIC. Never FLOAT.
4. Posted journal entries are immutable.
5. Corrections use reversal and/or adjustment entries.
6. Posting into CLOSED accounting periods is forbidden.
7. Historical financial transactions must remain traceable.
8. Used accounting master data must not be destructively deleted.
9. Journal source relationships must remain auditable.
10. Accounting reports must derive from authoritative posted accounting data.
11. Derived projections may be rebuilt.
12. Financial history must never be silently rewritten when configuration changes.
13. Tenant isolation applies to every accounting relationship.
14. Application configuration cannot override accounting invariants.

12. ACCOUNTING STANDARDS

Architecture must support tenant-selected accounting framework metadata. Examples: SAK_GENERAL SAK_EP SAK_EMKM CUSTOM Do NOT hardcode the Accounting engine around specific standard paragraph numbers. Do NOT claim automatic regulatory or accounting-standard compliance. Actual accounting policy, judgment, disclosure, tax treatment, and regulatory compliance remain dependent on tenant configuration and professional accounting judgment. Build a standards-capable engine, not a fake compliance engine.

13. ACCOUNTING PROFILE

Architecture must support a tenant Accounting Profile containing concepts such as: Accounting Framework Functional Currency Fiscal Year Configuration Accounting Policies Posting Configuration Period Locking Approval Policy Cutover Information Detailed implementation belongs to OA1.

14. CHART OF ACCOUNTS

COA must be: tenant-owned; hierarchical; configurable; version/history aware where required; independent from external systems. Platform may provide COA templates. When selected, the template creates tenant-owned accounts. Tenant journals must never depend directly on mutable shared platform accounts.

15. ACCOUNTING DIMENSIONS

Do not hardcode financial analysis exclusively into the COA. Support accounting dimensions. Minimum architectural concepts: Branch Business Unit Cost Center External integrations may introduce reference dimensions such as: Vehicle Work Order Workshop Warehouse Vendor Project Department Product Component Tire These must not create direct database dependencies on external systems.

16. JOURNAL & GENERAL LEDGER

Journal Entry is the authoritative accounting posting structure. General Ledger derives from POSTED journal lines. Do not create a separately editable General Ledger. Journal lifecycle foundation: DRAFT SUBMITTED APPROVED POSTED Alternatives: REJECTED CANCELLED Posted entries are immutable. Reversal creates a new linked journal.

17. SUBLEDGER PRINCIPLE

Future subledgers include: Accounts Payable Accounts Receivable Cash/Bank Fixed Asset Inventory Financial Interface Subledgers must reconcile to the General Ledger. Never allow each subledger to implement independent debit/credit logic without centralized posting control.

18. CENTRAL POSTING ENGINE

All automatic financial postings must eventually use a centralized Accounting Posting Engine. Concept: Business Transaction / Financial Event ↓ Accounting Event Gate ↓ Posting Rule ↓ Account Mapping ↓ Posting Engine ↓ Journal ↓ General Ledger Do not scatter debit/credit rules across controllers.

19. POSTING RULES

Posting behavior must be data/configuration-driven where appropriate. Posting rules describe accounting semantics. Example: Inventory Consumption Debit Role: MAINTENANCE_EXPENSE Credit Role: INVENTORY_ASSET Actual account numbers are resolved through Account Mapping. Do not hardcode: Debit 5110 Credit 1310 inside operational integration code.

20. SOURCE TRACEABILITY

Every automatically generated journal must be traceable to its source. Support generic source identity: source_system source_tenant_reference source_type source_id source_document_number source_event_id Do not require source IDs to be OptiFleet IDs.

21. STANDALONE OPERATION

OptiAccounting must support transactions without any external integration. Future standalone entry channels include: Manual Journal AP AR Expense Cash/Bank Import API External integration is optional. ACCOUNTING_INTEGRATION being disabled must not affect Accounting Core.

22. INTEGRATION ARCHITECTURE

External systems integrate through: API and/or Versioned Business Events Never through direct database access. Target architecture: External System ↓ Integration Connection ↓ Authentication ↓ Adapter ↓ Canonical Financial Event ↓ Accounting Event Gate ↓ Posting Rule ↓ Account Mapping ↓ Posting Engine ↓ Journal / GL

23. GENERIC INTEGRATION MODEL

OptiAccounting integration architecture must not be OptiFleet-specific. Support future adapters such as: OptiFleetAdapter ERPAdapter POSAdapter TMSAdapter WMSAdapter HRISAdapter ECommerceAdapter CustomAPIAdapter CSVImportAdapter Adapters translate external business facts into canonical financial events. Accounting Core must not depend on adapters.

24. CANONICAL FINANCIAL EVENTS

External systems send business facts. They do NOT instruct OptiAccounting which accounts to debit or credit. Good: inventory.issued quantity unit_cost total_cost currency warehouse product cost dimensions Bad: debit_account = 5110 credit_account = 1310 Account determination belongs to OptiAccounting.

25. EVENT ENVELOPE

Future integration contract must support a stable envelope concept containing: event_id event_type schema_version source_system source_tenant_reference occurred_at correlation_id source dimensions payload Do not implement all event schemas during MASTER. Define the architectural standard only.

26. EVENT VERSIONING

Integration contracts must be versioned. Prefer: event_type + schema_version Example: event_type: inventory.issue.posted schema_version: 1.0 Backward-compatible additive changes may remain within a compatible version policy. Breaking changes require a new major contract version.

27. IDEMPOTENCY

External event processing must eventually support: UNIQUE(source_system, event_id) or equivalent. At-least-once delivery is expected. Receiver must be idempotent. Do not design around distributed exactly-once transactions.

28. TRANSACTIONAL OUTBOX

Where OptiAccounting emits integration events/callbacks, use Transactional Outbox or equivalent reliable pattern. Do not perform critical remote HTTP operations inside financial database transactions. Likewise, external systems are expected to use reliable delivery patterns.

29. FAILURE ISOLATION

External system outage must not corrupt Accounting Core. Integration processing failure must be isolated and observable. Likewise: OptiAccounting must not assume an external system is always available. Integration should support: retry backoff dead-letter handling reconciliation manual authorized retry Detailed implementation belongs to OA6.

30. EXTERNAL TENANT MAPPING

Never assume: External Tenant ID = OptiAccounting Tenant ID Use explicit Integration Connection / Tenant Mapping. Example: OptiFleet Tenant 145 ↔ OptiAccounting Tenant 29 Connection credentials determine authorized target tenant. Do not trust arbitrary tenant identifiers from integration payloads.

31. EXTERNAL DIMENSION REGISTRY

Architecture should support generic external references without duplicating entire external systems. Concept: external_dimensions source_system source_tenant_reference entity_type external_id code name status metadata Examples: VEHICLE WORK_ORDER WORKSHOP WAREHOUSE VENDOR PRODUCT PROJECT COMPONENT TIRE Do not create foreign keys into external databases.

32. INTEGRATION CONNECTION

Future connection lifecycle: DRAFT CONFIGURING READY ACTIVE PAUSED ERROR DISCONNECTED Connection state is independent from Accounting subscription state. Example: Accounting Subscription = ACTIVE OptiFleet Connection = PAUSED Accounting must remain usable manually.

33. CUTOVER

External systems may contain historical transactions before integration. Never automatically back-post all historical transactions when a connection becomes active. Architecture must support: START_FROM_CUTOVER_DATE OPENING_BALANCE_ONLY HISTORICAL_BACKFILL Safe default: OPENING_BALANCE_ONLY + future events from cutover date Historical backfill requires explicit workflow.

34. REACTIVATION GAP

If an integration is paused/disconnected and later reactivated: do not silently post all missed events. Support future policies such as: IGNORE_GAP BACKFILL_GAP START_NEW_CUTOVER REQUIRES_REVIEW Safe default: REQUIRES_REVIEW

35. INTEGRATION RECONCILIATION

Integration must eventually support reconciliation between: external source events and OptiAccounting received/processed events Potential results: MATCHED MISSING DUPLICATE FAILED REVERSED AMOUNT_MISMATCH Detailed implementation belongs to OA6.

36. OPTIFLEET INTEGRATION BOUNDARY

OptiFleet is the first planned integration but not a dependency. Expected future OptiFleet event families include: Inventory Procurement Maintenance Work Order Tire Component Warranty Examples: inventory.received inventory.issued inventory.returned inventory.adjusted procurement.goods_received maintenance.external_cost.confirmed tire.installed tire.scrapped component.installed component.disposed warranty.recovery.confirmed These examples establish architectural direction only. Do NOT implement them during MASTER.

37. OPTIFLEET SOURCE OF TRUTH

For OptiFleet integration: OptiFleet owns: inventory quantity; inventory operational valuation source; maintenance transactions; work orders; procurement operational documents; vehicle; tire lifecycle; component lifecycle; warranty operational transactions. OptiAccounting owns: financial recognition; journal; general ledger; AP; AR; cash/bank; accounting period; financial statements. Never create dual financial truth.

38. OTHER APPLICATION INTEGRATION

Do not use names such as: optifleet_vehicle_id inside Accounting Core tables. Prefer generic external references. OptiAccounting must be capable of integrating another application without database redesign.

39. API ARCHITECTURE

Use versioned REST APIs. Example convention: /api/v1/... Separate: platform/superadmin APIs tenant application APIs integration APIs Integration endpoints must use machine-to-machine authentication. Do not use human username/password authentication for background integrations.

40. MACHINE-TO-MACHINE SECURITY

Architecture must support secure service authentication. Preferred future option: OAuth2 Client Credentials or another strong revocable service credential mechanism supported by the implementation. Credentials must be: scoped revocable rotatable securely stored never logged Integration authorization must resolve tenant from trusted connection identity.

41. WEBHOOK SECURITY

Future outbound/inbound webhooks must support: signature verification timestamp validation replay protection idempotency connection resolution schema validation Never trust webhook tenant_id blindly.

42. CONFIGURATION ARCHITECTURE

Tenant-configurable financial behavior must use controlled configuration. Avoid arbitrary executable tenant scripts. No: eval() tenant PHP code tenant JavaScript execution raw SQL configuration Use validated declarative configuration.

43. CONFIGURATION VERSIONING

Financially meaningful configuration changes should preserve historical behavior. Examples: Posting Rules Account Mappings Report Mappings Accounting Policies Where necessary use: DRAFT PUBLISHED ARCHIVED plus: version effective_from effective_to Historical posted journals never change because configuration changes later.

44. DOCUMENT NUMBERING

Architecture must support centralized configurable document numbering. Future document types may include: JOURNAL AP_INVOICE AR_INVOICE PAYMENT RECEIPT EXPENSE FIXED_ASSET ADJUSTMENT Number generation must eventually be: unique transactional concurrency-safe immutable after issuance Do not build separate numbering engines per module.

45. WORKFLOW

Financial workflows may be configurable but protected invariants remain system-controlled. Examples: Journal approval Vendor Invoice approval Expense approval Payment approval Period reopen Tenant workflow must never permit: unbalanced journal posting; POSTED → DRAFT; posting into CLOSED period; cross-tenant approval; unauthorized financial mutation.

46. SEGREGATION OF DUTIES

Architecture must support maker-checker / segregation-of-duties policies. Examples: creator != approver invoice creator != payment approver Do not hardcode specific role names. Use permissions/policies/workflow rules.

47. AUDIT

Accounting requires strong auditability. Audit architecture must cover: authentication/security-sensitive actions; tenant/module/entitlement changes; Accounting configuration; COA changes; posting rules; account mappings; journal lifecycle; reversal; period close/reopen; AP/AR/payment actions; integration processing; manual retries; reconciliation; critical exports. Audit records must be tenant-safe and append-oriented where appropriate.

48. DELETION POLICY

Avoid hard delete for financially relevant or historically referenced data. Prefer: ACTIVE INACTIVE ARCHIVED VOID CANCELLED REVERSED depending on domain semantics. Never destroy posted financial history.

49. MONEY & CURRENCY

Never use floating-point values for money. Architecture must distinguish: transaction currency transaction amount functional currency functional amount exchange rate Multi-currency implementation belongs to OA4, but OA1 data structures must not make future support impossible.

50. DATE SEMANTICS

Do not collapse all financial dates into created_at. Architecture must distinguish where relevant: document_date transaction_date posting_date due_date payment_date created_at updated_at Timezone handling must be explicit and tenant/business-date aware.

51. TAX

Tax must be a separate configurable domain. Do not scatter Indonesian tax formulas throughout AP/AR controllers. Future architecture should support: tax_codes tax_rates tax_account_mappings effective dates Detailed tax implementation belongs to OA4.

52. REPORTING

Financial reports derive from posted accounting data. Core future reports: Trial Balance General Ledger Statement of Financial Position Profit & Loss Cash Flow AP Aging AR Aging Do not implement reports in MASTER. Reporting architecture must not depend only on account-code prefixes. Use configurable report/account mapping where appropriate.

53. ANALYTICS

PostgreSQL remains financial source of truth. MongoDB may later be used for analytical projections. MongoDB must NEVER become: General Ledger Journal source of truth AP source of truth AR source of truth Cash/Bank source of truth OA7 will decide analytical projections based on actual requirements.

54. AI / INTELLIGENCE

Any future financial intelligence must be advisory. AI must never autonomously: post journals; approve invoices; approve payments; reopen periods; modify GL; change accounting policy. Anomaly detection is not automatically fraud detection.

55. REPOSITORY AS SOURCE OF TRUTH

The repository is persistent development memory. Chat context is only execution control. Create concise authoritative architecture documentation under: docs/architecture/ Required: SYSTEM_ARCHITECTURE.md ACCOUNTING_PRINCIPLES.md SAAS_ARCHITECTURE.md MODULE_CATALOG.md INTEGRATION_ARCHITECTURE.md SECURITY_INVARIANTS.md ROADMAP.md Keep documents concise. Do not create documentation for obvious framework behavior.

56. CLAUDE.md

Create root: CLAUDE.md Keep it approximately <= 200 lines. It must contain permanent development rules only. Include at minimum: repository source-of-truth policy; architecture document locations; Laravel conventions; React conventions; tenant isolation; dynamic RBAC; entitlement enforcement; money precision; double-entry invariant; journal immutability; period locking; reversal policy; integration boundary; no direct external DB access; testing policy; migration policy; status-file policy; token/output efficiency rules. Do not turn CLAUDE.md into complete product documentation.

57. PHASE STATUS SYSTEM

Create: docs/status/ Future phase files: OA0_STATUS.md OA1_STATUS.md OA2_STATUS.md OA3_STATUS.md OA4_STATUS.md OA5_STATUS.md OA6_STATUS.md OA7_STATUS.md PROD_STATUS.md Do not fill future status files with fabricated implementation status. Create templates only if useful. Each active status file should remain <= 100 lines.

58. DEVELOPMENT ROADMAP

Establish this roadmap: OA0 — Standalone SaaS Foundation OA1 — Accounting Core & General Ledger OA2 — Accounts Payable, Expense & Cash/Bank OA3 — Accounts Receivable & Revenue OA4 — Budget, Fixed Asset, Tax & Multi-Currency OA5 — Financial Reporting, Closing & Reconciliation OA6 — Integration Platform & OptiFleet Connector OA7 — Analytics & Management Accounting PROD — Production Hardening & Go-Live Do not implement these phases during MASTER.

59. OA0 BOUNDARY

OA0 will implement: Authentication Tenant Tenant Membership Superadmin/Tenant Portal Module Catalog Bundle Commercial Foundation Subscription Entitlement Feature Entitlement RBAC Data Scope Organization Audit Application Infrastructure MASTER must leave clear architecture for OA0 but not implement its business scope.

60. OA1 BOUNDARY

OA1 will implement: Accounting Profile Fiscal Year Accounting Period COA Cost Center Dimensions foundation Journal Reversal Posting Engine Posting Rules Account Mapping Opening Balance General Ledger Trial Balance Do not implement OA1 during MASTER.

61. OA2 BOUNDARY

OA2 will implement: Vendor AP Vendor Invoice Payment Payment Allocation Expense Cash/Bank AP Aging relevant reconciliation Do not implement OA2 during MASTER.

62. OA3 BOUNDARY

OA3 will implement: Customer AR Customer Invoice Credit/Debit Note Receipt Receipt Allocation AR Aging Revenue foundation Do not implement OA3 during MASTER.

63. OA4 BOUNDARY

OA4 will implement: Budget Fixed Asset Depreciation Tax Configuration Multi-Currency Do not implement OA4 during MASTER.

64. OA5 BOUNDARY

OA5 will implement: Financial Statements Advanced GL/Trial Balance Period Closing Year-End foundation Reconciliation Report Mapping Comparative Reporting Do not implement OA5 during MASTER.

65. OA6 BOUNDARY

OA6 will implement generic integration infrastructure first, then OptiFleet adapter. Includes: Integration Connections Service Authentication External Tenant Mapping External Dimensions Canonical Financial Events Event Gate Idempotency Adapter Framework Webhook/Callback Retry Dead Letter Reconciliation OptiFleet Adapter Do not implement OA6 during MASTER.

66. OA7 BOUNDARY

OA7 will implement: Accounting Analytics Management Accounting Financial KPI Cost Analysis Budget Analysis Financial Analytical Projection Do not implement OA7 during MASTER.

67. PRODUCTION BOUNDARY

Production Hardening is a release gate, not a feature phase. It will validate: financial integrity; tenant isolation; security; concurrency; performance; backup/restore; DR; queue reliability; scheduler reliability; integration reliability; reconciliation; observability; CI/CD; staging; UAT; production readiness. Do not claim production readiness during MASTER.

68. DATABASE PRINCIPLES

Use PostgreSQL relational constraints wherever appropriate. Future implementation should favor: foreign keys unique constraints check constraints transactional integrity proper indexes over frontend-only validation. Use JSONB only where configuration variability justifies it. Do not put core financial ledger structure into arbitrary JSON blobs.

69. MIGRATION POLICY

Once a phase is committed/released: do not rewrite historical migrations merely to make later development cleaner. Use additive migrations. Production data compatibility has priority over aesthetic migration history.

70. API PRINCIPLES

Use thin controllers. Business logic belongs in domain/application services. Avoid: fat controllers business logic in React duplicate accounting logic authorization only in UI raw SQL scattered across controllers Keep boundaries explicit.

71. FRONTEND PRINCIPLES

React frontend consumes backend-authoritative capabilities. Frontend must respect: tenant context module entitlement feature entitlement permission read-only state workflow state But backend remains authoritative. Use reusable: route guards capability checks form components table/filter patterns error/loading/empty states Do not duplicate authorization logic inconsistently.

72. TESTING PRINCIPLES

Every future phase must include: unit tests where appropriate; feature/integration tests; tenant isolation tests; permission tests; financial invariant tests; targeted concurrency tests; regression tests. Never claim PASS unless the test was actually run. Allowed status: PASS FAIL NOT RUN No fake validation.

73. SECURITY PRINCIPLES

Treat as release-critical: cross-tenant leakage; authorization bypass; entitlement bypass; financial mutation bypass; journal tampering; period lock bypass; duplicate posting; credential leakage; integration tenant spoofing; webhook forgery; event replay vulnerabilities. Secrets must never be committed or logged.

74. TOKEN EFFICIENCY — STRICT

Repository state is authoritative. During this MASTER task: DO NOT: print complete files; print large diffs; print full documentation contents; print full schemas; print dependency installation logs; print full test logs; repeat this specification; explain standard Laravel/React concepts; narrate every file edit; produce large architecture essays in chat. Write durable details into repository documentation. Chat output should only summarize progress/results.

75. FUTURE PHASE TOKEN POLICY

Future prompts must use: CLAUDE.md docs/architecture/ docs/status/{CURRENT_PHASE}_STATUS.md instead of restating previous architecture. When starting a phase:
1. Read CLAUDE.md.
2. Read only architecture documents relevant to that phase.
3. Read previous status file if necessary.
4. Inspect relevant code.
5. Implement only current phase.
6. Use targeted tests during batches.
7. Run broader regression only at release gate. Do not scan the entire repository repeatedly without reason.

76. CONTEXT CONSERVATION

When Claude context becomes high:
1. finish current logical operation;
2. run targeted validation;
3. update current status document;
4. continue from repository state. Never reconstruct implementation state from chat history.

77. MASTER TASK IMPLEMENTATION

For this MASTER task only: A. Inspect current repository. B. Determine whether it is:
- empty;
- partially initialized;
- already contains relevant application foundation. C. Preserve useful existing work. D. Establish only the minimum repository structure required to make future development deterministic. E. Create/update CLAUDE.md. F. Create concise architecture documents. G. Create roadmap/status structure. H. If the repository is empty and framework initialization is necessary for architecture readiness, initialize only the minimum Laravel/React project structure required. I. Do not implement Accounting business modules. J. Do not implement OA0.

78. ARCHITECTURE DECISION POLICY

Before creating a new abstraction: inspect whether an equivalent already exists. Reuse before duplicating. Do not introduce unnecessary: repositories; service interfaces; DTO layers; event buses; packages; microservices; generic frameworks; merely for architectural sophistication. Prefer the simplest structure that preserves domain boundaries and future extensibility.

79. NAMING

Use OptiAccounting as the product name. Avoid embedding OptiFleet naming into Accounting Core. Use generic names such as: integration_connections external_events external_dimensions external_source_links rather than: optifleet_connections optifleet_events optifleet_vehicle_links OptiFleet-specific naming belongs only inside the future OptiFleet adapter.

80. ARCHITECTURE VALIDATION

Before declaring MASTER complete, verify architecture documentation consistently answers:
1. Can OptiAccounting run without OptiFleet?
2. Can OptiAccounting be sold independently?
3. Can OptiFleet integrate without database sharing?
4. Can another ERP/POS/TMS integrate without redesigning Accounting Core?
5. Is financial truth owned only by OptiAccounting?
6. Are operational truths kept outside Accounting Core?
7. Is double-entry protected?
8. Are posted journals immutable?
9. Is tenant isolation explicit?
10. Are module and feature entitlements supported?
11. Is integration versioned and idempotent?
12. Is cutover/history behavior defined?
13. Are future phases clearly separated?
14. Are protected invariants documented?
15. Can future Claude sessions continue from repository without needing this full prompt again? Fix architecture inconsistencies before completion.

81. MASTER DEFINITION OF DONE

MASTER is complete only when: [ ] Repository inspected [ ] Existing useful work preserved [ ] Product boundary documented [ ] Standalone SaaS architecture documented [ ] Accounting principles documented [ ] Multi-tenancy strategy documented [ ] Module architecture documented [ ] RBAC/data scope principles documented [ ] Financial invariants documented [ ] Posting architecture documented [ ] Integration architecture documented [ ] Generic adapter strategy documented [ ] OptiFleet boundary documented [ ] Other-system integration supported architecturally [ ] Event versioning/idempotency strategy documented [ ] Cutover strategy documented [ ] Security invariants documented [ ] OA0–OA7 roadmap documented [ ] Production Hardening boundary documented [ ] CLAUDE.md created [ ] docs/status structure established [ ] No OA0–OA7 business implementation accidentally started [ ] No unnecessary architecture introduced [ ] Repository can serve as source of truth for future prompts

82. MASTER OUTPUT

During work output at most: OptiAccounting MASTER Progress Completed:
- ... Validation:
- ... Remaining:
- ... Blocker:
- none / ...

83. FINAL RESPONSE

When complete output only: OPTIACCOUNTING MASTER — ARCHITECTURE GATE STATUS: ARCHITECTURE READY / NOT READY Established:
- maximum 8 concise items Standalone Product:
- PASS/FAIL Multi-Tenant SaaS:
- PASS/FAIL Accounting Invariants:
- PASS/FAIL Module/Entitlement Architecture:
- PASS/FAIL Generic Integration Architecture:
- PASS/FAIL OptiFleet Integration Boundary:
- PASS/FAIL Other Application Integration:
- PASS/FAIL Security Architecture:
- PASS/FAIL Roadmap:
- PASS/FAIL Repository Development Memory:
- CLAUDE.md: PASS/FAIL
- Architecture Docs: PASS/FAIL
- Status Structure: PASS/FAIL Critical Issues:
- none / ... Owner Decisions Required:
- none / ... Next Phase: OA0 — Standalone SaaS Foundation Suggested Commit: chore: establish OptiAccounting master architecture Do not start OA0. Stop and wait for explicit instruction.
