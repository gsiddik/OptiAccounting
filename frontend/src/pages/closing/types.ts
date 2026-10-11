import type { Page } from '../../lib/operational'

// Shapes of the closing API (backend: Closing\Services\{ReadinessResult, ClosingOverviewService, ChecklistService, ClosingHistory, ClosingPolicyService, YearEndClosingService}).
// Every status, count, flag and amount (decimal strings such as "1500000.0000") was decided by the backend; the UI only shows it. Nothing here is summed or compared in React.

export type PeriodState = 'FUTURE' | 'OPEN' | 'SOFT_CLOSED' | 'CLOSED'
export type Severity = 'BLOCK' | 'WARN' | 'OFF'
export type CheckResult = 'PASS' | 'FAIL' | 'WARN' | 'SKIPPED'
export type ReadinessResult = 'READY' | 'NOT_READY'
export type ItemStatus = 'PENDING' | 'COMPLETED' | 'WAIVED'
export type EventType = 'PRE_CLOSE' | 'SOFT_CLOSE' | 'HARD_CLOSE' | 'REOPEN' | 'YEAR_END_RUN' | 'YEAR_END_REVERSE'
export type YearEndState = 'NOT_RUN' | 'POSTED' | 'REVERSED'

export const EVENT_TYPES: EventType[] = ['PRE_CLOSE', 'SOFT_CLOSE', 'HARD_CLOSE', 'REOPEN', 'YEAR_END_RUN', 'YEAR_END_REVERSE']

// ------------------------------------------------------------------------------------------ readiness

/** One close check. `details` depends on the code (counts, samples, the reconciliation result ...): see CheckDetails. */
export type CloseCheck = { code: string; title: string; severity: Severity; result: CheckResult; message: string; details: Record<string, unknown> }

export type Readiness = {
  period_id: string
  period_code: string
  target: 'SOFT' | 'HARD'
  result: ReadinessResult
  blocking_count: number
  warning_count: number
  evaluated_at: string
  checks: CloseCheck[]
}

export type PreCloseResponse = Readiness & { event_id: string }

/** What a soft close, hard close or reopen answers: the period as it is now. */
export type PeriodRow = { id: string; code: string; status: PeriodState; closed_at: string | null; closed_by: string | null }

// ------------------------------------------------------------------------------------------ overview

export type LastEvent = { event_type: EventType; from_status: string | null; to_status: string | null; reason: string | null; actor_id: string | null; actor_name: string | null; at: string }

export type OverviewPeriod = {
  id: string
  code: string
  name: string
  fiscal_year_id: string
  fiscal_year_code: string | null
  start_date: string
  end_date: string
  status: PeriodState
  closed_at: string | null
  closed_by: string | null
  last_event: LastEvent | null
  last_pre_close_at: string | null
  /** Null while the period is not a candidate (not ended yet, not opened or already closed). */
  readiness: { result: ReadinessResult; blocking: string[]; warnings: string[] } | null
}

export type OverviewYear = {
  id: string
  code: string
  name: string
  start_date: string
  end_date: string
  status: string
  year_end: { status: YearEndState; run_no: number | null; journal_entry_id: string | null; run_at: string | null }
}

export type ClosingOverviewData = { business_date: string; periods: OverviewPeriod[]; fiscal_years: OverviewYear[] }

// ------------------------------------------------------------------------------------------ checklist

export type ChecklistItem = {
  id: string | null
  code: string
  title: string
  required: boolean
  sort_order: number
  status: ItemStatus
  /** False while the period has no row for the item yet (it is created at the first action). */
  generated: boolean
  notes: string | null
  completed_by: string | null
  completed_at: string | null
  waived_by: string | null
  waived_at: string | null
  waive_reason: string | null
}

export type Checklist = {
  period: { id: string; code: string; status: PeriodState }
  system_checks: (CloseCheck & { settable: boolean })[]
  manual_items: ChecklistItem[]
  summary: { required: number; pending_required: number; completed: number; waived: number }
}

// ------------------------------------------------------------------------------------------ history

