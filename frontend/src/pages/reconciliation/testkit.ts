import { configure } from '@testing-library/react'
import { setToken } from '../../lib/api'
import { mockApi, page } from '../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../test/render'
import type { ReconComponent, ReconException, ReconOverview, ReconResult, ReconRun } from './types'

// Shared fixtures and boot helper of the reconciliation page tests (imported by the *.test.tsx files in this folder only).

// The first render of a file loads the whole app; give async queries room on a busy machine instead of flaking at the 1 s default.
configure({ asyncUtilTimeout: 4000 })

export type Routes = Parameters<typeof mockApi>[0]
export type Mode = 'FULL' | 'READ_ONLY' | 'NONE'

export const RC = '/app/accounting'
const MODULES = ['ACCOUNTING_CORE', 'ACCOUNTING_REPORTING', 'ACCOUNTING_AP', 'ACCOUNTING_AR']
const FEATURES = { RECONCILIATION: true }

export const view = ['accounting.reconciliation.view']
export const run = [...view, 'accounting.reconciliation.run']
export const manage = [...view, 'accounting.reconciliation.exception.manage']

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

export const component = (over: Partial<ReconComponent> = {}): ReconComponent => ({
  code: 'SUBLEDGER_VS_GL', label: 'Accounts payable subledger against the control accounts', source_balance: '700000.0000', gl_balance: '705000.0000', difference: '-5000.0000',
  status: 'MISMATCH', informational: false, ...over,
})

export const control = (over: Partial<ReconComponent> = {}): ReconComponent => component({
  code: 'CONTROL_ACCOUNT', label: 'Control account 2110 Utang Usaha', source_balance: null, gl_balance: '705000.0000', difference: null, status: 'INFORMATIONAL', informational: true,
  reference: { code: '2110', name: 'Utang Usaha' }, ...over,
})

export const result = (over: Partial<ReconResult> = {}): ReconResult => ({
  type: 'AP_GL', as_of: '2026-10-08', status: 'MISMATCH', source_balance: '700000.0000', gl_balance: '705000.0000', difference: '-5000.0000', tolerance: '0.0000', complete: true,
  components: [component(), control()], notes: ['Compared: open AP documents as of the date against the posted ledger lines.'], ...over,
})

export const matched = (type: ReconResult['type'] = 'AR_GL', over: Partial<ReconResult> = {}): ReconResult =>
  result({ type, status: 'MATCHED', source_balance: '250000.0000', gl_balance: '250000.0000', difference: '0.0000', components: [component({ source_balance: '250000.0000', gl_balance: '250000.0000', difference: '0.0000', status: 'MATCHED' })], notes: [], ...over })

export const notApplicable = (type: ReconResult['type'] = 'TAX_GL'): ReconResult =>
  result({ type, status: 'NOT_APPLICABLE', source_balance: null, gl_balance: null, difference: null, components: [], notes: ['No tax transaction has been posted by this date.'] })

export const overview = (data: ReconResult[] = [result(), matched(), notApplicable()], over: Partial<ReconOverview> = {}): ReconOverview => ({
  as_of: '2026-10-08', data, complete: data.every((r) => r.complete), ...over,
})

export const exception = (over: Partial<ReconException> = {}): ReconException => ({
  id: 'ex-1', type: 'AP_GL', reconciliation_type: 'AP_GL', as_of_date: '2026-10-08', accounting_period_id: null, status: 'OPEN', calculated_status: 'MISMATCH',
  source_balance: '700000.0000', gl_balance: '705000.0000', difference: '-5000.0000', owner_id: null, notes: null, explained_by: null, explained_at: null, resolved_by: null,
  resolved_at: null, resolution_note: null, last_run_id: 'run-1', updated_at: '2026-10-08T03:00:00Z', period: null, owner: null, ...over,
})

export const storedRun = (over: Partial<ReconRun> = {}): ReconRun => ({
  id: 'run-1', type: 'AP_GL', reconciliation_type: 'AP_GL', as_of_date: '2026-10-08', accounting_period_id: null, status: 'MISMATCH', source_balance: '700000.0000', gl_balance: '705000.0000',
  difference: '-5000.0000', tolerance: '0.0000', scope_complete: true, purpose: 'MANUAL', components: [component()], run_at: '2026-10-08T04:30:00Z', period: null, ...over,
})

export { page }
