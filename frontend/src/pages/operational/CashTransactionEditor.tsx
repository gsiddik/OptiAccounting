import { useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, EmptyState, Field, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { todayIn } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { API, useCashBankAccounts } from '../../lib/operational'
import { accountLabel, useAccounts, useDimensions } from '../accounting/data'
import { buildCashBody, cashFormFrom, emptyCashForm, previewAmount, type CashForm } from './cash/payload'
import { KIND, type CashKind, type CashTransaction } from './cash/types'
import { fieldMessage, localized } from './expense/errors'
import { DimensionFields, ErrorNotice } from './shared'

export default function CashTransactionEditor({ kind }: { kind: CashKind }) {
  const { id } = useParams()
  const k = KIND[kind]
  const transaction = useResource(async () => (id ? (await api.get<CashTransaction>(`${API}/${k.path}/${id}`)).data : null), [id, k.path])

  if (transaction.loading && !transaction.data && id) return <Loading />
  if (transaction.error) return <ErrorNotice error={transaction.error} onRetry={transaction.reload} />
  if (transaction.data && transaction.data.status !== 'DRAFT') {
    return <EmptyState title="Transaksi tidak dapat diubah">Hanya transaksi berstatus draf yang dapat diubah. Buka transaksinya untuk melihat riwayat.</EmptyState>
  }
  return <Form key={`${kind}:${id ?? 'new'}`} kind={kind} transaction={transaction.data} />
}

function Form({ kind, transaction }: { kind: CashKind; transaction: CashTransaction | null }) {
  const k = KIND[kind]
  const navigate = useNavigate()
  const toast = useToast()
  const { tenant } = useCapabilities()
  const { busy, error, run, clearError } = useAction()
  const cash = useCashBankAccounts()
  const gl = useAccounts()
  const { catalog } = useDimensions()
  const today = tenant?.business_date ?? todayIn()
  const [f, setF] = useState<CashForm>(() => (transaction ? cashFormFrom(transaction) : emptyCashForm(today)))
  const [hint, setHint] = useState<string | null>(null)
  const set = (name: keyof CashForm) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [name]: e.target.value }))
  const err = localized(error)

  // The cash/bank account: active ones, plus the draft's own account if it was deactivated since. The counter account: never a control
  // account and never the GL account of any cash/bank account (the API refuses both).
  const cashOptions = cash.accounts.filter((a) => a.status === 'ACTIVE' || a.id === f.cash_bank_account_id)
  const cashGl = useMemo(() => new Set(cash.accounts.map((a) => a.account_id)), [cash.accounts])
  const counterOptions = gl.accounts.filter((a) => a.id === f.counter_account_id || (a.status === 'ACTIVE' && a.is_postable && !a.is_control && !cashGl.has(a.id)))
  const preview = previewAmount(f.amount)

  function chooseCash(id: string) {
    // Like the API, a document without its own placement takes the one of its cash/bank account.
    const chosen = cash.byId.get(id)
    setF((s) => ({
      ...s,
      cash_bank_account_id: id,
      ...(chosen && !s.branch_id && !s.business_unit_id ? { branch_id: chosen.branch_id ?? '', business_unit_id: chosen.business_unit_id ?? '' } : {}),
    }))
  }

  async function save() {
    const built = buildCashBody(f)
    if ('problem' in built) return setHint(built.problem)
    setHint(null)
    clearError()
    const saved = await run(async () => (await (transaction ? api.patch<CashTransaction>(`${API}/${k.path}/${transaction.id}`, built.body) : api.post<CashTransaction>(`${API}/${k.path}`, built.body))).data)
    if (saved.ok) {
      toast.success('Draf transaksi kas disimpan.')
      navigate(`${k.route}/${saved.value.id}`)
    }
  }

  return (
    <>
      <PageHeader title={transaction ? `Ubah draf ${k.title.toLowerCase()}` : k.newLabel} description={`${k.direction}. ${k.posting}`} />
      <form onSubmit={(e) => { e.preventDefault(); void save() }} noValidate className="stack">
        {err != null && <Banner tone="bad">{describeError(err)}</Banner>}
        {hint && <Banner tone="warn">{hint}</Banner>}
        {(cash.error != null || gl.error != null) && <Banner tone="warn">Daftar akun kas/bank atau akun buku besar tidak dapat dimuat. Pastikan Anda berhak melihatnya, lalu muat ulang halaman.</Banner>}

        <Card title="Akun">
          <div className="form-grid">
            <Field label="Akun kas/bank" error={fieldMessage(err, 'cash_bank_account_id')} hint="Hanya akun kas/bank yang aktif.">
              {(p) => (
                <select className="select" required value={f.cash_bank_account_id} onChange={(e) => chooseCash(e.target.value)} {...p}>
                  <option value="">Pilih akun…</option>
                  {cashOptions.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
                </select>
              )}
            </Field>
            <Field label={k.counterLabel} error={fieldMessage(err, 'counter_account_id')} hint="Akun buku besar yang aktif dan dapat diposting; bukan akun kontrol dan bukan akun kas/bank.">
              {(p) => (
                <select className="select" required value={f.counter_account_id} onChange={set('counter_account_id')} {...p}>
                  <option value="">Pilih akun…</option>
                  {counterOptions.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
                </select>
              )}
            </Field>
            <Field label="Jumlah" error={fieldMessage(err, 'amount')} hint="Contoh 1500000 atau 1500000,50.">
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={f.amount} onChange={set('amount')} {...p} />}
            </Field>
          </div>
          <p className="muted preview-note" role="status" aria-live="polite">
            {preview === null ? 'Jumlah belum valid.' : <>Pratinjau: <strong className="money">{preview}</strong></>} Pratinjau saat mengetik; jumlah yang tersimpan dihitung server.
          </p>
        </Card>

        <Card title="Rincian">
          <div className="form-grid">
            <Field label="Tanggal transaksi" error={fieldMessage(err, 'transaction_date')}>
              {(p) => <input className="input" type="date" required value={f.transaction_date} onChange={(e) => setF((s) => ({ ...s, transaction_date: e.target.value, posting_date: s.posting_date === s.transaction_date ? e.target.value : s.posting_date }))} {...p} />}
            </Field>
            <Field label="Tanggal posting" error={fieldMessage(err, 'posting_date')} hint="Menentukan periode akuntansi.">
              {(p) => <input className="input" type="date" required value={f.posting_date} onChange={set('posting_date')} {...p} />}
            </Field>
            <Field label="Tujuan" error={fieldMessage(err, 'purpose')} hint="Mengapa uang ini berpindah. Wajib, minimal 3 karakter." full>
              {(p) => <input className="input" required maxLength={150} value={f.purpose} onChange={set('purpose')} {...p} />}
            </Field>
            <Field label="Deskripsi" error={fieldMessage(err, 'description')} full>
              {(p) => <input className="input" required maxLength={500} value={f.description} onChange={set('description')} {...p} />}
            </Field>
            <Field label={kind === 'PAYMENT' ? 'Dibayarkan kepada' : 'Diterima dari'} error={fieldMessage(err, 'counterparty_name')}>
              {(p) => <input className="input" maxLength={150} value={f.counterparty_name} onChange={set('counterparty_name')} {...p} />}
            </Field>
            <Field label="Referensi" error={fieldMessage(err, 'reference')} hint="Nomor dokumen sumber, opsional.">
              {(p) => <input className="input" maxLength={100} value={f.reference} onChange={set('reference')} {...p} />}
            </Field>
          </div>
        </Card>

        {(catalog.branches.length > 0 || catalog.business_units.length > 0 || catalog.cost_centers.length > 0) && (
          <Card title="Dimensi">
            <div className="form-grid">
              <DimensionFields catalog={catalog} value={f} onChange={(patch) => setF((s) => ({ ...s, ...patch }))} error={err} />
            </div>
          </Card>
        )}

        <div className="actions form-actions">
          <Button onClick={() => navigate(transaction ? `${k.route}/${transaction.id}` : k.route)}>Batal</Button>
          <Button type="submit" variant="primary" loading={busy}>Simpan draf</Button>
        </div>
      </form>
    </>
  )
}
