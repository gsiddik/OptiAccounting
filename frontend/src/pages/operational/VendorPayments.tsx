import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useDebounced, useResource } from '../../lib/hooks'
import { statusLabel } from '../../lib/labels'
import { API, DOC_STATUSES, MODULES, useCashBankAccounts, useModuleAccess, useVendors, type Page } from '../../lib/operational'
import { paymentMethodLabels } from '../../lib/operationalLabels'
import { useDimensions } from '../accounting/data'
import { exportQuery, listQuery } from './payables/lists'
import { DocAmount } from './foreign'
import type { PaymentRow } from './payables/types'
import { DimensionFilters, ExportButton, Filters, Money, ReadOnlyNotice } from './shared'

const EMPTY = {
  q: '', status: '', vendor_id: '', cash_bank_account_id: '', payment_from: '', payment_to: '', posting_from: '', posting_to: '',
  branch_id: '', business_unit_id: '', cost_center_id: '', mine: false,
}
type FilterState = typeof EMPTY
type DateKey = 'payment_from' | 'payment_to' | 'posting_from' | 'posting_to'
const dateChange = (key: DateKey, value: string): Partial<FilterState> => {
  const patch: Partial<Record<DateKey, string>> = {}
  patch[key] = value
  return patch
}

export default function VendorPayments() {
  const navigate = useNavigate()
  const access = useModuleAccess(MODULES.ap)
  const { vendors } = useVendors()
  const cash = useCashBankAccounts()
  const { catalog } = useDimensions()
  const [f, setF] = useState<FilterState>(EMPTY)
  const [page, setPage] = useState(1)
  const q = useDebounced(f.q)
  const filter = { ...f, q }

  const payments = useResource(async () => (await api.get<Page<PaymentRow>>(`${API}/vendor-payments`, { params: listQuery(filter, page) })).data, [page, JSON.stringify(filter)])

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
        title="Pembayaran vendor"
        description="Pembayaran kepada vendor yang melunasi faktur terposting. Pembayaran yang sudah diposting tidak dapat diubah; pembalikan melepas alokasinya dan mengembalikan saldo faktur."
        actions={
          <>
            <ExportButton path={`${API}/vendor-payments/export`} params={exportQuery(filter)} filename="pembayaran-vendor.csv" />
            {access.canChange('accounting.ap_payment.create') && <Button variant="primary" onClick={() => navigate('/app/akuntansi/pembayaran-vendor/baru')}>Pembayaran baru</Button>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body stack">
          <Filters>
            <input className="input" type="search" placeholder="Cari nomor, referensi, deskripsi" aria-label="Cari pembayaran" value={f.q} onChange={(e) => change({ q: e.target.value })} />
            <select className="select" aria-label="Status" value={f.status} onChange={(e) => change({ status: e.target.value })}>
              <option value="">Semua status</option>
              {DOC_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            <select className="select" aria-label="Vendor" value={f.vendor_id} onChange={(e) => change({ vendor_id: e.target.value })}>
              <option value="">Semua vendor</option>
              {vendors.map((v) => <option key={v.id} value={v.id}>{v.code} · {v.name}</option>)}
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
            {date('payment_from', 'Bayar dari')}
            {date('payment_to', 'Bayar sampai')}
            {date('posting_from', 'Posting dari')}
            {date('posting_to', 'Posting sampai')}
          </Filters>
        </div>

        {payments.loading && !payments.data ? (
          <Loading />
        ) : payments.error || !payments.data ? (
          <ErrorNotice error={payments.error} onRetry={payments.reload} />
        ) : payments.data.data.length === 0 ? (
          <EmptyState title="Tidak ada pembayaran">Ubah filter{access.canChange('accounting.ap_payment.create') ? ' atau buat pembayaran baru' : ''}.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar pembayaran vendor"
              rows={payments.data.data}
              rowKey={(p) => p.id}
              scroll
              columns={[
                { header: 'Nomor', primary: true, cell: (p) => <Link to={`/app/akuntansi/pembayaran-vendor/${p.id}`} className="mono">{p.document_number ?? 'Draf'}</Link> },
                { header: 'Vendor', cell: (p) => (p.vendor ? <>{p.vendor.name}<div className="muted mono">{p.vendor.code}</div></> : <span className="muted">—</span>) },
                { header: 'Tanggal bayar', cell: (p) => formatDate(p.payment_date) },
                { header: 'Tanggal posting', cell: (p) => formatDate(p.posting_date) },
                { header: 'Akun kas/bank', cell: (p) => (p.cash_bank_account ? <>{p.cash_bank_account.name}<div className="muted mono">{p.cash_bank_account.code}</div></> : <span className="muted">—</span>) },
                { header: 'Metode', cell: (p) => (p.payment_method ? paymentMethodLabels[p.payment_method] ?? p.payment_method : <span className="muted">—</span>) },
                { header: 'Jumlah', align: 'right', cell: (p) => <DocAmount value={p.amount} doc={p} functional={p.functional_amount} /> },
                { header: 'Dialokasikan', align: 'right', cell: (p) => <Money value={p.allocated_amount} /> },
                { header: 'Status', cell: (p) => <StatusBadge status={p.status} /> },
              ]}
            />
            <Pagination page={payments.data.current_page} lastPage={payments.data.last_page} total={payments.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}
