import { Link, useSearchParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination } from '../../components/ui'
import { useCapabilities } from '../../lib/capabilities'
import { formatDateTime } from '../../lib/format'
import { listParams } from '../../lib/operational'
import { usePagedList } from '../operational/expense/usePagedList'
import { Filters } from '../operational/shared'
import { Bullets, CheckBadge, EventBadge, ReadinessBadge } from './components'
import { ENDPOINTS, PATHS, useCalendar, useClosingAccess } from './data'
import { closingError, eventText, transitionText } from './labels'
import { EVENT_TYPES, type ClosingEvent } from './types'

type Filter = { period_id: string; fiscal_year_id: string; event_type: string }

export default function ClosingHistory() {
  const [search] = useSearchParams()
  const calendar = useCalendar()
  const access = useClosingAccess()
  const { filter, change, setPage, list } = usePagedList<ClosingEvent, Filter>(ENDPOINTS.history, { period_id: search.get('period_id') ?? '', fiscal_year_id: '', event_type: '' }, listParams)
  const known = calendar.periods.some((p) => p.id === filter.period_id)
  const rows = list.data?.data ?? []

  return (
    <>
      <PageHeader title="Riwayat penutupan" description="Pra-tutup, tutup sementara, tutup periode, buka kembali dan tutup tahun, lengkap dengan pelaksana, alasan dan hasilnya." />
      <Banner tone="info">
        <strong>Catatan bukti.</strong> Riwayat ini hanya bertambah: baris tidak dapat diubah atau dihapus oleh siapa pun, dan basis data menolaknya. Kolom bukti menyimpan hasil pemeriksaan persis seperti yang dilihat pelaksana saat bertindak, bukan hasil hitung ulang hari ini.
      </Banner>
      <Card flush>
        <div className="card-body">
          <Filters>
            <select className="select" aria-label="Periode" value={filter.period_id} onChange={(e) => change('period_id', e.target.value)}>
              <option value="">Semua periode</option>
              {filter.period_id && !known && <option value={filter.period_id}>Periode terpilih</option>}
              {calendar.periods.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
            </select>
            <select className="select" aria-label="Tahun fiskal" value={filter.fiscal_year_id} onChange={(e) => change('fiscal_year_id', e.target.value)}>
              <option value="">Semua tahun fiskal</option>
              {calendar.years.map((y) => <option key={y.id} value={y.id}>{y.code} · {y.name}</option>)}
            </select>
            <select className="select" aria-label="Jenis tindakan" value={filter.event_type} onChange={(e) => change('event_type', e.target.value)}>
              <option value="">Semua tindakan</option>
              {EVENT_TYPES.map((t) => <option key={t} value={t}>{eventText(t)[0]}</option>)}
            </select>
          </Filters>
        </div>
        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={closingError(list.error)} onRetry={list.reload} />
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada riwayat">Tidak ada peristiwa penutupan yang cocok dengan filter ini.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Riwayat penutupan periode dan tahun"
              rows={rows}
              rowKey={(e) => e.id}
              scroll
              columns={[
                { header: 'Waktu', primary: true, cell: (e) => formatDateTime(e.created_at) },
                { header: 'Tindakan', cell: (e) => <EventBadge type={e.event_type} /> },
                { header: 'Periode', cell: (e) => <Subject event={e} yearEnd={access.canViewYearEnd} /> },
                { header: 'Hasil', cell: (e) => <Outcome event={e} /> },
                { header: 'Pelaksana', cell: (e) => e.actor_name ?? 'Sistem' },
                { header: 'Alasan', cell: (e) => e.reason ?? '—' },
                { header: 'Referensi', cell: (e) => <Reference event={e} /> },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}

function Subject({ event: e, yearEnd }: { event: ClosingEvent; yearEnd: boolean }) {
  if (e.accounting_period_id) return <Link to={PATHS.period(e.accounting_period_id)}>{e.period_code ?? 'Periode'}</Link>
  if (!e.fiscal_year_id) return <>—</>
  const label = `Tahun fiskal ${e.fiscal_year_code ?? ''}`.trim()
  return yearEnd ? <Link to={PATHS.yearEndDetail(e.fiscal_year_id)}>{label}</Link> : <>{label}</>
}

function Outcome({ event: e }: { event: ClosingEvent }) {
  const checks = e.readiness_checks ?? []
  return (
    <>
      <div>{transitionText(e.from_status, e.to_status)} {e.readiness && <ReadinessBadge result={e.readiness} />}</div>
      {checks.length > 0 && (
        <details>
          <summary>Bukti kesiapan ({checks.length} pemeriksaan)</summary>
          <Bullets>{checks.map((c) => <li key={c.code}>{c.title} <CheckBadge result={c.result} /></li>)}</Bullets>
        </details>
      )}
    </>
  )
}

function Reference({ event: e }: { event: ClosingEvent }) {
  const { can } = useCapabilities()
  if (!e.reference) return <>—</>
  return e.journal_entry_id && can('accounting.journal.view') ? <Link to={PATHS.journal(e.journal_entry_id)}>{e.reference}</Link> : <>{e.reference}</>
}
