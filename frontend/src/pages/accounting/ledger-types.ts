import type { LedgerReport } from '../../lib/accounting'

// Extra fields the OA5 general ledger sends on every row (backend: GeneralLedgerService). The base type in lib/accounting.ts predates them.
export type LedgerLineX = LedgerReport['data'][number] & {
  document_date?: string | null
  transaction_date?: string | null
  source_type?: string | null
  source_id?: string | null
  journal_reference?: string | null
  reference?: string | null
  /** Transaction-currency snapshot stored on the line: the foreign amount and the rate that was used when it was posted. */
  currency?: string | null
  foreign_debit?: string | null
  foreign_credit?: string | null
  exchange_rate?: string | null
  is_fx_difference?: boolean
}

export type LedgerReportX = Omit<LedgerReport, 'data'> & { data: LedgerLineX[]; functional_currency?: string | null }
