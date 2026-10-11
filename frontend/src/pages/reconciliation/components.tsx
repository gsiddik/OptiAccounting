import type { Period } from '../../lib/accounting'
import { Badge } from '../../components/ui'
import { exceptionText, statusText } from './labels'
import type { Cutoff } from './types'

export function ReconBadge({ status }: { status: string | null | undefined }) {
  const [label, tone] = statusText(status)
  return <Badge tone={tone}>{label}</Badge>
}

export function ExceptionBadge({ status }: { status: string }) {
  const [label, tone] = exceptionText(status)
  return <Badge tone={tone}>{label}</Badge>
}

/** Cut-off selector: an explicit date, or an accounting period (its end date) when the user may see the periods. */
export function CutoffFilter({ value, onChange, periods }: { value: Cutoff; onChange: (cutoff: Cutoff) => void; periods: Period[] }) {
  const known = periods.some((p) => p.id === value.period_id)
  return (
    <>
      {(periods.length > 0 || value.period_id) && (
        <select className="select" aria-label="Periode" value={value.period_id} onChange={(e) => onChange({ ...value, period_id: e.target.value })}>
          <option value="">Tanggal tertentu</option>
          {value.period_id && !known && <option value={value.period_id}>Periode terpilih</option>}
          {periods.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
        </select>
      )}
      {!value.period_id && (
        <label className="inline-field">Per tanggal <input className="input" type="date" value={value.as_of} onChange={(e) => onChange({ ...value, as_of: e.target.value })} /></label>
      )}
    </>
  )
}
