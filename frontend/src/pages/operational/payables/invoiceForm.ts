import { amountToApi, parseAmount } from '../../../lib/accounting'
import type { Invoice, InvoiceLine } from './types'

// Form state of the vendor-invoice editor, the exact API payload built from it, and a live PREVIEW of the totals.
// The preview is exact integer arithmetic (BigInt, units of 1/10 000) and nothing more: the server recomputes every amount.

export type LineForm = {
  key: string
  description: string
  /** true: quantity x unit price; false: a plain amount. */
  useQuantity: boolean
  quantity: string
  unit_price: string
  amount: string
  expense_category_id: string
  account_role: string
  account_id: string
  cost_center_id: string
}

export type InvoiceHeader = {
  vendor_id: string
  vendor_invoice_number: string
  document_date: string
  posting_date: string
  due_date: string
  payment_term_id: string
  description: string
  reference: string
  branch_id: string
  business_unit_id: string
  cost_center_id: string
  discount_amount: string
  tax_amount: string
  other_charges_amount: string
}

let counter = 0
export const emptyInvoiceLine = (): LineForm => ({
  key: `il${++counter}`, description: '', useQuantity: false, quantity: '', unit_price: '', amount: '', expense_category_id: '', account_role: '', account_id: '', cost_center_id: '',
})

/** "1000000.0000" -> "1000000", "12.5000" -> "12.5": how a stored amount is shown in an input. */
export const plainAmount = (value: string | null | undefined): string => {
  if (!value) return ''
  return value.includes('.') ? value.replace(/\.?0+$/, '') : value
}

export function headerFrom(invoice: Invoice | null, today: string): InvoiceHeader {
  return {
    vendor_id: invoice?.vendor_id ?? '',
    vendor_invoice_number: invoice?.vendor_invoice_number ?? '',
    document_date: invoice?.document_date.slice(0, 10) ?? today,
    posting_date: invoice?.posting_date.slice(0, 10) ?? today,
    // Only a due date the user typed is editable; a derived one is recomputed by the server.
    due_date: invoice?.due_date_overridden ? invoice.due_date.slice(0, 10) : '',
    payment_term_id: invoice?.payment_term_id ?? '',
    description: invoice?.description ?? '',
    reference: invoice?.reference ?? '',
    branch_id: invoice?.branch_id ?? '',
    business_unit_id: invoice?.business_unit_id ?? '',
    cost_center_id: invoice?.cost_center_id ?? '',
    discount_amount: plainAmount(invoice?.discount_amount),
    tax_amount: plainAmount(invoice?.tax_amount),
    other_charges_amount: plainAmount(invoice?.other_charges_amount),
  }
}

export function linesFromInvoice(lines: InvoiceLine[] | undefined): LineForm[] {
  if (!lines || lines.length === 0) return [emptyInvoiceLine()]
  return lines.map((l) => ({
    key: `il${++counter}`,
    description: l.description,
    useQuantity: l.quantity !== null && l.unit_price !== null,
    quantity: plainAmount(l.quantity),
    unit_price: plainAmount(l.unit_price),
    amount: plainAmount(l.amount),
    expense_category_id: l.expense_category_id ?? '',
    account_role: l.account_role ?? '',
    account_id: l.account_id ?? '',
    cost_center_id: l.cost_center_id ?? '',
  }))
}

// ------------------------------------------------------------------------------------------------ payload

/** A typed amount as the API wants it: a normalized decimal string ("1500000.0000"), or the raw text when it is not a number (so it is refused, never guessed). */
export function decimal(input: string): string {
  const units = parseAmount(input)
  return units === null ? input.trim() : amountToApi(units)
}

/** Optional header amount: empty is null, anything else a decimal string. */
export function optionalDecimal(input: string): string | null {
  return input.trim() === '' ? null : decimal(input)
}

export function linePayload(l: LineForm) {
  return {
    description: l.description.trim(),
    ...(l.useQuantity ? { quantity: decimal(l.quantity), unit_price: decimal(l.unit_price) } : { amount: decimal(l.amount) }),
    expense_category_id: l.expense_category_id || null,
    account_role: l.account_role || null,
    account_id: l.account_id || null,
    cost_center_id: l.cost_center_id || null,
  }
}

export type DuplicateOverride = { reason: string }

