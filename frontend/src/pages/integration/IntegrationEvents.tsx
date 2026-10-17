import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination } from '../../components/ui'
import { useI18n } from '../../i18n'
import { api } from '../../lib/api'
import { formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { listParams, type Page } from '../../lib/operational'
import { usePagedList } from '../operational/expense/usePagedList'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { BooksBadge, EventBadge, OutcomeBadge } from './components'
import { DismissDialog, RetryDialog } from './EventDialogs'
import { ENDPOINTS, PATHS, useIntegrationAccess } from './data'
import { KNOWN_EVENT_TYPES, eventStatusText, eventTypeLabel, integrationError } from './labels'
import { EVENT_STATUSES, RETRYABLE, type Connection, type IntegrationEvent } from './types'

type Filter = { connection_id: string; status: string; event_type: string; from: string; to: string; conflict: boolean }

export default function IntegrationEvents() {
  const { t } = useI18n()
  const access = useIntegrationAccess()
  const [search] = useSearchParams()
  const [retrying, setRetrying] = useState<IntegrationEvent | null>(null)
  const [dismissing, setDismissing] = useState<IntegrationEvent | null>(null)
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
      <PageHeader title={t('it.events.title')} description={t('it.events.description')} />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <select className="select" aria-label={t('it.events.f.connection')} value={filter.connection_id} onChange={(e) => change('connection_id', e.target.value)}>
              <option value="">{t('it.events.f.all_connections')}</option>
              {filter.connection_id && !known && <option value={filter.connection_id}>{t('it.events.f.selected_connection')}</option>}
              {(connections.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
            <select className="select" aria-label={t('it.events.f.status')} value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">{t('it.list.all_status')}</option>
              {EVENT_STATUSES.map((s) => <option key={s} value={s}>{eventStatusText(s)[0]}</option>)}
            </select>
            <select className="select" aria-label={t('it.events.f.type')} value={filter.event_type} onChange={(e) => change('event_type', e.target.value)}>
              <option value="">{t('it.events.f.all_types')}</option>
              {KNOWN_EVENT_TYPES.map((t) => <option key={t} value={t}>{eventTypeLabel(t)} ({t.replace('optifleet.', '')})</option>)}
            </select>
            <input className="input" type="date" aria-label={t('it.events.f.from')} value={filter.from} onChange={(e) => change('from', e.target.value)} />
            <input className="input" type="date" aria-label={t('it.events.f.to')} value={filter.to} onChange={(e) => change('to', e.target.value)} />
            <label className="check">
              <input type="checkbox" checked={filter.conflict} onChange={(e) => change('conflict', e.target.checked)} /> {t('it.events.f.conflict')}
            </label>
          </Filters>
        </div>
        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={integrationError(list.error)} onRetry={list.reload} />
        ) : rows.length === 0 ? (
          <EmptyState title={filtered ? t('it.events.empty_filtered') : t('it.events.empty')}>
            {filtered ? t('it.events.empty_filtered_body') : t('it.events.empty_body')}
          </EmptyState>
        ) : (
          <>
            <DataTable
              caption={t('it.events.caption')}
              rows={rows}
              rowKey={(e) => e.id}
              scroll
              columns={[
                { header: t('it.events.col.received'), primary: true, cell: (e) => formatDateTime(e.received_at) },
                { header: t('it.events.col.type'), cell: (e) => eventTypeLabel(e.event_type) },
                { header: t('it.events.col.id'), cell: (e) => <Link className="mono" to={PATHS.event(e.id)}>{e.event_id}</Link> },
                { header: t('it.events.col.status'), cell: (e) => <><EventBadge status={e.status} />{e.conflict && <> <Badge tone="warn">{t('it.events.conflict_badge')}</Badge></>}</> },
                { header: t('it.events.col.books'), cell: (e) => e.status !== 'POSTED' ? '—' : e.books_state ? <BooksBadge state={e.books_state} /> : <OutcomeBadge outcome={e.accounting_outcome} /> },
                { header: t('it.events.col.attempts'), align: 'right', cell: (e) => e.attempts },
                { header: t('it.events.col.code'), cell: (e) => e.last_error_code ?? '—' },
                {
                  header: t('it.events.col.action'),
                  actions: true,
                  cell: (e) => access.canRetry && RETRYABLE.includes(e.status) && !e.conflict
                    ? (
                      <>
                        <Button size="sm" aria-label={t('it.events.retry_aria', { id: e.event_id })} onClick={() => setRetrying(e)}>{t('it.events.retry')}</Button>{' '}
                        <Button size="sm" aria-label={t('it.events.dismiss_aria', { id: e.event_id })} onClick={() => setDismissing(e)}>{t('it.events.dismiss')}</Button>
                      </>
                    )
                    : null,
                },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>
      {retrying && <RetryDialog event={retrying} onDone={list.reload} onClose={() => setRetrying(null)} />}
      {dismissing && <DismissDialog event={dismissing} onDone={list.reload} onClose={() => setDismissing(null)} />}
    </>
  )
}
