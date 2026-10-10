import { useState } from 'react'
import { FormModal } from '../../../components/Modal'
import { Field } from '../../../components/ui'
import { parseAmount } from '../../../lib/accounting'
import { api } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { API, usePaymentTerms, type Customer } from '../../../lib/operational'
import { accountLabel, useAccounts } from '../../accounting/data'
import { optionalDecimal, plainAmount } from '../payables/invoiceForm'
import { fieldMessage, useAct } from '../payables/messages'

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
  receivable_account_id: string
  default_revenue_account_id: string
  credit_limit: string
  notes: string
}

const orNull = (text: string) => text.trim() || null

/**
 * Create / edit modal of a customer: identity, tax profile and financial profile (payment term, currency, receivable account, default
 * revenue account, credit limit). The API validates every reference against the tenant's own masters. The credit limit is information
 * only: nothing in OptiAccounting enforces it.
 */
export function CustomerForm({ customer, onClose, onDone }: { customer: Customer | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const { tenant } = useCapabilities()
  const { terms } = usePaymentTerms('ar-payment-terms')
  const { accounts, error: accountsError } = useAccounts()
  const [f, setF] = useState<Form>({
    code: customer?.code ?? '',
    name: customer?.name ?? '',
    legal_name: customer?.legal_name ?? '',
    contact_name: customer?.contact_name ?? '',
    email: customer?.email ?? '',
    phone: customer?.phone ?? '',
    address: customer?.address ?? '',
    tax_id: customer?.tax_id ?? '',
    tax_registered: customer?.tax_registered ?? false,
    payment_term_id: customer?.payment_term_id ?? '',
    default_currency: customer?.default_currency ?? '',
    receivable_account_id: customer?.receivable_account_id ?? '',
    default_revenue_account_id: customer?.default_revenue_account_id ?? '',
    credit_limit: plainAmount(customer?.credit_limit),
    notes: customer?.notes ?? '',
  })
  const [problem, setProblem] = useState<string | null>(null)
  const set = (k: keyof Form) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const err = (name: string) => fieldMessage(error, name)

  // A receivable override must be a control account of type asset; the revenue hint a postable, non-control revenue account.
  const receivableOptions = accounts.filter((a) => a.status === 'ACTIVE' && a.is_postable && a.is_control && a.account_type === 'ASSET')
  const revenueOptions = accounts.filter((a) => a.status === 'ACTIVE' && a.is_postable && !a.is_control && a.account_type === 'REVENUE')
  const termOptions = terms.filter((t) => t.status === 'ACTIVE' || t.id === f.payment_term_id)

  async function submit() {
    if (parseAmount(f.credit_limit) === null) {
      setProblem('Batas kredit tidak valid. Gunakan angka dengan maksimal empat desimal.')
      return
    }
    setProblem(null)
    const body = {
      code: f.code.trim(), name: f.name.trim(), legal_name: orNull(f.legal_name), contact_name: orNull(f.contact_name), email: orNull(f.email), phone: orNull(f.phone),
      address: orNull(f.address), tax_id: orNull(f.tax_id), tax_registered: f.tax_registered, payment_term_id: f.payment_term_id || null,
      default_currency: orNull(f.default_currency), receivable_account_id: f.receivable_account_id || null, default_revenue_account_id: f.default_revenue_account_id || null,
      credit_limit: optionalDecimal(f.credit_limit), notes: orNull(f.notes),
    }
    const r = await run(() => (customer ? api.patch(`${API}/customers/${customer.id}`, body) : api.post(`${API}/customers`, body)))
    if (r.ok) onDone()
  }

  const accountSelect = (value: string, options: typeof accounts, current: Customer['receivable_account'], onChange: (id: string) => void, p: object, empty: string) => (
    <select className="select" value={value} onChange={(e) => onChange(e.target.value)} disabled={accountsError != null && options.length === 0 && !current} {...p}>
      <option value="">{empty}</option>
      {current && !options.some((a) => a.id === current.id) && <option value={current.id}>{accountLabel(current)}</option>}
      {options.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
    </select>
  )

  return (
    <FormModal title={customer ? `Ubah pelanggan ${customer.code}` : 'Pelanggan baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        {problem && <p className="field full"><span className="error" role="alert">{problem}</span></p>}
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

        <Field label="Termin pembayaran" error={err('payment_term_id')} hint="Dipakai sebagai termin awal faktur pelanggan ini.">
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
        <Field label="Akun piutang (override)" error={err('receivable_account_id')} hint={accountsError != null ? 'Daftar akun tidak dapat dimuat (perlu izin bagan akun).' : 'Kosongkan untuk memakai pemetaan peran Piutang usaha. Hanya akun kontrol bertipe aset.'}>
          {(p) => accountSelect(f.receivable_account_id, receivableOptions, customer?.receivable_account, (id) => setF((s) => ({ ...s, receivable_account_id: id })), p, 'Pemetaan peran standar')}
        </Field>
        <Field label="Akun pendapatan default" error={err('default_revenue_account_id')} hint="Petunjuk klasifikasi baris faktur: akun pendapatan yang bukan akun kontrol.">
          {(p) => accountSelect(f.default_revenue_account_id, revenueOptions, customer?.default_revenue_account, (id) => setF((s) => ({ ...s, default_revenue_account_id: id })), p, 'Tanpa akun default')}
        </Field>
        <Field label="Batas kredit" error={err('credit_limit')} hint="Hanya informasi yang ditampilkan di samping piutang. Tidak membatasi faktur atau penerimaan.">
          {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="Tanpa batas" value={f.credit_limit} onChange={set('credit_limit')} {...p} />}
        </Field>
        <Field label="Catatan" error={err('notes')} full>{(p) => <textarea className="textarea" maxLength={1000} value={f.notes} onChange={set('notes')} {...p} />}</Field>
      </div>
    </FormModal>
  )
}
