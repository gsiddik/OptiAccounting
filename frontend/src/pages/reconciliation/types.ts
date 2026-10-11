import type { Page } from '../../lib/operational'

// Shapes of the reconciliation API (backend: Reconciliation\Services\ReconciliationResult / ReconciliationRunService / ReconciliationExceptionService).
// Amounts are decimal strings ("1500000.0000") that the backend computed; the UI only formats them. Nothing here is ever summed or compared in React.

export type ReconType = 'AP_GL' | 'AR_GL' | 'CASH_BANK_GL' | 'FIXED_ASSET_GL' | 'TAX_GL' | 'FX_GL'
export type ReconStatus = 'MATCHED' | 'MISMATCH' | 'INCOMPLETE' | 'NOT_APPLICABLE'
export type ComponentStatus = ReconStatus | 'INFORMATIONAL'
export type ExceptionStatus = 'OPEN' | 'EXPLAINED' | 'RESOLVED'

export const RECON_TYPES: ReconType[] = ['AP_GL', 'AR_GL', 'CASH_BANK_GL', 'FIXED_ASSET_GL', 'TAX_GL', 'FX_GL']
export const EXCEPTION_STATUSES: ExceptionStatus[] = ['OPEN', 'EXPLAINED', 'RESOLVED']

/** One line of a reconciliation: `difference = source - gl`. An informational component is shown beside the comparison and never decides the result. */
export type ReconComponent = {
  code: string
  label: string
  source_balance: string | null
  gl_balance: string | null
  difference: string | null
  status: ComponentStatus
  informational: boolean
  /** Which account / bank account / currency the component is about (only some types send it). */
  reference?: { code?: string; name?: string } | null
  /** Rows that make up the component (counterparties, accounts or tax codes, depending on the type): see `BreakdownRow`. */
  breakdown?: unknown[]
  breakdown_total?: number
  breakdown_truncated?: boolean
  /** Cash/bank: what the whole ledger balance is and what the ledger holds beyond the documents. */
  book_balance?: string | null
  other_activity?: string | null
  /** Foreign open documents. */
  currency?: string
  foreign_outstanding?: string | null
  documents?: unknown
}

export type ReconResult = {
  type: ReconType
  as_of: string
  status: ReconStatus
  source_balance: string | null
  gl_balance: string | null
  difference: string | null
  tolerance: string
  complete: boolean
  components: ReconComponent[]
  notes: string[]
}

export type ReconOverview = { as_of: string; data: ReconResult[]; complete: boolean }

export type PeriodRef = { id: string; code: string; start_date: string; end_date: string }

export type ReconRun = {
  id: string
  type: ReconType
  reconciliation_type: ReconType
  as_of_date: string
  accounting_period_id: string | null
  status: ReconStatus
  source_balance: string | null
  gl_balance: string | null
  difference: string | null
  tolerance: string
  scope_complete: boolean
  purpose: 'MANUAL' | 'CLOSE_CHECK'
  components: ReconComponent[] | null
  run_at: string
  period: PeriodRef | null
}

export type ReconException = {
  id: string
  type: ReconType
  reconciliation_type: ReconType
  as_of_date: string
  accounting_period_id: string | null
  status: ExceptionStatus
  calculated_status: ReconStatus
  source_balance: string | null
  gl_balance: string | null
  difference: string | null
  owner_id: string | null
  notes: string | null
  explained_by: string | null
  explained_at: string | null
  resolved_by: string | null
  resolved_at: string | null
  resolution_note: string | null
  last_run_id: string | null
  created_at?: string
  updated_at?: string
  period: PeriodRef | null
  owner: { id: string; name: string } | null
}

export type RunResponse = { run: ReconRun; result: ReconResult; exception: ReconException | null }

export type ExceptionPage = Page<ReconException>
export type RunPage = Page<ReconRun>

/** A counterparty or account row of a compared component. */
export type CompareRow = { code?: string; name?: string; account?: { code?: string; name?: string }; asset_count?: number; source_balance: string | null; gl_balance: string | null; difference: string | null; status?: string }
/** A tax code row of a tax component. */
export type TaxRow = { tax_code: string; tax_name: string; tax_amount: string; transactions: number }

export const isCompareRow = (row: unknown): row is CompareRow => typeof row === 'object' && row !== null && 'source_balance' in row && 'gl_balance' in row && 'difference' in row
export const isTaxRow = (row: unknown): row is TaxRow => typeof row === 'object' && row !== null && 'tax_code' in row && 'tax_amount' in row

/** The cut-off the user picked: a date, or a period (its end date). The API takes one of them. */
export type Cutoff = { as_of: string; period_id: string }
