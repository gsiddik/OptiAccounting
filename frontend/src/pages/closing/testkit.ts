import { configure } from '@testing-library/react'
import { setToken } from '../../lib/api'
import { mockApi, page } from '../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../test/render'
import type { ChecklistItem, CloseCheck, ClosingEvent, ClosingOverviewData, Policy, PreviewAccount, Readiness, RuleStatus, Template, YearEndPreview, YearEndRun, YearEndShow, YearEndSummary } from './types'

// Shared fixtures and boot helper of the closing page tests (imported by the *.test.tsx files in this folder only).

// The first render of a file loads the whole app; give async queries room on a busy machine instead of flaking at the 1 s default.
configure({ asyncUtilTimeout: 4000 })

export type Routes = Parameters<typeof mockApi>[0]
export type Mode = 'FULL' | 'READ_ONLY' | 'NONE'

export const CL = '/app/accounting'
const MODULES = ['ACCOUNTING_CORE', 'ACCOUNTING_REPORTING']
const FEATURES = { PERIOD_CLOSING: true }

export const view = ['accounting.period.view']
export const closer = [...view, 'accounting.period.preclose', 'accounting.period.soft_close', 'accounting.period.hard_close', 'accounting.period.reopen']
export const checklistManager = [...view, 'accounting.closing.checklist.manage']
export const policyManager = [...view, 'accounting.closing.policy.manage']
export const yearView = [...view, 'accounting.year_end.view']
export const yearRunner = [...yearView, 'accounting.year_end.run']

/**
 * Sign in as a tenant user with the given permissions. `mode` applies to every module (a READ_ONLY subscription); `modes` overrides single modules.
 * Returns the recorded requests.
 */
export function boot(permissions: string[], routes: Routes = {}, options: { mode?: 'FULL' | 'READ_ONLY'; modes?: Record<string, Mode> } = {}) {
  const mode = options.mode ?? 'FULL'
  const caps = tenantCaps({ permissions, subscriptionMode: mode })
  const modules = { ...Object.fromEntries(MODULES.map((m) => [m, mode])), ...options.modes }
  const calls = mockApi({
    'GET /auth/me': { data: tenantMe },
    'GET /app/capabilities': { data: { ...caps, modules, features: FEATURES } },
    ...routes,
  })
  setToken('t')
  return calls
}

export type Calls = ReturnType<typeof boot>
export const gets = (calls: Calls, url: string) => calls.filter((c) => c.method === 'GET' && c.url === url)
export const posts = (calls: Calls) => calls.filter((c) => c.method !== 'GET' && c.url.startsWith(CL))

// ------------------------------------------------------------------------------------------ calendar and readiness

export const years = (status9: 'OPEN' | 'SOFT_CLOSED' | 'CLOSED' | 'FUTURE' = 'OPEN') => ({
  data: [{
    id: 'fy-1', code: 'FY2026', name: 'TF 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN', periods: [
      { id: 'p-8', fiscal_year_id: 'fy-1', number: 8, code: '2026-08', name: 'Agustus 2026', start_date: '2026-08-01', end_date: '2026-08-31', status: 'CLOSED' },
      { id: 'p-9', fiscal_year_id: 'fy-1', number: 9, code: '2026-09', name: 'September 2026', start_date: '2026-09-01', end_date: '2026-09-30', status: status9 },
      { id: 'p-10', fiscal_year_id: 'fy-1', number: 10, code: '2026-10', name: 'Oktober 2026', start_date: '2026-10-01', end_date: '2026-10-31', status: 'FUTURE' },
    ],
  }],
})

export const check = (code: string, over: Partial<CloseCheck> = {}): CloseCheck => ({
  code, title: `Pemeriksaan ${code}`, severity: 'BLOCK', result: 'PASS', message: 'Lolos.', details: {}, ...over,
})

