import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Card, EmptyState, ErrorNotice, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { ReadOnlyNotice } from '../operational/shared'
import { RuleStatusCard } from './RuleStatusCard'
import { Bullets, ServerDecides, YearEndBadge } from './components'
import { ENDPOINTS, PATHS, useClosingAccess } from './data'
import { blockerTitles, closingError, fiscalYearStates } from './labels'
import type { RuleStatus, YearEndList, YearEndSummary } from './types'

export default function YearEnd() {
  const access = useClosingAccess()
  const list = useResource(async () => (await api.get<YearEndList>(ENDPOINTS.yearEnd)).data, [])
  const rule = useResource(async () => (await api.get<RuleStatus>(ENDPOINTS.ruleStatus)).data, [])
  const years = list.data?.fiscal_years

  return (
    <>
      <PageHeader
        title="Tutup tahun"
        description="Menutup saldo semua akun pendapatan dan beban sampai akhir tahun fiskal ke laba ditahan lewat satu jurnal sistem, per cabang dan unit bisnis. Koreksinya adalah pembalikan, bukan penghapusan."
        actions={<Link className="btn" to={PATHS.overview}>Penutupan periode</Link>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <ServerDecides />
      <RuleStatusCard rule={rule} canApply={access.canRules} onApplied={() => { rule.reload(); list.reload() }} />
      <Card flush title="Tahun fiskal">
        {list.error ? (
          <ErrorNotice error={closingError(list.error)} onRetry={list.reload} />
        ) : !years ? (
          <Loading />
        ) : years.length === 0 ? (
          <EmptyState title="Belum ada tahun fiskal">Buat tahun fiskal di kalender fiskal sebelum menutup tahun.</EmptyState>
        ) : (
          <DataTable
            caption="Tahun fiskal dan status tutup tahun"
            rows={years}
            rowKey={(y) => y.id}
            scroll
            columns={[
              { header: 'Tahun fiskal', primary: true, cell: (y) => <><Link to={PATHS.yearEndDetail(y.id)}>{y.code}</Link><div className="muted">{y.name}</div></> },
              { header: 'Rentang', cell: (y) => `${formatDate(y.start_date)} – ${formatDate(y.end_date)}` },
              { header: 'Status', cell: (y) => fiscalYearStates[y.status] ?? y.status },
              { header: 'Periode terakhir', cell: (y) => (y.last_period ? <><Link to={PATHS.period(y.last_period.id)}>{y.last_period.code}</Link> <StatusBadge status={y.last_period.status} /></> : '—') },
              { header: 'Tutup tahun', cell: (y) => <YearEndCell year={y} /> },
              { header: 'Kesiapan', cell: (y) => <Readiness year={y} /> },
              { header: 'Rincian', actions: true, cell: (y) => <Link className="btn btn-sm" to={PATHS.yearEndDetail(y.id)} aria-label={`Rincian tutup tahun ${y.code}`}>Rincian</Link> },
            ]}
          />
        )}
      </Card>
    </>
  )
}

function YearEndCell({ year }: { year: YearEndSummary }) {
  const { can } = useCapabilities()
  const run = year.year_end
  return (
    <>
      <YearEndBadge status={run.status} />
      {run.run_no !== null && <span className="muted"> proses ke-{run.run_no}</span>}
      {run.journal_number && <div>{run.journal_entry_id && can('accounting.journal.view') ? <Link to={PATHS.journal(run.journal_entry_id)}>{run.journal_number}</Link> : run.journal_number}</div>}
    </>
  )
}

function Readiness({ year }: { year: YearEndSummary }) {
  if (year.year_end.status === 'POSTED') return <span className="muted">Sudah diproses; balik dulu untuk menjalankan ulang.</span>
  if (year.can_run) return <Badge tone="ok">Dapat dijalankan</Badge>
  return (
    <>
      <Badge tone="bad">{year.blockers.length} penghalang</Badge>
      <Bullets>{year.blockers.map((code) => <li key={code}>{blockerTitles[code] ?? code} <span className="muted">({code})</span></li>)}</Bullets>
    </>
  )
}
