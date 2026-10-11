import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { statusLabel } from '../../lib/labels'
import { API, DOC_STATUSES, MODULES, useModuleAccess } from '../../lib/operational'
import { disposalTypeLabels } from '../../lib/oa4Labels'
import { useDimensions } from '../accounting/data'
import { usePagedList } from '../operational/expense/usePagedList'
import { Filters, Money, ReadOnlyNotice } from '../operational/shared'
import { PATHS } from './data'
import { isZeroAmount } from './labels'
import { DISPOSAL_TYPES, type Disposal } from './types'

const INITIAL = { q: '', status: '', disposal_type: '', branch_id: '', posting_from: '', posting_to: '', mine: false }

export default function AssetDisposals() {
  const navigate = useNavigate()
  const access = useModuleAccess(MODULES.fixedAsset)
  const create = access.canChange('accounting.asset.dispose')
  const { filter, change, setPage, list } = usePagedList<Disposal, typeof INITIAL>(`${API}/asset-disposals`, INITIAL)
  const { catalog } = useDimensions()

  const date = (name: 'posting_from' | 'posting_to', short: string, label: string) => (
    <label className="inline-field">{short} <input className="input" type="date" aria-label={label} value={filter[name]} onChange={(e) => change(name, e.target.value)} /></label>
  )

  return (
    <>
      <PageHeader
        title="Pelepasan aset"
        description="Penjualan atau penghapusan aset tetap. Pelepasan melewati persetujuan, dan laba atau rugi dihitung server dari nilai buku saat diposting; aset tetap berada di register dengan riwayatnya."
        actions={create && <Button variant="primary" onClick={() => navigate(`${PATHS.disposals}/baru`)}>Pelepasan baru</Button>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari nomor, alasan, referensi" aria-label="Cari pelepasan" value={filter.q} onChange={(e) => change('q', e.target.value)} />
            <select className="select" aria-label="Status pelepasan" value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
              {DOC_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            <select className="select" aria-label="Jenis pelepasan" value={filter.disposal_type} onChange={(e) => change('disposal_type', e.target.value)}>
              <option value="">Semua jenis</option>
              {DISPOSAL_TYPES.map((t) => <option key={t} value={t}>{disposalTypeLabels[t]}</option>)}
            </select>
            {catalog.branches.length > 0 && (
              <select className="select" aria-label="Cabang" value={filter.branch_id} onChange={(e) => change('branch_id', e.target.value)}>
                <option value="">Semua cabang</option>
                {catalog.branches.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
              </select>
            )}
            {date('posting_from', 'Posting dari', 'Tanggal posting dari')}
            {date('posting_to', 'sampai', 'Tanggal posting sampai')}
            <label className="check"><input type="checkbox" checked={filter.mine} onChange={(e) => change('mine', e.target.checked)} /> Buatan saya</label>
          </Filters>
        </div>

        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={list.error} onRetry={list.reload} />
        ) : list.data.data.length === 0 ? (
          <EmptyState title="Belum ada pelepasan aset">{create ? 'Buat pelepasan pertama, atau ubah filter.' : 'Ubah filter pencarian.'}</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar pelepasan aset"
              rows={list.data.data}
              rowKey={(d) => d.id}
              scroll
              columns={[
                { header: 'Nomor', primary: true, cell: (d) => <Link to={`${PATHS.disposals}/${d.id}`} className="mono">{d.document_number ?? 'Draf'}</Link> },
                { header: 'Aset', cell: (d) => (d.asset ? `${d.asset.asset_number ?? 'Draf'} · ${d.asset.name}` : '—') },
                { header: 'Jenis', cell: (d) => disposalTypeLabels[d.disposal_type] ?? d.disposal_type },
                { header: 'Tanggal pelepasan', cell: (d) => formatDate(d.disposal_date) },
                { header: 'Hasil', align: 'right', cell: (d) => (isZeroAmount(d.proceeds_amount) ? <span className="muted">—</span> : <Money value={d.proceeds_amount} />) },
                { header: 'Laba/rugi', align: 'right', cell: (d) => <GainLoss disposal={d} /> },
                { header: 'Status', cell: (d) => <StatusBadge status={d.status} /> },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}

/** The result stored when the disposal posted. Before that the server only has an estimate (shown on the document), so the list shows a dash. */
function GainLoss({ disposal: d }: { disposal: Disposal }) {
  if (d.status !== 'POSTED' && d.status !== 'REVERSED') return <span className="muted">—</span>
  if (!isZeroAmount(d.gain_amount)) return <span>Laba <Money value={d.gain_amount} /></span>
  if (!isZeroAmount(d.loss_amount)) return <span>Rugi <Money value={d.loss_amount} /></span>
  return <span className="muted">Impas</span>
}
