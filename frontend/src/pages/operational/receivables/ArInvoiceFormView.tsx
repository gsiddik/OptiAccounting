import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useToast } from '../../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Field, Loading, PageHeader } from '../../../components/ui'
import { amountToApi } from '../../../lib/accounting'
import { api } from '../../../lib/api'
import { formatDate } from '../../../lib/format'
import { statusLabel } from '../../../lib/labels'
import { API, MODULES, useCustomers, useModuleAccess, usePaymentTerms } from '../../../lib/operational'
import { termTypeLabels } from '../../../lib/operationalLabels'
import { useAccounts, useDimensions } from '../../accounting/data'
import { previewInvoice } from '../payables/invoiceForm'
import { destinationRoles, useBusinessDate, useLineRoles } from '../payables/lists'
import { errorText, fieldMessage, useAct } from '../payables/messages'
import { DimensionFields, Money } from '../shared'
import { arHeaderFrom, arInvoicePayload, arInvoiceProblem, type ArInvoiceHeader } from './arInvoiceForm'
import { ArLines } from './ArLines'
import { arLinesFrom, type ArLineForm } from './arLines'
import { AR_PATH } from './paths'
import type { ArInvoice } from './types'

/** Create / edit form of a DRAFT customer invoice. It builds the exact API payload; the totals shown are a preview and the server recomputes them. */
export function ArInvoiceFormView({ invoice }: { invoice: ArInvoice | null }) {
  const navigate = useNavigate()
  const toast = useToast()
  const today = useBusinessDate()
  const access = useModuleAccess(MODULES.ar)
  const { busy, error, run, clearError } = useAct()
  const customerList = useCustomers('ACTIVE')
  const { terms } = usePaymentTerms('ar-payment-terms')
  const { accounts } = useAccounts()
  const roles = destinationRoles(useLineRoles())
  const { catalog } = useDimensions()

  const [h, setH] = useState<ArInvoiceHeader>(() => arHeaderFrom(invoice, today))
  const [lines, setLines] = useState<ArLineForm[]>(() => arLinesFrom(invoice?.lines))
  const [hint, setHint] = useState<string | null>(null)
  const set = (patch: Partial<ArInvoiceHeader>) => setH((s) => ({ ...s, ...patch }))

  // ------------------------------------------------------------------ customer, term and due date
  const known = customerList.customers.some((c) => c.id === invoice?.customer_id)
  const customers = invoice?.customer && !known ? [...customerList.customers, { ...invoice.customer, payment_term_id: null as string | null }] : customerList.customers
  const term = terms.find((t) => t.id === h.payment_term_id)
  const termKnown = !h.payment_term_id || term !== undefined
  const dueDateEditable = termKnown && (term === undefined || term.term_type === 'CUSTOM' || term.allows_due_date_override)

  function chooseCustomer(id: string) {
    const customer = customerList.customers.find((c) => c.id === id)
    const usable = customer?.payment_term_id && terms.some((t) => t.id === customer.payment_term_id && t.status === 'ACTIVE') ? customer.payment_term_id : ''
    set({ customer_id: id, payment_term_id: usable, due_date: '' })
  }

  const preview = previewInvoice(h, lines)
  const duplicates = invoice?.possible_duplicates ?? []

  async function save(thenSubmit: boolean) {
    const problem = arInvoiceProblem(h, lines)
    setHint(problem)
    if (problem) return
    clearError()
    const body = arInvoicePayload(h, lines, { dueDateEditable })
    const saved = await run(async () => {
      const draft = (await (invoice ? api.patch<ArInvoice>(`${API}/ar-invoices/${invoice.id}`, body) : api.post<ArInvoice>(`${API}/ar-invoices`, body))).data
      let submitError: unknown = null
      if (thenSubmit) {
        try {
          await api.post(`${API}/ar-invoices/${draft.id}/submit`)
        } catch (e) {
          submitError = e // the draft is saved: do not make the user create it twice
        }
      }
      return { draft, submitError }
    })
    if (!saved.ok) return
    const { draft, submitError } = saved.value
    if (submitError) toast.error(`Draf disimpan, tetapi belum dapat diajukan: ${errorText(submitError)}`)
    else toast.success(`${thenSubmit ? 'Faktur disimpan dan diajukan.' : 'Draf faktur disimpan.'}${(draft.possible_duplicates ?? []).length > 0 ? ' Ada kemungkinan faktur ganda; periksa di halaman faktur.' : ''}`)
    navigate(`${AR_PATH.invoices}/${draft.id}`)
  }

  if (customerList.loading && customerList.customers.length === 0 && !customerList.error) return <Loading />
  if (customerList.error) return <ErrorNotice error={customerList.error} onRetry={customerList.reload} />

  return (
    <>
      <PageHeader
        title={invoice ? `Ubah draf faktur pelanggan${invoice.customer_reference ? ` ${invoice.customer_reference}` : ''}` : 'Faktur pelanggan baru'}
        description="Total, jatuh tempo, dan saldo piutang dihitung oleh server saat faktur disimpan. Angka di halaman ini hanya pratinjau."
      />
      <form onSubmit={(e) => { e.preventDefault(); void save(false) }} noValidate className="stack">
        {error != null && <Banner tone="bad">{errorText(error)}</Banner>}
        {hint && <Banner tone="warn">{hint}</Banner>}
        {duplicates.length > 0 && (
          <Banner tone="warn">
            Faktur lain dari pelanggan ini memiliki referensi yang sama, atau total dan tanggal dokumen yang sama. Pastikan bukan tagihan ganda:{' '}
            {duplicates.map((d, i) => <span key={d.id}>{i > 0 && ', '}<Link to={`${AR_PATH.invoices}/${d.id}`}>{d.document_number ?? d.customer_reference ?? 'Draf'}</Link> ({statusLabel(d.status)[0]})</span>)}
          </Banner>
        )}

        <Card title="Informasi faktur">
          <div className="form-grid">
            <Field label="Pelanggan" error={fieldMessage(error, 'customer_id')}>
              {(p) => (
                <select className="select" required value={h.customer_id} onChange={(e) => chooseCustomer(e.target.value)} {...p}>
                  <option value="">Pilih pelanggan…</option>
                  {customers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                </select>
              )}
            </Field>
            <Field label="Referensi pelanggan" error={fieldMessage(error, 'customer_reference')} hint="Nomor PO atau dokumen milik pelanggan, opsional. Faktur lain dengan referensi yang sama ditandai sebagai kemungkinan ganda.">
              {(p) => <input className="input" maxLength={100} value={h.customer_reference} onChange={(e) => set({ customer_reference: e.target.value })} {...p} />}
            </Field>
            <Field label="Tanggal dokumen" error={fieldMessage(error, 'document_date')}>
              {(p) => <input className="input" type="date" required value={h.document_date} onChange={(e) => set({ document_date: e.target.value, ...(h.posting_date === h.document_date && { posting_date: e.target.value }) })} {...p} />}
            </Field>
            <Field label="Tanggal posting" error={fieldMessage(error, 'posting_date')} hint="Menentukan periode akuntansi.">
              {(p) => <input className="input" type="date" required value={h.posting_date} onChange={(e) => set({ posting_date: e.target.value })} {...p} />}
            </Field>
            <Field label="Termin pembayaran" error={fieldMessage(error, 'payment_term_id')} hint="Termin menentukan jatuh tempo.">
              {(p) => (
                <select className="select" value={h.payment_term_id} onChange={(e) => set({ payment_term_id: e.target.value, due_date: '' })} {...p}>
                  <option value="">Tanpa termin</option>
                  {terms.filter((t) => t.status === 'ACTIVE' || t.id === h.payment_term_id).map((t) => <option key={t.id} value={t.id}>{t.code} · {t.name}</option>)}
                </select>
              )}
            </Field>
            {dueDateEditable ? (
              <Field
                label={term?.term_type === 'CUSTOM' ? 'Jatuh tempo (wajib untuk termin ini)' : 'Jatuh tempo manual'}
                error={fieldMessage(error, 'due_date')}
                hint={term?.term_type === 'CUSTOM' ? 'Termin ini tidak menghitung tanggal sendiri.' : term ? 'Kosongkan agar server menghitung dari termin.' : 'Kosongkan agar sama dengan tanggal dokumen.'}
              >
                {(p) => <input className="input" type="date" value={h.due_date} onChange={(e) => set({ due_date: e.target.value })} {...p} />}
              </Field>
            ) : (
              <div className="field">
                <span className="label">Jatuh tempo</span>
                <span>{term ? `Dihitung server: ${termTypeLabels[term.term_type] ?? term.term_type}${term.due_days !== null ? `, ${term.due_days} hari` : ''}.` : 'Dihitung server dari termin.'}</span>
                {invoice && <span className="hint">Saat ini {formatDate(invoice.due_date)}; dihitung ulang saat disimpan.</span>}
              </div>
            )}
            <Field label="Deskripsi" error={fieldMessage(error, 'description')} full>
              {(p) => <input className="input" required maxLength={500} value={h.description} onChange={(e) => set({ description: e.target.value })} {...p} />}
            </Field>
            <Field label="Referensi" error={fieldMessage(error, 'reference')} hint="Nomor kontrak atau dokumen sumber internal, opsional.">
              {(p) => <input className="input" maxLength={100} value={h.reference} onChange={(e) => set({ reference: e.target.value })} {...p} />}
            </Field>
            <DimensionFields catalog={catalog} value={h} onChange={set} error={error} />
          </div>
        </Card>

        <Card title="Baris faktur">
          <ArLines lines={lines} onChange={setLines} preview={preview} accounts={accounts} roles={roles} catalog={catalog} error={error} />
        </Card>

        <Card title="Diskon, pajak, dan biaya lain">
          <div className="form-grid">
            <Field label="Diskon" error={fieldMessage(error, 'discount_amount')} hint="Mengurangi subtotal; tidak boleh melebihi subtotal.">
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={h.discount_amount} onChange={(e) => set({ discount_amount: e.target.value })} {...p} />}
            </Field>
            <Field label="Pajak" error={fieldMessage(error, 'tax_amount')} hint="Jumlah pajak keluaran sesuai faktur.">
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={h.tax_amount} onChange={(e) => set({ tax_amount: e.target.value })} {...p} />}
            </Field>
            <Field label="Biaya lain" error={fieldMessage(error, 'other_charges_amount')} hint="Ongkos kirim, biaya administrasi, dan sejenisnya.">
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={h.other_charges_amount} onChange={(e) => set({ other_charges_amount: e.target.value })} {...p} />}
            </Field>
          </div>
        </Card>

        <Card title="Ringkasan (pratinjau)">
          <div className="totals" role="status" aria-live="polite">
            <span>Subtotal <Money value={amountToApi(preview.subtotal)} /></span>
            <span>Diskon <Money value={amountToApi(preview.discount)} /></span>
            <span>Pajak <Money value={amountToApi(preview.tax)} /></span>
            <span>Biaya lain <Money value={amountToApi(preview.other)} /></span>
            <span>Total pratinjau <Money value={amountToApi(preview.total)} strong /></span>
          </div>
          {preview.discountTooHigh && <p className="balance-bad">Diskon melebihi subtotal; server akan menolaknya.</p>}
          <p className="muted preview-note">Ini pratinjau yang dihitung saat Anda mengetik. Total yang tersimpan, jatuh tempo, dan saldo piutang berasal dari server.</p>
        </Card>

        <div className="actions form-actions">
          <Button onClick={() => navigate(invoice ? `${AR_PATH.invoices}/${invoice.id}` : AR_PATH.invoices)}>Batal</Button>
          <Button type="submit" variant={access.canChange('accounting.ar_invoice.submit') ? 'secondary' : 'primary'} loading={busy}>Simpan draf</Button>
          {access.canChange('accounting.ar_invoice.submit') && <Button variant="primary" loading={busy} onClick={() => void save(true)}>Simpan & ajukan</Button>}
        </div>
      </form>
    </>
  )
}
