import { useMemo, useState } from 'react'
import { FormModal, Modal } from '../../../components/Modal'
import { Banner, Button, EmptyState, ErrorNotice, Field, Loading } from '../../../components/ui'
import { api } from '../../../lib/api'
import { fieldError } from '../../../lib/forms'
import { formatDate } from '../../../lib/format'
import { useAction, useResource } from '../../../lib/hooks'
import { API } from '../../../lib/operational'
import { Money } from '../shared'
import { plainAmount } from './amounts'
import { buildLines, checkLine, emptyRow, type RowDraft, type RowErrors } from './lines'
import { DirectionAmount } from './parts'
import { StatementLines } from './StatementLines'
import type { BankStatement, Candidate, StatementItem } from './types'

const statementPath = (statement: BankStatement) => `${API}/bank-statements/${statement.id}`

// ------------------------------------------------------------------------------------------------ add lines

/** Add lines to an OPEN statement: typed rows and/or text pasted from a spreadsheet. The API answers with the whole statement. */
export function AddLinesModal({ statement, onClose, onSaved }: { statement: BankStatement; onClose: () => void; onSaved: (saved: BankStatement) => void }) {
  const action = useAction()
  const [rows, setRows] = useState<RowDraft[]>(() => [emptyRow()])
  const [paste, setPaste] = useState('')
  const [submitted, setSubmitted] = useState(false)
  const built = useMemo(() => buildLines(rows, paste), [rows, paste])

  async function submit() {
    setSubmitted(true)
    if (!built.ready || built.items.length === 0) return
    const r = await action.run(async () => (await api.post<BankStatement>(`${statementPath(statement)}/items`, { items: built.items })).data)
    if (r.ok) onSaved(r.value)
  }

  return (
    <FormModal title="Tambah baris rekening koran" submitLabel="Simpan baris" busy={action.busy} error={action.error} onSubmit={() => void submit()} onClose={onClose} wide>
      {submitted && built.ready && built.items.length === 0 && <Banner tone="warn">Isi sedikitnya satu baris atau tempel dari spreadsheet.</Banner>}
      <StatementLines rows={rows} onRows={setRows} paste={paste} onPaste={setPaste} built={built} showErrors={submitted} serverError={action.error} />
    </FormModal>
  )
}

// ------------------------------------------------------------------------------------------------ edit a line

