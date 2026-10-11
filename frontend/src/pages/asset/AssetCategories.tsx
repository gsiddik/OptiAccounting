import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { usePagedList } from '../operational/expense/usePagedList'
import { CategoryDeleteDialog, CategoryForm, CategoryStatusDialog } from './CategoryDialogs'
import { categoryDefaultsText, residualPolicyText } from './labels'
import type { AssetCategory } from './types'

const INITIAL = { q: '', status: '' }

export default function AssetCategories() {
  const access = useModuleAccess(MODULES.fixedAsset)
  const toast = useToast()
  const manage = access.canChange('accounting.asset_category.manage')
  const { filter, change, setPage, list } = usePagedList<AssetCategory, typeof INITIAL>(`${API}/asset-categories`, INITIAL)
  const [editing, setEditing] = useState<AssetCategory | 'new' | null>(null)
  const [toggling, setToggling] = useState<AssetCategory | null>(null)
  const [deleting, setDeleting] = useState<AssetCategory | null>(null)

  const done = (message: string) => () => {
    setEditing(null)
    setToggling(null)
    setDeleting(null)
    list.reload()
    toast.success(message)
  }

  return (
    <>
      <PageHeader
        title="Kategori aset"
        description="Pengelompokan aset tetap beserta nilai awal penyusutannya (metode, umur manfaat, nilai sisa). Kategori hanya memberi nilai awal: aset yang sudah dikapitalisasi tidak berubah. Kategori yang sudah dipakai dinonaktifkan, tidak dihapus."
        actions={manage && <Button variant="primary" onClick={() => setEditing('new')}>Kategori baru</Button>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari kode atau nama" aria-label="Cari kategori" value={filter.q} onChange={(e) => change('q', e.target.value)} />
            <select className="select" aria-label="Status kategori" value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
              <option value="ACTIVE">Aktif</option>
              <option value="INACTIVE">Nonaktif</option>
            </select>
          </Filters>
        </div>

        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={list.error} onRetry={list.reload} />
        ) : list.data.data.length === 0 ? (
          <EmptyState title="Belum ada kategori aset">{manage ? 'Buat kategori pertama, atau ubah filter pencarian.' : 'Ubah filter pencarian.'}</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Kategori aset"
              rows={list.data.data}
              rowKey={(c) => c.id}
              scroll
              columns={[
                { header: 'Kode', primary: true, cell: (c) => <span className="mono">{c.code}</span> },
                { header: 'Nama', cell: (c) => <>{c.name}{c.description && <div className="muted">{c.description}</div>}</> },
                { header: 'Penyusutan default', cell: (c) => <>{categoryDefaultsText(c)}<div className="muted">{residualPolicyText(c)}</div></> },
                { header: 'Akun', cell: (c) => <Accounts category={c} /> },
                { header: 'Status', cell: (c) => <StatusBadge status={c.status} /> },
                { header: 'Aksi', actions: true, cell: (c) => manage && (
                  <div className="actions">
                    <Button size="sm" onClick={() => setEditing(c)}>Ubah</Button>
                    <Button size="sm" onClick={() => setToggling(c)}>{c.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
                    <Button size="sm" variant="danger" onClick={() => setDeleting(c)}>Hapus</Button>
                  </div>
                ) },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>

      {editing && <CategoryForm category={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={done('Kategori disimpan.')} />}
      {toggling && <CategoryStatusDialog category={toggling} onClose={() => setToggling(null)} onDone={done('Status kategori diperbarui.')} />}
      {deleting && <CategoryDeleteDialog category={deleting} onClose={() => setDeleting(null)} onDone={done('Kategori dihapus.')} />}
    </>
  )
}

/** The accounts a category overrides the role mapping with; nothing named means every account comes from the role mapping. */
function Accounts({ category: c }: { category: AssetCategory }) {
  const rows = [
    ['Aset', c.asset_account],
    ['Akumulasi', c.accumulated_account],
    ['Beban', c.expense_account],
    ['Laba/rugi', c.gain_loss_account],
  ] as const
  const named = rows.filter(([, account]) => account)
  if (named.length === 0) return <span className="muted">Mengikuti pemetaan peran akun</span>
  return (
    <>
      {named.map(([label, account]) => <div key={label}><span className="muted">{label}: </span><span className="mono">{account!.code}</span> · {account!.name}</div>)}
    </>
  )
}
