# OPTIACCOUNTING OA3 — ACCOUNTS RECEIVABLE & REVENUE Implement: OA3 — Accounts Receivable & Revenue Target: OPTIACCOUNTING OA3 STATUS: COMMIT READY

1. SOURCE OF TRUTH

MASTER and OA0–OA2 are completed and committed. Treat them as stable baseline. Repository state is authoritative. Before implementation:
1. Read root CLAUDE.md.
2. Read only OA3-relevant architecture docs.
3. Read docs/status/OA2_STATUS.md.
4. Read docs/status/OA3_STATUS.md if it exists.
5. Inspect only existing Accounting Core, AP/payment allocation, Cash/Bank, numbering, audit, entitlement, and UI patterns needed by OA3. Do NOT reconstruct previous phases from chat. Do NOT redesign or recreate completed functionality. Do NOT copy AP code mechanically when shared abstractions can safely be reused. Use additive migrations only. Modify committed baseline only for:
- genuine bugs; or
- small backward-compatible extensions required by OA3.

2. PHASE OBJECTIVE

Build Accounts Receivable and Revenue subledger capabilities: Customer ↓ Customer Invoice ↓ Revenue / Receivable Recognition ↓ Accounts Receivable ↓ Customer Receipt ↓ Receipt Allocation ↓ Outstanding Receivable ↓ AR Aging ↓ AR ↔ GL Reconciliation Every financial posting must reuse OA1 Accounting Core: Business Transaction ↓ Accounting Event ↓ Posting Rule ↓ Account Mapping ↓ Posting Engine ↓ Posted Journal ↓ General Ledger OA3 must NEVER implement an independent debit/credit engine.

3. STRICT PHASE BOUNDARY

IN SCOPE:
- Customer
- Customer Financial Profile
- Customer Payment Terms
- Customer Invoice
- Customer Invoice Lines
- AR Invoice lifecycle
- AR posting
- Revenue recognition foundation
- AR Subledger
- Outstanding Receivable
- Customer Receipt
- Receipt Allocation
- Partial Receipt
- Multi-Invoice Receipt Allocation
- Customer Advance foundation only if safely implemented
- Credit Note
- Debit Note foundation where justified
- Invoice/Receipt reversal
- AR Aging
- AR ↔ GL reconciliation
- Receipt ↔ Cash/Bank integration
- OA3 Posting Rules/Account Mappings
- OA3 permissions/data scope
- OA3 audit
- React UI
- Tests/regression OUT OF SCOPE:
- Revenue contracts
- Complex IFRS/PSAK revenue recognition
- Deferred revenue schedules
- Percentage-of-completion
- Subscription billing engine
- Recurring SaaS billing
- Sales Order
- CRM
- Inventory fulfillment
- Full Tax Engine
- Full Multi-Currency
- FX gain/loss
- Budget
- Fixed Asset
- Financial Statements
- Advanced Closing
- External payment gateway
- Automated bank feed
- OptiFleet integration
- External adapters
- MongoDB analytics
- Financial Intelligence Do not start OA4.

4. ENTITLEMENTS

Reuse OA0 entitlement architecture. Primary module: ACCOUNTING_AR Dependency: ACCOUNTING_AR → ACCOUNTING_CORE Receipt functionality using Cash/Bank should integrate with ACCOUNTING_CASH_BANK when available according to existing architecture. Do not create conflicting entitlement logic. Add only OA3 features actually required. Suggested: CUSTOMER AR_INVOICE AR_RECEIPT AR_CREDIT_NOTE AR_AGING REVENUE_FOUNDATION Backend capability resolver remains authoritative. READ_ONLY denies mutations while preserving authorized history.

5. CUSTOMER

Implement tenant-owned Customer master. Minimum concepts: code name status legal/business name where appropriate contact information billing information payment terms default currency metadata tax identifier metadata where appropriate external reference foundation notes/metadata where justified Customer must be standalone. Do not make Customer dependent on OptiFleet or another application. Used customers must not be hard deleted.

