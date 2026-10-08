import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { PermissionList, RolesPanel } from '../../components/RolesPanel'
import { RoleChecklist } from '../../components/RoleChecklist'
import { useToast } from '../../components/Toast'
import { Badge, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, StatusBadge, Tabs } from '../../components/ui'
import { api } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useCapabilities } from '../../lib/capabilities'
import { formatDateTime } from '../../lib/format'
import { fieldError } from '../../lib/forms'
import { useAction, useResource } from '../../lib/hooks'
import type { PlatformUser, Role } from '../../lib/types'

type Tab = 'operators' | 'roles' | 'permissions'

export default function Operators() {
  const { can } = useCapabilities()
  const tabs: { id: Tab; label: string }[] = [
    { id: 'operators', label: 'Operator' },
    ...(can('platform.role.view') ? [{ id: 'roles' as const, label: 'Peran' }] : []),
    ...(can('platform.permission.view') ? [{ id: 'permissions' as const, label: 'Izin' }] : []),
  ]
  const [tab, setTab] = useState<Tab>('operators')

  return (
    <>
      <PageHeader title="Operator & Akses Platform" description="Siapa yang boleh mengelola platform, dengan peran dan izin apa." />
      <Card flush>
        <Tabs tabs={tabs} value={tab} onChange={setTab} />
        {tab === 'operators' && <OperatorList />}
        {tab === 'roles' && <RolesPanel base="/platform" managePermission="platform.role.manage" />}
        {tab === 'permissions' && <PermissionList base="/platform" />}
      </Card>
    </>
  )
}

function OperatorList() {
  const { can } = useCapabilities()
  const { state } = useAuth()
  const myId = state.status === 'ready' ? state.me.user.id : null
  const toast = useToast()
  const canManage = can('platform.user.manage')
  const users = useResource(async () => (await api.get<{ data: PlatformUser[] }>('/platform/users')).data.data, [])
  const roles = useResource(async () => (await api.get<{ data: Role[] }>('/platform/roles')).data.data, [])
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<PlatformUser | null>(null)
  const [toggling, setToggling] = useState<PlatformUser | null>(null)

  if (users.loading && !users.data) return <Loading />
  if (users.error || !users.data) return <ErrorNotice error={users.error} onRetry={users.reload} />

  return (
    <>
      <div className="card-head">
        <span className="muted">Operator adalah pengguna dengan akses ke Portal Platform.</span>
        {canManage && <Button variant="primary" size="sm" onClick={() => setCreating(true)}>Operator baru</Button>}
      </div>
      {users.data.length === 0 ? (
        <EmptyState title="Belum ada operator" />
      ) : (
        <DataTable
          caption="Daftar operator platform"
          rows={users.data}
          rowKey={(u) => u.id}
          columns={[
            { header: 'Operator', primary: true, cell: (u) => <><strong>{u.name}</strong><div className="muted">{u.email}</div></> },
            { header: 'Peran', cell: (u) => <div className="chips">{(u.platformRoles ?? u.platform_roles ?? []).map((r) => <Badge key={r.id} tone="info">{r.name}</Badge>)}</div> },
            { header: 'Status', cell: (u) => <StatusBadge status={u.status} /> },
            { header: 'Login terakhir', cell: (u) => formatDateTime(u.last_login_at) },
            { header: 'Aksi', actions: true, cell: (u) => canManage && u.id !== myId && (
              <div className="actions">
                <Button size="sm" onClick={() => setEditing(u)}>Peran</Button>
                <Button size="sm" onClick={() => setToggling(u)}>{u.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
              </div>
            ) },
          ]}
        />
      )}
      {creating && roles.data && <CreateOperator roles={roles.data} onClose={() => setCreating(false)} onDone={() => { setCreating(false); users.reload(); toast.success('Operator ditambahkan.') }} />}
      {editing && roles.data && <EditRoles user={editing} roles={roles.data} onClose={() => setEditing(null)} onDone={() => { setEditing(null); users.reload(); toast.success('Peran operator diperbarui.') }} />}
      {toggling && <ToggleStatus user={toggling} onClose={() => setToggling(null)} onDone={() => { setToggling(null); users.reload(); toast.success('Status operator diperbarui.') }} />}
    </>
  )
}

function CreateOperator({ roles, onClose, onDone }: { roles: Role[]; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ name: '', email: '', password: '' })
  const [selected, setSelected] = useState(new Set<string>())
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  async function submit() {
    const r = await run(() => api.post('/platform/users', { ...f, name: f.name.trim(), email: f.email.trim(), role_ids: [...selected] }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Operator baru" busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="E-mail" error={fieldError(error, 'email')}>{(p) => <input className="input" type="email" required autoComplete="off" value={f.email} onChange={set('email')} {...p} />}</Field>
        <Field label="Kata sandi awal" error={fieldError(error, 'password')} hint="Minimal 12 karakter, dengan huruf besar, huruf kecil, dan angka." full>
          {(p) => <input className="input" type="password" required autoComplete="new-password" value={f.password} onChange={set('password')} {...p} />}
        </Field>
      </div>
      <RoleChecklist roles={roles} selected={selected} onChange={setSelected} legend="Peran (minimal satu)" />
      {fieldError(error, 'role_ids') && <span className="error">{fieldError(error, 'role_ids')}</span>}
    </FormModal>
  )
}

function EditRoles({ user, roles, onClose, onDone }: { user: PlatformUser; roles: Role[]; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [selected, setSelected] = useState(new Set((user.platformRoles ?? user.platform_roles ?? []).map((r) => r.id)))

  async function submit() {
    const r = await run(() => api.put(`/platform/users/${user.id}/roles`, { role_ids: [...selected] }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={`Peran: ${user.name}`} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <RoleChecklist roles={roles} selected={selected} onChange={setSelected} legend="Peran (minimal satu)" />
    </FormModal>
  )
}

function ToggleStatus({ user, onClose, onDone }: { user: PlatformUser; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const next = user.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  return (
    <ConfirmDialog
      title={next === 'ACTIVE' ? 'Aktifkan operator' : 'Nonaktifkan operator'}
      confirmLabel={next === 'ACTIVE' ? 'Aktifkan' : 'Nonaktifkan'}
      danger={next === 'INACTIVE'}
      busy={busy}
      error={error}
      message={next === 'ACTIVE' ? `Aktifkan kembali ${user.name}?` : `${user.name} langsung keluar dari semua sesi dan tidak dapat masuk lagi sampai diaktifkan kembali.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`/platform/users/${user.id}/status`, { status: next }))
        if (r.ok) onDone()
      }}
    />
  )
}
