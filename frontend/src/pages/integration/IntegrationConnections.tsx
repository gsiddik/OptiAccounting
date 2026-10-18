import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination } from '../../components/ui'
import { useI18n } from '../../i18n'
import { formatDate, formatDateTime } from '../../lib/format'
import { listParams } from '../../lib/operational'
import { usePagedList } from '../operational/expense/usePagedList'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { ConnectionBadge, HealthBadge } from './components'
import { ConnectionForm } from './ConnectionForm'
import { ENDPOINTS, PATHS, useIntegrationAccess } from './data'
import { connectionText, integrationError, sourceLabel } from './labels'
import { CONNECTION_STATUSES, type Connection } from './types'

export default function IntegrationConnections() {
  const { t } = useI18n()
  const access = useIntegrationAccess()
  const navigate = useNavigate()
  const [creating, setCreating] = useState(false)
  const { filter, change, setPage, list } = usePagedList<Connection, { status: string }>(ENDPOINTS.connections, { status: '' }, listParams)
  const rows = list.data?.data ?? []

  return (
    <>
      <PageHeader
        title={t('it.list.title')}
        description={t('it.list.description')}
        actions={access.canManage && <Button variant="primary" onClick={() => setCreating(true)}>{t('it.list.new')}</Button>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <select className="select" aria-label={t('it.col.status')} value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">{t('it.list.all_status')}</option>
              {CONNECTION_STATUSES.map((s) => <option key={s} value={s}>{connectionText(s)[0]}</option>)}
            </select>
          </Filters>
        </div>
        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={integrationError(list.error)} onRetry={list.reload} />
        ) : rows.length === 0 && !filter.status ? (
          <EmptyState
            title={t('it.list.empty.title')}
            action={access.canManage && <Button variant="primary" onClick={() => setCreating(true)}>{t('it.list.empty.create')}</Button>}
          >
            {t('it.list.empty.body')}
          </EmptyState>
        ) : rows.length === 0 ? (
          <EmptyState title={t('it.list.none.title')}>{t('it.list.none.body')}</EmptyState>
        ) : (
          <>
            <DataTable
              caption={t('it.list.title')}
              rows={rows}
              rowKey={(c) => c.id}
              scroll
              columns={[
                { header: t('it.col.name'), primary: true, cell: (c) => <Link to={PATHS.connection(c.id)}>{c.name}</Link> },
                { header: t('it.col.source'), cell: (c) => sourceLabel(c.source_system) },
                { header: t('it.col.status'), cell: (c) => <ConnectionBadge status={c.status} /> },
                { header: t('it.col.health'), cell: (c) => (c.status === 'ACTIVE' || c.status === 'ERROR') && c.health_level ? <HealthBadge level={c.health_level} /> : '—' },
                { header: t('it.col.cutover'), cell: (c) => formatDate(c.cutover_date) },
                { header: t('it.col.last_pull'), cell: (c) => formatDateTime(c.last_pulled_at) },
                { header: t('it.col.failures'), align: 'right', cell: (c) => c.consecutive_failures },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>
      {creating && <ConnectionForm onDone={(saved) => navigate(PATHS.connection(saved.id))} onClose={() => setCreating(false)} />}
    </>
  )
}
