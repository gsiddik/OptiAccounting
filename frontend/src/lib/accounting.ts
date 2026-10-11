import { api } from './api'
import { useCapabilities } from './capabilities'

// ---------------------------------------------------------------------------------------------- types

export type AccountingProfile = {
  id: string
  framework: 'SAK_GENERAL' | 'SAK_EP' | 'SAK_EMKM' | 'CUSTOM'
  functional_currency: string
  currency_scale: number
  status: 'CONFIGURING' | 'READY' | 'LOCKED'
  approval_required: boolean
  sod_creator_not_approver: boolean
  sod_creator_not_poster: boolean
  sod_approver_not_poster: boolean
  cutover_date: string | null
}

export type ReadinessCheck = { code: string; label: string; required: boolean; done: boolean; detail: string; action: string }
export type Readiness = { status: string; ready: boolean; can_activate: boolean; checks: ReadinessCheck[] }

export type Period = { id: string; fiscal_year_id: string; number: number; code: string; name: string; start_date: string; end_date: string; status: 'FUTURE' | 'OPEN' | 'SOFT_CLOSED' | 'CLOSED' }
export type FiscalYear = { id: string; code: string; name: string; start_date: string; end_date: string; status: 'DRAFT' | 'OPEN' | 'CLOSED'; periods?: Period[] }

export type AccountType = 'ASSET' | 'LIABILITY' | 'EQUITY' | 'REVENUE' | 'EXPENSE'
export type Account = {
  id: string
  code: string
  name: string
  description: string | null
  parent_id: string | null
  account_type: AccountType
  normal_balance: 'DEBIT' | 'CREDIT'
  is_postable: boolean
  is_control: boolean
  currency: string | null
  status: 'ACTIVE' | 'INACTIVE'
}

export type CostCenter = { id: string; code: string; name: string; description: string | null; branch_id: string | null; business_unit_id: string | null; status: 'ACTIVE' | 'INACTIVE' }

export type JournalStatus = 'DRAFT' | 'SUBMITTED' | 'APPROVED' | 'REJECTED' | 'POSTED' | 'CANCELLED'
export type JournalLine = {
  id?: string
  line_number?: number
  account_id: string
  account?: { id: string; code: string; name: string; normal_balance: string }
  description: string | null
  reference: string | null
  debit: string
  credit: string
  /** Transaction-currency snapshot of the line (the ledger sums debit/credit; these show what was entered in a foreign currency). */
  transaction_currency?: string
  transaction_debit?: string
  transaction_credit?: string
  exchange_rate?: string
  /** A realised exchange difference posted by the system when a foreign document is settled. */
  is_fx_difference?: boolean
  branch_id: string | null
  business_unit_id: string | null
  cost_center_id: string | null
  branch?: { id: string; code: string; name: string } | null
  business_unit?: { id: string; code: string; name: string } | null
  cost_center?: { id: string; code: string; name: string } | null
}
export type JournalTransition = { id: string; from_status: string | null; to_status: string; actor_user_id: string | null; actor?: { id: string; name: string } | null; reason: string | null; occurred_at: string }
export type SodAllowed = { approve: boolean; post: boolean }
export type Journal = {
  id: string
  journal_number: string | null
  journal_type: 'MANUAL' | 'OPENING' | 'REVERSAL' | 'SYSTEM'
  status: JournalStatus
  document_date: string
  posting_date: string
  description: string
  reference: string | null
  currency: string
  total_debit: string
  total_credit: string
  created_by: string | null
  creator?: { id: string; name: string } | null
  reject_reason?: string | null
  reversal_reason?: string | null
  reverses_journal_id: string | null
  reversed_by_journal_id: string | null
  source_type: string | null
  source_id: string | null
  posted_at: string | null
  lines?: JournalLine[]
  transitions?: JournalTransition[]
  sod?: SodAllowed
  approval_required?: boolean
}

export type OpeningBalance = {
  id: string
  status: 'DRAFT' | 'POSTED' | 'REVERSED' | 'CANCELLED'
  cutover_date: string
  reference: string | null
  description: string | null
  journal: Journal
  total_debit: string
  total_credit: string
  difference: string
  balanced: boolean
}

export type RuleLine = { id?: string; line_number?: number; side: 'DEBIT' | 'CREDIT'; account_role: string; amount_key: string; skip_if_zero: boolean; description: string | null }
export type PostingRule = {
  id: string
  code: string
  version: number
  event_type: string
  name: string
  description: string | null
  status: 'DRAFT' | 'PUBLISHED' | 'ARCHIVED'
  effective_from: string | null
  effective_to: string | null
  lines: RuleLine[]
}
export type EventType = { code: string; name: string; description: string | null; components: string[] }
export type AccountRole = { code: string; name: string; description: string | null; used_by_published_rule: boolean; mapped: boolean }
export type AccountMapping = { id: string; account_role: string; account_id: string; branch_id: string | null; business_unit_id: string | null; status: 'ACTIVE' | 'INACTIVE'; account?: Pick<Account, 'id' | 'code' | 'name' | 'account_type' | 'status'> }
export type AccountingEvent = {
  id: string
  event_type: string
  source_type: string
  source_id: string
  posting_purpose: string
  status: 'PENDING' | 'POSTED' | 'FAILED'
  posting_date: string
  journal_entry_id: string | null
  failure_code: string | null
  failure_message: string | null
  attempts: number
}
export type SimulatedLine = { line: number; side: 'DEBIT' | 'CREDIT'; account_role: string; amount_key: string; amount: string; account_id: string; account_code: string; account_name: string; mapping_scope: string }
export type Simulation = { lines: SimulatedLine[]; total_debit: string; total_credit: string; balanced: boolean }

