import { useState } from 'react'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Banner, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, Stat } from '../../components/ui'
import { downloadCsv, useAccountingAccess, type LedgerReport } from '../../lib/accounting'
import { journalTypeLabels } from '../../lib/accountingLabels'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useDebounced, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { DimensionFilters, Filters, Money, RangeFilter, Side } from './shared'
import { accountLabel, rangeParams, useAccounts, useCalendar, useDefaultRange, useDimensions } from './data'

export default function GeneralLedger() {
  const { can } = useAccountingAccess()
  const { periods } = useCalendar()
  const { accounts } = useAccounts()
  const { catalog } = useDimensions()
  const initial = useDefaultRange()
  const [range, setRange] = useState(initial)
  const [f, setF] = useState({ account_id: '', branch_id: '', business_unit_id: '', cost_center_id: '', q: '' })
  const [page, setPage] = useState(1)
  const [exportError, setExportError] = useState<unknown>(null)
  const q = useDebounced(f.q)

  const params = () => {
    const out: Record<string, string> = { ...rangeParams(range) }
    for (const [k, v] of Object.entries({ ...f, q })) if (v) out[k] = v
    return out
  }
  const report = useResource(async () => (await api.get<LedgerReport>('/app/accounting/general-ledger', { params: { ...params(), page } })).data, [range, f.account_id, f.branch_id, f.business_unit_id, f.cost_center_id, q, page])
  const reset = <T,>(set: (v: T) => void) => (v: T) => { set(v); setPage(1) }
  const r = report.data
  const withAccount = !!r?.account

  return (
    <>
      <PageHeader
        title="Buku besar"
        description="Berasal hanya dari jurnal yang sudah diposting. Saldo ditampilkan menurut saldo normal akun."
        actions={can('accounting.report.export') && <Button onClick={() => void downloadCsv('/app/accounting/general-ledger/export', params(), 'buku-besar.csv').then(() => setExportError(null), setExportError)}>Ekspor CSV</Button>}
      />
      {exportError != null && <Banner tone="bad">{describeError(exportError)}</Banner>}

      <Card flush>
        <div className="card-body">
          <Filters>
            <RangeFilter value={range} onChange={reset(setRange)} periods={periods} />
            <select className="select" aria-label="Akun" value={f.account_id} onChange={(e) => reset(setF)({ ...f, account_id: e.target.value })}>
              <option value="">Semua akun</option>
              {accounts.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
            </select>
            <DimensionFilters catalog={catalog} value={f} onChange={(patch) => reset(setF)({ ...f, ...patch })} />
            <input className="input" type="search" aria-label="Cari baris" placeholder="Cari keterangan atau referensi" value={f.q} onChange={(e) => reset(setF)({ ...f, q: e.target.value })} />
          </Filters>
        </div>
      </Card>

      {report.loading && !r ? (
        <Loading />
      ) : report.error || !r ? (
        <ErrorNotice error={report.error} onRetry={report.reload} />
      ) : (
        <>
          {!r.complete && <Banner tone="info">Laporan ini hanya memuat baris dalam cakupan data Anda, sehingga total tidak mencerminkan seluruh organisasi.</Banner>}
          <div className="grid grid-4">
            {withAccount && <Stat label={`Saldo awal (${r.summary.normal_balance === 'DEBIT' ? 'debit' : 'kredit'})`} value={<Money value={r.summary.opening} />} hint={formatDate(r.range.from)} />}
            <Stat label="Total debit" value={<Money value={r.summary.debit} />} hint="Dalam rentang" />
            <Stat label="Total kredit" value={<Money value={r.summary.credit} />} hint="Dalam rentang" />
            {withAccount && <Stat label="Saldo akhir" value={<Money value={r.summary.closing} />} hint={formatDate(r.range.to)} />}
          </div>
          <Card flush>
            {r.data.length === 0 ? (
              <EmptyState title="Tidak ada transaksi">Tidak ada baris terposting pada rentang dan filter ini.</EmptyState>
            ) : (
              <>
                <DataTable
                  caption="Buku besar"
                  rows={r.data}
                  rowKey={(l) => l.line_id}
                  columns={[
                    { header: 'Tanggal', primary: true, cell: (l) => formatDate(l.posting_date) },
                    { header: 'Jurnal', cell: (l) => <Link to={`/app/akuntansi/jurnal/${l.journal_id}`} className="mono">{l.journal_number}</Link> },
                    ...(withAccount ? [] : [{ header: 'Akun', cell: (l: LedgerReport['data'][number]) => <><span className="mono">{l.account.code}</span> · {l.account.name}</> }]),
                    { header: 'Keterangan', cell: (l) => <>{l.description ?? l.journal_description}{l.journal_type !== 'MANUAL' && <div className="muted">{journalTypeLabels[l.journal_type] ?? l.journal_type}</div>}</> },
                    { header: 'Debit', align: 'right', cell: (l) => <Side value={l.debit} /> },
                    { header: 'Kredit', align: 'right', cell: (l) => <Side value={l.credit} /> },
                    ...(withAccount ? [{ header: 'Saldo', align: 'right' as const, cell: (l: LedgerReport['data'][number]) => <Money value={l.running_balance} strong /> }] : []),
                  ]}
                />
                <Pagination page={r.meta.page} lastPage={r.meta.last_page} total={r.meta.total} onPage={setPage} />
              </>
            )}
          </Card>
        </>
      )}
    </>
  )
}
