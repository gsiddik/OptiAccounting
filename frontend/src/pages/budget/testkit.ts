import { setToken } from '../../lib/api'
import { mockApi } from '../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../test/render'
import type { Budget, BudgetLine, BudgetVersion } from './types'

// Fixtures and boot helper of the budget page tests (imported by budget*.test.tsx only).

export type Routes = Parameters<typeof mockApi>[0]
export const API = '/app/accounting'

export function boot(permissions: string[], routes: Routes = {}, options: { mode?: 'FULL' | 'READ_ONLY' } = {}) {
  const mode = options.mode ?? 'FULL'
  const caps = tenantCaps({ permissions, subscriptionMode: mode })
  const calls = mockApi({
    'GET /auth/me': { data: tenantMe },
    'GET /app/capabilities': { data: { ...(caps as object), modules: { ACCOUNTING_CORE: mode, ACCOUNTING_BUDGET: mode }, features: { BUDGET: true } } },
    ...routes,
  })
  setToken('t')
  return calls
}

export const year = {
  id: 'fy-1', code: 'FY2026', name: 'Tahun Fiskal 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN',
  periods: [
    { id: 'p-1', fiscal_year_id: 'fy-1', number: 1, code: '2026-01', name: 'Januari 2026', start_date: '2026-01-01', end_date: '2026-01-31', status: 'OPEN' },
    { id: 'p-2', fiscal_year_id: 'fy-1', number: 2, code: '2026-02', name: 'Februari 2026', start_date: '2026-02-01', end_date: '2026-02-28', status: 'OPEN' },
  ],
}

export const versionRef = (over: Record<string, unknown> = {}) => ({ id: 'v-1', budget_id: 'b-1', version_number: 1, label: 'Original', status: 'DRAFT', effective_from: null, effective_until: null, ...over })

export const budget = (over: Partial<Budget> = {}): Budget => ({
  id: 'b-1', code: 'BUD-2026', name: 'Anggaran operasional 2026', description: null, fiscal_year_id: 'fy-1', currency: 'IDR', responsible_user_id: null, status: 'ACTIVE',
  activated_at: null, closed_at: null, cancelled_at: null, cancel_reason: null,
  fiscal_year: { id: 'fy-1', code: 'FY2026', name: 'Tahun Fiskal 2026', start_date: '2026-01-01', end_date: '2026-12-31' }, responsible: null, creator: { id: 'u-t', name: 'Budi Santoso' },
  versions: [versionRef() as never], transitions: [], ...over,
})

export const line = (over: Partial<BudgetLine> = {}): BudgetLine => ({
  id: 'l-1', account_id: 'a-6300', accounting_period_id: 'p-1', branch_id: null, business_unit_id: null, cost_center_id: null, amount: '5000000.0000', description: null,
  account: { id: 'a-6300', code: '6300', name: 'Beban Utilitas', account_type: 'EXPENSE', normal_balance: 'DEBIT', is_postable: true },
  period: { id: 'p-1', code: '2026-01', name: 'Januari 2026', number: 1, start_date: '2026-01-01', end_date: '2026-01-31' }, ...over,
})

export const version = (over: Partial<BudgetVersion> = {}): BudgetVersion => ({
  ...(versionRef() as unknown as Pick<BudgetVersion, 'id' | 'budget_id' | 'version_number' | 'label' | 'status' | 'effective_from' | 'effective_until'>), description: null, base_version_id: null, reject_reason: null, cancel_reason: null, budget: budget(), creator: { id: 'u-t', name: 'Budi Santoso' }, transitions: [],
  lines: [line()], lines_total: '5000000.0000', lines_complete: true, sod: { approve: true, post: true }, ...over,
})

export const accounts = {
  data: [
    { id: 'a-6300', code: '6300', name: 'Beban Utilitas', description: null, parent_id: null, account_type: 'EXPENSE', normal_balance: 'DEBIT', is_postable: true, is_control: false, currency: null, status: 'ACTIVE' },
    { id: 'a-6400', code: '6400', name: 'Beban Sewa', description: null, parent_id: null, account_type: 'EXPENSE', normal_balance: 'DEBIT', is_postable: true, is_control: false, currency: null, status: 'ACTIVE' },
    { id: 'a-9999', code: '9999', name: 'Akun lama', description: null, parent_id: null, account_type: 'EXPENSE', normal_balance: 'DEBIT', is_postable: true, is_control: false, currency: null, status: 'INACTIVE' },
  ],
}
export const noDimensions = { branches: [], business_units: [], cost_centers: [] }
