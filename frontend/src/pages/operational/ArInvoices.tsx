import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useDebounced, useResource } from '../../lib/hooks'
import { statusLabel } from '../../lib/labels'
import { API, DOC_STATUSES, MODULES, useCustomers, useModuleAccess, type Page } from '../../lib/operational'
import { useDimensions } from '../accounting/data'
import { boundedInteger, exportQuery, listQuery, statusFromSearch, useBusinessDate } from './payables/lists'
import { AR_PATH } from './receivables/paths'
import { DocAmount } from './foreign'
import type { ArInvoiceRow } from './receivables/types'
import { DimensionFilters, ExportButton, Filters, Money, ReadOnlyNotice } from './shared'

const PAYMENT_STATUSES = ['UNPAID', 'PARTIALLY_PAID', 'PAID']

const EMPTY = {
  q: '', status: '', customer_id: '', payment_status: '', open: false, overdue: false, due_within: '',
  document_from: '', document_to: '', posting_from: '', posting_to: '', due_from: '', due_to: '',
  branch_id: '', business_unit_id: '', cost_center_id: '', mine: false,
}
type FilterState = typeof EMPTY
type DateKey = 'document_from' | 'document_to' | 'posting_from' | 'posting_to' | 'due_from' | 'due_to'
const dateChange = (key: DateKey, value: string): Partial<FilterState> => {
  const patch: Partial<Record<DateKey, string>> = {}
  patch[key] = value
  return patch
}

/** Reads the filters a link can carry (the accounting home links here with `?open=1`, `?overdue=1`, `?due_within=7` or `?status=SUBMITTED`). */
function initialFilter(search: string): FilterState {
  const params = new URLSearchParams(search)
  return { ...EMPTY, status: statusFromSearch(search), open: params.get('open') === '1', overdue: params.get('overdue') === '1', due_within: boundedInteger(params.get('due_within') ?? '', 1, 365) }
}

