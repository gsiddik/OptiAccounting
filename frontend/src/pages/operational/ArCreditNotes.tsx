import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useDebounced, useResource } from '../../lib/hooks'
import { statusLabel } from '../../lib/labels'
import { API, DOC_STATUSES, MODULES, useCustomers, useModuleAccess, type Page } from '../../lib/operational'
import { useDimensions } from '../accounting/data'
import { exportQuery, listQuery, statusFromSearch } from './payables/lists'
import { AR_PATH } from './receivables/paths'
import type { CreditNoteRow } from './receivables/types'
import { DimensionFilters, ExportButton, Filters, Money, ReadOnlyNotice } from './shared'

const EMPTY = {
  q: '', status: '', customer_id: '', document_from: '', document_to: '', posting_from: '', posting_to: '',
  branch_id: '', business_unit_id: '', cost_center_id: '', mine: false,
}
type FilterState = typeof EMPTY
type DateKey = 'document_from' | 'document_to' | 'posting_from' | 'posting_to'
const dateChange = (key: DateKey, value: string): Partial<FilterState> => {
  const patch: Partial<Record<DateKey, string>> = {}
  patch[key] = value
  return patch
}

export default function ArCreditNotes() {
  const navigate = useNavigate()
  const { search } = useLocation()
  const access = useModuleAccess(MODULES.ar)
  const { customers } = useCustomers()
  const { catalog } = useDimensions()
  const [f, setF] = useState<FilterState>(() => ({ ...EMPTY, status: statusFromSearch(search) }))
  const [page, setPage] = useState(1)
  const q = useDebounced(f.q)
  const filter = { ...f, q }

  const notes = useResource(async () => (await api.get<Page<CreditNoteRow>>(`${API}/ar-credit-notes`, { params: listQuery(filter, page) })).data, [page, JSON.stringify(filter)])

  const change = (patch: Partial<FilterState>) => {
    setF((s) => ({ ...s, ...patch }))
    setPage(1)
  }
  const date = (key: DateKey, label: string) => (
    <label className="inline-field">{label} <input className="input" type="date" value={f[key]} onChange={(e) => change(dateChange(key, e.target.value))} /></label>
  )

  return (
    <>
      <PageHeader
        title="Nota kredit"
        description="Pengurang piutang atas faktur pelanggan yang sudah diposting (retur, diskon, koreksi harga). Nota kredit yang sudah diposting tidak dapat diubah; koreksi dilakukan dengan pembalikan."
        actions={
          <>
            <ExportButton path={`${API}/ar-credit-notes/export`} params={exportQuery(filter)} filename="nota-kredit.csv" />
            {access.canChange('accounting.ar_credit_note.create') && <Button variant="primary" onClick={() => navigate(`${AR_PATH.creditNotes}/baru`)}>Nota kredit baru</Button>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body stack">
          <Filters>
            <input className="input" type="search" placeholder="Cari nomor, alasan, referensi" aria-label="Cari nota kredit" value={f.q} onChange={(e) => change({ q: e.target.value })} />
            <select className="select" aria-label="Status" value={f.status} onChange={(e) => change({ status: e.target.value })}>
              <option value="">Semua status</option>
              {DOC_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            <select className="select" aria-label="Pelanggan" value={f.customer_id} onChange={(e) => change({ customer_id: e.target.value })}>
              <option value="">Semua pelanggan</option>
              {customers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
            </select>
            <DimensionFilters catalog={catalog} value={f} onChange={change} />
            <label className="check"><input type="checkbox" checked={f.mine} onChange={(e) => change({ mine: e.target.checked })} /> Buatan saya</label>
          </Filters>
          <Filters>
            {date('posting_from', 'Posting dari')}
            {date('posting_to', 'Posting sampai')}
            {date('document_from', 'Dokumen dari')}
            {date('document_to', 'Dokumen sampai')}
          </Filters>
        </div>

        {notes.loading && !notes.data ? (
          <Loading />
        ) : notes.error || !notes.data ? (
          <ErrorNotice error={notes.error} onRetry={notes.reload} />
        ) : notes.data.data.length === 0 ? (
          <EmptyState title="Tidak ada nota kredit">Ubah filter{access.canChange('accounting.ar_credit_note.create') ? ' atau buat nota kredit baru' : ''}.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar nota kredit"
              rows={notes.data.data}
              rowKey={(n) => n.id}
              scroll
              columns={[
                { header: 'Nomor', primary: true, cell: (n) => <Link to={`${AR_PATH.creditNotes}/${n.id}`} className="mono">{n.document_number ?? 'Draf'}</Link> },
                { header: 'Pelanggan', cell: (n) => (n.customer ? <>{n.customer.name}<div className="muted mono">{n.customer.code}</div></> : <span className="muted">—</span>) },
                { header: 'Faktur', cell: (n) => (n.invoice ? <Link to={`${AR_PATH.invoices}/${n.ar_invoice_id}`} className="mono">{n.invoice.document_number ?? 'Faktur'}</Link> : <span className="muted">—</span>) },
                { header: 'Alasan', cell: (n) => n.reason },
                { header: 'Tanggal posting', cell: (n) => formatDate(n.posting_date) },
                { header: 'Total', align: 'right', cell: (n) => <Money value={n.total_amount} /> },
                { header: 'Status', cell: (n) => <StatusBadge status={n.status} /> },
              ]}
            />
            <Pagination page={notes.data.current_page} lastPage={notes.data.last_page} total={notes.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}
