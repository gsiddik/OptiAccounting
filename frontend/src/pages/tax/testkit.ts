import { setToken } from '../../lib/api'
import { mockApi } from '../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../test/render'
import type { Account } from '../../lib/accounting'
import type { TaxCode, TaxRate, TaxTransaction } from './types'

// Shared fixtures and boot helper of the tax page tests.

export type Routes = Parameters<typeof mockApi>[0]

const MODULES = ['ACCOUNTING_CORE', 'ACCOUNTING_TAX', 'ACCOUNTING_AP', 'ACCOUNTING_AR', 'ACCOUNTING_EXPENSE']
const FEATURES = { TAX_CONFIGURATION: true, TAX_REPORT: true, VENDOR_INVOICE: true, CUSTOMER_INVOICE: true, EXPENSE: true }

/** Sign in as a tenant user with the given permissions; `mode` applies to every module (a READ_ONLY subscription). Returns the recorded requests. */
export function boot(permissions: string[], routes: Routes = {}, options: { mode?: 'FULL' | 'READ_ONLY' } = {}) {
  const mode = options.mode ?? 'FULL'
  const caps = tenantCaps({ permissions, subscriptionMode: mode })
  const calls = mockApi({
    'GET /auth/me': { data: tenantMe },
    'GET /app/capabilities': { data: { ...caps, modules: Object.fromEntries(MODULES.map((m) => [m, mode])), features: FEATURES } },
    ...routes,
  })
  setToken('t')
  return calls
}

export const rate = (over: Partial<TaxRate> = {}): TaxRate => ({ id: 'r-1', tax_code_id: 'tc-1', rate: '11.000000', effective_from: '2026-01-01', effective_until: null, ...over })

export const taxCode = (over: Partial<TaxCode> = {}): TaxCode => ({
  id: 'tc-1', code: 'PPN-KELUARAN', name: 'PPN Keluaran', description: null, tax_type: 'OUTPUT_TAX', calculation_method: 'EXCLUSIVE', treatment: 'STANDARD', is_recoverable: true,
  account_role: 'TAX_PAYABLE', account_id: null, account: null, status: 'ACTIVE', ...over,
})

/** The detail response: the code with its rates (newest first), the rate in force today and whether any document used it. */
export const taxCodeDetail = (over: Partial<TaxCode> = {}): TaxCode => taxCode({ rates: [rate({ id: 'r-2', rate: '12.000000', effective_from: '2026-11-01' }), rate({ effective_until: '2026-10-31' })], current_rate: '11.000000', in_use: false, ...over })

export const taxTransaction = (over: Partial<TaxTransaction> = {}): TaxTransaction => ({
  id: 'tx-1', tax_code_id: 'tc-1', tax_code: 'PPN-KELUARAN', tax_name: 'PPN Keluaran', tax_type: 'OUTPUT_TAX', treatment: 'STANDARD', calculation_method: 'EXCLUSIVE', is_recoverable: true,
  rate: '11.000000', direction: 'OUTPUT', source_type: 'ar_invoice', source_id: 'ar-1', line_number: 1, entered_amount: '1000000.0000', base_amount: '1000000.0000', tax_amount: '110000.0000',
  tax_date: '2026-10-05', posting_date: '2026-10-06', document_number: 'AR-FY2026-000001', counterparty_type: 'customer', counterparty_id: 'c-1', counterparty_name: 'PT Pelanggan Setia',
  counterparty_tax_id: '01.234.567.8-901.000', status: 'POSTED', currency: null, exchange_rate: null, functional_base_amount: null, functional_tax_amount: null, ...over,
})

export const account = (id: string, code: string, name: string, over: Partial<Account> = {}): Account => ({
  id, code, name, description: null, parent_id: null, account_type: 'ASSET', normal_balance: 'DEBIT', is_postable: true, is_control: false, currency: null, status: 'ACTIVE', ...over,
})
