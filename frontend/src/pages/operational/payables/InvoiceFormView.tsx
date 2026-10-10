import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useToast } from '../../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Field, Loading, PageHeader } from '../../../components/ui'
import { amountToApi } from '../../../lib/accounting'
import { api, ApiError } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { formatDate } from '../../../lib/format'
import { useDebounced, useResource } from '../../../lib/hooks'
import { API, MODULES, useExpenseCategories, useModuleAccess, usePaymentTerms, useVendors } from '../../../lib/operational'
import { termTypeLabels } from '../../../lib/operationalLabels'
import { statusLabel } from '../../../lib/labels'
import { useAccounts, useDimensions } from '../../accounting/data'
import { DimensionFields, Money } from '../shared'
import { InvoiceLines } from './InvoiceLines'
import { headerFrom, invoicePayload, invoiceProblem, linesFromInvoice, previewInvoice, type InvoiceHeader, type LineForm } from './invoiceForm'
import { destinationRoles, useBusinessDate, useLineRoles } from './lists'
import { errorText, fieldMessage, useAct } from './messages'
import type { DuplicateCheck, Invoice } from './types'

/** " (INV-001, Draf)": which existing invoice carries the same vendor invoice number, if the API said. */
const duplicateWhere = (d: DuplicateCheck) => {
  const where = [d.document_number, d.status ? statusLabel(d.status)[0] : null].filter(Boolean).join(', ')
  return where ? ` (${where})` : ''
}

