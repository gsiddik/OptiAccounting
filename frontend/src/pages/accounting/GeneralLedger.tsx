import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Banner, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, Stat } from '../../components/ui'
import { downloadCsv, useAccountingAccess } from '../../lib/accounting'
import { journalTypeLabels } from '../../lib/accountingLabels'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useDebounced, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { DimensionFilters, Filters, Money, Side } from './shared'
import { accountLabel, useAccounts, useCalendar, useDefaultRange, useDimensions, type Range } from './data'
import { AccountFilters, LedgerWindow, SourceFilters } from './ledger-filters'
import { NOT_COMPLETE_NOTE, ledgerError } from './ledger-labels'
import { NO_ACCOUNT_FILTER, accountFilterParams, windowParams } from './ledger-params'
import { ForeignNote, SourceNote } from './ledger-rows'
import type { LedgerLineX, LedgerReportX } from './ledger-types'

const LINK_KEYS = ['period_id', 'fiscal_year_id', 'from', 'to']

export default function GeneralLedger() {
  const { can } = useAccountingAccess()
  const { periods, years } = useCalendar()
  const { accounts } = useAccounts()
  const { catalog } = useDimensions()
  const initial = useDefaultRange()
  // A drill link from another report (account balance) may carry the account, the window and the dimensions.
  const [link] = useSearchParams()
  const linked = LINK_KEYS.some((k) => link.get(k))
  const [range, setRange] = useState<Range>(() => (linked ? { period_id: link.get('period_id') ?? '', from: link.get('from') ?? '', to: link.get('to') ?? '' } : initial))
  const [year, setYear] = useState(() => link.get('fiscal_year_id') ?? '')
  const [acc, setAcc] = useState(NO_ACCOUNT_FILTER)
  const [f, setF] = useState(() => ({
    account_id: link.get('account_id') ?? '', branch_id: link.get('branch_id') ?? '', business_unit_id: link.get('business_unit_id') ?? '', cost_center_id: link.get('cost_center_id') ?? '',
    q: '', source_type: '', journal_type: '',
  }))
  const [page, setPage] = useState(1)
  const [exportError, setExportError] = useState<unknown>(null)
  const q = useDebounced(f.q)
  const source = useDebounced(f.source_type)

  const params = () => {
    const out: Record<string, string> = { ...windowParams(range, year), ...accountFilterParams(acc) }
    for (const [k, v] of Object.entries({ ...f, q, source_type: source })) if (v) out[k] = v
    return out
  }
  const report = useResource(async () => (await api.get<LedgerReportX>('/app/accounting/general-ledger', { params: { ...params(), page } })).data, [JSON.stringify(params()), page])
  const reset = <T,>(set: (v: T) => void) => (v: T) => { set(v); setPage(1) }
  const r = report.data
  const withAccount = !!r?.account
  const functional = r?.functional_currency

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
            <LedgerWindow range={range} year={year} years={years} periods={periods} defaultRange={initial} onChange={(next) => { setRange(next.range); setYear(next.year); setPage(1) }} />
            <select className="select" aria-label="Akun" value={f.account_id} onChange={(e) => reset(setF)({ ...f, account_id: e.target.value })}>
              <option value="">Semua akun</option>
              {accounts.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
            </select>
            <DimensionFilters catalog={catalog} value={f} onChange={(patch) => reset(setF)({ ...f, ...patch })} />
            <AccountFilters value={acc} onChange={(patch) => reset(setAcc)({ ...acc, ...patch })} />
            <SourceFilters source={f.source_type} journalType={f.journal_type} onChange={(patch) => reset(setF)({ ...f, ...patch })} />
            <input className="input" type="search" aria-label="Cari baris" placeholder="Cari keterangan atau referensi" value={f.q} onChange={(e) => reset(setF)({ ...f, q: e.target.value })} />
          </Filters>
        </div>
      </Card>

      {report.loading && !r ? (
        <Loading />
      ) : report.error || !r ? (
        <ErrorNotice error={ledgerError(report.error)} onRetry={report.reload} />
      ) : (
        <>
          {!r.complete && <Banner tone="info">{NOT_COMPLETE_NOTE}</Banner>}
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
                    ...(withAccount ? [] : [{ header: 'Akun', cell: (l: LedgerLineX) => <><span className="mono">{l.account.code}</span> · {l.account.name}</> }]),
                    { header: 'Keterangan', cell: (l) => <>{l.description ?? l.journal_description}{l.journal_type !== 'MANUAL' && <div className="muted">{journalTypeLabels[l.journal_type] ?? l.journal_type}</div>}<SourceNote line={l} /></> },
                    { header: 'Debit', align: 'right', cell: (l) => <><Side value={l.debit} /><ForeignNote line={l} side="debit" functional={functional} /></> },
                    { header: 'Kredit', align: 'right', cell: (l) => <><Side value={l.credit} /><ForeignNote line={l} side="credit" functional={functional} /></> },
                    ...(withAccount ? [{ header: 'Saldo', align: 'right' as const, cell: (l: LedgerLineX) => <Money value={l.running_balance} strong /> }] : []),
                  ]}
                />
                <Pagination page={r.meta.page} lastPage={r.meta.last_page} total={r.meta.total} onPage={setPage} />
                <p className="card-body muted preview-note">Jurnal penutup akhir tahun (sumber YEAR_END_CLOSING) ikut tercantum. Saldo berjalan bersifat kumulatif dan tidak tampil saat memakai kolom pencarian.</p>
              </>
            )}
          </Card>
        </>
      )}
    </>
  )
}