6. CUSTOMER FINANCIAL PROFILE

Support optional financial settings: default payment terms default AR control/mapping override where architecture permits default revenue mapping hints currency metadata tax metadata foundation credit limit metadata foundation if useful Posting Rules and Account Mapping remain accounting authority. Do not embed customer-specific debit/credit logic.

7. CUSTOMER PAYMENT TERMS

Reuse OA2 Payment Terms abstraction if semantically generic. Do not create duplicate AP-only and AR-only engines unless business semantics genuinely require separation. Support deterministic due-date calculation. Examples may include: Due on Receipt Net 7 Net 14 Net 30 Net 60 Custom Examples must remain data-driven.

8. CUSTOMER INVOICE

Implement Customer Invoice as AR source document. Minimum header concepts: tenant internal invoice number customer customer reference where appropriate document_date posting_date due_date payment terms currency description/reference subtotal discount foundation tax amount foundation other charges foundation total payment state workflow status source/reference created/submitted/approved/posted metadata Use DECIMAL/NUMERIC. Backend recalculates authoritative totals.

9. CUSTOMER INVOICE LINE

Minimum concepts: description quantity where applicable unit price where applicable line amount revenue/accounting classification account role/account mapping reference accounting dimensions tax metadata foundation source/reference metadata Allow amount-only lines where appropriate. Do not require inventory/product integration.

10. AR INVOICE LIFECYCLE

Controlled workflow: DRAFT → SUBMITTED → APPROVED → POSTED Alternatives: REJECTED CANCELLED Settlement state is separate: UNPAID PARTIALLY_PAID PAID Do not combine workflow and settlement into one ambiguous status. No arbitrary status PATCH.

11. AR INVOICE POSTING

Posting must reuse OA1 Posting Engine. Typical semantic result: Dr Accounts Receivable Cr Revenue Actual accounts are resolved through: Posting Rule + Account Mapping Never hardcode account numbers. Validate: Accounting readiness period customer invoice totals dimensions posting rule account mapping entitlement permission SoD tenant ownership Posting is atomic.

12. INTERNAL ACCOUNTING EVENTS

Extend internal Accounting Event catalog only as required. Suggested: AR_INVOICE_RECOGNIZED AR_INVOICE_REVERSED CUSTOMER_RECEIPT CUSTOMER_RECEIPT_REVERSED AR_CREDIT_NOTE_RECOGNIZED AR_CREDIT_NOTE_REVERSED AR_DEBIT_NOTE_RECOGNIZED if implemented These are internal Accounting events. They are NOT OA6 external integration events.

13. REVENUE FOUNDATION

OA3 supports straightforward revenue recognition represented by the Customer Invoice/accounting event. Do not claim support for complex accounting standards merely because invoice revenue can be posted. Simple baseline: Invoice recognized → Receivable + Revenue More complex future cases such as: performance obligations contract assets/liabilities deferred revenue milestone recognition percentage of completion are OUT OF SCOPE. Design OA3 so they can be added later without corrupting AR Core.

14. POSTED INVOICE IMMUTABILITY

Once POSTED: financially relevant Customer Invoice fields are immutable. Corrections use: Credit Note Debit Note where appropriate Reversal Adjustment according to accounting semantics. Never silently mutate the posted journal.

15. DUPLICATE CONTROL

Prevent accidental duplicate financial posting. Internal invoice numbering must be unique according to numbering scope. Where external/source references exist, support duplicate detection using appropriate tenant/source/reference identity. Do not rely only on frontend duplicate warnings.

16. AR SUBLEDGER

AR Subledger derives from: posted Customer Invoices Credit/Debit Notes valid posted Receipt Allocations authorized reversals/adjustments Do not create manually editable receivable balances. Provide at minimum: customer invoice posting date due date original amount adjustment amount received amount outstanding amount aging status AR must reconcile to AR control account in GL.

17. OUTSTANDING RECEIVABLE

