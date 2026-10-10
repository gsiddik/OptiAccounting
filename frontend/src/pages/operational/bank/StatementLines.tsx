import { Banner, Button, Field } from '../../../components/ui'
import { fieldError } from '../../../lib/forms'
import { emptyRow, MAX_ITEMS, type BuiltLines, type RowDraft, type RowField } from './lines'

const PLACEHOLDER = '2026-03-01;Setoran modal;5.000.000,00\n2026-03-15;Biaya admin bank;-25.000,00'

/**
 * Statement lines for a new statement or an "add lines" dialog: a small repeating row form and a "paste from spreadsheet" box.
 * Both feed the same list; nothing is sent until the parent submits `built.items`. Amounts are signed (deposits positive,
 * withdrawals negative) and travel as exact decimal strings.
 */
export function StatementLines({ rows, onRows, paste, onPaste, built, showErrors, serverError }: {
  rows: RowDraft[]
  onRows: (rows: RowDraft[]) => void
  paste: string
  onPaste: (text: string) => void
  built: BuiltLines
  /** Row errors appear only after the first submit attempt (pasted problems are shown while typing). */
  showErrors: boolean
  serverError?: unknown
}) {
  const patch = (key: number, change: Partial<RowDraft>) => onRows(rows.map((r) => (r.key === key ? { ...r, ...change } : r)))
  const errorOf = (row: RowDraft, field: RowField): string | undefined => {
    const local = showErrors ? built.rowErrors.get(row.key)?.[field] : undefined
    const index = built.indexOf.get(row.key)
    return local ?? (index === undefined ? undefined : fieldError(serverError, `items.${index}.${field}`))
  }

  return (
    <div className="journal-lines">
      {rows.length > 0 && (
        <div className="line-head" aria-hidden="true">
          <span>#</span>
          <span>Keterangan</span>
          <span>Referensi</span>
          <span>Tanggal</span>
          <span className="right">Jumlah (+ masuk / − keluar)</span>
          <span />
        </div>
      )}

      {rows.map((row, i) => (
        <div className="line-row" key={row.key} role="group" aria-label={`Baris ${i + 1}`}>
          <span className="line-no">{i + 1}</span>
          <Field label={`Keterangan baris ${i + 1}`} error={errorOf(row, 'description')}>
            {(p) => <input className="input" maxLength={255} value={row.description} onChange={(e) => patch(row.key, { description: e.target.value })} {...p} />}
          </Field>
          <Field label={`Referensi baris ${i + 1}`} error={errorOf(row, 'reference')}>
            {(p) => <input className="input" maxLength={100} value={row.reference} onChange={(e) => patch(row.key, { reference: e.target.value })} {...p} />}
          </Field>
          <Field label={`Tanggal baris ${i + 1}`} error={errorOf(row, 'item_date')}>
            {(p) => <input className="input" type="date" value={row.item_date} onChange={(e) => patch(row.key, { item_date: e.target.value })} {...p} />}
          </Field>
          <Field label={`Jumlah baris ${i + 1}`} error={errorOf(row, 'amount')}>
            {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="-25.000,00" value={row.amount} onChange={(e) => patch(row.key, { amount: e.target.value })} {...p} />}
          </Field>
          <Button variant="ghost" size="sm" aria-label={`Hapus baris ${i + 1}`} onClick={() => onRows(rows.filter((r) => r.key !== row.key))}>✕</Button>
        </div>
      ))}

      <div className="line-foot">
        <Button size="sm" onClick={() => onRows([...rows, emptyRow()])}>Tambah baris</Button>
        <span className="muted preview-note">Setoran bernilai positif, penarikan negatif. Paling banyak {MAX_ITEMS} baris per permintaan.</span>
      </div>

      <Field
        label="Tempel dari spreadsheet"
        hint="Satu baris per baris rekening koran, tiga kolom dipisah tab (salin dari spreadsheet) atau titik koma: tanggal (TTTT-BB-HH), keterangan, jumlah. Contoh jumlah: 1.250.000,50 atau -25000."
        error={built.tooMany ? `Terlalu banyak baris: paling banyak ${MAX_ITEMS} baris per permintaan.` : undefined}
      >
        {(p) => <textarea className="textarea mono" rows={5} spellCheck={false} placeholder={PLACEHOLDER} value={paste} onChange={(e) => onPaste(e.target.value)} {...p} />}
      </Field>
      {paste.trim() !== '' && built.problems.length === 0 && <p className="muted" role="status">{built.pastedCount} baris terbaca dari teks yang ditempel.</p>}
      {built.problems.length > 0 && (
        <Banner tone="bad">
          <strong>{built.problems.length} baris tempelan tidak dapat dibaca. Perbaiki sebelum menyimpan.</strong>
          <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
            {built.problems.map((problem) => <li key={problem.line}>Baris {problem.line}: {problem.message}</li>)}
          </ul>
        </Banner>
      )}
    </div>
  )
}
