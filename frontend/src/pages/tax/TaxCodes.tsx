import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useDebounced, useResource } from '../../lib/hooks'
import { API, MODULES, listParams, useModuleAccess, type Page } from '../../lib/operational'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { methodShort, taxTypeLabels, treatmentShort } from './labels'
import { DestinationText, RecoverableText } from './parts'
import { TaxCodeForm, TaxCodeStatusDialog } from './TaxDialogs'
import { TAX_TYPES, type TaxCode } from './types'

const EMPTY = { q: '', tax_type: '', status: '' }

export default function TaxCodes() {
  const navigate = useNavigate()
  const toast = useToast()
  const access = useModuleAccess(MODULES.tax)
  const manage = access.canChange('accounting.tax.manage')
  const [f, setF] = useState(EMPTY)
  const [page, setPage] = useState(1)
  const q = useDebounced(f.q)
  const filter = { ...f, q }
  const codes = useResource(async () => (await api.get<Page<TaxCode>>(`${API}/tax-codes`, { params: listParams(filter, page) })).data, [page, JSON.stringify(filter)])
  const [editing, setEditing] = useState<TaxCode | 'new' | null>(null)
  const [toggling, setToggling] = useState<TaxCode | null>(null)

  const change = (patch: Partial<typeof EMPTY>) => {
    setF((s) => ({ ...s, ...patch }))
    setPage(1)
  }
  const done = (message: string) => () => {
    setEditing(null)
    setToggling(null)
    codes.reload()
    toast.success(message)
  }

  return (
    <>
      <PageHeader
        title="Kode pajak"
        description="Kode pajak milik organisasi beserta tarif yang berlaku menurut tanggal. Tarif berubah dengan menambah tarif baru, bukan menimpa; transaksi yang sudah diposting menyimpan tarif yang dipakainya. Kode yang sudah dipakai dinonaktifkan, tidak dihapus; kode yang belum pernah dipakai dapat dihapus dari halaman rinciannya."
        actions={manage && <Button variant="primary" onClick={() => setEditing('new')}>Kode pajak baru</Button>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari kode atau nama" aria-label="Cari kode pajak" value={f.q} onChange={(e) => change({ q: e.target.value })} />
            <select className="select" aria-label="Jenis pajak" value={f.tax_type} onChange={(e) => change({ tax_type: e.target.value })}>
              <option value="">Semua jenis</option>
              {TAX_TYPES.map((t) => <option key={t} value={t}>{taxTypeLabels[t]}</option>)}
            </select>
            <select className="select" aria-label="Status kode pajak" value={f.status} onChange={(e) => change({ status: e.target.value })}>
              <option value="">Semua status</option>
              <option value="ACTIVE">Aktif</option>
              <option value="INACTIVE">Nonaktif</option>
            </select>
          </Filters>
        </div>

        {codes.loading && !codes.data ? (
          <Loading />
        ) : codes.error || !codes.data ? (
          <ErrorNotice error={codes.error} onRetry={codes.reload} />
        ) : codes.data.data.length === 0 ? (
          <EmptyState title="Belum ada kode pajak">{manage ? 'Ubah filter atau buat kode pajak pertama.' : 'Ubah filter pencarian.'}</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar kode pajak"
              rows={codes.data.data}
              rowKey={(c) => c.id}
              scroll
              columns={[
                { header: 'Kode', primary: true, cell: (c) => <Link to={`/app/akuntansi/kode-pajak/${c.id}`} className="mono">{c.code}</Link> },
                { header: 'Nama', cell: (c) => <>{c.name}{c.description && <div className="muted">{c.description}</div>}</> },
                { header: 'Jenis', cell: (c) => taxTypeLabels[c.tax_type] ?? c.tax_type },
                { header: 'Perhitungan', cell: (c) => <>{methodShort[c.calculation_method] ?? c.calculation_method} · {treatmentShort[c.treatment] ?? c.treatment}</> },
                { header: 'Dikreditkan', cell: (c) => <RecoverableText code={c} /> },
                { header: 'Tujuan akun', cell: (c) => <DestinationText code={c} /> },
                { header: 'Status', cell: (c) => <StatusBadge status={c.status} /> },
                { header: 'Aksi', actions: true, cell: (c) => (
                  <div className="actions">
                    <Button size="sm" onClick={() => navigate(`/app/akuntansi/kode-pajak/${c.id}`)}>Buka</Button>
                    {manage && <Button size="sm" onClick={() => setEditing(c)}>Ubah</Button>}
                    {manage && <Button size="sm" onClick={() => setToggling(c)}>{c.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>}
                  </div>
                ) },
              ]}
            />
            <Pagination page={codes.data.current_page} lastPage={codes.data.last_page} total={codes.data.total} onPage={setPage} />
          </>
        )}
      </Card>

      {editing && (
        <TaxCodeForm
          code={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onDone={(saved) => {
            if (editing === 'new') {
              toast.success('Kode pajak dibuat.')
              navigate(`/app/akuntansi/kode-pajak/${saved.id}`)
            } else done('Kode pajak disimpan.')()
          }}
        />
      )}
      {toggling && <TaxCodeStatusDialog code={toggling} onClose={() => setToggling(null)} onDone={done('Status kode pajak diperbarui.')} />}
    </>
  )
}
