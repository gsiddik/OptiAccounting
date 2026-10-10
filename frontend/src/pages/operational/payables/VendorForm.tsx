import { useState } from 'react'
import { FormModal } from '../../../components/Modal'
import { Field } from '../../../components/ui'
import { api } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { API, usePaymentTerms, type Vendor } from '../../../lib/operational'
import { accountLabel, useAccounts } from '../../accounting/data'
import { fieldMessage, useAct } from './messages'

type Form = {
  code: string
  name: string
  legal_name: string
  contact_name: string
  email: string
  phone: string
  address: string
  tax_id: string
  tax_registered: boolean
  payment_term_id: string
  default_currency: string
  payable_account_id: string
  default_expense_account_id: string
  notes: string
}

const orNull = (text: string) => text.trim() || null

/** Create / edit modal of a vendor. Identity fields and the financial profile (term, currency, accounts) are all sent; the API validates them against the tenant's own masters. */
export function VendorForm({ vendor, onClose, onDone }: { vendor: Vendor | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const { tenant } = useCapabilities()
  const { terms } = usePaymentTerms()
  const { accounts, error: accountsError } = useAccounts()
  const [f, setF] = useState<Form>({
    code: vendor?.code ?? '',
    name: vendor?.name ?? '',
    legal_name: vendor?.legal_name ?? '',
    contact_name: vendor?.contact_name ?? '',
    email: vendor?.email ?? '',
    phone: vendor?.phone ?? '',
    address: vendor?.address ?? '',
    tax_id: vendor?.tax_id ?? '',
    tax_registered: vendor?.tax_registered ?? false,
    payment_term_id: vendor?.payment_term_id ?? '',
    default_currency: vendor?.default_currency ?? '',
    payable_account_id: vendor?.payable_account_id ?? '',
    default_expense_account_id: vendor?.default_expense_account_id ?? '',
    notes: vendor?.notes ?? '',
  })
  const set = (k: keyof Form) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const err = (name: string) => fieldMessage(error, name)

  // Only control accounts of type liability can carry a vendor's payables; the expense hint is a postable, non-control expense or asset account.
  const payableOptions = accounts.filter((a) => a.status === 'ACTIVE' && a.is_postable && a.is_control && a.account_type === 'LIABILITY')
  const expenseOptions = accounts.filter((a) => a.status === 'ACTIVE' && a.is_postable && !a.is_control && (a.account_type === 'EXPENSE' || a.account_type === 'ASSET'))
  const termOptions = terms.filter((t) => t.status === 'ACTIVE' || t.id === f.payment_term_id)

  async function submit() {
    const body = {
      code: f.code.trim(), name: f.name.trim(), legal_name: orNull(f.legal_name), contact_name: orNull(f.contact_name), email: orNull(f.email), phone: orNull(f.phone),
      address: orNull(f.address), tax_id: orNull(f.tax_id), tax_registered: f.tax_registered, payment_term_id: f.payment_term_id || null,
      default_currency: orNull(f.default_currency), payable_account_id: f.payable_account_id || null, default_expense_account_id: f.default_expense_account_id || null, notes: orNull(f.notes),
    }
    const r = await run(() => (vendor ? api.patch(`${API}/vendors/${vendor.id}`, body) : api.post(`${API}/vendors`, body)))
    if (r.ok) onDone()
  }

  const accountSelect = (value: string, options: typeof accounts, current: Vendor['payable_account'], onChange: (id: string) => void, p: object, empty: string) => (
    <select className="select" value={value} onChange={(e) => onChange(e.target.value)} disabled={accountsError != null && options.length === 0 && !current} {...p}>
      <option value="">{empty}</option>
      {current && !options.some((a) => a.id === current.id) && <option value={current.id}>{accountLabel(current)}</option>}
      {options.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
    </select>
  )

  return (
    <FormModal title={vendor ? `Ubah vendor ${vendor.code}` : 'Vendor baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Kode" error={err('code')} hint="Huruf, angka, titik, strip, atau garis miring. Disimpan dalam huruf besar.">
          {(p) => <input className="input mono" required maxLength={40} value={f.code} onChange={set('code')} {...p} />}
        </Field>
        <Field label="Nama" error={err('name')}>{(p) => <input className="input" required maxLength={255} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Nama legal" error={err('legal_name')}>{(p) => <input className="input" maxLength={255} value={f.legal_name} onChange={set('legal_name')} {...p} />}</Field>
        <Field label="Nama kontak" error={err('contact_name')}>{(p) => <input className="input" maxLength={150} value={f.contact_name} onChange={set('contact_name')} {...p} />}</Field>
        <Field label="Email" error={err('email')}>{(p) => <input className="input" type="email" maxLength={255} value={f.email} onChange={set('email')} {...p} />}</Field>
        <Field label="Telepon" error={err('phone')}>{(p) => <input className="input" maxLength={50} value={f.phone} onChange={set('phone')} {...p} />}</Field>
        <Field label="Alamat" error={err('address')} full>{(p) => <input className="input" maxLength={500} value={f.address} onChange={set('address')} {...p} />}</Field>
        <Field label="NPWP / ID pajak" error={err('tax_id')}>{(p) => <input className="input" maxLength={50} value={f.tax_id} onChange={set('tax_id')} {...p} />}</Field>
        <label className="check"><input type="checkbox" checked={f.tax_registered} onChange={(e) => setF((s) => ({ ...s, tax_registered: e.target.checked }))} /> Pengusaha Kena Pajak (PKP)</label>

        <Field label="Termin pembayaran" error={err('payment_term_id')} hint="Dipakai sebagai termin awal faktur vendor ini.">
          {(p) => (
            <select className="select" value={f.payment_term_id} onChange={set('payment_term_id')} {...p}>
              <option value="">Tanpa termin</option>
              {termOptions.map((t) => <option key={t.id} value={t.id}>{t.code} · {t.name}</option>)}
            </select>
          )}
        </Field>
        <Field label="Mata uang default" error={err('default_currency')} hint={`Hanya mata uang fungsional (${tenant?.tenant.default_currency ?? 'IDR'}) yang didukung. Kosongkan untuk memakai default.`}>
          {(p) => <input className="input mono" maxLength={3} placeholder={tenant?.tenant.default_currency ?? 'IDR'} value={f.default_currency} onChange={(e) => setF((s) => ({ ...s, default_currency: e.target.value.toUpperCase() }))} {...p} />}
        </Field>
        <Field label="Akun utang (override)" error={err('payable_account_id')} hint={accountsError != null ? 'Daftar akun tidak dapat dimuat (perlu izin bagan akun).' : 'Kosongkan untuk memakai pemetaan peran Utang usaha. Hanya akun kontrol bertipe kewajiban.'}>
          {(p) => accountSelect(f.payable_account_id, payableOptions, vendor?.payable_account, (id) => setF((s) => ({ ...s, payable_account_id: id })), p, 'Pemetaan peran standar')}
        </Field>
        <Field label="Akun beban default" error={err('default_expense_account_id')} hint="Petunjuk klasifikasi baris faktur: akun beban atau aset yang bukan akun kontrol.">
          {(p) => accountSelect(f.default_expense_account_id, expenseOptions, vendor?.default_expense_account, (id) => setF((s) => ({ ...s, default_expense_account_id: id })), p, 'Tanpa akun default')}
        </Field>
        <Field label="Catatan" error={err('notes')} full>{(p) => <textarea className="textarea" maxLength={1000} value={f.notes} onChange={set('notes')} {...p} />}</Field>
      </div>
    </FormModal>
  )
}
