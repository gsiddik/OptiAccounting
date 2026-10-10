import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../../components/Modal'
import { Field } from '../../../components/ui'
import { api } from '../../../lib/api'
import { useAction } from '../../../lib/hooks'
import { API } from '../../../lib/operational'
import { cashKindLabels } from '../../../lib/operationalLabels'
import { accountLabel, useAccounts, useDimensions } from '../../accounting/data'
import { fieldMessage, localized } from '../expense/errors'
import { DimensionFields } from '../shared'
import type { CashAccount } from './types'

type Kind = 'CASH' | 'BANK'

/**
 * Create or edit a cash/bank account. The code and the kind are fixed after creation (the API refuses them on update). The account number is
 * write-only: it is sent when typed and never shown again; the API keeps and returns only the masked value.
 */
export function AccountForm({ account, onClose, onDone }: { account: CashAccount | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const gl = useAccounts()
  const { catalog } = useDimensions()
  const [problem, setProblem] = useState<string | null>(null)
  const [f, setF] = useState({
    code: account?.code ?? '',
    kind: (account?.kind ?? 'CASH') as Kind,
    name: account?.name ?? '',
    account_id: account?.account_id ?? '',
    bank_name: account?.bank_name ?? '',
    account_holder: account?.account_holder ?? '',
    account_number: '',
    notes: account?.notes ?? '',
    branch_id: account?.branch_id ?? '',
    business_unit_id: account?.business_unit_id ?? '',
    cost_center_id: '',
  })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const err = localized(error)
  const bank = f.kind === 'BANK'

  // Postable, non-control asset accounts. The API additionally refuses a GL account that another active cash/bank account already uses.
  const selectable = gl.accounts.filter((a) => a.id === f.account_id || (a.status === 'ACTIVE' && a.is_postable && !a.is_control && a.account_type === 'ASSET'))

  async function submit() {
    setProblem(null)
    if (!f.account_id) return setProblem('Pilih akun buku besar.')
    const common = { name: f.name.trim(), notes: f.notes.trim() || null, branch_id: f.branch_id || null, business_unit_id: f.business_unit_id || null }
    const bankFields = bank
      ? { bank_name: f.bank_name.trim(), account_holder: f.account_holder.trim() || null, ...(f.account_number.trim() && { account_number: f.account_number.trim() }) }
      : {}
    const r = await run(() => (account
      ? api.patch(`${API}/cash-bank-accounts/${account.id}`, { ...common, ...bankFields, ...(f.account_id !== account.account_id && { account_id: f.account_id }) })
      : api.post(`${API}/cash-bank-accounts`, { ...common, ...bankFields, code: f.code.trim(), kind: f.kind, account_id: f.account_id })))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={account ? `Ubah akun ${account.code}` : 'Akun kas/bank baru'} busy={busy} error={err} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Kode" error={fieldMessage(err, 'code')} hint={account ? 'Kode tidak dapat diubah.' : 'Huruf, angka, titik, garis bawah, atau strip.'}>
          {(p) => <input className="input mono" required maxLength={30} disabled={!!account} value={f.code} onChange={set('code')} {...p} />}
        </Field>
        <Field label="Jenis" error={fieldMessage(err, 'kind')} hint={account ? 'Jenis tidak dapat diubah.' : undefined}>
          {(p) => (
            <select className="select" disabled={!!account} value={f.kind} onChange={set('kind')} {...p}>
              {Object.entries(cashKindLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          )}
        </Field>
        <Field label="Nama" error={fieldMessage(err, 'name')} full>{(p) => <input className="input" required maxLength={150} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Akun buku besar" error={fieldMessage(err, 'account_id')} full hint={gl.error ? 'Daftar akun tidak dapat dimuat (butuh izin melihat bagan akun).' : 'Akun aset yang aktif, dapat diposting, dan bukan akun kontrol. Setiap akun kas/bank aktif memakai akun yang berbeda. Tidak dapat diganti setelah akun dipakai dokumen.'}>
          {(p) => (
            <select className="select" value={f.account_id} onChange={set('account_id')} {...p}>
              <option value="">Pilih akun…</option>
              {selectable.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
            </select>
          )}
        </Field>
        <Field label="Mata uang" hint="Mengikuti mata uang fungsional profil akuntansi dan tidak dapat diubah.">
          {(p) => <input className="input" disabled value={account?.currency ?? ''} placeholder="Mengikuti profil akuntansi" {...p} />}
        </Field>

        {bank && (
          <>
            <Field label="Nama bank" error={fieldMessage(err, 'bank_name')}>{(p) => <input className="input" required maxLength={100} value={f.bank_name} onChange={set('bank_name')} {...p} />}</Field>
            <Field label="Pemilik rekening" error={fieldMessage(err, 'account_holder')}>{(p) => <input className="input" maxLength={150} value={f.account_holder} onChange={set('account_holder')} {...p} />}</Field>
            <Field
              label="Nomor rekening"
              error={fieldMessage(err, 'account_number')}
              hint={account?.account_number_masked ? `Tersimpan: ${account.account_number_masked}. Isi hanya untuk menggantinya; nomor lengkap tidak pernah ditampilkan lagi.` : 'Hanya empat karakter terakhir yang disimpan dan ditampilkan.'}
              full
            >
              {(p) => <input className="input mono" maxLength={40} autoComplete="off" value={f.account_number} onChange={set('account_number')} {...p} />}
            </Field>
          </>
        )}

        <DimensionFields catalog={{ ...catalog, cost_centers: [] }} value={f} onChange={(patch) => setF((s) => ({ ...s, ...patch }))} error={err} />
        <Field label="Catatan" error={fieldMessage(err, 'notes')} full>{(p) => <input className="input" maxLength={500} value={f.notes} onChange={set('notes')} {...p} />}</Field>
        {problem && <p className="field full"><span className="error" role="alert">{problem}</span></p>}
      </div>
    </FormModal>
  )
}

export function AccountStatusDialog({ account, onClose, onDone }: { account: CashAccount; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const next = account.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  return (
    <ConfirmDialog
      title={next === 'ACTIVE' ? 'Aktifkan akun' : 'Nonaktifkan akun'}
      confirmLabel={next === 'ACTIVE' ? 'Aktifkan' : 'Nonaktifkan'}
      danger={next === 'INACTIVE'}
      busy={busy}
      error={localized(error)}
      message={next === 'ACTIVE' ? `Aktifkan kembali ${account.code} · ${account.name}?` : `${account.code} · ${account.name} tidak dapat dipakai untuk dokumen baru. Riwayat dan saldonya tetap utuh.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`${API}/cash-bank-accounts/${account.id}/status`, { status: next }))
        if (r.ok) onDone()
      }}
    />
  )
}

export function AccountDeleteDialog({ account, onClose, onDone }: { account: CashAccount; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  return (
    <ConfirmDialog
      title="Hapus akun kas/bank"
      confirmLabel="Hapus"
      danger
      busy={busy}
      error={localized(error)}
      message={`Hapus ${account.code} · ${account.name}? Hanya akun yang belum pernah dipakai dokumen yang dapat dihapus; yang sudah dipakai harus dinonaktifkan.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`${API}/cash-bank-accounts/${account.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
