import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, StatusBadge, Tabs } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { useAction, useResource } from '../../lib/hooks'
import type { Branch, BusinessUnit } from '../../lib/types'

type Tab = 'branches' | 'units'

export default function Organization() {
  const [tab, setTab] = useState<Tab>('branches')
  return (
    <>
      <PageHeader title="Organisasi" description="Cabang dan unit bisnis menentukan cakupan data setiap pengguna. Data yang sudah dipakai dinonaktifkan, tidak dihapus." />
      <Card flush>
        <Tabs tabs={[{ id: 'branches', label: 'Cabang' }, { id: 'units', label: 'Unit bisnis' }]} value={tab} onChange={setTab} />
        {tab === 'branches' ? <Branches /> : <Units />}
      </Card>
    </>
  )
}

function Branches() {
  const { can } = useCapabilities()
  const toast = useToast()
  const canManage = can('organization.manage')
  const branches = useResource(async () => (await api.get<{ data: Branch[] }>('/app/branches')).data.data, [])
  const [editing, setEditing] = useState<Branch | 'new' | null>(null)
  const [toggling, setToggling] = useState<Branch | null>(null)

  if (branches.loading && !branches.data) return <Loading />
  if (branches.error || !branches.data) return <ErrorNotice error={branches.error} onRetry={branches.reload} />

  return (
    <>
      <div className="card-head">
        <span className="muted">Jumlah cabang aktif dibatasi oleh langganan.</span>
        {canManage && <Button variant="primary" size="sm" onClick={() => setEditing('new')}>Cabang baru</Button>}
      </div>
      {branches.data.length === 0 ? (
        <EmptyState title="Belum ada cabang" />
      ) : (
        <DataTable
          caption="Daftar cabang"
          rows={branches.data}
          rowKey={(b) => b.id}
          columns={[
            { header: 'Kode', cell: (b) => <span className="mono">{b.code}</span> },
            { header: 'Nama', cell: (b) => <><strong>{b.name}</strong>{b.address && <div className="muted">{b.address}</div>}</> },
            { header: 'Status', cell: (b) => <StatusBadge status={b.status} /> },
            { header: 'Aksi', actions: true, cell: (b) => canManage && (
              <div className="actions">
                <Button size="sm" onClick={() => setEditing(b)}>Ubah</Button>
                <Button size="sm" onClick={() => setToggling(b)}>{b.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
              </div>
            ) },
          ]}
        />
      )}
      {editing && <BranchForm branch={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); branches.reload(); toast.success('Cabang disimpan.') }} />}
      {toggling && <StatusToggle kind="cabang" path={`/app/branches/${toggling.id}/status`} item={toggling} onClose={() => setToggling(null)} onDone={() => { setToggling(null); branches.reload(); toast.success('Status cabang diperbarui.') }} />}
    </>
  )
}

function BranchForm({ branch, onClose, onDone }: { branch: Branch | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ code: branch?.code ?? '', name: branch?.name ?? '', address: branch?.address ?? '' })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  async function submit() {
    const body = { name: f.name.trim(), address: f.address.trim() || null }
    const r = await run(() => (branch ? api.patch(`/app/branches/${branch.id}`, body) : api.post('/app/branches', { ...body, code: f.code.trim() })))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={branch ? `Ubah cabang ${branch.code}` : 'Cabang baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Kode" error={fieldError(error, 'code')} hint={branch ? 'Kode tidak dapat diubah.' : 'Huruf, angka, strip, atau garis bawah.'}>
          {(p) => <input className="input mono" required maxLength={30} disabled={!!branch} value={f.code} onChange={set('code')} {...p} />}
        </Field>
        <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required maxLength={255} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Alamat" error={fieldError(error, 'address')} full>{(p) => <input className="input" maxLength={255} value={f.address} onChange={set('address')} {...p} />}</Field>
      </div>
    </FormModal>
  )
}