Outstanding must be derived from authoritative financial transactions. Conceptually: Posted Invoice + Debit Adjustments
- Credit Adjustments
- Posted Receipt Allocations ± Reversals Never trust frontend-maintained outstanding values. Cached/materialized amounts, if used, are projections and must be reconcilable.

18. CUSTOMER RECEIPT

Implement Customer Receipt. Minimum: tenant receipt number customer receipt date posting date Cash/Bank account currency amount reference status allocations journal reference Baseline lifecycle: DRAFT → SUBMITTED → APPROVED → POSTED Alternatives: REJECTED CANCELLED Posted receipt is immutable.

19. RECEIPT ALLOCATION

A Receipt may allocate to multiple posted Customer Invoices. An Invoice may receive multiple Receipts. Support: partial receipt multi-invoice allocation unallocated amount only where explicitly supported Validate: allocation > 0 allocation <= available receipt allocation <= invoice outstanding unless advance policy applies same tenant compatible customer compatible currency in OA3 context invoice is POSTED and collectible Allocation posting must be atomic.

20. RECEIPT POSTING

Typical semantic result: Dr Cash/Bank Cr Accounts Receivable Actual accounts resolved by OA1 Posting Rules/Account Mapping. Reuse OA2 Cash/Bank accounts where available. Do not create a second Cash/Bank accounting engine.

21. CUSTOMER ADVANCE

Do not silently over-allocate receipts. If Customer Advance is implemented safely: unallocated receipt amount → Customer Advance / Liability semantic account with explicit posting rule and traceability. If this cannot be completed safely within OA3: reject over-allocation and record customer advance as future work. Do not use negative AR outstanding as fake advance handling.

22. RECEIPT REVERSAL

Use OA1 shared reversal mechanism. Reversal must: reverse journal impact; release/reverse allocations; restore invoice outstanding; reference original receipt; validate target period; remain atomic; remain auditable. Prevent duplicate reversal.

23. CREDIT NOTE

Implement Credit Note for reduction of a posted Customer Invoice/receivable. Minimum: customer original invoice reference where applicable document date posting date reason lines/amount dimensions status journal reference Typical semantic effect: Dr Revenue / configured adjustment account Cr Accounts Receivable Actual accounts resolved by Posting Engine. Do not mutate original invoice.

24. CREDIT NOTE ALLOCATION

A Credit Note should reduce the relevant receivable in a controlled manner. Validate: same tenant compatible customer valid posted invoice amount does not create invalid negative receivable unless explicit customer-credit policy exists Keep source relationship traceable.

25. DEBIT NOTE

Implement Debit Note only if the existing accounting model supports it cleanly without duplicating Customer Invoice semantics. Purpose: increase receivable after original invoice where accounting/business semantics require a separate adjustment document. If implemented, use Posting Engine. If not materially required for OA3 completeness, leave as documented extension rather than overengineering.

26. CREDIT/DEBIT NOTE IMMUTABILITY

Once posted: adjustment documents are immutable. Correction uses reversal or subsequent adjustment. Never modify original posted Customer Invoice history.

27. AR AGING

Implement AR Aging. Default presentation buckets may be: Current 1–30 31–60 61–90 >90 Architecture should allow future configurable buckets. Use: due_date + explicit as_of_date Support filters: customer branch business unit cost center where semantically valid Respect Data Scope.

28. AR ↔ GL RECONCILIATION

Implement reconciliation: AR Subledger Balance vs AR Control Account GL Balance Support: as_of_date tenant customer breakdown where useful difference status Suggested: MATCHED MISMATCH Never silently adjust either side to force a match.

29. RECEIPT ↔ CASH/BANK INTEGRITY

Customer Receipt posted to Cash/Bank must reconcile with: Cash/Bank operational transaction and mapped Cash/Bank GL account Reuse OA2 architecture. Avoid creating duplicate cash transaction records that independently represent the same financial event without linkage/idempotency.

30. CASH/BANK MODULE INTERACTION

