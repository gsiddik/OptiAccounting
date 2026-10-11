import { Link, useParams } from 'react-router-dom'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { ReadOnlyNotice } from '../operational/shared'
import { ChecklistPanel } from './ChecklistPanel'
import { CloseActions } from './CloseActions'
import { PeriodEvents } from './PeriodEvents'
import { ReadinessPanel } from './ReadinessPanel'
import { ServerDecides } from './components'
import { ENDPOINTS, PATHS, useCalendar, useClosingAccess } from './data'
import { closingError } from './labels'
import type { Checklist, HistoryPage, Readiness } from './types'

export default function PeriodClosing() {
  const { id = '' } = useParams()
  const access = useClosingAccess()
  const calendar = useCalendar()
  const readiness = useResource(async () => (await api.get<Readiness>(ENDPOINTS.readiness(id))).data, [id])
  const checklist = useResource(async () => (await api.get<Checklist>(ENDPOINTS.checklist(id))).data, [id])
  const events = useResource(async () => (await api.get<HistoryPage>(ENDPOINTS.history, { params: { period_id: id, per_page: 10 } })).data, [id])
  const period = calendar.periods.find((p) => p.id === id)
  const year = calendar.years.find((y) => y.id === period?.fiscal_year_id)

  // After any action the period's state, the readiness, the checklist and the history are all read again from the server.
  const reloadAll = () => {
    calendar.reload()
    readiness.reload()
    checklist.reload()
    events.reload()
  }

  if (calendar.error) return <ErrorNotice error={closingError(calendar.error)} onRetry={calendar.reload} />
  if (!period) {
    return calendar.loading ? <Loading /> : (
      <EmptyState title="Periode tidak ditemukan" action={<Link className="btn" to={PATHS.overview}>Kembali ke penutupan periode</Link>}>Periode ini tidak ada di kalender fiskal organisasi.</EmptyState>
    )
  }

  return (
    <>
      <PageHeader
        title={`Periode ${period.code}`}
        description={<>{period.name} · {formatDate(period.start_date)} – {formatDate(period.end_date)} · <StatusBadge status={period.status} />{year && <> · Tahun fiskal {year.code}</>}</>}
        actions={<CloseActions period={period} readiness={readiness.data} access={access} onDone={reloadAll} />}
      />
      <ReadOnlyNotice show={access.readOnly} />
      {period.status === 'FUTURE' && <Banner tone="info">Periode ini belum dibuka, jadi belum ada yang ditutup. Pemeriksaan di bawah hanya menunjukkan keadaan saat ini.</Banner>}
      {period.status === 'CLOSED' && <Banner tone="info">Periode sudah ditutup. Pemeriksaan di bawah dihitung ulang dari buku besar saat ini sebagai bukti; membuka kembali periode memerlukan alasan dan tercatat permanen.</Banner>}
      <ServerDecides />

      <Card flush title="Kesiapan penutupan">
        <ReadinessPanel readiness={readiness} />
      </Card>
      <Card flush title="Daftar periksa penutupan">
        <ChecklistPanel checklist={checklist} periodId={period.id} canManage={access.canChecklist} onChanged={reloadAll} />
      </Card>
      <Card title="Peristiwa terakhir periode">
        <PeriodEvents events={events} periodId={period.id} />
      </Card>
    </>
  )
}
