import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { setToken } from '../../lib/api'
import type { Mode } from '../../lib/types'
import { mockApi, page } from '../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../test/render'
import type { CurrencyOption, RateLookup } from './foreignSupport'
import { account, BASE, category, noDimensions, term, vendor } from './payables/testkit'
import { customer } from './receivables/testkit'
import type { TaxCodeOption, TaxPreview } from './taxSupport'

// Fixtures and boot helper of the OA4 (tax codes and multi-currency) tests on the AP / AR / expense screens (oa4.*.test.tsx). Not used by the application.
// The existing testkits boot a tenant without the tax and multi-currency modules; this one decides those two entitlements per test.

export { BASE, noDimensions }
export type Routes = Parameters<typeof mockApi>[0]

const FEATURES = {
  VENDOR: true, VENDOR_INVOICE: true, AP_PAYMENT: true, AP_AGING: true, CUSTOMER: true, CUSTOMER_INVOICE: true, AR_RECEIPT: true, AR_AGING: true, CREDIT_NOTE: true,
  EXPENSE: true, CASH_BANK_ACCOUNT: true, PAYMENT: true, RECEIPT: true,
}

/**
 * Signs in as a tenant user with `permissions`. `tax` / `fx` set the entitlement of the tax and the multi-currency module (default NONE: a
 * tenant that never bought them); the AP, AR, expense and cash/bank modules are FULL. Returns the recorded requests.
 */
export function bootOa4(permissions: string[], routes: Routes = {}, options: { tax?: Mode; fx?: Mode } = {}) {
  const tax = options.tax ?? 'NONE'
  const fx = options.fx ?? 'NONE'
  const caps = tenantCaps({ permissions })
  const modules: Record<string, Mode> = {
    ACCOUNTING_CORE: 'FULL', ACCOUNTING_AP: 'FULL', ACCOUNTING_AR: 'FULL', ACCOUNTING_EXPENSE: 'FULL', ACCOUNTING_CASH_BANK: 'FULL',
    ...(tax !== 'NONE' && { ACCOUNTING_TAX: tax }),
    ...(fx !== 'NONE' && { ACCOUNTING_MULTI_CURRENCY: fx }),
  }
  const features = { ...FEATURES, ...(tax !== 'NONE' && { TAX_CONFIGURATION: true }), ...(fx !== 'NONE' && { EXCHANGE_RATE: true }) }
  const calls = mockApi({ 'GET /auth/me': { data: tenantMe }, 'GET /app/capabilities': { data: { ...caps, modules, features } }, ...routes })
  setToken('t')
  return calls
}

// ------------------------------------------------------------------------------------------------ tax fixtures

export const taxIn: TaxCodeOption = { id: 'tc-in', code: 'PPN-MASUKAN', name: 'PPN Masukan', tax_type: 'INPUT_TAX', calculation_method: 'EXCLUSIVE', treatment: 'STANDARD', is_recoverable: true, status: 'ACTIVE' }
export const taxOut: TaxCodeOption = { id: 'tc-out', code: 'PPN-KELUARAN', name: 'PPN Keluaran', tax_type: 'OUTPUT_TAX', calculation_method: 'EXCLUSIVE', treatment: 'STANDARD', is_recoverable: true, status: 'ACTIVE' }

/** The server's answer to POST tax-codes/{id}/preview for 1.000.000 at 11% (a fixture: the page never computes it). */
export const taxPreview = (over: Partial<TaxPreview> = {}): TaxPreview => ({
  tax_code: 'PPN-MASUKAN', calculation_method: 'EXCLUSIVE', treatment: 'STANDARD', is_recoverable: true, tax_type: 'INPUT_TAX', date: '2026-10-08', rate: '11.000000',
  entered_amount: '1000000.0000', base_amount: '1000000.0000', tax_amount: '110000.0000', total_amount: '1110000.0000', ...over,
})

// ------------------------------------------------------------------------------------------------ currency fixtures

export const usd: CurrencyOption = { id: 'cur-usd', code: 'USD', name: 'Dolar Amerika Serikat', symbol: '$', decimal_places: 2, status: 'ACTIVE' }

/** The server's answer to GET exchange-rates/lookup: 1 USD = 16.250,50 IDR. */
export const usdRate: RateLookup = { currency: 'USD', functional_currency: 'IDR', rate: '16250.5000000000', rate_id: 'fx-1', effective_date: '2026-10-05', rate_type: 'MANUAL', source: 'Bank Indonesia' }

/** The routes a multi-currency tenant answers while a document form is open. */
export const currencyRoutes: Routes = {
  [`GET ${BASE}/currencies`]: { data: page([usd]) },
  [`GET ${BASE}/exchange-rates/lookup`]: { data: usdRate },
}

/** The snapshot a foreign document carries (the server's rate and functional amount). */
export const usdSnapshot = {
  currency: 'USD', exchange_rate: '16250.5000000000', exchange_rate_id: 'fx-1', exchange_rate_date: '2026-10-05', exchange_rate_type: 'MANUAL',
}

// ------------------------------------------------------------------------------------------------ editor routes

export const MISSING_RATE = { status: 422, data: { message: 'There is no exchange rate', code: 'EXCHANGE_RATE_NOT_FOUND', details: {} } }

