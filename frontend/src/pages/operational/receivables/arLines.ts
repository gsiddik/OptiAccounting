import { decimal, plainAmount } from '../payables/invoiceForm'
import type { ArInvoiceLine } from './types'

// Line state shared by the customer-invoice and the credit-note editors: the form value, the exact API payload built from it, and a
// check for the obvious mistakes. Totals are previewed by `previewInvoice` (payables/invoiceForm.ts) and always recomputed by the server.

export type ArLineForm = {
  key: string
  description: string
  /** true: quantity x unit price; false: a plain amount. */
  useQuantity: boolean
  quantity: string
  unit_price: string
  amount: string
  account_role: string
  account_id: string
  cost_center_id: string
  /** Optional output tax code (customer invoices with the tax module only; credit notes never carry one). */
  tax_code_id: string
}

let counter = 0
export const emptyArLine = (): ArLineForm => ({ key: `al${++counter}`, description: '', useQuantity: false, quantity: '', unit_price: '', amount: '', account_role: '', account_id: '', cost_center_id: '', tax_code_id: '' })

export function arLinesFrom(lines: ArInvoiceLine[] | undefined): ArLineForm[] {
  if (!lines || lines.length === 0) return [emptyArLine()]
  return lines.map((l) => ({
    key: `al${++counter}`,
    description: l.description,
    useQuantity: l.quantity !== null && l.unit_price !== null,
    quantity: plainAmount(l.quantity),
    unit_price: plainAmount(l.unit_price),
    // A taxed line stores the base in `amount`; the editor works with what was entered.
    amount: plainAmount(l.tax_code_id ? l.entered_amount : l.amount),
    account_role: l.account_role ?? '',
    account_id: l.account_id ?? '',
    cost_center_id: l.cost_center_id ?? '',
    tax_code_id: l.tax_code_id ?? '',
  }))
}

export function arLinePayload(l: ArLineForm) {
  return {
    description: l.description.trim(),
    ...(l.useQuantity ? { quantity: decimal(l.quantity), unit_price: decimal(l.unit_price) } : { amount: decimal(l.amount) }),
    account_role: l.account_role || null,
    account_id: l.account_id || null,
    cost_center_id: l.cost_center_id || null,
    ...(l.tax_code_id ? { tax_code_id: l.tax_code_id } : {}),
  }
}

/** The first line without a description, as a 1-based number, or null. */
export function firstUndescribedLine(lines: ArLineForm[]): number | null {
  const empty = lines.findIndex((l) => !l.description.trim())
  return empty < 0 ? null : empty + 1
}
