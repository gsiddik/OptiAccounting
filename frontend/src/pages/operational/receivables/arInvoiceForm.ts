import { hasTaxCodes, optionalDecimal, plainAmount, previewInvoice } from '../payables/invoiceForm'
import { arLinePayload, firstUndescribedLine, type ArLineForm } from './arLines'
import type { ArInvoice } from './types'

// Form state of the customer-invoice editor and the exact API payload built from it. The preview of the totals is
// `previewInvoice` (exact BigInt arithmetic) and nothing more: the server recomputes every amount.

export type ArInvoiceHeader = {
  customer_id: string
  customer_reference: string
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

export function arHeaderFrom(invoice: ArInvoice | null, today: string): ArInvoiceHeader {
  return {
    customer_id: invoice?.customer_id ?? '',
    customer_reference: invoice?.customer_reference ?? '',
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
    // With tax codes the stored tax is the server's calculation, not a manual amount: never send it back.
    tax_amount: invoice?.lines?.some((l) => l.tax_code_id) ? '' : plainAmount(invoice?.tax_amount),
    other_charges_amount: plainAmount(invoice?.other_charges_amount),
  }
}

/**
 * The body of POST / PATCH /ar-invoices. `dueDateEditable` says whether the term lets the user name the due date; `currency` is the currency part of
 * the request (empty for a functional document of a single-currency organisation, so its payload does not change).
 */
export function arInvoicePayload(h: ArInvoiceHeader, lines: ArLineForm[], options: { dueDateEditable: boolean; currency?: Record<string, string | null> }) {
  return {
    customer_id: h.customer_id,
    customer_reference: h.customer_reference.trim() || null,
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
    // The tax of a document with tax codes is calculated from its lines; a manual amount next to them is refused (TAX_AMOUNT_CONFLICT).
    tax_amount: hasTaxCodes(lines) ? null : optionalDecimal(h.tax_amount),
    other_charges_amount: optionalDecimal(h.other_charges_amount),
    lines: lines.map(arLinePayload),
    ...(options.currency ?? {}),
  }
}

/** Obvious mistakes worth catching before a request. The API judges everything else (customer status, accounts, period, totals). */
export function arInvoiceProblem(h: ArInvoiceHeader, lines: ArLineForm[]): string | null {
  if (!h.customer_id) return 'Pilih pelanggan.'
  if (!h.document_date || !h.posting_date) return 'Isi tanggal dokumen dan tanggal posting.'
  if (!h.description.trim()) return 'Isi deskripsi faktur.'
  if (lines.length === 0) return 'Faktur memerlukan sedikitnya satu baris.'
  const undescribed = firstUndescribedLine(lines)
  if (undescribed !== null) return `Isi deskripsi baris ${undescribed}.`
  const bad = previewInvoice(h, lines).invalid
  if (bad.length > 0) return `Jumlah pada ${bad.join(', ')} tidak valid. Gunakan angka dengan maksimal empat desimal.`
  return null
}
