import { useMemo } from 'react'
import { useCashBankAccounts, useExpenseCategories, useModuleAccess } from '../../lib/operational'
import type { Connection, ConnectionSettings } from './types'

export const INTEGRATION = 'ACCOUNTING_INTEGRATION'

const BASE = '/app/integrasi'
const ACCOUNTING = '/app/akuntansi'

export const PATHS = {
  connections: BASE,
  connection: (id: string) => `${BASE}/koneksi/${id}`,
  events: `${BASE}/kejadian`,
  /** The event inbox narrowed to one connection and, optionally, one status. */
  connectionEvents: (id: string, status?: string) => `${BASE}/kejadian?connection_id=${id}${status ? `&status=${status}` : ''}`,
  event: (id: string) => `${BASE}/kejadian/${id}`,
  journal: (id: string) => `${ACCOUNTING}/jurnal/${id}`,
  apInvoice: (id: string) => `${ACCOUNTING}/faktur-vendor/${id}`,
  apPayment: (id: string) => `${ACCOUNTING}/pembayaran-vendor/${id}`,
} as const

/** Integration API (`/api/v1/app/integration/*`). */
const API = '/app/integration'
export const ENDPOINTS = {
  connections: `${API}/connections`,
  connection: (id: string) => `${API}/connections/${id}`,
  summary: (id: string) => `${API}/connections/${id}/summary`,
  action: (id: string, action: 'activate' | 'pause' | 'resume' | 'disconnect' | 'sync-now') => `${API}/connections/${id}/${action}`,
  events: `${API}/events`,
  event: (id: string) => `${API}/events/${id}`,
  retry: (id: string) => `${API}/events/${id}/retry`,
} as const

/** Source systems offered when creating a connection. The API takes any lowercase identifier; `optifleet` has an adapter and settings of its own. */
export const OPTIFLEET = 'optifleet'

/**
 * What the signed-in user may do on the integration pages. Managing and retrying change what is posted, so they need the permission AND a writable
 * integration module (a READ_ONLY subscription keeps the views). Cosmetic only: the API enforces each of them again.
 */
export function useIntegrationAccess() {
  const access = useModuleAccess(INTEGRATION)
  return { ...access, canManage: access.canChange('accounting.integration.manage'), canRetry: access.canChange('accounting.integration.retry') }
}

/** The API returns an empty settings object as `[]`. */
export const settingsOf = (c: Connection): ConnectionSettings => (c.settings && !Array.isArray(c.settings) ? c.settings : {})

/** The tenant's cash/bank accounts and expense categories an OptiFleet connection can be pointed at (active ones only). */
export function useSettingOptions() {
  const cash = useCashBankAccounts('ACTIVE')
  const expense = useExpenseCategories()
  const categories = useMemo(() => expense.categories.filter((c) => c.status === 'ACTIVE'), [expense.categories])
  return {
    accounts: cash.accounts,
    categories,
    loading: cash.loading || expense.loading,
    error: cash.error ?? expense.error,
    reload: () => {
      cash.reload()
      expense.reload()
    },
  }
}
