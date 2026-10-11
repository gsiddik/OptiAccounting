import { Banner } from '../../../components/ui'
import { PARTIAL_TEXT, warningText } from './labels'
import type { Warning } from './types'

/**
 * Tells the reader how far a statement can be trusted, before the figures: unreliable (the API's own verdict, with its reasons), partial (the data
 * scope or a filter narrows the ledger) or confirmed, and every warning of the API. A statement that is unreliable looks unreliable.
 */
export function Reliability({ complete, reliable, reasons, confirmed, warnings }: {
  complete: boolean
  reliable: boolean
  reasons: string[]
  /** The sentence that confirms a complete and reliable statement. */
  confirmed: string
  warnings: Warning[]
}) {
  const shown = warnings.filter((w) => w.code !== 'PARTIAL_LEDGER')
  return (
    <>
      {!reliable && (
        <Banner tone="bad">
          <strong>Laporan ini tidak dapat diandalkan.</strong>{' '}
          {reasons.length > 0 ? `Penyebab: ${reasons.join('; ')}.` : 'Server menandai laporan ini tidak dapat diandalkan.'} Selisih ditampilkan apa adanya, tidak diserap ke baris mana pun.
        </Banner>
      )}
      {!complete && <Banner tone="warn"><strong>Cakupan sebagian.</strong> {PARTIAL_TEXT}</Banner>}
      {reliable && complete && <Banner tone="ok">{confirmed}</Banner>}
      {shown.length > 0 && (
        <Banner tone="warn">
          <strong>Peringatan</strong>
          <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>
            {shown.map((w, i) => <li key={`${w.code}:${w.side ?? ''}:${i}`}>{warningText(w)}</li>)}
          </ul>
        </Banner>
      )}
    </>
  )
}
