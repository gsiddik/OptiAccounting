import { useMemo } from 'react'
import type { FiscalYear, Period } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useResource } from '../../lib/hooks'
import { API, useModuleAccess } from '../../lib/operational'
import { reconSlug } from './labels'

export const REPORTING = 'ACCOUNTING_REPORTING'

const BASE = '/app/akuntansi'

export const PATHS = {
  overview: `${BASE}/penutupan`,
  period: (id: string) => `${BASE}/penutupan/periode/${id}`,
  history: `${BASE}/penutupan/riwayat`,
  policy: `${BASE}/penutupan/kebijakan`,
  yearEnd: `${BASE}/penutupan/akhir-tahun`,
  yearEndDetail: (id: string) => `${BASE}/penutupan/akhir-tahun/${id}`,
  journal: (id: string) => `${BASE}/jurnal/${id}`,
  asset: (id: string) => `${BASE}/aset/${id}`,
  accountMappings: `${BASE}/pemetaan-akun`,
  postingRules: `${BASE}/aturan-posting`,
  reportMapping: `${BASE}/pemetaan-laporan`,
  reconciliation: (type: string, asOf?: string) => `${BASE}/rekonsiliasi/jenis/${reconSlug(type)}${asOf ? `?as_of=${asOf}` : ''}`,
} as const

export const ENDPOINTS = {
  fiscalYears: `${API}/fiscal-years`,
  overview: `${API}/closing/overview`,
  history: `${API}/closing/history`,
  policy: `${API}/closing/policy`,
  templates: `${API}/closing/checklist-templates`,
  template: (id: string) => `${API}/closing/checklist-templates/${id}`,
  readiness: (period: string) => `${API}/closing/periods/${period}/readiness`,
  checklist: (period: string) => `${API}/closing/periods/${period}/checklist`,
  item: (period: string, code: string, action: 'complete' | 'waive' | 'reset') => `${API}/closing/periods/${period}/checklist/${code}/${action}`,
  action: (period: string, action: 'pre-close' | 'soft-close' | 'hard-close' | 'reopen') => `${API}/closing/periods/${period}/${action}`,
  yearEnd: `${API}/year-end`,
  ruleStatus: `${API}/year-end/rule-status`,
  ruleDefaults: `${API}/year-end/rule-defaults`,
  yearEndOne: (year: string) => `${API}/year-end/${year}`,
  preview: (year: string) => `${API}/year-end/${year}/preview`,
  run: (year: string) => `${API}/year-end/${year}/run`,
  reverse: (year: string) => `${API}/year-end/${year}/reverse`,
} as const

/**
 * What the signed-in user may do on the closing pages. Every action changes the ledger's state, so it needs the permission AND a writable
 * reporting module (a READ_ONLY subscription keeps the views). Cosmetic only: the API enforces every one of them again.
 */
export function useClosingAccess() {
  const access = useModuleAccess(REPORTING)
  const change = access.canChange
  return {
    ...access,
    canPreClose: change('accounting.period.preclose'),
    canSoftClose: change('accounting.period.soft_close'),
    canHardClose: change('accounting.period.hard_close'),
    canReopen: change('accounting.period.reopen'),
    canChecklist: change('accounting.closing.checklist.manage'),
    canPolicy: change('accounting.closing.policy.manage'),
    canRunYearEnd: change('accounting.year_end.run'),
    canRules: change('accounting.posting_rule.manage'),
    canViewYearEnd: access.can('accounting.year_end.view'),
  }
}

/** The fiscal years with their periods: the period's name, dates and state (the closing API has no single-period read) and the history filters. */
export function useCalendar() {
  const resource = useResource(async () => (await api.get<{ data: FiscalYear[] }>(ENDPOINTS.fiscalYears)).data.data, [])
  const periods = useMemo<Period[]>(() => (resource.data ?? []).flatMap((y) => y.periods ?? []).sort((a, b) => b.start_date.localeCompare(a.start_date)), [resource.data])
  return { ...resource, years: resource.data ?? [], periods }
}
