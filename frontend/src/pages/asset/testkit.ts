import { configure } from '@testing-library/react'
import type { Account } from '../../lib/accounting'
import { setToken } from '../../lib/api'
import { mockApi } from '../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../test/render'

// Shared fixtures and boot helper of the fixed asset page tests (imported by the *.test.tsx files in this folder only).

// The first render of a file loads the whole app; give async queries room on a busy machine instead of flaking at the 1 s default.
configure({ asyncUtilTimeout: 4000 })

export type Routes = Parameters<typeof mockApi>[0]
export type Mode = 'FULL' | 'READ_ONLY' | 'NONE'

export const AP = '/app/accounting'
const MODULES = ['ACCOUNTING_CORE', 'ACCOUNTING_FIXED_ASSET', 'ACCOUNTING_AP']
const FEATURES = { ASSET_REGISTER: true, DEPRECIATION: true, VENDOR_INVOICE: true }

export const noDimensions = { branches: [], business_units: [], cost_centers: [] }

/**
 * Sign in as a tenant user with the given permissions. `mode` applies to every module (a READ_ONLY subscription); `modes` overrides single modules.
 * The dimension catalog is mocked by default. Returns the recorded requests.
 */
export function boot(permissions: string[], routes: Routes = {}, options: { mode?: 'FULL' | 'READ_ONLY'; modes?: Record<string, Mode>; features?: Record<string, boolean> } = {}) {
  const mode = options.mode ?? 'FULL'
  const caps = tenantCaps({ permissions, subscriptionMode: mode })
  const modules = { ...Object.fromEntries(MODULES.map((m) => [m, mode])), ...options.modes }
  const calls = mockApi({
    'GET /auth/me': { data: tenantMe },
    'GET /app/capabilities': { data: { ...caps, modules, features: { ...FEATURES, ...options.features } } },
    'GET /app/accounting/dimensions': { data: noDimensions },
    ...routes,
  })
  setToken('t')
  return calls
}

export const account = (id: string, code: string, name: string, over: Partial<Account> = {}): Account => ({
  id, code, name, description: null, parent_id: null, account_type: 'ASSET', normal_balance: 'DEBIT', is_postable: true, is_control: false, currency: null, status: 'ACTIVE', ...over,
})

/** A small chart: what the asset forms may and may not offer. */
export const chart = [
  account('a-1000', '1000', 'Aset (induk)', { is_postable: false }),
  account('a-1120', '1120', 'Bank BCA'),
  account('a-1500', '1500', 'Kendaraan'),
  account('a-1590', '1590', 'Akumulasi Penyusutan Kendaraan'),
  account('a-2110', '2110', 'Utang Usaha', { account_type: 'LIABILITY', normal_balance: 'CREDIT', is_control: true }),
  account('a-2190', '2190', 'Utang Pembelian Aset', { account_type: 'LIABILITY', normal_balance: 'CREDIT' }),
  account('a-4250', '4250', 'Laba Rugi Pelepasan Aset', { account_type: 'REVENUE', normal_balance: 'CREDIT' }),
  account('a-6500', '6500', 'Beban Penyusutan', { account_type: 'EXPENSE' }),
]

export const category = (over: Record<string, unknown> = {}) => ({
  id: 'c-1', code: 'KEND', name: 'Kendaraan', description: null, status: 'ACTIVE', asset_account_id: null, accumulated_account_id: null, expense_account_id: null, gain_loss_account_id: null,
  default_method: 'STRAIGHT_LINE', default_useful_life_months: 48, default_residual_type: 'PERCENT', default_residual_value: '10.0000', default_start_policy: 'CAPITALIZATION_MONTH',
  asset_account: null, accumulated_account: null, expense_account: null, gain_loss_account: null, ...over,
})

const transition = { id: 'tr-1', from_status: null, to_status: 'DRAFT', actor_user_id: 'u-t', actor: { id: 'u-t', name: 'Budi Santoso' }, reason: null, occurred_at: '2026-10-08T03:00:00Z' }

/** A draft asset as `GET assets/{id}` returns it. */
export const asset = (over: Record<string, unknown> = {}) => ({
  id: 'as-1', asset_number: null, name: 'Truk Hino', description: null, asset_category_id: 'c-1', status: 'DRAFT', acquisition_date: '2026-10-01', capitalization_date: '2026-10-01',
  acquisition_cost: '120000000.0000', residual_value: '12000000.0000', useful_life_months: 48, method: 'STRAIGHT_LINE', method_params: null, start_policy: 'CAPITALIZATION_MONTH', currency: 'IDR',
  branch_id: null, business_unit_id: null, cost_center_id: null, capitalization_mode: 'POST', source_account_id: 'a-1120', source_type: null, source_id: null, source_reference: null,
  asset_account_id: null, accumulated_account_id: null, expense_account_id: null, gain_loss_account_id: null, depreciable_basis: null, accumulated_depreciation: '0.0000',
  capitalization_journal_id: null, capitalization_reversal_journal_id: null, capitalization_reversal_reason: null, disposed_on: null, disposal_id: null, inactive_reason: null, created_by: 'u-t',
  net_book_value: '120000000.0000', schedule_summary: { rows: 0, planned: 0, in_run: 0, posted: 0, cancelled: 0, next_period: null }, sod: { capitalize: true },
  category: { id: 'c-1', code: 'KEND', name: 'Kendaraan', status: 'ACTIVE' }, branch: null, creator: { id: 'u-t', name: 'Budi Santoso' }, source_account: { id: 'a-1120', code: '1120', name: 'Bank BCA' },
  transitions: [transition], ...over,
})

