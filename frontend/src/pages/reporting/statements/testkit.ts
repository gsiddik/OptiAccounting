import { configure } from '@testing-library/react'
import { setToken } from '../../../lib/api'
import { mockApi } from '../../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../../test/render'
import type {
  BalanceSheetIntegrity, BalanceSheetReport, CashFlowIntegrity, CashFlowReport, ComparativeLine, ComparativeReport, DefinitionRef, Drilldown, ProfitLossIntegrity, ProfitLossReport, StatementLine,
} from './types'

// Shared fixtures and boot helper of the financial statement page tests (imported by the *.test.tsx files in this folder only). The payloads follow the
// shapes of backend/tests/Feature/Reporting (FinancialStatementsTest, CashFlowStatementTest, ComparativeReportTest, DrilldownTest).

// The first render of a file loads the whole app; give async queries room on a busy machine instead of flaking at the 1 s default.
configure({ asyncUtilTimeout: 4000 })

export type Routes = Parameters<typeof mockApi>[0]
export type Mode = 'FULL' | 'READ_ONLY' | 'NONE'

export const RP = '/app/accounting'
const MODULES = ['ACCOUNTING_CORE', 'ACCOUNTING_REPORTING', 'ACCOUNTING_BUDGET']
const FEATURES = { FINANCIAL_STATEMENTS: true }
export const noDimensions = { branches: [], business_units: [], cost_centers: [] }

export const balanceView = ['accounting.reporting.balance_sheet.view']
export const pnlView = ['accounting.reporting.pnl.view']
export const cashView = ['accounting.reporting.cash_flow.view']
export const ledgerView = ['accounting.gl.view']
export const exporter = ['accounting.report.export']

/**
 * Sign in as a tenant user with the given permissions. `mode` applies to every module (a READ_ONLY subscription); `modes` overrides single modules.
 * The dimension catalog is mocked by default. Returns the recorded requests.
 */
export function boot(permissions: string[], routes: Routes = {}, options: { mode?: 'FULL' | 'READ_ONLY'; modes?: Record<string, Mode> } = {}) {
  const mode = options.mode ?? 'FULL'
  const caps = tenantCaps({ permissions, subscriptionMode: mode })
  const modules = { ...Object.fromEntries(MODULES.map((m) => [m, mode])), ...options.modes }
  const calls = mockApi({
    'GET /auth/me': { data: tenantMe },
    'GET /app/capabilities': { data: { ...caps, modules, features: FEATURES } },
    [`GET ${RP}/dimensions`]: { data: noDimensions },
    ...routes,
  })
  setToken('t')
  return calls
}

export type Calls = ReturnType<typeof boot>
export const gets = (calls: Calls, url: string) => calls.filter((c) => c.method === 'GET' && c.url === url)

// ------------------------------------------------------------------------------------------------ statement lines

export const definition = (over: Partial<DefinitionRef> = {}): DefinitionRef => ({
  id: 'def-1', code: 'BS-STD', name: 'Neraca Standar', version: 2, status: 'ACTIVE', effective_from: '2026-01-01', effective_to: null, ...over,
})

export const line = (over: Partial<StatementLine> = {}): StatementLine => ({
  id: `l-${over.code ?? 'x'}`, code: 'ACC_1110', label: 'Kas', line_type: 'ACCOUNT', depth: 1, parent_code: 'HDR_ASSET', amount: '7200000.0000', emphasis: false, accounts: [],
  balance_side: 'DEBIT', system_key: null, cash_flow_category: null, notes: null, ...over,
})

export const bsLines = (): StatementLine[] => [
  line({ code: 'HDR_ASSET', label: 'ASET', line_type: 'HEADER', depth: 0, parent_code: null, amount: null, balance_side: null }),
  line({ code: 'ACC_1110', label: 'Kas', accounts: [{ id: 'a-1110', code: '1110', name: 'Kas Kecil', amount: '7200000.0000' }] }),
  line({ code: 'ACC_1120', label: 'Bank', amount: '4400000.0000', accounts: [{ id: 'a-1120', code: '1120', name: 'Bank BCA', amount: '4400000.0000' }] }),
  line({ code: 'TOT_1000', label: 'Total aset', line_type: 'SUBTOTAL', depth: 0, parent_code: null, amount: '11600000.0000', emphasis: true, balance_side: null }),
  line({ code: 'HDR_FUND', label: 'KEWAJIBAN DAN EKUITAS', line_type: 'HEADER', depth: 0, parent_code: null, amount: null, balance_side: null }),
  line({ code: 'ACC_2140', label: 'Utang usaha', parent_code: 'HDR_FUND', amount: '400000.0000', balance_side: 'CREDIT' }),
  line({ code: 'ACC_3100', label: 'Modal disetor', parent_code: 'HDR_FUND', amount: '8500000.0000', balance_side: 'CREDIT' }),
  line({ code: 'CURRENT_RESULT', label: 'Laba tahun berjalan', line_type: 'SYSTEM', parent_code: 'HDR_FUND', amount: '2700000.0000', balance_side: null, system_key: 'CURRENT_RESULT' }),
  line({ code: 'TOT_FUND', label: 'Total kewajiban dan ekuitas', line_type: 'SUBTOTAL', depth: 0, parent_code: null, amount: '11600000.0000', emphasis: true, balance_side: null }),
]

