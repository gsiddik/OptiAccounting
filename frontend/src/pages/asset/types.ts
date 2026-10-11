import type { DocSod, DocTransition, Ref } from '../../lib/operational'

// Shapes of the fixed asset API (backend: FixedAsset\Services\*::load()/query(), AssetReportController). Amounts are decimal strings
// ("1500000.0000") that the backend computed or validated; the UI only formats them. Nothing here is ever summed or compared in React.

export type AccountRef = { id: string; code: string; name: string }

export type AssetMethod = 'STRAIGHT_LINE' | 'DECLINING_BALANCE' | 'NONE'
export type ResidualType = 'NONE' | 'AMOUNT' | 'PERCENT'
export type StartPolicy = 'CAPITALIZATION_MONTH' | 'NEXT_MONTH'
export type CapitalizationMode = 'POST' | 'REGISTER_ONLY'
export type AssetStatus = 'DRAFT' | 'ACTIVE' | 'FULLY_DEPRECIATED' | 'DISPOSED' | 'INACTIVE'
export type DisposalType = 'SALE' | 'SCRAP'

export const ASSET_METHODS: AssetMethod[] = ['STRAIGHT_LINE', 'DECLINING_BALANCE', 'NONE']
export const RESIDUAL_TYPES: ResidualType[] = ['NONE', 'AMOUNT', 'PERCENT']
export const START_POLICIES: StartPolicy[] = ['CAPITALIZATION_MONTH', 'NEXT_MONTH']
export const ASSET_STATUSES: AssetStatus[] = ['DRAFT', 'ACTIVE', 'FULLY_DEPRECIATED', 'DISPOSED', 'INACTIVE']
export const RUN_STATUSES = ['DRAFT', 'POSTED', 'REVERSED', 'CANCELLED'] as const
export const DISPOSAL_TYPES: DisposalType[] = ['SALE', 'SCRAP']

/** Assets that are still on the books and so may be disposed of. */
export const DISPOSABLE_STATUSES: AssetStatus[] = ['ACTIVE', 'FULLY_DEPRECIATED']

export type AssetCategory = {
  id: string
  code: string
  name: string
  description: string | null
  status: 'ACTIVE' | 'INACTIVE'
  asset_account_id: string | null
  accumulated_account_id: string | null
  expense_account_id: string | null
  gain_loss_account_id: string | null
  default_method: AssetMethod
  default_useful_life_months: number | null
  default_residual_type: ResidualType
  default_residual_value: string
  default_start_policy: StartPolicy
  asset_account?: AccountRef | null
  accumulated_account?: AccountRef | null
  expense_account?: AccountRef | null
  gain_loss_account?: AccountRef | null
}

export type CategoryRef = { id: string; code: string; name: string; status?: string }

export type ScheduleSummary = { rows: number; planned: number; in_run: number; posted: number; cancelled: number; next_period: string | null }

export type Asset = {
  id: string
  asset_number: string | null
  name: string
  description: string | null
  asset_category_id: string
  status: AssetStatus
  acquisition_date: string
  capitalization_date: string
  acquisition_cost: string
  residual_value: string
  useful_life_months: number | null
  method: AssetMethod
  method_params: { factor?: string } | null
  start_policy: StartPolicy
  currency: string
  branch_id: string | null
  business_unit_id: string | null
  cost_center_id: string | null
  capitalization_mode: CapitalizationMode
  source_account_id: string | null
  source_type: string | null
  source_id: string | null
  source_reference: string | null
  asset_account_id: string | null
  accumulated_account_id: string | null
  expense_account_id: string | null
  gain_loss_account_id: string | null
  depreciable_basis: string | null
  accumulated_depreciation: string
  capitalization_journal_id: string | null
  capitalization_reversal_journal_id: string | null
  capitalization_reversal_reason: string | null
  disposed_on: string | null
  disposal_id: string | null
  inactive_reason: string | null
  created_by: string | null
  /** Present on the detail response only (the list does not carry it). */
  net_book_value?: string
  schedule_summary?: ScheduleSummary
  sod?: { capitalize: boolean }
  category?: CategoryRef | null
  branch?: Ref | null
  business_unit?: Ref | null
  cost_center?: Ref | null
  creator?: { id: string; name: string } | null
  source_account?: AccountRef | null
  asset_account?: AccountRef | null
  accumulated_account?: AccountRef | null
  expense_account?: AccountRef | null
  gain_loss_account?: AccountRef | null
  transitions?: DocTransition[]
}

