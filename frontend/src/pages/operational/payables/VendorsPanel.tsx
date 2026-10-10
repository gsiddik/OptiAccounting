import { useState } from 'react'
import { DataTable } from '../../../components/DataTable'
import { ConfirmDialog } from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { Badge, Button, EmptyState, ErrorNotice, Loading, Pagination, StatusBadge } from '../../../components/ui'
import { api } from '../../../lib/api'
import { useDebounced, useResource } from '../../../lib/hooks'
import { API, usePaymentTerms, type Page, type Vendor } from '../../../lib/operational'
import { ExportButton, Filters } from '../shared'
import { exportQuery, listQuery } from './lists'
import { useAct } from './messages'
import { VendorForm } from './VendorForm'

/** The "Vendor" tab: server-side search / status / term filters, pagination, create / edit, activate / deactivate and delete. */
export function VendorsPanel({ manage }: { manage: boolean }) {
  const toast = useToast()
  const { terms } = usePaymentTerms()
  const [f, setF] = useState({ q: '', status: '', payment_term_id: '' })
  const [page, setPage] = useState(1)
  const q = useDebounced(f.q)
  const filter = { q, status: f.status, payment_term_id: f.payment_term_id }
  const vendors = useResource(async () => (await api.get<Page<Vendor>>(`${API}/vendors`, { params: listQuery(filter, page) })).data, [page, q, f.status, f.payment_term_id])
  const [editing, setEditing] = useState<Vendor | 'new' | null>(null)
  const [toggling, setToggling] = useState<Vendor | null>(null)
  const [deleting, setDeleting] = useState<Vendor | null>(null)

  const change = (patch: Partial<typeof f>) => {
    setF((s) => ({ ...s, ...patch }))
    setPage(1)
  }
  const refresh = () => vendors.reload()

  return (
    <>
      <div className="card-body">
        <Filters>
          <input className="input" type="search" aria-label="Cari vendor" placeholder="Cari kode, nama, atau NPWP" value={f.q} onChange={(e) => change({ q: e.target.value })} />
          <select className="select" aria-label="Status vendor" value={f.status} onChange={(e) => change({ status: e.target.value })}>
            <option value="">Semua status</option>
            <option value="ACTIVE">Aktif</option>
            <option value="INACTIVE">Nonaktif</option>
          </select>
          <select className="select" aria-label="Termin pembayaran" value={f.payment_term_id} onChange={(e) => change({ payment_term_id: e.target.value })}>
            <option value="">Semua termin</option>
            {terms.map((t) => <option key={t.id} value={t.id}>{t.code} · {t.name}</option>)}
          </select>
          <span className="spacer" />
          <ExportButton path={`${API}/vendors/export`} params={exportQuery(filter)} filename="vendor.csv" />
          {manage && <Button variant="primary" onClick={() => setEditing('new')}>Vendor baru</Button>}
        </Filters>
      </div>

      {vendors.loading && !vendors.data ? (
        <Loading />
      ) : vendors.error || !vendors.data ? (
        <ErrorNotice error={vendors.error} onRetry={vendors.reload} />
      ) : vendors.data.data.length === 0 ? (
        <EmptyState title="Tidak ada vendor">{manage ? 'Ubah filter atau tambahkan vendor baru.' : 'Ubah filter pencarian.'}</EmptyState>
      ) : (
        <>
          <DataTable
            caption="Daftar vendor"
            rows={vendors.data.data}
            rowKey={(v) => v.id}
            columns={[
              { header: 'Kode', primary: true, cell: (v) => <span className="mono">{v.code}</span> },
              { header: 'Nama', cell: (v) => <>{v.name}{v.legal_name && <div className="muted">{v.legal_name}</div>}{v.tax_id && <div className="muted">NPWP {v.tax_id}</div>}</> },
              { header: 'Kontak', cell: (v) => (v.contact_name || v.email || v.phone ? <>{v.contact_name}{v.email && <div className="muted">{v.email}</div>}{v.phone && <div className="muted">{v.phone}</div>}</> : <span className="muted">—</span>) },
              { header: 'Termin', cell: (v) => v.payment_term?.name ?? <span className="muted">—</span> },
              { header: 'Akun utang', cell: (v) => (v.payable_account ? <span className="mono">{v.payable_account.code}</span> : <span className="muted">Standar</span>) },
              { header: 'Pajak', cell: (v) => (v.tax_registered ? <Badge tone="info">PKP</Badge> : <span className="muted">—</span>) },
              { header: 'Status', cell: (v) => <StatusBadge status={v.status} /> },
              {
                header: 'Aksi',
                actions: true,
                cell: (v) => manage && (
                  <div className="actions">
                    <Button size="sm" aria-label={`Ubah vendor ${v.code}`} onClick={() => setEditing(v)}>Ubah</Button>
                    <Button size="sm" aria-label={`${v.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'} vendor ${v.code}`} onClick={() => setToggling(v)}>{v.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
                    <Button size="sm" variant="danger" aria-label={`Hapus vendor ${v.code}`} onClick={() => setDeleting(v)}>Hapus</Button>
                  </div>
                ),
              },
            ]}
          />
          <Pagination page={vendors.data.current_page} lastPage={vendors.data.last_page} total={vendors.data.total} onPage={setPage} />
        </>
      )}

      {editing && <VendorForm vendor={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); refresh(); toast.success('Vendor disimpan.') }} />}
      {toggling && <VendorStatus vendor={toggling} onClose={() => setToggling(null)} onDone={() => { setToggling(null); refresh(); toast.success('Status vendor diperbarui.') }} />}
      {deleting && <VendorDelete vendor={deleting} onClose={() => setDeleting(null)} onDone={() => { setDeleting(null); refresh(); toast.success('Vendor dihapus.') }} />}
    </>
  )
}

function VendorStatus({ vendor, onClose, onDone }: { vendor: Vendor; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const next = vendor.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  return (
    <ConfirmDialog
      title={next === 'ACTIVE' ? 'Aktifkan vendor' : 'Nonaktifkan vendor'}
      confirmLabel={next === 'ACTIVE' ? 'Aktifkan' : 'Nonaktifkan'}
      danger={next === 'INACTIVE'}
      busy={busy}
      error={error}
      message={next === 'ACTIVE' ? `Aktifkan kembali ${vendor.code} · ${vendor.name}?` : `${vendor.code} · ${vendor.name} tidak dapat dipakai untuk faktur atau pembayaran baru. Dokumen yang sudah ada tidak berubah.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`${API}/vendors/${vendor.id}/status`, { status: next }))
        if (r.ok) onDone()
      }}
    />
  )
}

function VendorDelete({ vendor, onClose, onDone }: { vendor: Vendor; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  return (
    <ConfirmDialog
      title="Hapus vendor"
      confirmLabel="Hapus"
      danger
      busy={busy}
      error={error}
      message={`Hapus ${vendor.code} · ${vendor.name}? Hanya vendor yang belum memiliki dokumen yang dapat dihapus; vendor yang sudah dipakai harus dinonaktifkan.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`${API}/vendors/${vendor.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
