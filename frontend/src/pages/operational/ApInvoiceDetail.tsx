import { Link, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { invoiceOriginLabels } from '../../lib/operationalLabels'
import { plainAmount } from './payables/invoiceForm'
import { statusLabel } from '../../lib/labels'
import type { Invoice, InvoiceLine } from './payables/types'
import { Money, ReadOnlyNotice, Timeline } from './shared'
import { useDocumentActions } from './workflow'

export default function ApInvoiceDetail() {
  const { id } = useParams()
  const invoice = useResource(async () => (await api.get<Invoice>(`${API}/ap-invoices/${id}`)).data, [id])

  if (invoice.loading && !invoice.data) return <Loading />
  if (invoice.error || !invoice.data) return <ErrorNotice error={invoice.error} onRetry={invoice.reload} />
  return <InvoiceView invoice={invoice.data} reload={invoice.reload} />
}

const classification = (l: InvoiceLine) => [l.expense_category && `Kategori ${l.expense_category.code}`, l.account && `Akun ${l.account.code}`, l.account_role && `Peran ${l.account_role}`].filter(Boolean).join(' · ')

function InvoiceView({ invoice: inv, reload }: { invoice: Invoice; reload: () => void }) {
  const access = useModuleAccess(MODULES.ap)
  const fromExpense = inv.origin === 'EXPENSE'
  const wf = useDocumentActions({
    base: `${API}/ap-invoices/${inv.id}`,
    status: inv.status,
    sod: inv.sod,
    noun: 'faktur',
    perms: { update: 'accounting.ap_invoice.update', submit: 'accounting.ap_invoice.submit', approve: 'accounting.ap_invoice.approve', post: 'accounting.ap_invoice.post', reverse: 'accounting.ap_invoice.reverse' },
    modules: [MODULES.ap],
    editPath: `/app/akuntansi/faktur-vendor/${inv.id}/ubah`,
    // A payable created by an expense is posted and reversed together with its expense.
    reversible: !fromExpense,
    onChanged: reload,
  })
  const settled = inv.status === 'POSTED'
  const allocations = inv.allocations ?? []

  return (
    <>
      <PageHeader
        title={inv.document_number ?? (inv.status === 'DRAFT' ? 'Draf faktur vendor' : 'Faktur belum bernomor')}
        description={<>{invoiceOriginLabels[inv.origin] ?? inv.origin} · <StatusBadge status={inv.status} />{settled && inv.payment_status && <> · <StatusBadge status={inv.payment_status} /></>}</>}
        actions={wf.buttons}
      />
      <ReadOnlyNotice show={access.readOnly} />
      {wf.notes.map((n) => <Banner key={n} tone="info">{n}</Banner>)}
      {wf.error != null && <ErrorNotice error={wf.error} />}
      {wf.dialogs}
      {fromExpense && (
        <Banner tone="info">
          Utang ini dibuat oleh beban{inv.source_id ? <> <Link to={`/app/akuntansi/beban/${inv.source_id}`}>Lihat beban</Link></> : ''}. Posting dan pembalikannya dilakukan bersama bebannya; pelunasan memakai pembayaran vendor biasa.
        </Banner>
      )}
      {inv.status === 'REJECTED' && inv.reject_reason && <Banner tone="warn">Ditolak: {inv.reject_reason}</Banner>}
      {inv.status === 'CANCELLED' && inv.cancel_reason && <Banner tone="info">Dibatalkan: {inv.cancel_reason}</Banner>}
      {inv.status === 'REVERSED' && <Banner tone="info">Faktur ini sudah dibalik{inv.reversal_reason ? `: ${inv.reversal_reason}` : '.'}{inv.reversal_journal_id && <> <Link to={`/app/akuntansi/jurnal/${inv.reversal_journal_id}`}>Lihat jurnal pembalik</Link></>}</Banner>}
      {inv.duplicate_override_by && <Banner tone="info">Nomor faktur vendor ini dicatat sebagai nomor ganda yang disengaja{inv.duplicate_override_reason ? `: ${inv.duplicate_override_reason}` : '.'}</Banner>}
      {(inv.possible_duplicates ?? []).length > 0 && (
        <Banner tone="warn">
          Faktur lain dari vendor ini memiliki total dan tanggal dokumen yang sama dengan nomor berbeda. Pastikan bukan tagihan ganda:{' '}
          {(inv.possible_duplicates ?? []).map((d, i) => (
            <span key={d.id}>{i > 0 && ', '}<Link to={`/app/akuntansi/faktur-vendor/${d.id}`}>{d.document_number ?? d.vendor_invoice_number}</Link> ({statusLabel(d.status)[0]})</span>
          ))}
        </Banner>
      )}

      <Card>
        <dl className="facts">
          <div><dt>Vendor</dt><dd>{inv.vendor ? `${inv.vendor.code} · ${inv.vendor.name}` : '—'}</dd></div>
          <div><dt>Nomor faktur vendor</dt><dd>{inv.vendor_invoice_number}</dd></div>
          <div><dt>Tanggal dokumen</dt><dd>{formatDate(inv.document_date)}</dd></div>
          <div><dt>Tanggal posting</dt><dd>{formatDate(inv.posting_date)}</dd></div>
          <div><dt>Jatuh tempo</dt><dd>{formatDate(inv.due_date)}{inv.due_date_overridden && <span className="muted"> (diisi manual)</span>}</dd></div>
          <div><dt>Termin pembayaran</dt><dd>{inv.payment_term ? `${inv.payment_term.code} · ${inv.payment_term.name}` : '—'}</dd></div>
          <div><dt>Mata uang</dt><dd>{inv.currency}</dd></div>
          <div><dt>Referensi</dt><dd>{inv.reference ?? '—'}</dd></div>
          {(inv.branch || inv.business_unit || inv.cost_center) && (
            <div><dt>Dimensi</dt><dd>{[inv.branch && `Cabang ${inv.branch.code}`, inv.business_unit && `Unit ${inv.business_unit.code}`, inv.cost_center && `Biaya ${inv.cost_center.code}`].filter(Boolean).join(' · ')}</dd></div>
          )}
          <div><dt>Dibuat oleh</dt><dd>{inv.creator?.name ?? (fromExpense ? 'Sistem' : '—')}</dd></div>
          {inv.posted_at && <div><dt>Diposting pada</dt><dd>{formatDateTime(inv.posted_at)}</dd></div>}
          {inv.journal_entry_id && <div><dt>Jurnal</dt><dd><Link to={`/app/akuntansi/jurnal/${inv.journal_entry_id}`}>Lihat jurnal</Link></dd></div>}
          {inv.reversal_journal_id && <div><dt>Jurnal pembalik</dt><dd><Link to={`/app/akuntansi/jurnal/${inv.reversal_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
          <div className="wide"><dt>Deskripsi</dt><dd>{inv.description}</dd></div>
        </dl>
      </Card>

      <Card title="Baris faktur" flush>
        {(inv.lines ?? []).length === 0 ? (
          <EmptyState title="Tidak ada baris faktur">{fromExpense ? 'Utang dari beban tidak memiliki baris faktur; rinciannya ada pada bebannya.' : 'Faktur ini belum memiliki baris.'}</EmptyState>
        ) : (
          <DataTable
            caption="Baris faktur"
            rows={inv.lines ?? []}
            rowKey={(l) => l.id}
            scroll
            columns={[
              { header: 'No.', cell: (l) => l.line_number },
              { header: 'Deskripsi', primary: true, cell: (l) => <>{l.description}{classification(l) && <div className="muted">{classification(l)}</div>}</> },
              { header: 'Kuantitas × harga', align: 'right', cell: (l) => (l.quantity !== null && l.unit_price !== null ? <>{plainAmount(l.quantity)} × <Money value={l.unit_price} /></> : <span className="muted">—</span>) },
              { header: 'Pusat biaya', cell: (l) => (l.cost_center ? l.cost_center.code : <span className="muted">—</span>) },
              { header: 'Jumlah', align: 'right', cell: (l) => <Money value={l.amount} /> },
            ]}
          />
        )}
        <div className="table-total">
          <span>Subtotal <Money value={inv.subtotal_amount} /></span>
          <span>Diskon <Money value={inv.discount_amount} /></span>
          <span>Pajak <Money value={inv.tax_amount} /></span>
          <span>Biaya lain <Money value={inv.other_charges_amount} /></span>
          <span>Total <Money value={inv.total_amount} strong /></span>
        </div>
      </Card>

      {(settled || allocations.length > 0) && (
        <Card title="Pelunasan" flush>
          <div className="card-body">
            <dl className="facts">
              <div><dt>Total faktur</dt><dd><Money value={inv.total_amount} /></dd></div>
              <div><dt>Sudah dibayar</dt><dd><Money value={inv.paid_amount} /></dd></div>
              <div><dt>Saldo terutang</dt><dd><Money value={inv.outstanding_amount} strong /></dd></div>
              <div><dt>Status bayar</dt><dd>{inv.payment_status ? <StatusBadge status={inv.payment_status} /> : '—'}</dd></div>
            </dl>
            <p className="muted preview-note">Saldo dihitung server dari alokasi pembayaran yang sudah diposting; tidak ada saldo yang disimpan atau diubah manual.</p>
          </div>
          {allocations.length === 0 ? (
            <EmptyState title="Belum ada pembayaran">Pembayaran vendor yang sudah diposting untuk faktur ini akan muncul di sini.</EmptyState>
          ) : (
            <DataTable
              caption="Alokasi pembayaran"
              rows={allocations}
              rowKey={(a) => a.id}
              columns={[
                { header: 'Pembayaran', primary: true, cell: (a) => <Link className="mono" to={`/app/akuntansi/pembayaran-vendor/${a.vendor_payment_id}`}>{a.payment?.document_number ?? 'Pembayaran'}</Link> },
                { header: 'Tanggal posting', cell: (a) => formatDate(a.payment?.posting_date) },
                { header: 'Status pembayaran', cell: (a) => <StatusBadge status={a.payment?.status} /> },
                { header: 'Dialokasikan', align: 'right', cell: (a) => <Money value={a.amount} /> },
              ]}
            />
          )}
        </Card>
      )}

      <Card title="Riwayat"><Timeline transitions={inv.transitions} /></Card>
    </>
  )
}
