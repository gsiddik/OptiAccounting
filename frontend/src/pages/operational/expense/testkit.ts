import type { Account } from '../../../lib/accounting'
import { setToken } from '../../../lib/api'
import { mockApi } from '../../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../../test/render'

// Shared fixtures and boot helper of the expense and cash/bank page tests (imported by expense*.test.tsx and cash*.test.tsx only).

export type Routes = Parameters<typeof mockApi>[0]
export type Mode = 'FULL' | 'READ_ONLY' | 'NONE'

const MODULES = ['ACCOUNTING_CORE', 'ACCOUNTING_AP', 'ACCOUNTING_EXPENSE', 'ACCOUNTING_CASH_BANK']
const FEATURES = { EXPENSE: true, CASH_BANK_ACCOUNT: true, PAYMENT: true, RECEIPT: true, VENDOR_INVOICE: true, BANK_RECONCILIATION: true }

/**
 * Sign in as a tenant user with the given permissions. `mode` applies to every accounting module (a READ_ONLY subscription);
 * `modes` overrides single modules, e.g. `{ ACCOUNTING_AP: 'NONE' }`. Returns the recorded requests.
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

export const account = (id: string, code: string, name: string, over: Partial<Account> = {}): Account => ({
  id, code, name, description: null, parent_id: null, account_type: 'ASSET', normal_balance: 'DEBIT', is_postable: true, is_control: false, currency: null, status: 'ACTIVE', ...over,
})

export const noDimensions = { branches: [], business_units: [], cost_centers: [] }
