import { useMemo } from 'react'
import type { Period } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useResource } from '../../lib/hooks'
import { API, type Page } from '../../lib/operational'
import { formatDate } from '../../lib/format'
import { useCalendar } from '../accounting/data'
import type { Budget, VersionRef } from './types'

/** Budgets for a select box (up to 100, newest first). */
export function useBudgetOptions() {
  const r = useResource(async () => (await api.get<Page<Budget>>(`${API}/budgets`, { params: { per_page: 100 } })).data.data, [])
  return { ...r, budgets: r.data ?? [] }
}

/**
 * The periods of one fiscal year in calendar order. The list needs the permission to view the fiscal calendar; without it the year and its
 * periods stay empty and `unavailable` says why (the API has no budget-specific period catalog).
 */
export function useFiscalPeriods(fiscalYearId: string | null | undefined) {
  const { years, error, loading } = useCalendar()
  const periods = useMemo<Period[]>(() => (years.find((y) => y.id === fiscalYearId)?.periods ?? []).slice().sort((a, b) => a.number - b.number), [years, fiscalYearId])
  return { periods, loading, unavailable: error != null }
}

/** Window shown as "1 Jan 2026 – 31 Des 2026", or an open end when the version is still in force. */
export const windowText = (v: Pick<VersionRef, 'effective_from' | 'effective_until'>): string =>
  v.effective_from ? `${formatDate(v.effective_from)} – ${v.effective_until ? formatDate(v.effective_until) : 'sekarang'}` : '—'
