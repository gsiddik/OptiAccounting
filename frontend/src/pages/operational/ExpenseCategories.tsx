import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useAction, useDebounced, useResource } from '../../lib/hooks'
import { useModuleAccess, type ExpenseCategory } from '../../lib/operational'
import { CategoryDeleteDialog, CategoryForm, CategoryStatusDialog } from './expense/CategoryDialogs'
import { localized } from './expense/errors'
import { ErrorNotice, Filters, ReadOnlyNotice } from './shared'

export default function ExpenseCategories() {
  const { canChange, readOnly } = useModuleAccess('ACCOUNTING_EXPENSE')
  const toast = useToast()
  const manage = canChange('accounting.expense_category.manage')
  const [f, setF] = useState({ q: '', status: '' })
  const q = useDebounced(f.q)
  const categories = useResource(async () => {
    const params: Record<string, string> = {}
    if (q) params.q = q
    if (f.status) params.status = f.status
    return (await api.get<{ data: ExpenseCategory[] }>('/app/accounting/expense-categories', { params })).data.data
  }, [q, f.status])
  const defaults = useAction()
  const [editing, setEditing] = useState<ExpenseCategory | 'new' | null>(null)
  const [toggling, setToggling] = useState<ExpenseCategory | null>(null)
  const [deleting, setDeleting] = useState<ExpenseCategory | null>(null)

  async function applyDefaults() {
    const r = await defaults.run(async () => (await api.post<{ created: string[] }>('/app/accounting/expense-categories/defaults')).data)
    if (r.ok) {
      toast.success(r.value.created.length > 0 ? `${r.value.created.length} kategori standar ditambahkan.` : 'Semua kategori standar sudah ada.')
      categories.reload()
    }
  }

  const done = (message: string) => () => {
    setEditing(null)
    setToggling(null)
    setDeleting(null)
    categories.reload()
    toast.success(message)
  }

  return (
    <>
      <PageHeader
        title="Kategori beban"
        description="Pengelompokan beban milik tenant. Kategori hanya menentukan ke mana biaya diklasifikasikan; kategori yang sudah dipakai dinonaktifkan, tidak dihapus."
        actions={manage && (
          <>
            <Button loading={defaults.busy} onClick={() => void applyDefaults()}>Terapkan kategori standar</Button>
            <Button variant="primary" onClick={() => setEditing('new')}>Kategori baru</Button>
          </>
        )}
      />
      <ReadOnlyNotice show={readOnly} />
      {defaults.error != null && <ErrorNotice error={localized(defaults.error)} />}
      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari kode atau nama" aria-label="Cari kategori" value={f.q} onChange={(e) => setF((s) => ({ ...s, q: e.target.value }))} />
            <select className="select" aria-label="Status kategori" value={f.status} onChange={(e) => setF((s) => ({ ...s, status: e.target.value }))}>
              <option value="">Semua status</option>
              <option value="ACTIVE">Aktif</option>
              <option value="INACTIVE">Nonaktif</option>
            </select>
          </Filters>
        </div>

        {categories.loading && !categories.data ? (
          <Loading />
        ) : categories.error || !categories.data ? (
          <ErrorNotice error={categories.error} onRetry={categories.reload} />
        ) : categories.data.length === 0 ? (
          <EmptyState title="Belum ada kategori beban">{manage ? 'Terapkan kategori standar atau buat kategori pertama.' : 'Ubah filter pencarian.'}</EmptyState>
        ) : (
          <DataTable
            caption="Kategori beban"
            rows={categories.data}
            rowKey={(c) => c.id}
            columns={[
              { header: 'Kode', primary: true, cell: (c) => <span className="mono">{c.code}</span> },
              { header: 'Nama', cell: (c) => <>{c.name}{c.description && <div className="muted">{c.description}</div>}</> },
              { header: 'Tujuan klasifikasi', cell: (c) => destination(c) },
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
        )}
      </Card>

      {editing && <CategoryForm category={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={done('Kategori disimpan.')} />}
      {toggling && <CategoryStatusDialog category={toggling} onClose={() => setToggling(null)} onDone={done('Status kategori diperbarui.')} />}
      {deleting && <CategoryDeleteDialog category={deleting} onClose={() => setDeleting(null)} onDone={done('Kategori dihapus.')} />}
    </>
  )
}

function destination(c: ExpenseCategory) {
  if (c.account) return <><span className="mono">{c.account.code}</span> · {c.account.name}</>
  if (c.account_role) return <>Peran <span className="mono">{c.account_role}</span></>
  return <span className="muted">Mengikuti aturan posting</span>
}
