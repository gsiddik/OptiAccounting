import type { DocSod, DocTransition, Ref } from '../../lib/operational'

// Shapes of the budget API (backend: Budget\Services\BudgetService, BudgetVsActualService). Amounts are strings and are only formatted.
// A budget is planning data: nothing here ever becomes a journal.

export type BudgetStatus = 'DRAFT' | 'ACTIVE' | 'CLOSED' | 'CANCELLED'
export type VersionStatus = 'DRAFT' | 'SUBMITTED' | 'APPROVED' | 'REJECTED' | 'ACTIVE' | 'SUPERSEDED' | 'CANCELLED'
export const BUDGET_STATUSES: BudgetStatus[] = ['DRAFT', 'ACTIVE', 'CLOSED', 'CANCELLED']

export type FiscalYearRef = { id: string; code: string; name: string; start_date: string; end_date: string }

export type VersionRef = {
  id: string
  budget_id: string
  version_number: number
  label: string
  status: VersionStatus
  effective_from: string | null
  effective_until: string | null
}

export type Budget = {
  id: string
  code: string
  name: string
  description: string | null
  fiscal_year_id: string
  currency: string
  responsible_user_id: string | null
  status: BudgetStatus
  activated_at: string | null
  closed_at: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  fiscal_year?: FiscalYearRef | null
  responsible?: { id: string; name: string } | null
  creator?: { id: string; name: string } | null
  versions?: VersionRef[]
  transitions?: DocTransition[]
}

export type PeriodRef = { id: string; code: string; name: string; number: number; start_date: string; end_date: string }
export type AccountRef = { id: string; code: string; name: string; account_type: string; normal_balance: string; is_postable: boolean }

export type BudgetLine = {
  id: string
  account_id: string
  accounting_period_id: string
  branch_id: string | null
  business_unit_id: string | null
  cost_center_id: string | null
  amount: string
  description: string | null
  account?: AccountRef
  period?: PeriodRef
  branch?: Ref | null
  business_unit?: Ref | null
  cost_center?: Ref | null
}

export type BudgetVersion = VersionRef & {
  description: string | null
  base_version_id: string | null
  reject_reason: string | null
  cancel_reason: string | null
  budget?: Budget
  creator?: { id: string; name: string } | null
  transitions?: DocTransition[]
  lines?: BudgetLine[]
  /** Sum of the visible lines, computed by the server. */
  lines_total: string
  /** false when the user's data scope hides some lines: the grid is then partial and cannot replace all lines. */
  lines_complete: boolean
  sod?: DocSod
}

/** One line of a bulk replace (`PUT budget-versions/{id}/lines`). */
export type LinePayload = {
  account_id: string
  accounting_period_id: string
  amount: string
  branch_id?: string | null
  business_unit_id?: string | null
  cost_center_id?: string | null
  description?: string | null
}

export type GroupBy = 'account' | 'period' | 'branch' | 'business_unit' | 'cost_center' | 'line'

/** A dimension on a report row; every part is null for "no branch / unit / cost center". */
export type DimensionRef = { id: string | null; code: string | null; name: string | null }

export type ReportRow = {
  /** The id of the group (account, period, branch, ...); null for the group "without a branch / unit / cost center". */
  key: string | null
  code: string | null
  name: string | null
  account_type: string | null
  unbudgeted: boolean
  lines: number
  budget: string
  actual: string
  variance: string
  variance_pct: string | null
  favorable: boolean | null
  period?: { id: string; code: string } | null
  branch?: DimensionRef
  business_unit?: DimensionRef
  cost_center?: DimensionRef
  description?: string | null
}

export type BudgetVsActual = {
  budget: { id: string; code: string; name: string; currency: string; status: BudgetStatus }
  fiscal_year: { id: string; code: string }
  version: { id: string; version_number: number; label: string; status: VersionStatus; effective_from: string | null; effective_until: string | null }
  period_from: { id: string; code: string }
  period_to: { id: string; code: string }
  from: string
  to: string
  group_by: GroupBy
  rows: ReportRow[]
  totals: { budget: string; actual: string; variance: string; variance_pct: string | null; favorable: boolean | null; unbudgeted_actual: string }
  /** false when the data scope hides part of the budget or actuals. */
  complete: boolean
  basis?: string
}