export type LedgerLine = {
  line_id: string
  posting_date: string
  journal_id: string
  journal_number: string | null
  journal_type: string
  journal_description: string
  description: string | null
  reference: string | null
  account: { id: string; code: string; name: string; normal_balance: string }
  debit: string
  credit: string
  running_balance: string | null
}
export type LedgerReport = {
  range: { from: string; to: string }
  account: Pick<Account, 'id' | 'code' | 'name' | 'account_type' | 'normal_balance'> | null
  summary: { normal_balance?: string; opening?: string; debit: string; credit: string; closing?: string }
  data: LedgerLine[]
  meta: { page: number; per_page: number; total: number; last_page: number }
  complete: boolean
}
export type TrialBalanceRow = {
  account_id: string
  code: string
  name: string
  account_type: AccountType
  depth: number
  is_header: boolean
  opening_debit: string
  opening_credit: string
  debit: string
  credit: string
  ending_debit: string
  ending_credit: string
}
type Pair = { debit: string; credit: string; difference: string; equal: boolean }
export type TrialBalanceReport = {
  range: { from: string; to: string }
  data: TrialBalanceRow[]
  totals: Record<'opening_debit' | 'opening_credit' | 'debit' | 'credit' | 'ending_debit' | 'ending_credit', string>
  reconciliation: { opening: Pair; movement: Pair; ending: Pair; reconciled: boolean | null }
  complete: boolean
}

export type AccountingDashboard = {
  business_date: string
  fiscal_year: Pick<FiscalYear, 'id' | 'code' | 'name' | 'start_date' | 'end_date' | 'status'> | null
  period: Pick<Period, 'id' | 'code' | 'name' | 'start_date' | 'end_date' | 'status'> | null
  journals: { draft: number; pending_approval: number; awaiting_posting: number }
  recent_posted: Pick<Journal, 'id' | 'journal_number' | 'journal_type' | 'posting_date' | 'description' | 'total_debit' | 'currency'>[]
}

// ---------------------------------------------------------------------------------------------- decimal-safe amounts
// The backend computes every authoritative total. These helpers only preview what the user is typing, and they use
// integer arithmetic on BigInt (units of 1/10 000) so no floating point ever touches money.

const UNIT = 10_000n

/** "1234,5" or "1234.5" -> units of 1/10 000. null when it is not a plain non-negative decimal with at most four decimals. */
export function parseAmount(input: string | null | undefined): bigint | null {
  const text = (input ?? '').trim().replace(',', '.')
  if (text === '') return 0n
  const m = /^(\d+)(?:\.(\d{1,4}))?$/.exec(text)
  if (!m) return null
  return BigInt(m[1]) * UNIT + BigInt((m[2] ?? '').padEnd(4, '0') || '0')
}

/** Units -> the API string: "1234.5000". */
export function amountToApi(units: bigint): string {
  const negative = units < 0n
  const abs = negative ? -units : units
  return `${negative ? '-' : ''}${abs / UNIT}.${(abs % UNIT).toString().padStart(4, '0')}`
}

/** What to send for a typed amount: a normalised decimal string, or '' when empty. Never a number. */
export function normalizeAmountInput(input: string): string {
  const units = parseAmount(input)
  return units === null || units === 0n ? '' : amountToApi(units)
}

/** "1500000.5000" -> "1.500.000,50" (Indonesian grouping; two decimals at least, up to four when they matter). */
export function formatAmount(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const m = /^(-?)(\d+)(?:\.(\d+))?$/.exec(value)
  if (!m) return value
  const decimals = (m[3] ?? '').replace(/0+$/, '').padEnd(2, '0')
  return `${m[1]}${m[2].replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${decimals}`
}

export type LineDraft = { account_id: string; debit: string; credit: string }

/** Totals of a journal being typed, in exact arithmetic. `invalid` lists the 1-based line numbers whose amounts do not parse. */
export function previewTotals(lines: LineDraft[]): { debit: bigint; credit: bigint; difference: bigint; balanced: boolean; invalid: number[] } {
  let debit = 0n
  let credit = 0n
  const invalid: number[] = []
  lines.forEach((l, i) => {
    const d = parseAmount(l.debit)
    const c = parseAmount(l.credit)
    if (d === null || c === null) invalid.push(i + 1)
    debit += d ?? 0n
    credit += c ?? 0n
  })
  return { debit, credit, difference: debit - credit, balanced: debit === credit && debit > 0n && invalid.length === 0, invalid }
}

// ---------------------------------------------------------------------------------------------- access and downloads

/** Accounting permission check that also respects a READ_ONLY subscription: a mutation button needs both. */
export function useAccountingAccess() {
  const { can, moduleMode } = useCapabilities()
  const writable = moduleMode('ACCOUNTING_CORE') === 'FULL'
  return {
    can,
    /** Permission held AND the module is not read-only. Cosmetic; the API enforces both. */
    canChange: (permission: string) => writable && can(permission),
    readOnly: moduleMode('ACCOUNTING_CORE') === 'READ_ONLY',
  }
}

/** Download a CSV from an authenticated endpoint (the bearer token cannot ride on a plain link). */
export async function downloadCsv(path: string, params: Record<string, string | number | boolean | undefined>, filename: string): Promise<void> {
  const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== ''))
  const response = await api.get<Blob>(path, { params: clean, responseType: 'blob' })
  const url = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
