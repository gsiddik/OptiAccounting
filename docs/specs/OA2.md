# OPTIACCOUNTING OA2 — ACCOUNTS PAYABLE, EXPENSE & CASH/BANK Implement: OA2 — Accounts Payable, Expense & Cash/Bank Target: OPTIACCOUNTING OA2 STATUS: COMMIT READY

1. SOURCE OF TRUTH

MASTER, OA0, and OA1 are completed and committed. Treat them as stable baseline. Repository state is authoritative. Before implementation:
1. Read root CLAUDE.md.
2. Read only OA2-relevant docs under docs/architecture/.
3. Read docs/status/OA1_STATUS.md.
4. Read docs/status/OA2_STATUS.md if it exists.
5. Inspect only OA1 Accounting Core services and OA0 SaaS services required by OA2. Do NOT reconstruct previous phases from chat. Do NOT redesign or recreate:
- Tenant/SaaS foundation
- RBAC/Data Scope
- Entitlements
- Organization
- Accounting Profile
- Fiscal Period
- COA
- Accounting Dimensions
- Journal
- Posting Engine
- Posting Rules
- Account Mapping
- GL
- Trial Balance Reuse OA0/OA1. Use additive migrations only. Do not modify committed baseline except for a genuine bug or small backward-compatible extension required by OA2.

2. PHASE OBJECTIVE

Build operational financial subledgers for: Vendor ↓ Accounts Payable ↓ Vendor Invoice ↓ Expense Recognition ↓ Payment ↓ Cash/Bank Every financial recognition must use the OA1 Accounting Core. Target architecture: OA2 Business Transaction ↓ Accounting Event ↓ Posting Rule ↓ Account Mapping ↓ OA1 Posting Engine ↓ Posted Journal ↓ General Ledger OA2 must NEVER implement an independent debit/credit engine.

3. STRICT PHASE BOUNDARY

IN SCOPE:
- Vendor
- Vendor financial profile
- Accounts Payable
- Vendor Invoice
- Vendor Invoice Line
- Invoice lifecycle
- Invoice approval
- Invoice posting
- AP Subledger
- Outstanding Payable
- Payment Terms
- Due Date
- Partial Payment
- Vendor Payment
- Payment Allocation
- AP Aging
- Expense Category
- Expense Request/Transaction
- Expense approval
- Expense posting
- Cash Account
- Bank Account
- Cash Receipt foundation where required
- Cash Payment
- Bank Transaction foundation
- Bank Reconciliation foundation
- AP ↔ GL reconciliation
- Cash/Bank ↔ GL reconciliation
- OA2 permissions
- OA2 audit
- React UI
- Tests/regression OUT OF SCOPE:
- Customer
- Accounts Receivable
- Customer Invoice
- Customer Receipt
- Revenue
- Budget
- Fixed Asset
- Depreciation
- Full Tax Engine
- Full Multi-Currency
- FX gain/loss
- Financial Statements
- Advanced Closing
- Automated bank feed integration
- Payment gateway
- Banking API integration
- OptiFleet integration
- External accounting adapters
- MongoDB analytics
- Financial Intelligence Do not start OA3.

4. ENTITLEMENTS

Reuse OA0 entitlement infrastructure. Use relevant modules: ACCOUNTING_AP ACCOUNTING_EXPENSE ACCOUNTING_CASH_BANK Dependencies should remain data-driven. Expected dependency direction: ACCOUNTING_AP → ACCOUNTING_CORE ACCOUNTING_EXPENSE → ACCOUNTING_CORE ACCOUNTING_CASH_BANK → ACCOUNTING_CORE Do not hardcode dependency checks in controllers. Add only OA2 features actually required. Examples: VENDOR AP_INVOICE AP_PAYMENT AP_AGING EXPENSE CASH_ACCOUNT BANK_ACCOUNT BANK_TRANSACTION BANK_RECONCILIATION Backend capability resolver remains authoritative. READ_ONLY must deny mutations while preserving authorized history.

5. VENDOR

Implement tenant-owned Vendor master. Minimum concepts: code name status legal/business name where appropriate contact information payment terms default currency metadata tax identifier metadata where appropriate default AP account mapping override if architecture supports it external reference foundation notes/metadata where justified Do not make Vendor dependent on OptiFleet. Do not store unnecessary sensitive information. Used vendors must not be hard deleted.

