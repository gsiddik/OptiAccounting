import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination } from '../../components/ui'
import { formatDate, formatDateTime } from '../../lib/format'
import { listParams } from '../../lib/operational'
import { usePagedList } from '../operational/expense/usePagedList'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { ConnectionBadge } from './components'
import { ConnectionForm } from './ConnectionForm'
import { ENDPOINTS, PATHS, useIntegrationAccess } from './data'
import { connectionText, integrationError, sourceLabel } from './labels'
import { CONNECTION_STATUSES, type Connection } from './types'

export default function IntegrationConnections() {
  const access = useIntegrationAccess()
  const navigate = useNavigate()
  const [creating, setCreating] = useState(false)
  const { filter, change, setPage, list } = usePagedList<Connection, { status: string }>(ENDPOINTS.connections, { status: '' }, listParams)
  const rows = list.data?.data ?? []

  return (
    <>
      <PageHeader
        title="Koneksi integrasi"
        description="Koneksi menarik fakta bisnis dari sistem operasional (misalnya OptiFleet) dan membukukannya lewat aturan posting."
        actions={access.canManage && <Button variant="primary" onClick={() => setCreating(true)}>Koneksi baru</Button>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <select className="select" aria-label="Status" value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
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
            title="Belum ada koneksi"
            action={access.canManage && <Button variant="primary" onClick={() => setCreating(true)}>Buat koneksi pertama</Button>}
          >
            Koneksi menghubungkan OptiEntry ke sistem lain yang memiliki data operasional, seperti faktur bengkel di OptiFleet. Sistem sumber tetap menjadi pemilik data operasionalnya; OptiEntry hanya menerima fakta bisnis dan membukukannya. OptiEntry berjalan penuh tanpa koneksi, dan pembukuan manual tidak terpengaruh.
          </EmptyState>
        ) : rows.length === 0 ? (
          <EmptyState title="Tidak ada koneksi">Tidak ada koneksi dengan status ini.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Koneksi integrasi"
              rows={rows}
              rowKey={(c) => c.id}
              scroll
              columns={[
                { header: 'Nama', primary: true, cell: (c) => <Link to={PATHS.connection(c.id)}>{c.name}</Link> },
                { header: 'Sistem sumber', cell: (c) => sourceLabel(c.source_system) },
                { header: 'Status', cell: (c) => <ConnectionBadge status={c.status} /> },
                { header: 'Tanggal cutover', cell: (c) => formatDate(c.cutover_date) },
                { header: 'Tarikan terakhir', cell: (c) => formatDateTime(c.last_pulled_at) },
                { header: 'Gagal berturut-turut', align: 'right', cell: (c) => c.consecutive_failures },
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
