import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { FormModal } from '../../components/Modal'
import { Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, TIMEZONES } from '../../lib/format'
import { fieldError } from '../../lib/forms'
import { useAction, useDebounced, useResource } from '../../lib/hooks'
import type { Paginated, Tenant } from '../../lib/types'

export default function Tenants() {
  const { can } = useCapabilities()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [creating, setCreating] = useState(false)
  const q = useDebounced(search)

  const { data, loading, error, reload } = useResource(
    async () => (await api.get<Paginated<Tenant>>('/platform/tenants', { params: { page, ...(q ? { search: q } : {}), ...(status ? { status } : {}) } })).data,
    [page, q, status],
  )

  return (
    <>
      <PageHeader
        title="Tenant"
        description="Organisasi pelanggan, status siklus hidup, dan langganannya."
        actions={can('platform.tenant.create') && <Button variant="primary" onClick={() => setCreating(true)}>Tenant baru</Button>}
      />
      <Card flush>
        <div className="card-head">
          <div className="toolbar">
            <label htmlFor="tenant-search" className="sr-only">Cari tenant</label>
            <input id="tenant-search" className="input" placeholder="Cari nama atau kode" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
            <label htmlFor="tenant-status" className="sr-only">Status</label>
            <select id="tenant-status" className="select" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
              <option value="">Semua status</option>
              {['DRAFT', 'ACTIVE', 'SUSPENDED', 'INACTIVE', 'TERMINATED'].map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
        </div>
        {loading && !data ? <Loading /> : error ? <ErrorNotice error={error} onRetry={reload} /> : data && data.data.length === 0 ? (
          <EmptyState title="Tidak ada tenant" action={can('platform.tenant.create') ? <Button onClick={() => setCreating(true)}>Buat tenant pertama</Button> : undefined}>
            Ubah filter atau buat tenant baru.
          </EmptyState>
        ) : data ? (
          <>
            <DataTable
              caption="Daftar tenant"
              rows={data.data}
              rowKey={(t) => t.id}
              columns={[
                { header: 'Tenant', cell: (t) => <Link to={`/platform/tenants/${t.id}`}><strong>{t.name}</strong></Link> },
                { header: 'Kode', cell: (t) => <span className="mono">{t.code}</span> },
                { header: 'Status', cell: (t) => <StatusBadge status={t.status} /> },
                { header: 'Zona waktu', cell: (t) => t.timezone },
                { header: 'Dibuat', cell: (t) => formatDate(t.created_at) },
              ]}
            />
            <Pagination page={data.current_page} lastPage={data.last_page} total={data.total} onPage={setPage} />
          </>
        ) : null}
      </Card>
      {creating && <CreateTenant onClose={() => setCreating(false)} />}
    </>
  )
}

function CreateTenant({ onClose }: { onClose: () => void }) {
  const navigate = useNavigate()
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ name: '', code: '', timezone: 'Asia/Jakarta', default_currency: 'IDR', adminName: '', adminEmail: '', adminPassword: '' })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  async function submit() {
    const withAdmin = f.adminName || f.adminEmail || f.adminPassword
    const body = {
      name: f.name.trim(), code: f.code.trim(), timezone: f.timezone, default_currency: f.default_currency.trim().toUpperCase(),
      ...(withAdmin ? { admin: { name: f.adminName.trim(), email: f.adminEmail.trim(), ...(f.adminPassword ? { password: f.adminPassword } : {}) } } : {}),
    }
    const r = await run(async () => (await api.post<Tenant>('/platform/tenants', body)).data)
    if (r.ok) navigate(`/platform/tenants/${r.value.id}`)
  }

  return (
    <FormModal title="Tenant baru" wide busy={busy} error={error} submitLabel="Buat tenant" onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Nama tenant" error={fieldError(error, 'name')}>{(p) => <input className="input" required value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Kode" hint="Huruf kecil, angka, dan tanda hubung. Tidak dapat diubah." error={fieldError(error, 'code')}>
          {(p) => <input className="input mono" required value={f.code} onChange={set('code')} {...p} />}
        </Field>
        <Field label="Zona waktu" hint="Menentukan tanggal bisnis tenant." error={fieldError(error, 'timezone')}>
          {(p) => <select className="select" value={f.timezone} onChange={set('timezone')} {...p}>{TIMEZONES.map((z) => <option key={z}>{z}</option>)}</select>}
        </Field>
        <Field label="Mata uang" error={fieldError(error, 'default_currency')}>{(p) => <input className="input mono" maxLength={3} value={f.default_currency} onChange={set('default_currency')} {...p} />}</Field>
        <div className="full"><h3>Administrator awal (opsional)</h3><p className="muted">Dapat ditambahkan sekarang atau nanti dari halaman tenant.</p></div>
        <Field label="Nama" error={fieldError(error, 'admin.name')}>{(p) => <input className="input" value={f.adminName} onChange={set('adminName')} {...p} />}</Field>
        <Field label="E-mail" error={fieldError(error, 'admin.email')}>{(p) => <input className="input" type="email" value={f.adminEmail} onChange={set('adminEmail')} {...p} />}</Field>
        <Field label="Kata sandi awal" hint="Minimal 12 karakter, huruf besar-kecil dan angka." full error={fieldError(error, 'admin.password')}>
          {(p) => <input className="input" type="password" autoComplete="new-password" value={f.adminPassword} onChange={set('adminPassword')} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}
