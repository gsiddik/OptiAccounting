import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, todayIn } from '../../lib/format'
import { useAction } from '../../lib/hooks'
import { API } from '../../lib/operational'
import { accountLabel, useAccounts } from '../accounting/data'
import { useTaxRoleOptions } from './data'
import { fieldMessage, taxError } from './errors'
import { decimalText, taxMethodLabels, taxTreatmentLabels, taxTypeLabels } from './labels'
import { TAX_METHODS, TAX_TREATMENTS, TAX_TYPES, type TaxCode, type TaxMethod, type TaxTreatment, type TaxType } from './types'

type Destination = 'DEFAULT' | 'ROLE' | 'ACCOUNT'

/** Account types a tax of each kind may post to (the API checks it again; this only keeps the account list short). */
const ACCOUNT_TYPES: Record<TaxType, string[]> = { INPUT_TAX: ['ASSET', 'EXPENSE'], OUTPUT_TAX: ['LIABILITY'], WITHHOLDING: ['LIABILITY', 'ASSET'], OTHER: [] }

const businessDate = (tenant: { business_date: string } | null) => tenant?.business_date ?? todayIn()

/**
 * Create or edit a tax code. The code is fixed after creation. Type, method, treatment and recoverability are fixed once a document used the
 * code (`inUse`, known on the detail page; elsewhere the API refuses and says so). A recoverable tax names an account OR an account role,
 * or leaves both empty to take the role that fits its type; a non-recoverable tax is a cost and has no account.
 */
