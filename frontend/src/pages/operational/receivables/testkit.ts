import type { AxiosResponse, InternalAxiosRequestConfig } from 'axios'
import { vi } from 'vitest'
import { api, setToken } from '../../../lib/api'
import type { Customer } from '../../../lib/operational'
import type { Mode } from '../../../lib/types'
import { mockApi } from '../../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../../test/render'
import type { ArInvoice, ArInvoiceRow, CreditNote, CreditNoteRow, OpenArInvoice, Receipt, ReceiptRow } from './types'

// Fixtures shared by the receivables tests (receivables*.test.tsx). Not used by the application. The accounts, terms and dimension
// helpers of the payables kit are reused as they are.

export { account, BASE, noDimensions, term } from '../payables/testkit'

/**
 * Signs in as a tenant user with `permissions`, the AR and cash/bank modules and the OA3 features entitled, and the given routes mocked.
 * `ar` / `cashBank` set the module mode (default FULL); `modules` replaces the whole entitlement (to leave a module out). Returns the recorded calls.
 */
export function bootAr(options: { permissions: string[]; subscription?: 'FULL' | 'READ_ONLY'; ar?: Mode; cashBank?: Mode; modules?: Record<string, Mode>; features?: Record<string, boolean> }, routes: Parameters<typeof mockApi>[0] = {}) {
  const caps = tenantCaps({ permissions: options.permissions, subscriptionMode: options.subscription })
  const withModules = {
    ...caps,
    modules: options.modules ?? { ACCOUNTING_CORE: caps.modules.ACCOUNTING_CORE, ACCOUNTING_AR: options.ar ?? caps.modules.ACCOUNTING_CORE, ACCOUNTING_CASH_BANK: options.cashBank ?? 'FULL' },
    features: { CUSTOMER: true, CUSTOMER_INVOICE: true, AR_RECEIPT: true, CREDIT_NOTE: true, AR_AGING: true, CASH_BANK_ACCOUNT: true, ...options.features },
  }
  const calls = mockApi({ 'GET /auth/me': { data: tenantMe }, 'GET /app/capabilities': { data: withModules }, ...routes })
  setToken('t')
  return calls
}

export const customer = (over: Partial<Customer> = {}): Customer => ({
  id: 'c-1', code: 'C1', name: 'PT Pelanggan Setia', legal_name: null, status: 'ACTIVE', contact_name: null, email: null, phone: null, address: null, tax_id: null, tax_registered: false,
  payment_term_id: 'term-30', payment_term: { id: 'term-30', code: 'NET30', name: 'Net 30 hari' }, default_currency: null, receivable_account_id: null, default_revenue_account_id: null,
  receivable_account: null, default_revenue_account: null, credit_limit: null, external_source: null, external_id: null, notes: null, ...over,
})

const creator = { id: 'u-t', name: 'Budi Santoso' }
const customerRef = { id: 'c-1', code: 'C1', name: 'PT Pelanggan Setia', status: 'ACTIVE' }
const draftTransition = { id: 'tr-1', from_status: null, to_status: 'DRAFT', actor_user_id: 'u-t', actor: { id: 'u-t', name: 'Budi Santoso' }, reason: null, occurred_at: '2026-09-01T01:00:00Z' }
const sod = { approve: true, post: true, approval_required: true }

export const arInvoiceRow = (over: Partial<ArInvoiceRow> = {}): ArInvoiceRow => ({
  id: 'ai-1', document_number: 'ARI-FY2026-000001', status: 'POSTED', customer_id: 'c-1', customer: customerRef, customer_reference: 'PO-100', document_date: '2026-09-01', posting_date: '2026-09-01',
  due_date: '2026-10-01', currency: 'IDR', description: 'Penjualan barang', total_amount: '1500000.0000', received_amount: '500000.0000', credited_amount: '100000.0000',
  outstanding_amount: '900000.0000', payment_status: 'PARTIALLY_PAID', creator, ...over,
})

export const arInvoice = (over: Partial<ArInvoice> = {}): ArInvoice => ({
  ...arInvoiceRow(),
  reference: null, payment_term_id: 'term-30', payment_term: { id: 'term-30', code: 'NET30', name: 'Net 30 hari' }, due_date_overridden: false,
  subtotal_amount: '1500000.0000', discount_amount: '0.0000', tax_amount: '0.0000', other_charges_amount: '0.0000',
  branch_id: null, business_unit_id: null, cost_center_id: null, journal_entry_id: 'j-1', reversal_journal_id: null, posted_at: '2026-09-01T03:00:00Z',
  lines: [{ id: 'l-1', line_number: 1, description: 'Penjualan barang', quantity: null, unit_price: null, amount: '1500000.0000', account_role: null, account_id: 'a-rev', cost_center_id: null, account: { id: 'a-rev', code: '4100', name: 'Pendapatan penjualan' } }],
  allocations: [{ id: 'al-1', customer_receipt_id: 'rc-1', ar_invoice_id: 'ai-1', amount: '500000.0000', is_effective: true, effective_at: '2026-09-20T03:00:00Z', receipt: { id: 'rc-1', document_number: 'RCP-FY2026-000001', receipt_date: '2026-09-20', posting_date: '2026-09-20', status: 'POSTED' } }],
  credit_notes: [{ id: 'cn-1', document_number: 'CN-FY2026-000001', posting_date: '2026-09-25', reason: 'Retur sebagian', total_amount: '100000.0000', status: 'POSTED' }],
  transitions: [draftTransition], sod, possible_duplicates: [], ...over,
})

