import { Link, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { paymentMethodLabels } from '../../lib/operationalLabels'
import type { Payment } from './payables/types'
import { Money, ReadOnlyNotice, Timeline } from './shared'
import { useDocumentActions } from './workflow'

export default function VendorPaymentDetail() {
  const { id } = useParams()
  const payment = useResource(async () => (await api.get<Payment>(`${API}/vendor-payments/${id}`)).data, [id])

  if (payment.loading && !payment.data) return <Loading />
  if (payment.error || !payment.data) return <ErrorNotice error={payment.error} onRetry={payment.reload} />
  return <PaymentView payment={payment.data} reload={payment.reload} />
}

function PaymentView({ payment: pay, reload }: { payment: Payment; reload: () => void }) {
  const access = useModuleAccess(MODULES.ap)
  const { can } = useCapabilities()
  const wf = useDocumentActions({
    base: `${API}/vendor-payments/${pay.id}`,
    status: pay.status,
    sod: pay.sod,
    noun: 'pembayaran',
    // Editing, reopening and cancelling a payment draft all need the create permission (see the API routes).
    perms: { update: 'accounting.ap_payment.create', submit: 'accounting.ap_payment.submit', approve: 'accounting.ap_payment.approve', post: 'accounting.ap_payment.post', reverse: 'accounting.ap_payment.reverse' },
    modules: [MODULES.ap],
    editPath: `/app/akuntansi/pembayaran-vendor/${pay.id}/ubah`,
    onChanged: reload,
  })
  const allocations = pay.allocations ?? []

  return (
    <>
      <PageHeader
        title={pay.document_number ?? (pay.status === 'DRAFT' ? 'Draf pembayaran vendor' : 'Pembayaran belum bernomor')}
        description={<>Pembayaran vendor · <StatusBadge status={pay.status} /></>}
        actions={<><Link to="/app/akuntansi/pembayaran-vendor" className="btn btn-ghost">Kembali ke daftar</Link>{wf.buttons}</>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      {wf.notes.map((n) => <Banner key={n} tone="info">{n}</Banner>)}
      {wf.error != null && <ErrorNotice error={wf.error} />}
      {wf.dialogs}
      {pay.status === 'POSTED' && can('accounting.ap_payment.reverse') && (
        <Banner tone="info">Membalik pembayaran ini membuat jurnal pembalik dan melepas alokasinya, sehingga saldo faktur yang dilunasinya kembali terutang.</Banner>
      )}
      {pay.status === 'REJECTED' && pay.reject_reason && <Banner tone="warn">Ditolak: {pay.reject_reason}</Banner>}
      {pay.status === 'CANCELLED' && pay.cancel_reason && <Banner tone="info">Dibatalkan: {pay.cancel_reason}</Banner>}
      {pay.status === 'REVERSED' && (
        <Banner tone="info">Pembayaran ini sudah dibalik{pay.reversal_reason ? `: ${pay.reversal_reason}` : '.'} Alokasinya dilepas dan saldo faktur kembali terutang.{pay.reversal_journal_id && <> <Link to={`/app/akuntansi/jurnal/${pay.reversal_journal_id}`}>Lihat jurnal pembalik</Link></>}</Banner>
      )}

      <Card>
        <dl className="facts">
          <div><dt>Vendor</dt><dd>{pay.vendor ? `${pay.vendor.code} · ${pay.vendor.name}` : '—'}</dd></div>
          <div><dt>Akun kas/bank</dt><dd>{pay.cash_bank_account ? `${pay.cash_bank_account.code} · ${pay.cash_bank_account.name}` : '—'}</dd></div>
          <div><dt>Jumlah</dt><dd><Money value={pay.amount} strong /></dd></div>
          <div><dt>Teralokasi</dt><dd><Money value={pay.allocated_amount} /></dd></div>
          <div><dt>Belum dialokasikan</dt><dd><Money value={pay.unallocated_amount} /></dd></div>
          <div><dt>Tanggal pembayaran</dt><dd>{formatDate(pay.payment_date)}</dd></div>
          <div><dt>Tanggal posting</dt><dd>{formatDate(pay.posting_date)}</dd></div>
          <div><dt>Metode</dt><dd>{pay.payment_method ? paymentMethodLabels[pay.payment_method] ?? pay.payment_method : '—'}</dd></div>
          <div><dt>Mata uang</dt><dd>{pay.currency}</dd></div>
          <div><dt>Referensi</dt><dd>{pay.reference ?? '—'}</dd></div>
          {pay.gl_account && <div><dt>Akun buku besar</dt><dd>{pay.gl_account.code} · {pay.gl_account.name}</dd></div>}
          {(pay.branch || pay.business_unit || pay.cost_center) && (
            <div><dt>Dimensi</dt><dd>{[pay.branch && `Cabang ${pay.branch.code}`, pay.business_unit && `Unit ${pay.business_unit.code}`, pay.cost_center && `Biaya ${pay.cost_center.code}`].filter(Boolean).join(' · ')}</dd></div>
          )}
          <div><dt>Dibuat oleh</dt><dd>{pay.creator?.name ?? '—'}</dd></div>
          {pay.posted_at && <div><dt>Diposting pada</dt><dd>{formatDateTime(pay.posted_at)}</dd></div>}
          {pay.journal_entry_id && <div><dt>Jurnal</dt><dd><Link to={`/app/akuntansi/jurnal/${pay.journal_entry_id}`}>Lihat jurnal</Link></dd></div>}
          {pay.reversal_journal_id && <div><dt>Jurnal pembalik</dt><dd><Link to={`/app/akuntansi/jurnal/${pay.reversal_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
          {pay.description && <div className="wide"><dt>Deskripsi</dt><dd>{pay.description}</dd></div>}
        </dl>
      </Card>

      <Card title="Alokasi ke faktur" flush>
        {allocations.length === 0 ? (
          <EmptyState title="Belum ada alokasi">Pembayaran harus dialokasikan penuh ke faktur vendor sebelum dapat diajukan atau diposting.</EmptyState>
        ) : (
          <DataTable
            caption="Alokasi pembayaran ke faktur"
            rows={allocations}
            rowKey={(a) => a.id}
            scroll
            columns={[
              { header: 'Faktur', primary: true, cell: (a) => <Link className="mono" to={`/app/akuntansi/faktur-vendor/${a.ap_invoice_id}`}>{a.invoice?.document_number ?? a.invoice?.vendor_invoice_number ?? 'Faktur'}</Link> },
              { header: 'No. faktur vendor', cell: (a) => <span className="mono">{a.invoice?.vendor_invoice_number ?? '—'}</span> },
              { header: 'Jatuh tempo', cell: (a) => formatDate(a.invoice?.due_date) },
              { header: 'Total faktur', align: 'right', cell: (a) => <Money value={a.invoice?.total_amount} /> },
              { header: 'Dialokasikan', align: 'right', cell: (a) => <Money value={a.amount} /> },
              { header: 'Keadaan', cell: (a) => (a.released_at ? <Badge tone="neutral">Dilepas</Badge> : a.is_effective ? <Badge tone="ok">Berlaku</Badge> : pay.status === 'CANCELLED' || pay.status === 'REJECTED' ? <Badge tone="neutral">Tidak berlaku</Badge> : <Badge tone="info">Menunggu posting</Badge>) },
            ]}
          />
        )}
        <div className="table-total">
          <span>Jumlah pembayaran <Money value={pay.amount} /></span>
          <span>Teralokasi <Money value={pay.allocated_amount} strong /></span>
        </div>
      </Card>

      <Card title="Riwayat"><Timeline transitions={pay.transitions} /></Card>
    </>
  )
}
