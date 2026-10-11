import { useMemo } from 'react'
import type { FiscalYear, Period } from '../../../lib/accounting'
import { api } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { todayIn } from '../../../lib/format'
import { useResource } from '../../../lib/hooks'
import { API } from '../../../lib/operational'
import { STATEMENT_META } from './labels'
import type { CompareMode, ComparativeReport, DefinitionOption, Drilldown, StatementKind, StatementReport } from './types'

export const PATHS = {
  ledger: '/app/akuntansi/buku-besar',
  definitions: '/app/akuntansi/pemetaan-laporan',
} as const

export const ENDPOINTS = {
  statement: (kind: StatementKind) => `${API}/reports/${STATEMENT_META[kind].slug}`,
  export: (kind: StatementKind) => `${API}/reports/${STATEMENT_META[kind].slug}/export`,
  comparative: `${API}/reports/comparative`,
  comparativeExport: `${API}/reports/comparative/export`,
  drilldown: `${API}/reports/drilldown`,
  definitions: `${API}/report-definitions`,
  fiscalYears: `${API}/fiscal-years`,
} as const

export type Params = Record<string, string | number>

/** Everything a person can choose on a statement page. Empty means "let the server decide" (business date, current fiscal year to date). */
export type FilterState = {
  as_of: string
  period_id: string
  from: string
  to: string
  branch_id: string
  business_unit_id: string
  cost_center_id: string
  definition_id: string
  include_zero: boolean
}

export const emptyFilters = (asOf = ''): FilterState => ({
  as_of: asOf, period_id: '', from: '', to: '', branch_id: '', business_unit_id: '', cost_center_id: '', definition_id: '', include_zero: false,
})

/**
 * Query parameters of a statement: a balance sheet is dated by `as_of`, a profit and loss or cash flow by a period or a range (the API refuses the
 * two mixed). Empty values are dropped and the zero-lines flag is sent only when set, as `1` (Laravel's boolean rule rejects the text "true").
 */
export function statementParams(kind: StatementKind, f: FilterState): Params {
  const out: Params = {}
  if (kind === 'BALANCE_SHEET') {
    if (f.as_of) out.as_of = f.as_of
  } else if (f.period_id) {
    out.period_id = f.period_id
  } else {
    if (f.from) out.from = f.from
    if (f.to) out.to = f.to
  }
  for (const key of ['branch_id', 'business_unit_id', 'cost_center_id', 'definition_id'] as const) if (f[key]) out[key] = f[key]
  if (f.include_zero) out.include_zero = 1
  return out
}

/** The drill-down of a line explains the statement exactly as shown: the dates, definition version and filters the report itself echoed, not the controls as they are now. */
export function drilldownParams(report: StatementReport): Params {
  const out: Params = { statement: report.statement, definition_id: report.definition.id }
  if (report.statement === 'BALANCE_SHEET') out.as_of = report.as_of
  else Object.assign(out, report.range)
  for (const key of ['branch_id', 'business_unit_id', 'cost_center_id'] as const) if (report.filters[key]) out[key] = report.filters[key]
  if (report.filters.include_zero) out.include_zero = 1
  return out
}

export type ComparativeChoice = { statement: StatementKind; mode: CompareMode; budget_id: string }

export const comparativeParams = (c: ComparativeChoice, f: FilterState): Params => ({
  statement: c.statement, mode: c.mode, ...(c.mode === 'BUDGET' && c.budget_id ? { budget_id: c.budget_id } : {}), ...statementParams(c.statement, f),
})

/** True for the zero amounts of the API ("0.0000", "-0.0000"): a presentation test on the text, not arithmetic. */
export const isZeroAmount = (value: string | null | undefined): boolean => value == null || /^-?0+(\.0+)?$/.test(value)

/** The general ledger link of a drill-down account: the query the API built (account, dates, dimensions), as page parameters. */
export const ledgerLink = (query: Record<string, string>): string => `${PATHS.ledger}?${new URLSearchParams(query).toString()}`

// ------------------------------------------------------------------------------------------------ hooks

export const useCompanyName = (): string => useCapabilities().tenant?.tenant.name ?? '—'

/** The business date of the organisation: the default "per tanggal" of a balance sheet. */
export const useBusinessDate = (): string => useCapabilities().tenant?.business_date ?? todayIn()

export function useStatement(kind: StatementKind, params: Params) {
  return useResource(async () => (await api.get<StatementReport>(ENDPOINTS.statement(kind), { params })).data, [kind, JSON.stringify(params)])
}

/** The comparative report; `params` null (a budget comparison with no budget chosen yet) requests nothing. */
export function useComparative(params: Params | null) {
  return useResource(async () => (params ? (await api.get<ComparativeReport>(ENDPOINTS.comparative, { params })).data : null), [JSON.stringify(params)])
}

/** The accounts behind one line. `line` null means no drill-down is open (nothing is requested). */
export function useDrilldown(line: string | null, params: Params) {
  return useResource(async () => (line ? (await api.get<Drilldown>(ENDPOINTS.drilldown, { params: { ...params, line } })).data : null), [line, JSON.stringify(params)])
}

/** Report definition versions of one statement for the version selector. Needs `accounting.report_definition.view`; without it only "automatic" is offered. */
export function useDefinitionOptions(kind: StatementKind) {
  const { can } = useCapabilities()
  const enabled = can('accounting.report_definition.view')
  const resource = useResource(async () => {
    if (!enabled) return []
    try {
      return (await api.get<{ data: DefinitionOption[] }>(ENDPOINTS.definitions, { params: { statement_type: kind } })).data.data
    } catch {
      return []
    }
  }, [enabled, kind])
  return { definitions: resource.data ?? [] }
}

/** Accounting periods for the period selector. Needs `accounting.period.view`; without it there is simply no period choice. */
export function useStatementPeriods() {
  const { can } = useCapabilities()
  const enabled = can('accounting.period.view')
  const resource = useResource(async () => {
    if (!enabled) return []
    try {
      return (await api.get<{ data: FiscalYear[] }>(ENDPOINTS.fiscalYears)).data.data
    } catch {
      return []
    }
  }, [enabled])
  const periods = useMemo<Period[]>(() => (resource.data ?? []).flatMap((y) => y.periods ?? []).sort((a, b) => b.start_date.localeCompare(a.start_date)), [resource.data])
  return { periods }
}
