import type { DocSod, DocStatus, DocTransition, PaymentTerm, Ref } from '../../../lib/operational'

// Shapes of the payables API responses (backend: Payables\Services\*::load()/query(), ApReportController). Amounts are decimal strings
// ("1500000.0000") that the backend computed; the UI only formats them.

export type PaymentStatus = 'UNPAID' | 'PARTIALLY_PAID' | 'PAID'
export type Coded = { id: string; code: string; name: string }
export type Actor = { id: string; name: string }
export type VendorRef = Coded & { status?: string }

/**
 * The foreign-currency snapshot the API returns on invoices, payments and receipts (backend: ForeignDocumentService). A functional document has no
 * rate id and no functional amount. `fx_difference` (payments and receipts) is the realised exchange difference: positive = loss for a payment, gain for a receipt.
 */
export type ForeignFields = {
  exchange_rate?: string | null
  exchange_rate_id?: string | null
  exchange_rate_date?: string | null
  exchange_rate_type?: string | null
  /** Invoices: the total in functional currency. */
  functional_total_amount?: string | null
  /** Invoices: what is still carried in the ledger, in functional currency. */
  outstanding_functional?: string | null
  /** Payments and receipts: the amount in functional currency. */
  functional_amount?: string | null
  fx_difference?: string | null
}

/** A tax code the user chose for a line, with the amount as entered (the line `amount` is then the tax base). */
export type LineTaxFields = { tax_code_id?: string | null; entered_amount?: string | null }

export type InvoiceRow = ForeignFields & {
  id: string
  document_number: string | null
  origin: 'INVOICE' | 'EXPENSE'
  status: DocStatus
  vendor_id: string
  vendor: VendorRef | null
  vendor_invoice_number: string
  document_date: string
  posting_date: string
  due_date: string
  currency: string
  description: string
  total_amount: string
  /** Derived by the server from effective payment allocations; outstanding exists for POSTED invoices only. */
  paid_amount: string
  outstanding_amount: string
  payment_status: PaymentStatus | null
  creator?: Actor | null
  branch?: Ref | null
}

export type InvoiceLine = LineTaxFields & {
  id: string
  line_number: number
  description: string
  quantity: string | null
  unit_price: string | null
  amount: string
  expense_category_id: string | null
  account_role: string | null
  account_id: string | null
  cost_center_id: string | null
  expense_category?: Coded | null
  account?: Coded | null
  cost_center?: Coded | null
}

export type InvoiceAllocation = {
  id: string
  vendor_payment_id: string
  ap_invoice_id: string
  amount: string
  is_effective: boolean
  effective_at: string | null
  payment?: { id: string; document_number: string | null; payment_date: string; posting_date: string; status: string } | null
}

export type PossibleDuplicate = { id: string; document_number: string | null; vendor_invoice_number: string; status: string }

export type Invoice = InvoiceRow & {
  reference: string | null
  payment_term_id: string | null
  payment_term?: Pick<PaymentTerm, 'id' | 'code' | 'name'> | null
  due_date_overridden: boolean
  subtotal_amount: string
  discount_amount: string
  tax_amount: string
  other_charges_amount: string
  branch_id: string | null
  business_unit_id: string | null
  cost_center_id: string | null
  business_unit?: Ref | null
  cost_center?: Ref | null
  journal_entry_id: string | null
  reversal_journal_id: string | null
  reversal_reason?: string | null
  reject_reason?: string | null
  cancel_reason?: string | null
  duplicate_override_by?: string | null
  duplicate_override_reason?: string | null
  source_type?: string | null
  source_id?: string | null
  posted_at: string | null
  lines?: InvoiceLine[]
  allocations?: InvoiceAllocation[]
  transitions?: DocTransition[]
  sod?: DocSod
  possible_duplicates?: PossibleDuplicate[]
}

export type DuplicateCheck = { duplicate: boolean; status: string | null; document_number: string | null }