If ACCOUNTING_CASH_BANK is active: use existing Cash/Bank accounts and controls. If AR can exist without Cash/Bank entitlement according to module dependencies: design receipt handling consistently with the configured dependency model. Do not bypass entitlement architecture merely to make receipt posting work. If dependency adjustment is required, modify module dependency data additively and document the decision in OA3_STATUS.md.

31. POSTING RULES

Add only OA3 semantic posting rules required. Examples: AR_INVOICE_RECOGNIZED Debit: ACCOUNTS_RECEIVABLE Credit: REVENUE CUSTOMER_RECEIPT Debit: CASH_OR_BANK Credit: ACCOUNTS_RECEIVABLE AR_CREDIT_NOTE_RECOGNIZED Debit: REVENUE_ADJUSTMENT Credit: ACCOUNTS_RECEIVABLE Actual accounts remain tenant mappings. Do not hardcode account codes.

32. ACCOUNT MAPPING

Reuse OA1 account-role/mapping architecture. Add semantic roles only where genuinely required, such as: ACCOUNTS_RECEIVABLE REVENUE REVENUE_ADJUSTMENT CUSTOMER_ADVANCE Do not create dozens of speculative account roles. Tenant-specific mappings must remain auditable.

33. ACCOUNTING DIMENSIONS

Reuse OA1 dimension architecture. Customer Invoice lines and adjustments may carry: Branch Business Unit Cost Center and future external dimensions Receipt generally follows Cash/Bank and receivable accounting semantics. Prevent cross-tenant dimension injection. Do not add customer-specific columns to journal lines when generic dimension/source structures already solve the requirement.

34. ACCOUNTING PERIOD

Every financial OA3 action uses explicit posting_date and OA1 Period Guard. Closed period blocks: Customer Invoice posting Receipt posting Credit/Debit Note posting Reversal No OA3 endpoint may bypass period locking.

35. SEGREGATION OF DUTIES

Reuse OA1 SoD foundation. Support policies such as: invoice creator != approver receipt creator != approver credit note creator != approver when enabled. No hardcoded role names.

36. DOCUMENT NUMBERING

Reuse centralized numbering. Add document types as required: AR_INVOICE CUSTOMER_RECEIPT AR_CREDIT_NOTE AR_DEBIT_NOTE if implemented Never create separate max+1 numbering. Issued numbers are immutable and concurrency-safe.

37. ATTACHMENTS

Reuse existing secure attachment infrastructure if available. Potential attachments: Customer Invoice supporting documents Receipt proof Credit Note support Do not build a separate document management system. Enforce tenant/permission/file security.

38. PERMISSIONS

Add only required OA3 atomic permissions. Examples: accounting.customer.view accounting.customer.manage accounting.ar_invoice.view accounting.ar_invoice.create accounting.ar_invoice.update accounting.ar_invoice.submit accounting.ar_invoice.approve accounting.ar_invoice.post accounting.ar_invoice.reverse accounting.ar_receipt.view accounting.ar_receipt.create accounting.ar_receipt.update accounting.ar_receipt.submit accounting.ar_receipt.approve accounting.ar_receipt.post accounting.ar_receipt.reverse accounting.ar_credit_note.view accounting.ar_credit_note.create accounting.ar_credit_note.approve accounting.ar_credit_note.post accounting.ar_credit_note.reverse accounting.ar_aging.view accounting.reconciliation.ar.view Reuse equivalent existing permissions where available. Do not authorize by role name.

39. DATA SCOPE

Reuse existing Data Scope. Apply applicable: TENANT BRANCH BUSINESS_UNIT COST_CENTER OWN where semantically appropriate Scope applies to: customers invoices receipts credit/debit notes aging reconciliation exports Do not leak out-of-scope totals through dashboards or aggregate APIs.

40. AUDIT

Reuse OA0/OA1 audit infrastructure. Audit at minimum: Customer financial changes Customer Invoice lifecycle Invoice posting/reversal Receipt lifecycle Receipt allocation Receipt posting/reversal Credit/Debit Note lifecycle AR reconciliation critical exports Never store sensitive attachment content in audit.

41. DATABASE INTEGRITY