export const trialBalance = (): CloseCheck => check('TRIAL_BALANCE', {
  title: 'Neraca saldo seimbang', message: 'Total debit sama dengan total kredit sampai 2026-09-30.',
  details: { as_of: '2026-09-30', total_debit: '9000000.0000', total_credit: '9000000.0000', difference: '0.0000', period_debit: '4000000.0000', period_credit: '4000000.0000', period_difference: '0.0000' },
})

export const pendingJournals = (): CloseCheck => check('PENDING_JOURNALS', {
  title: 'Jurnal diajukan atau disetujui belum diposting', result: 'FAIL', message: '2 jurnal diajukan atau disetujui yang belum diposting pada periode ini.',
  details: { count: 2, by_status: { APPROVED: 1, SUBMITTED: 1 }, sample: [{ id: 'j-1', journal_type: 'MANUAL', status: 'APPROVED', posting_date: '2026-09-10', description: 'Akrual gaji' }, { id: 'j-2', journal_type: 'MANUAL', status: 'SUBMITTED', posting_date: '2026-09-12', description: 'Koreksi sewa' }] },
})

export const apRecon = (status = 'MISMATCH'): CloseCheck => check('AP_RECONCILIATION', {
  title: 'Rekonsiliasi utang usaha dengan buku besar', result: status === 'MATCHED' ? 'PASS' : 'FAIL', message: 'Rekonsiliasi tidak cocok pada 2026-09-30: selisih -5000.0000.',
  details: { type: 'AP_GL', as_of: '2026-09-30', status, source_balance: '700000.0000', gl_balance: '705000.0000', difference: '-5000.0000', tolerance: '0.0000', complete: true, components: [], notes: [] },
})

export const readiness = (checks: CloseCheck[] = [trialBalance(), pendingJournals(), apRecon(), check('DRAFT_JOURNALS', { severity: 'WARN', result: 'WARN', message: '1 jurnal draf pada periode ini.', details: { count: 1, by_status: { DRAFT: 1 }, sample: [] } }), check('TAX_RECONCILIATION', { severity: 'WARN', result: 'SKIPPED', message: 'Modul tidak berlangganan; pemeriksaan tidak diperlukan.' })], over: Partial<Readiness> = {}): Readiness => {
  const blocking = checks.filter((c) => c.severity === 'BLOCK' && c.result === 'FAIL').length
  return {
    period_id: 'p-9', period_code: '2026-09', target: 'HARD', result: blocking === 0 ? 'READY' : 'NOT_READY', blocking_count: blocking,
    warning_count: checks.filter((c) => c.result === 'WARN').length, evaluated_at: '2026-10-08T03:00:00Z', checks, ...over,
  }
}

export const ready = (): Readiness => readiness([trialBalance(), check('CHECKLIST', { message: 'Semua butir wajib selesai.' })])

export const item = (over: Partial<ChecklistItem> = {}): ChecklistItem => ({
  id: 'i-1', code: 'REVIEW_ACCRUALS', title: 'Tinjau akrual', required: true, sort_order: 1, status: 'PENDING', generated: true, notes: null,
  completed_by: null, completed_at: null, waived_by: null, waived_at: null, waive_reason: null, ...over,
})

export const checklist = (items: ChecklistItem[] = [item(), item({ id: 'i-2', code: 'SIGN_PACK', title: 'Tanda tangan paket laporan', sort_order: 2, status: 'COMPLETED', notes: 'Sudah ditandatangani', completed_at: '2026-10-07T04:00:00Z' })], status: 'OPEN' | 'CLOSED' = 'OPEN') => ({
  period: { id: 'p-9', code: '2026-09', status },
  system_checks: [{ ...trialBalance(), settable: false }, { ...pendingJournals(), settable: false }],
  manual_items: items,
  summary: { required: items.filter((i) => i.required).length, pending_required: items.filter((i) => i.required && i.status === 'PENDING').length, completed: items.filter((i) => i.status === 'COMPLETED').length, waived: items.filter((i) => i.status === 'WAIVED').length },
})