/** A posted invoice with something still outstanding (GET /vendors/{id}/open-invoices). */
export type OpenInvoice = {
  id: string
  document_number: string | null
  vendor_invoice_number: string
  posting_date: string
  due_date: string
  currency?: string
  exchange_rate?: string
  total_amount: string
  paid_amount: string
  outstanding_amount: string
  /** What the invoice still carries in the ledger, in functional currency (only differs from the outstanding amount for a foreign invoice). */
  outstanding_functional?: string
  payment_status: PaymentStatus | null
  branch?: Ref | null
}

export type CashBankRef = { id: string; code: string; name: string; kind?: string; bank_name?: string | null; account_number_masked?: string | null; status?: string }

export type PaymentRow = ForeignFields & {
  id: string
  document_number: string | null
  status: DocStatus
  vendor_id: string
  vendor: VendorRef | null
  cash_bank_account_id: string
  cash_bank_account: CashBankRef | null
  payment_date: string
  posting_date: string
  currency: string
  amount: string
  payment_method: string | null
  reference: string | null
  description: string | null
  allocated_amount?: string
  creator?: Actor | null
  branch?: Ref | null
}

export type PaymentAllocation = {
  id: string
  vendor_payment_id: string
  ap_invoice_id: string
  amount: string
  /** Foreign payment: the functional value released from the invoice and the functional value that settled it (set when the payment is posted). */
  carrying_amount?: string | null
  settlement_amount?: string | null
  is_effective: boolean
  effective_at: string | null
  released_at: string | null
  invoice?: { id: string; document_number: string | null; vendor_invoice_number: string; due_date: string; total_amount: string; status: string } | null
}

export type Payment = PaymentRow & {
  gl_account?: Coded | null
  branch_id: string | null
  business_unit_id: string | null
  cost_center_id: string | null
  business_unit?: Ref | null
  cost_center?: Ref | null
  journal_entry_id: string | null
  reversal_journal_id: string | null
  reversal_reason?: string | null
  reject_reason?: string | null
  cancel_reason?: string | null
  posted_at: string | null
  unallocated_amount?: string
  allocations?: PaymentAllocation[]
  transitions?: DocTransition[]
  sod?: DocSod
}

export type SuggestedAllocation = { ap_invoice_id: string; amount: string }

export type AgingBucket = { key: string; label: string; from: number | null; to: number | null }
export type AgingVendorRow = { vendor_id: string; vendor_code: string; vendor_name: string; invoice_count: number; buckets: Record<string, string>; total: string }
export type AgingInvoiceRow = {
  id: string
  document_number: string | null
  vendor_invoice_number: string
  vendor_id: string
  vendor_code: string
  vendor_name: string
  posting_date: string
  due_date: string
  days_overdue: number
  bucket: string
  currency?: string
  exchange_rate?: string
  total_amount: string
  paid_amount: string
  outstanding_amount: string
  /** Buckets and totals are functional; a foreign invoice also shows its own amounts, its rate and this functional balance. */
  outstanding_functional?: string
}
export type AgingReport = {
  as_of: string
  buckets: AgingBucket[]
  data: AgingVendorRow[]
  totals: Record<string, string>
  invoice_count: number
  complete: boolean
  invoices?: AgingInvoiceRow[]
}

export type ReconciliationVendor = { vendor_id: string; vendor_code: string | null; vendor_name: string | null; gl_balance: string; subledger_balance: string; difference: string; status: 'MATCHED' | 'MISMATCH' }
export type ApReconciliation = {
  as_of: string
  vendor_id: string | null
  control_accounts: { id: string; code: string; name: string; gl_balance: string }[]
  gl_balance: string
  opening_balance_component: string
  gl_transactional_balance: string
  subledger_balance: string
  difference: string
  status: 'MATCHED' | 'MISMATCH'
  vendors: ReconciliationVendor[]
  complete: boolean
}
