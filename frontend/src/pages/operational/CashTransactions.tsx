import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { statusLabel } from '../../lib/labels'
import { API, useCashBankAccounts, useModuleAccess } from '../../lib/operational'
import { accountLabel, useAccounts } from '../accounting/data'
import { KIND, type CashKind, type CashTransaction } from './cash/types'
import { usePagedList } from './expense/usePagedList'
import { ErrorNotice, ExportButton, Filters, Money, ReadOnlyNotice } from './shared'

const INITIAL = {
  q: '', status: '', cash_bank_account_id: '', counter_account_id: '',
  transaction_from: '', transaction_to: '', posting_from: '', posting_to: '', mine: false,
}
const STATUSES = ['DRAFT', 'POSTED', 'CANCELLED', 'REVERSED']

export default function CashTransactions({ kind }: { kind: CashKind }) {
  const k = KIND[kind]
  const base = `${API}/${k.path}`
  const { canChange, readOnly } = useModuleAccess('ACCOUNTING_CASH_BANK')
  const navigate = useNavigate()
  const { filter, change, setPage, list, exportParams } = usePagedList<CashTransaction, typeof INITIAL>(base, INITIAL)
  const { accounts: cashAccounts } = useCashBankAccounts()
  const gl = useAccounts()
  const counters = gl.accounts.filter((a) => a.is_postable && !a.is_control)

  const date = (name: 'transaction_from' | 'transaction_to' | 'posting_from' | 'posting_to', short: string, label: string) => (
    <label className="inline-field">{short} <input className="input" type="date" aria-label={label} value={filter[name]} onChange={(e) => change(name, e.target.value)} /></label>
  )

  return (
    <>
      <PageHeader
        title={k.title}
        description={`${k.direction} di luar utang usaha dan piutang usaha. Setiap transaksi menyebut tujuannya dan satu akun lawan; hanya yang sudah diposting yang masuk ke buku besar.`}
        actions={canChange('accounting.cash_transaction.create') && <Button variant="primary" onClick={() => navigate(`${k.route}/baru`)}>{k.newLabel}</Button>}
      />
      <ReadOnlyNotice show={readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari nomor, tujuan, deskripsi, pihak" aria-label={`Cari ${k.title.toLowerCase()}`} value={filter.q} onChange={(e) => change('q', e.target.value)} />
            <select className="select" aria-label="Status" value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
              {STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            {cashAccounts.length > 0 && (
              <select className="select" aria-label="Akun kas/bank" value={filter.cash_bank_account_id} onChange={(e) => change('cash_bank_account_id', e.target.value)}>
                <option value="">Semua akun kas/bank</option>
                {cashAccounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
              </select>
            )}
            {counters.length > 0 && (
              <select className="select" aria-label="Akun lawan" value={filter.counter_account_id} onChange={(e) => change('counter_account_id', e.target.value)}>
                <option value="">Semua akun lawan</option>
                {counters.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
              </select>
            )}
            {date('transaction_from', 'Transaksi dari', 'Tanggal transaksi dari')}
            {date('transaction_to', 'sampai', 'Tanggal transaksi sampai')}
            {date('posting_from', 'Posting dari', 'Tanggal posting dari')}
            {date('posting_to', 'sampai', 'Tanggal posting sampai')}
            <label className="check"><input type="checkbox" checked={filter.mine} onChange={(e) => change('mine', e.target.checked)} /> Buatan saya</label>
            <span className="spacer" />
            <ExportButton path={`${base}/export`} params={exportParams} filename={`${k.path}.csv`} />
          </Filters>
        </div>

        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={list.error} onRetry={list.reload} />
        ) : list.data.data.length === 0 ? (
          <EmptyState title={`Tidak ada ${k.title.toLowerCase()}`}>Ubah filter atau buat transaksi baru.</EmptyState>
        ) : (
          <>
            <DataTable
              caption={`Daftar ${k.title.toLowerCase()}`}
              rows={list.data.data}
              rowKey={(t) => t.id}
              columns={[
                { header: 'Nomor', primary: true, cell: (t) => <Link to={`${k.route}/${t.id}`} className="mono">{t.document_number ?? 'Draf'}</Link> },
                { header: 'Tanggal transaksi', cell: (t) => formatDate(t.transaction_date) },
                { header: 'Tanggal posting', cell: (t) => formatDate(t.posting_date) },
                { header: 'Tujuan', cell: (t) => <>{t.purpose}{t.counterparty_name && <div className="muted">{t.counterparty_name}</div>}</> },
                { header: 'Akun kas/bank', cell: (t) => (t.cash_bank_account ? <><span className="mono">{t.cash_bank_account.code}</span> · {t.cash_bank_account.name}</> : <span className="muted">—</span>) },
                { header: k.counterShort, cell: (t) => (t.counter_account ? <><span className="mono">{t.counter_account.code}</span> · {t.counter_account.name}</> : <span className="muted">—</span>) },
                { header: 'Jumlah', align: 'right', cell: (t) => <Money value={t.amount} /> },
                { header: 'Status', cell: (t) => <StatusBadge status={t.status} /> },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}
