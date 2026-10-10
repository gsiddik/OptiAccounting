import { setToken } from '../../../lib/api'
import type { Vendor } from '../../../lib/operational'
import { mockApi } from '../../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../../test/render'
import type { Invoice, InvoiceRow, OpenInvoice, Payment, PaymentRow } from './types'

// Fixtures shared by the payables tests (payables*.test.tsx). Not used by the application.

export const BASE = '/app/accounting'
export const noDimensions = { branches: [], business_units: [], cost_centers: [] }

type Mode = 'FULL' | 'READ_ONLY'

/** Signs in as a tenant user with `permissions`, the AP module and its features entitled, and the given routes mocked. Returns the recorded calls. */
export function bootAp(options: { permissions: string[]; subscription?: Mode; ap?: Mode }, routes: Parameters<typeof mockApi>[0] = {}) {
  const caps = tenantCaps({ permissions: options.permissions, subscriptionMode: options.subscription })
  const withModules = {
    ...caps,
    modules: { ACCOUNTING_CORE: caps.modules.ACCOUNTING_CORE, ACCOUNTING_AP: options.ap ?? caps.modules.ACCOUNTING_CORE, ACCOUNTING_CASH_BANK: 'FULL' },
    features: { VENDOR: true, VENDOR_INVOICE: true, AP_PAYMENT: true, AP_AGING: true, CASH_BANK_ACCOUNT: true },
  }
  const calls = mockApi({ 'GET /auth/me': { data: tenantMe }, 'GET /app/capabilities': { data: withModules }, ...routes })
  setToken('t')
  return calls
}

export const vendor = (over: Partial<Vendor> = {}): Vendor => ({
  id: 'v-1', code: 'V1', name: 'PT Sumber Makmur', legal_name: null, status: 'ACTIVE', contact_name: null, email: null, phone: null, address: null, tax_id: null, tax_registered: false,
  payment_term_id: 'term-30', payment_term: { id: 'term-30', code: 'NET30', name: 'Net 30 hari' }, default_currency: null, payable_account_id: null, default_expense_account_id: null,
  payable_account: null, default_expense_account: null, external_source: null, external_id: null, notes: null, ...over,
})

export const term = (over: Record<string, unknown> = {}) => ({
  id: 'term-30', code: 'NET30', name: 'Net 30 hari', term_type: 'NET_DAYS', due_days: 30, allows_due_date_override: false, description: null, status: 'ACTIVE', ...over,
})

export const category = { id: 'cat-1', code: 'JASA', name: 'Jasa profesional', description: null, account_role: null, account_id: 'a-exp', account: null, status: 'ACTIVE' }

export const account = (id: string, code: string, name: string, over: Record<string, unknown> = {}) => ({
  id, code, name, description: null, parent_id: null, account_type: 'EXPENSE', normal_balance: 'DEBIT', is_postable: true, is_control: false, currency: null, status: 'ACTIVE', ...over,
})

export const invoiceRow = (over: Partial<InvoiceRow> = {}): InvoiceRow => ({
  id: 'inv-1', document_number: 'API-FY2026-000001', origin: 'INVOICE', status: 'POSTED', vendor_id: 'v-1', vendor: { id: 'v-1', code: 'V1', name: 'PT Sumber Makmur', status: 'ACTIVE' },
  vendor_invoice_number: 'FP-100', document_date: '2026-09-01', posting_date: '2026-09-01', due_date: '2026-10-01', currency: 'IDR', description: 'Jasa konsultasi',
  total_amount: '1500000.0000', paid_amount: '500000.0000', outstanding_amount: '1000000.0000', payment_status: 'PARTIALLY_PAID', creator: { id: 'u-t', name: 'Budi Santoso' }, ...over,
})

