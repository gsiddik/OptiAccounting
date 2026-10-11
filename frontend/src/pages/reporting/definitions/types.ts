// Shapes of the report definition and account balance APIs (backend: Reporting\Services\ReportDefinitionService, ReportDefinitionValidator,
// AccountBalanceService). Amounts are decimal strings that the backend computed; the UI only formats them. Nothing here is ever summed in React.

export type StatementType = 'BALANCE_SHEET' | 'PROFIT_LOSS' | 'CASH_FLOW'
export type DefinitionStatus = 'DRAFT' | 'ACTIVE' | 'ARCHIVED'
export type LineType = 'HEADER' | 'ACCOUNT' | 'ACCOUNT_GROUP' | 'SUBTOTAL' | 'CALCULATED_TOTAL' | 'SYSTEM'
export type BalanceSide = 'DEBIT' | 'CREDIT'
export type CashFlowCategory = 'OPERATING' | 'INVESTING' | 'FINANCING' | 'NON_CASH' | 'UNCLASSIFIED'

export const STATEMENT_TYPES: StatementType[] = ['BALANCE_SHEET', 'PROFIT_LOSS', 'CASH_FLOW']
export const DEFINITION_STATUSES: DefinitionStatus[] = ['DRAFT', 'ACTIVE', 'ARCHIVED']
export const LINE_TYPES: LineType[] = ['HEADER', 'ACCOUNT', 'ACCOUNT_GROUP', 'SUBTOTAL', 'CALCULATED_TOTAL', 'SYSTEM']
export const CASH_FLOW_CATEGORIES: CashFlowCategory[] = ['OPERATING', 'INVESTING', 'FINANCING', 'NON_CASH', 'UNCLASSIFIED']

/** Lines whose amount comes from the accounts mapped to them. */
export const MAPPED_TYPES: LineType[] = ['ACCOUNT', 'ACCOUNT_GROUP']
/** Lines that may contain other lines. */
export const CONTAINER_TYPES: LineType[] = ['HEADER', 'SUBTOTAL']

export type DefinitionHeader = {
  id: string
  statement_type: StatementType
  code: string
  name: string
  description: string | null
  version: number
  status: DefinitionStatus
  based_on_id: string | null
  effective_from: string | null
  effective_to: string | null
  published_by: string | null
  published_at: string | null
  archived_at: string | null
  lines_count: number | null
  mappings_count: number | null
  created_at: string | null
  updated_at: string | null
}

/** An account mapped to a line; `include_descendants` makes a header account stand for every posting account below it. */
export type LineMapping = {
  account_id: string
  code: string
  name: string
  account_type: string
  is_postable: boolean
  status: string
  include_descendants: boolean
}

export type Operand = { line: string; sign: '+' | '-' }

export type ReportLine = {
  id: string
  code: string
  label: string
  line_type: LineType
  parent_code: string | null
  depth: number
  display_order: number
  balance_side: BalanceSide | null
  cash_flow_category: CashFlowCategory | null
  system_key: string | null
  operands: Operand[] | null
  show_zero: boolean
  emphasis: boolean
  notes: string | null
  mappings: LineMapping[]
}

/** One finding of the validator: `code` is stable, `message` is English, `line` names the line it is about. */
export type Finding = { code: string; message: string; line: string | null; details: Record<string, unknown> }
export type Validation = { valid: boolean; errors: Finding[]; warnings: Finding[] }

export type DefinitionDetail = DefinitionHeader & { lines: ReportLine[]; validation?: Validation; warnings?: Finding[] }

export type UnmappedAccount = { id: string; code: string; name: string; account_type: string; balance: string; has_activity: boolean; status: string }

/** One entry of a PUT mappings body: a line (by code) and an account of this tenant. */
export type MappingEntry = { line_code: string; account_id: string; include_descendants: boolean }

export type DefaultsResult = {
  created: (DefinitionHeader & { validation?: Validation })[]
  skipped: { statement_type: StatementType; definition_id: string | null; code: string; message: string }[]
}

// ------------------------------------------------------------------------------------------------ account balance

type BalanceRowBase = {
  account_id: string
  code: string
  name: string
  account_type: string
  normal_balance: 'DEBIT' | 'CREDIT'
  parent_id: string | null
  depth: number
  is_header: boolean
}
export type BalanceRow = BalanceRowBase & { balance?: string; opening?: string; debit?: string; credit?: string; closing?: string }

export type BalanceTotals = {
  normal_balance: 'DEBIT' | 'CREDIT'
  balance: string
  closing_net_debit_minus_credit: string
  opening?: string
  debit?: string
  credit?: string
  closing?: string
  opening_net_debit_minus_credit?: string
}

export type AccountBalanceReport = {
  mode: 'AS_OF' | 'PERIOD'
  as_of: string | null
  range: { from: string; to: string } | null
  functional_currency: string | null
  account: { id: string; code: string; name: string; account_type: string; normal_balance: 'DEBIT' | 'CREDIT'; is_postable: boolean } | null
  filters: Record<string, unknown>
  data: BalanceRow[]
  totals: BalanceTotals
  complete: boolean
  generated_at: string
}
