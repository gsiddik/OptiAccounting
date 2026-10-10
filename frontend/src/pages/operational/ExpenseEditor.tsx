import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, EmptyState, Field, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { todayIn } from '../../lib/format'
import { normalizeAmountInput } from '../../lib/accounting'
import { useAction, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { API, useCashBankAccounts, useExpenseCategories, useModuleAccess, usePaymentTerms, useVendors } from '../../lib/operational'
import { paymentMethodLabels, settlementLabels } from '../../lib/operationalLabels'
import { useDimensions } from '../accounting/data'
import { fieldMessage, localized } from './expense/errors'
import { buildExpenseBody, dueDateRule, emptyExpenseForm, expenseFormFrom, previewTotal, type ExpenseForm } from './expense/payload'
import { SETTLEMENTS, type Expense } from './expense/types'
import { DimensionFields, ErrorNotice } from './shared'
import { TaxCodeField, TaxPreviewNote } from './taxOptions'
import { useTaxCodes } from './taxSupport'

export default function ExpenseEditor() {
  const { id } = useParams()
  const expense = useResource(async () => (id ? (await api.get<Expense>(`${API}/expenses/${id}`)).data : null), [id])

  if (expense.loading && !expense.data && id) return <Loading />
  if (expense.error) return <ErrorNotice error={expense.error} onRetry={expense.reload} />
  if (expense.data && expense.data.status !== 'DRAFT') {
    return <EmptyState title="Beban tidak dapat diubah">Hanya beban berstatus draf yang dapat diubah. Buka bebannya untuk melihat riwayat.</EmptyState>
  }
  return <Form key={id ?? 'new'} expense={expense.data} />
}

function Form({ expense }: { expense: Expense | null }) {
  const navigate = useNavigate()
  const toast = useToast()
  const { tenant } = useCapabilities()
  const { canChange } = useModuleAccess('ACCOUNTING_EXPENSE')
  const { busy, error, run, clearError } = useAction()
  const { vendors } = useVendors('ACTIVE')
  const { terms } = usePaymentTerms()
  const { categories } = useExpenseCategories()
  const { accounts: cashAccounts, byId: cashByAccount } = useCashBankAccounts('ACTIVE')
  const { catalog } = useDimensions()
  const taxCodes = useTaxCodes('INPUT')
  const today = tenant?.business_date ?? todayIn()

  const [f, setF] = useState<ExpenseForm>(() => (expense ? expenseFormFrom(expense) : emptyExpenseForm(today)))
  const [hint, setHint] = useState<string | null>(null)
  const set = (k: keyof ExpenseForm) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const err = localized(error)

  // The record's own vendor, category and cash account stay selectable even if they were deactivated since the draft was saved.
  const vendorOptions = withCurrent(vendors.map((v) => ({ id: v.id, label: `${v.code} · ${v.name}` })), expense?.vendor && { id: expense.vendor.id, label: `${expense.vendor.code} · ${expense.vendor.name}` })
  const cashOptions = withCurrent(cashAccounts.map((a) => ({ id: a.id, label: `${a.code} · ${a.name}` })), expense?.cash_bank_account && { id: expense.cash_bank_account.id, label: `${expense.cash_bank_account.code} · ${expense.cash_bank_account.name}` })
  const categoryOptions = categories.filter((c) => c.status === 'ACTIVE' || c.id === f.expense_category_id)

  const vendor = vendors.find((v) => v.id === f.vendor_id)
  const effectiveTerm = terms.find((t) => t.id === (f.payment_term_id || vendor?.payment_term_id))
  const dueRule = dueDateRule(effectiveTerm)
  const total = previewTotal(f)

  function chooseCash(id: string) {
    // Like the API, a document without its own placement takes the one of its cash/bank account.
    const chosen = cashByAccount.get(id)
    setF((s) => ({
      ...s,
      cash_bank_account_id: id,
      ...(chosen && !s.branch_id && !s.business_unit_id ? { branch_id: chosen.branch_id ?? '', business_unit_id: chosen.business_unit_id ?? '' } : {}),
    }))
  }

  async function save(thenSubmit: boolean) {
    const built = buildExpenseBody(f, vendors, terms, { hadTaxCode: !!expense?.tax_code_id })
    if ('problem' in built) return setHint(built.problem)
    setHint(null)
    clearError()
    const saved = await run(async () => {
      const draft = (await (expense ? api.patch<Expense>(`${API}/expenses/${expense.id}`, built.body) : api.post<Expense>(`${API}/expenses`, built.body))).data
      if (thenSubmit) await api.post(`${API}/expenses/${draft.id}/submit`)
      return draft
    })
    if (saved.ok) {
      toast.success(thenSubmit ? 'Beban disimpan dan diajukan.' : 'Draf beban disimpan.')
      navigate(`/app/akuntansi/beban/${saved.value.id}`)
    }
  }

  return (
    <>
      <PageHeader title={expense ? `Ubah draf beban ${expense.document_number ?? ''}`.trim() : 'Beban baru'} description="Pilih cara penyelesaian lebih dulu: itu menentukan jalur keuangannya dan tidak ditebak dari label. Total dihitung ulang oleh server saat disimpan." />
      <form onSubmit={(e) => { e.preventDefault(); void save(false) }} noValidate className="stack">
        {err != null && <Banner tone="bad">{describeError(err)}</Banner>}
        {hint && <Banner tone="warn">{hint}</Banner>}

        <Card title="Cara penyelesaian">
          <fieldset className="field">
            <legend className="sr-only">Cara penyelesaian</legend>
            {SETTLEMENTS.map((s) => (
              <label className="check" key={s}>
                <input type="radio" name="settlement" checked={f.settlement === s} onChange={() => setF((x) => ({ ...x, settlement: s }))} /> {settlementLabels[s]}
              </label>
            ))}
            <span className="hint">
              {f.settlement === 'PAYABLE'
                ? 'Saat diposting: Debit beban, Kredit utang usaha. Utangnya masuk ke sub-buku utang dan dilunasi dengan pembayaran vendor.'
                : 'Saat diposting: Debit beban, Kredit akun kas/bank yang dipilih. Tidak ada utang yang dibuat.'}
            </span>
          </fieldset>
        </Card>

        <Card title="Informasi beban">
          <div className="form-grid">
            <Field label="Kategori" error={fieldMessage(err, 'expense_category_id')}>
              {(p) => (
                <select className="select" required value={f.expense_category_id} onChange={set('expense_category_id')} {...p}>
                  <option value="">Pilih kategori…</option>
                  {categoryOptions.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                </select>
              )}
            </Field>
            <Field label="Dokumen pendukung" error={fieldMessage(err, 'supporting_document')} hint="Nomor kuitansi atau bukti lain, opsional.">
              {(p) => <input className="input" maxLength={150} value={f.supporting_document} onChange={set('supporting_document')} {...p} />}
            </Field>
            <Field label="Deskripsi" error={fieldMessage(err, 'description')} full>
              {(p) => <input className="input" required maxLength={500} value={f.description} onChange={set('description')} {...p} />}
            </Field>
            <Field label="Tanggal beban" error={fieldMessage(err, 'expense_date')}>
              {(p) => <input className="input" type="date" required value={f.expense_date} onChange={(e) => setF((s) => ({ ...s, expense_date: e.target.value, posting_date: s.posting_date === s.expense_date ? e.target.value : s.posting_date }))} {...p} />}
            </Field>
            <Field label="Tanggal posting" error={fieldMessage(err, 'posting_date')} hint="Menentukan periode akuntansi.">
              {(p) => <input className="input" type="date" required value={f.posting_date} onChange={set('posting_date')} {...p} />}
            </Field>
            <Field label="Referensi" error={fieldMessage(err, 'reference')} hint="Nomor dokumen sumber, opsional.">
              {(p) => <input className="input" maxLength={100} value={f.reference} onChange={set('reference')} {...p} />}
            </Field>
          </div>
        </Card>

        {f.settlement === 'PAYABLE' ? (
          <Card title="Utang kepada vendor">
            <div className="form-grid">
              <Field label="Vendor" error={fieldMessage(err, 'vendor_id')}>
                {(p) => (
                  <select className="select" required value={f.vendor_id} onChange={set('vendor_id')} {...p}>
                    <option value="">Pilih vendor…</option>
                    {vendorOptions.map((v) => <option key={v.id} value={v.id}>{v.label}</option>)}
                  </select>
                )}
              </Field>
              <Field label="Termin pembayaran" error={fieldMessage(err, 'payment_term_id')} hint="Kosong berarti memakai termin vendor.">
                {(p) => (
                  <select className="select" value={f.payment_term_id} onChange={set('payment_term_id')} {...p}>
                    <option value="">Ikuti termin vendor</option>
                    {terms.filter((t) => t.status === 'ACTIVE' || t.id === f.payment_term_id).map((t) => <option key={t.id} value={t.id}>{t.code} · {t.name}</option>)}
                  </select>
                )}
              </Field>
              <Field label="Jatuh tempo" error={fieldMessage(err, 'due_date')} hint={dueHint(dueRule)}>
                {(p) => <input className="input" type="date" disabled={dueRule === 'locked'} required={dueRule === 'required'} value={dueRule === 'locked' ? '' : f.due_date} onChange={set('due_date')} {...p} />}
              </Field>
            </div>
          </Card>
        ) : (
          <Card title="Pembayaran langsung">
            <div className="form-grid">
              <Field label="Akun kas/bank" error={fieldMessage(err, 'cash_bank_account_id')} hint="Hanya akun kas/bank yang aktif.">
                {(p) => (
                  <select className="select" required value={f.cash_bank_account_id} onChange={(e) => chooseCash(e.target.value)} {...p}>
                    <option value="">Pilih akun…</option>
                    {cashOptions.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                  </select>
                )}
              </Field>
              <Field label="Metode pembayaran" error={fieldMessage(err, 'payment_method')}>
                {(p) => (
                  <select className="select" value={f.payment_method} onChange={set('payment_method')} {...p}>
                    <option value="">Tidak ditentukan</option>
                    {Object.entries(paymentMethodLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                  </select>
                )}
              </Field>
              <Field label="Nama penerima" error={fieldMessage(err, 'payee_name')} full>
                {(p) => <input className="input" maxLength={150} value={f.payee_name} onChange={set('payee_name')} {...p} />}
              </Field>
            </div>
          </Card>
        )}

        <Card title="Jumlah">
          <div className="form-grid">
            <Field label="Jumlah neto" error={fieldMessage(err, 'net_amount')} hint="Sebelum pajak. Contoh 1500000 atau 1500000,50.">
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={f.net_amount} onChange={set('net_amount')} {...p} />}
            </Field>
            {taxCodes.enabled && (
              <TaxCodeField label="Kode pajak" value={f.tax_code_id} codes={taxCodes.codes} onChange={(id) => setF((s) => ({ ...s, tax_code_id: id }))} error={fieldMessage(err, 'tax_code_id')} hint="Opsional. Pajak masukan dihitung server dengan tarif pada tanggal beban; untuk kode inklusif isi jumlah termasuk pajak." />
            )}
            <Field label="Pajak" error={fieldMessage(err, 'tax_amount')} hint={f.tax_code_id ? 'Dihitung server dari kode pajak; tidak diisi manual.' : 'Kosong berarti tanpa pajak.'}>
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" disabled={f.tax_code_id !== ''} value={f.tax_code_id ? '' : f.tax_amount} onChange={set('tax_amount')} {...p} />}
            </Field>
            {f.tax_code_id !== '' && <TaxPreviewNote codeId={f.tax_code_id} amount={normalizeAmountInput(f.net_amount)} date={f.expense_date} />}
          </div>
          {f.tax_code_id === '' && (
            <p className="muted preview-note" role="status" aria-live="polite">
              {total === null ? 'Jumlah belum valid.' : <>Pratinjau total: <strong className="money">{total}</strong></>} Pratinjau saat mengetik; total yang tersimpan dihitung server.
            </p>
          )}
        </Card>

        {(catalog.branches.length > 0 || catalog.business_units.length > 0 || catalog.cost_centers.length > 0) && (
          <Card title="Dimensi">
            <div className="form-grid">
              <DimensionFields catalog={catalog} value={f} onChange={(patch) => setF((s) => ({ ...s, ...patch }))} error={err} />
            </div>
          </Card>
        )}

        <div className="actions form-actions">
          <Button onClick={() => navigate(expense ? `/app/akuntansi/beban/${expense.id}` : '/app/akuntansi/beban')}>Batal</Button>
          <Button type="submit" variant={canChange('accounting.expense.submit') ? 'secondary' : 'primary'} loading={busy}>Simpan draf</Button>
          {canChange('accounting.expense.submit') && <Button variant="primary" loading={busy} onClick={() => void save(true)}>Simpan & ajukan</Button>}
        </div>
      </form>
    </>
  )
}

type Option = { id: string; label: string }

function withCurrent(list: Option[], current: Option | null | undefined | false): Option[] {
  return current && !list.some((o) => o.id === current.id) ? [...list, current] : list
}

function dueHint(rule: 'free' | 'required' | 'locked'): string {
  if (rule === 'required') return 'Wajib diisi: termin ini memakai tanggal yang ditentukan sendiri.'
  if (rule === 'locked') return 'Termin ini menghitung jatuh tempo otomatis dan tidak mengizinkan tanggal lain.'
  return 'Kosongkan agar dihitung dari termin pembayaran.'
}