export const event = (over: Partial<ClosingEvent> = {}): ClosingEvent => ({
  id: 'e-1', event_type: 'SOFT_CLOSE', accounting_period_id: 'p-9', period_code: '2026-09', fiscal_year_id: 'fy-1', fiscal_year_code: 'FY2026', from_status: 'OPEN', to_status: 'SOFT_CLOSED',
  readiness: 'NOT_READY', readiness_checks: [check('TRIAL_BALANCE', { title: 'Neraca saldo seimbang' }), check('PENDING_JOURNALS', { title: 'Jurnal tertunda', result: 'FAIL' })], reason: 'Mulai penutupan September', reference: null,
  actor_id: 'u-1', actor_name: 'Sari Dewi', journal_entry_id: null, created_at: '2026-10-07T04:00:00Z', ...over,
})

export const history = (rows: ClosingEvent[] = [event(), event({ id: 'e-2', event_type: 'REOPEN', from_status: 'CLOSED', to_status: 'SOFT_CLOSED', readiness: null, readiness_checks: null, reason: 'Koreksi akrual', period_code: '2026-08', accounting_period_id: 'p-8' })]) => page(rows)

export const overviewData = (): ClosingOverviewData => ({
  business_date: '2026-10-08',
  periods: [
    { id: 'p-8', code: '2026-08', name: 'Agustus 2026', fiscal_year_id: 'fy-1', fiscal_year_code: 'FY2026', start_date: '2026-08-01', end_date: '2026-08-31', status: 'CLOSED', closed_at: '2026-09-05T04:00:00Z', closed_by: 'u-1',
      last_event: { event_type: 'HARD_CLOSE', from_status: 'SOFT_CLOSED', to_status: 'CLOSED', reason: null, actor_id: 'u-1', actor_name: 'Sari Dewi', at: '2026-09-05T04:00:00Z' }, last_pre_close_at: null, readiness: null },
    { id: 'p-9', code: '2026-09', name: 'September 2026', fiscal_year_id: 'fy-1', fiscal_year_code: 'FY2026', start_date: '2026-09-01', end_date: '2026-09-30', status: 'OPEN', closed_at: null, closed_by: null,
      last_event: null, last_pre_close_at: '2026-10-05T04:00:00Z', readiness: { result: 'NOT_READY', blocking: ['PENDING_JOURNALS', 'AP_RECONCILIATION'], warnings: ['DRAFT_JOURNALS'] } },
    { id: 'p-10', code: '2026-10', name: 'Oktober 2026', fiscal_year_id: 'fy-1', fiscal_year_code: 'FY2026', start_date: '2026-10-01', end_date: '2026-10-31', status: 'OPEN', closed_at: null, closed_by: null, last_event: null, last_pre_close_at: null, readiness: null },
  ],
  fiscal_years: [{ id: 'fy-1', code: 'FY2026', name: 'TF 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN', year_end: { status: 'NOT_RUN', run_no: null, journal_entry_id: null, run_at: null } }],
})

// ------------------------------------------------------------------------------------------ policy

export const policy = (): Policy => ({
  checks: [
    { check_code: 'TRIAL_BALANCE', title: 'Neraca saldo seimbang', default_severity: 'BLOCK', severity: 'BLOCK', locked: true, module: null },
    { check_code: 'DRAFT_JOURNALS', title: 'Jurnal draf pada periode', default_severity: 'WARN', severity: 'WARN', locked: false, module: null },
    { check_code: 'AP_RECONCILIATION', title: 'Rekonsiliasi utang usaha dengan buku besar', default_severity: 'BLOCK', severity: 'BLOCK', locked: false, module: 'ACCOUNTING_AP' },
  ],
  settings: { reconciliation_tolerance: '0.0000', reopen_max_periods_back: null, reopen_requires_later_open: true, separate_closer_and_reopener: false, require_soft_close_first: false },
})

