import { Link } from 'react-router-dom'
import { EmptyState, ErrorNotice, Loading } from '../../components/ui'
import { formatDateTime } from '../../lib/format'
import type { Resource } from '../../lib/hooks'
import { EventBadge, ReadinessBadge } from './components'
import { PATHS } from './data'
import { closingError, transitionText } from './labels'
import type { HistoryPage } from './types'

/** The latest closing events of one period (newest first), straight from the append-only history. */
export function PeriodEvents({ events, periodId }: { events: Resource<HistoryPage>; periodId: string }) {
  if (events.error) return <ErrorNotice error={closingError(events.error)} onRetry={events.reload} />
  if (!events.data) return <Loading />
  const rows = events.data.data
  if (rows.length === 0) return <EmptyState title="Belum ada peristiwa">Pra-tutup, tutup sementara, tutup periode dan buka kembali periode ini akan tercatat di sini.</EmptyState>

  return (
    <div className="stack">
      <ol className="timeline">
        {rows.map((e) => (
          <li key={e.id}>
            <EventBadge type={e.event_type} /> <strong>{transitionText(e.from_status, e.to_status)}</strong>
            <span className="muted"> · {e.actor_name ?? 'Sistem'} · {formatDateTime(e.created_at)}</span> {e.readiness && <ReadinessBadge result={e.readiness} />}
            {e.reason && <div>{e.reason}</div>}
          </li>
        ))}
      </ol>
      <Link to={`${PATHS.history}?period_id=${periodId}`}>Lihat seluruh riwayat periode ini</Link>
    </div>
  )
}