6. VENDOR FINANCIAL PROFILE

Keep vendor operational identity separate from accounting configuration. Support optional financial settings such as: default payment terms default payable account override default expense/account mapping hints currency metadata tax metadata foundation Global/tenant posting rules remain primary accounting authority. Vendor override must be explicit and auditable. Do not scatter vendor-specific debit/credit logic.

7. PAYMENT TERMS

Implement tenant-configurable Payment Terms. Examples: Due on Receipt Net 7 Net 14 Net 30 Net 60 Custom Do not hardcode only these examples. Due date must be deterministically derived when payment term applies, while allowing authorized explicit due date where policy permits.

8. VENDOR INVOICE

Implement Vendor Invoice as AP source document. Minimum header concepts: tenant invoice number/internal document number vendor invoice number vendor document_date posting_date due_date payment terms currency description/reference subtotal discount foundation tax amount foundation other charges foundation total outstanding amount derived status source/reference created/submitted/approved/posted metadata Do not use FLOAT. Backend recalculates authoritative totals.

9. VENDOR INVOICE LINE

Minimum line concepts: description quantity where applicable unit price where applicable line amount expense/accounting classification account role/account mapping reference accounting dimensions tax metadata foundation reference metadata Do not require quantity/unit price for every financial invoice line. Allow amount-based lines where semantically valid.

10. AP INVOICE LIFECYCLE

Use controlled transitions. Baseline: DRAFT → SUBMITTED → APPROVED → POSTED Alternatives: REJECTED CANCELLED Payment state is separate from document workflow state. Example payment state: UNPAID PARTIALLY_PAID PAID Do not overload one status field with both workflow and settlement state. No arbitrary status PATCH.

11. AP INVOICE POSTING

Posting must reuse OA1 Posting Engine. Typical conceptual result: Dr Expense / Asset / Other configured destination Cr Accounts Payable But actual accounts must be resolved through: Posting Rule + Account Mapping Do not hardcode account numbers. Posting must validate: Accounting readiness Period Vendor Invoice totals Dimensions Posting rules Account mappings Entitlement Permission SoD Tenant ownership Posting must be atomic.

12. AP ACCOUNTING EVENTS

Extend OA1 internal Accounting Event catalog only as required. Suggested events: AP_INVOICE_RECOGNIZED AP_INVOICE_REVERSED VENDOR_PAYMENT VENDOR_PAYMENT_REVERSED EXPENSE_RECOGNIZED EXPENSE_REVERSED CASH_PAYMENT CASH_RECEIPT These are internal accounting events. They are NOT OA6 external integration events. Do not create external adapters.

13. INVOICE IMMUTABILITY

Once a Vendor Invoice is POSTED: financially relevant fields must not be edited. Correction must use appropriate: reversal credit/adjustment foundation or controlled cancellation before posting Do not silently rewrite the journal. Posted invoice must retain journal/source relationship.

14. DUPLICATE INVOICE CONTROL

Provide duplicate detection/control. At minimum detect suspicious duplicate: tenant vendor vendor invoice number Where appropriate also consider: invoice date amount Use a strong uniqueness rule where business-safe. Do not allow accidental duplicate posting. If legitimate duplicate vendor numbers are possible, use explicit authorized override rather than weakening detection silently.

15. AP SUBLEDGER

AP Subledger derives from posted AP transactions and payment allocations. Do not maintain a manually editable payable balance. Provide at minimum: vendor invoice posting date due date original amount paid amount outstanding amount aging status Subledger must reconcile to AP control account in GL.

16. OUTSTANDING PAYABLE

Outstanding must be derived from: posted invoice amount minus valid posted payment allocations plus/minus authorized adjustments/reversals Do not trust a frontend-maintained outstanding field as financial truth. If cached/materialized values are used for performance, they are projections and must reconcile to authoritative transactions.

17. AP AGING

Implement AP Aging. At minimum support configurable/reporting buckets such as: Current 1–30 31–60 61–90 >90 Do not hardcode presentation so future bucket configuration becomes impossible. Aging must use due_date and an explicit report/as-of date. Support filters: vendor branch business unit cost center where semantically valid Respect Data Scope.

18. VENDOR PAYMENT

