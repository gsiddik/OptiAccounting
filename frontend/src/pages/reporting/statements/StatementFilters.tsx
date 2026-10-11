import type { ReactNode } from 'react'
import { useDimensions } from '../../accounting/data'
import { DimensionFilters, Filters } from '../../accounting/shared'
import { useDefinitionOptions, useStatementPeriods, type FilterState } from './data'
import { definitionStatusText } from './labels'
import type { StatementKind } from './types'

/**
 * The filters every statement shares: the date (a day for a balance sheet; a period or a range for a period statement), the dimensions the user
 * may use, the version of the report definition and the display switches. `lead` puts page-specific controls first (the comparative's choices).
 * Empty dates are not an error: the server then uses the business date or the current fiscal year to date, and the header says which.
 */
export function StatementFilters({ kind, value, onChange, lead, showAccounts, onShowAccounts }: {
  kind: StatementKind
  value: FilterState
  onChange: (value: FilterState) => void
  lead?: ReactNode
  showAccounts: boolean
  onShowAccounts: (show: boolean) => void
}) {
  const { catalog } = useDimensions()
  const { periods } = useStatementPeriods()
  const { definitions } = useDefinitionOptions(kind)
  const set = (patch: Partial<FilterState>) => onChange({ ...value, ...patch })
  const periodKnown = periods.some((p) => p.id === value.period_id)

  return (
    <>
      <Filters>
        {lead}
        {kind === 'BALANCE_SHEET' ? (
          <label className="inline-field">Per tanggal <input className="input" type="date" value={value.as_of} onChange={(e) => set({ as_of: e.target.value })} /></label>
        ) : (
          <>
            {(periods.length > 0 || value.period_id) && (
              <select className="select" aria-label="Periode" value={value.period_id} onChange={(e) => set({ period_id: e.target.value })}>
                <option value="">Rentang tanggal</option>
                {value.period_id && !periodKnown && <option value={value.period_id}>Periode terpilih</option>}
                {periods.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
              </select>
            )}
            {!value.period_id && (
              <>
                <label className="inline-field">Dari <input className="input" type="date" value={value.from} onChange={(e) => set({ from: e.target.value })} /></label>
                <label className="inline-field">Sampai <input className="input" type="date" value={value.to} onChange={(e) => set({ to: e.target.value })} /></label>
              </>
            )}
          </>
        )}
        <DimensionFilters catalog={catalog} value={value} onChange={(patch) => set(patch)} />
        {definitions.length > 0 && (
          <select className="select" aria-label="Versi definisi" value={value.definition_id} onChange={(e) => set({ definition_id: e.target.value })}>
            <option value="">Versi yang berlaku (otomatis)</option>
            {definitions.map((d) => <option key={d.id} value={d.id}>{d.name} · versi {d.version} · {definitionStatusText(d.status)[0]}</option>)}
          </select>
        )}
        <label className="check"><input type="checkbox" checked={value.include_zero} onChange={(e) => set({ include_zero: e.target.checked })} /> Tampilkan baris nol</label>
        <label className="check"><input type="checkbox" checked={showAccounts} onChange={(e) => onShowAccounts(e.target.checked)} /> Tampilkan akun</label>
      </Filters>
      {kind !== 'BALANCE_SHEET' && !value.period_id && !value.from && !value.to && (
        <p className="muted preview-note" style={{ marginTop: 8 }}>Tanpa tanggal, laporan memakai tahun fiskal berjalan sampai tanggal bisnis.</p>
      )}
    </>
  )
}
