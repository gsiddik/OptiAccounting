# OPTIACCOUNTING OA0 — STANDALONE SAAS FOUNDATION Implement: OA0 — Standalone SaaS Foundation Target: OPTIACCOUNTING OA0 STATUS: COMMIT READY

1. SOURCE OF TRUTH

The repository is authoritative. MASTER architecture has already been completed and committed. Before implementation:
1. Read root CLAUDE.md.
2. Read only architecture documents relevant to OA0 under: docs/architecture/
3. Inspect the existing repository structure and implementation.
4. Preserve completed MASTER work.
5. Check docs/status/OA0_STATUS.md if it already exists. Do NOT reconstruct architecture from chat. Do NOT redesign MASTER decisions unless an actual implementation blocker or contradiction is found. If a contradiction exists:
- document it concisely;
- choose the safest backward-compatible solution where possible;
- stop only if owner decision is genuinely required.

2. PHASE OBJECTIVE

Build the standalone SaaS foundation required for OptiAccounting to operate independently from OptiFleet or any other external application. At the end of OA0 the system must have a functional foundation for: Platform/Superadmin Tenant Authentication Tenant Membership Organization Dynamic RBAC Data Scope Module Catalog Module Dependency Bundle Subscription Module Entitlement Feature Entitlement Capacity Entitlement foundation Tenant lifecycle/access enforcement Audit Application infrastructure Platform Portal Tenant Portal OA0 must establish reusable SaaS infrastructure for OA1–OA7. Do NOT implement Accounting business transactions yet.

3. STRICT PHASE BOUNDARY

IN SCOPE:
- Application foundation
- Authentication
- Platform/Superadmin scope
- Tenant Management
- Tenant Membership
- Tenant status/lifecycle foundation
- Dynamic RBAC
- Atomic Permission
- Data Scope
- Organization foundation
- Module Catalog
- Module Dependency
- Bundle foundation
- Subscription foundation
- Module Entitlement
- Feature Entitlement
- Capacity Entitlement foundation
- Effective Access/Capability Resolver
- Audit Trail
- Platform Portal foundation
- Tenant Portal foundation
- Docker/development runtime
- PostgreSQL
- Redis
- Backend/frontend test foundation
- Seeds required for OA0
- API foundation OUT OF SCOPE:
- Accounting Profile
- Fiscal Year
- Accounting Period
- Chart of Accounts
- Journal
- Journal Lines
- Posting Engine
- General Ledger
- Trial Balance
- Opening Balance
- AP
- AR
- Expense transactions
- Cash/Bank transactions
- Budget
- Fixed Asset
- Tax engine
- Multi-Currency accounting
- Financial Statements
- Accounting Closing
- Accounting Reconciliation
- OptiFleet integration
- Generic financial event integration
- MongoDB analytics
- Accounting Intelligence Those belong to later phases. Do not start OA1.

4. APPLICATION ARCHITECTURE

Follow MASTER architecture. Expected stack unless repository already contains an approved equivalent: Backend: Laravel Frontend: ReactJS Transactional database: PostgreSQL Queue/cache: Redis Architecture: Modular Monolith Do not introduce microservices or unnecessary infrastructure. Keep Platform SaaS concerns separated from future Accounting domain concerns.

5. MULTI-TENANCY

Implement shared-database/shared-schema multi-tenancy according to MASTER architecture. Core identity model should support: users tenants tenant_users A user may belong to multiple tenants. Do NOT permanently model: users.tenant_id as the only tenant relationship. Tenant context must be resolved server-side. Never trust tenant_id from request payload/query parameters as authorization authority. All tenant-owned resources must be protected against cross-tenant access. Implement reusable tenant-aware: middleware/context policies/authorization query/service patterns Avoid manually repeating tenant checks inconsistently across controllers.

6. TENANT

Implement Tenant Management foundation. Minimum tenant concepts:
- unique identifier
- code/slug where appropriate
- legal/display name
- status
- timezone
- default locale foundation
- default currency metadata foundation
- contact metadata where appropriate
- created/updated metadata Recommended lifecycle: DRAFT ACTIVE SUSPENDED INACTIVE TERMINATED Use repository conventions if MASTER defines different names. Do not overload tenant lifecycle with Accounting setup status. Accounting setup belongs to OA1.

7. TENANT MEMBERSHIP

Implement tenant membership separately from global user identity. Support: user tenant membership status tenant-specific access tenant-specific role assignment Recommended membership lifecycle: INVITED ACTIVE SUSPENDED INACTIVE Do not hardcode one role per user. Architecture must allow multiple role assignments where appropriate.

