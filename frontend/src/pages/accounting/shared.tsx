import type { ReactNode } from 'react'
import { formatAmount, type Period } from '../../lib/accounting'
import type { DimensionCatalog, Range } from './data'

/** Amount cell: right-aligned, tabular figures, Indonesian grouping. */
export function Money({ value, strong }: { value: string | null | undefined; strong?: boolean }) {
  const text = formatAmount(value)
  return <span className={`money${strong ? ' strong' : ''}`}>{text}</span>
}

/** Debit/credit cell that stays blank for a zero side so a journal reads like a ledger page. */
export function Side({ value }: { value: string | null | undefined }) {
  const zero = !value || /^0+(\.0+)?$/.test(value)
  return zero ? <span className="muted">—</span> : <Money value={value} />
}

/** A row of filter controls that wraps on narrow screens. */
export function Filters({ children }: { children: ReactNode }) {
  return <div className="filters">{children}</div>
}

/** Period picker with an "explicit dates" mode: picking a period hides the date inputs. */
export function RangeFilter({ value, onChange, periods }: { value: Range; onChange: (r: Range) => void; periods: Period[] }) {
  return (
    <>
      <select className="select" aria-label="Periode" value={value.period_id} onChange={(e) => onChange({ ...value, period_id: e.target.value })}>
        <option value="">Rentang tanggal</option>
        {periods.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
      </select>
      {!value.period_id && (
        <>
          <label className="inline-field">Dari <input className="input" type="date" value={value.from} onChange={(e) => onChange({ ...value, from: e.target.value })} /></label>
          <label className="inline-field">Sampai <input className="input" type="date" value={value.to} onChange={(e) => onChange({ ...value, to: e.target.value })} /></label>
        </>
      )}
    </>
  )
}

/** Branch, business unit and cost center selectors for report filters; renders nothing when the user has no dimensions. */
export function DimensionFilters({ catalog, value, onChange }: { catalog: DimensionCatalog; value: { branch_id: string; business_unit_id: string; cost_center_id?: string }; onChange: (patch: Partial<{ branch_id: string; business_unit_id: string; cost_center_id: string }>) => void }) {
  const units = catalog.business_units.filter((u) => !value.branch_id || u.branch_id === null || u.branch_id === value.branch_id)
  return (
    <>
      {catalog.branches.length > 0 && (
        <select className="select" aria-label="Cabang" value={value.branch_id} onChange={(e) => onChange({ branch_id: e.target.value, business_unit_id: '' })}>
          <option value="">Semua cabang</option>
          {catalog.branches.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
        </select>
      )}
      {units.length > 0 && (
        <select className="select" aria-label="Unit bisnis" value={value.business_unit_id} onChange={(e) => onChange({ business_unit_id: e.target.value })}>
          <option value="">Semua unit bisnis</option>
          {units.map((u) => <option key={u.id} value={u.id}>{u.code} · {u.name}</option>)}
        </select>
      )}
      {value.cost_center_id !== undefined && catalog.cost_centers.length > 0 && (
        <select className="select" aria-label="Pusat biaya" value={value.cost_center_id} onChange={(e) => onChange({ cost_center_id: e.target.value })}>
          <option value="">Semua pusat biaya</option>
          {catalog.cost_centers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
        </select>
      )}
    </>
  )
}
