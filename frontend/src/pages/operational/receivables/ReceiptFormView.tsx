import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useToast } from '../../../components/Toast'
import { Banner, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader } from '../../../components/ui'
import { amountToApi, parseAmount } from '../../../lib/accounting'
import { api } from '../../../lib/api'
import { useResource } from '../../../lib/hooks'
import { API, MODULES, useCashBankAccounts, useCustomers, useModuleAccess } from '../../../lib/operational'
import { paymentMethodLabels } from '../../../lib/operationalLabels'
import { useDimensions } from '../../accounting/data'
import { AllocationSummary, AllocationTable, type AllocationLabels, type AllocationRow } from '../payables/AllocationTable'
import { useBusinessDate } from '../payables/lists'
import { allocationErrorFor, errorText, fieldMessage, useAct } from '../payables/messages'
import { previewAllocation, type AllocationInputs } from '../payables/paymentForm'
import { DimensionFields } from '../shared'
import { AR_PATH } from './paths'
import { receiptAllocationRows, receiptAllocationsFrom, receiptHeaderFrom, receiptPayload, receiptProblem, type ReceiptHeader } from './receiptForm'
import type { OpenArInvoice, Receipt, SuggestedAllocation } from './types'

const LABELS: AllocationLabels = { caption: 'Faktur terbuka dan alokasi penerimaan', reference: 'Referensi pelanggan', outstanding: 'Saldo piutang' }

/**
 * Create / edit form of a DRAFT customer receipt with its allocation to the customer's open invoices. Same approach as the vendor
 * payment: the proposal comes from the server, the typed amounts are previewed in exact arithmetic, and the receipt must be allocated
 * in full before it is submitted or posted (the server refuses otherwise and reports the figures).
 */