/** The body of POST / PATCH /ap-invoices. `dueDateEditable` says whether the term lets the user name the due date. */
export function invoicePayload(h: InvoiceHeader, lines: LineForm[], options: { dueDateEditable: boolean; override: DuplicateOverride | null }) {
  return {
    vendor_id: h.vendor_id,
    vendor_invoice_number: h.vendor_invoice_number.trim(),
    document_date: h.document_date,
    posting_date: h.posting_date,
    due_date: options.dueDateEditable && h.due_date ? h.due_date : null,
    payment_term_id: h.payment_term_id || null,
    description: h.description.trim(),
    reference: h.reference.trim() || null,
    branch_id: h.branch_id || null,
    business_unit_id: h.business_unit_id || null,
    cost_center_id: h.cost_center_id || null,
    discount_amount: optionalDecimal(h.discount_amount),
    tax_amount: optionalDecimal(h.tax_amount),
    other_charges_amount: optionalDecimal(h.other_charges_amount),
    lines: lines.map(linePayload),
    ...(options.override ? { duplicate_override: true, duplicate_override_reason: options.override.reason.trim() } : {}),
  }
}

// ------------------------------------------------------------------------------------------------ preview

export type InvoicePreview = {
  lineAmounts: (bigint | null)[]
  subtotal: bigint
  discount: bigint
  tax: bigint
  other: bigint
  total: bigint
  /** Human labels of the inputs that are not valid numbers (empty when all parse). */
  invalid: string[]
  discountTooHigh: boolean
}

/** quantity x unit price at two decimals, half-up, as units of 1/10 000. The server rounds to the currency's own scale: this is only a preview. */
function product(quantity: bigint, unitPrice: bigint): bigint {
  const cents = (quantity * unitPrice + 500_000n) / 1_000_000n
  return cents * 100n
}

/** The part of an invoice form the preview reads: the header amounts and the quantity / price / amount of each line (also used by the receivables forms). */
export type PreviewHeader = Pick<InvoiceHeader, 'discount_amount' | 'tax_amount' | 'other_charges_amount'>
export type PreviewLine = Pick<LineForm, 'useQuantity' | 'quantity' | 'unit_price' | 'amount'>

export function previewInvoice(h: PreviewHeader, lines: PreviewLine[]): InvoicePreview {
  const invalid: string[] = []
  const lineAmounts = lines.map((l, i) => {
    if (l.useQuantity) {
      const q = parseAmount(l.quantity)
      const p = parseAmount(l.unit_price)
      if (q === null || p === null) {
        invalid.push(`baris ${i + 1}`)
        return null
      }
      return product(q, p)
    }
    const a = parseAmount(l.amount)
    if (a === null) invalid.push(`baris ${i + 1}`)
    return a
  })
  const header = (value: string, label: string) => {
    const units = parseAmount(value)
    if (units === null) invalid.push(label)
    return units ?? 0n
  }
  const discount = header(h.discount_amount, 'diskon')
  const tax = header(h.tax_amount, 'pajak')
  const other = header(h.other_charges_amount, 'biaya lain')
  const subtotal = lineAmounts.reduce<bigint>((sum, a) => sum + (a ?? 0n), 0n)
  return { lineAmounts, subtotal, discount, tax, other, total: subtotal - discount + tax + other, invalid, discountTooHigh: discount > subtotal }
}

/** Obvious mistakes worth catching before a request: missing required text, no line, or an amount that is not a number. The API judges everything else. */
export function invoiceProblem(h: InvoiceHeader, lines: LineForm[]): string | null {
  if (!h.vendor_id) return 'Pilih vendor.'
  if (!h.vendor_invoice_number.trim()) return 'Isi nomor faktur vendor.'
  if (!h.document_date || !h.posting_date) return 'Isi tanggal dokumen dan tanggal posting.'
  if (!h.description.trim()) return 'Isi deskripsi faktur.'
  if (lines.length === 0) return 'Faktur memerlukan sedikitnya satu baris.'
  const empty = lines.findIndex((l) => !l.description.trim())
  if (empty >= 0) return `Isi deskripsi baris ${empty + 1}.`
  const bad = previewInvoice(h, lines).invalid
  if (bad.length > 0) return `Jumlah pada ${bad.join(', ')} tidak valid. Gunakan angka dengan maksimal empat desimal.`
  return null
}