export const apPermissions = ['accounting.ap_invoice.view', 'accounting.ap_invoice.create']
export const arPermissions = ['accounting.ar_invoice.view', 'accounting.ar_invoice.create']
export const expensePermissions = ['accounting.expense.view', 'accounting.expense.create']

/** The master data the vendor-invoice editor reads. */
export const apEditorRoutes: Routes = {
  [`GET ${BASE}/vendors`]: { data: page([vendor()]) },
  [`GET ${BASE}/payment-terms`]: { data: { data: [term()] } },
  [`GET ${BASE}/expense-categories`]: { data: { data: [category] } },
  [`GET ${BASE}/accounts`]: { data: { data: [account('a-exp', '6100', 'Beban jasa')] } },
  [`GET ${BASE}/account-mappings`]: { data: { roles: [], mappings: [] } },
  [`GET ${BASE}/dimensions`]: { data: noDimensions },
  [`GET ${BASE}/ap-invoices/check-duplicate`]: { data: { duplicate: false, status: null, document_number: null } },
}

/** The master data the customer-invoice editor reads. */
export const arEditorRoutes: Routes = {
  [`GET ${BASE}/customers`]: { data: page([customer()]) },
  [`GET ${BASE}/ar-payment-terms`]: { data: { data: [term()] } },
  [`GET ${BASE}/accounts`]: { data: { data: [account('a-rev', '4100', 'Pendapatan penjualan', { account_type: 'REVENUE', normal_balance: 'CREDIT' })] } },
  [`GET ${BASE}/account-mappings`]: { data: { roles: [], mappings: [] } },
  [`GET ${BASE}/dimensions`]: { data: noDimensions },
}

/** The master data the expense editor reads. */
export const expenseEditorRoutes: Routes = {
  [`GET ${BASE}/vendors`]: { data: page([vendor()]) },
  [`GET ${BASE}/payment-terms`]: { data: { data: [term()] } },
  [`GET ${BASE}/expense-categories`]: { data: { data: [category] } },
  [`GET ${BASE}/cash-bank-accounts`]: { data: page([]) },
  [`GET ${BASE}/dimensions`]: { data: noDimensions },
}

// ------------------------------------------------------------------------------------------------ form helpers

/** Fills the vendor-invoice editor (opened at /faktur-vendor/baru) with the minimum a draft needs: a vendor, a number, a description and one line. */
export async function fillApInvoice(amount = '1000000') {
  await screen.findByRole('heading', { name: 'Faktur vendor baru' })
  await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
  await userEvent.type(screen.getByLabelText('Nomor faktur vendor'), 'FP-200')
  await userEvent.type(screen.getByLabelText('Deskripsi'), 'Jasa konsultasi')
  await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Konsultasi')
  await userEvent.type(screen.getByLabelText('Jumlah baris 1'), amount)
}

/** Fills the customer-invoice editor (opened at /faktur-pelanggan/baru) with the minimum a draft needs. */
export async function fillArInvoice(amount = '1000000') {
  await screen.findByRole('heading', { name: 'Faktur pelanggan baru' })
  await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
  await userEvent.type(screen.getByLabelText('Deskripsi'), 'Penjualan barang')
  await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Barang')
  await userEvent.type(screen.getByLabelText('Jumlah baris 1'), amount)
}

/** The option texts of a select. */
export const optionTexts = (select: HTMLElement) => within(select).getAllByRole('option').map((o) => o.textContent)

/** The request of the first call that matches, for a payload assertion. */
export const sent = (calls: ReturnType<typeof bootOa4>, method: string, url: string) => calls.find((c) => c.method === method && c.url === url)

/** A DRAFT payable expense as GET expenses/{id} returns it (the response of a save and the draft an edit starts from). */
export const expenseDraft = (over: Record<string, unknown> = {}) => ({
  id: 'e-1', document_number: null, settlement: 'PAYABLE', status: 'DRAFT', vendor_id: 'v-1', payee_name: null, expense_category_id: 'cat-1', account_id: null,
  expense_date: '2026-10-08', posting_date: '2026-10-08', due_date: '2026-11-07', payment_term_id: 'term-30', due_date_overridden: false, currency: 'IDR',
  description: 'Biaya listrik Oktober', reference: null, payment_method: null, supporting_document: null, cash_bank_account_id: null,
  branch_id: null, business_unit_id: null, cost_center_id: null, net_amount: '1000000.0000', tax_amount: '0.0000', total_amount: '1000000.0000',
  created_by: 'u-t', posted_at: null, journal_entry_id: null, reversal_journal_id: null, reversal_reason: null, reversal_posting_date: null, reject_reason: null, cancel_reason: null,
  creator: { id: 'u-t', name: 'Budi Santoso' }, vendor: { id: 'v-1', code: 'V1', name: 'PT Sumber Makmur', status: 'ACTIVE' },
  category: { id: 'cat-1', code: 'JASA', name: 'Jasa profesional', status: 'ACTIVE' }, payment_term: { id: 'term-30', code: 'NET30', name: 'Net 30 hari' }, cash_bank_account: null,
  transitions: [{ id: 'tr-1', from_status: null, to_status: 'DRAFT', actor_user_id: 'u-t', actor: { id: 'u-t', name: 'Budi Santoso' }, reason: null, occurred_at: '2026-10-08T03:00:00Z' }],
  sod: { approve: true, post: true, approval_required: true }, ...over,
})