8. AUTHENTICATION

Implement secure application authentication using the framework-supported architecture already selected by the repository. Support at minimum: login logout authenticated session/token handling current user current tenant context tenant switching when user belongs to multiple tenants Apply: rate limiting secure credential handling session/token invalidation inactive-user enforcement inactive-membership enforcement Do not invent custom cryptography. Do not expose secrets in logs.

9. PLATFORM VS TENANT SCOPE

Maintain two logical application scopes: A. Platform/Superadmin Portal B. Tenant Portal They may share the same Laravel backend and React application architecture if appropriate. Platform scope manages SaaS resources. Tenant scope manages tenant-owned resources. Do not create two unrelated authentication systems. Platform privileges must not automatically grant tenant operational context without explicit authorized behavior.

10. DYNAMIC RBAC

Implement dynamic RBAC. Core concepts: roles permissions role_permissions tenant role assignment Use atomic permission codes: resource.action Do not authorize using hardcoded role names. Bad: if role == "Superadmin" Good: permission/policy/capability check Platform-level authorization may use platform-scoped permissions but must remain permission-based. Seed sensible initial roles only as bootstrap data. Seeded roles must not become authorization logic.

11. PERMISSION FOUNDATION

Create permission registry capable of supporting OA0–OA7. OA0 only needs to seed permissions required by current phase. Examples: platform.tenant.view platform.tenant.create platform.tenant.update platform.tenant.status.manage platform.module.view platform.module.manage platform.bundle.view platform.bundle.manage platform.subscription.view platform.subscription.manage platform.entitlement.view platform.entitlement.manage access.user.view access.user.manage access.role.view access.role.manage access.permission.view access.scope.manage organization.view organization.manage audit.view Do not prematurely seed hundreds of speculative OA1–OA7 permissions. Later phases add permissions additively.

12. DATA SCOPE

Implement reusable Data Scope foundation. Minimum scopes: TENANT BRANCH BUSINESS_UNIT OWN Architecture must be extension-ready for: COST_CENTER WORKSHOP WAREHOUSE other future dimensions Permission answers: "What may this user do?" Data Scope answers: "To which records may they do it?" Keep them separate.

13. ORGANIZATION FOUNDATION

Implement only the organization structure required for a standalone Accounting SaaS foundation. Minimum: Tenant └── Branch └── Business Unit where appropriate Support tenant-owned: branches business_units Allow future OA1 accounting dimensions/cost centers to reference organizational structure without schema redesign. Do NOT implement Cost Center accounting behavior yet. Do not force every tenant to use multiple branches/business units.

14. MODULE CATALOG

Implement data-driven Module Catalog. Initial module codes: ACCOUNTING_CORE ACCOUNTING_AP ACCOUNTING_AR ACCOUNTING_EXPENSE ACCOUNTING_CASH_BANK ACCOUNTING_BUDGET ACCOUNTING_FIXED_ASSET ACCOUNTING_TAX ACCOUNTING_MULTI_CURRENCY ACCOUNTING_REPORTING ACCOUNTING_INTEGRATION ACCOUNTING_ANALYTICS Modules must not be hardcoded into frontend authorization logic. Module metadata should support concepts such as: code name description status commercial availability sort/order metadata Use MASTER architecture as authority.

15. MODULE DEPENDENCY

Implement dependency management. Must support: direct dependencies transitive dependency validation reverse dependency validation circular dependency prevention Activation must fail safely when required dependencies are missing. Deactivation must detect active dependent modules. Do not silently disable dependent modules unless explicitly designed and authorized. Example direction: ACCOUNTING_AP may require ACCOUNTING_CORE But dependency configuration must be data-driven.

16. FEATURE ENTITLEMENT

Module and feature entitlement are separate. Implement feature catalog/entitlement foundation capable of later supporting: ACCOUNTING_CORE ├── JOURNAL ├── GENERAL_LEDGER ├── OPENING_BALANCE └── ACCOUNTING_CONFIGURATION Do not implement these Accounting features yet. OA0 implements entitlement infrastructure only. Feature entitlement must be enforceable server-side.

17. CAPACITY ENTITLEMENT

Implement generic capacity/limit foundation. Examples: USER_LIMIT BRANCH_LIMIT BUSINESS_UNIT_LIMIT Architecture must support future limits without schema redesign. Capacity checks must be server-authoritative. Do not add speculative financial transaction limits unless required.

18. BUNDLE FOUNDATION