Use PostgreSQL constraints where appropriate. Protect: cross-tenant relationships duplicate customer codes invoice numbering uniqueness invalid monetary values invalid allocations allocation exceeding available receipt allocation exceeding outstanding invalid adjustment relationship duplicate posting duplicate reversal invalid GL mapping Application validation complements DB constraints.

42. CONCURRENCY

Explicitly protect/test: invoice double posting two receipts allocating same remaining invoice balance receipt double posting receipt reversal race credit note vs receipt allocation race invoice reversal vs receipt race document numbering AR outstanding calculation Use transactions/locking/constraints where appropriate. Never allow race conditions to create negative receivable accidentally.

43. REACT TENANT MENU

Extend Accounting navigation only when entitled. Suggested: Accounting ├── Receivables │ ├── Customers │ ├── Customer Invoices │ ├── Receipts │ ├── Credit Notes │ └── AR Aging │ └── Reconciliation └── AR vs GL Reuse existing Accounting menu structure. Do not add OA4 menus.

44. OA3 DASHBOARD

Keep dashboard additions operational. Useful indicators: Total AR Outstanding Overdue Receivables Invoices Due Soon Pending Invoice Approval Pending Receipt Approval Recent Receipts Do not implement OA7 analytics.

45. SEARCH / FILTER / PAGINATION

Use server-side filtering/pagination. Useful filters: Customer Workflow Status Payment Status Document Date Posting Date Due Date Overdue Branch Business Unit Cost Center Do not load full AR datasets into React for filtering.

46. EXPORT

If export infrastructure exists, support authorized export for: Customer list Customer Invoices Receipts Credit Notes AR Aging AR reconciliation Exports must enforce identical: tenant entitlement permission data scope Do not add unnecessary dependencies.

47. BACKWARD COMPATIBILITY

OA3 must not break tenants using only: OA0 SaaS Accounting Core AP Expense Cash/Bank ACCOUNTING_AR disabled must leave previous behavior unchanged. No dependency on OptiFleet or external applications.

48. OA3 STATUS FILE

Create/update: docs/status/OA3_STATUS.md Keep <= approximately 100 lines. Include only: Status Completed Batches Important Decisions Migrations Accounting Events Added Financial Invariants Tests Known Issues Remaining Next Phase Dependencies Do not rewrite previous architecture/status docs unnecessarily.

49. TOKEN EFFICIENCY — STRICT

Repository state is authoritative. DO NOT print: complete files large diffs migration contents full schemas full API payloads full test logs dependency logs long accounting explanations previous phase summaries restatement of this prompt Do not narrate routine edits. Inspect only files required for current batch. Reuse OA2 patterns where appropriate rather than rediscovering architecture. Do not mechanically duplicate AP implementation if a small shared abstraction safely eliminates duplication. Do not perform speculative refactors. Use OA3_STATUS.md as continuation memory. Progress output <= approximately 15 lines.

50. IMPLEMENTATION BATCHES

Execute sequentially: A — Customer + Financial Profile + Payment Terms Reuse B — Customer Invoice + Lines + Lifecycle C — AR Posting + Revenue Foundation + AR Subledger D — Customer Receipt + Allocation + Reversal E — Credit Note + Adjustment Controls F — AR Aging + AR ↔ GL Reconciliation G — Cash/Bank Integration + Posting Rules/Account Mappings H — Audit + Permissions + Data Scope + Entitlements I — React OA3 UI J — Financial Integrity + Concurrency Tests K — Tenant/Security Tests L — OA0–OA2 Regression M — Release Gate Do not stop for approval between batches. For each batch:
1. inspect relevant code;
2. implement only current scope;
3. run targeted tests;
4. fix failures;
5. update OA3_STATUS.md concisely;
6. continue. Run broad regression only at release gate.

51. CRITICAL AR TESTS