export function ReceiptFormView({ receipt }: { receipt: Receipt | null }) {
  const navigate = useNavigate()
  const toast = useToast()
  const today = useBusinessDate()
  const access = useModuleAccess(MODULES.ar)
  const cashAccess = useModuleAccess(MODULES.cashBank)
  const { busy, error, run, clearError } = useAct()
  const suggestion = useAct()
  const customerList = useCustomers('ACTIVE')
  const cash = useCashBankAccounts('ACTIVE')
  const { catalog } = useDimensions()

  const [h, setH] = useState<ReceiptHeader>(() => receiptHeaderFrom(receipt, today))
  const [alloc, setAlloc] = useState<AllocationInputs>(() => receiptAllocationsFrom(receipt))
  const [hint, setHint] = useState<string | null>(null)
  const set = (patch: Partial<ReceiptHeader>) => setH((s) => ({ ...s, ...patch }))

  const open = useResource(async () => (h.customer_id ? (await api.get<{ data: OpenArInvoice[] }>(`${API}/customers/${h.customer_id}/open-invoices`)).data.data : []), [h.customer_id])

  const customers = receipt?.customer && !customerList.customers.some((c) => c.id === receipt.customer_id) ? [...customerList.customers, receipt.customer] : customerList.customers
  const accounts = receipt?.cash_bank_account && !cash.accounts.some((a) => a.id === receipt.cash_bank_account_id) ? [...cash.accounts, receipt.cash_bank_account] : cash.accounts

  // Open invoices, plus any invoice this draft still names that is no longer open (so the user can clear it).
  const openRows: AllocationRow[] = (open.data ?? []).map((i) => ({ id: i.id, document_number: i.document_number, reference: i.customer_reference ?? '', due_date: i.due_date, outstanding_amount: i.outstanding_amount }))
  const staleRows: AllocationRow[] = h.customer_id === receipt?.customer_id
    ? (receipt?.allocations ?? []).filter((a) => !openRows.some((r) => r.id === a.ar_invoice_id) && a.invoice).map((a) => ({ id: a.ar_invoice_id, document_number: a.invoice?.document_number ?? null, reference: a.invoice?.customer_reference ?? '', due_date: a.invoice?.due_date ?? '', outstanding_amount: null }))
    : []
  const rows = open.data && !open.loading ? [...openRows, ...staleRows] : []
  const preview = previewAllocation(h.amount, alloc)
  const sentIds = receiptAllocationRows(alloc).map((a) => a.ar_invoice_id)
  // Submitting posts nothing, but the API refuses a receipt whose cash/bank module is read-only, so the button follows both entitlements.
  const canSubmit = access.canChange('accounting.ar_receipt.submit') && cashAccess.writable

  function chooseCustomer(id: string) {
    set({ customer_id: id })
    setAlloc({}) // allocations belong to the previous customer's invoices
  }

  function chooseCashAccount(id: string) {
    const account = cash.byId.get(id)
    // The account's branch / business unit are the default dimensions until the user picks their own.
    set({ cash_bank_account_id: id, ...(account && !h.branch_id && !h.business_unit_id && { branch_id: account.branch_id ?? '', business_unit_id: account.business_unit_id ?? '' }) })
  }

  async function autoAllocate() {
    const units = parseAmount(h.amount)
    if (!h.customer_id || units === null || units <= 0n) {
      setHint('Pilih pelanggan dan isi jumlah penerimaan terlebih dahulu.')
      return
    }
    setHint(null)
    const r = await suggestion.run(async () => (await api.get<{ data: SuggestedAllocation[] }>(`${API}/customers/${h.customer_id}/allocation-suggestion`, { params: { amount: amountToApi(units), ...(h.posting_date && { posting_date: h.posting_date }) } })).data.data)
    if (r.ok) setAlloc(Object.fromEntries(r.value.map((a) => [a.ar_invoice_id, a.amount])))
  }

  async function save(thenSubmit: boolean) {
    const problem = receiptProblem(h, alloc)
    setHint(problem)
    if (problem) return
    clearError()
    const body = receiptPayload(h, alloc)
    const saved = await run(async () => {
      const draft = (await (receipt ? api.patch<Receipt>(`${API}/customer-receipts/${receipt.id}`, body) : api.post<Receipt>(`${API}/customer-receipts`, body))).data
      let submitError: unknown = null
      if (thenSubmit) {
        try {
          await api.post(`${API}/customer-receipts/${draft.id}/submit`)
        } catch (e) {
          submitError = e // the draft is saved: do not make the user create it twice
        }
      }
      return { draft, submitError }
    })
    if (!saved.ok) return
    if (saved.value.submitError) toast.error(`Draf disimpan, tetapi belum dapat diajukan: ${errorText(saved.value.submitError)}`)
    else toast.success(thenSubmit ? 'Penerimaan disimpan dan diajukan.' : 'Draf penerimaan disimpan.')
    navigate(`${AR_PATH.receipts}/${saved.value.draft.id}`)
  }

  if ((customerList.loading && customerList.customers.length === 0 && !customerList.error) || (cash.loading && cash.accounts.length === 0 && !cash.error)) return <Loading />
  if (customerList.error) return <ErrorNotice error={customerList.error} onRetry={customerList.reload} />
  if (cash.error) return <ErrorNotice error={cash.error} onRetry={cash.reload} />

  return (
    <>
      <PageHeader
        title={receipt ? `Ubah draf penerimaan${receipt.reference ? ` ${receipt.reference}` : ''}` : 'Penerimaan pelanggan baru'}
        description="Penerimaan harus dialokasikan penuh ke faktur pelanggan yang sudah diposting sebelum diajukan atau diposting. Saldo faktur dihitung server."
      />
      <form onSubmit={(e) => { e.preventDefault(); void save(false) }} noValidate className="stack">
        {error != null && <Banner tone="bad">{errorText(error)}</Banner>}
        {hint && <Banner tone="warn">{hint}</Banner>}

        <Card title="Informasi penerimaan">
          <div className="form-grid">
            <Field label="Pelanggan" error={fieldMessage(error, 'customer_id')}>
              {(p) => (
                <select className="select" required value={h.customer_id} onChange={(e) => chooseCustomer(e.target.value)} {...p}>
                  <option value="">Pilih pelanggan…</option>
                  {customers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                </select>
              )}
            </Field>
            <Field label="Akun kas/bank" error={fieldMessage(error, 'cash_bank_account_id')} hint="Akun yang menerima uang.">
              {(p) => (
                <select className="select" required value={h.cash_bank_account_id} onChange={(e) => chooseCashAccount(e.target.value)} {...p}>
                  <option value="">Pilih akun kas/bank…</option>
                  {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
                </select>
              )}
            </Field>
            <Field label="Jumlah penerimaan" error={fieldMessage(error, 'amount')} hint="Mata uang fungsional.">
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" required value={h.amount} onChange={(e) => set({ amount: e.target.value })} {...p} />}
            </Field>
            <Field label="Metode penerimaan" error={fieldMessage(error, 'receipt_method')}>
              {(p) => (
                <select className="select" value={h.receipt_method} onChange={(e) => set({ receipt_method: e.target.value })} {...p}>
                  <option value="">Tidak ditentukan</option>
                  {Object.entries(paymentMethodLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
              )}
            </Field>
            <Field label="Tanggal penerimaan" error={fieldMessage(error, 'receipt_date')}>
              {(p) => <input className="input" type="date" required value={h.receipt_date} onChange={(e) => set({ receipt_date: e.target.value, ...(h.posting_date === h.receipt_date && { posting_date: e.target.value }) })} {...p} />}
            </Field>
            <Field label="Tanggal posting" error={fieldMessage(error, 'posting_date')} hint="Menentukan periode akuntansi; tidak boleh sebelum tanggal faktur yang dilunasi.">
              {(p) => <input className="input" type="date" required value={h.posting_date} onChange={(e) => set({ posting_date: e.target.value })} {...p} />}
            </Field>
            <Field label="Referensi" error={fieldMessage(error, 'reference')} hint="Nomor transfer, cek, atau giro dari pelanggan.">
              {(p) => <input className="input" maxLength={100} value={h.reference} onChange={(e) => set({ reference: e.target.value })} {...p} />}
            </Field>
            <Field label="Deskripsi" error={fieldMessage(error, 'description')}>
              {(p) => <input className="input" maxLength={500} value={h.description} onChange={(e) => set({ description: e.target.value })} {...p} />}
            </Field>
            <DimensionFields catalog={catalog} value={h} onChange={set} error={error} />
          </div>
        </Card>

        <Card
          title="Alokasi ke faktur"
          actions={<Button size="sm" loading={suggestion.busy} disabled={!h.customer_id} onClick={() => void autoAllocate()}>Alokasikan otomatis</Button>}
          flush
        >
          <div className="card-body stack">
            <p className="muted" style={{ margin: 0 }}>Alokasi otomatis diusulkan server mulai dari jatuh tempo terlama. Anda dapat mengubah angkanya sebelum menyimpan.</p>
            {suggestion.error != null && <ErrorNotice error={suggestion.error} />}
          </div>
          {!h.customer_id ? (
            <EmptyState title="Pilih pelanggan">Faktur terbuka milik pelanggan akan tampil di sini.</EmptyState>
          ) : open.loading ? (
            <Loading />
          ) : open.error ? (
            <ErrorNotice error={open.error} onRetry={open.reload} />
          ) : rows.length === 0 ? (
            <EmptyState title="Tidak ada faktur terbuka">Pelanggan ini tidak memiliki faktur terposting dengan saldo piutang.</EmptyState>
          ) : (
            <AllocationTable rows={rows} values={alloc} onChange={(id, text) => setAlloc((s) => ({ ...s, [id]: text }))} errorFor={(id) => allocationErrorFor(error, sentIds, id)} labels={LABELS} />
          )}
          <div className="card-body">
            <AllocationSummary preview={preview} noun="penerimaan" />
            <p className="muted preview-note">Ini pratinjau yang dihitung saat Anda mengetik. Server memeriksa alokasi terhadap saldo faktur dan jumlah penerimaan saat disimpan, diajukan, dan diposting.</p>
          </div>
        </Card>

        <div className="actions form-actions">
          <Button onClick={() => navigate(receipt ? `${AR_PATH.receipts}/${receipt.id}` : AR_PATH.receipts)}>Batal</Button>
          <Button type="submit" variant={canSubmit ? 'secondary' : 'primary'} loading={busy}>Simpan draf</Button>
          {canSubmit && <Button variant="primary" loading={busy} onClick={() => void save(true)}>Simpan & ajukan</Button>}
        </div>
      </form>
    </>
  )
}
