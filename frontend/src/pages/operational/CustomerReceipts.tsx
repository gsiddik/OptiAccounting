import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useDebounced, useResource } from '../../lib/hooks'
import { statusLabel } from '../../lib/labels'
import { API, DOC_STATUSES, MODULES, useCashBankAccounts, useCustomers, useModuleAccess, type Page } from '../../lib/operational'
import { paymentMethodLabels } from '../../lib/operationalLabels'
import { useDimensions } from '../accounting/data'
import { exportQuery, listQuery, statusFromSearch } from './payables/lists'
import { AR_PATH } from './receivables/paths'
import { DocAmount } from './foreign'
import type { ReceiptRow } from './receivables/types'
import { DimensionFilters, ExportButton, Filters, Money, ReadOnlyNotice } from './shared'

const EMPTY = {
  q: '', status: '', customer_id: '', cash_bank_account_id: '', receipt_from: '', receipt_to: '', posting_from: '', posting_to: '',
  branch_id: '', business_unit_id: '', cost_center_id: '', mine: false,
}
type FilterState = typeof EMPTY
type DateKey = 'receipt_from' | 'receipt_to' | 'posting_from' | 'posting_to'
const dateChange = (key: DateKey, value: string): Partial<FilterState> => {
  const patch: Partial<Record<DateKey, string>> = {}
  patch[key] = value
  return patch
}

export default function CustomerReceipts() {
  const navigate = useNavigate()
  const { search } = useLocation()
  const access = useModuleAccess(MODULES.ar)
  const { customers } = useCustomers()
  const cash = useCashBankAccounts()
  const { catalog } = useDimensions()
  const [f, setF] = useState<FilterState>(() => ({ ...EMPTY, status: statusFromSearch(search) }))
  const [page, setPage] = useState(1)
  const q = useDebounced(f.q)
  const filter = { ...f, q }

  const receipts = useResource(async () => (await api.get<Page<ReceiptRow>>(`${API}/customer-receipts`, { params: listQuery(filter, page) })).data, [page, JSON.stringify(filter)])

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
        title="Penerimaan pelanggan"
        description="Uang yang diterima dari pelanggan untuk melunasi faktur terposting. Penerimaan yang sudah diposting tidak dapat diubah; pembalikan melepas alokasinya dan mengembalikan saldo piutang faktur."
        actions={
          <>
            <ExportButton path={`${API}/customer-receipts/export`} params={exportQuery(filter)} filename="penerimaan-pelanggan.csv" />
            {access.canChange('accounting.ar_receipt.create') && <Button variant="primary" onClick={() => navigate(`${AR_PATH.receipts}/baru`)}>Penerimaan baru</Button>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body stack">
          <Filters>
            <input className="input" type="search" placeholder="Cari nomor, referensi, deskripsi" aria-label="Cari penerimaan" value={f.q} onChange={(e) => change({ q: e.target.value })} />
            <select className="select" aria-label="Status" value={f.status} onChange={(e) => change({ status: e.target.value })}>
              <option value="">Semua status</option>
              {DOC_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            <select className="select" aria-label="Pelanggan" value={f.customer_id} onChange={(e) => change({ customer_id: e.target.value })}>
              <option value="">Semua pelanggan</option>
              {customers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
            </select>
            {cash.accounts.length > 0 && (
              <select className="select" aria-label="Akun kas/bank" value={f.cash_bank_account_id} onChange={(e) => change({ cash_bank_account_id: e.target.value })}>
                <option value="">Semua akun kas/bank</option>
                {cash.accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
              </select>
            )}
            <DimensionFilters catalog={catalog} value={f} onChange={change} />
            <label className="check"><input type="checkbox" checked={f.mine} onChange={(e) => change({ mine: e.target.checked })} /> Buatan saya</label>
          </Filters>
          <Filters>
            {date('receipt_from', 'Terima dari')}
            {date('receipt_to', 'Terima sampai')}
            {date('posting_from', 'Posting dari')}
            {date('posting_to', 'Posting sampai')}
          </Filters>
        </div>

        {receipts.loading && !receipts.data ? (
          <Loading />
        ) : receipts.error || !receipts.data ? (
          <ErrorNotice error={receipts.error} onRetry={receipts.reload} />
        ) : receipts.data.data.length === 0 ? (
          <EmptyState title="Tidak ada penerimaan">Ubah filter{access.canChange('accounting.ar_receipt.create') ? ' atau buat penerimaan baru' : ''}.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar penerimaan pelanggan"
              rows={receipts.data.data}
              rowKey={(r) => r.id}
              scroll
              columns={[
                { header: 'Nomor', primary: true, cell: (r) => <Link to={`${AR_PATH.receipts}/${r.id}`} className="mono">{r.document_number ?? 'Draf'}</Link> },
                { header: 'Pelanggan', cell: (r) => (r.customer ? <>{r.customer.name}<div className="muted mono">{r.customer.code}</div></> : <span className="muted">—</span>) },
                { header: 'Tanggal terima', cell: (r) => formatDate(r.receipt_date) },
                { header: 'Tanggal posting', cell: (r) => formatDate(r.posting_date) },
                { header: 'Akun kas/bank', cell: (r) => (r.cash_bank_account ? <>{r.cash_bank_account.name}<div className="muted mono">{r.cash_bank_account.code}</div></> : <span className="muted">—</span>) },
                { header: 'Metode', cell: (r) => (r.receipt_method ? paymentMethodLabels[r.receipt_method] ?? r.receipt_method : <span className="muted">—</span>) },
                { header: 'Jumlah', align: 'right', cell: (r) => <DocAmount value={r.amount} doc={r} functional={r.functional_amount} /> },
                { header: 'Dialokasikan', align: 'right', cell: (r) => <Money value={r.allocated_amount} /> },
                { header: 'Status', cell: (r) => <StatusBadge status={r.status} /> },
              ]}
            />
            <Pagination page={receipts.data.current_page} lastPage={receipts.data.last_page} total={receipts.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}
