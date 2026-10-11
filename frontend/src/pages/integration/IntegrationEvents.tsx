import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { listParams, type Page } from '../../lib/operational'
import { usePagedList } from '../operational/expense/usePagedList'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { EventBadge } from './components'
import { RetryDialog } from './EventDialogs'
import { ENDPOINTS, PATHS, useIntegrationAccess } from './data'
import { KNOWN_EVENT_TYPES, eventStatusText, eventTypeLabel, integrationError } from './labels'
import { EVENT_STATUSES, RETRYABLE, type Connection, type IntegrationEvent } from './types'

type Filter = { connection_id: string; status: string; event_type: string; from: string; to: string; conflict: boolean }

export default function IntegrationEvents() {
  const access = useIntegrationAccess()
  const [search] = useSearchParams()
  const [retrying, setRetrying] = useState<IntegrationEvent | null>(null)
  const connections = useResource(async () => (await api.get<Page<Connection>>(ENDPOINTS.connections, { params: { per_page: 200 } })).data.data, [])
  const { filter, change, setPage, list } = usePagedList<IntegrationEvent, Filter>(
    ENDPOINTS.events,
    { connection_id: search.get('connection_id') ?? '', status: search.get('status') ?? '', event_type: '', from: '', to: '', conflict: search.get('conflict') === '1' },
    listParams,
  )
  const rows = list.data?.data ?? []
  const known = (connections.data ?? []).some((c) => c.id === filter.connection_id)
  const filtered = Object.values(filter).some((v) => v !== '' && v !== false)

  return (
    <>
      <PageHeader title="Kotak masuk kejadian" description="Kejadian yang diterima dari sistem sumber, dari penerimaan sampai dibukukan. Isi kejadian tidak pernah berubah; yang berubah hanya status pemrosesannya." />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <select className="select" aria-label="Koneksi" value={filter.connection_id} onChange={(e) => change('connection_id', e.target.value)}>
              <option value="">Semua koneksi</option>
              {filter.connection_id && !known && <option value={filter.connection_id}>Koneksi terpilih</option>}
              {(connections.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
            <select className="select" aria-label="Status" value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
              {EVENT_STATUSES.map((s) => <option key={s} value={s}>{eventStatusText(s)[0]}</option>)}
            </select>
            <select className="select" aria-label="Jenis kejadian" value={filter.event_type} onChange={(e) => change('event_type', e.target.value)}>
              <option value="">Semua jenis</option>
              {KNOWN_EVENT_TYPES.map((t) => <option key={t} value={t}>{eventTypeLabel(t)} ({t.replace('optifleet.', '')})</option>)}
            </select>
            <input className="input" type="date" aria-label="Terjadi dari" value={filter.from} onChange={(e) => change('from', e.target.value)} />
            <input className="input" type="date" aria-label="Terjadi sampai" value={filter.to} onChange={(e) => change('to', e.target.value)} />
            <label className="check">
              <input type="checkbox" checked={filter.conflict} onChange={(e) => change('conflict', e.target.checked)} /> Hanya yang konflik isi
            </label>
          </Filters>
        </div>
        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={integrationError(list.error)} onRetry={list.reload} />
        ) : rows.length === 0 ? (
          <EmptyState title={filtered ? 'Tidak ada kejadian' : 'Belum ada kejadian'}>
            {filtered ? 'Tidak ada kejadian yang cocok dengan filter ini.' : 'Kejadian muncul di sini setelah koneksi aktif menariknya dari sistem sumber.'}
          </EmptyState>
        ) : (
          <>
            <DataTable
              caption="Kejadian integrasi"
              rows={rows}
              rowKey={(e) => e.id}
              scroll
              columns={[
                { header: 'Diterima', primary: true, cell: (e) => formatDateTime(e.received_at) },
                { header: 'Jenis kejadian', cell: (e) => eventTypeLabel(e.event_type) },
                { header: 'ID kejadian', cell: (e) => <Link className="mono" to={PATHS.event(e.id)}>{e.event_id}</Link> },
                { header: 'Status', cell: (e) => <><EventBadge status={e.status} />{e.conflict && <> <Badge tone="warn">Konflik isi</Badge></>}</> },
                { header: 'Percobaan', align: 'right', cell: (e) => e.attempts },
                { header: 'Kode galat', cell: (e) => e.last_error_code ?? '—' },
                {
                  header: 'Tindakan',
                  actions: true,
                  cell: (e) => access.canRetry && RETRYABLE.includes(e.status)
                    ? <Button size="sm" aria-label={`Ulangi ${e.event_id}`} onClick={() => setRetrying(e)}>Ulangi</Button>
                    : null,
                },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>
      {retrying && <RetryDialog event={retrying} onDone={list.reload} onClose={() => setRetrying(null)} />}
    </>
  )
}
