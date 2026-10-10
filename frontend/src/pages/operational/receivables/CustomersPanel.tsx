import { useState } from 'react'
import { DataTable } from '../../../components/DataTable'
import { ConfirmDialog } from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { Badge, Button, EmptyState, ErrorNotice, Loading, Pagination, StatusBadge } from '../../../components/ui'
import { api } from '../../../lib/api'
import { useDebounced, useResource } from '../../../lib/hooks'
import { API, usePaymentTerms, type Customer, type Page } from '../../../lib/operational'
import { useAct } from '../payables/messages'
import { exportQuery, listQuery } from '../payables/lists'
import { ExportButton, Filters, Money } from '../shared'
import { CustomerForm } from './CustomerForm'

/** The "Pelanggan" tab: server-side search / status / term filters, pagination, create / edit, activate / deactivate and delete. */
export function CustomersPanel({ manage }: { manage: boolean }) {
  const toast = useToast()
  const { terms } = usePaymentTerms('ar-payment-terms')
  const [f, setF] = useState({ q: '', status: '', payment_term_id: '' })
  const [page, setPage] = useState(1)
  const q = useDebounced(f.q)
  const filter = { q, status: f.status, payment_term_id: f.payment_term_id }
  const customers = useResource(async () => (await api.get<Page<Customer>>(`${API}/customers`, { params: listQuery(filter, page) })).data, [page, q, f.status, f.payment_term_id])
  const [editing, setEditing] = useState<Customer | 'new' | null>(null)
  const [toggling, setToggling] = useState<Customer | null>(null)
  const [deleting, setDeleting] = useState<Customer | null>(null)

  const change = (patch: Partial<typeof f>) => {
    setF((s) => ({ ...s, ...patch }))
    setPage(1)
  }

  return (
    <>
      <div className="card-body">
        <Filters>
          <input className="input" type="search" aria-label="Cari pelanggan" placeholder="Cari kode, nama, atau NPWP" value={f.q} onChange={(e) => change({ q: e.target.value })} />
          <select className="select" aria-label="Status pelanggan" value={f.status} onChange={(e) => change({ status: e.target.value })}>
            <option value="">Semua status</option>
            <option value="ACTIVE">Aktif</option>
            <option value="INACTIVE">Nonaktif</option>
          </select>
          <select className="select" aria-label="Termin pembayaran" value={f.payment_term_id} onChange={(e) => change({ payment_term_id: e.target.value })}>
            <option value="">Semua termin</option>
            {terms.map((t) => <option key={t.id} value={t.id}>{t.code} · {t.name}</option>)}
          </select>
          <span className="spacer" />
          <ExportButton path={`${API}/customers/export`} params={exportQuery(filter)} filename="pelanggan.csv" />
          {manage && <Button variant="primary" onClick={() => setEditing('new')}>Pelanggan baru</Button>}
        </Filters>
      </div>

      {customers.loading && !customers.data ? (
        <Loading />
      ) : customers.error || !customers.data ? (
        <ErrorNotice error={customers.error} onRetry={customers.reload} />
      ) : customers.data.data.length === 0 ? (
        <EmptyState title="Tidak ada pelanggan">{manage ? 'Ubah filter atau tambahkan pelanggan baru.' : 'Ubah filter pencarian.'}</EmptyState>
      ) : (
        <>
          <DataTable
            caption="Daftar pelanggan"
            rows={customers.data.data}
            rowKey={(c) => c.id}
            scroll
            columns={[
              { header: 'Kode', primary: true, cell: (c) => <span className="mono">{c.code}</span> },
              { header: 'Nama', cell: (c) => <>{c.name}{c.tax_registered && <> <Badge tone="info">PKP</Badge></>}{c.legal_name && <div className="muted">{c.legal_name}</div>}{c.tax_id && <div className="muted">NPWP {c.tax_id}</div>}</> },
              { header: 'Kontak', cell: (c) => (c.contact_name || c.email || c.phone ? <>{c.contact_name}{c.email && <div className="muted">{c.email}</div>}{c.phone && <div className="muted">{c.phone}</div>}</> : <span className="muted">—</span>) },
              { header: 'Termin', cell: (c) => c.payment_term?.name ?? <span className="muted">—</span> },
              {
                header: 'Akun',
                cell: (c) => (
                  <>
                    <div>Piutang {c.receivable_account ? <span className="mono">{c.receivable_account.code}</span> : <span className="muted">standar</span>}</div>
                    <div>Pendapatan {c.default_revenue_account ? <span className="mono">{c.default_revenue_account.code}</span> : <span className="muted">—</span>}</div>
                  </>
                ),
              },
              { header: 'Batas kredit (info)', align: 'right', cell: (c) => (c.credit_limit === null ? <span className="muted">—</span> : <Money value={c.credit_limit} />) },
              { header: 'Status', cell: (c) => <StatusBadge status={c.status} /> },
              {
                header: 'Aksi',
                actions: true,
                cell: (c) => manage && (
                  <div className="actions">
                    <Button size="sm" aria-label={`Ubah pelanggan ${c.code}`} onClick={() => setEditing(c)}>Ubah</Button>
                    <Button size="sm" aria-label={`${c.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'} pelanggan ${c.code}`} onClick={() => setToggling(c)}>{c.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
                    <Button size="sm" variant="danger" aria-label={`Hapus pelanggan ${c.code}`} onClick={() => setDeleting(c)}>Hapus</Button>
                  </div>
                ),
              },
            ]}
          />
          <Pagination page={customers.data.current_page} lastPage={customers.data.last_page} total={customers.data.total} onPage={setPage} />
        </>
      )}

      {editing && <CustomerForm customer={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); customers.reload(); toast.success('Pelanggan disimpan.') }} />}
      {toggling && <CustomerStatus customer={toggling} onClose={() => setToggling(null)} onDone={() => { setToggling(null); customers.reload(); toast.success('Status pelanggan diperbarui.') }} />}
      {deleting && <CustomerDelete customer={deleting} onClose={() => setDeleting(null)} onDone={() => { setDeleting(null); customers.reload(); toast.success('Pelanggan dihapus.') }} />}
    </>
  )
}

function CustomerStatus({ customer, onClose, onDone }: { customer: Customer; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const next = customer.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  return (
    <ConfirmDialog
      title={next === 'ACTIVE' ? 'Aktifkan pelanggan' : 'Nonaktifkan pelanggan'}
      confirmLabel={next === 'ACTIVE' ? 'Aktifkan' : 'Nonaktifkan'}
      danger={next === 'INACTIVE'}
      busy={busy}
      error={error}
      message={next === 'ACTIVE' ? `Aktifkan kembali ${customer.code} · ${customer.name}?` : `${customer.code} · ${customer.name} tidak dapat dipakai untuk faktur atau penerimaan baru. Dokumen yang sudah ada tidak berubah.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`${API}/customers/${customer.id}/status`, { status: next }))
        if (r.ok) onDone()
      }}
    />
  )
}

function CustomerDelete({ customer, onClose, onDone }: { customer: Customer; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  return (
    <ConfirmDialog
      title="Hapus pelanggan"
      confirmLabel="Hapus"
      danger
      busy={busy}
      error={error}
      message={`Hapus ${customer.code} · ${customer.name}? Hanya pelanggan yang belum memiliki dokumen yang dapat dihapus; pelanggan yang sudah dipakai harus dinonaktifkan.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`${API}/customers/${customer.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
