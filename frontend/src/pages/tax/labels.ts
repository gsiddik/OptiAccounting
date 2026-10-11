import { taxBasisLabels, taxDirectionLabels, taxMethodLabels, taxSourceLabels, taxTreatmentLabels, taxTypeLabels } from '../../lib/oa4Labels'

export { taxBasisLabels, taxDirectionLabels, taxMethodLabels, taxSourceLabels, taxTreatmentLabels, taxTypeLabels }

/** Where a posted tax transaction's source document opens, and the permission that lets its detail page open (cosmetic: the page guards itself). */
export const SOURCE_LINKS: Record<string, { path: string; permission: string }> = {
  ap_invoice: { path: '/app/akuntansi/faktur-vendor', permission: 'accounting.ap_invoice.view' },
  ar_invoice: { path: '/app/akuntansi/faktur-pelanggan', permission: 'accounting.ar_invoice.view' },
  expense: { path: '/app/akuntansi/beban', permission: 'accounting.expense.view' },
}

export const sourceLabel = (type: string): string => taxSourceLabels[type] ?? type

export const treatmentShort: Record<string, string> = { STANDARD: 'Standar', ZERO_RATED: 'Tarif nol', EXEMPT: 'Dibebaskan' }
export const methodShort: Record<string, string> = { EXCLUSIVE: 'Eksklusif', INCLUSIVE: 'Inklusif' }

/** "11" or "11,5" typed by a person becomes the decimal string the API expects; the digits are never changed. */
export const decimalText = (value: string): string => value.trim().replace(',', '.')