export function TaxCodeForm({ code, inUse, onClose, onDone }: { code: TaxCode | null; inUse?: boolean; onClose: () => void; onDone: (saved: TaxCode) => void }) {
  const { busy, error: raw, run } = useAction()
  const error = taxError(raw, code ? 'update' : undefined)
  const { tenant } = useCapabilities()
  const accounts = useAccounts()
  const roles = useTaxRoleOptions()
  const [problem, setProblem] = useState<string | null>(null)
  const [f, setF] = useState({
    code: code?.code ?? '',
    name: code?.name ?? '',
    description: code?.description ?? '',
    tax_type: (code?.tax_type ?? 'OUTPUT_TAX') as TaxType,
    calculation_method: (code?.calculation_method ?? 'EXCLUSIVE') as TaxMethod,
    treatment: (code?.treatment ?? 'STANDARD') as TaxTreatment,
    is_recoverable: code?.is_recoverable ?? true,
    destination: (code?.account_id ? 'ACCOUNT' : code?.account_role ? 'ROLE' : 'DEFAULT') as Destination,
    account_id: code?.account_id ?? '',
    account_role: code?.account_role ?? '',
    rate: '',
    effective_from: businessDate(tenant),
  })
  const set = (k: 'code' | 'name' | 'description' | 'rate' | 'effective_from') => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  const canBeCost = f.tax_type === 'INPUT_TAX' || f.tax_type === 'OTHER'
  const recoverable = canBeCost ? f.is_recoverable : true
  const structural = !!code && !!inUse
  const types = ACCOUNT_TYPES[f.tax_type]
  const selectable = accounts.accounts.filter((a) => a.id === f.account_id || (a.status === 'ACTIVE' && a.is_postable && !a.is_control && (types.length === 0 || types.includes(a.account_type))))
  const roleOptions = code?.account_role && roles.every((r) => r.code !== code.account_role) ? [...roles, { code: code.account_role, name: code.account_role }] : roles
  const zeroRate = f.treatment !== 'STANDARD'

  async function submit() {
    setProblem(null)
    if (!code && !f.code.trim()) return setProblem('Kode wajib diisi.')
    if (!f.name.trim()) return setProblem('Nama wajib diisi.')
    if (!code && !zeroRate && !decimalText(f.rate)) return setProblem('Tarif wajib diisi.')
    if (!code && !f.effective_from) return setProblem('Tanggal berlaku wajib diisi.')
    if (recoverable) {
      if (f.destination === 'ACCOUNT' && !f.account_id) return setProblem('Pilih akun pajak.')
      if (f.destination === 'ROLE' && !f.account_role) return setProblem('Pilih peran akun.')
      if (f.destination === 'DEFAULT' && f.tax_type === 'OTHER') return setProblem('Pajak berjenis lainnya membutuhkan akun atau peran akun tertentu.')
    }
    // Both destination keys are always sent and at most one carries a value, so the API never sees an ambiguous destination.
    const destination = {
      account_id: recoverable && f.destination === 'ACCOUNT' ? f.account_id : null,
      account_role: recoverable && f.destination === 'ROLE' ? f.account_role : null,
    }
    const base = { name: f.name.trim(), description: f.description.trim() || null, ...destination }
    const r = await run(async () => {
      if (!code) {
        const body = {
          ...base,
          code: f.code.trim().toUpperCase(),
          tax_type: f.tax_type,
          calculation_method: f.calculation_method,
          treatment: f.treatment,
          is_recoverable: recoverable,
          rate: zeroRate ? '0' : decimalText(f.rate),
          effective_from: f.effective_from,
        }
        return (await api.post<TaxCode>(`${API}/tax-codes`, body)).data
      }
      // Only what changed of the fixed-once-used settings goes out, so an untouched form never trips the "already used" refusal.
      const changed: Partial<Record<'tax_type' | 'calculation_method' | 'treatment' | 'is_recoverable', string | boolean>> = {}
      if (f.tax_type !== code.tax_type) changed.tax_type = f.tax_type
      if (f.calculation_method !== code.calculation_method) changed.calculation_method = f.calculation_method
      if (f.treatment !== code.treatment) changed.treatment = f.treatment
      if (recoverable !== code.is_recoverable) changed.is_recoverable = recoverable
      return (await api.patch<TaxCode>(`${API}/tax-codes/${code.id}`, { ...base, ...changed })).data
    })
    if (r.ok) onDone(r.value)
  }

  return (
    <FormModal title={code ? `Ubah kode pajak ${code.code}` : 'Kode pajak baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Kode" error={fieldMessage(error, 'code')} hint={code ? 'Kode tidak dapat diubah.' : 'Huruf, angka, titik, garis bawah, atau strip.'}>
          {(p) => <input className="input mono" required maxLength={30} disabled={!!code} value={f.code} onChange={set('code')} {...p} />}
        </Field>
        <Field label="Nama" error={fieldMessage(error, 'name')}>{(p) => <input className="input" required maxLength={150} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Keterangan" error={fieldMessage(error, 'description')} full>{(p) => <input className="input" maxLength={500} value={f.description} onChange={set('description')} {...p} />}</Field>

        <Field label="Jenis pajak" error={fieldMessage(error, 'tax_type')} hint={structural ? 'Tidak dapat diubah karena kode sudah dipakai dokumen.' : f.tax_type === 'WITHHOLDING' ? 'Kode pemotongan/pemungutan belum dapat dipakai pada dokumen; hanya untuk laporan pajak.' : undefined}>
          {(p) => (
            <select className="select" disabled={structural} value={f.tax_type} onChange={(e) => setF((s) => ({ ...s, tax_type: e.target.value as TaxType, is_recoverable: e.target.value === 'INPUT_TAX' || e.target.value === 'OTHER' ? s.is_recoverable : true }))} {...p}>
              {TAX_TYPES.map((t) => <option key={t} value={t}>{taxTypeLabels[t]}</option>)}
            </select>
          )}
        </Field>
        <Field label="Metode perhitungan" error={fieldMessage(error, 'calculation_method')} hint={structural ? 'Tidak dapat diubah karena kode sudah dipakai dokumen.' : undefined}>
          {(p) => (
            <select className="select" disabled={structural} value={f.calculation_method} onChange={(e) => setF((s) => ({ ...s, calculation_method: e.target.value as TaxMethod }))} {...p}>
              {TAX_METHODS.map((m) => <option key={m} value={m}>{taxMethodLabels[m]}</option>)}
            </select>
          )}
        </Field>
        <Field label="Perlakuan" error={fieldMessage(error, 'treatment')} hint={structural ? 'Tidak dapat diubah karena kode sudah dipakai dokumen.' : 'Tarif nol dan dibebaskan selalu bertarif 0.'}>
          {(p) => (
            <select className="select" disabled={structural} value={f.treatment} onChange={(e) => setF((s) => ({ ...s, treatment: e.target.value as TaxTreatment }))} {...p}>
              {TAX_TREATMENTS.map((t) => <option key={t} value={t}>{taxTreatmentLabels[t]}</option>)}
            </select>
          )}
        </Field>
        <div className="field">
          <span className="label">Pengkreditan</span>
          <label className="check">
            <input type="checkbox" checked={recoverable} disabled={structural || !canBeCost} onChange={(e) => setF((s) => ({ ...s, is_recoverable: e.target.checked }))} />
            Dapat dikreditkan
          </label>
          <span className="hint">{canBeCost ? 'Pajak masukan yang tidak dapat dikreditkan menjadi bagian dari biaya barisnya, tanpa akun pajak.' : 'Pajak keluaran dan pemotongan selalu terutang.'}</span>
          {fieldMessage(error, 'is_recoverable') && <span className="error">{fieldMessage(error, 'is_recoverable')}</span>}
        </div>

        {!code && (
          <>
            <Field label="Tarif (%)" error={fieldMessage(error, 'rate')} hint={zeroRate ? 'Kode tarif nol atau dibebaskan bertarif 0.' : 'Persentase 0 sampai 100, paling banyak enam desimal. Contoh: 11 atau 2,5.'}>
              {(p) => <input className="input amount" inputMode="decimal" required disabled={zeroRate} value={zeroRate ? '0' : f.rate} onChange={set('rate')} {...p} />}
            </Field>
            <Field label="Berlaku mulai" error={fieldMessage(error, 'effective_from')} hint="Tarif ditentukan menurut tanggal pajak dokumen.">
              {(p) => <input className="input" type="date" required value={f.effective_from} onChange={set('effective_from')} {...p} />}
            </Field>
          </>
        )}

        {recoverable && (
          <fieldset className="field full">
            <legend className="label">Tujuan posting pajak</legend>
            <label className="check"><input type="radio" name="tax-destination" checked={f.destination === 'DEFAULT'} disabled={f.tax_type === 'OTHER'} onChange={() => setF((s) => ({ ...s, destination: 'DEFAULT' }))} /> Peran akun bawaan sesuai jenis pajak</label>
            <label className="check"><input type="radio" name="tax-destination" checked={f.destination === 'ROLE'} disabled={roleOptions.length === 0} onChange={() => setF((s) => ({ ...s, destination: 'ROLE' }))} /> Peran akun tertentu (dipetakan tenant)</label>
            <label className="check"><input type="radio" name="tax-destination" checked={f.destination === 'ACCOUNT'} onChange={() => setF((s) => ({ ...s, destination: 'ACCOUNT' }))} /> Akun tertentu</label>
            <span className="hint">Pajak masukan bawaannya Pajak dibayar di muka; pajak keluaran dan pemotongan bawaannya Utang pajak. Akun dikunci pada dokumen saat diposting.</span>
          </fieldset>
        )}
        {recoverable && f.destination === 'ROLE' && (
          <Field label="Peran akun" full error={fieldMessage(error, 'account_role')}>
            {(p) => (
              <select className="select" value={f.account_role} onChange={(e) => setF((s) => ({ ...s, account_role: e.target.value }))} {...p}>
                <option value="">Pilih peran…</option>
                {roleOptions.map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}
              </select>
            )}
          </Field>
        )}
        {recoverable && f.destination === 'ACCOUNT' && (
          <Field label="Akun pajak" full error={fieldMessage(error, 'account_id')} hint={accounts.error ? 'Daftar akun tidak dapat dimuat (butuh izin melihat bagan akun).' : 'Akun yang aktif, dapat diposting, dan bukan akun kontrol.'}>
            {(p) => (
              <select className="select" value={f.account_id} onChange={(e) => setF((s) => ({ ...s, account_id: e.target.value }))} {...p}>
                <option value="">Pilih akun…</option>
                {selectable.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
              </select>
            )}
          </Field>
        )}
        {problem && <p className="field full"><span className="error" role="alert">{problem}</span></p>}
      </div>
    </FormModal>
  )
}

/** A new rate that takes over from a date. The server closes the previous one and refuses a date that reaches back to taxed documents. */
export function TaxRateForm({ code, onClose, onDone }: { code: TaxCode; onClose: () => void; onDone: () => void }) {
  const { busy, error: raw, run } = useAction()
  const error = taxError(raw)
  const { tenant } = useCapabilities()
  const [f, setF] = useState({ rate: '', effective_from: businessDate(tenant) })
  const [problem, setProblem] = useState<string | null>(null)
  const zeroRate = code.treatment !== 'STANDARD'
  const latest = code.rates?.[0]

  async function submit() {
    setProblem(null)
    if (!zeroRate && !decimalText(f.rate)) return setProblem('Tarif wajib diisi.')
    if (!f.effective_from) return setProblem('Tanggal berlaku wajib diisi.')
    const r = await run(() => api.post(`${API}/tax-codes/${code.id}/rates`, { rate: zeroRate ? '0' : decimalText(f.rate), effective_from: f.effective_from }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={`Tarif baru untuk ${code.code}`} submitLabel="Simpan tarif" busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <p className="muted">
        Tarif baru berlaku mulai tanggal efektifnya dan menutup tarif sebelumnya sehari sebelumnya. Dokumen yang sudah diposting tidak berubah, dan tarif tidak dapat diubah surut ke tanggal yang sudah dipakai transaksi terposting.
        {latest && <> Tarif terakhir berlaku sejak {formatDate(latest.effective_from)}.</>}
      </p>
      <div className="form-grid">
        <Field label="Tarif (%)" error={fieldMessage(error, 'rate')} hint={zeroRate ? 'Kode tarif nol atau dibebaskan bertarif 0.' : 'Persentase 0 sampai 100, paling banyak enam desimal.'}>
          {(p) => <input className="input amount" inputMode="decimal" required disabled={zeroRate} value={zeroRate ? '0' : f.rate} onChange={(e) => setF((s) => ({ ...s, rate: e.target.value }))} {...p} />}
        </Field>
        <Field label="Berlaku mulai" error={fieldMessage(error, 'effective_from')}>
          {(p) => <input className="input" type="date" required value={f.effective_from} onChange={(e) => setF((s) => ({ ...s, effective_from: e.target.value }))} {...p} />}
        </Field>
        {problem && <p className="field full"><span className="error" role="alert">{problem}</span></p>}
      </div>
    </FormModal>
  )
}

export function TaxCodeStatusDialog({ code, onClose, onDone }: { code: TaxCode; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const activate = code.status !== 'ACTIVE'
  return (
    <ConfirmDialog
      title={activate ? 'Aktifkan kode pajak' : 'Nonaktifkan kode pajak'}
      confirmLabel={activate ? 'Aktifkan' : 'Nonaktifkan'}
      danger={!activate}
      busy={busy}
      error={taxError(error)}
      message={activate ? `Aktifkan kembali ${code.code} · ${code.name}?` : `${code.code} · ${code.name} tidak dapat dipakai pada dokumen baru. Dokumen yang sudah ada dan laporan pajaknya tetap utuh.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`${API}/tax-codes/${code.id}/${activate ? 'activate' : 'deactivate'}`))
        if (r.ok) onDone()
      }}
    />
  )
}

export function TaxCodeDeleteDialog({ code, onClose, onDone }: { code: TaxCode; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  return (
    <ConfirmDialog
      title="Hapus kode pajak"
      confirmLabel="Hapus"
      danger
      busy={busy}
      error={taxError(error)}
      message={`Hapus ${code.code} · ${code.name} beserta tarifnya? Hanya kode yang belum pernah dipakai dokumen yang dapat dihapus; yang sudah dipakai harus dinonaktifkan.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`${API}/tax-codes/${code.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
