import { useState } from 'react'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Card, EmptyState, ErrorNotice, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { EventBadge, ReadinessBadge, ServerDecides, YearEndBadge } from './components'
import { ENDPOINTS, PATHS, useClosingAccess } from './data'
import { closingError, fiscalYearStates, transitionText } from './labels'
import type { ClosingOverviewData, OverviewPeriod, OverviewYear } from './types'

const NO_READINESS: Record<string, string> = { FUTURE: 'Belum dibuka', CLOSED: 'Sudah ditutup', OPEN: 'Periode belum berakhir', SOFT_CLOSED: 'Periode belum berakhir' }

export default function ClosingOverview() {
  const access = useClosingAccess()
  const overview = useResource(async () => (await api.get<ClosingOverviewData>(ENDPOINTS.overview)).data, [])
  const data = overview.data

  return (
    <>
      <PageHeader
        title="Penutupan periode"
        description="Status periode, kesiapan penutupan dan tindakan terakhir. Hasil kesiapan dihitung server dari buku besar setiap kali halaman dibuka."
        actions={
          <>
            <Link className="btn" to={PATHS.history}>Riwayat</Link>
            <Link className="btn" to={PATHS.policy}>Kebijakan</Link>
            {access.canViewYearEnd && <Link className="btn" to={PATHS.yearEnd}>Tutup tahun</Link>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      <ServerDecides />
      {overview.error ? <Card><ErrorNotice error={closingError(overview.error)} onRetry={overview.reload} /></Card> : !data ? <Card><Loading /></Card> : <Body data={data} showYearEnd={access.canViewYearEnd} />}
    </>
  )
}

function Body({ data, showYearEnd }: { data: ClosingOverviewData; showYearEnd: boolean }) {
  const [year, setYear] = useState('')
  const periods = data.periods.filter((p) => !year || p.fiscal_year_id === year)

  return (
    <>
      <Card flush title="Periode" actions={<span className="muted">Tanggal bisnis {formatDate(data.business_date)}</span>}>
        <div className="card-body">
          <Filters>
            <select className="select" aria-label="Tahun fiskal" value={year} onChange={(e) => setYear(e.target.value)}>
              <option value="">Semua tahun fiskal</option>
              {data.fiscal_years.map((y) => <option key={y.id} value={y.id}>{y.code} · {y.name}</option>)}
            </select>
          </Filters>
        </div>
        {periods.length === 0 ? (
          <EmptyState title="Belum ada periode">Periode dibuat bersama tahun fiskal di kalender fiskal. Periode yang sudah ada akan tampil di sini.</EmptyState>
        ) : (
          <PeriodsTable periods={periods} />
        )}
      </Card>
      <Card flush title="Tahun fiskal">
        <YearsTable years={data.fiscal_years} showYearEnd={showYearEnd} />
      </Card>
    </>
  )
}

function PeriodsTable({ periods }: { periods: OverviewPeriod[] }) {
  return (
    <DataTable
      caption="Periode akuntansi dan kesiapan penutupannya"
      rows={periods}
      rowKey={(p) => p.id}
      scroll
      columns={[
        { header: 'Periode', primary: true, cell: (p) => <><Link to={PATHS.period(p.id)}>{p.code}</Link><div className="muted">{p.name}</div></> },
        { header: 'Rentang', cell: (p) => `${formatDate(p.start_date)} – ${formatDate(p.end_date)}` },
        { header: 'Status', cell: (p) => <StatusBadge status={p.status} /> },
        { header: 'Kesiapan', cell: (p) => (p.readiness ? <ReadinessBadge result={p.readiness.result} /> : <span className="muted">{NO_READINESS[p.status] ?? '—'}</span>) },
        { header: 'Penghalang', cell: (p) => (p.readiness ? <>{p.readiness.blocking.length}{p.readiness.blocking.length > 0 && <div className="muted preview-note">{p.readiness.blocking.join(', ')}</div>}</> : '—') },
        { header: 'Peringatan', cell: (p) => (p.readiness ? <>{p.readiness.warnings.length}{p.readiness.warnings.length > 0 && <div className="muted preview-note">{p.readiness.warnings.join(', ')}</div>}</> : '—') },
        { header: 'Tindakan terakhir', cell: (p) => <LastAction period={p} /> },
        { header: 'Rincian', actions: true, cell: (p) => <Link className="btn btn-sm" to={PATHS.period(p.id)} aria-label={`Buka periode ${p.code}`}>Buka</Link> },
      ]}
    />
  )
}

function LastAction({ period }: { period: OverviewPeriod }) {
  const e = period.last_event
  if (!e && !period.last_pre_close_at) return <span className="muted">Belum ada</span>
  return (
    <>
      {e && (
        <>
          <EventBadge type={e.event_type} /> {transitionText(e.from_status, e.to_status)}
          <div className="muted">{e.actor_name ?? 'Sistem'} · {formatDateTime(e.at)}</div>
          {e.reason && <div className="muted">{e.reason}</div>}
        </>
      )}
      {period.last_pre_close_at && <div className="muted">Pra-tutup terakhir {formatDateTime(period.last_pre_close_at)}</div>}
    </>
  )
}

function YearsTable({ years, showYearEnd }: { years: OverviewYear[]; showYearEnd: boolean }) {
  if (years.length === 0) return <EmptyState title="Belum ada tahun fiskal">Buat tahun fiskal di kalender fiskal terlebih dahulu.</EmptyState>
  return (
    <DataTable
      caption="Tahun fiskal dan status tutup tahun"
      rows={years}
      rowKey={(y) => y.id}
      scroll
      columns={[
        { header: 'Tahun fiskal', primary: true, cell: (y) => <>{y.code}<div className="muted">{y.name}</div></> },
        { header: 'Rentang', cell: (y) => `${formatDate(y.start_date)} – ${formatDate(y.end_date)}` },
        { header: 'Status', cell: (y) => fiscalYearStates[y.status] ?? y.status },
        { header: 'Tutup tahun', cell: (y) => <><YearEndBadge status={y.year_end.status} />{y.year_end.run_no !== null && <span className="muted"> proses ke-{y.year_end.run_no}</span>}</> },
        { header: 'Rincian', actions: true, cell: (y) => showYearEnd && <Link className="btn btn-sm" to={PATHS.yearEndDetail(y.id)} aria-label={`Tutup tahun ${y.code}`}>Rincian</Link> },
      ]}
    />
  )
}
