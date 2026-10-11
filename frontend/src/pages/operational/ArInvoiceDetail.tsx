import { Link, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { statusLabel } from '../../lib/labels'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { ForeignFacts } from './foreign'
import { isForeignDoc } from './foreignSupport'
import { plainAmount } from './payables/invoiceForm'
import { AR_PATH } from './receivables/paths'
import type { ArInvoice, ArInvoiceLine } from './receivables/types'
import { Money, ReadOnlyNotice, Timeline } from './shared'
import { TaxAmountCell, TaxCodeCell } from './taxOptions'
import { useLineTaxFacts } from './taxSupport'
import { useDocumentActions } from './workflow'

export default function ArInvoiceDetail() {
  const { id } = useParams()
  const invoice = useResource(async () => (await api.get<ArInvoice>(`${API}/ar-invoices/${id}`)).data, [id])

  if (invoice.loading && !invoice.data) return <Loading />
  if (invoice.error || !invoice.data) return <ErrorNotice error={invoice.error} onRetry={invoice.reload} />
  return <InvoiceView invoice={invoice.data} reload={invoice.reload} />
}

const classification = (l: ArInvoiceLine) => [l.account && `Akun ${l.account.code}`, l.account_role && `Peran ${l.account_role}`].filter(Boolean).join(' · ')

function InvoiceView({ invoice: inv, reload }: { invoice: ArInvoice; reload: () => void }) {
  const access = useModuleAccess(MODULES.ar)
  const { can, featureEnabled } = useCapabilities()
  const wf = useDocumentActions({
    base: `${API}/ar-invoices/${inv.id}`,
    status: inv.status,
    sod: inv.sod,
    noun: 'faktur',
    perms: { update: 'accounting.ar_invoice.update', submit: 'accounting.ar_invoice.submit', approve: 'accounting.ar_invoice.approve', post: 'accounting.ar_invoice.post', reverse: 'accounting.ar_invoice.reverse' },
    modules: [MODULES.ar],
    editPath: `${AR_PATH.invoices}/${inv.id}/ubah`,
    onChanged: reload,
  })
  const settled = inv.status === 'POSTED'
  const allocations = inv.allocations ?? []
  const creditNotes = inv.credit_notes ?? []
  const foreign = isForeignDoc(inv)
  // Tax code, rate and tax per line from the server, only when a line carries a tax code (and the user may read tax codes).
  const tax = useLineTaxFacts(inv.lines ?? [], inv.document_date, foreign)
  // A posted invoice with something left to collect can be credited (the API still checks the remaining outstanding).
  const creditable = settled && inv.payment_status !== 'PAID' && access.canChange('accounting.ar_credit_note.create') && featureEnabled('CREDIT_NOTE')

  return (
    <>
      <PageHeader
        title={inv.document_number ?? (inv.status === 'DRAFT' ? 'Draf faktur pelanggan' : 'Faktur belum bernomor')}
        description={<><StatusBadge status={inv.status} />{settled && inv.payment_status && <> · <StatusBadge status={inv.payment_status} /></>}</>}
        actions={
          <>
            {creditable && <Link to={`${AR_PATH.creditNotes}/baru?faktur=${inv.id}`} className="btn">Buat nota kredit</Link>}
            {wf.buttons}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      {wf.notes.map((n) => <Banner key={n} tone="info">{n}</Banner>)}
      {wf.error != null && <ErrorNotice error={wf.error} />}
      {wf.dialogs}
      {settled && can('accounting.ar_invoice.reverse') && (allocations.length > 0 || creditNotes.length > 0) && (
        <Banner tone="info">Faktur ini sudah dilunasi sebagian atau seluruhnya oleh penerimaan atau nota kredit. Server menolak pembalikan faktur selama itu belum dibalik: balik penerimaan dan nota kreditnya terlebih dahulu.</Banner>
      )}
      {inv.status === 'REJECTED' && inv.reject_reason && <Banner tone="warn">Ditolak: {inv.reject_reason}</Banner>}
      {inv.status === 'CANCELLED' && inv.cancel_reason && <Banner tone="info">Dibatalkan: {inv.cancel_reason}</Banner>}
      {inv.status === 'REVERSED' && (
        <Banner tone="info">
          Faktur ini sudah dibalik{inv.reversal_reason ? `: ${inv.reversal_reason}` : '.'}
          {inv.reversal_journal_id && <> <Link to={`/app/akuntansi/jurnal/${inv.reversal_journal_id}`}>Lihat jurnal pembalik</Link></>}
        </Banner>
      )}
      {(inv.possible_duplicates ?? []).length > 0 && (
        <Banner tone="warn">
          Faktur lain dari pelanggan ini memiliki referensi pelanggan yang sama, atau total dan tanggal dokumen yang sama. Pastikan bukan tagihan ganda:{' '}
          {(inv.possible_duplicates ?? []).map((d, i) => (
            <span key={d.id}>{i > 0 && ', '}<Link to={`${AR_PATH.invoices}/${d.id}`}>{d.document_number ?? d.customer_reference ?? 'Draf'}</Link> ({statusLabel(d.status)[0]})</span>
          ))}
        </Banner>
      )}

      <Card>
        <dl className="facts">
          <div><dt>Pelanggan</dt><dd>{inv.customer ? `${inv.customer.code} · ${inv.customer.name}` : '—'}</dd></div>
          <div><dt>Referensi pelanggan</dt><dd>{inv.customer_reference ?? '—'}</dd></div>
          <div><dt>Tanggal dokumen</dt><dd>{formatDate(inv.document_date)}</dd></div>
          <div><dt>Tanggal posting</dt><dd>{formatDate(inv.posting_date)}</dd></div>
          <div><dt>Jatuh tempo</dt><dd>{formatDate(inv.due_date)}{inv.due_date_overridden && <span className="muted"> (diisi manual)</span>}</dd></div>
          <div><dt>Termin pembayaran</dt><dd>{inv.payment_term ? `${inv.payment_term.code} · ${inv.payment_term.name}` : '—'}</dd></div>
          <div><dt>Mata uang</dt><dd>{inv.currency}</dd></div>
          <ForeignFacts doc={inv} total={{ label: 'Total', value: inv.total_amount }} />
          <div><dt>Referensi</dt><dd>{inv.reference ?? '—'}</dd></div>
          {(inv.branch || inv.business_unit || inv.cost_center) && (
            <div><dt>Dimensi</dt><dd>{[inv.branch && `Cabang ${inv.branch.code}`, inv.business_unit && `Unit ${inv.business_unit.code}`, inv.cost_center && `Biaya ${inv.cost_center.code}`].filter(Boolean).join(' · ')}</dd></div>
          )}
          <div><dt>Dibuat oleh</dt><dd>{inv.creator?.name ?? '—'}</dd></div>
          {inv.posted_at && <div><dt>Diposting pada</dt><dd>{formatDateTime(inv.posted_at)}</dd></div>}
          {inv.journal_entry_id && <div><dt>Jurnal</dt><dd><Link to={`/app/akuntansi/jurnal/${inv.journal_entry_id}`}>Lihat jurnal</Link></dd></div>}
          {inv.reversal_journal_id && <div><dt>Jurnal pembalik</dt><dd><Link to={`/app/akuntansi/jurnal/${inv.reversal_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
          <div className="wide"><dt>Deskripsi</dt><dd>{inv.description}</dd></div>
        </dl>
      </Card>

      <Card title="Baris faktur" flush>
        {(inv.lines ?? []).length === 0 ? (
          <EmptyState title="Tidak ada baris faktur">Faktur ini belum memiliki baris.</EmptyState>
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
              ...(tax.taxed ? [
                { header: 'Kode pajak', cell: (l: ArInvoiceLine) => <TaxCodeCell taxCodeId={l.tax_code_id} fact={tax.facts[l.id]} /> },
                { header: 'Pajak', align: 'right' as const, cell: (l: ArInvoiceLine) => <TaxAmountCell taxCodeId={l.tax_code_id} fact={tax.facts[l.id]} /> },
              ] : []),
              { header: tax.taxed ? 'Dasar (jumlah)' : 'Jumlah', align: 'right', cell: (l) => <><Money value={l.amount} />{l.tax_code_id && l.entered_amount != null && l.entered_amount !== l.amount && <div className="muted">Diinput <Money value={l.entered_amount} /></div>}</> },
            ]}
          />
        )}
        {tax.taxed && <div className="card-body"><p className="muted preview-note">Kode pajak, tarif dan pajak per baris adalah hitungan server atas jumlah yang diinput, dengan tarif pada tanggal dokumen{foreign ? '; pajak faktur mata uang asing hanya tampil pada total' : ''}. Total pajak faktur adalah angka tersimpan.</p></div>}
        <div className="table-total">
          <span>Subtotal <Money value={inv.subtotal_amount} /></span>
          <span>Diskon <Money value={inv.discount_amount} /></span>
          <span>Pajak <Money value={inv.tax_amount} /></span>
          <span>Biaya lain <Money value={inv.other_charges_amount} /></span>
          <span>Total{foreign ? ` ${inv.currency}` : ''} <Money value={inv.total_amount} strong /></span>
          {foreign && <span>Total fungsional <Money value={inv.functional_total_amount} strong /></span>}
        </div>
      </Card>

      {(settled || allocations.length > 0 || creditNotes.length > 0) && (
        <Card title="Pelunasan" flush>
          <div className="card-body">
            <dl className="facts">
              <div><dt>Total faktur</dt><dd><Money value={inv.total_amount} /></dd></div>
              <div><dt>Sudah diterima</dt><dd><Money value={inv.received_amount} /></dd></div>
              <div><dt>Dikreditkan</dt><dd><Money value={inv.credited_amount} /></dd></div>
              <div><dt>Saldo piutang</dt><dd><Money value={inv.outstanding_amount} strong /></dd></div>
              {foreign && inv.outstanding_functional != null && <div><dt>Saldo fungsional</dt><dd><Money value={inv.outstanding_functional} strong /></dd></div>}
              <div><dt>Status bayar</dt><dd>{inv.payment_status ? <StatusBadge status={inv.payment_status} /> : '—'}</dd></div>
            </dl>
            <p className="muted preview-note">Saldo dihitung server dari penerimaan dan nota kredit yang sudah diposting; tidak ada saldo yang disimpan atau diubah manual.</p>
          </div>
          {allocations.length === 0 ? (
            <EmptyState title="Belum ada penerimaan">Penerimaan pelanggan yang sudah diposting untuk faktur ini akan muncul di sini.</EmptyState>
          ) : (
            <DataTable
              caption="Alokasi penerimaan"
              rows={allocations}
              rowKey={(a) => a.id}
              columns={[
                { header: 'Penerimaan', primary: true, cell: (a) => <Link className="mono" to={`${AR_PATH.receipts}/${a.customer_receipt_id}`}>{a.receipt?.document_number ?? 'Penerimaan'}</Link> },
                { header: 'Tanggal posting', cell: (a) => formatDate(a.receipt?.posting_date) },
                { header: 'Status penerimaan', cell: (a) => <StatusBadge status={a.receipt?.status} /> },
                { header: 'Dialokasikan', align: 'right', cell: (a) => <Money value={a.amount} /> },
              ]}
            />
          )}
          {creditNotes.length > 0 && (
            <DataTable
              caption="Nota kredit"
              rows={creditNotes}
              rowKey={(c) => c.id}
              columns={[
                { header: 'Nota kredit', primary: true, cell: (c) => <Link className="mono" to={`${AR_PATH.creditNotes}/${c.id}`}>{c.document_number ?? 'Nota kredit'}</Link> },
                { header: 'Tanggal posting', cell: (c) => formatDate(c.posting_date) },
                { header: 'Alasan', cell: (c) => c.reason },
                { header: 'Status', cell: (c) => <StatusBadge status={c.status} /> },
                { header: 'Jumlah', align: 'right', cell: (c) => <Money value={c.total_amount} /> },
              ]}
            />
          )}
        </Card>
      )}

      <Card title="Riwayat"><Timeline transitions={inv.transitions} /></Card>
    </>
  )
}