Implement Vendor Payment. Minimum concepts: tenant payment number vendor payment date posting date cash/bank account currency amount reference status allocations journal reference Baseline lifecycle: DRAFT → SUBMITTED → APPROVED → POSTED Alternatives: REJECTED CANCELLED Posted payment is immutable.

19. PAYMENT ALLOCATION

A payment may allocate to one or multiple posted Vendor Invoices. An invoice may receive multiple payments. Support: partial payment multiple invoice allocation unallocated amount foundation where business-safe Validate: allocation > 0 allocation <= available payment amount allocation <= invoice outstanding unless explicit overpayment policy exists same tenant compatible vendor compatible currency in OA2 single-currency context invoice is posted and payable Allocation posting must be atomic.

20. PAYMENT POSTING

Conceptual accounting: Dr Accounts Payable Cr Cash/Bank Actual accounts must be resolved by OA1 Account Mapping/Posting Rules. Do not implement direct journal construction inside controller. Payment must reuse Accounting Posting Engine.

21. OVERPAYMENT

Do not silently create negative invoice outstanding. Safe OA2 behavior: reject allocation exceeding invoice outstanding unless a controlled vendor advance/prepayment mechanism is explicitly implemented. If vendor advance is not implemented in OA2: prevent over-allocation. Do not fake support with negative payable balances.

22. PAYMENT REVERSAL

Support controlled reversal of posted payment. Reversal must: reverse financial journal; reverse/release payment allocations; restore invoice outstanding correctly; reference original payment; validate target accounting period; remain auditable; be atomic. Prevent duplicate reversal.

23. EXPENSE CATEGORY

Implement tenant-configurable Expense Category. Examples: Office Travel Maintenance Utilities Professional Service Other Do not hardcode examples as accounting logic. Expense Category may map to: semantic account role or default tenant expense account Mapping must remain configurable.

24. EXPENSE TRANSACTION

Implement standalone Expense transaction for costs that do not require a full Vendor Invoice workflow. Minimum: expense number date posting date payee/vendor optional expense category description amount currency dimensions payment method/source where appropriate supporting document metadata status Use OA1 Accounting Core for posting.

25. EXPENSE LIFECYCLE

Baseline: DRAFT → SUBMITTED → APPROVED → POSTED Alternatives: REJECTED CANCELLED Expense may represent: accrued/payable expense or direct cash/bank expense Model the accounting path explicitly. Do not infer financial treatment from UI labels.

26. EXPENSE POSTING

Possible semantic paths: A. Payable Expense Dr Expense Cr Accounts Payable B. Direct Paid Expense Dr Expense Cr Cash/Bank Actual account resolution belongs to Posting Rules/Account Mapping. Do not duplicate posting engine logic.

27. EXPENSE VS AP INVOICE

Avoid duplicating the same financial document unnecessarily. Vendor Invoice: formal payable source document. Expense: simpler internal expense transaction. If Expense creates a payable, it must use the same AP control architecture rather than creating a second unrelated payable ledger. Document this boundary concisely.

28. CASH ACCOUNT

Implement tenant-owned Cash Account. Minimum: code name status currency mapped GL account branch/business unit where appropriate responsible scope metadata if useful A Cash Account is a financial operational account mapped to a GL account. Do not duplicate cash balance as independent financial truth.

29. BANK ACCOUNT

Implement tenant-owned Bank Account. Minimum: code/name bank name masked account number currency mapped GL account status branch/business unit where appropriate Protect sensitive account data. Do not expose full bank details unnecessarily in logs/audit/API responses.

30. CASH/BANK ACCOUNT MAPPING

Every active Cash/Bank account used for posting must resolve to a valid tenant-owned postable GL account. Prevent: cross-tenant GL mapping inactive account mapping non-postable account mapping Mapping changes affect future transactions only. Historical posted journals remain unchanged.

31. CASH PAYMENT

Support controlled Cash/Bank Payment transaction where required outside AP. Conceptual accounting: Dr configured destination account Cr Cash/Bank Use Posting Engine. Do not create a generic unrestricted money-moving endpoint. Require: purpose accounting classification dimensions where required authorization posting date period validation.

32. CASH RECEIPT FOUNDATION

