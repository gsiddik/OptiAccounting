import { configure } from '@testing-library/react'
import { setToken } from '../../lib/api'
import { mockApi, page } from '../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../test/render'
import type { Connection, ConnectionAccess, ConnectionSummary, EventDetailData, Gap, IntegrationEvent, SourceLink } from './types'

// Shared fixtures and boot helper of the integration page tests (imported by the *.test.tsx files in this folder only).

// The first render of a file loads the whole app; give async queries room on a busy machine instead of flaking at the 1 s default.
configure({ asyncUtilTimeout: 4000 })

export type Routes = Parameters<typeof mockApi>[0]
export type Mode = 'FULL' | 'READ_ONLY' | 'NONE'

export const IT = '/app/integration'
const MODULES = ['ACCOUNTING_CORE', 'ACCOUNTING_INTEGRATION']

export const viewer = ['accounting.integration.view']
export const manager = [...viewer, 'accounting.integration.manage']
export const retrier = [...viewer, 'accounting.integration.retry']
export const all = [...manager, 'accounting.integration.retry', 'accounting.journal.view', 'accounting.ap_invoice.view']

/**
 * Sign in as a tenant user with the given permissions. `mode` applies to every module (a READ_ONLY subscription); `features` overrides single features.
 * Returns the recorded requests.
 */
export function boot(permissions: string[], routes: Routes = {}, options: { mode?: 'FULL' | 'READ_ONLY'; features?: Record<string, boolean> } = {}) {
  const mode = options.mode ?? 'FULL'
  const caps = tenantCaps({ permissions, subscriptionMode: mode })
  const calls = mockApi({
    'GET /auth/me': { data: tenantMe },
    [`GET ${IT}/gaps`]: { data: page([]) },
    'GET /app/capabilities': { data: { ...caps, modules: Object.fromEntries(MODULES.map((m) => [m, mode])), features: { INTEGRATION_CONNECTION: true, ...options.features } } },
    ...routes,
  })
  setToken('t')
  return calls
}

export type Calls = ReturnType<typeof boot>
export const writes = (calls: Calls) => calls.filter((c) => c.method !== 'GET' && c.url.startsWith(IT))
export const gets = (calls: Calls, url: string) => calls.filter((c) => c.method === 'GET' && c.url === url)

export const connection = (over: Partial<Connection> = {}): Connection => ({
  id: 'c-1', name: 'OptiFleet Maju Jaya', source_system: 'optifleet', source_tenant_reference: 'fleet-co-1', status: 'ACTIVE', base_url: 'https://fleet.example.test', has_credential: true,
  cutover_policy: 'OPENING_BALANCE_ONLY', cutover_date: '2026-10-01', gap_policy: 'REQUIRES_REVIEW', pull_interval_minutes: 5, last_cursor: '41', last_pulled_at: '2026-10-10T03:00:00Z',
  last_error: null, consecutive_failures: 0, settings: { payables_cash_bank_account_id: 'cb-1', expense_category_id: 'ec-1' }, created_at: '2026-10-01T03:00:00Z', updated_at: '2026-10-10T03:00:00Z', ...over,
})

export const access = (over: Partial<ConnectionAccess> = {}): ConnectionAccess => ({
  state: 'OK', code: null, since: null, checked_at: '2026-10-10T03:00:00Z', next_attempt_at: null,
  pairing: { required: true, linked: true, verified_nexus_tenant_id: 'nx-tenant-1' }, entry: null, ...over,
})

export const gap = (over: Partial<Gap> = {}): Gap => ({
  id: 'g-1', connection_id: 'c-1', status: 'CLOSED', open_reason: 'PAUSED', policy: 'REQUIRES_REVIEW', sequence_at_open: 41, ceiling_sequence: 47, new_cutover_date: null,
  opened_at: '2026-10-08T03:00:00Z', closed_at: '2026-10-09T03:00:00Z', events: { total: 6, held: 4, processed: 1, refused: 1 }, ...over,
})

export const summary = (over: Partial<ConnectionSummary> = {}): ConnectionSummary => ({
  connection_id: 'c-1', status: 'ACTIVE', received: 10, posted: 6,
  counts: { RECEIVED: 1, VALIDATED: 0, POSTED: 6, NEEDS_REVIEW: 2, REJECTED: 0, FAILED: 0, DEAD_LETTER: 1 },
  duplicates: 3, conflicts: 1, last_cursor: '41', last_pulled_at: '2026-10-10T03:00:00Z', last_error: null, consecutive_failures: 0, ...over,
})

export const cashAccount = { id: 'cb-1', code: 'BANK-01', name: 'Bank BCA Operasional', kind: 'BANK', status: 'ACTIVE', currency: 'IDR', account_id: 'a-1120' }
export const category = { id: 'ec-1', code: 'BENGKEL', name: 'Beban perawatan bengkel', description: null, account_role: null, account_id: null, status: 'ACTIVE' }
export const optionRoutes = (): Routes => ({
  'GET /app/accounting/cash-bank-accounts': { data: page([cashAccount, { ...cashAccount, id: 'cb-2', code: 'KAS-01', name: 'Kas kecil', kind: 'CASH' }]) },
  'GET /app/accounting/expense-categories': { data: { data: [category, { ...category, id: 'ec-2', code: 'LAIN', name: 'Beban lain', status: 'INACTIVE' }] } },
})

export const event = (over: Partial<IntegrationEvent> = {}): IntegrationEvent => ({
  id: 'ev-1', connection_id: 'c-1', source_system: 'optifleet', event_id: 'evt-0001', event_type: 'optifleet.workshop_invoice.recorded', schema_version: '1.0', occurred_at: '2026-10-05T01:00:00Z',
  sequence: 7, status: 'NEEDS_REVIEW', attempts: 1, next_attempt_at: null, last_error_code: 'PERIOD_CLOSED', last_error: 'The accounting period is closed.', conflict: false, duplicate_count: 0,
  resulting_journal_id: null, received_at: '2026-10-10T03:00:00Z', processed_at: '2026-10-10T03:00:05Z', ...over,
})

export const link = (over: Partial<SourceLink> = {}): SourceLink => ({
  id: 'l-1', journal_id: 'j-1', document_type: 'ap_invoice', document_id: 'ap-1', source_system: 'optifleet', source_type: 'workshop_invoice', source_id: 'wi-1',
  source_document_number: 'WI-001', source_event_id: 'evt-0001', created_at: '2026-10-10T03:00:05Z', ...over,
})

export const detail = (over: Partial<EventDetailData> = {}): EventDetailData => ({
  ...event(),
  envelope: {
    event_id: 'evt-0001', event_type: 'optifleet.workshop_invoice.recorded', schema_version: '1.0', source_system: 'optifleet', source_tenant_reference: 'fleet-co-1', occurred_at: '2026-10-05T08:00:00+07:00',
    correlation_id: 'corr-9', source: { type: 'workshop_invoice', id: 'wi-1', number: 'WI-001' }, payload: { total_amount: '1110000.00', tax_total: '110000.00', currency: 'IDR' },
  },
  source_links: [], ...over,
})

export { page }