export const bsIntegrity = (over: Partial<BalanceSheetIntegrity> = {}): BalanceSheetIntegrity => ({
  assets: '11600000.0000', liabilities_and_equity: '11600000.0000', difference: '0.0000', balanced: true, reliable: true, current_result: '2700000.0000', unmapped: [], ...over,
})

export const balanceSheet = (over: Partial<BalanceSheetReport> = {}): BalanceSheetReport => ({
  statement: 'BALANCE_SHEET', definition: definition(), currency: 'IDR', as_of: '2026-10-08', generated_at: '2026-10-08T03:30:00Z', complete: true,
  filters: { include_zero: false }, lines: bsLines(), integrity: bsIntegrity(), warnings: [], ...over,
})

export const plLines = (): StatementLine[] => [
  line({ code: 'HDR_REV', label: 'PENDAPATAN', line_type: 'HEADER', depth: 0, parent_code: null, amount: null, balance_side: null }),
  line({ code: 'ACC_4100', label: 'Penjualan', amount: '2800000.0000', balance_side: 'CREDIT', parent_code: 'HDR_REV', accounts: [{ id: 'a-4100', code: '4100', name: 'Penjualan Barang', amount: '2800000.0000' }] }),
  line({ code: 'ACC_5100', label: 'Beban pokok penjualan', amount: '400000.0000', parent_code: 'HDR_REV' }),
  line({ code: 'NET_RESULT_CALC', label: 'Laba bersih', line_type: 'CALCULATED_TOTAL', depth: 0, parent_code: null, amount: '1900000.0000', emphasis: true, balance_side: null }),
]

export const plIntegrity = (over: Partial<ProfitLossIntegrity> = {}): ProfitLossIntegrity => ({
  ledger_result: '1900000.0000', mapped_result: '1900000.0000', difference: '0.0000', reliable: true, unmapped: [], ...over,
})

export const profitLoss = (over: Partial<ProfitLossReport> = {}): ProfitLossReport => ({
  statement: 'PROFIT_LOSS', definition: definition({ id: 'def-2', code: 'PL-STD', name: 'Laba Rugi Standar', version: 1 }), currency: 'IDR', range: { from: '2026-01-01', to: '2026-10-08' },
  generated_at: '2026-10-08T03:31:00Z', complete: true, filters: { include_zero: false }, lines: plLines(), integrity: plIntegrity(), warnings: [], ...over,
})

export const cfLines = (): StatementLine[] => [
  line({ code: 'NET_RESULT', label: 'Laba bersih periode', line_type: 'SYSTEM', depth: 0, parent_code: null, amount: '1900000.0000', balance_side: null, system_key: 'NET_RESULT' }),
  line({ code: 'ACC_2140', label: 'Kenaikan utang usaha', depth: 1, parent_code: 'CF_OPERATING', amount: '400000.0000', cash_flow_category: 'OPERATING', balance_side: 'CREDIT' }),
  line({ code: 'CF_OPERATING', label: 'Arus kas operasi', line_type: 'SUBTOTAL', depth: 0, parent_code: null, amount: '2300000.0000', emphasis: true, balance_side: null }),
  line({ code: 'ACC_1230', label: 'Pembelian peralatan', depth: 1, parent_code: 'CF_INVESTING', amount: '-500000.0000', cash_flow_category: 'INVESTING' }),
  line({ code: 'CF_INVESTING', label: 'Arus kas investasi', line_type: 'SUBTOTAL', depth: 0, parent_code: null, amount: '-500000.0000', emphasis: true, balance_side: null }),
  line({ code: 'UNCLASSIFIED_CASH_FLOW', label: 'Pergerakan belum diklasifikasi', line_type: 'SYSTEM', depth: 0, parent_code: null, amount: '0.0000', balance_side: null, system_key: 'UNCLASSIFIED_CASH_FLOW' }),
  line({ code: 'CASH_OPENING', label: 'Kas awal periode', line_type: 'SYSTEM', depth: 0, parent_code: null, amount: '9800000.0000', balance_side: null, system_key: 'CASH_OPENING' }),
  line({ code: 'CASH_CLOSING', label: 'Kas akhir periode', line_type: 'SYSTEM', depth: 0, parent_code: null, amount: '11600000.0000', emphasis: true, balance_side: null, system_key: 'CASH_CLOSING' }),
]

