import { parseAmount } from '../../../lib/accounting'
import { decimal, plainAmount } from '../payables/invoiceForm'
import { allocationEntries, previewAllocation, type AllocationInputs } from '../payables/paymentForm'
import type { Receipt } from './types'

// Form state of the customer-receipt editor and the exact API payload. The allocation preview (BigInt) and the "exceeds outstanding" hint
// are the payables ones (`previewAllocation`, `exceedsOutstanding`); the allocation PROPOSAL comes from GET /customers/{id}/allocation-suggestion.

export type ReceiptHeader = {
  customer_id: string
  cash_bank_account_id: string
  amount: string
  receipt_date: string
  posting_date: string
  receipt_method: string
  reference: string
  description: string
  branch_id: string
  business_unit_id: string
  cost_center_id: string
}

export function receiptHeaderFrom(receipt: Receipt | null, today: string): ReceiptHeader {
  return {
    customer_id: receipt?.customer_id ?? '',
    cash_bank_account_id: receipt?.cash_bank_account_id ?? '',
    amount: plainAmount(receipt?.amount),
    receipt_date: receipt?.receipt_date.slice(0, 10) ?? today,
    posting_date: receipt?.posting_date.slice(0, 10) ?? today,
    receipt_method: receipt ? (receipt.receipt_method ?? '') : 'TRANSFER',
    reference: receipt?.reference ?? '',
    description: receipt?.description ?? '',
    branch_id: receipt?.branch_id ?? '',
    business_unit_id: receipt?.business_unit_id ?? '',
    cost_center_id: receipt?.cost_center_id ?? '',
  }
}

export function receiptAllocationsFrom(receipt: Receipt | null): AllocationInputs {
  return Object.fromEntries((receipt?.allocations ?? []).map((a) => [a.ar_invoice_id, plainAmount(a.amount)]))
}

/** Allocation rows to send: only invoices with a non-zero amount, as decimal strings. */
export function receiptAllocationRows(inputs: AllocationInputs): { ar_invoice_id: string; amount: string }[] {
  return allocationEntries(inputs).map(([ar_invoice_id, amount]) => ({ ar_invoice_id, amount }))
}

/** The body of POST / PATCH /customer-receipts. `currency` is the currency part of the request (empty for a functional receipt of a single-currency organisation, so its payload does not change). */
export function receiptPayload(h: ReceiptHeader, inputs: AllocationInputs, currency: Record<string, string | null> = {}) {
  return {
    customer_id: h.customer_id,
    cash_bank_account_id: h.cash_bank_account_id,
    amount: decimal(h.amount),
    receipt_date: h.receipt_date,
    posting_date: h.posting_date,
    receipt_method: h.receipt_method || null,
    reference: h.reference.trim() || null,
    description: h.description.trim() || null,
    branch_id: h.branch_id || null,
    business_unit_id: h.business_unit_id || null,
    cost_center_id: h.cost_center_id || null,
    allocations: receiptAllocationRows(inputs),
    ...currency,
  }
}

/** Obvious mistakes worth catching before a request. The API judges everything else (over- and under-allocation included). */
export function receiptProblem(h: ReceiptHeader, inputs: AllocationInputs): string | null {
  if (!h.customer_id) return 'Pilih pelanggan.'
  if (!h.cash_bank_account_id) return 'Pilih akun kas/bank.'
  const amount = parseAmount(h.amount)
  if (amount === null) return 'Jumlah penerimaan tidak valid. Gunakan angka dengan maksimal empat desimal.'
  if (amount === 0n) return 'Isi jumlah penerimaan.'
  if (!h.receipt_date || !h.posting_date) return 'Isi tanggal penerimaan dan tanggal posting.'
  if (previewAllocation(h.amount, inputs).invalid.length > 0) return 'Ada alokasi yang bukan angka yang valid. Gunakan angka dengan maksimal empat desimal.'
  return null
}
