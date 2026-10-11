import type { DocSod, DocStatus, DocTransition, PaymentTerm, Ref } from '../../../lib/operational'
import type { Actor, CashBankRef, Coded, ForeignFields, LineTaxFields, PaymentStatus } from '../payables/types'

// Shapes of the receivables API responses (backend: Receivables\Services\*::load()/query(), ArReportController). Amounts are decimal
// strings ("1500000.0000") that the backend computed; the UI only formats them. Every figure of an invoice (received, credited,
// outstanding, payment status) is derived by the server from the posted receipts and credit notes; nothing is stored or edited.

export type CustomerRef = Coded & { status?: string }

export type ArInvoiceRow = ForeignFields & {
  id: string
  document_number: string | null
  status: DocStatus
  customer_id: string
  customer: CustomerRef | null
  customer_reference: string | null
  document_date: string
  posting_date: string
  due_date: string
  currency: string
  description: string
  total_amount: string
  /** Derived by the server; `outstanding_amount` and `payment_status` exist for POSTED invoices only. */
  received_amount: string
  credited_amount: string
  outstanding_amount: string
  payment_status: PaymentStatus | null
  creator?: Actor | null
  branch?: Ref | null
}

export type ArInvoiceLine = LineTaxFields & {
  id: string
  line_number: number
  description: string
  quantity: string | null
  unit_price: string | null
  amount: string
  account_role: string | null
  account_id: string | null
  cost_center_id: string | null
  account?: Coded | null
  cost_center?: Coded | null
}

/** A receipt allocation that currently settles the invoice (the API lists the effective ones only). */
export type InvoiceReceiptAllocation = {
  id: string
  customer_receipt_id: string
  ar_invoice_id: string
  amount: string
  is_effective: boolean
  effective_at: string | null
  receipt?: { id: string; document_number: string | null; receipt_date: string; posting_date: string; status: string } | null
}

/** A posted credit note that currently reduces the invoice. */
export type InvoiceCreditNote = { id: string; document_number: string | null; posting_date: string; reason: string; total_amount: string; status: string }

export type PossibleDuplicate = { id: string; document_number: string | null; customer_reference: string | null; status: string }

export type ArInvoice = ArInvoiceRow & {
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
  posted_at: string | null
  lines?: ArInvoiceLine[]
  allocations?: InvoiceReceiptAllocation[]
  credit_notes?: InvoiceCreditNote[]
  transitions?: DocTransition[]
  sod?: DocSod
  /** Soft duplicate warning: other live invoices of the customer with the same reference, or the same total on the same document date. */
  possible_duplicates?: PossibleDuplicate[]
}

/** A posted invoice with something still outstanding (GET /customers/{id}/open-invoices), oldest due date first. */
export type OpenArInvoice = {
  id: string
  document_number: string | null
  customer_reference: string | null
  posting_date: string
  due_date: string
  currency?: string
  exchange_rate?: string
  total_amount: string
  received_amount: string
  credited_amount: string
  outstanding_amount: string
  /** What the invoice still carries in the ledger, in functional currency (only differs from the outstanding amount for a foreign invoice). */
  outstanding_functional?: string
  branch?: Ref | null
}

export type SuggestedAllocation = { ar_invoice_id: string; amount: string }

export type ReceiptRow = ForeignFields & {
  id: string
  document_number: string | null
  status: DocStatus
  customer_id: string
  customer: CustomerRef | null
  cash_bank_account_id: string
  cash_bank_account: CashBankRef | null
  receipt_date: string
  posting_date: string
  currency: string
  amount: string
  receipt_method: string | null
  reference: string | null
  description: string | null
  allocated_amount?: string
  creator?: Actor | null
  branch?: Ref | null
}

export type ReceiptAllocation = {
  id: string
  customer_receipt_id: string
  ar_invoice_id: string
  amount: string
  /** Foreign receipt: the functional value released from the invoice and the functional value that settled it (set when the receipt is posted). */
  carrying_amount?: string | null
  settlement_amount?: string | null
  is_effective: boolean
  effective_at: string | null
  released_at: string | null
  invoice?: { id: string; document_number: string | null; customer_reference: string | null; due_date: string; total_amount: string; status: string } | null
}

export type Receipt = ReceiptRow & {
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
  /** Computed by the server: the receipt amount minus what is allocated. A receipt must be allocated in full before it is approved or posted. */
  unallocated_amount?: string
  allocations?: ReceiptAllocation[]
  transitions?: DocTransition[]
  sod?: DocSod
}

export type CreditNoteLine = ArInvoiceLine

export type CreditNoteRow = {
  id: string
  document_number: string | null
  status: DocStatus
  customer_id: string
  customer: CustomerRef | null
  ar_invoice_id: string
  invoice: { id: string; document_number: string | null; customer_reference: string | null; due_date: string; total_amount: string; status: string; posting_date: string } | null
  document_date: string
  posting_date: string
  currency: string
  reason: string
  reference: string | null
  subtotal_amount: string
  tax_amount: string
  total_amount: string
  creator?: Actor | null
  branch?: Ref | null
}

export type CreditNote = CreditNoteRow & {
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
  /** What is still outstanding on the credited invoice right now, as the server computed it. */
  invoice_outstanding?: string
  lines?: CreditNoteLine[]
  transitions?: DocTransition[]
  sod?: DocSod
}

export type ArAgingBucket = { key: string; label: string; from: number | null; to: number | null }
export type ArAgingCustomerRow = { customer_id: string; customer_code: string; customer_name: string; invoice_count: number; buckets: Record<string, string>; total: string }
export type ArAgingInvoiceRow = {
  id: string
  document_number: string | null
  customer_reference: string | null
  customer_id: string
  customer_code: string
  customer_name: string
  posting_date: string
  due_date: string
  days_overdue: number
  bucket: string
  currency?: string
  exchange_rate?: string
  total_amount: string
  received_amount: string
  credited_amount: string
  outstanding_amount: string
  /** Buckets and totals are functional; a foreign invoice also shows its own amounts, its rate and this functional balance. */
  outstanding_functional?: string
}
export type ArAgingReport = {
  as_of: string
  buckets: ArAgingBucket[]
  data: ArAgingCustomerRow[]
  totals: Record<string, string>
  invoice_count: number
  complete: boolean
  invoices?: ArAgingInvoiceRow[]
}

export type ArReconciliationCustomer = { customer_id: string; customer_code: string | null; customer_name: string | null; gl_balance: string; subledger_balance: string; difference: string; status: 'MATCHED' | 'MISMATCH' }
export type ArReconciliationReport = {
  as_of: string
  customer_id: string | null
  control_accounts: { id: string; code: string; name: string; gl_balance: string }[]
  gl_balance: string
  opening_balance_component: string
  gl_transactional_balance: string
  subledger_balance: string
  difference: string
  status: 'MATCHED' | 'MISMATCH'
  customers: ArReconciliationCustomer[]
  complete: boolean
}
