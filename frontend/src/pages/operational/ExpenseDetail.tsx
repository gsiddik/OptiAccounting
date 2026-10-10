import { Link, useParams } from 'react-router-dom'
import { Banner, Card, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { paymentMethodLabels, settlementLabels } from '../../lib/operationalLabels'
import { localized } from './expense/errors'
import type { Expense } from './expense/types'
import { ErrorNotice, Money, ReadOnlyNotice, Timeline } from './shared'
import { TaxCodeCell } from './taxOptions'
import { formatPercent, useTaxFacts } from './taxSupport'
import { useDocumentActions } from './workflow'

export default function ExpenseDetail() {
  const { id } = useParams()
  const expense = useResource(async () => (await api.get<Expense>(`${API}/expenses/${id}`)).data, [id])
  const e = expense.data
  const settlementModule = e?.settlement === 'PAYABLE' ? MODULES.ap : MODULES.cashBank
  const modules = [MODULES.expense, settlementModule]
  const actions = useDocumentActions({
    base: `${API}/expenses/${id}`,
    status: e?.status ?? '',
    sod: e?.sod,
    noun: 'beban',
    perms: { update: 'accounting.expense.update', submit: 'accounting.expense.submit', approve: 'accounting.expense.approve', post: 'accounting.expense.post', reverse: 'accounting.expense.reverse' },
    modules,
    editPath: `/app/akuntansi/beban/${id}/ubah`,
    onChanged: expense.reload,
  })
  const { readOnly } = useModuleAccess(MODULES.expense)
  // The tax code of the expense and its rate on the expense date, from the server (nothing is requested without tax access). The tax amount is the document's own.
  const taxFacts = useTaxFacts(e?.tax_code_id ? [{ id: 'expense', codeId: e.tax_code_id, amount: e.net_amount }] : [], e?.expense_date ?? '', { calculate: false })

  if (expense.loading && !e) return <Loading />
  if (expense.error || !e) return <ErrorNotice error={localized(expense.error)} onRetry={expense.reload} />
  const payable = e.payable

  return (
    <>
      <PageHeader
        title={e.document_number ?? (e.status === 'DRAFT' ? 'Draf beban' : 'Beban belum bernomor')}
        description={<>{settlementLabels[e.settlement] ?? e.settlement} · <StatusBadge status={e.status} /></>}
        actions={actions.buttons}
      />
      <ReadOnlyNotice show={readOnly} />
      {actions.notes.map((n) => <Banner key={n} tone="info">{n}</Banner>)}
      {actions.error != null && <ErrorNotice error={localized(actions.error)} />}
      {e.status === 'REJECTED' && e.reject_reason && <Banner tone="warn">Ditolak: {e.reject_reason}</Banner>}
      {e.status === 'CANCELLED' && e.cancel_reason && <Banner tone="info">Dibatalkan: {e.cancel_reason}</Banner>}

      <Card title="Rincian beban">
        <dl className="facts">
          <div><dt>Kategori</dt><dd>{e.category ? `${e.category.code} · ${e.category.name}` : '—'}</dd></div>
          <div><dt>Penyelesaian</dt><dd>{settlementLabels[e.settlement] ?? e.settlement}</dd></div>
          <div><dt>Tanggal beban</dt><dd>{formatDate(e.expense_date)}</dd></div>
          <div><dt>Tanggal posting</dt><dd>{formatDate(e.posting_date)}</dd></div>
          {e.settlement === 'PAYABLE' ? (
            <>
              <div><dt>Vendor</dt><dd>{e.vendor ? `${e.vendor.code} · ${e.vendor.name}` : '—'}</dd></div>
              <div><dt>Termin pembayaran</dt><dd>{e.payment_term ? `${e.payment_term.code} · ${e.payment_term.name}` : '—'}</dd></div>
              <div><dt>Jatuh tempo</dt><dd>{formatDate(e.due_date)}</dd></div>
            </>
          ) : (
            <>
              <div><dt>Akun kas/bank</dt><dd>{e.cash_bank_account ? `${e.cash_bank_account.code} · ${e.cash_bank_account.name}` : '—'}{e.cash_bank_account?.account_number_masked && <span className="muted mono"> {e.cash_bank_account.account_number_masked}</span>}</dd></div>
              <div><dt>Metode pembayaran</dt><dd>{e.payment_method ? paymentMethodLabels[e.payment_method] ?? e.payment_method : '—'}</dd></div>
              <div><dt>Penerima</dt><dd>{e.payee_name ?? '—'}</dd></div>
            </>
          )}
          <div><dt>Dokumen pendukung</dt><dd>{e.supporting_document ?? '—'}</dd></div>
          <div><dt>Referensi</dt><dd>{e.reference ?? '—'}</dd></div>
          <div><dt>Dibuat oleh</dt><dd>{e.creator?.name ?? '—'}</dd></div>
          {e.posted_at && <div><dt>Diposting pada</dt><dd>{formatDateTime(e.posted_at)}</dd></div>}
          {e.branch && <div><dt>Cabang</dt><dd>{e.branch.code} · {e.branch.name}</dd></div>}
          {e.business_unit && <div><dt>Unit bisnis</dt><dd>{e.business_unit.code} · {e.business_unit.name}</dd></div>}
          {e.cost_center && <div><dt>Pusat biaya</dt><dd>{e.cost_center.code} · {e.cost_center.name}</dd></div>}
          {e.journal_entry_id && <div><dt>Jurnal</dt><dd><Link to={`/app/akuntansi/jurnal/${e.journal_entry_id}`}>Lihat jurnal</Link></dd></div>}
          {e.reversal_journal_id && <div><dt>Jurnal pembalik</dt><dd><Link to={`/app/akuntansi/jurnal/${e.reversal_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
          <div className="wide"><dt>Deskripsi</dt><dd>{e.description}</dd></div>
          {e.reversal_reason && <div className="wide"><dt>Alasan pembalikan{e.reversal_posting_date ? ` (${formatDate(e.reversal_posting_date)})` : ''}</dt><dd>{e.reversal_reason}</dd></div>}
        </dl>
      </Card>

      <Card title="Jumlah">
        <dl className="facts">
          {e.tax_code_id && <div><dt>Kode pajak</dt><dd><TaxCodeCell taxCodeId={e.tax_code_id} fact={taxFacts.expense} /></dd></div>}
          {e.tax_code_id && e.entered_amount != null && e.entered_amount !== e.net_amount && <div><dt>Jumlah diinput</dt><dd><Money value={e.entered_amount} /></dd></div>}
          <div><dt>{e.tax_code_id ? 'Dasar pajak (neto)' : 'Neto'}</dt><dd><Money value={e.net_amount} /></dd></div>
          <div><dt>Pajak{e.tax_code_id && taxFacts.expense?.rate ? ` (${formatPercent(taxFacts.expense.rate)}%)` : ''}</dt><dd><Money value={e.tax_amount} /></dd></div>
          <div><dt>Total</dt><dd><Money value={e.total_amount} strong /></dd></div>
          <div><dt>Mata uang</dt><dd>{e.currency}</dd></div>
        </dl>
      </Card>

      {payable && (
        <Card title="Utang usaha dari beban ini">
          <dl className="facts">
            <div><dt>Utang</dt><dd><Link to={`/app/akuntansi/faktur-vendor/${payable.id}`}>{payable.document_number ?? 'Lihat utang'}</Link></dd></div>
            <div><dt>Status pembayaran</dt><dd>{payable.status === 'POSTED' ? <StatusBadge status={payable.payment_status} /> : <StatusBadge status={payable.status} />}</dd></div>
            <div><dt>Jatuh tempo</dt><dd>{formatDate(payable.due_date ?? e.due_date)}</dd></div>
            <div><dt>Total</dt><dd><Money value={payable.total_amount} /></dd></div>
            <div><dt>Sudah dibayar</dt><dd><Money value={payable.paid_amount} /></dd></div>
            <div><dt>Sisa terutang</dt><dd><Money value={payable.outstanding_amount} strong /></dd></div>
          </dl>
          <p className="muted preview-note">Utang ini bagian dari sub-buku utang usaha yang sama dengan faktur vendor; dilunasi lewat pembayaran vendor dan dibalik bersama bebannya.</p>
        </Card>
      )}

      <Card title="Riwayat">
        <Timeline transitions={e.transitions} />
      </Card>
      {actions.dialogs}
    </>
  )
}