export type ScheduleRow = {
  id?: string
  sequence_no: number
  period_start: string
  period_end: string
  amount: string
  accumulated_after: string
  book_value_after: string
  status?: string
}
export type Schedule = { preview: boolean; rows: ScheduleRow[] }

export type PeriodRef = { id: string; code: string; start_date: string; end_date: string; status: string }

export type RunLine = {
  id: string
  line_number: number
  fixed_asset_id: string
  schedule_rows: number
  amount: string
  accumulated_before: string
  accumulated_after: string
  book_value_after: string
  asset?: { id: string; asset_number: string | null; name: string; status: string } | null
}

export type DepreciationRun = {
  id: string
  document_number: string | null
  accounting_period_id: string
  posting_date: string
  description: string | null
  reference: string | null
  total_amount: string
  asset_count: number
  status: 'DRAFT' | 'POSTED' | 'REVERSED' | 'CANCELLED'
  cancel_reason: string | null
  posted_at: string | null
  journal_entry_id: string | null
  reversal_journal_id: string | null
  reversal_reason: string | null
  reversal_posting_date: string | null
  period?: PeriodRef | null
  creator?: { id: string; name: string } | null
  transitions?: DocTransition[]
  lines?: RunLine[]
  sod?: DocSod
}

export type DisposalFigures = { cost: string; accumulated: string; book_value: string; proceeds: string; gain: string; loss: string }

export type DisposalAsset = { id: string; asset_number: string | null; name: string; status: string; acquisition_cost: string; accumulated_depreciation: string }

export type Disposal = {
  id: string
  document_number: string | null
  fixed_asset_id: string
  disposal_type: DisposalType
  status: 'DRAFT' | 'SUBMITTED' | 'APPROVED' | 'REJECTED' | 'POSTED' | 'CANCELLED' | 'REVERSED'
  document_date: string
  disposal_date: string
  posting_date: string
  proceeds_amount: string
  proceeds_account_id: string | null
  reason: string
  reference: string | null
  branch_id: string | null
  business_unit_id: string | null
  cost_center_id: string | null
  /** Snapshot taken when the disposal posted; null before. */
  cost_amount: string | null
  accumulated_depreciation: string | null
  book_value: string | null
  gain_amount: string | null
  loss_amount: string | null
  reject_reason: string | null
  cancel_reason: string | null
  posted_at: string | null
  journal_entry_id: string | null
  reversal_journal_id: string | null
  reversal_reason: string | null
  reversal_posting_date: string | null
  asset?: DisposalAsset | null
  proceeds_account?: AccountRef | null
  branch?: Ref | null
  business_unit?: Ref | null
  cost_center?: Ref | null
  creator?: { id: string; name: string } | null
  transitions?: DocTransition[]
  /** The server's calculation: the register's current figures until posting, the stored snapshot afterwards. */
  preview?: DisposalFigures
  sod?: DocSod
}

export type ReconRow = { account: { id: string; code: string | null; name: string | null }; asset_count: number; register: string; ledger: string; difference: string; matched: boolean }

export type AssetReconciliation = {
  as_of: string
  complete: boolean
  cost: ReconRow[]
  accumulated_depreciation: ReconRow[]
  totals: {
    register_cost: string
    ledger_cost: string
    register_accumulated: string
    ledger_accumulated: string
    register_net_book_value: string
    ledger_net_book_value: string
    difference: string
  }
  reconciled: boolean
}

export type RuleStatus = { event_type: string; name: string; ready: boolean; rule_code: string | null; effective_from: string | null }
export type AssetRules = { events: RuleStatus[]; unmapped_roles: string[] }
export type RuleDefaultsResult = { created: string[]; skipped: { event_type: string; reason: string }[] }

/** A posted AP invoice offered when registering cost that the invoice already put in the ledger. */
export type ApInvoiceOption = { id: string; document_number: string | null; vendor_invoice_number: string; total_amount: string; vendor?: { id: string; code: string; name: string } | null }
export type ApLineOption = { id: string; line_number: number; description: string; amount: string; account_id: string | null; account?: AccountRef | null }
