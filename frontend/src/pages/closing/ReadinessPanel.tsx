import { DataTable } from '../../components/DataTable'
import { Banner, EmptyState, ErrorNotice, Loading } from '../../components/ui'
import { formatDateTime } from '../../lib/format'
import type { Resource } from '../../lib/hooks'
import { CheckDetails } from './CheckDetails'
import { CheckBadge, ReadinessBadge, SeverityBadge } from './components'
import { checkHints, closingError } from './labels'
import type { Readiness } from './types'

/** Every check of the readiness as the server just computed it: result, whether it blocks, and the evidence behind it. */
export function ReadinessPanel({ readiness }: { readiness: Resource<Readiness> }) {
  const data = readiness.data
  if (readiness.error) return <ErrorNotice error={closingError(readiness.error)} onRetry={readiness.reload} />
  if (!data) return <Loading />
  if (data.checks.length === 0) return <EmptyState title="Belum ada pemeriksaan">Server tidak mengirim pemeriksaan untuk periode ini.</EmptyState>

  return (
    <>
      <div className="card-body stack">
        <div className="toolbar">
          <ReadinessBadge result={data.result} />
          <span>{data.blocking_count} penghalang · {data.warning_count} peringatan</span>
          <span className="muted">Dihitung {formatDateTime(data.evaluated_at)}</span>
        </div>
        {data.result === 'NOT_READY'
          ? <Banner tone="bad"><strong>Belum siap ditutup.</strong> Pemeriksaan bertanda Gagal dan Menghalangi harus lolos sebelum tutup periode dapat diterima server. Peringatan tidak menghalangi.</Banner>
          : <Banner tone="ok"><strong>Siap ditutup.</strong> Tidak ada pemeriksaan penghalang yang gagal pada saat dihitung. Server menghitung ulang saat periode ditutup.</Banner>}
      </div>
      <DataTable
        caption="Pemeriksaan kesiapan penutupan periode"
        rows={data.checks}
        rowKey={(c) => c.code}
        columns={[
          { header: 'Pemeriksaan', primary: true, cell: (c) => <><strong>{c.title}</strong> <span className="muted">({c.code})</span><div className="muted preview-note">{checkHints[c.code]}</div></> },
          { header: 'Sifat', cell: (c) => <SeverityBadge severity={c.severity} /> },
          { header: 'Hasil', cell: (c) => <CheckBadge result={c.result} /> },
          { header: 'Rincian', cell: (c) => <><div>{c.message}</div><CheckDetails check={c} /></> },
        ]}
      />
    </>
  )
}