export default function ArInvoices() {
  const navigate = useNavigate()
  const { search } = useLocation()
  const access = useModuleAccess(MODULES.ar)
  const today = useBusinessDate()
  const { customers } = useCustomers()
  const { catalog } = useDimensions()
  const [f, setF] = useState<FilterState>(() => initialFilter(search))
  const [page, setPage] = useState(1)
  const q = useDebounced(f.q)
  const dueWithin = useDebounced(boundedInteger(f.due_within, 1, 365))
  const filter = { ...f, q, due_within: dueWithin }

  const invoices = useResource(async () => (await api.get<Page<ArInvoiceRow>>(`${API}/ar-invoices`, { params: listQuery(filter, page) })).data, [page, JSON.stringify(filter)])

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
        title="Faktur pelanggan"
        description="Tagihan kepada pelanggan. Faktur yang sudah diposting tidak dapat diubah; koreksi dilakukan dengan pembalikan atau nota kredit. Saldo piutang dan status pelunasan dihitung server dari penerimaan dan nota kredit."
        actions={
          <>
            <ExportButton path={`${API}/ar-invoices/export`} params={exportQuery(filter)} filename="faktur-pelanggan.csv" />
            {access.canChange('accounting.ar_invoice.create') && <Button variant="primary" onClick={() => navigate(`${AR_PATH.invoices}/baru`)}>Faktur baru</Button>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body stack">
          <Filters>
            <input className="input" type="search" placeholder="Cari nomor, referensi pelanggan, deskripsi, referensi" aria-label="Cari faktur" value={f.q} onChange={(e) => change({ q: e.target.value })} />
            <select className="select" aria-label="Status" value={f.status} onChange={(e) => change({ status: e.target.value })}>
              <option value="">Semua status</option>
              {DOC_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            <select className="select" aria-label="Pelanggan" value={f.customer_id} onChange={(e) => change({ customer_id: e.target.value })}>
              <option value="">Semua pelanggan</option>
              {customers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
            </select>
            <select className="select" aria-label="Status bayar" value={f.payment_status} onChange={(e) => change({ payment_status: e.target.value })}>
              <option value="">Semua status bayar</option>
              {PAYMENT_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            <DimensionFilters catalog={catalog} value={f} onChange={change} />
          </Filters>
          <Filters>
            <label className="check"><input type="checkbox" checked={f.open} onChange={(e) => change({ open: e.target.checked })} /> Belum lunas</label>
            <label className="check"><input type="checkbox" checked={f.overdue} onChange={(e) => change({ overdue: e.target.checked })} /> Jatuh tempo lewat</label>
            <label className="inline-field">Jatuh tempo ≤ <input className="input amount" style={{ width: 80 }} type="number" min={1} max={365} inputMode="numeric" aria-label="Jatuh tempo dalam hari" placeholder="hari" value={f.due_within} onChange={(e) => change({ due_within: e.target.value })} /> hari</label>
            <label className="check"><input type="checkbox" checked={f.mine} onChange={(e) => change({ mine: e.target.checked })} /> Buatan saya</label>
          </Filters>
          <Filters>
            {date('posting_from', 'Posting dari')}
            {date('posting_to', 'Posting sampai')}
            {date('document_from', 'Dokumen dari')}
            {date('document_to', 'Dokumen sampai')}
            {date('due_from', 'Jatuh tempo dari')}
            {date('due_to', 'Jatuh tempo sampai')}
          </Filters>
        </div>

        {invoices.loading && !invoices.data ? (
          <Loading />
        ) : invoices.error || !invoices.data ? (
          <ErrorNotice error={invoices.error} onRetry={invoices.reload} />
        ) : invoices.data.data.length === 0 ? (
          <EmptyState title="Tidak ada faktur">Ubah filter{access.canChange('accounting.ar_invoice.create') ? ' atau buat faktur baru' : ''}.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar faktur pelanggan"
              rows={invoices.data.data}
              rowKey={(i) => i.id}
              scroll
              columns={[
                { header: 'Nomor', primary: true, cell: (i) => <Link to={`${AR_PATH.invoices}/${i.id}`} className="mono">{i.document_number ?? 'Draf'}</Link> },
                { header: 'Pelanggan', cell: (i) => (i.customer ? <>{i.customer.name}<div className="muted mono">{i.customer.code}</div></> : <span className="muted">—</span>) },
                { header: 'Referensi pelanggan', cell: (i) => (i.customer_reference ? <span className="mono">{i.customer_reference}</span> : <span className="muted">—</span>) },
                { header: 'Tanggal posting', cell: (i) => formatDate(i.posting_date) },
                { header: 'Jatuh tempo', cell: (i) => <>{formatDate(i.due_date)}{i.status === 'POSTED' && i.payment_status !== 'PAID' && i.due_date.slice(0, 10) < today && <div><Badge tone="bad">Lewat jatuh tempo</Badge></div>}</> },
                { header: 'Total', align: 'right', cell: (i) => <DocAmount value={i.total_amount} doc={i} functional={i.functional_total_amount} /> },
                { header: 'Diterima', align: 'right', cell: (i) => (i.status === 'POSTED' ? <Money value={i.received_amount} /> : <span className="muted">—</span>) },
                { header: 'Dikreditkan', align: 'right', cell: (i) => (i.status === 'POSTED' ? <Money value={i.credited_amount} /> : <span className="muted">—</span>) },
                { header: 'Saldo piutang', align: 'right', cell: (i) => (i.status === 'POSTED' ? <DocAmount value={i.outstanding_amount} doc={i} /> : <span className="muted">—</span>) },
                { header: 'Status bayar', cell: (i) => (i.payment_status ? <StatusBadge status={i.payment_status} /> : <span className="muted">—</span>) },
                { header: 'Status', cell: (i) => <StatusBadge status={i.status} /> },
              ]}
            />
            <Pagination page={invoices.data.current_page} lastPage={invoices.data.last_page} total={invoices.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}
