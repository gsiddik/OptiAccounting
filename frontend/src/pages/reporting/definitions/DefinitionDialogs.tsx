import { useState } from 'react'
import { Link } from 'react-router-dom'
import { FormModal, Modal } from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { Badge, Banner, Button, Field } from '../../../components/ui'
import { api } from '../../../lib/api'
import { fieldError } from '../../../lib/forms'
import { useAction } from '../../../lib/hooks'
import { RefusalFindings } from './components'
import { ENDPOINTS, PATHS } from './data'
import { definitionError, refusalFindings, skippedTexts, statementLabels } from './labels'
import { STATEMENT_TYPES, type DefaultsResult, type DefinitionDetail, type DefinitionHeader, type StatementType } from './types'

/** A new empty draft of one statement type. The first version of a type fixes the code of every later version. */
export function CreateDefinitionDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [f, setF] = useState<{ statement_type: StatementType; name: string; description: string }>({ statement_type: 'BALANCE_SHEET', name: '', description: '' })
  const shown = definitionError(error)

  async function submit() {
    const r = await run(async () => (await api.post<DefinitionDetail>(ENDPOINTS.list, { statement_type: f.statement_type, name: f.name.trim(), description: f.description.trim() || null })).data)
    if (r.ok) {
      toast.success('Draf definisi dibuat.')
      onCreated(r.value.id)
    }
  }

  return (
    <FormModal title="Definisi laporan baru" submitLabel="Buat draf" busy={busy} error={shown} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Jenis laporan" error={fieldError(error, 'statement_type')}>
          {(p) => (
            <select className="select" value={f.statement_type} onChange={(e) => setF((s) => ({ ...s, statement_type: e.target.value as StatementType }))} {...p}>
              {STATEMENT_TYPES.map((t) => <option key={t} value={t}>{statementLabels[t]}</option>)}
            </select>
          )}
        </Field>
        <Field label="Nama" error={fieldError(error, 'name')}>
          {(p) => <input className="input" required maxLength={150} value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} {...p} />}
        </Field>
        <Field label="Keterangan" error={fieldError(error, 'description')} full>
          {(p) => <textarea className="textarea" maxLength={500} value={f.description} onChange={(e) => setF((s) => ({ ...s, description: e.target.value }))} {...p} />}
        </Field>
      </div>
      <p className="muted">Draf baru kosong. Untuk memulai dari tata letak yang sudah ada, buat versi baru dari definisi yang terbit; untuk memulai dari bagan akun, pakai <strong>Terapkan bawaan</strong>.</p>
    </FormModal>
  )
}

/** Name and description of a draft. */
export function RenameDialog({ definition, onClose, onSaved }: { definition: DefinitionHeader; onClose: () => void; onSaved: (detail: DefinitionDetail) => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ name: definition.name, description: definition.description ?? '' })

  async function submit() {
    const r = await run(async () => (await api.patch<DefinitionDetail>(ENDPOINTS.one(definition.id), { name: f.name.trim(), description: f.description.trim() || null })).data)
    if (r.ok) {
      toast.success('Draf disimpan.')
      onSaved(r.value)
    }
  }

  return (
    <FormModal title="Ubah nama dan keterangan" busy={busy} error={definitionError(error)} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Nama" error={fieldError(error, 'name')}>
          {(p) => <input className="input" required maxLength={150} value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} {...p} />}
        </Field>
        <Field label="Keterangan" error={fieldError(error, 'description')} full>
          {(p) => <textarea className="textarea" maxLength={500} value={f.description} onChange={(e) => setF((s) => ({ ...s, description: e.target.value }))} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}

