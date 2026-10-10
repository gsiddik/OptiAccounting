import type { DocSod, DocStatus, DocTransition, Ref } from '../../../lib/operational'

export type Settlement = 'PAYABLE' | 'DIRECT_PAID'

export const SETTLEMENTS: Settlement[] = ['PAYABLE', 'DIRECT_PAID']

/** The payable a posted PAYABLE expense created in the AP subledger; every figure is computed by the API. */
export type ExpensePayable = {
  id: string
  document_number: string | null
  status: string
  due_date: string | null
  total_amount: string
  paid_amount: string
  outstanding_amount: string
  payment_status: 'UNPAID' | 'PARTIALLY_PAID' | 'PAID' | null
}

export type Expense = {
  id: string
  document_number: string | null
  settlement: Settlement
  status: DocStatus
  vendor_id: string | null
  payee_name: string | null
  expense_category_id: string
  account_id: string | null
  expense_date: string
  posting_date: string
  due_date: string | null
  payment_term_id: string | null
  due_date_overridden: boolean
  currency: string
  description: string
  reference: string | null
  payment_method: string | null
  supporting_document: string | null
  cash_bank_account_id: string | null
  branch_id: string | null
  business_unit_id: string | null
  cost_center_id: string | null
  net_amount: string
  tax_amount: string
  total_amount: string
  created_by: string | null
  posted_at: string | null
  journal_entry_id: string | null
  reversal_journal_id: string | null
  reversal_reason: string | null
  reversal_posting_date: string | null
  reject_reason: string | null
  cancel_reason: string | null
  creator?: { id: string; name: string } | null
  vendor?: { id: string; code: string; name: string; status: string } | null
  category?: { id: string; code: string; name: string; status: string } | null
  account?: Ref | null
  payment_term?: { id: string; code: string; name: string } | null
  cash_bank_account?: { id: string; code: string; name: string; kind: 'CASH' | 'BANK'; bank_name: string | null; account_number_masked: string | null; status: string } | null
  gl_account?: Ref | null
  branch?: Ref | null
  business_unit?: Ref | null
  cost_center?: Ref | null
  transitions?: DocTransition[]
  payable?: ExpensePayable | null
  sod?: DocSod
}
