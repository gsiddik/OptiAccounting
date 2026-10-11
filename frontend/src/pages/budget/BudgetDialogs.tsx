import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { API } from '../../lib/operational'
import { useCalendar } from '../accounting/data'
import { fieldMessage, useAct } from '../operational/payables/messages'
import type { Budget, VersionRef } from './types'

/** Create or edit a budget. The code, the fiscal year and the currency are fixed once the budget exists. */
export function BudgetForm({ budget, onClose, onDone }: { budget: Budget | null; onClose: () => void; onDone: (saved: Budget) => void }) {
  const { busy, error, run } = useAct()
  const calendar = useCalendar()
  const [f, setF] = useState({ code: budget?.code ?? '', name: budget?.name ?? '', description: budget?.description ?? '', fiscal_year_id: budget?.fiscal_year_id ?? '' })
  const set = (k: 'code' | 'name' | 'description' | 'fiscal_year_id') => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const years = calendar.years.filter((y) => y.status !== 'CLOSED' || y.id === f.fiscal_year_id)

  async function submit() {
    const body = { name: f.name.trim(), description: f.description.trim() || null }
    const r = await run(async () => (budget ? await api.patch<Budget>(`${API}/budgets/${budget.id}`, body) : await api.post<Budget>(`${API}/budgets`, { ...body, code: f.code.trim(), fiscal_year_id: f.fiscal_year_id })).data)
    if (r.ok) onDone(r.value)
  }

  return (
    <FormModal title={budget ? `Ubah anggaran ${budget.code}` : 'Anggaran baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Kode" error={fieldMessage(error, 'code')} hint={budget ? 'Kode tidak dapat diubah.' : 'Huruf, angka, titik, garis bawah, atau strip.'}>
          {(p) => <input className="input mono" required maxLength={30} disabled={!!budget} value={f.code} onChange={set('code')} {...p} />}
        </Field>
        <Field label="Nama" error={fieldMessage(error, 'name')}>{(p) => <input className="input" required maxLength={150} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Tahun fiskal" error={fieldMessage(error, 'fiscal_year_id')} hint={calendar.error ? 'Daftar tahun fiskal tidak dapat dimuat (butuh izin melihat periode akuntansi).' : budget ? 'Tahun fiskal tidak dapat diubah.' : 'Satu anggaran untuk satu tahun fiskal.'}>
          {(p) => (
            <select className="select" required disabled={!!budget} value={f.fiscal_year_id} onChange={set('fiscal_year_id')} {...p}>
              <option value="">Pilih tahun fiskal…</option>
              {budget && !years.some((y) => y.id === budget.fiscal_year_id) && <option value={budget.fiscal_year_id}>{budget.fiscal_year?.name ?? budget.fiscal_year_id}</option>}
              {years.map((y) => <option key={y.id} value={y.id}>{y.code} · {y.name}</option>)}
            </select>
          )}
        </Field>
        <Field label="Keterangan" error={fieldMessage(error, 'description')} full>
          {(p) => <textarea className="textarea" maxLength={500} value={f.description} onChange={set('description')} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}

/** A new draft version, empty or copied from an earlier one (a revision). */
export function VersionForm({ budget, versions, onClose, onDone }: { budget: Budget; versions: VersionRef[]; onClose: () => void; onDone: (versionId: string) => void }) {
  const { busy, error, run } = useAct()
  const [f, setF] = useState({ label: '', description: '', copy_from_version_id: '' })
  const copyable = versions.filter((v) => v.status !== 'CANCELLED')

  async function submit() {
    const body = { label: f.label.trim() || null, description: f.description.trim() || null, copy_from_version_id: f.copy_from_version_id || null }
    const r = await run(async () => (await api.post<{ id: string }>(`${API}/budgets/${budget.id}/versions`, body)).data)
    if (r.ok) onDone(r.value.id)
  }

  return (
    <FormModal title={`Versi baru untuk ${budget.code}`} submitLabel="Buat versi" busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Label" error={fieldMessage(error, 'label')} hint="Mis. Original atau Revisi 1. Kosongkan untuk label otomatis.">
          {(p) => <input className="input" maxLength={100} value={f.label} onChange={(e) => setF((s) => ({ ...s, label: e.target.value }))} {...p} />}
        </Field>
        <Field label="Salin dari versi" error={fieldMessage(error, 'copy_from_version_id')} hint="Revisi dimulai dari baris versi sebelumnya; versi lama tidak berubah.">
          {(p) => (
            <select className="select" value={f.copy_from_version_id} onChange={(e) => setF((s) => ({ ...s, copy_from_version_id: e.target.value }))} {...p}>
              <option value="">Mulai kosong</option>
              {copyable.map((v) => <option key={v.id} value={v.id}>Versi {v.version_number} · {v.label}</option>)}
            </select>
          )}
        </Field>
        <Field label="Keterangan" error={fieldMessage(error, 'description')} full>
          {(p) => <textarea className="textarea" maxLength={500} value={f.description} onChange={(e) => setF((s) => ({ ...s, description: e.target.value }))} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}

/** Open, close or cancel a budget. Cancelling needs a reason (the API records it in the audit trail). */
export function BudgetStatusDialog({ budget, action, onClose, onDone }: { budget: Budget; action: 'open' | 'close' | 'cancel'; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const copy = {
    open: { title: 'Buka anggaran', label: 'Buka', message: `Anggaran ${budget.code} menjadi aktif. Setelah itu versi yang sudah disetujui dapat diaktifkan sehingga laporan Anggaran vs Aktual memakainya.` },
    close: { title: 'Tutup anggaran', label: 'Tutup anggaran', message: `Anggaran ${budget.code} ditutup: angkanya tetap dapat dilaporkan, tetapi tidak ada versi yang dapat ditambah atau diaktifkan.` },
    cancel: { title: 'Batalkan anggaran', label: 'Batalkan anggaran', message: `Anggaran ${budget.code} dibatalkan beserta versi yang masih berupa draf. Anggaran yang pernah memiliki versi disetujui tidak dapat dibatalkan, hanya ditutup.` },
  }[action]
  return (
    <ConfirmDialog
      title={copy.title}
      confirmLabel={copy.label}
      danger={action !== 'open'}
      reasonRequired={action === 'cancel'}
      busy={busy}
      error={error}
      message={copy.message}
      onClose={onClose}
      onConfirm={async (reason) => {
        const r = await run(() => api.post(`${API}/budgets/${budget.id}/${action}`, action === 'cancel' ? { reason } : undefined))
        if (r.ok) onDone()
      }}
    />
  )
}
