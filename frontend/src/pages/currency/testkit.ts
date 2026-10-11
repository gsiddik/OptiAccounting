import { setToken } from '../../lib/api'
import { mockApi } from '../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../test/render'
import type { Currency, ExchangeRate } from './types'

// Shared fixtures and boot helper of the multi-currency page tests.

export type Routes = Parameters<typeof mockApi>[0]

const MODULES = ['ACCOUNTING_CORE', 'ACCOUNTING_MULTI_CURRENCY']
const FEATURES = { EXCHANGE_RATE: true }

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

export const currency = (over: Partial<Currency> = {}): Currency => ({ id: 'cur-usd', code: 'USD', name: 'Dolar Amerika Serikat', symbol: '$', decimal_places: 2, status: 'ACTIVE', in_use: false, ...over })

export const exchangeRate = (over: Partial<ExchangeRate> = {}): ExchangeRate => ({
  id: 'fx-1', from_currency: 'USD', to_currency: 'IDR', rate: '16250.5000000000', effective_date: '2026-10-05', rate_type: 'MANUAL', source: 'Bank Indonesia', notes: null, status: 'ACTIVE', ...over,
})

export const profile = { id: 'p-1', framework: 'SAK_EMKM', functional_currency: 'IDR', currency_scale: 2, status: 'READY', approval_required: true, sod_creator_not_approver: true, sod_creator_not_poster: false, sod_approver_not_poster: false, cutover_date: null }
