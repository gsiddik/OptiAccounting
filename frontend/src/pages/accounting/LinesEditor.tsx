import { Button, Field } from '../../components/ui'
import { amountToApi, formatAmount, previewTotals, type Account } from '../../lib/accounting'
import { accountLabel, type DimensionCatalog } from './data'
import { emptyLine, type LineState } from './lines'

export function LinesEditor({
  lines,
  onChange,
  accounts,
  catalog,
  errorFor,
  allowControl,
}: {
  lines: LineState[]
  onChange: (lines: LineState[]) => void
  accounts: Account[]
  catalog: DimensionCatalog
  errorFor?: (path: string) => string | undefined
  allowControl?: boolean
}) {
  const totals = previewTotals(lines)
  const selectable = accounts.filter((a) => a.status === 'ACTIVE' && a.is_postable && (allowControl || !a.is_control))
  const patch = (key: string, change: Partial<LineState>) => onChange(lines.map((l) => (l.key === key ? { ...l, ...change } : l)))
  const diff = totals.difference < 0n ? -totals.difference : totals.difference

  return (
    <div className="journal-lines">
      <div className="line-head" aria-hidden="true">
        <span>#</span>
        <span>Akun</span>
        <span>Keterangan</span>
        <span className="right">Debit</span>
        <span className="right">Kredit</span>
        <span />
      </div>

      {lines.map((line, i) => {
        const options = line.account_id && !selectable.some((a) => a.id === line.account_id) ? [...selectable, ...accounts.filter((a) => a.id === line.account_id)] : selectable
        const units = catalog.business_units.filter((u) => !line.branch_id || u.branch_id === null || u.branch_id === line.branch_id)
        const hasDimensions = catalog.branches.length > 0 || catalog.business_units.length > 0 || catalog.cost_centers.length > 0
        return (
          <div className="line-row" key={line.key} role="group" aria-label={`Baris ${i + 1}`}>
            <span className="line-no">{i + 1}</span>
            <Field label={`Akun baris ${i + 1}`} error={errorFor?.(`lines.${i}.account_id`)}>
              {(p) => (
                <select className="select" value={line.account_id} onChange={(e) => patch(line.key, { account_id: e.target.value })} {...p}>
                  <option value="">Pilih akun…</option>
                  {options.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
                </select>
              )}
            </Field>
            <Field label={`Keterangan baris ${i + 1}`} error={errorFor?.(`lines.${i}.description`)}>
              {(p) => <input className="input" maxLength={255} value={line.description} onChange={(e) => patch(line.key, { description: e.target.value })} {...p} />}
            </Field>
            <Field label={`Debit baris ${i + 1}`} error={errorFor?.(`lines.${i}.debit`)}>
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={line.debit} onChange={(e) => patch(line.key, { debit: e.target.value, credit: e.target.value.trim() ? '' : line.credit })} {...p} />}
            </Field>
            <Field label={`Kredit baris ${i + 1}`} error={errorFor?.(`lines.${i}.credit`)}>
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={line.credit} onChange={(e) => patch(line.key, { credit: e.target.value, debit: e.target.value.trim() ? '' : line.debit })} {...p} />}
            </Field>
            <Button variant="ghost" size="sm" aria-label={`Hapus baris ${i + 1}`} disabled={lines.length <= 2} onClick={() => onChange(lines.filter((l) => l.key !== line.key))}>✕</Button>

            {hasDimensions && (
              <details className="line-dims" open={!!(line.branch_id || line.business_unit_id || line.cost_center_id)}>
                <summary>Dimensi baris {i + 1}</summary>
                <div className="line-dims-grid">
                  <Field label="Cabang" error={errorFor?.(`lines.${i}.branch_id`)}>
                    {(p) => (
                      <select className="select" value={line.branch_id} onChange={(e) => patch(line.key, { branch_id: e.target.value, business_unit_id: '' })} {...p}>
                        <option value="">Tanpa cabang</option>
                        {catalog.branches.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
                      </select>
                    )}
                  </Field>
                  <Field label="Unit bisnis" error={errorFor?.(`lines.${i}.business_unit_id`)}>
                    {(p) => (
                      <select className="select" value={line.business_unit_id} onChange={(e) => patch(line.key, { business_unit_id: e.target.value })} {...p}>
                        <option value="">Tanpa unit bisnis</option>
                        {units.map((u) => <option key={u.id} value={u.id}>{u.code} · {u.name}</option>)}
                      </select>
                    )}
                  </Field>
                  <Field label="Pusat biaya" error={errorFor?.(`lines.${i}.cost_center_id`)}>
                    {(p) => (
                      <select className="select" value={line.cost_center_id} onChange={(e) => patch(line.key, { cost_center_id: e.target.value })} {...p}>
                        <option value="">Tanpa pusat biaya</option>
                        {catalog.cost_centers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                      </select>
                    )}
                  </Field>
                </div>
              </details>
            )}
          </div>
        )
      })}

      <div className="line-foot">
        <Button size="sm" onClick={() => onChange([...lines, emptyLine()])}>Tambah baris</Button>
        <div className="totals" role="status" aria-live="polite">
          <span>Debit <strong className="money">{formatAmount(amountToApi(totals.debit))}</strong></span>
          <span>Kredit <strong className="money">{formatAmount(amountToApi(totals.credit))}</strong></span>
          <span className={totals.balanced ? 'balance-ok' : 'balance-bad'}>
            {totals.balanced ? 'Seimbang' : totals.debit === 0n && totals.credit === 0n ? 'Belum ada jumlah' : `Selisih ${formatAmount(amountToApi(diff))}`}
          </span>
        </div>
      </div>
      <p className="muted preview-note">Pratinjau saat mengetik. Server menghitung ulang semua total saat jurnal disimpan.</p>
    </div>
  )
}
