import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { listParams } from '../../lib/operational'
import { usePagedList } from '../operational/expense/usePagedList'
import { Filters, Money, ReadOnlyNotice } from '../operational/shared'
import { ExceptionBadge, ReconBadge } from './components'
import { ENDPOINTS, PATHS, cutoffSearch, useMembers, useReconAccess } from './data'
import { useExceptionActions } from './useExceptionActions'
import { exceptionFilterOptions, reconError, slugOf, typeLabels } from './labels'
import { RECON_TYPES, type ReconException } from './types'

const INITIAL = { type: '', status: 'ACTIVE', owner: '', as_of_from: '', as_of_to: '' }
type Filter = typeof INITIAL

/** `owner` is one control for two API filters: "me" is `mine`, a member is `owner_id`; the type travels as its URL slug. */
function serialize(filter: Filter, page: number) {
  const { owner, type, ...rest } = filter
  const params = listParams(rest, page)
  if (type) params.type = slugOf(type)
  if (owner === 'me') params.mine = 1
  else if (owner) params.owner_id = owner
  return params
}

export default function ReconciliationExceptions() {
  const access = useReconAccess()
  const { filter, change, setPage, list } = usePagedList<ReconException, Filter>(ENDPOINTS.exceptions, INITIAL, serialize)
  const { members } = useMembers('')
  const { start, dialogs } = useExceptionActions(list.reload)
  const rows = list.data?.data ?? []

  const date = (name: 'as_of_from' | 'as_of_to', short: string, label: string) => (
    <label className="inline-field">{short} <input className="input" type="date" aria-label={label} value={filter[name]} onChange={(e) => change(name, e.target.value)} /></label>
  )

  return (
    <>
      <PageHeader
        title="Pengecualian rekonsiliasi"
        description="Selisih rekonsiliasi yang perlu ditindaklanjuti. Menjelaskan atau menugaskan tidak mengubah status hitung; pengecualian selesai hanya setelah hitung ulang menunjukkan Cocok."
        actions={<Link className="btn" to={PATHS.overview}>Ringkasan rekonsiliasi</Link>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <select className="select" aria-label="Jenis rekonsiliasi" value={filter.type} onChange={(e) => change('type', e.target.value)}>
              <option value="">Semua jenis</option>
              {RECON_TYPES.map((t) => <option key={t} value={t}>{typeLabels[t]}</option>)}
            </select>
            <select className="select" aria-label="Status penanganan" value={filter.status} onChange={(e) => change('status', e.target.value)}>
              {exceptionFilterOptions.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
            <select className="select" aria-label="Pemilik" value={filter.owner} onChange={(e) => change('owner', e.target.value)}>
              <option value="">Semua pemilik</option>
              <option value="me">Milik saya</option>
              {members.map((m) => <option key={m.user.id} value={m.user.id}>{m.user.name}</option>)}
            </select>
            {date('as_of_from', 'Per tanggal dari', 'Per tanggal dari')}
            {date('as_of_to', 'sampai', 'Per tanggal sampai')}
          </Filters>
        </div>

        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={reconError(list.error)} onRetry={list.reload} />
        ) : rows.length === 0 ? (
          <EmptyState title="Tidak ada pengecualian">Tidak ada pengecualian yang cocok dengan filter ini. Pengecualian dibuka saat rekonsiliasi berselisih dijalankan dan disimpan.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar pengecualian rekonsiliasi"
              rows={rows}
              rowKey={(e) => e.id}
              scroll
              columns={[
                { header: 'Jenis', primary: true, cell: (e) => <Link to={`${PATHS.detail(e.type)}${cutoffSearch({ as_of: e.as_of_date, period_id: '' })}`}>{typeLabels[e.type] ?? e.type}</Link> },
                { header: 'Per tanggal', cell: (e) => formatDate(e.as_of_date) },
                { header: 'Penanganan', cell: (e) => <ExceptionBadge status={e.status} /> },
                { header: 'Status hitung', cell: (e) => <ReconBadge status={e.calculated_status} /> },
                { header: 'Selisih', align: 'right', cell: (e) => <Money value={e.difference} strong /> },
                { header: 'Pemilik', cell: (e) => e.owner?.name ?? '—' },
                {
                  header: 'Tindakan',
                  actions: true,
                  cell: (e) => access.canManage && e.status !== 'RESOLVED' && (
                    <div className="toolbar">
                      <Button size="sm" onClick={() => start('assign', e)}>Tetapkan</Button>
                      <Button size="sm" onClick={() => start('explain', e)}>Jelaskan</Button>
                      <Button size="sm" variant="primary" onClick={() => start('resolve', e)}>Selesaikan</Button>
                    </div>
                  ),
                },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>
      {dialogs}
    </>
  )
}
