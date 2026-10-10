import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API } from '../../lib/operational'
import { Money } from '../operational/shared'
import type { Asset, Schedule } from './types'

const PAGE_SIZE = 24

/**
 * The depreciation schedule of an asset, exactly as `GET assets/{id}/schedule` returns it: the stored rows of a capitalized asset, or the server's
 * preview of a draft's terms. Every amount, running total and book value comes from the API; this card only pages and formats them.
 */
export function ScheduleCard({ asset }: { asset: Asset }) {
  const [page, setPage] = useState(1)
  const schedule = useResource(async () => (await api.get<Schedule>(`${API}/assets/${asset.id}/schedule`)).data, [asset.id, asset.status])
  const summary = asset.schedule_summary

  return (
    <Card title="Jadwal penyusutan" flush>
      {summary && summary.rows > 0 && (
        <div className="card-body">
          <p className="muted" role="status">
            {summary.rows} bulan: {summary.posted} diposting, {summary.in_run} dalam proses draf, {summary.planned} terjadwal
            {summary.cancelled > 0 ? `, ${summary.cancelled} dibatalkan` : ''}.
            {summary.next_period ? ` Bulan berikutnya mulai ${formatDate(summary.next_period)}.` : ''}
          </p>
        </div>
      )}
      {schedule.loading && !schedule.data ? (
        <Loading />
      ) : schedule.error || !schedule.data ? (
        <ErrorNotice error={schedule.error} onRetry={schedule.reload} />
      ) : (
        <>
          {schedule.data.preview && (
            <div className="card-body">
              <Banner tone="info">Ini pratinjau yang dihitung server dari ketentuan draf. Jadwal resmi dibuat dan dikunci saat aset dikapitalisasi.</Banner>
            </div>
          )}
          {schedule.data.rows.length === 0 ? (
            <EmptyState title="Tidak ada jadwal penyusutan">{asset.method === 'NONE' ? 'Aset ini tidak disusutkan (misalnya tanah).' : 'Jadwal kosong untuk ketentuan aset ini.'}</EmptyState>
          ) : (
            <>
              <DataTable
                caption="Jadwal penyusutan"
                rows={schedule.data.rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)}
                rowKey={(r) => String(r.sequence_no)}
                scroll
                columns={[
                  { header: 'Bulan ke', primary: true, cell: (r) => r.sequence_no },
                  { header: 'Periode', cell: (r) => `${formatDate(r.period_start)} – ${formatDate(r.period_end)}` },
                  { header: 'Penyusutan', align: 'right', cell: (r) => <Money value={r.amount} /> },
                  { header: 'Akumulasi', align: 'right', cell: (r) => <Money value={r.accumulated_after} /> },
                  { header: 'Nilai buku', align: 'right', cell: (r) => <Money value={r.book_value_after} /> },
                  { header: 'Status', cell: (r) => (r.status ? <StatusBadge status={r.status} /> : <span className="muted">—</span>) },
                ]}
              />
              <Pagination page={page} lastPage={Math.ceil(schedule.data.rows.length / PAGE_SIZE)} total={schedule.data.rows.length} onPage={setPage} />
            </>
          )}
        </>
      )}
    </Card>
  )
}
