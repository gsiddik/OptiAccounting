import { Banner, Button, Card, EmptyState } from '../../components/ui'
import { formatDate, formatDateTime } from '../../lib/format'
import { Money } from '../operational/shared'
import { ExceptionBadge, ReconBadge } from './components'
import type { ExceptionAction } from './ExceptionDialogs'
import type { ReconException } from './types'

type Props = {
  exceptions: ReconException[]
  canManage: boolean
  canRun: boolean
  /** The status of the live computation shown on the page. */
  calculated: string
  onAction: (action: ExceptionAction, exception: ReconException) => void
}

/**
 * The work item of the difference at this cut-off: owner, explanation and resolution. It is the human side only. The calculated status and every figure
 * come from a recomputation, never from what a person wrote here.
 */
export function ExceptionPanel({ exceptions, canManage, canRun, calculated, onAction }: Props) {
  const live = exceptions.find((e) => e.status !== 'RESOLVED') ?? null
  const history = exceptions.filter((e) => e.status === 'RESOLVED')

  return (
    <Card title="Pengecualian">
      <div className="stack">
        <Banner tone="info">
          Menjelaskan selisih tidak mengubah status hitung: status dan angka selalu berasal dari hitung ulang buku besar. Pengecualian hanya dapat diselesaikan oleh hitung ulang yang hasilnya Cocok.
        </Banner>
        {live ? <Live exception={live} canManage={canManage} onAction={onAction} /> : (
          <EmptyState title="Tidak ada pengecualian aktif">
            {calculated === 'MISMATCH' && canRun ? 'Jalankan & simpan untuk membuka pengecualian atas selisih ini.' : 'Tidak ada selisih yang perlu ditindaklanjuti pada tanggal ini.'}
          </EmptyState>
        )}
        {history.length > 0 && (
          <div>
            <h3 style={{ margin: '0 0 6px', fontSize: '0.95rem' }}>Riwayat pengecualian selesai</h3>
            <ul className="stack" style={{ margin: 0, paddingLeft: 18, gap: 6 }}>
              {history.map((e) => (
                <li key={e.id}>
                  Selesai {formatDateTime(e.resolved_at)}{e.resolution_note ? `: ${e.resolution_note}` : ''}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Card>
  )
}

function Live({ exception: e, canManage, onAction }: { exception: ReconException; canManage: boolean; onAction: Props['onAction'] }) {
  return (
    <>
      <dl className="facts">
        <div><dt>Status penanganan</dt><dd><ExceptionBadge status={e.status} /></dd></div>
        <div><dt>Status hitung terakhir</dt><dd><ReconBadge status={e.calculated_status} /></dd></div>
        <div><dt>Pemilik</dt><dd>{e.owner?.name ?? 'Belum ditetapkan'}</dd></div>
        <div><dt>Selisih (hitung terakhir)</dt><dd><Money value={e.difference} strong /></dd></div>
        <div><dt>Saldo sumber</dt><dd><Money value={e.source_balance} /></dd></div>
        <div><dt>Saldo buku besar</dt><dd><Money value={e.gl_balance} /></dd></div>
        <div><dt>Per tanggal</dt><dd>{formatDate(e.as_of_date)}</dd></div>
        <div><dt>Diperbarui</dt><dd>{formatDateTime(e.updated_at)}</dd></div>
        <div className="wide"><dt>Penjelasan{e.explained_at ? ` (${formatDateTime(e.explained_at)})` : ''}</dt><dd>{e.notes ?? '—'}</dd></div>
      </dl>
      {canManage && (
        <div className="toolbar">
          <Button onClick={() => onAction('assign', e)}>Tetapkan pemilik</Button>
          <Button onClick={() => onAction('explain', e)}>Jelaskan</Button>
          <Button variant="primary" onClick={() => onAction('resolve', e)}>Selesaikan</Button>
        </div>
      )}
    </>
  )
}