Release gate must prove:
1. Draft invoice does not affect GL.
2. Submitted invoice does not affect GL.
3. Approved but unposted invoice does not affect GL.
4. Posted invoice affects GL exactly once.
5. Posted invoice creates correct AR subledger balance.
6. Posted invoice is financially immutable.
7. Duplicate posting is prevented.
8. Closed period blocks invoice posting.
9. Cross-tenant account/dimension is rejected.
10. Partial receipt reduces outstanding correctly.
11. Multiple receipts reduce outstanding correctly.
12. Multi-invoice receipt allocation works.
13. Allocation cannot exceed receipt amount.
14. Allocation cannot exceed invoice outstanding unless explicit customer-advance policy applies.
15. Concurrent receipts cannot over-allocate.
16. Posted receipt affects GL exactly once.
17. Receipt reversal restores outstanding correctly.
18. Duplicate receipt reversal is prevented.
19. Credit Note reduces receivable correctly.
20. Credit Note does not mutate original invoice.
21. AR Aging uses due_date/as_of_date correctly.
22. AR Subledger reconciles to GL control account.

52. REVENUE TESTS

Prove: Simple invoice revenue uses Posting Engine. Revenue account is resolved through mapping, not hardcoded. Revenue posting occurs only when configured recognition event occurs. Unposted invoice does not affect revenue. Invoice reversal reverses accounting impact without rewriting history. Cross-tenant revenue mapping is rejected.

53. CUSTOMER ADVANCE TESTS

If Customer Advance is implemented: prove: unallocated receipt is not negative AR; advance uses configured liability account; later allocation is traceable; GL remains balanced; reversal works. If not implemented: prove over-allocation is rejected. Do not report unsupported advance behavior as PASS.

54. TENANT ISOLATION

Tenant A must never access Tenant B: customers invoices invoice lines receipts allocations credit/debit notes AR aging reconciliation attachments exports Test relevant: GET LIST FILTER CREATE relationship UPDATE SUBMIT APPROVE POST REVERSE EXPORT Cross-tenant financial access is P0.

55. ENTITLEMENT / READ_ONLY

Test ACCOUNTING_AR for: ACTIVE READ_ONLY SUSPENDED DISABLED expired future-effective READ_ONLY must block: Customer mutation where policy requires Invoice mutation/workflow/posting Receipt mutation/workflow/posting Credit Note mutation/posting Reversal while retaining authorized historical access. ACCOUNTING_CORE unavailable must prevent AR financial posting.

56. PERMISSION / SOD

Test unauthorized: Customer management Invoice create/update/submit/approve/post/reverse Receipt create/update/submit/approve/post/reverse Credit Note actions AR Aging AR reconciliation/export Test configured maker-checker rules. No role-name bypass.

57. AR ↔ GL RECONCILIATION TEST

Create deterministic: Customer Invoice Partial Receipt Additional Receipt Credit Note Reversal where relevant Verify: AR Subledger = AR Control Account GL for the same tenant/as-of scope. Mismatch must be detected, never silently corrected.

58. OA0–OA2 REGRESSION

OA3 must not break: Authentication/Tenant/RBAC Entitlements/Data Scope Accounting Profile Fiscal Period COA Journal Posting Engine Reversal GL Trial Balance Vendor/AP Vendor Payment Expense Cash/Bank AP reconciliation Run targeted prior-phase tests during development only when impacted. Run appropriate broader regression at OA3 release gate.

59. FRONTEND VALIDATION

Verify: entitlement-based menu permission-based actions READ_ONLY behavior Customer management Invoice workflow Receipt allocation Partial receipt Credit Note AR Aging AR reconciliation filters/pagination loading/error/empty states production build Backend remains financial authority.

60. SECURITY

Treat as release blockers: cross-tenant financial access permission bypass entitlement bypass READ_ONLY bypass duplicate posting receipt allocation race negative receivable caused by race posted document mutation period lock bypass cross-tenant GL/dimension mapping attachment authorization bypass export scope leakage

61. RELEASE GATE

