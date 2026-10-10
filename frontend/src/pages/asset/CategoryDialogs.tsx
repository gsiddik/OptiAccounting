import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { API } from '../../lib/operational'
import { assetMethodLabels, residualTypeLabels, startPolicyLabels } from '../../lib/oa4Labels'
import { useAccounts } from '../accounting/data'
import { fieldMessage, useAct } from '../operational/payables/messages'
import { accountOptions } from './data'
import { buildCategoryBody, categoryFormFrom, emptyCategoryForm, type CategoryForm as Form } from './payload'
import { ASSET_METHODS, RESIDUAL_TYPES, START_POLICIES, type AssetCategory } from './types'

/** Create or edit a category: its depreciation defaults and the optional accounts it overrides the role mapping with. The code is fixed once created. */
export function CategoryForm({ category, onClose, onDone }: { category: AssetCategory | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const accounts = useAccounts()
  const [problem, setProblem] = useState<string | null>(null)
  const [f, setF] = useState<Form>(() => (category ? categoryFormFrom(category) : emptyCategoryForm()))
  const set = <K extends keyof Form>(key: K) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [key]: e.target.value }))

  const current = [category?.asset_account, category?.accumulated_account, category?.expense_account, category?.gain_loss_account]
  const assetOptions = accountOptions(accounts.accounts, ['ASSET'], current.slice(0, 2))
  const expenseOptions = accountOptions(accounts.accounts, ['EXPENSE'], [category?.expense_account])
  const gainLossOptions = accountOptions(accounts.accounts, ['REVENUE', 'EXPENSE'], [category?.gain_loss_account])
  const accountHint = accounts.error ? 'Daftar akun tidak dapat dimuat (butuh izin melihat bagan akun).' : 'Kosong berarti mengikuti pemetaan peran akun.'
  const noLife = f.default_method === 'NONE'

  async function submit() {
    const built = buildCategoryBody(f, category === null)
    if ('problem' in built) return setProblem(built.problem)
    setProblem(null)
    const r = await run(async () => (category ? await api.patch(`${API}/asset-categories/${category.id}`, built.body) : await api.post(`${API}/asset-categories`, built.body)).data)
    if (r.ok) onDone()
  }

  const accountField = (key: 'asset_account_id' | 'accumulated_account_id' | 'expense_account_id' | 'gain_loss_account_id', label: string, options: { id: string; label: string }[]) => (
    <Field label={label} error={fieldMessage(error, key)} hint={accountHint}>
      {(p) => (
        <select className="select" value={f[key]} onChange={set(key)} {...p}>
          <option value="">Ikuti pemetaan peran akun</option>
          {options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
        </select>
      )}
    </Field>
  )

  return (
    <FormModal title={category ? `Ubah kategori ${category.code}` : 'Kategori aset baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Kode" error={fieldMessage(error, 'code')} hint={category ? 'Kode tidak dapat diubah.' : 'Huruf, angka, garis bawah, atau strip.'}>
          {(p) => <input className="input mono" required maxLength={30} disabled={!!category} value={f.code} onChange={set('code')} {...p} />}
        </Field>
        <Field label="Nama" error={fieldMessage(error, 'name')}>{(p) => <input className="input" required maxLength={150} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Keterangan" error={fieldMessage(error, 'description')} full>{(p) => <input className="input" maxLength={500} value={f.description} onChange={set('description')} {...p} />}</Field>

        <Field label="Metode penyusutan default" error={fieldMessage(error, 'default_method')}>
          {(p) => (
            <select className="select" value={f.default_method} onChange={set('default_method')} {...p}>
              {ASSET_METHODS.map((m) => <option key={m} value={m}>{assetMethodLabels[m]}</option>)}
            </select>
          )}
        </Field>
        <Field label="Umur manfaat default (bulan)" error={fieldMessage(error, 'default_useful_life_months')} hint={noLife ? 'Aset yang tidak disusutkan tidak memakai umur manfaat.' : 'Antara 1 dan 1200 bulan; kosong berarti diisi per aset.'}>
          {(p) => <input className="input" inputMode="numeric" autoComplete="off" disabled={noLife} value={noLife ? '' : f.default_useful_life_months} onChange={set('default_useful_life_months')} {...p} />}
        </Field>
        <Field label="Kebijakan nilai sisa" error={fieldMessage(error, 'default_residual_type')}>
          {(p) => (
            <select className="select" value={f.default_residual_type} onChange={set('default_residual_type')} {...p}>
              {RESIDUAL_TYPES.map((t) => <option key={t} value={t}>{residualTypeLabels[t]}</option>)}
            </select>
          )}
        </Field>
        <Field
          label={f.default_residual_type === 'PERCENT' ? 'Nilai sisa default (persen)' : 'Nilai sisa default (jumlah)'}
          error={fieldMessage(error, 'default_residual_value')}
          hint={f.default_residual_type === 'NONE' ? 'Pilih kebijakan nilai sisa terlebih dahulu.' : f.default_residual_type === 'PERCENT' ? 'Persentase dari harga perolehan, paling besar 100.' : 'Jumlah tetap per aset, contoh 1000000 atau 1000000,50.'}
        >
          {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" disabled={f.default_residual_type === 'NONE'} value={f.default_residual_type === 'NONE' ? '' : f.default_residual_value} onChange={set('default_residual_value')} {...p} />}
        </Field>
        <Field label="Mulai disusutkan" error={fieldMessage(error, 'default_start_policy')} full>
          {(p) => (
            <select className="select" value={f.default_start_policy} onChange={set('default_start_policy')} {...p}>
              {START_POLICIES.map((s) => <option key={s} value={s}>{startPolicyLabels[s]}</option>)}
            </select>
          )}
        </Field>

        <p className="field full muted">Akun di bawah ini opsional. Aset yang sudah dikapitalisasi membekukan akunnya sendiri, sehingga mengubah kategori tidak menggeser aset yang sudah berjalan.</p>
        {accountField('asset_account_id', 'Akun aset tetap', assetOptions)}
        {accountField('accumulated_account_id', 'Akun akumulasi penyusutan', assetOptions)}
        {accountField('expense_account_id', 'Akun beban penyusutan', expenseOptions)}
        {accountField('gain_loss_account_id', 'Akun laba/rugi pelepasan', gainLossOptions)}
        {problem && <p className="field full"><span className="error" role="alert">{problem}</span></p>}
      </div>
    </FormModal>
  )
}

export function CategoryStatusDialog({ category, onClose, onDone }: { category: AssetCategory; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const activating = category.status !== 'ACTIVE'
  return (
    <ConfirmDialog
      title={activating ? 'Aktifkan kategori' : 'Nonaktifkan kategori'}
      confirmLabel={activating ? 'Aktifkan' : 'Nonaktifkan'}
      danger={!activating}
      busy={busy}
      error={error}
      message={activating ? `Aktifkan kembali ${category.code} · ${category.name}?` : `${category.code} · ${category.name} tidak dapat dipilih untuk aset baru. Aset yang sudah ada tetap utuh.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`${API}/asset-categories/${category.id}/${activating ? 'activate' : 'deactivate'}`))
        if (r.ok) onDone()
      }}
    />
  )
}

export function CategoryDeleteDialog({ category, onClose, onDone }: { category: AssetCategory; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  return (
    <ConfirmDialog
      title="Hapus kategori"
      confirmLabel="Hapus"
      danger
      busy={busy}
      error={error}
      message={`Hapus ${category.code} · ${category.name}? Hanya kategori yang belum dipakai aset yang dapat dihapus; yang sudah dipakai harus dinonaktifkan.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`${API}/asset-categories/${category.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