Implement data-driven bundles. Concepts: bundles bundle_modules optional bundle feature mappings if justified by MASTER Bundle composition must not be hardcoded. Bundles provide commercial packaging. Effective tenant access must ultimately resolve to tenant entitlements, not directly to frontend bundle names. Keep bundle implementation minimal but functional.

19. COMMERCIAL BOUNDARY

OA0 needs enough commercial foundation to support standalone SaaS entitlement. Do not build a large billing engine unless MASTER explicitly assigned it to OA0. Implement only what is necessary for: tenant subscription module entitlement feature entitlement effective access If Contract/Pricing/Billing implementation belongs to later commercial expansion according to repository architecture, preserve that boundary. Do not invent invoice/payment SaaS billing functionality merely to complete OA0.

20. SUBSCRIPTION

Implement subscription foundation. Minimum concepts: tenant subscription status effective/start date expiry/end date source/package reference where applicable Recommended statuses: PENDING ACTIVE PAST_DUE SUSPENDED EXPIRED CANCELLED Follow MASTER if already defined differently. Subscription state must participate in effective access.

21. MODULE ENTITLEMENT

Implement tenant module entitlement. Minimum concepts: tenant module state source effective_from effective_until Suggested sources: BUNDLE ADD_ON CUSTOM_CONTRACT MANUAL_OVERRIDE Suggested states: ACTIVE READ_ONLY SUSPENDED DISABLED If MASTER has already defined semantics, use them. Historical entitlement records must remain auditable. Do not delete tenant financial data when an entitlement expires. Actual financial read-only behavior will become important from OA1 onward.

22. FEATURE ENTITLEMENT DATA MODEL

Implement tenant feature entitlement independently from module entitlement. A feature cannot become effectively usable when its parent module is unavailable. Effective feature access must evaluate: module entitlement AND feature entitlement AND other access rules Do not duplicate module state into every feature row unnecessarily.

23. EFFECTIVE ACCESS RESOLVER

Implement one reusable server-side capability/access resolver. Conceptually: Tenant Allowed AND Subscription Allowed AND Module Entitlement Allowed AND Feature Entitlement Allowed where applicable AND User Active AND Tenant Membership Active AND Permission Granted AND Data Scope Allowed AND Resource Tenant Match Do not duplicate this decision tree across controllers. Design it so OA1–OA7 can reuse it. The resolver must distinguish reasons for denial where useful without leaking sensitive information.

24. READ-ONLY FOUNDATION

Entitlement architecture must support READ_ONLY. READ_ONLY means: read operations may remain available according to policy; mutating operations are denied. Implement reusable mutation gating. Do not wait until Accounting modules exist to invent read-only semantics. OA1+ will apply it to financial resources.

25. ENTITLEMENT EXPIRY

Provide deterministic handling for effective_from/effective_until. Use business-safe date/time handling. Expired entitlement must not silently remain writable. Do not delete historical records when access expires. Ensure scheduled or request-time evaluation cannot disagree unpredictably.

26. AUDIT

Implement reusable Audit Trail foundation. Audit at minimum: tenant lifecycle changes user/membership changes role changes permission changes data scope changes module changes module dependency changes bundle changes subscription changes module entitlement changes feature entitlement changes capacity changes critical platform configuration Capture where appropriate: tenant actor action resource type resource id before/after summary or structured change timestamp request/correlation metadata Do not store secrets in audit payloads.

27. SUPERADMIN PORTAL

Implement functional Platform/Superadmin UI foundation. Minimum navigation: Dashboard Tenant Management
- Tenants
- Tenant Membership/Access where appropriate
- Subscription
- Entitlements Product Management
- Modules
- Dependencies
- Features
- Bundles Access Management
- Platform Users/Roles where architecture permits Audit Log Do not implement Accounting operational menus yet.

28. TENANT PORTAL

Implement functional tenant shell. Minimum navigation: Dashboard Organization
- Branch
- Business Unit Access Management
- Users
- Roles
- Permissions / role configuration
- Data Scope Account / Subscription
- Subscription
- Active Modules
- Feature Entitlements
- Usage & Limits Audit Log where permitted Do NOT expose: Journal GL AP AR Financial Reports during OA0.

29. FRONTEND CAPABILITY MODEL

Create reusable frontend capability handling. Navigation/actions should react to: current tenant module entitlement feature entitlement permission read-only state Backend remains authoritative. Avoid scattered checks such as: if (role === 'admin') Use centralized hooks/services/components appropriate to existing frontend architecture.

30. API FOUNDATION