Provide Cash Receipt foundation only to the extent needed for Cash/Bank ledger completeness. Do not implement Customer/AR receipt allocation. That belongs to OA3. A generic non-AR receipt may conceptually post: Dr Cash/Bank Cr configured account Use controlled posting rules. Do not implement revenue recognition broadly in OA2.

33. BANK TRANSACTION FOUNDATION

Implement internal bank transaction/history representation needed for Cash/Bank activity. Do NOT integrate external bank APIs. Do NOT implement bank statement import unless trivial existing infrastructure already supports it and it is necessary for reconciliation. Financial truth remains posted journals.

34. BANK RECONCILIATION FOUNDATION

Implement minimal manual reconciliation foundation. Support conceptually: statement/reference item book transaction match status reconciliation date matched by notes Suggested states: UNMATCHED MATCHED EXCEPTION Do not build advanced bank-feed matching algorithms. OA5 may extend reconciliation.

35. CASH/BANK BALANCE

Cash/Bank accounting balance derives from posted journal lines for the mapped GL account. Do not create independently editable balances. Operational bank statement balance may be stored separately for reconciliation, but it is not GL truth.

36. AP ↔ GL RECONCILIATION

Implement a reconciliation service/report proving: AP Subledger Balance vs AP Control Account GL Balance Provide: as_of_date tenant optional vendor breakdown difference status Suggested status: MATCHED MISMATCH Do not silently adjust either side. Mismatch must be observable.

37. CASH/BANK ↔ GL RECONCILIATION

Provide reconciliation between Cash/Bank operational transaction records and their mapped GL activity where appropriate. Also preserve distinction between: Book/GL balance and External statement balance Do not automatically create adjusting journals merely to force a match.

38. REVERSAL POLICY

Reuse OA1 reversal mechanism. Do not create custom reversal implementations per OA2 module. Vendor Invoice reversal Vendor Payment reversal Expense reversal Cash/Bank transaction reversal must use shared Accounting Core reversal semantics plus subledger state correction. Financial and subledger reversal must remain atomic.

39. ACCOUNTING PERIOD

Every OA2 financial posting uses explicit posting_date and OA1 Period Guard. Closed period means no posting. Do not bypass period lock for: invoice payment expense cash transaction reversal Any exceptional policy must use existing OA1 authorization architecture.

40. SEGREGATION OF DUTIES

Reuse OA1 SoD foundation. Support relevant policies such as: invoice creator != invoice approver payment creator != payment approver expense creator != expense approver Where enabled, enforce server-side. Do not hardcode role names.

41. DOCUMENT NUMBERING

Reuse OA1 numbering infrastructure. Add document types only as required: AP_INVOICE VENDOR_PAYMENT EXPENSE CASH_PAYMENT CASH_RECEIPT Do not create separate sequence engines. Numbers must remain concurrency-safe and immutable after issuance.

42. ATTACHMENT FOUNDATION

If repository already has secure file infrastructure, allow attachments for: Vendor Invoice Expense Payment Examples: invoice document receipt supporting document Enforce: tenant ownership authorization safe file type/size private storage secure download Do not build a large document-management system.

43. PERMISSIONS

Add only required atomic OA2 permissions. Examples: accounting.vendor.view accounting.vendor.manage accounting.ap_invoice.view accounting.ap_invoice.create accounting.ap_invoice.update accounting.ap_invoice.submit accounting.ap_invoice.approve accounting.ap_invoice.post accounting.ap_invoice.reverse accounting.ap_payment.view accounting.ap_payment.create accounting.ap_payment.submit accounting.ap_payment.approve accounting.ap_payment.post accounting.ap_payment.reverse accounting.ap_aging.view accounting.expense.view accounting.expense.create accounting.expense.update accounting.expense.submit accounting.expense.approve accounting.expense.post accounting.expense.reverse accounting.cash_bank.view accounting.cash_bank.manage accounting.cash_transaction.view accounting.cash_transaction.create accounting.cash_transaction.post accounting.cash_transaction.reverse accounting.bank_reconciliation.view accounting.bank_reconciliation.manage accounting.reconciliation.ap.view accounting.reconciliation.cash_bank.view Use existing equivalent permission codes rather than duplicating them. No role-name authorization.

44. DATA SCOPE

Reuse OA0/OA1 Data Scope. OA2 must honor applicable: TENANT BRANCH BUSINESS_UNIT COST_CENTER OWN where semantically appropriate Tenant/Scope protection applies to: vendors invoices payments expenses cash accounts bank accounts transactions aging reconciliation exports Do not leak out-of-scope financial totals through aggregate endpoints.

