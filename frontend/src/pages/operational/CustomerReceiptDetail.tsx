import { Link, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { parseAmount } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { paymentMethodLabels } from '../../lib/operationalLabels'
import { AR_PATH } from './receivables/paths'
import type { Receipt } from './receivables/types'
import { Money, ReadOnlyNotice, Timeline } from './shared'
import { useDocumentActions } from './workflow'

export default function CustomerReceiptDetail() {
  const { id } = useParams()
  const receipt = useResource(async () => (await api.get<Receipt>(`${API}/customer-receipts/${id}`)).data, [id])

  if (receipt.loading && !receipt.data) return <Loading />
  if (receipt.error || !receipt.data) return <ErrorNotice error={receipt.error} onRetry={receipt.reload} />
  return <ReceiptView receipt={receipt.data} reload={receipt.reload} />
}

/** True when the server-reported unallocated figure is not zero (the server computed it; this only tells zero from non-zero). */
const hasUnallocated = (value: string | undefined) => {
  const units = value === undefined ? null : parseAmount(value)
  return units !== null && units !== 0n
}

function ReceiptView({ receipt: rec, reload }: { receipt: Receipt; reload: () => void }) {
  const access = useModuleAccess(MODULES.ar)
  const { can } = useCapabilities()
  const wf = useDocumentActions({
    base: `${API}/customer-receipts/${rec.id}`,
    status: rec.status,
    sod: rec.sod,
    noun: 'penerimaan',
    // Editing, reopening and cancelling a receipt draft all need the create permission (see the API routes).
    perms: { update: 'accounting.ar_receipt.create', submit: 'accounting.ar_receipt.submit', approve: 'accounting.ar_receipt.approve', post: 'accounting.ar_receipt.post', reverse: 'accounting.ar_receipt.reverse' },
    modules: [MODULES.ar],
    // Submitting, approving and posting a receipt also need the cash and bank module writable (the API checks it at those steps only).
    stepModules: [MODULES.cashBank],
    editPath: `${AR_PATH.receipts}/${rec.id}/ubah`,
    onChanged: reload,
  })
  const allocations = rec.allocations ?? []
  const draft = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'].includes(rec.status)

  return (
    <>
      <PageHeader
        title={rec.document_number ?? (rec.status === 'DRAFT' ? 'Draf penerimaan pelanggan' : 'Penerimaan belum bernomor')}
        description={<>Penerimaan pelanggan · <StatusBadge status={rec.status} /></>}
        actions={wf.buttons}
      />
      <ReadOnlyNotice show={access.readOnly} />
      {wf.notes.map((n) => <Banner key={n} tone="info">{n}</Banner>)}
      {wf.error != null && <ErrorNotice error={wf.error} />}
      {wf.dialogs}
      {draft && hasUnallocated(rec.unallocated_amount) && (
        <Banner tone="warn">Penerimaan ini belum teralokasi penuh (belum dialokasikan <Money value={rec.unallocated_amount} />). Alokasikan seluruh jumlahnya ke faktur pelanggan sebelum diajukan atau diposting.</Banner>
      )}
      {rec.status === 'POSTED' && can('accounting.ar_receipt.reverse') && (
        <Banner tone="info">Membalik penerimaan ini membuat jurnal pembalik dan melepas alokasinya, sehingga saldo piutang faktur yang dilunasinya kembali terbuka.</Banner>
      )}
      {rec.status === 'REJECTED' && rec.reject_reason && <Banner tone="warn">Ditolak: {rec.reject_reason}</Banner>}
      {rec.status === 'CANCELLED' && rec.cancel_reason && <Banner tone="info">Dibatalkan: {rec.cancel_reason}</Banner>}
      {rec.status === 'REVERSED' && (
        <Banner tone="info">Penerimaan ini sudah dibalik{rec.reversal_reason ? `: ${rec.reversal_reason}` : '.'} Alokasinya dilepas dan saldo piutang faktur kembali terbuka.{rec.reversal_journal_id && <> <Link to={`/app/akuntansi/jurnal/${rec.reversal_journal_id}`}>Lihat jurnal pembalik</Link></>}</Banner>
      )}

      <Card>
        <dl className="facts">
          <div><dt>Pelanggan</dt><dd>{rec.customer ? `${rec.customer.code} · ${rec.customer.name}` : '—'}</dd></div>
          <div><dt>Akun kas/bank</dt><dd>{rec.cash_bank_account ? `${rec.cash_bank_account.code} · ${rec.cash_bank_account.name}` : '—'}</dd></div>
          <div><dt>Jumlah</dt><dd><Money value={rec.amount} strong /></dd></div>
          <div><dt>Teralokasi</dt><dd><Money value={rec.allocated_amount} /></dd></div>
          <div><dt>Belum dialokasikan</dt><dd><Money value={rec.unallocated_amount} /></dd></div>
          <div><dt>Tanggal penerimaan</dt><dd>{formatDate(rec.receipt_date)}</dd></div>
          <div><dt>Tanggal posting</dt><dd>{formatDate(rec.posting_date)}</dd></div>
          <div><dt>Metode</dt><dd>{rec.receipt_method ? paymentMethodLabels[rec.receipt_method] ?? rec.receipt_method : '—'}</dd></div>
          <div><dt>Mata uang</dt><dd>{rec.currency}</dd></div>
          <div><dt>Referensi</dt><dd>{rec.reference ?? '—'}</dd></div>
          {rec.gl_account && <div><dt>Akun buku besar</dt><dd>{rec.gl_account.code} · {rec.gl_account.name}</dd></div>}
          {(rec.branch || rec.business_unit || rec.cost_center) && (
            <div><dt>Dimensi</dt><dd>{[rec.branch && `Cabang ${rec.branch.code}`, rec.business_unit && `Unit ${rec.business_unit.code}`, rec.cost_center && `Biaya ${rec.cost_center.code}`].filter(Boolean).join(' · ')}</dd></div>
          )}
          <div><dt>Dibuat oleh</dt><dd>{rec.creator?.name ?? '—'}</dd></div>
          {rec.posted_at && <div><dt>Diposting pada</dt><dd>{formatDateTime(rec.posted_at)}</dd></div>}
          {rec.journal_entry_id && <div><dt>Jurnal</dt><dd><Link to={`/app/akuntansi/jurnal/${rec.journal_entry_id}`}>Lihat jurnal</Link></dd></div>}
          {rec.reversal_journal_id && <div><dt>Jurnal pembalik</dt><dd><Link to={`/app/akuntansi/jurnal/${rec.reversal_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
          {rec.description && <div className="wide"><dt>Deskripsi</dt><dd>{rec.description}</dd></div>}
        </dl>
      </Card>

      <Card title="Alokasi ke faktur" flush>
        {allocations.length === 0 ? (
          <EmptyState title="Belum ada alokasi">Penerimaan harus dialokasikan penuh ke faktur pelanggan sebelum dapat diajukan atau diposting.</EmptyState>
        ) : (
          <DataTable
            caption="Alokasi penerimaan ke faktur"
            rows={allocations}
            rowKey={(a) => a.id}
            scroll
            columns={[
              { header: 'Faktur', primary: true, cell: (a) => <Link className="mono" to={`${AR_PATH.invoices}/${a.ar_invoice_id}`}>{a.invoice?.document_number ?? a.invoice?.customer_reference ?? 'Faktur'}</Link> },
              { header: 'Referensi pelanggan', cell: (a) => <span className="mono">{a.invoice?.customer_reference ?? '—'}</span> },
              { header: 'Jatuh tempo', cell: (a) => formatDate(a.invoice?.due_date) },
              { header: 'Total faktur', align: 'right', cell: (a) => <Money value={a.invoice?.total_amount} /> },
              { header: 'Dialokasikan', align: 'right', cell: (a) => <Money value={a.amount} /> },
              { header: 'Keadaan', cell: (a) => (a.released_at ? <Badge tone="neutral">Dilepas</Badge> : a.is_effective ? <Badge tone="ok">Berlaku</Badge> : rec.status === 'CANCELLED' || rec.status === 'REJECTED' ? <Badge tone="neutral">Tidak berlaku</Badge> : <Badge tone="info">Menunggu posting</Badge>) },
            ]}
          />
        )}
        <div className="table-total">
          <span>Jumlah penerimaan <Money value={rec.amount} /></span>
          <span>Teralokasi <Money value={rec.allocated_amount} strong /></span>
        </div>
      </Card>

      <Card title="Riwayat"><Timeline transitions={rec.transitions} /></Card>
    </>
  )
}