Use versioned APIs according to MASTER. Suggested logical separation: /api/v1/platform/... /api/v1/app/... Keep controllers thin. Use application/domain services for: tenant lifecycle membership RBAC module dependency subscription entitlement capacity effective access Use repository conventions rather than creating unnecessary abstraction layers.

31. DATABASE INTEGRITY

Use PostgreSQL constraints where appropriate: foreign keys unique constraints check constraints indexes Protect: duplicate membership duplicate permission codes duplicate module codes invalid same-tenant relationships invalid dependency self-reference duplicate entitlement identity cross-tenant relationships Do not rely only on React validation.

32. MIGRATIONS

MASTER is already committed. Do not rewrite committed MASTER migrations if any exist. Use additive migrations. Migrations must be runnable on: fresh database existing MASTER baseline Seeds must be idempotent where practical.

33. SEEDING

Seed only what is necessary for OA0. Include: module catalog minimum module dependencies minimum feature catalog foundation permissions bootstrap platform role/user mechanism sensible tenant bootstrap roles if needed Do not seed: fake tenants fake subscriptions fake financial data fake journals fake invoices Production-safe bootstrap is preferred.

34. BOOTSTRAP SUPERADMIN

Provide a secure method to create the initial platform administrator. Do not commit default production passwords. Prefer environment/CLI bootstrap consistent with Laravel practices. Document the process concisely.

35. REDIS / QUEUE

Configure Redis foundation for: cache queue Do not create unnecessary background jobs. Infrastructure must be ready for later: notifications subscription lifecycle integration report generation Queue failures must not corrupt tenant/access state.

36. DOCKER / LOCAL RUNTIME

Provide a reproducible local runtime. Expected services where appropriate: backend frontend PostgreSQL Redis web/reverse proxy if repository architecture requires it Do NOT add MongoDB yet unless MASTER already initialized it for a justified reason. OA0 must be runnable from documented commands.

37. SECURITY

Treat these as OA0 release blockers: cross-tenant access tenant spoofing permission bypass module entitlement bypass feature entitlement bypass read-only mutation bypass inactive membership access inactive tenant access capacity bypass privilege escalation mass assignment of privileged fields secret exposure Use framework security capabilities rather than custom cryptography.

38. CROSS-TENANT TEST MATRIX

At minimum test attempts by Tenant A to access Tenant B: users/memberships roles branches business units subscriptions entitlements feature entitlements capacity information audit records Cover where relevant: GET LIST SEARCH/FILTER CREATE relationship UPDATE DELETE/deactivate Cross-tenant relationship creation must fail.

39. ENTITLEMENT TEST MATRIX

Test at minimum: module ACTIVE module READ_ONLY module SUSPENDED module DISABLED expired entitlement future entitlement feature enabled feature disabled parent module disabled subscription inactive tenant inactive Ensure backend and frontend behavior are consistent.

40. MODULE DEPENDENCY TESTS

Test: valid dependency missing dependency transitive dependency reverse dependency self dependency circular dependency deactivation with active dependent module No silent inconsistent module state.

41. CAPACITY TESTS

Test at least: below limit at limit above limit unlimited/null policy if supported concurrent creation near limit where practical Capacity enforcement must be server-side.

42. PERFORMANCE BASELINE

Avoid N+1 and obvious inefficient authorization queries. Pay attention to high-frequency operations: current user current tenant capability resolution menu capability retrieval permission lookup entitlement lookup Use caching only where useful. Cache keys must be tenant-aware. Invalidate cache when: role/permission changes membership changes entitlement changes feature changes subscription changes tenant status changes Never allow stale cache to grant revoked privileges indefinitely.

43. OA0 DOCUMENTATION

Update repository documentation only where implementation makes it necessary. Do not rewrite MASTER architecture documents wholesale. Create/update: docs/status/OA0_STATUS.md Keep <= 100 lines. It should contain only: Status Completed Batches Important Implementation Decisions Migrations Security/Invariants Tests Known Issues Remaining Next Phase Dependencies If implementation materially changes an architecture decision, update only the relevant architecture document.

44. TOKEN EFFICIENCY — STRICT

The repository is the source of truth. DO NOT print: complete files large diffs full migrations full schemas full API responses full test logs dependency installation logs long architecture explanations restatement of MASTER architecture restatement of this prompt Do not narrate routine edits. Inspect only relevant files. Do not repeatedly scan the entire repository. During development, output no more than approximately 15 lines per progress checkpoint.

45. IMPLEMENTATION STRATEGY

