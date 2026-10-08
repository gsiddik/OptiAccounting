import { useMemo } from 'react'
import { api } from '../../lib/api'
import type { Account, FiscalYear, Period } from '../../lib/accounting'
import { useCapabilities } from '../../lib/capabilities'
import { todayIn } from '../../lib/format'
import { useResource } from '../../lib/hooks'

// Data hooks and small helpers shared by the accounting pages. Nothing here computes money: amounts arrive from the
// API as strings and are only formatted for display.

export type DimensionCatalog = {
  branches: { id: string; code: string; name: string }[]
  business_units: { id: string; code: string; name: string; branch_id: string | null }[]
  cost_centers: { id: string; code: string; name: string; branch_id: string | null; business_unit_id: string | null }[]
}

const EMPTY_CATALOG: DimensionCatalog = { branches: [], business_units: [], cost_centers: [] }

/** Branches, business units and cost centers the signed-in user may use. A refusal (no permission) yields an empty catalog. */
export function useDimensions() {
  const resource = useResource<DimensionCatalog>(async () => {
    try {
      return (await api.get<DimensionCatalog>('/app/accounting/dimensions')).data
    } catch {
      return EMPTY_CATALOG
    }
  }, [])
  return { catalog: resource.data ?? EMPTY_CATALOG, loading: resource.loading }
}

/** The whole chart of accounts as a flat list ordered by code. */
export function useAccounts() {
  const resource = useResource(async () => (await api.get<{ data: Account[] }>('/app/accounting/accounts')).data.data, [])
  const byId = useMemo(() => new Map((resource.data ?? []).map((a) => [a.id, a])), [resource.data])
  return { ...resource, accounts: resource.data ?? [], byId }
}

/** Fiscal years with their periods, newest first. */
export function useCalendar() {
  const resource = useResource(async () => (await api.get<{ data: FiscalYear[] }>('/app/accounting/fiscal-years')).data.data, [])
  const periods = useMemo<Period[]>(() => (resource.data ?? []).flatMap((y) => y.periods ?? []).sort((a, b) => b.start_date.localeCompare(a.start_date)), [resource.data])
  return { ...resource, years: resource.data ?? [], periods }
}

export const accountLabel = (a: Pick<Account, 'code' | 'name'>) => `${a.code} · ${a.name}`

/** The first day of the current month and today, as the default report window. */
export function defaultRange(today: string): { from: string; to: string } {
  return { from: `${today.slice(0, 8)}01`, to: today }
}

export type Range = { period_id: string; from: string; to: string }

/** Query parameters for a report window: a period, or an explicit date range. */
export function rangeParams(r: Range): Record<string, string> {
  return r.period_id ? { period_id: r.period_id } : { ...(r.from && { from: r.from }), ...(r.to && { to: r.to }) }
}

/** State for a report window that starts on the first of the tenant's current month. */
export function useDefaultRange(): Range {
  const { tenant } = useCapabilities()
  const today = tenant?.business_date ?? todayIn()
  return useMemo(() => ({ period_id: '', ...defaultRange(today) }), [today])
}
