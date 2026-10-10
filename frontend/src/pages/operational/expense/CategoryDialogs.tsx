import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../../components/Modal'
import { Field } from '../../../components/ui'
import { api } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { useAction, useResource } from '../../../lib/hooks'
import type { ExpenseCategory } from '../../../lib/operational'
import { accountLabel, useAccounts } from '../../accounting/data'
import { fieldMessage, localized } from './errors'

type Destination = 'ACCOUNT' | 'ROLE' | 'RULE'
type RoleOption = { code: string; name: string; binding?: string }

/**
 * Account roles a category may name, read from the API's own role catalog (it needs the permission to view account mappings). Only roles
 * resolved through a tenant mapping qualify; whether a role may classify costs is decided by the API when the category is saved.
 */
function useDestinationRoles(): RoleOption[] {
  const { can } = useCapabilities()
  const allowed = can('accounting.account_mapping.view')
  const roles = useResource(async () => (allowed ? (await api.get<{ roles: RoleOption[] }>('/app/accounting/account-mappings')).data.roles : []), [allowed])
  return (roles.data ?? []).filter((r) => r.binding === undefined || r.binding === 'MAPPED')
}

/** Create or edit a category. The code is fixed after creation. A category names an account OR an account role, never both. */
export function CategoryForm({ category, onClose, onDone }: { category: ExpenseCategory | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const accounts = useAccounts()
  const roles = useDestinationRoles()
  const [problem, setProblem] = useState<string | null>(null)
  const [f, setF] = useState({
    code: category?.code ?? '',
    name: category?.name ?? '',
    description: category?.description ?? '',
    destination: (category?.account_id ? 'ACCOUNT' : category?.account_role ? 'ROLE' : 'RULE') as Destination,
    account_id: category?.account_id ?? '',
    account_role: category?.account_role ?? '',
  })
  const set = (k: 'code' | 'name' | 'description') => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  const selectable = accounts.accounts.filter((a) => a.id === f.account_id || (a.status === 'ACTIVE' && a.is_postable && !a.is_control && (a.account_type === 'EXPENSE' || a.account_type === 'ASSET')))
  const roleOptions = category?.account_role && roles.every((r) => r.code !== category.account_role) ? [...roles, { code: category.account_role, name: category.account_role }] : roles
  const err = localized(error)

  async function submit() {
    setProblem(null)
    if (f.destination === 'ACCOUNT' && !f.account_id) return setProblem('Pilih akun tujuan.')
    if (f.destination === 'ROLE' && !f.account_role) return setProblem('Pilih peran akun.')
    // Both keys are always sent and exactly one of them carries a value, so the API never sees an ambiguous destination.
    const body = {
      name: f.name.trim(),
      description: f.description.trim() || null,
      account_id: f.destination === 'ACCOUNT' ? f.account_id : null,
      account_role: f.destination === 'ROLE' ? f.account_role : null,
    }
    const r = await run(() => (category ? api.patch(`/app/accounting/expense-categories/${category.id}`, body) : api.post('/app/accounting/expense-categories', { ...body, code: f.code.trim() })))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={category ? `Ubah kategori ${category.code}` : 'Kategori beban baru'} busy={busy} error={err} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Kode" error={fieldMessage(err, 'code')} hint={category ? 'Kode tidak dapat diubah.' : 'Huruf, angka, titik, garis bawah, atau strip.'}>
          {(p) => <input className="input mono" required maxLength={30} disabled={!!category} value={f.code} onChange={set('code')} {...p} />}
        </Field>
        <Field label="Nama" error={fieldMessage(err, 'name')}>{(p) => <input className="input" required maxLength={150} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Keterangan" error={fieldMessage(err, 'description')} full>{(p) => <input className="input" maxLength={255} value={f.description} onChange={set('description')} {...p} />}</Field>

        <fieldset className="field full">
          <legend className="label">Tujuan klasifikasi biaya</legend>
          <label className="check"><input type="radio" name="destination" checked={f.destination === 'ACCOUNT'} onChange={() => setF((s) => ({ ...s, destination: 'ACCOUNT' }))} /> Akun tertentu</label>
          <label className="check"><input type="radio" name="destination" checked={f.destination === 'ROLE'} disabled={roleOptions.length === 0} onChange={() => setF((s) => ({ ...s, destination: 'ROLE' }))} /> Peran akun (dipetakan tenant)</label>
          <label className="check"><input type="radio" name="destination" checked={f.destination === 'RULE'} onChange={() => setF((s) => ({ ...s, destination: 'RULE' }))} /> Ikuti aturan posting (tanpa tujuan khusus)</label>
          <span className="hint">Kategori memilih satu akun atau satu peran akun, bukan keduanya. Akun dan peran dikunci saat beban diposting.</span>
        </fieldset>

        {f.destination === 'ACCOUNT' && (
          <Field label="Akun tujuan" full error={fieldMessage(err, 'account_id')} hint={accounts.error ? 'Daftar akun tidak dapat dimuat (butuh izin melihat bagan akun).' : 'Akun beban atau aset yang aktif, dapat diposting, dan bukan akun kontrol.'}>
            {(p) => (
              <select className="select" value={f.account_id} onChange={(e) => setF((s) => ({ ...s, account_id: e.target.value }))} {...p}>
                <option value="">Pilih akun…</option>
                {selectable.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
              </select>
            )}
          </Field>
        )}
        {f.destination === 'ROLE' && (
          <Field label="Peran akun" full error={fieldMessage(err, 'account_role')}>
            {(p) => (
              <select className="select" value={f.account_role} onChange={(e) => setF((s) => ({ ...s, account_role: e.target.value }))} {...p}>
                <option value="">Pilih peran…</option>
                {roleOptions.map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}
              </select>
            )}
          </Field>
        )}
        {problem && <p className="field full"><span className="error" role="alert">{problem}</span></p>}
      </div>
    </FormModal>
  )
}

export function CategoryStatusDialog({ category, onClose, onDone }: { category: ExpenseCategory; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const next = category.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  return (
    <ConfirmDialog
      title={next === 'ACTIVE' ? 'Aktifkan kategori' : 'Nonaktifkan kategori'}
      confirmLabel={next === 'ACTIVE' ? 'Aktifkan' : 'Nonaktifkan'}
      danger={next === 'INACTIVE'}
      busy={busy}
      error={localized(error)}
      message={next === 'ACTIVE' ? `Aktifkan kembali ${category.code} · ${category.name}?` : `${category.code} · ${category.name} tidak dapat dipakai untuk beban baru. Beban yang sudah ada tetap utuh.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`/app/accounting/expense-categories/${category.id}/status`, { status: next }))
        if (r.ok) onDone()
      }}
    />
  )
}

export function CategoryDeleteDialog({ category, onClose, onDone }: { category: ExpenseCategory; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  return (
    <ConfirmDialog
      title="Hapus kategori"
      confirmLabel="Hapus"
      danger
      busy={busy}
      error={localized(error)}
      message={`Hapus ${category.code} · ${category.name}? Hanya kategori yang belum pernah dipakai yang dapat dihapus; yang sudah dipakai harus dinonaktifkan.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`/app/accounting/expense-categories/${category.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