Work in these batches: A — Application Runtime + Authentication + Tenant Context B — Tenant + Membership + Tenant Lifecycle C — Dynamic RBAC + Permission Registry + Data Scope D — Organization Foundation E — Module Catalog + Dependency Engine F — Bundle + Subscription Foundation G — Module + Feature + Capacity Entitlements H — Effective Access Resolver + Read-Only Enforcement I — Audit + Security Controls J — Platform React Portal K — Tenant React Portal L — Tests + Full OA0 Regression M — Security/Performance/Runtime Release Gate Complete batches sequentially. Do not stop for approval between batches. For each batch:
1. inspect relevant implementation;
2. implement only required changes;
3. run targeted tests;
4. fix failures;
5. update OA0_STATUS.md concisely;
6. continue. Do not run the full regression suite after every batch.

46. TEST POLICY

During batches: run targeted tests only. At release gate run: backend OA0 tests tenant isolation tests RBAC tests entitlement tests dependency tests capacity tests security tests frontend tests if configured frontend production build migration fresh test seed test Docker/runtime validation where executable Also run MASTER/repository baseline regression necessary to ensure no existing behavior was broken. Report actual results only. Allowed: PASS FAIL NOT RUN Never report PASS for an unexecuted check.

47. RELEASE GATE

Before COMMIT READY verify: [ ] OptiAccounting runs independently from OptiFleet [ ] Platform administrator can authenticate [ ] Tenant can be created [ ] User can belong to tenant [ ] User can belong to multiple tenants [ ] Tenant switching is safe [ ] Tenant lifecycle enforcement works [ ] RBAC is dynamic [ ] No hardcoded role authorization [ ] Data Scope works [ ] Branch/Business Unit are tenant-safe [ ] Module Catalog is data-driven [ ] Module dependencies work [ ] Circular dependency is prevented [ ] Bundle foundation works [ ] Subscription foundation works [ ] Module entitlement works [ ] Feature entitlement works [ ] Capacity foundation works [ ] READ_ONLY prevents mutation [ ] Effective Access Resolver is centralized/reusable [ ] Cross-tenant access tests pass [ ] Audit captures critical OA0 actions [ ] Platform Portal is functional [ ] Tenant Portal is functional [ ] No OA1 accounting business functionality was implemented [ ] No dependency on OptiFleet exists [ ] Fresh migrations pass [ ] Seeds pass [ ] Backend tests pass [ ] Frontend production build passes [ ] Runtime/Docker validation performed where possible [ ] No release-blocking security issue remains

48. DEFINITION OF DONE

OA0 is COMMIT READY only if:
- implementation is complete;
- migrations are valid;
- seeds are valid;
- targeted tests pass;
- OA0 regression passes;
- tenant isolation passes;
- authorization/entitlement tests pass;
- frontend build passes;
- runnable environment is validated where possible;
- OA0_STATUS.md is current;
- no P0/P1 issue remains;
- no fake/mock/TODO implementation is being counted as completed;
- OA1 has not been started.

49. BLOCKER POLICY

Do not ask questions for ordinary implementation choices. Use MASTER architecture and repository conventions. Stop only when:
- requirements fundamentally conflict;
- a destructive decision requires owner approval;
- required credentials/external infrastructure are unavailable and no local-safe validation is possible;
- repository state is materially inconsistent and cannot be safely resolved. Otherwise continue autonomously.

50. PROGRESS OUTPUT

During implementation output only: OptiAccounting OA0 Progress Batch: <batch> Completed:
- ... Validation:
- ... Remaining:
- ... Blocker:
- none / ... Keep it concise.

51. FINAL OUTPUT

When release gate completes output only: OPTIACCOUNTING OA0 — RELEASE GATE STATUS: COMMIT READY / NOT READY Completed:
- maximum 10 concise items Validation:
- Backend: PASS/FAIL/NOT RUN
- Tenant Isolation: PASS/FAIL/NOT RUN
- RBAC/Data Scope: PASS/FAIL/NOT RUN
- Module Dependency: PASS/FAIL/NOT RUN
- Entitlements: PASS/FAIL/NOT RUN
- Capacity: PASS/FAIL/NOT RUN
- Security: PASS/FAIL/NOT RUN
- Frontend Build: PASS/FAIL/NOT RUN
- Migration/Seed: PASS/FAIL/NOT RUN
- Runtime/Docker: PASS/FAIL/NOT RUN Critical Issues:
- none / ... Known Non-Blocking Issues:
- none / ... OA0_STATUS.md:
- UPDATED / NOT UPDATED Next Phase: OA1 — Accounting Core & General Ledger Suggested Commit: feat: establish OptiAccounting standalone SaaS foundation Do not start OA1. Stop and wait for explicit instruction.