export type ClosingEvent = {
  id: string
  event_type: EventType
  accounting_period_id: string | null
  period_code: string | null
  fiscal_year_id: string | null
  fiscal_year_code: string | null
  from_status: string | null
  to_status: string | null
  readiness: ReadinessResult | null
  readiness_checks: CloseCheck[] | null
  reason: string | null
  reference: string | null
  actor_id: string | null
  actor_name: string | null
  journal_entry_id: string | null
  created_at: string
}

export type HistoryPage = Page<ClosingEvent>

// ------------------------------------------------------------------------------------------ policy

export type PolicyCheck = { check_code: string; title: string; default_severity: Severity; severity: Severity; locked: boolean; module: string | null }

export type PolicySettings = {
  reconciliation_tolerance: string
  reopen_max_periods_back: number | null
  reopen_requires_later_open: boolean
  separate_closer_and_reopener: boolean
  require_soft_close_first: boolean
}

export type Policy = { checks: PolicyCheck[]; settings: PolicySettings }

export type Template = { id: string; code: string; title: string; description: string | null; required: boolean; sort_order: number; status: 'ACTIVE' | 'INACTIVE' }

// ------------------------------------------------------------------------------------------ year end

export type AccountRef = { id: string; code: string; name: string }
export type Blocker = { code: string; message: string; details?: Record<string, unknown> }

export type YearEndSummary = {
  id: string
  code: string
  name: string
  start_date: string
  end_date: string
  status: string
  closing_date: string
  last_period: { id: string; code: string; status: PeriodState } | null
  year_end: { status: YearEndState; run_no: number | null; journal_entry_id: string | null; journal_number: string | null; run_at: string | null }
  can_run: boolean
  blockers: string[]
}

export type YearEndRun = {
  id: string
  fiscal_year_id: string
  run_no: number
  status: 'POSTED' | 'REVERSED'
  closing_date: string
  journal_entry_id: string
  journal_number: string | null
  reversal_journal_id: string | null
  reversal_journal_number: string | null
  retained_earnings_account: AccountRef | null
  closed_account_count: number
  net_result: string
  run_by: string | null
  run_at: string | null
  reversed_by: string | null
  reversed_at: string | null
  reverse_reason: string | null
}

export type YearEndShow = YearEndSummary & { runs: YearEndRun[] }

export type PreviewTotals = { revenue: string; expense: string; profit: string; loss: string; net_result: string; result: 'PROFIT' | 'LOSS' | 'BREAK_EVEN' }

export type PreviewGroup = {
  branch_id: string | null
  branch_code: string | null
  business_unit_id: string | null
  business_unit_code: string | null
  revenue: string
  expense: string
  result: string
}

export type PreviewAccount = {
  account_id: string
  code: string
  name: string
  account_type: string
  status: string
  branch_code: string | null
  business_unit_code: string | null
  balance: string
  closes_with: 'DEBIT' | 'CREDIT'
  amount: string
}

export type PreviewJournal = { balanced: boolean; total_debit: string | null; total_credit: string | null; line_count: number; error: string | null }

export type YearEndPreview = {
  fiscal_year: { id: string; code: string; name: string; start_date: string; end_date: string; status: string }
  closing_date: string
  last_period: { id: string; code: string; status: PeriodState } | null
  posting_rule: { code: string; version: number; effective_from: string } | null
  retained_earnings_account: AccountRef | null
  can_run: boolean
  blockers: Blocker[]
  totals: PreviewTotals
  journal: PreviewJournal | null
  groups: PreviewGroup[]
  accounts: PreviewAccount[]
}

export type YearEndList = { fiscal_years: YearEndSummary[] }

export type RuleStatus = {
  events: { event_type: string; name: string; ready: boolean; rule_code: string | null; effective_from: string | null }[]
  retained_earnings: { mapped: boolean; account: AccountRef | null; problem: string | null }
  unmapped_roles: string[]
}

export type RuleDefaultsResult = { created: string[]; skipped: { event_type: string; reason: string }[] }
