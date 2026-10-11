import { configure } from '@testing-library/react'
import { setToken } from '../../lib/api'
import { mockApi } from '../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../test/render'
import type { LedgerLineX, LedgerReportX } from './ledger-types'

// Fixtures and boot helper of the trial balance / general ledger filter tests (imported by ledger-*.test.tsx only).

configure({ asyncUtilTimeout: 4000 })

export const AC = '/app/accounting'
export const noDimensions = { branches: [], business_units: [], cost_centers: [] }
export const years = {
  data: [{ id: 'fy-1', code: 'FY2026', name: 'TF 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN', periods: [
    { id: 'p-9', fiscal_year_id: 'fy-1', number: 9, code: '2026-09', name: 'September 2026', start_date: '2026-09-01', end_date: '2026-09-30', status: 'OPEN' },
  ] }],
}

/** Sign in with the given permissions; `modules` / `features` extend the accounting core (for the multi-currency filter). */
export function bootLedger(permissions: string[], routes: Parameters<typeof mockApi>[0] = {}, extra: { modules?: Record<string, string>; features?: Record<string, boolean> } = {}) {
  const caps = tenantCaps({ permissions })
  const calls = mockApi({
    'GET /auth/me': { data: tenantMe },
    'GET /app/capabilities': { data: { ...caps, modules: { ...caps.modules, ...extra.modules }, features: { ...caps.features, ...extra.features } } },
    ...routes,
  })
  setToken('t')
  return calls
}

export type LedgerCalls = ReturnType<typeof bootLedger>

const zeroPair = { debit: '0', credit: '0', difference: '0', equal: true }

export const trialBalance = (over: Record<string, unknown> = {}) => ({
  range: { from: '2026-10-01', to: '2026-10-08' },
  data: [{ account_id: 'a-1', code: '1110', name: 'Kas', account_type: 'ASSET', depth: 0, is_header: false, opening_debit: '0.0000', opening_credit: '0.0000', debit: '1500000.0000', credit: '0.0000', ending_debit: '1500000.0000', ending_credit: '0.0000' }],
  totals: { opening_debit: '0.0000', opening_credit: '0.0000', debit: '1500000.0000', credit: '1500000.0000', ending_debit: '1500000.0000', ending_credit: '1500000.0000' },
  reconciliation: { opening: zeroPair, movement: zeroPair, ending: zeroPair, reconciled: true },
  complete: true,
  ...over,
})

export const ledgerLine = (over: Partial<LedgerLineX> = {}): LedgerLineX => ({
  line_id: 'ln-1', posting_date: '2026-10-05', journal_id: 'j-1', journal_number: 'SJ-FY2026-000001', journal_type: 'MANUAL', journal_description: 'Penjualan tunai', description: null, reference: null,
  account: { id: 'a-1', code: '1110', name: 'Kas', normal_balance: 'DEBIT' }, debit: '1500000.0000', credit: '0.0000', running_balance: null, ...over,
})

export const ledger = (over: Partial<LedgerReportX> = {}): LedgerReportX => ({
  range: { from: '2026-10-01', to: '2026-10-08' }, account: null, summary: { debit: '1500000.0000', credit: '1500000.0000' }, data: [ledgerLine()],
  meta: { page: 1, per_page: 50, total: 1, last_page: 1 }, complete: true, functional_currency: 'IDR', ...over,
})