function Units() {
  const { can } = useCapabilities()
  const toast = useToast()
  const canManage = can('organization.manage')
  const units = useResource(async () => (await api.get<{ data: BusinessUnit[] }>('/app/business-units')).data.data, [])
  const branches = useResource(async () => (await api.get<{ data: Branch[] }>('/app/branches')).data.data, [])
  const [editing, setEditing] = useState<BusinessUnit | 'new' | null>(null)
  const [toggling, setToggling] = useState<BusinessUnit | null>(null)

  if (units.loading && !units.data) return <Loading />
  if (units.error || !units.data) return <ErrorNotice error={units.error} onRetry={units.reload} />

  return (
    <>
      <div className="card-head">
        <span className="muted">Unit bisnis dapat berdiri sendiri atau berada di bawah satu cabang.</span>
        {canManage && <Button variant="primary" size="sm" onClick={() => setEditing('new')}>Unit bisnis baru</Button>}
      </div>
      {units.data.length === 0 ? (
        <EmptyState title="Belum ada unit bisnis" />
      ) : (
        <DataTable
          caption="Daftar unit bisnis"
          rows={units.data}
          rowKey={(u) => u.id}
          columns={[
            { header: 'Kode', cell: (u) => <span className="mono">{u.code}</span> },
            { header: 'Nama', cell: (u) => <strong>{u.name}</strong> },
            { header: 'Cabang', cell: (u) => u.branch?.name ?? <span className="muted">Tanpa cabang</span> },
            { header: 'Status', cell: (u) => <StatusBadge status={u.status} /> },
            { header: 'Aksi', actions: true, cell: (u) => canManage && (
              <div className="actions">
                <Button size="sm" onClick={() => setEditing(u)}>Ubah</Button>
                <Button size="sm" onClick={() => setToggling(u)}>{u.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
              </div>
            ) },
          ]}
        />
      )}
      {editing && (
        <UnitForm unit={editing === 'new' ? null : editing} branches={(branches.data ?? []).filter((b) => b.status === 'ACTIVE' || (editing !== 'new' && editing.branch_id === b.id))}
          onClose={() => setEditing(null)} onDone={() => { setEditing(null); units.reload(); toast.success('Unit bisnis disimpan.') }} />
      )}
      {toggling && <StatusToggle kind="unit bisnis" path={`/app/business-units/${toggling.id}/status`} item={toggling} onClose={() => setToggling(null)} onDone={() => { setToggling(null); units.reload(); toast.success('Status unit bisnis diperbarui.') }} />}
    </>
  )
}

function UnitForm({ unit, branches, onClose, onDone }: { unit: BusinessUnit | null; branches: Branch[]; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ code: unit?.code ?? '', name: unit?.name ?? '', branch_id: unit?.branch_id ?? '' })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  async function submit() {
    const body = { name: f.name.trim(), branch_id: f.branch_id || null }
    const r = await run(() => (unit ? api.patch(`/app/business-units/${unit.id}`, body) : api.post('/app/business-units', { ...body, code: f.code.trim() })))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={unit ? `Ubah unit bisnis ${unit.code}` : 'Unit bisnis baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Kode" error={fieldError(error, 'code')} hint={unit ? 'Kode tidak dapat diubah.' : 'Huruf, angka, strip, atau garis bawah.'}>
          {(p) => <input className="input mono" required maxLength={30} disabled={!!unit} value={f.code} onChange={set('code')} {...p} />}
        </Field>
        <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required maxLength={255} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Cabang induk" error={fieldError(error, 'branch_id')} full>
          {(p) => (
            <select className="select" value={f.branch_id} onChange={set('branch_id')} {...p}>
              <option value="">Tanpa cabang</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
            </select>
          )}
        </Field>
      </div>
    </FormModal>
  )
}

function StatusToggle({ kind, path, item, onClose, onDone }: { kind: string; path: string; item: { code: string; name: string; status: 'ACTIVE' | 'INACTIVE' }; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const next = item.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  return (
    <ConfirmDialog
      title={next === 'ACTIVE' ? `Aktifkan ${kind}` : `Nonaktifkan ${kind}`}
      confirmLabel={next === 'ACTIVE' ? 'Aktifkan' : 'Nonaktifkan'}
      danger={next === 'INACTIVE'}
      busy={busy}
      error={error}
      message={next === 'ACTIVE' ? `Aktifkan kembali ${item.code} · ${item.name}? Ini dihitung dalam batas langganan.` : `${item.code} · ${item.name} tidak dapat dipilih untuk data baru. Riwayat yang sudah ada tetap tersimpan.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(path, { status: next }))
        if (r.ok) onDone()
      }}
    />
  )
}
