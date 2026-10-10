import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { statusLabel } from '../../lib/labels'
import { API, DOC_STATUSES, useCashBankAccounts, useExpenseCategories, useModuleAccess, useVendors } from '../../lib/operational'
import { settlementLabels } from '../../lib/operationalLabels'
import type { Expense } from './expense/types'
import { usePagedList } from './expense/usePagedList'
import { ErrorNotice, ExportButton, Filters, Money, ReadOnlyNotice } from './shared'

const INITIAL = {
  q: '', status: '', settlement: '', vendor_id: '', expense_category_id: '', cash_bank_account_id: '',
  expense_from: '', expense_to: '', posting_from: '', posting_to: '', mine: false,
}

export default function Expenses() {
  const { canChange, readOnly } = useModuleAccess('ACCOUNTING_EXPENSE')
  const navigate = useNavigate()
  const { filter, change, setPage, list, exportParams } = usePagedList<Expense, typeof INITIAL>(`${API}/expenses`, INITIAL)
  const { vendors } = useVendors()
  const { categories } = useExpenseCategories()
  const { accounts } = useCashBankAccounts()

  const text = (name: 'q', label: string, placeholder: string) => (
    <input className="input" type="search" placeholder={placeholder} aria-label={label} value={filter[name]} onChange={(e) => change(name, e.target.value)} />
  )
  const date = (name: 'expense_from' | 'expense_to' | 'posting_from' | 'posting_to', short: string, label: string) => (
    <label className="inline-field">{short} <input className="input" type="date" aria-label={label} value={filter[name]} onChange={(e) => change(name, e.target.value)} /></label>
  )

  return (
    <>
      <PageHeader
        title="Beban"
        description="Biaya yang tidak memerlukan faktur vendor penuh. Beban dicatat sebagai utang atau dibayar langsung dari kas/bank; hanya beban yang sudah diposting yang masuk ke buku besar."
        actions={canChange('accounting.expense.create') && <Button variant="primary" onClick={() => navigate('/app/akuntansi/beban/baru')}>Beban baru</Button>}
      />
      <ReadOnlyNotice show={readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            {text('q', 'Cari beban', 'Cari nomor, deskripsi, referensi, penerima')}
            <select className="select" aria-label="Status" value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
              {DOC_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            <select className="select" aria-label="Penyelesaian" value={filter.settlement} onChange={(e) => change('settlement', e.target.value)}>
              <option value="">Semua penyelesaian</option>
              {Object.entries(settlementLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
            {vendors.length > 0 && (
              <select className="select" aria-label="Vendor" value={filter.vendor_id} onChange={(e) => change('vendor_id', e.target.value)}>
                <option value="">Semua vendor</option>
                {vendors.map((v) => <option key={v.id} value={v.id}>{v.code} · {v.name}</option>)}
              </select>
            )}
            {categories.length > 0 && (
              <select className="select" aria-label="Kategori" value={filter.expense_category_id} onChange={(e) => change('expense_category_id', e.target.value)}>
                <option value="">Semua kategori</option>
                {categories.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
              </select>
            )}
            {accounts.length > 0 && (
              <select className="select" aria-label="Akun kas/bank" value={filter.cash_bank_account_id} onChange={(e) => change('cash_bank_account_id', e.target.value)}>
                <option value="">Semua akun kas/bank</option>
                {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
              </select>
            )}
            {date('expense_from', 'Beban dari', 'Tanggal beban dari')}
            {date('expense_to', 'sampai', 'Tanggal beban sampai')}
            {date('posting_from', 'Posting dari', 'Tanggal posting dari')}
            {date('posting_to', 'sampai', 'Tanggal posting sampai')}
            <label className="check"><input type="checkbox" checked={filter.mine} onChange={(e) => change('mine', e.target.checked)} /> Buatan saya</label>
            <span className="spacer" />
            <ExportButton path={`${API}/expenses/export`} params={exportParams} filename="beban.csv" />
          </Filters>
        </div>

        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={list.error} onRetry={list.reload} />
        ) : list.data.data.length === 0 ? (
          <EmptyState title="Tidak ada beban">Ubah filter atau buat beban baru.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar beban"
              rows={list.data.data}
              rowKey={(e) => e.id}
              columns={[
                { header: 'Nomor', primary: true, cell: (e) => <Link to={`/app/akuntansi/beban/${e.id}`} className="mono">{e.document_number ?? 'Draf'}</Link> },
                { header: 'Tanggal beban', cell: (e) => formatDate(e.expense_date) },
                { header: 'Tanggal posting', cell: (e) => formatDate(e.posting_date) },
                { header: 'Deskripsi', cell: (e) => <>{e.description}{e.reference && <div className="muted">Ref {e.reference}</div>}</> },
                { header: 'Kategori', cell: (e) => e.category?.name ?? <span className="muted">—</span> },
                { header: 'Vendor / penerima', cell: (e) => e.vendor?.name ?? e.payee_name ?? <span className="muted">—</span> },
                { header: 'Penyelesaian', cell: (e) => <>{settlementLabels[e.settlement] ?? e.settlement}{e.cash_bank_account && <div className="muted">{e.cash_bank_account.code}</div>}</> },
                { header: 'Total', align: 'right', cell: (e) => <Money value={e.total_amount} /> },
                { header: 'Status', cell: (e) => <StatusBadge status={e.status} /> },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}