export const capitalized = (over: Record<string, unknown> = {}) => asset({
  status: 'ACTIVE', asset_number: 'FA-FY2026-000001', depreciable_basis: '108000000.0000', capitalization_journal_id: 'j-10',
  asset_account_id: 'a-1500', accumulated_account_id: 'a-1590', expense_account_id: 'a-6500',
  asset_account: { id: 'a-1500', code: '1500', name: 'Kendaraan' }, accumulated_account: { id: 'a-1590', code: '1590', name: 'Akumulasi Penyusutan Kendaraan' }, expense_account: { id: 'a-6500', code: '6500', name: 'Beban Penyusutan' },
  schedule_summary: { rows: 48, planned: 48, in_run: 0, posted: 0, cancelled: 0, next_period: '2026-10-01' }, ...over,
})

export const scheduleRows = [
  { id: 'sr-1', sequence_no: 1, period_start: '2026-10-01', period_end: '2026-10-31', amount: '2250000.0000', accumulated_after: '2250000.0000', book_value_after: '117750000.0000', status: 'PLANNED' },
  { id: 'sr-2', sequence_no: 2, period_start: '2026-11-01', period_end: '2026-11-30', amount: '2250000.0000', accumulated_after: '4500000.0000', book_value_after: '115500000.0000', status: 'PLANNED' },
]

export const runLine = (over: Record<string, unknown> = {}) => ({
  id: 'rl-1', line_number: 1, fixed_asset_id: 'as-1', schedule_rows: 2, amount: '4500000.0000', accumulated_before: '0.0000', accumulated_after: '4500000.0000', book_value_after: '115500000.0000',
  asset: { id: 'as-1', asset_number: 'FA-FY2026-000001', name: 'Truk Hino', status: 'ACTIVE' }, ...over,
})

export const run = (over: Record<string, unknown> = {}) => ({
  id: 'r-1', document_number: null, accounting_period_id: 'p-10', posting_date: '2026-10-31', description: 'Penyusutan Oktober', reference: null, total_amount: '4500000.0000', asset_count: 1,
  status: 'DRAFT', cancel_reason: null, posted_at: null, journal_entry_id: null, reversal_journal_id: null, reversal_reason: null, reversal_posting_date: null,
  period: { id: 'p-10', code: '2026-10', start_date: '2026-10-01', end_date: '2026-10-31', status: 'OPEN' }, creator: { id: 'u-t', name: 'Budi Santoso' },
  transitions: [transition], lines: [runLine()], sod: { approve: true, post: true }, ...over,
})

export const postedRun = (over: Record<string, unknown> = {}) => run({ status: 'POSTED', document_number: 'DEP-FY2026-000001', journal_entry_id: 'j-20', posted_at: '2026-10-31T10:00:00Z', ...over })

export const disposal = (over: Record<string, unknown> = {}) => ({
  id: 'd-1', document_number: null, fixed_asset_id: 'as-1', disposal_type: 'SALE', status: 'DRAFT', document_date: '2026-10-08', disposal_date: '2026-10-08', posting_date: '2026-10-08',
  proceeds_amount: '90000000.0000', proceeds_account_id: 'a-1120', reason: 'Dijual ke dealer', reference: null, branch_id: null, business_unit_id: null, cost_center_id: null,
  cost_amount: null, accumulated_depreciation: null, book_value: null, gain_amount: null, loss_amount: null, reject_reason: null, cancel_reason: null, posted_at: null,
  journal_entry_id: null, reversal_journal_id: null, reversal_reason: null, reversal_posting_date: null,
  asset: { id: 'as-1', asset_number: 'FA-FY2026-000001', name: 'Truk Hino', status: 'ACTIVE', acquisition_cost: '120000000.0000', accumulated_depreciation: '45000000.0000' },
  proceeds_account: { id: 'a-1120', code: '1120', name: 'Bank BCA' }, creator: { id: 'u-t', name: 'Budi Santoso' },
  preview: { cost: '120000000.0000', accumulated: '45000000.0000', book_value: '75000000.0000', proceeds: '90000000.0000', gain: '15000000.0000', loss: '0.0000' },
  transitions: [transition], sod: { approve: true, post: true, approval_required: true }, ...over,
})

export const postedDisposal = (over: Record<string, unknown> = {}) => disposal({
  status: 'POSTED', document_number: 'AD-FY2026-000001', journal_entry_id: 'j-30', posted_at: '2026-10-08T05:00:00Z', cost_amount: '120000000.0000', accumulated_depreciation: '45000000.0000',
  book_value: '75000000.0000', gain_amount: '15000000.0000', loss_amount: '0.0000',
  preview: { cost: '120000000.0000', accumulated: '45000000.0000', book_value: '75000000.0000', proceeds: '90000000.0000', gain: '15000000.0000', loss: '0.0000' }, ...over,
})

export const reconRow = (over: Record<string, unknown> = {}) => ({ account: { id: 'a-1500', code: '1500', name: 'Kendaraan' }, asset_count: 3, register: '360000000.0000', ledger: '360000000.0000', difference: '0.0000', matched: true, ...over })

export const reconciliation = (over: Record<string, unknown> = {}) => ({
  as_of: '2026-10-08', complete: true, cost: [reconRow()], accumulated_depreciation: [reconRow({ account: { id: 'a-1590', code: '1590', name: 'Akumulasi Penyusutan Kendaraan' }, register: '90000000.0000', ledger: '90000000.0000' })],
  totals: { register_cost: '360000000.0000', ledger_cost: '360000000.0000', register_accumulated: '90000000.0000', ledger_accumulated: '90000000.0000', register_net_book_value: '270000000.0000', ledger_net_book_value: '270000000.0000', difference: '0.0000' },
  reconciled: true, ...over,
})