export const cfIntegrity = (over: Partial<CashFlowIntegrity> = {}): CashFlowIntegrity => ({
  net_result: '1900000.0000', cash_opening: '9800000.0000', net_change: '1800000.0000', cash_closing_calculated: '11600000.0000', cash_closing_ledger: '11600000.0000', difference: '0.0000',
  reconciled: true, reliable: true,
  categories: { OPERATING: '400000.0000', INVESTING: '-500000.0000', FINANCING: '0.0000', NON_CASH: '0.0000', UNCLASSIFIED: '0.0000' }, unclassified: [],
  cash_accounts: [
    { id: 'a-1110', code: '1110', name: 'Kas Kecil', opening: '6000000.0000', movement: '1200000.0000', closing: '7200000.0000' },
    { id: 'a-1120', code: '1120', name: 'Bank BCA', opening: '3800000.0000', movement: '600000.0000', closing: '4400000.0000' },
  ], ...over,
})

export const cashFlow = (over: Partial<CashFlowReport> = {}): CashFlowReport => ({
  statement: 'CASH_FLOW', definition: definition({ id: 'def-3', code: 'CF-STD', name: 'Arus Kas Standar', version: 1 }), currency: 'IDR', range: { from: '2026-01-01', to: '2026-03-31' },
  generated_at: '2026-10-08T03:32:00Z', complete: true, filters: { include_zero: false }, lines: cfLines(), integrity: cfIntegrity(), warnings: [], ...over,
})

// ------------------------------------------------------------------------------------------------ comparative and drill-down

export const cline = (over: Partial<ComparativeLine> = {}): ComparativeLine => ({
  code: 'ACC_4100', label: 'Penjualan', line_type: 'ACCOUNT', depth: 1, parent_code: 'HDR_REV', emphasis: false, current: '800000.0000', comparison: '2000000.0000', variance: '-1200000.0000',
  variance_pct: '-60.00', accounts: [{ id: 'a-4100', code: '4100', name: 'Penjualan Barang', current: '800000.0000', comparison: '2000000.0000', variance: '-1200000.0000', variance_pct: '-60.00' }], ...over,
})

export const comparative = (over: Partial<ComparativeReport> = {}): ComparativeReport => ({
  statement: 'PROFIT_LOSS', mode: 'PRIOR_PERIOD', definition: definition({ id: 'def-2', code: 'PL-STD', name: 'Laba Rugi Standar', version: 1 }), currency: 'IDR', range: { from: '2026-02-01', to: '2026-02-28' },
  comparison: { mode: 'PRIOR_PERIOD', range: { from: '2026-01-01', to: '2026-01-31' } }, generated_at: '2026-10-08T03:33:00Z', complete: true, filters: { include_zero: false },
  lines: [
    cline({ code: 'HDR_REV', label: 'PENDAPATAN', line_type: 'HEADER', depth: 0, parent_code: null, current: null, comparison: null, variance: null, variance_pct: null, accounts: [] }),
    cline(),
    cline({ code: 'ACC_6200', label: 'Beban sewa', current: '200000.0000', comparison: '0.0000', variance: '200000.0000', variance_pct: null, accounts: [] }),
    cline({ code: 'NET_RESULT_CALC', label: 'Laba bersih', line_type: 'CALCULATED_TOTAL', depth: 0, parent_code: null, emphasis: true, current: '600000.0000', comparison: '1700000.0000', variance: '-1100000.0000', variance_pct: '-64.71', accounts: [] }),
  ],
  integrity: { current: plIntegrity({ ledger_result: '600000.0000', mapped_result: '600000.0000' }), comparison: plIntegrity({ ledger_result: '1700000.0000', mapped_result: '1700000.0000' }) },
  warnings: [], ...over,
})

export const drilldown = (over: Partial<Drilldown> = {}): Drilldown => ({
  statement: 'BALANCE_SHEET', definition: definition(), currency: 'IDR', as_of: '2026-10-08', generated_at: '2026-10-08T03:34:00Z', complete: true, filters: { include_zero: false },
  line: { code: 'TOT_1000', label: 'Total aset', line_type: 'SUBTOTAL', depth: 0, parent_code: null, amount: '11600000.0000', emphasis: true, balance_side: null, system_key: null, cash_flow_category: null, notes: null },
  explanation: null,
  components: [
    { code: 'ACC_1110', label: 'Kas', line_type: 'ACCOUNT', sign: '+', amount: '7200000.0000' },
    { code: 'ACC_1120', label: 'Bank', line_type: 'ACCOUNT', sign: '+', amount: '4400000.0000' },
  ],
  accounts: [
    { id: 'a-1110', code: '1110', name: 'Kas Kecil', amount: '7200000.0000', line_code: 'ACC_1110', contribution: '7200000.0000', gl_query: { account_id: 'a-1110', from: '2026-01-01', to: '2026-10-08' } },
    { id: 'a-1120', code: '1120', name: 'Bank BCA', amount: '4400000.0000', line_code: 'ACC_1120', contribution: '4400000.0000', gl_query: { account_id: 'a-1120', from: '2026-01-01', to: '2026-10-08' } },
  ],
  accounts_total: '11600000.0000', not_explained_by_accounts: '0.0000', general_ledger: { endpoint: 'general-ledger', closing_journals_listed: true, note: 'English note from the server.' }, warnings: [], ...over,
})
