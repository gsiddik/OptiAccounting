import { useState } from 'react'
import { api } from '../lib/api'
import { useCapabilities } from '../lib/capabilities'
import { useAction, useResource } from '../lib/hooks'
import type { Permission, Role } from '../lib/types'
import { DataTable } from './DataTable'
import { ConfirmDialog, FormModal } from './Modal'
import { ManagedNotice } from './ManagedNotice'
import { PermissionPicker } from './PermissionPicker'
import { useToast } from './Toast'
import { Badge, Banner, Button, EmptyState, ErrorNotice, Field, Loading } from './ui'

type Props = {
  /** '/app' for the tenant portal, '/platform' for the platform portal. */
  base: '/app' | '/platform'
  managePermission: string
}

/** Roles are named permission sets: create, edit and delete custom roles; bundled system roles are read-only. */
export function RolesPanel({ base, managePermission }: Props) {
  const { canEdit, caps } = useCapabilities()
  const toast = useToast()
  const roles = useResource(async () => (await api.get<{ data: Role[] }>(`${base}/roles`)).data.data, [base])
  const permissions = useResource(async () => (await api.get<{ data: Permission[] }>(`${base}/permissions`)).data.data, [base])
  const [editing, setEditing] = useState<Role | 'new' | null>(null)
  const [removing, setRemoving] = useState<Role | null>(null)
  const canManage = canEdit(managePermission)
  const myPermissions = new Set(caps?.permissions ?? [])

  if (roles.loading && !roles.data) return <Loading />
  if (roles.error) return <ErrorNotice error={roles.error} onRetry={roles.reload} />

  return (
    <div>
      {base === '/app' && <ManagedNotice>Izin pengguna ditentukan di OptiNexus. Peran di bawah ini hanya untuk dilihat dan tidak menentukan akses.</ManagedNotice>}
      <div className="card-head">
        <span className="muted">Peran hanyalah kumpulan izin. Izin yang diberikan tidak boleh melebihi izin Anda sendiri.</span>
        {canManage && <Button variant="primary" size="sm" onClick={() => setEditing('new')}>Peran baru</Button>}
      </div>
      {roles.data && roles.data.length === 0 ? (
        <EmptyState title="Belum ada peran" />
      ) : (
        <DataTable
          caption="Daftar peran"
          rows={roles.data ?? []}
          rowKey={(r) => r.id}
          columns={[
            { header: 'Nama', cell: (r) => <strong>{r.name}</strong> },
            { header: 'Jenis', cell: (r) => (r.is_system ? <Badge tone="info">Bawaan sistem</Badge> : <Badge>Kustom</Badge>) },
            { header: 'Izin', align: 'right', cell: (r) => r.permissions.length },
            { header: 'Aksi', actions: true, cell: (r) => (
              <div className="actions">
                <Button size="sm" onClick={() => setEditing(r)}>{r.is_system || !canManage ? 'Lihat' : 'Ubah'}</Button>
                {canManage && !r.is_system && <Button size="sm" variant="danger" onClick={() => setRemoving(r)}>Hapus</Button>}
              </div>
            ) },
          ]}
        />
      )}

      {editing && permissions.data && (
        <RoleEditor
          base={base}
          role={editing === 'new' ? null : editing}
          permissions={permissions.data}
          myPermissions={myPermissions}
          readOnly={!canManage || (editing !== 'new' && editing.is_system)}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); roles.reload(); toast.success('Peran disimpan.') }}
        />
      )}
      {removing && (
        <RoleRemoval base={base} role={removing} onClose={() => setRemoving(null)} onDone={() => { setRemoving(null); roles.reload(); toast.success('Peran dihapus.') }} />
      )}
    </div>
  )
}

function RoleEditor({ base, role, permissions, myPermissions, readOnly, onClose, onSaved }: {
  base: string
  role: Role | null
  permissions: Permission[]
  myPermissions: Set<string>
  readOnly: boolean
  onClose: () => void
  onSaved: () => void
}) {
  const [name, setName] = useState(role?.name ?? '')
  const [description, setDescription] = useState(role?.description ?? '')
  const [selected, setSelected] = useState(new Set(role?.permissions.map((p) => p.code) ?? []))
  const { busy, error, run } = useAction()

  async function save() {
    const body = { name: name.trim(), description: description.trim() || null, permissions: [...selected] }
    const result = await run(() => (role ? api.patch(`${base}/roles/${role.id}`, body) : api.post(`${base}/roles`, body)))
    if (result.ok) onSaved()
  }

  if (readOnly) {
    return (
      <FormModal title={role?.name ?? 'Peran'} wide submitLabel="Tutup" onSubmit={onClose} onClose={onClose}>
        <Banner tone="info">{role?.is_system ? 'Peran bawaan sistem tidak dapat diubah. Buat peran kustom untuk kombinasi izin lain.' : 'Anda hanya dapat melihat peran ini.'}</Banner>
        <PermissionPicker permissions={permissions} selected={selected} onChange={() => undefined} disabled />
      </FormModal>
    )
  }

  return (
    <FormModal title={role ? `Ubah peran: ${role.name}` : 'Peran baru'} wide busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Nama peran">{(p) => <input className="input" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} {...p} />}</Field>
        <Field label="Keterangan">{(p) => <input className="input" maxLength={255} value={description} onChange={(e) => setDescription(e.target.value)} {...p} />}</Field>
      </div>
      <div className="field">
        <span className="label">Izin ({selected.size})</span>
        <span className="hint">Izin yang tidak Anda miliki tidak dapat diberikan.</span>
      </div>
      <PermissionPicker permissions={permissions} selected={selected} onChange={setSelected} held={myPermissions} />
    </FormModal>
  )
}

function RoleRemoval({ base, role, onClose, onDone }: { base: string; role: Role; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  return (
    <ConfirmDialog
      title="Hapus peran"
      danger
      confirmLabel="Hapus"
      busy={busy}
      error={error}
      message={<>Hapus peran <strong>{role.name}</strong>? Peran yang masih dipakai pengguna tidak dapat dihapus.</>}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`${base}/roles/${role.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}

export function PermissionList({ base }: { base: '/app' | '/platform' }) {
  const permissions = useResource(async () => (await api.get<{ data: Permission[] }>(`${base}/permissions`)).data.data, [base])
  if (permissions.loading && !permissions.data) return <Loading />
  if (permissions.error) return <ErrorNotice error={permissions.error} onRetry={permissions.reload} />
  return (
    <div className="card-body">
      <p className="muted" style={{ marginBottom: 12 }}>Semua izin yang dikenal sistem. Hak akses diputuskan oleh izin ini, bukan oleh nama peran.</p>
      <PermissionPicker permissions={permissions.data ?? []} selected={new Set()} onChange={() => undefined} disabled />
    </div>
  )
}
