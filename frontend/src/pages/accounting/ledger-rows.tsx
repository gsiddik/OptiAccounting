import { formatAmount } from '../../lib/accounting'
import type { LedgerLineX } from './ledger-types'

/** The foreign amount a line was entered in and the rate it was posted at ("USD 100,00 · kurs 15.000,00"). Shown only for a foreign line; the ledger amount beside it is what counts. */
export function ForeignNote({ line, side, functional }: { line: LedgerLineX; side: 'debit' | 'credit'; functional: string | null | undefined }) {
  const amount = side === 'debit' ? line.foreign_debit : line.foreign_credit
  if (!line.currency || line.currency === functional || !amount || /^0+(\.0+)?$/.test(amount)) return null
  return <div className="muted money">{line.currency} {formatAmount(amount)}{line.exchange_rate ? ` · kurs ${formatAmount(line.exchange_rate)}` : ''}</div>
}

/** Where the journal came from, as one muted line under the description. */
export function SourceNote({ line }: { line: LedgerLineX }) {
  if (!line.source_type) return null
  return <div className="muted">Sumber: <span className="mono">{line.source_type}</span>{line.source_id ? <> · <span className="mono">{line.source_id}</span></> : null}</div>
}
