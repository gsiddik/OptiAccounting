import { useState } from 'react'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { useBusinessDate } from '../operational/payables/lists'
import { ExportButton, Filters, Money, ReadOnlyNotice } from '../operational/shared'
import { CutoffFilter, ReconBadge } from './components'
import { ENDPOINTS, PATHS, cutoffParams, cutoffSearch, hasCutoff, useReconAccess, useReconPeriods } from './data'
import { LEGACY, reconError, typeHints, typeLabels } from './labels'
import type { Cutoff, ReconOverview, ReconResult } from './types'

export default function ReconciliationOverview() {
  const today = useBusinessDate()
  const access = useReconAccess()
  const { periods } = useReconPeriods()
  const [cutoff, setCutoff] = useState<Cutoff>({ as_of: today, period_id: '' })
  const overview = useResource(async () => (hasCutoff(cutoff) ? (await api.get<ReconOverview>(ENDPOINTS.overview, { params: cutoffParams(cutoff) })).data : null), [cutoff.as_of, cutoff.period_id])
  const data = overview.data

  return (
    <>
      <PageHeader
        title="Rekonsiliasi"
        description="Bukti bahwa sub-buku dan data kontrol sama dengan buku besar pada tanggal yang sama. Selisih ditampilkan apa adanya; tidak ada jurnal penyesuaian yang dibuat otomatis."
        actions={
          <>
            <Link className="btn" to={PATHS.exceptions}>Pengecualian</Link>
            {hasCutoff(cutoff) && <ExportButton path={ENDPOINTS.exportAll} params={cutoffParams(cutoff)} filename={`rekonsiliasi_${data?.as_of ?? cutoff.as_of ?? 'periode'}.csv`} />}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <CutoffFilter value={cutoff} onChange={setCutoff} periods={periods} />
          </Filters>
        </div>
        {overview.error ? <ErrorNotice error={reconError(overview.error)} onRetry={overview.reload} /> : data ? <Rows overview={data} cutoff={cutoff} /> : overview.loading ? <Loading /> : <EmptyState title="Pilih tanggal">Pilih tanggal atau periode untuk melihat rekonsiliasi.</EmptyState>}
      </Card>
    </>
  )
}

function Rows({ overview, cutoff }: { overview: ReconOverview; cutoff: Cutoff }) {
  const rows = overview.data
  const mismatches = rows.filter((r) => r.status === 'MISMATCH').length
  const allGood = rows.length > 0 && overview.complete && rows.every((r) => r.status === 'MATCHED' || r.status === 'NOT_APPLICABLE') && rows.some((r) => r.status === 'MATCHED')
  const search = cutoffSearch({ as_of: cutoff.period_id ? '' : overview.as_of, period_id: cutoff.period_id })

  if (rows.length === 0) {
    return <EmptyState title="Belum ada rekonsiliasi">Rekonsiliasi muncul sesuai modul yang aktif di langganan organisasi (utang, piutang, kas/bank, aset tetap, pajak, multi-mata uang).</EmptyState>
  }

  return (
    <>
      <div className="card-body stack">
        {!overview.complete && (
          <Banner tone="warn">
            Sebagian rekonsiliasi tidak lengkap: cakupan data Anda lebih sempit dari seluruh organisasi (cabang atau unit bisnis tertentu), atau akun kontrol belum dapat ditentukan.
            Perbandingan atas sebagian buku besar tidak membuktikan kecocokan, sehingga angkanya tidak ditampilkan.
          </Banner>
        )}
        {mismatches > 0 && <Banner tone="bad"><strong>{mismatches} rekonsiliasi berselisih</strong> per {formatDate(overview.as_of)}. Buka rinciannya untuk melihat komponen yang berbeda.</Banner>}
        {allGood && <Banner tone="ok"><strong>Cocok.</strong> Semua rekonsiliasi yang berlaku sama dengan buku besar per {formatDate(overview.as_of)}.</Banner>}
      </div>
      <DataTable
        caption="Ringkasan rekonsiliasi per jenis"
        rows={rows}
        rowKey={(r) => r.type}
        scroll
        columns={[
          { header: 'Jenis', primary: true, cell: (r) => <><Link to={`${PATHS.detail(r.type)}${search}`}>{typeLabels[r.type] ?? r.type}</Link><div className="muted">{typeHints[r.type]}</div></> },
          { header: 'Status', cell: (r) => <ReconBadge status={r.status} /> },
          { header: 'Saldo sumber', align: 'right', cell: (r) => <Money value={r.source_balance} /> },
          { header: 'Saldo buku besar', align: 'right', cell: (r) => <Money value={r.gl_balance} /> },
          { header: 'Selisih', align: 'right', cell: (r) => <Money value={r.difference} strong={r.status === 'MISMATCH'} /> },
          { header: 'Tindakan', actions: true, cell: (r) => <Actions row={r} search={search} /> },
        ]}
      />
      <p className="card-body muted preview-note">
        Dihitung ulang dari buku besar setiap kali halaman dibuka; hasilnya tidak disimpan. Gunakan <strong>Jalankan &amp; simpan</strong> di halaman rincian untuk menyimpan bukti dan membuka pengecualian.
        Status berasal dari selisih hasil hitung; penjelasan atau penugasan tidak mengubahnya.
      </p>
    </>
  )
}

function Actions({ row, search }: { row: ReconResult; search: string }) {
  const { can } = useCapabilities()
  const legacy = LEGACY[row.type]
  return (
    <div className="toolbar">
      <Link className="btn btn-sm" to={`${PATHS.detail(row.type)}${search}`} aria-label={`Rincian ${typeLabels[row.type]}`}>Rincian</Link>
      {legacy && can(legacy.permission) && <Link className="btn btn-sm" to={legacy.path} aria-label={`Halaman khusus ${typeLabels[row.type]}`}>Halaman khusus</Link>}
    </div>
  )
}