/** Create / edit form of a DRAFT vendor invoice. It builds the exact API payload; the totals shown are a preview and the server recomputes them. */
export function InvoiceFormView({ invoice }: { invoice: Invoice | null }) {
  const navigate = useNavigate()
  const toast = useToast()
  const today = useBusinessDate()
  const { can } = useCapabilities()
  const access = useModuleAccess(MODULES.ap)
  const { busy, error, run, clearError } = useAct()
  const vendorList = useVendors('ACTIVE')
  const { terms } = usePaymentTerms()
  const { categories } = useExpenseCategories()
  const { accounts } = useAccounts()
  const roles = destinationRoles(useLineRoles())
  const { catalog } = useDimensions()

  const [h, setH] = useState<InvoiceHeader>(() => headerFrom(invoice, today))
  const [lines, setLines] = useState<LineForm[]>(() => linesFromInvoice(invoice?.lines))
  const [hint, setHint] = useState<string | null>(null)
  const [override, setOverride] = useState({ on: false, reason: '' })
  const set = (patch: Partial<InvoiceHeader>) => {
    setH((s) => ({ ...s, ...patch }))
    if ('vendor_id' in patch || 'vendor_invoice_number' in patch) clearError()
  }

  // ------------------------------------------------------------------ vendor, term and due date
  const vendors = invoice?.vendor && !vendorList.vendors.some((v) => v.id === invoice.vendor_id) ? [...vendorList.vendors, { ...invoice.vendor, payment_term_id: null }] : vendorList.vendors
  const term = terms.find((t) => t.id === h.payment_term_id)
  const termKnown = !h.payment_term_id || term !== undefined
  const dueDateEditable = termKnown && (term === undefined || term.term_type === 'CUSTOM' || term.allows_due_date_override)

  function chooseVendor(id: string) {
    const vendor = vendorList.vendors.find((v) => v.id === id)
    const usable = vendor?.payment_term_id && terms.some((t) => t.id === vendor.payment_term_id && t.status === 'ACTIVE') ? vendor.payment_term_id : ''
    set({ vendor_id: id, payment_term_id: usable, due_date: '' })
  }

  // ------------------------------------------------------------------ duplicate vendor invoice number
  const number = useDebounced(h.vendor_invoice_number.trim())
  const check = useResource(async () => {
    if (!h.vendor_id || !number) return null
    try {
      const params = { vendor_id: h.vendor_id, vendor_invoice_number: number, ...(invoice && { except_id: invoice.id }) }
      return (await api.get<DuplicateCheck>(`${API}/ap-invoices/check-duplicate`, { params })).data
    } catch {
      return null
    }
  }, [h.vendor_id, number, invoice?.id])
  const identityChanged = !invoice || h.vendor_id !== invoice.vendor_id || h.vendor_invoice_number.trim() !== invoice.vendor_invoice_number
  const duplicate = (identityChanged && check.data?.duplicate === true ? check.data : null) ?? (error instanceof ApiError && error.code === 'AP_INVOICE_DUPLICATE' ? { duplicate: true, status: String(error.details.status ?? ''), document_number: (error.details.document_number as string | null | undefined) ?? null } : null)
  const mayOverride = can('accounting.ap_invoice.override_duplicate')

  const preview = previewInvoice(h, lines)

  async function save(thenSubmit: boolean) {
    const problem = invoiceProblem(h, lines) ?? (duplicate && override.on && !override.reason.trim() ? 'Isi alasan mencatat nomor faktur vendor yang sama.' : null)
    setHint(problem)
    if (problem) return
    clearError()
    const body = invoicePayload(h, lines, { dueDateEditable, override: duplicate && override.on ? { reason: override.reason } : null })
    const saved = await run(async () => {
      const draft = (await (invoice ? api.patch<Invoice>(`${API}/ap-invoices/${invoice.id}`, body) : api.post<Invoice>(`${API}/ap-invoices`, body))).data
      let submitError: unknown = null
      if (thenSubmit) {
        try {
          await api.post(`${API}/ap-invoices/${draft.id}/submit`)
        } catch (e) {
          submitError = e // the draft is saved: do not make the user create it twice
        }
      }
      return { draft, submitError }
    })
    if (!saved.ok) return
    if (saved.value.submitError) toast.error(`Draf disimpan, tetapi belum dapat diajukan: ${errorText(saved.value.submitError)}`)
    else toast.success(thenSubmit ? 'Faktur disimpan dan diajukan.' : 'Draf faktur disimpan.')
    navigate(`/app/akuntansi/faktur-vendor/${saved.value.draft.id}`)
  }

  if (vendorList.loading && vendorList.vendors.length === 0 && !vendorList.error) return <Loading />
  if (vendorList.error) return <ErrorNotice error={vendorList.error} onRetry={vendorList.reload} />

  return (
    <>
      <PageHeader
        title={invoice ? `Ubah draf faktur ${invoice.vendor_invoice_number}` : 'Faktur vendor baru'}
        description="Total, jatuh tempo, dan saldo dihitung oleh server saat faktur disimpan. Angka di halaman ini hanya pratinjau."
      />
      <form onSubmit={(e) => { e.preventDefault(); void save(false) }} noValidate className="stack">
        {error != null && <Banner tone="bad">{errorText(error)}</Banner>}
        {hint && <Banner tone="warn">{hint}</Banner>}

        <Card title="Informasi faktur">
          <div className="form-grid">
            <Field label="Vendor" error={fieldMessage(error, 'vendor_id')}>
              {(p) => (
                <select className="select" required value={h.vendor_id} onChange={(e) => chooseVendor(e.target.value)} {...p}>
                  <option value="">Pilih vendor…</option>
                  {vendors.map((v) => <option key={v.id} value={v.id}>{v.code} · {v.name}</option>)}
                </select>
              )}
            </Field>
            <Field label="Nomor faktur vendor" error={fieldMessage(error, 'vendor_invoice_number')} hint="Nomor yang tercetak pada faktur dari vendor.">
              {(p) => <input className="input" required maxLength={100} value={h.vendor_invoice_number} onChange={(e) => set({ vendor_invoice_number: e.target.value })} {...p} />}
            </Field>
            {duplicate && (
              <div className="full stack">
                <Banner tone="warn">
                  Vendor ini sudah memiliki faktur dengan nomor yang sama{duplicateWhere(duplicate)}. Nomor ganda ditolak kecuali dicatat sebagai pengecualian oleh pengguna yang berwenang.
                </Banner>
                {mayOverride ? (
                  <>
                    <label className="check"><input type="checkbox" checked={override.on} onChange={(e) => setOverride((s) => ({ ...s, on: e.target.checked }))} /> Catat tetap sebagai nomor ganda yang disengaja</label>
                    {override.on && (
                      <Field label="Alasan nomor ganda" error={fieldMessage(error, 'duplicate_override_reason')} hint="Dicatat pada faktur dan audit.">
                        {(p) => <input className="input" maxLength={255} value={override.reason} onChange={(e) => setOverride((s) => ({ ...s, reason: e.target.value }))} {...p} />}
                      </Field>
                    )}
                  </>
                ) : (
                  <p className="muted">Anda tidak memiliki izin mencatat nomor ganda. Periksa kembali nomornya atau minta pengguna yang berwenang.</p>
                )}
              </div>
            )}
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
            <Field label="Referensi" error={fieldMessage(error, 'reference')} hint="Nomor PO atau dokumen sumber, opsional.">
              {(p) => <input className="input" maxLength={100} value={h.reference} onChange={(e) => set({ reference: e.target.value })} {...p} />}
            </Field>
            <DimensionFields catalog={catalog} value={h} onChange={set} error={error} />
          </div>
        </Card>

        <Card title="Baris faktur">
          <InvoiceLines lines={lines} onChange={setLines} preview={preview} categories={categories} accounts={accounts} roles={roles} catalog={catalog} error={error} />
        </Card>

        <Card title="Diskon, pajak, dan biaya lain">
          <div className="form-grid">
            <Field label="Diskon" error={fieldMessage(error, 'discount_amount')} hint="Mengurangi subtotal; tidak boleh melebihi subtotal.">
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={h.discount_amount} onChange={(e) => set({ discount_amount: e.target.value })} {...p} />}
            </Field>
            <Field label="Pajak" error={fieldMessage(error, 'tax_amount')} hint="Jumlah pajak sesuai faktur vendor.">
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
          <p className="muted preview-note">Ini pratinjau yang dihitung saat Anda mengetik. Total yang tersimpan, jatuh tempo, dan saldo berasal dari server.</p>
        </Card>

        <div className="actions form-actions">
          <Button onClick={() => navigate(invoice ? `/app/akuntansi/faktur-vendor/${invoice.id}` : '/app/akuntansi/faktur-vendor')}>Batal</Button>
          <Button type="submit" variant={access.canChange('accounting.ap_invoice.submit') ? 'secondary' : 'primary'} loading={busy}>Simpan draf</Button>
          {access.canChange('accounting.ap_invoice.submit') && <Button variant="primary" loading={busy} onClick={() => void save(true)}>Simpan & ajukan</Button>}
        </div>
      </form>
    </>
  )
}
