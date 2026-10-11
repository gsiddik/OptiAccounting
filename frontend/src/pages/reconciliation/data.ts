import { useMemo } from 'react'
import type { FiscalYear, Period } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { useResource } from '../../lib/hooks'
import { API, useModuleAccess } from '../../lib/operational'
import type { Member, Paginated } from '../../lib/types'
import { slugOf } from './labels'
import type { Cutoff, ReconType } from './types'

export const REPORTING = 'ACCOUNTING_REPORTING'

export const PATHS = {
  overview: '/app/akuntansi/rekonsiliasi',
  exceptions: '/app/akuntansi/rekonsiliasi/pengecualian',
  detail: (type: ReconType | string) => `/app/akuntansi/rekonsiliasi/jenis/${slugOf(type)}`,
} as const

export const ENDPOINTS = {
  overview: `${API}/reconciliations`,
  one: (type: string) => `${API}/reconciliations/${slugOf(type)}`,
  run: (type: string) => `${API}/reconciliations/${slugOf(type)}/run`,
  runs: `${API}/reconciliations/runs`,
  exportAll: `${API}/reconciliations/export`,
  exportOne: (type: string) => `${API}/reconciliations/${slugOf(type)}/export`,
  exceptions: `${API}/reconciliation-exceptions`,
  exception: (id: string, action: 'assign' | 'explain' | 'resolve') => `${API}/reconciliation-exceptions/${id}/${action}`,
} as const

/**
 * What the signed-in user may do on the reconciliation pages. Running a reconciliation and working an exception change data, so they need the
 * permission AND a writable reconciliation module (a READ_ONLY subscription keeps the views). Cosmetic only: the API enforces every one of them.
 */
export function useReconAccess() {
  const access = useModuleAccess(REPORTING)
  return { ...access, canRun: access.canChange('accounting.reconciliation.run'), canManage: access.canChange('accounting.reconciliation.exception.manage') }
}

/** Query parameters of a cut-off: a period (its end date) or an explicit date. Never both: the API refuses two different cut-offs. */
export function cutoffParams(c: Cutoff): Record<string, string> {
  return c.period_id ? { period_id: c.period_id } : c.as_of ? { as_of: c.as_of } : {}
}

export const hasCutoff = (c: Cutoff): boolean => Boolean(c.period_id || c.as_of)

/** The query string that carries a cut-off to another reconciliation page. */
export function cutoffSearch(c: Pick<Cutoff, 'as_of' | 'period_id'>): string {
  const params = new URLSearchParams(cutoffParams({ as_of: c.as_of, period_id: c.period_id }))
  const text = params.toString()
  return text ? `?${text}` : ''
}

/** Accounting periods for the cut-off selector. Needs `accounting.period.view`; without it there is simply no period choice. */
export function useReconPeriods() {
  const { can } = useCapabilities()
  const enabled = can('accounting.period.view')
  const resource = useResource(async () => {
    if (!enabled) return []
    try {
      return (await api.get<{ data: FiscalYear[] }>(`${API}/fiscal-years`)).data.data
    } catch {
      return []
    }
  }, [enabled])
  const periods = useMemo<Period[]>(() => (resource.data ?? []).flatMap((y) => y.periods ?? []).sort((a, b) => b.start_date.localeCompare(a.start_date)), [resource.data])
  return { periods }
}

/** Active members an exception can be assigned to. Listing them needs `access.user.view`; without it the dialog offers only the signed-in user. */
export function useMembers(search: string) {
  const { can } = useCapabilities()
  const enabled = can('access.user.view')
  const resource = useResource(async () => {
    if (!enabled) return []
    try {
      return (await api.get<Paginated<Member>>('/app/users', { params: { status: 'ACTIVE', ...(search && { search }) } })).data.data
    } catch {
      return []
    }
  }, [enabled, search])
  return { members: resource.data ?? [], canList: enabled, loading: resource.loading }
}