45. AUDIT

Reuse existing Audit infrastructure. Audit at minimum: Vendor financial changes Payment Term changes Vendor Invoice lifecycle Vendor Invoice posting/reversal Payment lifecycle Payment allocation/reversal Expense lifecycle Cash/Bank account changes Cash/Bank transaction posting/reversal Reconciliation actions critical exports Do not log full bank account numbers or sensitive attachment content.

46. DATABASE INTEGRITY

Use PostgreSQL constraints where appropriate. Protect: cross-tenant relationships duplicate vendor codes duplicate invoice identity where policy applies invalid amounts invalid allocations allocation > payment amount allocation > invoice outstanding invalid status relationships invalid GL account mapping duplicate posting references duplicate reversal Application validation complements DB integrity.

47. CONCURRENCY

Explicitly test/guard: duplicate Vendor Invoice posting two payments allocating same remaining invoice balance payment double posting payment reversal race invoice reversal vs payment race document numbering expense posting cash transaction posting Use transactions/locks/constraints where appropriate. Never allow negative outstanding due to race conditions.

48. REACT TENANT MENU

Extend tenant Accounting menu according to entitlement. Suggested: Accounting ├── Core │ ├── Manual Journal │ ├── General Ledger │ └── Trial Balance │ ├── Payables │ ├── Vendors │ ├── Vendor Invoices │ ├── Payments │ └── AP Aging │ ├── Expense │ ├── Expenses │ └── Expense Categories │ ├── Cash & Bank │ ├── Cash Accounts │ ├── Bank Accounts │ ├── Transactions │ └── Reconciliation │ └── Reconciliation ├── AP vs GL └── Cash/Bank vs GL Show sections only when corresponding entitlement/permission allows. Do not add OA3 menus.

49. DASHBOARD

Keep OA2 dashboard additions operational and concise. Useful metrics: Total AP Outstanding Overdue Payables Invoices Due Soon Pending Invoice Approval Pending Payment Approval Expenses Pending Approval Cash/Bank Book Balance Do not build OA7 analytics. Use authoritative accounting data.

50. FILTER / SEARCH

Provide useful server-side filters for financial lists. Examples: Vendor Status Document Date Posting Date Due Date Branch Business Unit Cost Center Payment Status Overdue Paginate large lists. Do not load entire financial datasets into React for client-side filtering.

51. EXPORT

If existing export infrastructure supports it, allow authorized export for: Vendor list AP invoices AP Aging Payments Expenses Cash/Bank transactions Reconciliation Exports must enforce the same: tenant entitlement permission data scope as UI/API. Do not add unnecessary export dependencies.

52. BACKWARD COMPATIBILITY

OA0/OA1 tenants without OA2 modules must behave exactly as before. OA2 must not make Vendor/AP/Expense/Cash setup mandatory for: authentication tenant management Accounting Core Manual Journal GL Trial Balance Each module remains independently entitlement-controlled subject to its configured dependencies.

53. OA2 STATUS FILE

Create/update: docs/status/OA2_STATUS.md Keep <= approximately 100 lines. Include only: Status Completed Batches Important Decisions Migrations Accounting Events Added Financial Invariants Tests Known Issues Remaining Next Phase Dependencies Do not rewrite MASTER/OA0/OA1 documentation unless a durable architecture decision genuinely changes.

54. TOKEN EFFICIENCY — STRICT

Repository state is authoritative. DO NOT print: complete files large diffs migration contents full schemas full API payloads full test logs dependency installation logs long accounting explanations MASTER/OA0/OA1 restatements this prompt Do not narrate routine edits. Inspect only files required by current batch. Do not repeatedly scan the whole repository. Use OA2_STATUS.md as continuation memory. Progress output <= approximately 15 lines.

55. IMPLEMENTATION BATCHES

