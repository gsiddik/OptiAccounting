import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useToast } from '../../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Field, Loading, PageHeader } from '../../../components/ui'
import { amountToApi } from '../../../lib/accounting'
import { api } from '../../../lib/api'
import { useResource } from '../../../lib/hooks'
import { API, MODULES, useCustomers, useModuleAccess, type Page } from '../../../lib/operational'
import { useAccounts, useDimensions } from '../../accounting/data'
import { destinationRoles, useBusinessDate, useLineRoles } from '../payables/lists'
import { errorText, fieldMessage, useAct } from '../payables/messages'
import { DimensionFields, Money } from '../shared'
import { ArLines } from './ArLines'
import { arLinesFrom, type ArLineForm } from './arLines'
import { creditNoteHeaderFrom, creditNotePayload, creditNoteProblem, previewCreditNote, type CreditNoteHeader } from './creditNoteForm'
import { AR_PATH } from './paths'
import type { ArInvoice, ArInvoiceRow, CreditNote } from './types'

type InvoiceChoice = { id: string; label: string; outstanding: string | null }

const choiceLabel = (number: string | null, reference: string | null) => `${number ?? 'Faktur'}${reference ? ` · ${reference}` : ''}`

/**
 * Create / edit form of a DRAFT credit note. A note credits ONE posted invoice of the customer; the invoice's outstanding balance comes
 * from the server (never computed here) and the note's total may not exceed it (the server refuses a note that would). The invoice and the
 * customer of a saved note cannot be changed. `prefill` is the invoice a new note starts from.
 */