/** Publishing makes the layout final: it is validated again by the server and takes effect from a date. */
export function PublishDialog({ definition, onClose, onPublished }: { definition: DefinitionHeader; onClose: () => void; onPublished: (detail: DefinitionDetail) => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [from, setFrom] = useState('')
  const { errors, warnings } = refusalFindings(error)

  async function submit() {
    const r = await run(async () => (await api.post<DefinitionDetail>(ENDPOINTS.publish(definition.id), from ? { effective_from: from } : {})).data)
    if (r.ok) {
      toast.success('Definisi diterbitkan.')
      onPublished(r.value)
    }
  }

  return (
    <FormModal title={`Terbitkan versi ${definition.version}`} submitLabel="Terbitkan" busy={busy} error={definitionError(error)} onSubmit={() => void submit()} onClose={onClose} wide>
      <RefusalFindings errors={errors} warnings={warnings} />
      <p>
        Setelah diterbitkan, versi ini <strong>final</strong>: baris dan pemetaannya tidak dapat diubah lagi. Revisi dilakukan lewat versi baru. Versi sebelumnya yang masih berlaku berakhir sehari sebelum tanggal berlaku,
        sehingga laporan periode lama tetap memakai tata letaknya semula.
      </p>
      <div className="form-grid">
        <Field label="Berlaku mulai" error={fieldError(error, 'effective_from')} hint="Kosongkan: versi pertama berlaku sejak awal; versi berikutnya berlaku mulai hari ini.">
          {(p) => <input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}

/** Archiving retires a published version without a successor; it keeps serving the dates up to the end date. */
export function ArchiveDialog({ definition, onClose, onArchived }: { definition: DefinitionHeader; onClose: () => void; onArchived: (detail: DefinitionDetail) => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [to, setTo] = useState('')

  async function submit() {
    const r = await run(async () => (await api.post<DefinitionDetail>(ENDPOINTS.archive(definition.id), to ? { effective_to: to } : {})).data)
    if (r.ok) {
      toast.success('Definisi diarsipkan.')
      onArchived(r.value)
    }
  }

  return (
    <FormModal title={`Arsipkan versi ${definition.version}`} submitLabel="Arsipkan" danger busy={busy} error={definitionError(error)} onSubmit={() => void submit()} onClose={onClose} wide>
      <p>
        Versi yang diarsipkan tidak lagi melayani tanggal setelah tanggal berakhir. Tanpa versi pengganti, laporan untuk tanggal itu tidak dapat dibuat sampai ada versi terbit yang berlaku.
        Tata letaknya tetap tersimpan dan melayani tanggal sebelumnya.
      </p>
      <div className="form-grid">
        <Field label="Berlaku sampai" error={fieldError(error, 'effective_to')} hint="Kosongkan: berakhir hari ini (atau tanggal mulai berlaku bila belum tercapai).">
          {(p) => <input className="input" type="date" value={to} onChange={(e) => setTo(e.target.value)} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}

const DEFAULT_TYPES: StatementType[] = [...STATEMENT_TYPES]

/** Builds draft definitions from the tenant's own chart of accounts (account type, hierarchy and roles; never account codes). Nothing is published. */
export function DefaultsDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [types, setTypes] = useState<StatementType[]>(DEFAULT_TYPES)
  const [result, setResult] = useState<DefaultsResult | null>(null)

  async function submit() {
    const r = await run(async () => (await api.post<DefaultsResult>(ENDPOINTS.defaults, { statement_types: types })).data)
    if (!r.ok) return
    setResult(r.value)
    onDone()
    if (r.value.created.length > 0) toast.success(`${r.value.created.length} draf definisi dibuat.`)
  }

  if (result) {
    return (
      <Modal title="Hasil penerapan bawaan" onClose={onClose} wide footer={<Button variant="primary" onClick={onClose}>Selesai</Button>}>
        <div className="modal-body stack">
          {result.created.length === 0 && <Banner tone="info">Tidak ada draf baru yang dibuat.</Banner>}
          {result.created.map((d) => (
            <div key={d.id}>
              <Link to={PATHS.detail(d.id)}>{statementLabels[d.statement_type]} · {d.name}</Link>
              {d.validation && <> <Badge tone={d.validation.valid ? 'ok' : 'bad'}>{d.validation.valid ? 'Valid' : `${d.validation.errors.length} kesalahan`}</Badge></>}
              <div className="muted">Draf versi {d.version}. Tinjau barisnya dan petakan akun yang tersisa sebelum menerbitkan.</div>
            </div>
          ))}
          {result.skipped.map((s) => (
            <div key={`${s.statement_type}-${s.code}`}>
              <strong>{statementLabels[s.statement_type]}</strong> dilewati
              <div className="muted">{skippedTexts[s.code] ?? s.message}{s.definition_id && <> <Link to={PATHS.detail(s.definition_id)}>Buka definisi</Link></>}</div>
            </div>
          ))}
        </div>
      </Modal>
    )
  }

  const toggle = (t: StatementType) => setTypes((s) => (s.includes(t) ? s.filter((x) => x !== t) : [...s, t]))
  return (
    <FormModal title="Terapkan definisi bawaan" submitLabel="Terapkan" busy={busy} error={definitionError(error)} onSubmit={() => { if (types.length > 0) void submit() }} onClose={onClose} wide>
      <p>
        Membuat draf neraca, laba rugi, dan arus kas dari bagan akun organisasi ini (tipe akun, hierarki, dan peran akun). Jenis laporan yang sudah memiliki definisi dilewati.
        Hasilnya hanya draf: tinjau, lengkapi pemetaannya, lalu terbitkan sendiri.
      </p>
      <fieldset className="stack" style={{ border: 0, padding: 0, margin: 0 }}>
        <legend className="sr-only">Jenis laporan</legend>
        {STATEMENT_TYPES.map((t) => (
          <label key={t} className="check"><input type="checkbox" checked={types.includes(t)} onChange={() => toggle(t)} /> <span>{statementLabels[t]}</span></label>
        ))}
      </fieldset>
      {types.length === 0 && <p className="muted">Pilih minimal satu jenis laporan.</p>}
    </FormModal>
  )
}
