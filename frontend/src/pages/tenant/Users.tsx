import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { RoleChecklist } from '../../components/RoleChecklist'
import { ScopeEditor } from '../../components/ScopeEditor'
import { useToast } from '../../components/Toast'
import { Badge, Banner, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { useAction, useDebounced, useResource } from '../../lib/hooks'
import { scopeLabels } from '../../lib/labels'
import { toDraft, toPayload, type ScopeDraft } from '../../lib/scopes'
import type { Branch, BusinessUnit, DataScopeRow, Member, Paginated, Role } from '../../lib/types'

type NextStatus = 'ACTIVE' | 'SUSPENDED' | 'INACTIVE'

const scopesOf = (m: Member) => m.data_scopes ?? m.dataScopes ?? []

function useOrganizationLists(enabled: boolean) {
  const branches = useResource(async () => (enabled ? (await api.get<{ data: Branch[] }>('/app/branches')).data.data : []), [enabled])
  const units = useResource(async () => (enabled ? (await api.get<{ data: BusinessUnit[] }>('/app/business-units')).data.data : []), [enabled])
  return { branches: branches.data ?? [], units: units.data ?? [], loading: branches.loading || units.loading, error: branches.error ?? units.error }
}

export default function Users() {
  const { can } = useCapabilities()
  const { state } = useAuth()
  const myId = state.status === 'ready' ? state.me.user.id : null
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const q = useDebounced(search)
  const [creating, setCreating] = useState(false)
  const [rolesFor, setRolesFor] = useState<Member | null>(null)
  const [scopeFor, setScopeFor] = useState<Member | null>(null)
  const [change, setChange] = useState<{ member: Member; status: NextStatus } | null>(null)
  const canManage = can('access.user.manage')
  const canScope = can('access.scope.manage')
  const org = useOrganizationLists(can('organization.view'))

  const members = useResource(
    async () => (await api.get<Paginated<Member>>('/app/users', { params: { page, ...(q ? { search: q } : {}), ...(status ? { status } : {}) } })).data,
    [page, q, status],
  )

  const nameOf = (row: DataScopeRow) => {
    if (row.scope_type === 'BRANCH') return org.branches.find((b) => b.id === row.branch_id)?.code
    if (row.scope_type === 'BUSINESS_UNIT') return org.units.find((u) => u.id === row.business_unit_id)?.code
    return undefined
  }

  return (
    <>
      <PageHeader
        title="Pengguna"
        description="Siapa yang boleh masuk ke organisasi ini, dengan peran apa, dan data mana yang boleh dijangkau."
        actions={canManage && <Button variant="primary" onClick={() => setCreating(true)}>Pengguna baru</Button>}
      />
      <Card flush>
        <div className="card-head">
          <div className="toolbar">
            <label htmlFor="user-search" className="sr-only">Cari pengguna</label>
            <input id="user-search" className="input" placeholder="Cari nama atau e-mail" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
            <label htmlFor="user-status" className="sr-only">Status</label>
            <select id="user-status" className="select" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
              <option value="">Semua status</option>
              <option value="ACTIVE">Aktif</option>
              <option value="INVITED">Diundang</option>
              <option value="SUSPENDED">Ditangguhkan</option>
              <option value="INACTIVE">Nonaktif</option>
            </select>
          </div>
        </div>
        {members.loading && !members.data ? <Loading /> : members.error || !members.data ? <ErrorNotice error={members.error} onRetry={members.reload} /> : members.data.data.length === 0 ? (
          <EmptyState title="Tidak ada pengguna">Ubah filter atau tambahkan pengguna baru.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar pengguna"
              rows={members.data.data}
              rowKey={(m) => m.id}
              columns={[
                { header: 'Pengguna', cell: (m) => <><strong>{m.user.name}</strong><div className="muted">{m.user.email}</div></> },
                { header: 'Peran', cell: (m) => <div className="chips">{m.roles.length === 0 ? <span className="muted">Tanpa peran</span> : m.roles.map((r) => <Badge key={r.id} tone="info">{r.name}</Badge>)}</div> },
                { header: 'Cakupan data', cell: (m) => (
                  <div className="chips">
                    {scopesOf(m).length === 0 ? <span className="muted">Tidak ada</span> : scopesOf(m).map((s) => <Badge key={s.id}>{scopeLabels[s.scope_type]}{nameOf(s) ? `: ${nameOf(s)}` : ''}</Badge>)}
                  </div>
                ) },
                { header: 'Status', cell: (m) => <StatusBadge status={m.status} /> },
                { header: 'Aksi', actions: true, cell: (m) => m.user.id !== myId && (canManage || canScope) && (
                  <div className="actions">
                    {canManage && <Button size="sm" onClick={() => setRolesFor(m)}>Peran</Button>}
                    {canScope && <Button size="sm" onClick={() => setScopeFor(m)}>Cakupan</Button>}
                    {canManage && m.status === 'ACTIVE' && <Button size="sm" onClick={() => setChange({ member: m, status: 'SUSPENDED' })}>Tangguhkan</Button>}
                    {canManage && (m.status === 'SUSPENDED' || m.status === 'INACTIVE') && <Button size="sm" onClick={() => setChange({ member: m, status: 'ACTIVE' })}>Aktifkan</Button>}
                    {canManage && m.status !== 'INACTIVE' && <Button size="sm" variant="danger" onClick={() => setChange({ member: m, status: 'INACTIVE' })}>Nonaktifkan</Button>}
                  </div>
                ) },
              ]}
            />
            <Pagination page={members.data.current_page} lastPage={members.data.last_page} total={members.data.total} onPage={setPage} />
          </>
        )}
      </Card>

      {creating && <CreateUser org={org} canScope={canScope} onClose={() => setCreating(false)} onDone={() => { setCreating(false); members.reload(); toast.success('Pengguna ditambahkan.') }} />}
      {rolesFor && <EditRoles member={rolesFor} onClose={() => setRolesFor(null)} onDone={() => { setRolesFor(null); members.reload(); toast.success('Peran pengguna diperbarui.') }} />}
      {scopeFor && <EditScopes member={scopeFor} org={org} onClose={() => setScopeFor(null)} onDone={() => { setScopeFor(null); members.reload(); toast.success('Cakupan data diperbarui.') }} />}
      {change && <ChangeStatus change={change} onClose={() => setChange(null)} onDone={() => { setChange(null); members.reload(); toast.success('Status pengguna diperbarui.') }} />}
    </>
  )
}

function useRoles() {
  return useResource(async () => (await api.get<{ data: Role[] }>('/app/roles')).data.data, [])
}

function CreateUser({ org, canScope, onClose, onDone }: { org: ReturnType<typeof useOrganizationLists>; canScope: boolean; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const roles = useRoles()
  const [f, setF] = useState({ name: '', email: '', password: '' })
  const [selected, setSelected] = useState(new Set<string>())
  const [scopes, setScopes] = useState<ScopeDraft[]>([{ scope_type: 'TENANT', branch_id: '', business_unit_id: '' }])
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  async function submit() {
    const body = { name: f.name.trim(), email: f.email.trim(), ...(f.password ? { password: f.password } : {}), role_ids: [...selected], ...(canScope ? { data_scopes: toPayload(scopes) } : {}) }
    const r = await run(() => api.post('/app/users', body))
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Pengguna baru" wide busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <Banner tone="info">Jika e-mail sudah terdaftar di organisasi lain, pengguna menerima undangan dan harus menerimanya sendiri. Kata sandi hanya untuk akun baru.</Banner>
      <div className="form-grid">
        <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="E-mail" error={fieldError(error, 'email')}>{(p) => <input className="input" type="email" required autoComplete="off" value={f.email} onChange={set('email')} {...p} />}</Field>
        <Field label="Kata sandi awal" error={fieldError(error, 'password')} hint="Minimal 12 karakter, dengan huruf besar, huruf kecil, dan angka." full>
          {(p) => <input className="input" type="password" autoComplete="new-password" value={f.password} onChange={set('password')} {...p} />}
        </Field>
      </div>
      {roles.loading && !roles.data ? <Loading /> : roles.error ? <ErrorNotice error={roles.error} onRetry={roles.reload} /> : <RoleChecklist roles={roles.data ?? []} selected={selected} onChange={setSelected} />}
      {canScope && (org.error ? <ErrorNotice error={org.error} /> : <ScopeEditor rows={scopes} onChange={setScopes} branches={org.branches.filter((b) => b.status === 'ACTIVE')} units={org.units.filter((u) => u.status === 'ACTIVE')} />)}
    </FormModal>
  )
}

function EditRoles({ member, onClose, onDone }: { member: Member; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const roles = useRoles()
  const [selected, setSelected] = useState(new Set(member.roles.map((r) => r.id)))

  async function submit() {
    const r = await run(() => api.put(`/app/users/${member.id}/roles`, { role_ids: [...selected] }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={`Peran: ${member.user.name}`} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      {roles.loading && !roles.data ? <Loading /> : roles.error ? <ErrorNotice error={roles.error} onRetry={roles.reload} /> : <RoleChecklist roles={roles.data ?? []} selected={selected} onChange={setSelected} />}
    </FormModal>
  )
}

function EditScopes({ member, org, onClose, onDone }: { member: Member; org: ReturnType<typeof useOrganizationLists>; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [rows, setRows] = useState<ScopeDraft[]>(scopesOf(member).map(toDraft))

  async function submit() {
    const r = await run(() => api.put(`/app/users/${member.id}/data-scopes`, { data_scopes: toPayload(rows) }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={`Cakupan data: ${member.user.name}`} wide busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      {org.error ? <ErrorNotice error={org.error} /> : <ScopeEditor rows={rows} onChange={setRows} branches={org.branches.filter((b) => b.status === 'ACTIVE' || rows.some((r) => r.branch_id === b.id))} units={org.units.filter((u) => u.status === 'ACTIVE' || rows.some((r) => r.business_unit_id === u.id))} />}
    </FormModal>
  )
}

const CHANGE_TEXT: Record<NextStatus, { title: string; label: string; body: (name: string) => string }> = {
  ACTIVE: { title: 'Aktifkan pengguna', label: 'Aktifkan', body: (n) => `Aktifkan ${n}? Pengguna ini dihitung kembali dalam batas jumlah pengguna.` },
  SUSPENDED: { title: 'Tangguhkan pengguna', label: 'Tangguhkan', body: (n) => `${n} langsung keluar dari semua sesi dan tidak dapat masuk sampai diaktifkan kembali.` },
  INACTIVE: { title: 'Nonaktifkan pengguna', label: 'Nonaktifkan', body: (n) => `${n} langsung keluar dari semua sesi dan tidak lagi dihitung dalam batas jumlah pengguna. Riwayat aktivitasnya tetap tersimpan.` },
}

function ChangeStatus({ change, onClose, onDone }: { change: { member: Member; status: NextStatus }; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const text = CHANGE_TEXT[change.status]
  return (
    <ConfirmDialog
      title={text.title}
      confirmLabel={text.label}
      danger={change.status !== 'ACTIVE'}
      busy={busy}
      error={error}
      message={text.body(change.member.user.name)}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`/app/users/${change.member.id}/status`, { status: change.status }))
        if (r.ok) onDone()
      }}
    />
  )
}
