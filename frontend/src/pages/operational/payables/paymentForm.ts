import { amountToApi, parseAmount } from '../../../lib/accounting'
import { plainAmount } from './invoiceForm'
import type { Payment } from './types'

// Form state of the vendor-payment editor, the exact API payload, and an exact (BigInt) PREVIEW of how much of the payment is allocated.
// The allocation PROPOSAL is never computed here: it comes from GET /vendors/{id}/allocation-suggestion.

export type PaymentHeader = {
  vendor_id: string
  cash_bank_account_id: string
  amount: string
  payment_date: string
  posting_date: string
  payment_method: string
  reference: string
  description: string
  branch_id: string
  business_unit_id: string
  cost_center_id: string
}

/** What the user typed per open invoice: invoice id -> allocation text. */
export type AllocationInputs = Record<string, string>

export function paymentHeaderFrom(payment: Payment | null, today: string): PaymentHeader {
  return {
    vendor_id: payment?.vendor_id ?? '',
    cash_bank_account_id: payment?.cash_bank_account_id ?? '',
    amount: plainAmount(payment?.amount),
    payment_date: payment?.payment_date.slice(0, 10) ?? today,
    posting_date: payment?.posting_date.slice(0, 10) ?? today,
    payment_method: payment ? (payment.payment_method ?? '') : 'TRANSFER',
    reference: payment?.reference ?? '',
    description: payment?.description ?? '',
    branch_id: payment?.branch_id ?? '',
    business_unit_id: payment?.business_unit_id ?? '',
    cost_center_id: payment?.cost_center_id ?? '',
  }
}

export function allocationsFrom(payment: Payment | null): AllocationInputs {
  return Object.fromEntries((payment?.allocations ?? []).map((a) => [a.ap_invoice_id, plainAmount(a.amount)]))
}

/** A typed amount as the API wants it, or the raw text when it is not a number (so the API refuses it instead of the UI guessing). */
function decimal(input: string): string {
  const units = parseAmount(input)
  return units === null ? input.trim() : amountToApi(units)
}

/** Allocation rows to send: only invoices with a non-zero amount, as decimal strings. */
export function allocationRows(inputs: AllocationInputs): { ap_invoice_id: string; amount: string }[] {
  return Object.entries(inputs)
    .filter(([, text]) => text.trim() !== '' && parseAmount(text) !== 0n)
    .map(([ap_invoice_id, text]) => ({ ap_invoice_id, amount: decimal(text) }))
}

/** The body of POST / PATCH /vendor-payments. */
export function paymentPayload(h: PaymentHeader, inputs: AllocationInputs) {
  return {
    vendor_id: h.vendor_id,
    cash_bank_account_id: h.cash_bank_account_id,
    amount: decimal(h.amount),
    payment_date: h.payment_date,
    posting_date: h.posting_date,
    payment_method: h.payment_method || null,
    reference: h.reference.trim() || null,
    description: h.description.trim() || null,
    branch_id: h.branch_id || null,
    business_unit_id: h.business_unit_id || null,
    cost_center_id: h.cost_center_id || null,
    allocations: allocationRows(inputs),
  }
}

export type AllocationPreview = {
  /** null when the payment amount is not a valid number. */
  amount: bigint | null
  allocated: bigint
  /** payment amount - allocated (0n when the amount is invalid). */
  remaining: bigint
  /** Invoice ids whose allocation text is not a valid number. */
  invalid: string[]
  over: boolean
  under: boolean
  balanced: boolean
}

export function previewAllocation(amountText: string, inputs: AllocationInputs): AllocationPreview {
  const amount = parseAmount(amountText)
  const invalid: string[] = []
  let allocated = 0n
  for (const [id, text] of Object.entries(inputs)) {
    const units = parseAmount(text)
    if (units === null) invalid.push(id)
    else allocated += units
  }
  const remaining = amount === null ? 0n : amount - allocated
  const positive = amount !== null && amount > 0n
  return { amount, allocated, remaining, invalid, over: positive && remaining < 0n, under: positive && remaining > 0n, balanced: positive && remaining === 0n && invalid.length === 0 }
}

/** True when the typed allocation is larger than the invoice's outstanding amount as the server reported it. A hint only: the server decides. */
export function exceedsOutstanding(text: string, outstanding: string | null): boolean {
  const typed = parseAmount(text)
  const limit = outstanding === null ? null : parseAmount(outstanding)
  return typed !== null && limit !== null && typed > limit
}

/** Obvious mistakes worth catching before a request. The API judges everything else (over- and under-allocation included). */
export function paymentProblem(h: PaymentHeader, inputs: AllocationInputs): string | null {
  if (!h.vendor_id) return 'Pilih vendor.'
  if (!h.cash_bank_account_id) return 'Pilih akun kas/bank.'
  const amount = parseAmount(h.amount)
  if (amount === null) return 'Jumlah pembayaran tidak valid. Gunakan angka dengan maksimal empat desimal.'
  if (amount === 0n) return 'Isi jumlah pembayaran.'
  if (!h.payment_date || !h.posting_date) return 'Isi tanggal pembayaran dan tanggal posting.'
  if (previewAllocation(h.amount, inputs).invalid.length > 0) return 'Ada alokasi yang bukan angka yang valid. Gunakan angka dengan maksimal empat desimal.'
  return null
}
