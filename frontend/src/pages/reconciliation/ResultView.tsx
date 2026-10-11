import { Banner, Stat } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { Money } from '../operational/shared'
import { ReconBadge } from './components'
import { ComponentsTable } from './ComponentsTable'
import { typeLabels } from './labels'
import type { ReconResult } from './types'

/** One reconciliation as the server computed it: verdict, headline figures, components and the server's own notes. */
export function ResultView({ result }: { result: ReconResult }) {
  const name = typeLabels[result.type] ?? result.type
  return (
    <>
      <div className="card-body stack">
        {!result.complete && (
          <Banner tone="warn">
            Perbandingan ini tidak lengkap: cakupan data Anda lebih sempit dari seluruh organisasi, atau akun kontrol belum dapat ditentukan. Perbandingan atas sebagian buku besar tidak membuktikan kecocokan, sehingga angkanya tidak ditampilkan.
          </Banner>
        )}
        <Verdict result={result} name={name} />
        <div className="grid grid-4">
          <Stat label="Saldo sumber" value={<Money value={result.source_balance} />} hint="Menurut sub-buku, register, atau dokumen" />
          <Stat label="Saldo buku besar" value={<Money value={result.gl_balance} />} hint="Hanya jurnal terposting, pada tanggal yang sama" />
          <Stat label="Selisih" value={<Money value={result.difference} strong />} hint="Sumber dikurangi buku besar" />
          <Stat label="Status" value={<ReconBadge status={result.status} />} hint={<>Per {formatDate(result.as_of)} · toleransi <Money value={result.tolerance} /></>} />
        </div>
      </div>

      <div className="card-head"><h2>Komponen</h2></div>
      <ComponentsTable type={result.type} components={result.components} />

      {result.notes.length > 0 && (
        <div className="card-body">
          <h3 style={{ margin: '0 0 6px', fontSize: '0.95rem' }}>Catatan dari server</h3>
          <ul className="stack muted" style={{ margin: 0, paddingLeft: 18, gap: 6 }}>
            {result.notes.map((note) => <li key={note}>{note}</li>)}
          </ul>
        </div>
      )}
    </>
  )
}

function Verdict({ result, name }: { result: ReconResult; name: string }) {
  const when = formatDate(result.as_of)
  switch (result.status) {
    case 'MATCHED':
      return <Banner tone="ok"><strong>Cocok.</strong> Per {when}, {name.toLowerCase()}: selisih <Money value={result.difference} strong /> berada dalam toleransi <Money value={result.tolerance} />.</Banner>
    case 'MISMATCH':
      return <Banner tone="bad"><strong>Selisih.</strong> Per {when}, {name.toLowerCase()} berbeda sebesar <Money value={result.difference} strong />. Tidak ada penyesuaian otomatis; periksa komponen berstatus Selisih di bawah.</Banner>
    case 'INCOMPLETE':
      return <Banner tone="warn"><strong>Tidak lengkap.</strong> Per {when}, perbandingan belum dapat dibuat penuh sehingga belum membuktikan kecocokan. Lihat catatan di bawah.</Banner>
    default:
      return <Banner tone="info"><strong>Tidak berlaku.</strong> Per {when}, belum ada data yang perlu direkonsiliasi untuk jenis ini.</Banner>
  }
}