Execute sequentially: A — Vendor + Payment Terms B — Vendor Invoice + Lines + Lifecycle C — AP Posting + AP Subledger + Outstanding D — Vendor Payment + Allocation + Reversal E — AP Aging + AP ↔ GL Reconciliation F — Expense Category + Expense Transaction G — Cash Account + Bank Account H — Cash/Bank Transactions + Reconciliation Foundation I — OA2 Posting Rules + Account Mappings + Audit J — React OA2 UI K — Financial Integrity + Concurrency Tests L — Tenant/RBAC/Entitlement/Security Tests M — OA0/OA1 Regression N — Release Gate Do not stop for approval between batches. For each batch:
1. inspect relevant code;
2. implement only current scope;
3. run targeted tests;
4. fix failures;
5. update OA2_STATUS.md concisely;
6. continue. Do not run full regression after every batch.

56. TARGETED TEST STRATEGY

Run only relevant tests during batches. Expected coverage areas: Vendor Payment Terms Vendor Invoice Invoice Lifecycle AP Posting AP Subledger Payment Allocation Payment Reversal AP Aging Expense Cash/Bank Reconciliation Tenant Isolation Data Scope Entitlement Permissions Concurrency Use repository naming conventions. Do not create redundant tests only to match suggested names.

57. CRITICAL AP TESTS

Release gate must prove:
1. Draft invoice does not affect GL.
2. Submitted invoice does not affect GL.
3. Approved but unposted invoice does not affect GL.
4. Posted invoice affects GL exactly once.
5. Posted invoice creates correct AP subledger balance.
6. Posted invoice cannot be financially edited.
7. Duplicate invoice posting is prevented.
8. Invoice in CLOSED period cannot post.
9. Cross-tenant account/dimension cannot post.
10. Partial payment reduces outstanding correctly.
11. Multiple payments reduce outstanding correctly.
12. Multiple invoice allocation works.
13. Allocation cannot exceed payment amount.
14. Allocation cannot exceed invoice outstanding.
15. Concurrent payments cannot over-allocate invoice.
16. Posted payment affects GL exactly once.
17. Payment reversal restores outstanding correctly.
18. Duplicate payment reversal is prevented.
19. AP Aging uses due date/as-of date correctly.
20. AP subledger reconciles to GL control account.

58. CRITICAL EXPENSE TESTS

Prove: Expense draft does not affect GL. Posted payable expense uses Accounting Core. Posted direct-paid expense uses Accounting Core. Expense cannot bypass period lock. Posted expense is immutable. Expense reversal preserves history. Permission/SoD applies. Cross-tenant dimensions/accounts are rejected.

59. CRITICAL CASH/BANK TESTS

Prove: Cash/Bank account maps to valid tenant GL account. Cross-tenant mapping is rejected. Posted transaction affects GL once. Closed period blocks transaction. Posted transaction is immutable. Reversal uses Accounting Core. Book balance derives from posted GL. Reconciliation does not silently alter GL. Sensitive bank data is not exposed improperly.

60. TENANT ISOLATION

Tenant A must never access Tenant B: vendors payment terms invoices invoice lines payments allocations expenses cash accounts bank accounts transactions AP aging reconciliation attachments exports Test relevant: GET LIST SEARCH/FILTER CREATE relationship UPDATE DELETE/deactivate SUBMIT APPROVE POST REVERSE EXPORT Cross-tenant financial access is P0.

61. ENTITLEMENT / READ_ONLY

Test each OA2 module for: ACTIVE READ_ONLY SUSPENDED DISABLED expired future-effective READ_ONLY must block mutations/posting/reversal while retaining authorized historical visibility according to policy. Parent ACCOUNTING_CORE unavailability must prevent OA2 financial posting.

62. ACCOUNTING CORE REGRESSION

OA2 must not break OA1: Accounting Profile Fiscal Year Accounting Period COA Dimensions Manual Journal Journal lifecycle Posting Engine Posting Rules Account Mapping Reversal Opening Balance GL Trial Balance Run targeted OA1 regression during development. Run appropriate broader OA0/OA1 regression at release gate.

63. FRONTEND VALIDATION

Verify: entitlement-based navigation permission-based actions READ_ONLY behavior invoice workflow payment allocation UX partial payment aging filters expense workflow cash/bank transaction workflow reconciliation states loading/error/empty states production build Backend remains authoritative.

64. SECURITY

Treat as release blockers: cross-tenant financial access permission bypass entitlement bypass READ_ONLY bypass duplicate financial posting allocation race causing negative outstanding posted transaction mutation period lock bypass cross-tenant GL mapping sensitive bank information leakage attachment authorization bypass financial export leakage