export const openArInvoice = (over: Partial<OpenArInvoice> = {}): OpenArInvoice => ({
  id: 'i-1', document_number: 'ARI-FY2026-000001', customer_reference: 'PO-100', posting_date: '2026-09-01', due_date: '2026-09-30', total_amount: '300000.0000', received_amount: '0.0000',
  credited_amount: '0.0000', outstanding_amount: '300000.0000', ...over,
})

export const receiptRow = (over: Partial<ReceiptRow> = {}): ReceiptRow => ({
  id: 'rc-1', document_number: 'RCP-FY2026-000001', status: 'POSTED', customer_id: 'c-1', customer: customerRef, cash_bank_account_id: 'cb-1',
  cash_bank_account: { id: 'cb-1', code: 'BCA', name: 'BCA Operasional', kind: 'BANK' }, receipt_date: '2026-09-20', posting_date: '2026-09-20', currency: 'IDR', amount: '500000.0000',
  receipt_method: 'TRANSFER', reference: 'TRF-1', description: null, allocated_amount: '500000.0000', creator, ...over,
})

export const receipt = (over: Partial<Receipt> = {}): Receipt => ({
  ...receiptRow(),
  gl_account: { id: 'a-bank', code: '1120', name: 'Bank' }, branch_id: null, business_unit_id: null, cost_center_id: null, journal_entry_id: 'j-9', reversal_journal_id: null, posted_at: '2026-09-20T03:00:00Z',
  unallocated_amount: '0.0000',
  allocations: [{ id: 'al-1', customer_receipt_id: 'rc-1', ar_invoice_id: 'ai-1', amount: '500000.0000', is_effective: true, effective_at: '2026-09-20T03:00:00Z', released_at: null, invoice: { id: 'ai-1', document_number: 'ARI-FY2026-000001', customer_reference: 'PO-100', due_date: '2026-10-01', total_amount: '1500000.0000', status: 'POSTED' } }],
  transitions: [{ ...draftTransition, occurred_at: '2026-09-20T01:00:00Z' }], sod, ...over,
})

export const creditNoteRow = (over: Partial<CreditNoteRow> = {}): CreditNoteRow => ({
  id: 'cn-1', document_number: 'CN-FY2026-000001', status: 'POSTED', customer_id: 'c-1', customer: customerRef, ar_invoice_id: 'ai-1',
  invoice: { id: 'ai-1', document_number: 'ARI-FY2026-000001', customer_reference: 'PO-100', due_date: '2026-10-01', total_amount: '1500000.0000', status: 'POSTED', posting_date: '2026-09-01' },
  document_date: '2026-09-25', posting_date: '2026-09-25', currency: 'IDR', reason: 'Retur sebagian', reference: null, subtotal_amount: '100000.0000', tax_amount: '0.0000', total_amount: '100000.0000', creator, ...over,
})

export const creditNote = (over: Partial<CreditNote> = {}): CreditNote => ({
  ...creditNoteRow(),
  branch_id: null, business_unit_id: null, cost_center_id: null, journal_entry_id: 'j-20', reversal_journal_id: null, posted_at: '2026-09-25T03:00:00Z', invoice_outstanding: '900000.0000',
  lines: [{ id: 'cl-1', line_number: 1, description: 'Retur barang', quantity: null, unit_price: null, amount: '100000.0000', account_role: null, account_id: 'a-rev', cost_center_id: null, account: { id: 'a-rev', code: '4100', name: 'Pendapatan penjualan' } }],
  transitions: [{ ...draftTransition, occurred_at: '2026-09-25T01:00:00Z' }], sod, ...over,
})

/** A cash or bank account as the cash-bank-accounts list returns it (the receipt form and list offer it). */
export const cashAccount = {
  id: 'cb-1', code: 'BCA', name: 'BCA Operasional', kind: 'BANK', status: 'ACTIVE', currency: 'IDR', account_id: 'a-bank', gl_account: null,
  bank_name: 'BCA', account_holder: null, account_number_masked: null, branch_id: null, business_unit_id: null,
}

/** Replaces the browser's file download (a CSV export) with spies; returns the `createObjectURL` spy. Call `vi.restoreAllMocks()` afterwards. */
export function mockDownload() {
  const create = vi.fn(() => 'blob:csv')
  Object.assign(URL, { createObjectURL: create, revokeObjectURL: vi.fn() })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
  return create
}

/** Holds back every GET of `url` (a path under /api/v1) until the returned function is called, to look at a loading state. Call it after `bootAr`. */
export function holdGet(url: string): () => void {
  const inner = api.defaults.adapter as (config: InternalAxiosRequestConfig) => Promise<AxiosResponse>
  let release: () => void = () => {}
  const gate = new Promise<void>((resolve) => { release = resolve })
  api.defaults.adapter = async (config) => {
    if (config.url === url && (config.method ?? 'get').toLowerCase() === 'get') await gate
    return inner(config)
  }
  return release
}