Before COMMIT READY verify: [ ] OA0–OA2 remain stable [ ] Customer works [ ] Payment Terms reuse is clean [ ] Customer Invoice lifecycle works [ ] AR posting uses OA1 Posting Engine [ ] No independent debit/credit engine exists [ ] Posted invoice is immutable [ ] AR Subledger works [ ] Outstanding Receivable works [ ] Partial Receipt works [ ] Multi-Invoice Receipt Allocation works [ ] Over-allocation safely handled [ ] Concurrent over-allocation prevented [ ] Receipt posting works [ ] Receipt reversal works [ ] Credit Note works [ ] Original invoice remains immutable [ ] AR Aging works [ ] AR ↔ GL reconciliation works [ ] Cash/Bank integration remains consistent with OA2 [ ] Revenue mapping is configurable [ ] Period locking works [ ] SoD/permissions work [ ] Entitlement/READ_ONLY works [ ] Tenant isolation passes [ ] Data Scope passes [ ] Audit works [ ] React UI works [ ] Frontend production build passes [ ] Fresh migrations/seeds pass [ ] OA0–OA2 regression passes [ ] No OA4 functionality accidentally implemented [ ] No external/OptiFleet dependency introduced [ ] No P0/P1 issue remains

62. DEFINITION OF DONE

OA3 is COMMIT READY only when:
- AR implementation is complete;
- all financial posting uses OA1 Accounting Core;
- AR subledger reconciles to GL;
- receipt allocation integrity is proven;
- Credit Note preserves immutable history;
- period locking is enforced;
- tenant isolation passes;
- RBAC/Data Scope passes;
- entitlement/READ_ONLY passes;
- critical concurrency tests pass;
- frontend production build passes;
- migrations/seeds pass;
- prior-phase regression passes;
- OA3_STATUS.md is current;
- no fake PASS exists;
- no TODO/mock is counted as completed;
- OA4 has not started.

63. BLOCKER POLICY

Do not ask questions for ordinary implementation decisions. Use: CLAUDE.md architecture docs existing OA0–OA2 implementation repository conventions Prefer reuse over duplication. Stop only for a genuine blocker requiring owner decision. If validation cannot run: report NOT RUN with concise reason. Never convert NOT RUN into PASS.

64. PROGRESS OUTPUT

During implementation output only: OptiAccounting OA3 Progress Batch: <batch> Completed:
- ... Validation:
- ... Remaining:
- ... Blocker:
- none / ... Keep <= approximately 15 lines.

65. FINAL OUTPUT

When release gate completes output only: OPTIACCOUNTING OA3 — RELEASE GATE STATUS: COMMIT READY / NOT READY Completed:
- maximum 10 concise items Financial Integrity:
- AR Posting: PASS/FAIL/NOT RUN
- AR ↔ GL Reconciliation: PASS/FAIL/NOT RUN
- Receipt Allocation: PASS/FAIL/NOT RUN
- Receipt Reversal: PASS/FAIL/NOT RUN
- Credit Note: PASS/FAIL/NOT RUN
- Revenue Posting: PASS/FAIL/NOT RUN
- Period Locking: PASS/FAIL/NOT RUN
- Transaction Immutability: PASS/FAIL/NOT RUN Validation:
- Backend: PASS/FAIL/NOT RUN
- OA0–OA2 Regression: PASS/FAIL/NOT RUN
- Tenant Isolation: PASS/FAIL/NOT RUN
- RBAC/Data Scope: PASS/FAIL/NOT RUN
- Entitlement/READ_ONLY: PASS/FAIL/NOT RUN
- Concurrency: PASS/FAIL/NOT RUN
- Security: PASS/FAIL/NOT RUN
- Frontend Build: PASS/FAIL/NOT RUN
- Migration/Seed: PASS/FAIL/NOT RUN Critical Issues:
- none / ... Known Non-Blocking Issues:
- none / ... OA3_STATUS.md:
- UPDATED / NOT UPDATED Next Phase: OA4 — Budget, Fixed Asset, Tax & Multi-Currency Suggested Commit: feat: implement accounts receivable and revenue Do not start OA4. Stop and wait for explicit instruction.