/** Edit one UNMATCHED or EXCEPTION line (a matched line must be unmatched first; the API refuses otherwise). */
export function EditItemModal({ statement, item, onClose, onSaved }: { statement: BankStatement; item: StatementItem; onClose: () => void; onSaved: (saved: BankStatement) => void }) {
  const action = useAction()
  const [f, setF] = useState({ item_date: item.item_date, description: item.description, reference: item.reference ?? '', amount: plainAmount(item.amount) })
  const [local, setLocal] = useState<RowErrors>({})
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const errorOf = (name: keyof typeof f) => local[name] ?? fieldError(action.error, name)

  async function submit() {
    const { item: payload, errors } = checkLine(f)
    setLocal(errors)
    if (!payload) return
    const r = await action.run(async () => (await api.patch<BankStatement>(`${statementPath(statement)}/items/${item.id}`, payload)).data)
    if (r.ok) onSaved(r.value)
  }

  return (
    <FormModal title={`Ubah baris ${item.line_number}`} busy={action.busy} error={action.error} onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Tanggal" error={errorOf('item_date')}>
          {(p) => <input className="input" type="date" value={f.item_date} onChange={set('item_date')} {...p} />}
        </Field>
        <Field label="Jumlah" error={errorOf('amount')} hint="Setoran positif, penarikan negatif.">
          {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" value={f.amount} onChange={set('amount')} {...p} />}
        </Field>
        <Field label="Keterangan" error={errorOf('description')} full>
          {(p) => <input className="input" maxLength={255} value={f.description} onChange={set('description')} {...p} />}
        </Field>
        <Field label="Referensi" error={errorOf('reference')} full>
          {(p) => <input className="input" maxLength={100} value={f.reference} onChange={set('reference')} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}

// ------------------------------------------------------------------------------------------------ match

/**
 * Pick the posted book line a statement line corresponds to. The API lists the candidates (unmatched, same amount and direction,
 * posted within 30 days); the choice is sent as `journal_line_id` and the API re-checks everything.
 */
export function MatchDialog({ statement, item, onClose, onMatched }: { statement: BankStatement; item: StatementItem; onClose: () => void; onMatched: (saved: BankStatement) => void }) {
  const candidates = useResource(async () => (await api.get<{ data: Candidate[] }>(`${statementPath(statement)}/items/${item.id}/candidates`)).data.data, [statement.id, item.id])
  const action = useAction()
  const [chosen, setChosen] = useState('')

  async function submit() {
    if (!chosen) return
    const r = await action.run(async () => (await api.post<BankStatement>(`${statementPath(statement)}/items/${item.id}/match`, { journal_line_id: chosen })).data)
    if (r.ok) onMatched(r.value)
  }

  const list = candidates.data ?? []
  return (
    <Modal
      title={`Cocokkan baris ${item.line_number}`}
      onClose={onClose}
      wide
      footer={
        <>
          <Button onClick={onClose}>Batal</Button>
          <Button variant="primary" disabled={!chosen} loading={action.busy} onClick={() => void submit()}>Cocokkan</Button>
        </>
      }
    >
      <div className="modal-body">
        <p>
          <strong>{item.description}</strong> · {formatDate(item.item_date)} · <DirectionAmount amount={item.amount} />
        </p>
        <p className="muted">Pilih baris buku yang sudah diposting dengan jumlah dan arah yang sama. Pencocokan hanya menautkan keduanya; tidak ada jurnal yang dibuat atau diubah.</p>
        {action.error != null && <ErrorNotice error={action.error} />}
        {candidates.loading && !candidates.data ? (
          <Loading />
        ) : candidates.error ? (
          <ErrorNotice error={candidates.error} onRetry={candidates.reload} />
        ) : list.length === 0 ? (
          <EmptyState title="Tidak ada baris buku yang cocok">
            Tidak ada baris buku belum-dicocokkan dengan jumlah dan arah yang sama dalam 30 hari dari tanggal baris ini. Bila baris ini memang belum dicatat di buku, tandai sebagai pengecualian.
          </EmptyState>
        ) : (
          <fieldset style={{ border: 0, padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: 10 }}>
            <legend className="sr-only">Baris buku yang dapat dicocokkan</legend>
            {list.map((c) => (
              <label key={c.journal_line_id} className="check" style={{ alignItems: 'flex-start', padding: '10px 12px', border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)' }}>
                <input type="radio" name="candidate" value={c.journal_line_id} checked={chosen === c.journal_line_id} onChange={() => setChosen(c.journal_line_id)} />
                <span>
                  <strong className="mono">{c.journal_number ?? 'Jurnal'}</strong> · {formatDate(c.posting_date)} · <Money value={c.amount} />
                  <span className="muted" style={{ display: 'block' }}>
                    {[c.description, c.reference && `Ref ${c.reference}`, c.days_apart === 0 ? 'tanggal sama' : `${c.days_apart} hari dari tanggal baris`].filter(Boolean).join(' · ')}
                  </span>
                </span>
              </label>
            ))}
          </fieldset>
        )}
      </div>
    </Modal>
  )
}

// ------------------------------------------------------------------------------------------------ exception

/** Flag a statement line that has no book counterpart yet; the reason is required and kept with the line. */
export function ExceptionDialog({ statement, item, onClose, onSaved }: { statement: BankStatement; item: StatementItem; onClose: () => void; onSaved: (saved: BankStatement) => void }) {
  const action = useAction()
  const [notes, setNotes] = useState(item.notes ?? '')
  const [missing, setMissing] = useState(false)

  async function submit() {
    if (notes.trim() === '') {
      setMissing(true)
      return
    }
    setMissing(false)
    const r = await action.run(async () => (await api.post<BankStatement>(`${statementPath(statement)}/items/${item.id}/exception`, { notes: notes.trim() })).data)
    if (r.ok) onSaved(r.value)
  }

  return (
    <FormModal title={`Pengecualian baris ${item.line_number}`} submitLabel="Tandai pengecualian" busy={action.busy} error={action.error} onSubmit={() => void submit()} onClose={onClose}>
      <p>
        <strong>{item.description}</strong> · {formatDate(item.item_date)} · <DirectionAmount amount={item.amount} />
      </p>
      <p className="muted">Gunakan untuk baris rekening koran yang belum ada di buku, misalnya biaya atau bunga bank yang belum dicatat. Baris ini dihitung sebagai pengecualian pada rekonsiliasi; buku besar tidak berubah.</p>
      <Field label="Alasan pengecualian" error={missing ? 'Alasan wajib diisi.' : fieldError(action.error, 'notes')} hint="Dicatat bersama baris dan di audit.">
        {(p) => <textarea className="textarea" maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}
