import { optionalDecimal, plainAmount, previewInvoice, type InvoicePreview } from '../payables/invoiceForm'
import { arLinePayload, firstUndescribedLine, type ArLineForm } from './arLines'
import type { CreditNote } from './types'

// Form state of the credit-note editor and the exact API payload built from it. A credit note has no discount or other charges: its
// totals preview is `previewInvoice` with the tax only. The server recomputes every amount and checks them against the invoice's outstanding.

export type CreditNoteHeader = {
  customer_id: string
  ar_invoice_id: string
  reason: string
  reference: string
  document_date: string
  posting_date: string
  tax_amount: string
  branch_id: string
  business_unit_id: string
  cost_center_id: string
}

/** `prefill` is the invoice a new note starts from (the "Buat nota kredit" link of the invoice page). */
export function creditNoteHeaderFrom(note: CreditNote | null, today: string, prefill: { id: string; customer_id: string } | null = null): CreditNoteHeader {
  return {
    customer_id: note?.customer_id ?? prefill?.customer_id ?? '',
    ar_invoice_id: note?.ar_invoice_id ?? prefill?.id ?? '',
    reason: note?.reason ?? '',
    reference: note?.reference ?? '',
    document_date: note?.document_date.slice(0, 10) ?? today,
    posting_date: note?.posting_date.slice(0, 10) ?? today,
    tax_amount: plainAmount(note?.tax_amount),
    branch_id: note?.branch_id ?? '',
    business_unit_id: note?.business_unit_id ?? '',
    cost_center_id: note?.cost_center_id ?? '',
  }
}

/** The totals preview of a credit note: the lines and the tax. */
export const previewCreditNote = (h: CreditNoteHeader, lines: ArLineForm[]): InvoicePreview => previewInvoice({ discount_amount: '', tax_amount: h.tax_amount, other_charges_amount: '' }, lines)

/** The body of POST / PATCH /ar-credit-notes. */
export function creditNotePayload(h: CreditNoteHeader, lines: ArLineForm[]) {
  return {
    ar_invoice_id: h.ar_invoice_id,
    reason: h.reason.trim(),
    reference: h.reference.trim() || null,
    document_date: h.document_date,
    posting_date: h.posting_date,
    branch_id: h.branch_id || null,
    business_unit_id: h.business_unit_id || null,
    cost_center_id: h.cost_center_id || null,
    tax_amount: optionalDecimal(h.tax_amount),
    lines: lines.map(arLinePayload),
  }
}

/** Obvious mistakes worth catching before a request. The API judges everything else (the invoice's state, the amount against its outstanding, accounts, period). */
export function creditNoteProblem(h: CreditNoteHeader, lines: ArLineForm[]): string | null {
  if (!h.ar_invoice_id) return 'Pilih faktur yang dikreditkan.'
  if (!h.reason.trim()) return 'Isi alasan nota kredit.'
  if (!h.document_date || !h.posting_date) return 'Isi tanggal dokumen dan tanggal posting.'
  if (lines.length === 0) return 'Nota kredit memerlukan sedikitnya satu baris.'
  const undescribed = firstUndescribedLine(lines)
  if (undescribed !== null) return `Isi deskripsi baris ${undescribed}.`
  const bad = previewCreditNote(h, lines).invalid
  if (bad.length > 0) return `Jumlah pada ${bad.join(', ')} tidak valid. Gunakan angka dengan maksimal empat desimal.`
  return null
}
