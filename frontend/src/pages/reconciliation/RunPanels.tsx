import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState } from '../../components/ui'
import { formatDate, formatDateTime } from '../../lib/format'
import { Money } from '../operational/shared'
import { ExceptionBadge, ReconBadge } from './components'
import { purposeLabels } from './labels'
import type { ReconException, ReconRun } from './types'

/** The evidence a "Jalankan & simpan" just kept, and what it did to the exception of the cut-off. */
export function StoredRun({ run, exception }: { run: ReconRun; exception: ReconException | null }) {
  return (
    <Card title="Hasil jalankan tersimpan">
      <div className="stack">
        <dl className="facts">
          <div><dt>Dijalankan</dt><dd>{formatDateTime(run.run_at)}</dd></div>
          <div><dt>Per tanggal</dt><dd>{formatDate(run.as_of_date)}{run.period ? ` · periode ${run.period.code}` : ''}</dd></div>
          <div><dt>Status</dt><dd><ReconBadge status={run.status} /></dd></div>
          <div><dt>Saldo sumber</dt><dd><Money value={run.source_balance} /></dd></div>
          <div><dt>Saldo buku besar</dt><dd><Money value={run.gl_balance} /></dd></div>
          <div><dt>Selisih</dt><dd><Money value={run.difference} strong /></dd></div>
          <div><dt>Tujuan</dt><dd>{purposeLabels[run.purpose] ?? run.purpose}</dd></div>
          <div><dt>ID hasil</dt><dd className="mono">{run.id}</dd></div>
        </dl>
        {exception === null ? (
          <Banner tone="info">Tidak ada pengecualian aktif untuk jenis dan tanggal ini.</Banner>
        ) : exception.status === 'RESOLVED' ? (
          <Banner tone="ok">Hitung ulang menunjukkan Cocok, sehingga pengecualian yang terbuka diselesaikan otomatis.</Banner>
        ) : (
          <Banner tone="info">
            Pengecualian dibuka atau diperbarui dengan angka terbaru: <ExceptionBadge status={exception.status} /> · selisih <Money value={exception.difference} strong />.
          </Banner>
        )}
      </div>
    </Card>
  )
}

/** The latest kept runs of the type: evidence of what was seen, newest first. */
export function RunsTable({ runs }: { runs: ReconRun[] }) {
  return (
    <Card title="Jalankan terakhir" flush>
      {runs.length === 0 ? (
        <EmptyState title="Belum ada hasil tersimpan">Gunakan Jalankan &amp; simpan untuk menyimpan bukti rekonsiliasi jenis ini.</EmptyState>
      ) : (
        <DataTable
          caption="Hasil rekonsiliasi tersimpan"
          rows={runs}
          rowKey={(r) => r.id}
          scroll
          columns={[
            { header: 'Dijalankan', primary: true, cell: (r) => formatDateTime(r.run_at) },
            { header: 'Per tanggal', cell: (r) => formatDate(r.as_of_date) },
            { header: 'Status', cell: (r) => <ReconBadge status={r.status} /> },
            { header: 'Saldo sumber', align: 'right', cell: (r) => <Money value={r.source_balance} /> },
            { header: 'Saldo buku besar', align: 'right', cell: (r) => <Money value={r.gl_balance} /> },
            { header: 'Selisih', align: 'right', cell: (r) => <Money value={r.difference} strong={r.status === 'MISMATCH'} /> },
            { header: 'Tujuan', cell: (r) => purposeLabels[r.purpose] ?? r.purpose },
          ]}
        />
      )}
    </Card>
  )
}