export const invoice = (over: Partial<Invoice> = {}): Invoice => ({
  ...invoiceRow(),
  reference: null, payment_term_id: 'term-30', payment_term: { id: 'term-30', code: 'NET30', name: 'Net 30 hari' }, due_date_overridden: false,
  subtotal_amount: '1500000.0000', discount_amount: '0.0000', tax_amount: '0.0000', other_charges_amount: '0.0000',
  branch_id: null, business_unit_id: null, cost_center_id: null, journal_entry_id: 'j-1', reversal_journal_id: null, posted_at: '2026-09-01T03:00:00Z',
  lines: [{ id: 'l-1', line_number: 1, description: 'Jasa konsultasi', quantity: null, unit_price: null, amount: '1500000.0000', expense_category_id: 'cat-1', account_role: null, account_id: null, cost_center_id: null, expense_category: { id: 'cat-1', code: 'JASA', name: 'Jasa profesional' } }],
  allocations: [{ id: 'al-1', vendor_payment_id: 'pay-1', ap_invoice_id: 'inv-1', amount: '500000.0000', is_effective: true, effective_at: '2026-09-20T03:00:00Z', payment: { id: 'pay-1', document_number: 'PAY-FY2026-000001', payment_date: '2026-09-20', posting_date: '2026-09-20', status: 'POSTED' } }],
  transitions: [{ id: 'tr-1', from_status: null, to_status: 'DRAFT', actor_user_id: 'u-t', actor: { id: 'u-t', name: 'Budi Santoso' }, reason: null, occurred_at: '2026-09-01T01:00:00Z' }],
  sod: { approve: true, post: true, approval_required: true }, possible_duplicates: [], ...over,
})

export const openInvoice = (over: Partial<OpenInvoice> = {}): OpenInvoice => ({
  id: 'i-1', document_number: 'API-FY2026-000001', vendor_invoice_number: 'FP-100', posting_date: '2026-09-01', due_date: '2026-09-30', total_amount: '300000.0000', paid_amount: '0.0000',
  outstanding_amount: '300000.0000', payment_status: 'UNPAID', ...over,
})

export const paymentRow = (over: Partial<PaymentRow> = {}): PaymentRow => ({
  id: 'pay-1', document_number: 'PAY-FY2026-000001', status: 'POSTED', vendor_id: 'v-1', vendor: { id: 'v-1', code: 'V1', name: 'PT Sumber Makmur', status: 'ACTIVE' },
  cash_bank_account_id: 'cb-1', cash_bank_account: { id: 'cb-1', code: 'BCA', name: 'BCA Operasional', kind: 'BANK' }, payment_date: '2026-09-20', posting_date: '2026-09-20', currency: 'IDR',
  amount: '500000.0000', payment_method: 'TRANSFER', reference: 'TRF-1', description: null, allocated_amount: '500000.0000', creator: { id: 'u-t', name: 'Budi Santoso' }, ...over,
})

export const payment = (over: Partial<Payment> = {}): Payment => ({
  ...paymentRow(),
  gl_account: { id: 'a-bank', code: '1120', name: 'Bank' }, branch_id: null, business_unit_id: null, cost_center_id: null, journal_entry_id: 'j-9', reversal_journal_id: null, posted_at: '2026-09-20T03:00:00Z',
  unallocated_amount: '0.0000',
  allocations: [{ id: 'al-1', vendor_payment_id: 'pay-1', ap_invoice_id: 'inv-1', amount: '500000.0000', is_effective: true, effective_at: '2026-09-20T03:00:00Z', released_at: null, invoice: { id: 'inv-1', document_number: 'API-FY2026-000001', vendor_invoice_number: 'FP-100', due_date: '2026-10-01', total_amount: '1500000.0000', status: 'POSTED' } }],
  transitions: [{ id: 'tr-1', from_status: null, to_status: 'DRAFT', actor_user_id: 'u-t', actor: { id: 'u-t', name: 'Budi Santoso' }, reason: null, occurred_at: '2026-09-20T01:00:00Z' }],
  sod: { approve: true, post: true, approval_required: true }, ...over,
})
