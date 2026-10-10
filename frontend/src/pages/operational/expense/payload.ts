import { amountToApi, formatAmount, parseAmount } from '../../../lib/accounting'
import type { PaymentTerm, Vendor } from '../../../lib/operational'
import type { Expense, Settlement } from './types'

// The expense form's state and the request body built from it. No accounting math lives here: the total shown while typing is a preview in
// exact integer arithmetic, and the API recomputes the net, tax and total it stores.

export type ExpenseForm = {
  settlement: Settlement
  expense_category_id: string
  description: string
  expense_date: string
  posting_date: string
  supporting_document: string
  reference: string
  vendor_id: string
  payment_term_id: string
  due_date: string
  cash_bank_account_id: string
  payment_method: string
  payee_name: string
  net_amount: string
  tax_amount: string
  /** Optional input tax code (only with the tax module). With one, `net_amount` is what the user entered and the server splits base and tax. */
  tax_code_id: string
  branch_id: string
  business_unit_id: string
  cost_center_id: string
}

export const emptyExpenseForm = (today: string): ExpenseForm => ({
  settlement: 'PAYABLE', expense_category_id: '', description: '', expense_date: today, posting_date: today, supporting_document: '', reference: '',
  vendor_id: '', payment_term_id: '', due_date: '', cash_bank_account_id: '', payment_method: '', payee_name: '', net_amount: '', tax_amount: '', tax_code_id: '',
  branch_id: '', business_unit_id: '', cost_center_id: '',
})

/** "1000000.5000" -> "1000000.5"; a zero amount becomes an empty input. */
export function amountInput(value: string | null | undefined): string {
  if (!value || /^0+(\.0+)?$/.test(value)) return ''
  return value.includes('.') ? value.replace(/\.?0+$/, '') : value
}

export function expenseFormFrom(e: Expense): ExpenseForm {
  return {
    settlement: e.settlement, expense_category_id: e.expense_category_id, description: e.description, expense_date: e.expense_date.slice(0, 10), posting_date: e.posting_date.slice(0, 10),
    supporting_document: e.supporting_document ?? '', reference: e.reference ?? '', vendor_id: e.vendor_id ?? '', payment_term_id: e.payment_term_id ?? '',
    due_date: e.due_date_overridden ? (e.due_date ?? '').slice(0, 10) : '', cash_bank_account_id: e.cash_bank_account_id ?? '', payment_method: e.payment_method ?? '',
    // With a tax code the stored net is the base and the tax is the server's calculation: the form holds what was entered and no manual tax.
    payee_name: e.payee_name ?? '', net_amount: amountInput(e.tax_code_id ? e.entered_amount : e.net_amount), tax_amount: e.tax_code_id ? '' : amountInput(e.tax_amount), tax_code_id: e.tax_code_id ?? '',
    branch_id: e.branch_id ?? '', business_unit_id: e.business_unit_id ?? '', cost_center_id: e.cost_center_id ?? '',
  }
}

/** Net + tax as the API will store them, for display while typing. null when either amount is not a plain decimal. */
export function previewTotal(f: Pick<ExpenseForm, 'net_amount' | 'tax_amount'>): string | null {
  const net = parseAmount(f.net_amount)
  const tax = parseAmount(f.tax_amount)
  return net === null || tax === null ? null : formatAmount(amountToApi(net + tax))
}

/** How the due-date field behaves for a payment term: free (optional), required (a custom term) or locked (the term fixes the date). */
export function dueDateRule(term: PaymentTerm | undefined): 'free' | 'required' | 'locked' {
  if (!term) return 'free'
  if (term.term_type === 'CUSTOM') return 'required'
  return term.allows_due_date_override ? 'free' : 'locked'
}

export type Built = { body: Record<string, string | null> } | { problem: string }

/**
 * The request body for the chosen settlement path. Amounts are decimal strings, never numbers. With a tax code the manual tax is sent as null (the
 * server calculates it; a different amount is refused); `hadTaxCode` says the saved draft had one, so removing it is sent as an explicit null.
 */
export function buildExpenseBody(f: ExpenseForm, vendors: Vendor[], terms: PaymentTerm[], options: { hadTaxCode?: boolean } = {}): Built {
  const net = parseAmount(f.net_amount)
  const tax = parseAmount(f.tax_amount)
  if (net === null || tax === null) return { problem: 'Jumlah neto dan pajak harus berupa angka tanpa pemisah ribuan, dengan maksimal empat desimal.' }

  const common = {
    settlement: f.settlement,
    expense_category_id: f.expense_category_id,
    expense_date: f.expense_date,
    posting_date: f.posting_date,
    net_amount: amountToApi(net),
    tax_amount: f.tax_code_id ? null : amountToApi(tax),
    ...(f.tax_code_id || options.hadTaxCode ? { tax_code_id: f.tax_code_id || null } : {}),
    supporting_document: f.supporting_document.trim() || null,
    description: f.description.trim(),
    reference: f.reference.trim() || null,
    branch_id: f.branch_id || null,
    business_unit_id: f.business_unit_id || null,
    cost_center_id: f.cost_center_id || null,
  }

  if (f.settlement === 'DIRECT_PAID') {
    return { body: { ...common, vendor_id: null, cash_bank_account_id: f.cash_bank_account_id, payment_method: f.payment_method || null, payee_name: f.payee_name.trim() || null } }
  }
  // Without a chosen term the vendor's own term applies; the due date is sent only where the term lets the user pick it.
  const vendor = vendors.find((v) => v.id === f.vendor_id)
  const termId = f.payment_term_id || vendor?.payment_term_id || null
  const rule = dueDateRule(terms.find((t) => t.id === termId))
  return { body: { ...common, vendor_id: f.vendor_id, payment_term_id: termId, due_date: rule === 'locked' ? null : f.due_date || null } }
}
