import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useToast } from '../../../components/Toast'
import { Banner, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader } from '../../../components/ui'
import { amountToApi, parseAmount } from '../../../lib/accounting'
import { api } from '../../../lib/api'
import { useResource } from '../../../lib/hooks'
import { API, MODULES, useCashBankAccounts, useModuleAccess, useVendors } from '../../../lib/operational'
import { paymentMethodLabels } from '../../../lib/operationalLabels'
import { useDimensions } from '../../accounting/data'
import { CurrencyFields, ExchangeRateLink } from '../foreign'
import { currencyChoiceFrom, currencyPayload, isForeignDoc, placesHint, useForeignSupport, type CurrencyChoice } from '../foreignSupport'
import { DimensionFields } from '../shared'
import { AllocationSummary, AllocationTable, type AllocationRow } from './AllocationTable'
import { useBusinessDate } from './lists'
import { allocationErrorFor, errorText, fieldMessage, useAct } from './messages'
import { allocationRows, allocationsFrom, paymentHeaderFrom, paymentPayload, paymentProblem, previewAllocation, type AllocationInputs, type PaymentHeader } from './paymentForm'
import type { OpenInvoice, Payment, SuggestedAllocation } from './types'

/** Create / edit form of a DRAFT vendor payment with its allocation to open invoices. The proposal comes from the server; amounts are previewed in exact arithmetic. */
export function PaymentFormView({ payment }: { payment: Payment | null }) {
  const navigate = useNavigate()
  const toast = useToast()
  const today = useBusinessDate()
  const access = useModuleAccess(MODULES.ap)
  const { busy, error, run, clearError } = useAct()
  const suggestion = useAct()
  const vendorList = useVendors('ACTIVE')
  const cash = useCashBankAccounts('ACTIVE')
  const { catalog } = useDimensions()
  const foreign = useForeignSupport()

  const [h, setH] = useState<PaymentHeader>(() => paymentHeaderFrom(payment, today))
  const [alloc, setAlloc] = useState<AllocationInputs>(() => allocationsFrom(payment))
  const [choice, setChoice] = useState<CurrencyChoice>(() => currencyChoiceFrom(payment))
  const [hint, setHint] = useState<string | null>(null)
  const set = (patch: Partial<PaymentHeader>) => setH((s) => ({ ...s, ...patch }))

  // A payment settles invoices of its own currency only: the open invoices and the allocation proposal are asked for the payment's currency.
  const open = useResource(
    async () => (h.vendor_id ? (await api.get<{ data: OpenInvoice[] }>(`${API}/vendors/${h.vendor_id}/open-invoices`, { params: choice.currency ? { currency: choice.currency } : undefined })).data.data : []),
    [h.vendor_id, choice.currency],
  )
  const places = choice.currency ? foreign.placesOf(choice.currency) : null
  const fx = choice.currency && places !== null ? { code: choice.currency, places } : null

  function chooseCurrency(patch: Partial<CurrencyChoice>) {
    setChoice((c) => ({ ...c, ...patch }))
    if ('currency' in patch) setAlloc({}) // allocations belong to the invoices of the previous currency
  }

  const vendors = payment?.vendor && !vendorList.vendors.some((v) => v.id === payment.vendor_id) ? [...vendorList.vendors, payment.vendor] : vendorList.vendors
  const accounts = payment?.cash_bank_account && !cash.accounts.some((a) => a.id === payment.cash_bank_account_id) ? [...cash.accounts, payment.cash_bank_account] : cash.accounts

  // Open invoices, plus any invoice this draft still names that is no longer open (so the user can clear it).
  const openRows: AllocationRow[] = (open.data ?? []).map((i) => ({
    id: i.id, document_number: i.document_number, reference: i.vendor_invoice_number, due_date: i.due_date, outstanding_amount: i.outstanding_amount,
    currency: i.currency, exchange_rate: i.exchange_rate, outstanding_functional: i.outstanding_functional,
  }))
  const staleRows: AllocationRow[] = h.vendor_id === payment?.vendor_id
    ? (payment?.allocations ?? []).filter((a) => !openRows.some((r) => r.id === a.ap_invoice_id) && a.invoice).map((a) => ({ id: a.ap_invoice_id, document_number: a.invoice?.document_number ?? null, reference: a.invoice?.vendor_invoice_number ?? '', due_date: a.invoice?.due_date ?? '', outstanding_amount: null }))
    : []
  const rows = open.data && !open.loading ? [...openRows, ...staleRows] : []
  const preview = previewAllocation(h.amount, alloc)
  const sentIds = allocationRows(alloc).map((a) => a.ap_invoice_id)

  function chooseVendor(id: string) {
    set({ vendor_id: id })
    setAlloc({}) // allocations belong to the previous vendor's invoices
  }

  function chooseCashAccount(id: string) {
    const account = cash.byId.get(id)
    // The account's branch / business unit are the default dimensions until the user picks their own.
    set({ cash_bank_account_id: id, ...(account && !h.branch_id && !h.business_unit_id && { branch_id: account.branch_id ?? '', business_unit_id: account.business_unit_id ?? '' }) })
  }

  async function autoAllocate() {
    const units = parseAmount(h.amount)
    if (!h.vendor_id || units === null || units <= 0n) {
      setHint('Pilih vendor dan isi jumlah pembayaran terlebih dahulu.')
      return
    }
    setHint(null)
    const r = await suggestion.run(async () => (await api.get<{ data: SuggestedAllocation[] }>(`${API}/vendors/${h.vendor_id}/allocation-suggestion`, { params: { amount: amountToApi(units), ...(h.posting_date && { posting_date: h.posting_date }), ...(choice.currency && { currency: choice.currency }) } })).data.data)
    if (r.ok) setAlloc(Object.fromEntries(r.value.map((a) => [a.ap_invoice_id, a.amount])))
  }

  async function save(thenSubmit: boolean) {
    const problem = paymentProblem(h, alloc)
    setHint(problem)
    if (problem) return
    clearError()
    const currency = currencyPayload(choice, { shown: foreign.visible, wasForeign: isForeignDoc(payment), functional: foreign.functional })
    const body = paymentPayload(h, alloc, currency)
    const saved = await run(async () => {
      const draft = (await (payment ? api.patch<Payment>(`${API}/vendor-payments/${payment.id}`, body) : api.post<Payment>(`${API}/vendor-payments`, body))).data
      let submitError: unknown = null
      if (thenSubmit) {
        try {
          await api.post(`${API}/vendor-payments/${draft.id}/submit`)
        } catch (e) {
          submitError = e // the draft is saved: do not make the user create it twice
        }
      }
      return { draft, submitError }
    })
    if (!saved.ok) return
    if (saved.value.submitError) toast.error(`Draf disimpan, tetapi belum dapat diajukan: ${errorText(saved.value.submitError)}`)
    else toast.success(thenSubmit ? 'Pembayaran disimpan dan diajukan.' : 'Draf pembayaran disimpan.')
    navigate(`/app/akuntansi/pembayaran-vendor/${saved.value.draft.id}`)
  }

  if ((vendorList.loading && vendorList.vendors.length === 0 && !vendorList.error) || (cash.loading && cash.accounts.length === 0 && !cash.error)) return <Loading />
  if (vendorList.error) return <ErrorNotice error={vendorList.error} onRetry={vendorList.reload} />
  if (cash.error) return <ErrorNotice error={cash.error} onRetry={cash.reload} />

  return (
    <>
      <PageHeader
        title={payment ? `Ubah draf pembayaran${payment.reference ? ` ${payment.reference}` : ''}` : 'Pembayaran vendor baru'}
        description="Pembayaran harus dialokasikan penuh ke faktur vendor yang sudah diposting sebelum diajukan atau diposting. Saldo faktur dihitung server."
      />
      <form onSubmit={(e) => { e.preventDefault(); void save(false) }} noValidate className="stack">
        {error != null && <Banner tone="bad">{errorText(error)}</Banner>}
        <ExchangeRateLink error={error} />
        {hint && <Banner tone="warn">{hint}</Banner>}

        <Card title="Informasi pembayaran">
          <div className="form-grid">
            <Field label="Vendor" error={fieldMessage(error, 'vendor_id')}>
              {(p) => (
                <select className="select" required value={h.vendor_id} onChange={(e) => chooseVendor(e.target.value)} {...p}>
                  <option value="">Pilih vendor…</option>
                  {vendors.map((v) => <option key={v.id} value={v.id}>{v.code} · {v.name}</option>)}
                </select>
              )}
            </Field>
            <Field label="Akun kas/bank" error={fieldMessage(error, 'cash_bank_account_id')} hint="Akun yang membayar.">
              {(p) => (
                <select className="select" required value={h.cash_bank_account_id} onChange={(e) => chooseCashAccount(e.target.value)} {...p}>
                  <option value="">Pilih akun kas/bank…</option>
                  {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
                </select>
              )}
            </Field>
            <CurrencyFields support={foreign} value={choice} onChange={chooseCurrency} date={h.posting_date} doc={payment} error={error} />
            <Field label="Jumlah pembayaran" error={fieldMessage(error, 'amount')} hint={placesHint(fx, h.amount) ?? 'Mata uang fungsional.'}>
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" required value={h.amount} onChange={(e) => set({ amount: e.target.value })} {...p} />}
            </Field>
            <Field label="Metode pembayaran" error={fieldMessage(error, 'payment_method')}>
              {(p) => (
                <select className="select" value={h.payment_method} onChange={(e) => set({ payment_method: e.target.value })} {...p}>
                  <option value="">Tidak ditentukan</option>
                  {Object.entries(paymentMethodLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
              )}
            </Field>
            <Field label="Tanggal pembayaran" error={fieldMessage(error, 'payment_date')}>
              {(p) => <input className="input" type="date" required value={h.payment_date} onChange={(e) => set({ payment_date: e.target.value, ...(h.posting_date === h.payment_date && { posting_date: e.target.value }) })} {...p} />}
            </Field>
            <Field label="Tanggal posting" error={fieldMessage(error, 'posting_date')} hint="Menentukan periode akuntansi; tidak boleh sebelum tanggal faktur yang dilunasi.">
              {(p) => <input className="input" type="date" required value={h.posting_date} onChange={(e) => set({ posting_date: e.target.value })} {...p} />}
            </Field>
            <Field label="Referensi" error={fieldMessage(error, 'reference')} hint="Nomor transfer, cek, atau giro.">
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
          actions={<Button size="sm" loading={suggestion.busy} disabled={!h.vendor_id} onClick={() => void autoAllocate()}>Alokasikan otomatis</Button>}
          flush
        >
          <div className="card-body stack">
            <p className="muted" style={{ margin: 0 }}>Alokasi otomatis diusulkan server mulai dari jatuh tempo terlama. Anda dapat mengubah angkanya sebelum menyimpan.</p>
            {suggestion.error != null && <ErrorNotice error={suggestion.error} />}
          </div>
          {!h.vendor_id ? (
            <EmptyState title="Pilih vendor">Faktur terbuka milik vendor akan tampil di sini.</EmptyState>
          ) : open.loading ? (
            <Loading />
          ) : open.error ? (
            <ErrorNotice error={open.error} onRetry={open.reload} />
          ) : rows.length === 0 ? (
            <EmptyState title="Tidak ada faktur terbuka">Vendor ini tidak memiliki faktur terposting dengan saldo terutang.</EmptyState>
          ) : (
            <AllocationTable rows={rows} values={alloc} onChange={(id, text) => setAlloc((s) => ({ ...s, [id]: text }))} errorFor={(id) => allocationErrorFor(error, sentIds, id)} foreign={choice.currency !== ''} />
          )}
          <div className="card-body">
            <AllocationSummary preview={preview} />
            <p className="muted preview-note">Ini pratinjau yang dihitung saat Anda mengetik. Server memeriksa alokasi terhadap saldo faktur dan jumlah pembayaran saat disimpan, diajukan, dan diposting.</p>
          </div>
        </Card>

        <div className="actions form-actions">
          <Button onClick={() => navigate(payment ? `/app/akuntansi/pembayaran-vendor/${payment.id}` : '/app/akuntansi/pembayaran-vendor')}>Batal</Button>
          <Button type="submit" variant={access.canChange('accounting.ap_payment.submit') ? 'secondary' : 'primary'} loading={busy}>Simpan draf</Button>
          {access.canChange('accounting.ap_payment.submit') && <Button variant="primary" loading={busy} onClick={() => void save(true)}>Simpan & ajukan</Button>}
        </div>
      </form>
    </>
  )
}
