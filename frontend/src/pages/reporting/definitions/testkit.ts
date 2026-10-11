import { configure } from '@testing-library/react'
import { setToken } from '../../../lib/api'
import { mockApi, page } from '../../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../../test/render'
import type { AccountBalanceReport, BalanceRow, DefinitionDetail, DefinitionHeader, LineMapping, ReportLine, UnmappedAccount, Validation } from './types'

// Shared fixtures and boot helper of the report definition and account balance tests (imported by the *.test.tsx files in this folder only).

// The first render of a file loads the whole app; give async queries room on a busy machine instead of flaking at the 1 s default.
configure({ asyncUtilTimeout: 4000 })

export type Routes = Parameters<typeof mockApi>[0]
export type Mode = 'FULL' | 'READ_ONLY' | 'NONE'

export const RC = '/app/accounting'
const MODULES = ['ACCOUNTING_CORE', 'ACCOUNTING_REPORTING']
const FEATURES = { FINANCIAL_STATEMENTS: true }

export const view = ['accounting.report_definition.view']
export const manage = [...view, 'accounting.report_definition.manage', 'accounting.coa.view']
export const balanceView = ['accounting.gl.view']

/** Sign in as a tenant user with the given permissions. `mode` applies to every module (a READ_ONLY subscription). Returns the recorded requests. */
export function boot(permissions: string[], routes: Routes = {}, options: { mode?: 'FULL' | 'READ_ONLY'; features?: Record<string, boolean> } = {}) {
  const mode = options.mode ?? 'FULL'
  const caps = tenantCaps({ permissions, subscriptionMode: mode })
  const calls = mockApi({
    'GET /auth/me': { data: tenantMe },
    'GET /app/capabilities': { data: { ...caps, modules: Object.fromEntries(MODULES.map((m) => [m, mode])), features: { ...FEATURES, ...options.features } } },
    ...routes,
  })
  setToken('t')
  return calls
}

export type Calls = ReturnType<typeof boot>

export const header = (over: Partial<DefinitionHeader> = {}): DefinitionHeader => ({
  id: 'd-1', statement_type: 'PROFIT_LOSS', code: 'PROFIT_LOSS', name: 'Laporan Laba Rugi', description: null, version: 1, status: 'DRAFT', based_on_id: null, effective_from: null,
  effective_to: null, published_by: null, published_at: null, archived_at: null, lines_count: 5, mappings_count: 2, created_at: '2026-10-01T03:00:00Z', updated_at: '2026-10-01T03:00:00Z', ...over,
})

export const mapping = (over: Partial<LineMapping> = {}): LineMapping => ({
  account_id: 'a-4100', code: '4100', name: 'Penjualan', account_type: 'REVENUE', is_postable: true, status: 'ACTIVE', include_descendants: false, ...over,
})

export const line = (over: Partial<ReportLine> = {}): ReportLine => ({
  id: 'l-1', code: 'REV', label: 'Pendapatan', line_type: 'SUBTOTAL', parent_code: null, depth: 0, display_order: 10, balance_side: null, cash_flow_category: null, system_key: null,
  operands: null, show_zero: false, emphasis: false, notes: null, mappings: [], ...over,
})

/** A small profit and loss tree: REV > REV_SALES (4100), EXP > EXP_RENT (6200), RESULT = REV - EXP. */
export const tree = (): ReportLine[] => [
  line(),
  line({ id: 'l-2', code: 'REV_SALES', label: 'Penjualan', line_type: 'ACCOUNT', parent_code: 'REV', depth: 1, display_order: 10, balance_side: 'CREDIT', mappings: [mapping()] }),
  line({ id: 'l-3', code: 'EXP', label: 'Beban', parent_code: null, display_order: 20 }),
  line({ id: 'l-4', code: 'EXP_RENT', label: 'Beban sewa', line_type: 'ACCOUNT', parent_code: 'EXP', depth: 1, display_order: 10, balance_side: 'DEBIT', mappings: [mapping({ account_id: 'a-6200', code: '6200', name: 'Beban Sewa', account_type: 'EXPENSE' })] }),
  line({ id: 'l-5', code: 'RESULT', label: 'Laba (rugi) bersih', line_type: 'CALCULATED_TOTAL', display_order: 30, operands: [{ line: 'REV', sign: '+' }, { line: 'EXP', sign: '-' }], emphasis: true }),
]

export const detail = (over: Partial<DefinitionDetail> = {}): DefinitionDetail => ({ ...header(over), lines: tree(), ...over })

export const published = (over: Partial<DefinitionDetail> = {}): DefinitionDetail => detail({ status: 'ACTIVE', effective_from: '2026-01-01', published_at: '2026-01-02T03:00:00Z', ...over })

export const validation = (over: Partial<Validation> = {}): Validation => ({ valid: true, errors: [], warnings: [], ...over })

export const unmapped = (over: Partial<UnmappedAccount> = {}): UnmappedAccount => ({ id: 'a-5100', code: '5100', name: 'Beban Gaji', account_type: 'EXPENSE', balance: '2500000.0000', has_activity: true, status: 'ACTIVE', ...over })

export const account = (id: string, code: string, name: string, over: Record<string, unknown> = {}) => ({
  id, code, name, description: null, parent_id: null, account_type: 'EXPENSE', normal_balance: 'DEBIT', is_postable: true, is_control: false, currency: null, status: 'ACTIVE', ...over,
})

export const noDimensions = { branches: [], business_units: [], cost_centers: [] }

// ------------------------------------------------------------------------------------------------ account balance

export const balanceRow = (over: Partial<BalanceRow> = {}): BalanceRow => ({
  account_id: 'a-1110', code: '1110', name: 'Kas', account_type: 'ASSET', normal_balance: 'DEBIT', parent_id: null, depth: 0, is_header: false, balance: '7200000.0000', ...over,
})

export const asOfReport = (over: Partial<AccountBalanceReport> = {}): AccountBalanceReport => ({
  mode: 'AS_OF', as_of: '2026-10-08', range: null, functional_currency: 'IDR', account: null, filters: {}, data: [balanceRow()],
  totals: { normal_balance: 'DEBIT', balance: '7200000.0000', closing_net_debit_minus_credit: '7200000.0000' }, complete: true, generated_at: '2026-10-08T04:30:00Z', ...over,
})

export const periodReport = (over: Partial<AccountBalanceReport> = {}): AccountBalanceReport => ({
  ...asOfReport(),
  mode: 'PERIOD',
  as_of: null,
  range: { from: '2026-10-01', to: '2026-10-08' },
  data: [balanceRow({ balance: undefined, opening: '6000000.0000', debit: '1500000.0000', credit: '300000.0000', closing: '7200000.0000' })],
  totals: { normal_balance: 'DEBIT', balance: '7200000.0000', closing_net_debit_minus_credit: '7200000.0000', opening: '6000000.0000', debit: '1500000.0000', credit: '300000.0000', closing: '7200000.0000', opening_net_debit_minus_credit: '6000000.0000' },
  ...over,
})

export { page }
