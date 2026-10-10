import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { statusLabel } from '../../lib/labels'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { useDimensions } from '../accounting/data'
import { usePagedList } from '../operational/expense/usePagedList'
import { DimensionFilters, Filters, Money, ReadOnlyNotice } from '../operational/shared'
import { PATHS, useAssetCategories } from './data'
import { ASSET_STATUSES, type Asset } from './types'

const INITIAL = { q: '', status: '', asset_category_id: '', branch_id: '', business_unit_id: '', cost_center_id: '', capitalized_from: '', capitalized_to: '', mine: false }

export default function Assets() {
  const navigate = useNavigate()
  const access = useModuleAccess(MODULES.fixedAsset)
  const { filter, change, setPage, list } = usePagedList<Asset, typeof INITIAL>(`${API}/assets`, INITIAL)
  const { categories } = useAssetCategories()
  const { catalog } = useDimensions()
  const rows = list.data?.data ?? []
  // The list endpoint does not carry the net book value (only the detail does); the column appears when the API sends it, and is never computed here.
  const hasBookValue = rows.some((a) => a.net_book_value != null)

  const date = (name: 'capitalized_from' | 'capitalized_to', short: string, label: string) => (
    <label className="inline-field">{short} <input className="input" type="date" aria-label={label} value={filter[name]} onChange={(e) => change(name, e.target.value)} /></label>
  )

  return (
    <>
      <PageHeader
        title="Register aset tetap"
        description="Kartu aset tetap tenant. Register adalah sub-buku: buku besar tetap menjadi sumber kebenaran, dan jurnal kapitalisasi, penyusutan serta pelepasan selalu lewat mesin posting."
        actions={access.canChange('accounting.asset.manage') && <Button variant="primary" onClick={() => navigate(`${PATHS.assets}/baru`)}>Aset baru</Button>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari nomor, nama, referensi" aria-label="Cari aset" value={filter.q} onChange={(e) => change('q', e.target.value)} />
            <select className="select" aria-label="Status aset" value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
              {ASSET_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            {categories.length > 0 && (
              <select className="select" aria-label="Kategori aset" value={filter.asset_category_id} onChange={(e) => change('asset_category_id', e.target.value)}>
                <option value="">Semua kategori</option>
                {categories.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
              </select>
            )}
            <DimensionFilters catalog={catalog} value={filter} onChange={(patch) => Object.entries(patch).forEach(([k, v]) => change(k as 'branch_id' | 'business_unit_id' | 'cost_center_id', v))} />
            {date('capitalized_from', 'Dikapitalisasi dari', 'Tanggal kapitalisasi dari')}
            {date('capitalized_to', 'sampai', 'Tanggal kapitalisasi sampai')}
            <label className="check"><input type="checkbox" checked={filter.mine} onChange={(e) => change('mine', e.target.checked)} /> Buatan saya</label>
          </Filters>
        </div>

        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={list.error} onRetry={list.reload} />
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada aset">{access.canChange('accounting.asset.manage') ? 'Ubah filter atau daftarkan aset pertama.' : 'Ubah filter pencarian.'}</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar aset tetap"
              rows={rows}
              rowKey={(a) => a.id}
              scroll
              columns={[
                { header: 'Nomor', primary: true, cell: (a) => <Link to={`${PATHS.assets}/${a.id}`} className="mono">{a.asset_number ?? 'Draf'}</Link> },
                { header: 'Nama', cell: (a) => <>{a.name}{a.branch && <div className="muted">{a.branch.name}</div>}</> },
                { header: 'Kategori', cell: (a) => (a.category ? `${a.category.code} · ${a.category.name}` : '—') },
                { header: 'Kapitalisasi', cell: (a) => formatDate(a.capitalization_date) },
                { header: 'Harga perolehan', align: 'right', cell: (a) => <Money value={a.acquisition_cost} /> },
                { header: 'Akumulasi penyusutan', align: 'right', cell: (a) => <Money value={a.accumulated_depreciation} /> },
                ...(hasBookValue ? [{ header: 'Nilai buku', align: 'right' as const, cell: (a: Asset) => <Money value={a.net_book_value} strong /> }] : []),
                { header: 'Status', cell: (a) => <StatusBadge status={a.status} /> },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}
