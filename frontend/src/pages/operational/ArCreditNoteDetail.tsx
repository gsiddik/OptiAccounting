import { Link, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { plainAmount } from './payables/invoiceForm'
import { AR_PATH } from './receivables/paths'
import type { CreditNote, CreditNoteLine } from './receivables/types'
import { Money, ReadOnlyNotice, Timeline } from './shared'
import { useDocumentActions } from './workflow'

export default function ArCreditNoteDetail() {
  const { id } = useParams()
  const note = useResource(async () => (await api.get<CreditNote>(`${API}/ar-credit-notes/${id}`)).data, [id])

  if (note.loading && !note.data) return <Loading />
  if (note.error || !note.data) return <ErrorNotice error={note.error} onRetry={note.reload} />
  return <NoteView note={note.data} reload={note.reload} />
}

const classification = (l: CreditNoteLine) => [l.account && `Akun ${l.account.code}`, l.account_role && `Peran ${l.account_role}`].filter(Boolean).join(' · ')

function NoteView({ note: cn, reload }: { note: CreditNote; reload: () => void }) {
  const access = useModuleAccess(MODULES.ar)
  const wf = useDocumentActions({
    base: `${API}/ar-credit-notes/${cn.id}`,
    status: cn.status,
    sod: cn.sod,
    noun: 'nota kredit',
    // Editing, reopening and cancelling a credit-note draft all need the create permission (see the API routes).
    perms: { update: 'accounting.ar_credit_note.create', submit: 'accounting.ar_credit_note.submit', approve: 'accounting.ar_credit_note.approve', post: 'accounting.ar_credit_note.post', reverse: 'accounting.ar_credit_note.reverse' },
    modules: [MODULES.ar],
    editPath: `${AR_PATH.creditNotes}/${cn.id}/ubah`,
    onChanged: reload,
  })

  return (
    <>
      <PageHeader
        title={cn.document_number ?? (cn.status === 'DRAFT' ? 'Draf nota kredit' : 'Nota kredit belum bernomor')}
        description={<>Nota kredit · <StatusBadge status={cn.status} /></>}
        actions={<><Link to={AR_PATH.creditNotes} className="btn btn-ghost">Kembali ke daftar</Link>{wf.buttons}</>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      {wf.notes.map((n) => <Banner key={n} tone="info">{n}</Banner>)}
      {wf.error != null && <ErrorNotice error={wf.error} />}
      {wf.dialogs}
      {cn.status === 'REJECTED' && cn.reject_reason && <Banner tone="warn">Ditolak: {cn.reject_reason}</Banner>}
      {cn.status === 'CANCELLED' && cn.cancel_reason && <Banner tone="info">Dibatalkan: {cn.cancel_reason}</Banner>}
      {cn.status === 'REVERSED' && (
        <Banner tone="info">
          Nota kredit ini sudah dibalik{cn.reversal_reason ? `: ${cn.reversal_reason}` : '.'} Saldo piutang fakturnya kembali seperti sebelum dikreditkan.
          {cn.reversal_journal_id && <> <Link to={`/app/akuntansi/jurnal/${cn.reversal_journal_id}`}>Lihat jurnal pembalik</Link></>}
        </Banner>
      )}

      <Card>
        <dl className="facts">
          <div><dt>Pelanggan</dt><dd>{cn.customer ? `${cn.customer.code} · ${cn.customer.name}` : '—'}</dd></div>
          <div><dt>Faktur dikreditkan</dt><dd>{cn.invoice ? <Link className="mono" to={`${AR_PATH.invoices}/${cn.ar_invoice_id}`}>{cn.invoice.document_number ?? 'Faktur'}</Link> : '—'}</dd></div>
          <div><dt>Saldo piutang faktur</dt><dd>{cn.invoice_outstanding !== undefined ? <Money value={cn.invoice_outstanding} strong /> : '—'}</dd></div>
          <div><dt>Alasan</dt><dd>{cn.reason}</dd></div>
          <div><dt>Referensi</dt><dd>{cn.reference ?? '—'}</dd></div>
          <div><dt>Tanggal dokumen</dt><dd>{formatDate(cn.document_date)}</dd></div>
          <div><dt>Tanggal posting</dt><dd>{formatDate(cn.posting_date)}</dd></div>
          <div><dt>Mata uang</dt><dd>{cn.currency}</dd></div>
          {(cn.branch || cn.business_unit || cn.cost_center) && (
            <div><dt>Dimensi</dt><dd>{[cn.branch && `Cabang ${cn.branch.code}`, cn.business_unit && `Unit ${cn.business_unit.code}`, cn.cost_center && `Biaya ${cn.cost_center.code}`].filter(Boolean).join(' · ')}</dd></div>
          )}
          <div><dt>Dibuat oleh</dt><dd>{cn.creator?.name ?? '—'}</dd></div>
          {cn.posted_at && <div><dt>Diposting pada</dt><dd>{formatDateTime(cn.posted_at)}</dd></div>}
          {cn.journal_entry_id && <div><dt>Jurnal</dt><dd><Link to={`/app/akuntansi/jurnal/${cn.journal_entry_id}`}>Lihat jurnal</Link></dd></div>}
          {cn.reversal_journal_id && <div><dt>Jurnal pembalik</dt><dd><Link to={`/app/akuntansi/jurnal/${cn.reversal_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
        </dl>
      </Card>

      <Card title="Baris nota kredit" flush>
        {(cn.lines ?? []).length === 0 ? (
          <EmptyState title="Tidak ada baris">Nota kredit ini belum memiliki baris.</EmptyState>
        ) : (
          <DataTable
            caption="Baris nota kredit"
            rows={cn.lines ?? []}
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
          <span>Subtotal <Money value={cn.subtotal_amount} /></span>
          <span>Pajak <Money value={cn.tax_amount} /></span>
          <span>Total <Money value={cn.total_amount} strong /></span>
        </div>
      </Card>

      <Card title="Riwayat"><Timeline transitions={cn.transitions} /></Card>
    </>
  )
}