export const template = (over: Partial<Template> = {}): Template => ({ id: 't-1', code: 'REVIEW_ACCRUALS', title: 'Tinjau akrual', description: 'Periksa akrual akhir bulan', required: true, sort_order: 1, status: 'ACTIVE', ...over })

// ------------------------------------------------------------------------------------------ year end

export const yearSummary = (over: Partial<YearEndSummary> = {}): YearEndSummary => ({
  id: 'fy-1', code: 'FY2026', name: 'TF 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN', closing_date: '2026-12-31', last_period: { id: 'p-12', code: '2026-12', status: 'OPEN' },
  year_end: { status: 'NOT_RUN', run_no: null, journal_entry_id: null, journal_number: null, run_at: null }, can_run: true, blockers: [], ...over,
})

export const run = (over: Partial<YearEndRun> = {}): YearEndRun => ({
  id: 'r-1', fiscal_year_id: 'fy-1', run_no: 1, status: 'POSTED', closing_date: '2026-12-31', journal_entry_id: 'j-ye', journal_number: 'JV-2026-0099', reversal_journal_id: null, reversal_journal_number: null,
  retained_earnings_account: { id: 'a-3200', code: '3200', name: 'Laba Ditahan' }, closed_account_count: 3, net_result: '1500000.0000', run_by: 'u-1', run_at: '2026-12-31T10:00:00Z',
  reversed_by: null, reversed_at: null, reverse_reason: null, ...over,
})

export const yearShow = (over: Partial<YearEndShow> = {}): YearEndShow => ({ ...yearSummary(), runs: [], ...over })

export const previewAccount = (over: Partial<PreviewAccount> = {}): PreviewAccount => ({
  account_id: 'a-4100', code: '4100', name: 'Pendapatan Jasa', account_type: 'REVENUE', status: 'ACTIVE', branch_code: null, business_unit_code: null, balance: '-5000000.0000', closes_with: 'DEBIT', amount: '5000000.0000', ...over,
})

export const preview = (over: Partial<YearEndPreview> = {}): YearEndPreview => ({
  fiscal_year: { id: 'fy-1', code: 'FY2026', name: 'TF 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN' }, closing_date: '2026-12-31', last_period: { id: 'p-12', code: '2026-12', status: 'OPEN' },
  posting_rule: { code: 'YEAR-END-CLOSING', version: 1, effective_from: '2026-01-01' }, retained_earnings_account: { id: 'a-3200', code: '3200', name: 'Laba Ditahan' }, can_run: true, blockers: [],
  totals: { revenue: '5000000.0000', expense: '3500000.0000', profit: '1500000.0000', loss: '0.0000', net_result: '1500000.0000', result: 'PROFIT' },
  journal: { balanced: true, total_debit: '5000000.0000', total_credit: '5000000.0000', line_count: 3, error: null },
  groups: [{ branch_id: null, branch_code: null, business_unit_id: null, business_unit_code: null, revenue: '5000000.0000', expense: '3500000.0000', result: '1500000.0000' }],
  accounts: [previewAccount(), previewAccount({ account_id: 'a-6100', code: '6100', name: 'Beban Gaji', account_type: 'EXPENSE', balance: '3500000.0000', closes_with: 'CREDIT', amount: '3500000.0000' })], ...over,
})

export const ruleStatus = (over: Partial<RuleStatus> = {}): RuleStatus => ({
  events: [{ event_type: 'YEAR_END_CLOSING', name: 'Penutupan akhir tahun', ready: true, rule_code: 'YEAR-END-CLOSING', effective_from: '2026-01-01' }],
  retained_earnings: { mapped: true, account: { id: 'a-3200', code: '3200', name: 'Laba Ditahan' }, problem: null }, unmapped_roles: [], ...over,
})

export { page }
