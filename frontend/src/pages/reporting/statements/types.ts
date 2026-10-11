// Shapes of the financial statement API (backend: Reporting\Services\{BalanceSheetService, ProfitLossService, CashFlowService, ComparativeReportService,
// DrilldownService}). Amounts are decimal strings ("1500000.0000") that the backend computed; the UI only formats them. Nothing here is ever summed,
// compared or subtracted in React: every total, balance, difference and variance is the API's own.

export type StatementKind = 'BALANCE_SHEET' | 'PROFIT_LOSS' | 'CASH_FLOW'
export type CompareMode = 'PRIOR_PERIOD' | 'PRIOR_YEAR' | 'BUDGET'
export type LineType = 'HEADER' | 'ACCOUNT' | 'ACCOUNT_GROUP' | 'SUBTOTAL' | 'CALCULATED_TOTAL' | 'SYSTEM'
export type DefinitionStatus = 'DRAFT' | 'ACTIVE' | 'ARCHIVED'

export const STATEMENT_KINDS: StatementKind[] = ['BALANCE_SHEET', 'PROFIT_LOSS', 'CASH_FLOW']

export type DateRange = { from: string; to: string }

/** Which version of which report definition produced the figures. */
export type DefinitionRef = {
  id: string
  code: string
  name: string
  version: number
  status: DefinitionStatus
  effective_from: string | null
  effective_to: string | null
}

/** Header of a report definition as listed for the version selector (GET report-definitions). */
export type DefinitionOption = DefinitionRef & { statement_type: StatementKind }

export type LineAccount = { id: string; code: string; name: string; amount: string }

export type StatementLine = {
  id: string
  code: string
  label: string
  line_type: LineType
  depth: number
  parent_code: string | null
  amount: string | null
  emphasis: boolean
  accounts: LineAccount[]
  balance_side: 'DEBIT' | 'CREDIT' | null
  system_key: string | null
  cash_flow_category: string | null
  notes: string | null
}

/** A warning of the API: `details` depends on the code (count, accounts, difference). A comparative marks the ones of the comparison side. */
export type Warning = { code: string; message: string; details?: Record<string, unknown>; side?: 'comparison' }

export type AccountRef = { id: string; code: string; name: string }
export type UnmappedAccount = AccountRef & { account_type: string; amount: string }
export type UnclassifiedAccount = UnmappedAccount & { reason: 'NOT_MAPPED' | 'CATEGORY_UNCLASSIFIED' }
export type CashAccount = AccountRef & { opening: string; movement: string; closing: string }

export type BalanceSheetIntegrity = {
  assets: string
  liabilities_and_equity: string
  difference: string
  /** null when the figures cover only part of the ledger: a partial ledger does not balance on its own. */
  balanced: boolean | null
  reliable: boolean
  current_result: string
  unmapped: UnmappedAccount[]
}

export type ProfitLossIntegrity = {
  ledger_result: string
  mapped_result: string
  difference: string
  reliable: boolean
  unmapped: UnmappedAccount[]
}

export type CashFlowIntegrity = {
  net_result: string
  cash_opening: string
  net_change: string
  cash_closing_calculated: string
  cash_closing_ledger: string
  difference: string
  /** null when the figures cover only part of the ledger. */
  reconciled: boolean | null
  reliable: boolean
  categories: Record<string, string>
  unclassified: UnclassifiedAccount[]
  cash_accounts: CashAccount[]
}

export type Integrity = BalanceSheetIntegrity | ProfitLossIntegrity | CashFlowIntegrity

export type ReportFilters = { branch_id?: string; business_unit_id?: string; cost_center_id?: string; include_zero: boolean }

type ReportBase = {
  definition: DefinitionRef
  currency: string | null
  generated_at: string
  complete: boolean
  filters: ReportFilters
  warnings: Warning[]
}

export type BalanceSheetReport = ReportBase & { statement: 'BALANCE_SHEET'; as_of: string; lines: StatementLine[]; integrity: BalanceSheetIntegrity }
export type ProfitLossReport = ReportBase & { statement: 'PROFIT_LOSS'; range: DateRange; lines: StatementLine[]; integrity: ProfitLossIntegrity }
export type CashFlowReport = ReportBase & { statement: 'CASH_FLOW'; range: DateRange; lines: StatementLine[]; integrity: CashFlowIntegrity }
export type StatementReport = BalanceSheetReport | ProfitLossReport | CashFlowReport

// ------------------------------------------------------------------------------------------------ comparative

export type ComparativeAccount = AccountRef & { current: string; comparison: string; variance: string | null; variance_pct: string | null }

export type ComparativeLine = {
  code: string
  label: string
  line_type: LineType
  depth: number
  parent_code: string | null
  emphasis: boolean
  current: string | null
  comparison: string | null
  variance: string | null
  /** Percent of the absolute comparison, two decimals; null when the comparison is zero. */
  variance_pct: string | null
  accounts: ComparativeAccount[]
}

export type BudgetIntegrity = { budget_result: string; unplaced: (AccountRef & { budget: string })[] }

export type ComparisonBlock = {
  mode: CompareMode
  as_of?: string
  range?: DateRange
  budget?: { id: string; code: string; name: string }
  version?: { id?: string; version_number?: number; label?: string } | null
}

export type ComparativeReport = {
  statement: StatementKind
  mode: CompareMode
  definition: DefinitionRef
  currency: string | null
  as_of?: string
  range?: DateRange
  comparison: ComparisonBlock
  generated_at: string
  complete: boolean
  filters: ReportFilters
  lines: ComparativeLine[]
  integrity: { current: Integrity; comparison?: Integrity; budget?: BudgetIntegrity }
  warnings: Warning[]
}

// ------------------------------------------------------------------------------------------------ drill-down

export type DrillAccount = LineAccount & { line_code: string; contribution: string; gl_query: Record<string, string> }
export type DrillComponent = { code: string; label: string; line_type: LineType; sign: '+' | '-'; amount: string | null }

export type Drilldown = {
  statement: StatementKind
  definition: DefinitionRef
  currency: string | null
  as_of?: string
  range?: DateRange
  generated_at: string
  complete: boolean
  filters: ReportFilters
  line: Pick<StatementLine, 'code' | 'label' | 'line_type' | 'depth' | 'parent_code' | 'amount' | 'emphasis' | 'balance_side' | 'system_key' | 'cash_flow_category' | 'notes'>
  explanation: string | null
  components: DrillComponent[]
  accounts: DrillAccount[]
  accounts_total: string
  not_explained_by_accounts: string
  general_ledger: { endpoint: string; closing_journals_listed: boolean; note: string }
  warnings: Warning[]
}