65. RELEASE GATE

Before COMMIT READY verify: [ ] OA0/OA1 remain stable [ ] Vendor works [ ] Payment Terms work [ ] Vendor Invoice lifecycle works [ ] AP Invoice posting uses OA1 Posting Engine [ ] No independent debit/credit engine exists [ ] Posted invoice is immutable [ ] Duplicate invoice protection works [ ] AP Subledger works [ ] Outstanding Payable works [ ] Partial Payment works [ ] Multi-Invoice Allocation works [ ] Over-allocation prevented [ ] Concurrent over-allocation prevented [ ] Payment posting works [ ] Payment reversal works [ ] AP Aging works [ ] AP ↔ GL reconciliation works [ ] Expense workflow works [ ] Expense posting uses OA1 Posting Engine [ ] Cash Account works [ ] Bank Account works [ ] Cash/Bank transactions work [ ] Cash/Bank reconciliation foundation works [ ] Period locking works for all OA2 postings [ ] SoD/permissions work [ ] Entitlements/READ_ONLY work [ ] Tenant isolation passes [ ] Data Scope passes [ ] Audit works [ ] React UI works [ ] Frontend production build passes [ ] Fresh migrations/seeds pass [ ] OA0/OA1 regression passes [ ] No OA3 functionality accidentally implemented [ ] No OptiFleet dependency introduced [ ] No P0/P1 issue remains

66. DEFINITION OF DONE

OA2 is COMMIT READY only when:
- implementation is complete;
- all OA2 financial posting uses OA1 Accounting Core;
- AP subledger reconciles to GL;
- payment allocation integrity is proven;
- period locking is enforced;
- posted transactions are immutable;
- tenant isolation passes;
- RBAC/Data Scope passes;
- entitlement/READ_ONLY passes;
- critical concurrency tests pass;
- frontend production build passes;
- migrations/seeds pass;
- OA0/OA1 regression passes;
- OA2_STATUS.md is current;
- no fake PASS exists;
- no TODO/mock implementation is counted as complete;
- OA3 has not been started.

67. BLOCKER POLICY

Do not ask questions for ordinary implementation decisions. Use: CLAUDE.md architecture docs OA0/OA1 implementation repository conventions Stop only for a genuine blocker requiring owner decision. If validation cannot run due to environment limitations: report NOT RUN with concise reason. Never convert NOT RUN to PASS.

68. PROGRESS OUTPUT

During implementation output only: OptiAccounting OA2 Progress Batch: <batch> Completed:
- ... Validation:
- ... Remaining:
- ... Blocker:
- none / ... Keep <= approximately 15 lines.

69. FINAL OUTPUT

When release gate completes output only: OPTIACCOUNTING OA2 — RELEASE GATE STATUS: COMMIT READY / NOT READY Completed:
- maximum 10 concise items Financial Integrity:
- AP Posting: PASS/FAIL/NOT RUN
- AP ↔ GL Reconciliation: PASS/FAIL/NOT RUN
- Payment Allocation: PASS/FAIL/NOT RUN
- Payment Reversal: PASS/FAIL/NOT RUN
- Expense Posting: PASS/FAIL/NOT RUN
- Cash/Bank Posting: PASS/FAIL/NOT RUN
- Period Locking: PASS/FAIL/NOT RUN
- Transaction Immutability: PASS/FAIL/NOT RUN Validation:
- Backend: PASS/FAIL/NOT RUN
- OA0/OA1 Regression: PASS/FAIL/NOT RUN
- Tenant Isolation: PASS/FAIL/NOT RUN
- RBAC/Data Scope: PASS/FAIL/NOT RUN
- Entitlement/READ_ONLY: PASS/FAIL/NOT RUN
- Concurrency: PASS/FAIL/NOT RUN
- Security: PASS/FAIL/NOT RUN
- Frontend Build: PASS/FAIL/NOT RUN
- Migration/Seed: PASS/FAIL/NOT RUN Critical Issues:
- none / ... Known Non-Blocking Issues:
- none / ... OA2_STATUS.md:
- UPDATED / NOT UPDATED Next Phase: OA3 — Accounts Receivable & Revenue Suggested Commit: feat: implement accounts payable expense and cash bank Do not start OA3. Stop and wait for explicit instruction.