export function CreditNoteFormView({ note, prefill }: { note: CreditNote | null; prefill: ArInvoice | null }) {
  const navigate = useNavigate()
  const toast = useToast()
  const today = useBusinessDate()
  const access = useModuleAccess(MODULES.ar)
  const { busy, error, run, clearError } = useAct()
  const customerList = useCustomers('ACTIVE')
  const { accounts } = useAccounts()
  const roles = destinationRoles(useLineRoles())
  const { catalog } = useDimensions()

  const [h, setH] = useState<CreditNoteHeader>(() => creditNoteHeaderFrom(note, today, prefill))
  const [lines, setLines] = useState<ArLineForm[]>(() => arLinesFrom(note?.lines))
  const [hint, setHint] = useState<string | null>(null)
  const set = (patch: Partial<CreditNoteHeader>) => setH((s) => ({ ...s, ...patch }))
  const locked = note !== null

  // Posted invoices of the chosen customer that still have something outstanding.
  const invoices = useResource(
    async () => (h.customer_id ? (await api.get<Page<ArInvoiceRow>>(`${API}/ar-invoices`, { params: { status: 'POSTED', open: 1, customer_id: h.customer_id, per_page: 100 } })).data.data : []),
    [h.customer_id],
  )
  const choices: InvoiceChoice[] = (invoices.data ?? []).map((i) => ({ id: i.id, label: choiceLabel(i.document_number, i.customer_reference), outstanding: i.outstanding_amount }))
  // The invoice this note already names (or starts from) is always selectable, even when it is no longer in the open list.
  const named = note?.invoice ?? prefill
  if (named && h.customer_id === (note?.customer_id ?? prefill?.customer_id) && !choices.some((c) => c.id === named.id)) {
    const outstanding = note ? (note.invoice_outstanding ?? null) : (prefill?.outstanding_amount ?? null)
    choices.push({ id: named.id, label: choiceLabel(named.document_number, named.customer_reference), outstanding })
  }
  const chosen = choices.find((c) => c.id === h.ar_invoice_id)

  const customers = note?.customer && !customerList.customers.some((c) => c.id === note.customer_id) ? [...customerList.customers, note.customer] : customerList.customers
  const preview = previewCreditNote(h, lines)

  async function save(thenSubmit: boolean) {
    const problem = creditNoteProblem(h, lines)
    setHint(problem)
    if (problem) return
    clearError()
    const body = creditNotePayload(h, lines)
    const saved = await run(async () => {
      const draft = (await (note ? api.patch<CreditNote>(`${API}/ar-credit-notes/${note.id}`, body) : api.post<CreditNote>(`${API}/ar-credit-notes`, body))).data
      let submitError: unknown = null
      if (thenSubmit) {
        try {
          await api.post(`${API}/ar-credit-notes/${draft.id}/submit`)
        } catch (e) {
          submitError = e // the draft is saved: do not make the user create it twice
        }
      }
      return { draft, submitError }
    })
    if (!saved.ok) return
    if (saved.value.submitError) toast.error(`Draf disimpan, tetapi belum dapat diajukan: ${errorText(saved.value.submitError)}`)
    else toast.success(thenSubmit ? 'Nota kredit disimpan dan diajukan.' : 'Draf nota kredit disimpan.')
    navigate(`${AR_PATH.creditNotes}/${saved.value.draft.id}`)
  }

  if (customerList.loading && customerList.customers.length === 0 && !customerList.error) return <Loading />
  if (customerList.error) return <ErrorNotice error={customerList.error} onRetry={customerList.reload} />

  return (
    <>
      <PageHeader
        title={note ? `Ubah draf nota kredit${note.document_number ? ` ${note.document_number}` : ''}` : 'Nota kredit baru'}
        description="Nota kredit mengurangi saldo piutang satu faktur yang sudah diposting. Totalnya tidak boleh melebihi saldo piutang faktur; server menghitung dan memeriksanya."
      />
      <form onSubmit={(e) => { e.preventDefault(); void save(false) }} noValidate className="stack">
        {error != null && <Banner tone="bad">{errorText(error)}</Banner>}
        {hint && <Banner tone="warn">{hint}</Banner>}

        <Card title="Informasi nota kredit">
          <div className="form-grid">
            <Field label="Pelanggan" error={fieldMessage(error, 'customer_id')}>
              {(p) => (
                <select className="select" required disabled={locked || prefill !== null} value={h.customer_id} onChange={(e) => set({ customer_id: e.target.value, ar_invoice_id: '' })} {...p}>
                  <option value="">Pilih pelanggan…</option>
                  {customers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                </select>
              )}
            </Field>
            <Field label="Faktur yang dikreditkan" error={fieldMessage(error, 'ar_invoice_id')} hint={locked ? 'Faktur nota kredit yang sudah disimpan tidak dapat diganti; buat nota baru.' : 'Faktur terposting milik pelanggan yang masih memiliki saldo piutang.'}>
              {(p) => (
                <select className="select" required disabled={locked || !h.customer_id} value={h.ar_invoice_id} onChange={(e) => set({ ar_invoice_id: e.target.value })} {...p}>
                  <option value="">{h.customer_id ? 'Pilih faktur…' : 'Pilih pelanggan terlebih dahulu'}</option>
                  {choices.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
                </select>
              )}
            </Field>
            <div className="field" role="status" aria-live="polite">
              <span className="label">Saldo piutang faktur</span>
              {chosen && chosen.outstanding !== null ? <span><Money value={chosen.outstanding} strong /></span> : <span className="muted">{h.customer_id && !invoices.loading && choices.length === 0 ? 'Pelanggan ini tidak memiliki faktur terbuka.' : '—'}</span>}
              <span className="hint">Dihitung server dari penerimaan dan nota kredit yang sudah diposting.</span>
            </div>
            <Field label="Alasan" error={fieldMessage(error, 'reason')} full>
              {(p) => <input className="input" required maxLength={500} value={h.reason} onChange={(e) => set({ reason: e.target.value })} {...p} />}
            </Field>
            <Field label="Referensi" error={fieldMessage(error, 'reference')} hint="Nomor retur atau dokumen sumber, opsional.">
              {(p) => <input className="input" maxLength={100} value={h.reference} onChange={(e) => set({ reference: e.target.value })} {...p} />}
            </Field>
            <Field label="Tanggal dokumen" error={fieldMessage(error, 'document_date')}>
              {(p) => <input className="input" type="date" required value={h.document_date} onChange={(e) => set({ document_date: e.target.value, ...(h.posting_date === h.document_date && { posting_date: e.target.value }) })} {...p} />}
            </Field>
            <Field label="Tanggal posting" error={fieldMessage(error, 'posting_date')} hint="Menentukan periode akuntansi; tidak boleh sebelum tanggal posting faktur.">
              {(p) => <input className="input" type="date" required value={h.posting_date} onChange={(e) => set({ posting_date: e.target.value })} {...p} />}
            </Field>
            <Field label="Pajak" error={fieldMessage(error, 'tax_amount')} hint="Pajak keluaran yang ikut dikreditkan.">
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={h.tax_amount} onChange={(e) => set({ tax_amount: e.target.value })} {...p} />}
            </Field>
            <DimensionFields catalog={catalog} value={h} onChange={set} error={error} />
          </div>
        </Card>

        <Card title="Baris nota kredit">
          <ArLines lines={lines} onChange={setLines} preview={preview} accounts={accounts} roles={roles} catalog={catalog} error={error} />
        </Card>

        <Card title="Ringkasan (pratinjau)">
          <div className="totals" role="status" aria-live="polite">
            <span>Subtotal <Money value={amountToApi(preview.subtotal)} /></span>
            <span>Pajak <Money value={amountToApi(preview.tax)} /></span>
            <span>Total pratinjau <Money value={amountToApi(preview.total)} strong /></span>
          </div>
          <p className="muted preview-note">Ini pratinjau yang dihitung saat Anda mengetik. Server menghitung total nota kredit dan menolaknya bila melebihi saldo piutang faktur.</p>
        </Card>

        <div className="actions form-actions">
          <Button onClick={() => navigate(note ? `${AR_PATH.creditNotes}/${note.id}` : AR_PATH.creditNotes)}>Batal</Button>
          <Button type="submit" variant={access.canChange('accounting.ar_credit_note.submit') ? 'secondary' : 'primary'} loading={busy}>Simpan draf</Button>
          {access.canChange('accounting.ar_credit_note.submit') && <Button variant="primary" loading={busy} onClick={() => void save(true)}>Simpan & ajukan</Button>}
        </div>
      </form>
    </>
  )
}
