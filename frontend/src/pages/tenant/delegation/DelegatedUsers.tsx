import { useState } from 'react'
import { DataTable } from '../../../components/DataTable'
import { ErrorState } from '../../../components/ErrorState'
import { ConfirmDialog, FormModal } from '../../../components/Modal'
import { RoleChecklist } from '../../../components/RoleChecklist'
import { ScopeEditor } from '../../../components/ScopeEditor'
import { useToast } from '../../../components/Toast'
import { Badge, Banner, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, Pagination, StatusBadge } from '../../../components/ui'
import { useI18n } from '../../../i18n'
import { api } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { fieldError } from '../../../lib/forms'
import { useAction, useDebounced, useResource } from '../../../lib/hooks'
import { scopeLabel } from '../../../lib/labels'
import { toDraft, toPayload, type ScopeDraft } from '../../../lib/scopes'
import type { Branch, BusinessUnit, Role } from '../../../lib/types'
import type { AssignableRole, CreateOutcome, DelegatedUser, DelegatedUserPage, DelegationStatus } from './types'

function useOrganizationLists(enabled: boolean) {
  const branches = useResource(async () => (enabled ? (await api.get<{ data: Branch[] }>('/app/branches')).data.data : []), [enabled])
  const units = useResource(async () => (enabled ? (await api.get<{ data: BusinessUnit[] }>('/app/business-units')).data.data : []), [enabled])
  return { branches: branches.data ?? [], units: units.data ?? [], error: branches.error ?? units.error }
}

function useAssignableRoles() {
  return useResource(async () => (await api.get<{ data: AssignableRole[] }>('/app/delegation/assignable-roles')).data.data, [])
}

/** The checklist wants the shape of a local role; OptiNexus roles only need an id, a name and a description there. */
const asChecklist = (roles: AssignableRole[]): Role[] => roles.map((r) => ({ id: r.id, name: r.name, description: r.description, is_system: r.template, permissions: [] }))

/**
 * Settings > Users while OptiNexus is the identity authority. What is listed is OptiNexus' answer; what is changed is changed by
 * OptiNexus on behalf of the signed-in Admin Tenant. This page keeps no list of its own and no second set of rules.
 */
export function DelegatedUsers({ fallback }: { fallback: React.ReactNode }) {
  const { t } = useI18n()
  const status = useResource(async () => (await api.get<DelegationStatus>('/app/delegation/status')).data, [])

  if (status.loading && !status.data) return <Loading />
  if (status.error || !status.data) return <ErrorState error={status.error} onRetry={status.reload} />
  if (!status.data.available) {
    const reason = status.data.reason ?? 'DELEGATION_NOT_CONFIGURED'
    return (
      <>
        <PageHeader title={t('users.title')} description={t('users.description')} />
        <ErrorState error={new Error(reason)} kind="setup" />
        <Banner tone="warn">{t(`error.${reason}` as 'error.DELEGATION_NOT_CONFIGURED')}</Banner>
        {fallback}
      </>
    )
  }
  return <UserList />
}

function UserList() {
  const { t } = useI18n()
  const { can } = useCapabilities()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const q = useDebounced(search)
  const [creating, setCreating] = useState(false)
  const [rolesFor, setRolesFor] = useState<DelegatedUser | null>(null)
  const [scopeFor, setScopeFor] = useState<DelegatedUser | null>(null)
  const [removing, setRemoving] = useState<DelegatedUser | null>(null)
  const canManage = can('access.user.manage')
  const canScope = can('access.scope.manage')
  const org = useOrganizationLists(can('organization.view'))

  const users = useResource(
    async () => (await api.get<DelegatedUserPage>('/app/delegation/users', { params: { page, ...(q ? { search: q } : {}) } })).data,
    [page, q],
  )

  const nameOf = (row: { scope_type: string; branch_id?: string | null; business_unit_id?: string | null }) => {
    if (row.scope_type === 'BRANCH') return org.branches.find((b) => b.id === row.branch_id)?.code
    if (row.scope_type === 'BUSINESS_UNIT') return org.units.find((u) => u.id === row.business_unit_id)?.code
    return undefined
  }

  const seats = users.data?.meta.seats
  const full = seats?.remaining === 0

  return (
    <>
      <PageHeader
        title={t('users.title')}
        description={t('users.description')}
        actions={canManage && !users.error && <Button variant="primary" onClick={() => setCreating(true)}>{t('users.add')}</Button>}
      />
      <Banner tone="info">{t('users.managed_notice')}</Banner>
      {seats && (
        <Banner tone={full ? 'warn' : 'info'}>
          {seats.limit === null ? t('users.seats_unlimited', { used: seats.used }) : t('users.seats', { used: seats.used, limit: seats.limit })}
          {full && <> {t('users.seats_full')}</>}
        </Banner>
      )}
      <Card flush>
        <div className="card-head">
          <div className="toolbar">
            <label htmlFor="user-search" className="sr-only">{t('users.search_label')}</label>
            <input id="user-search" className="input" placeholder={t('users.search')} value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
          </div>
        </div>
        {users.loading && !users.data ? <Loading /> : users.error || !users.data ? <ErrorState error={users.error} onRetry={users.reload} /> : users.data.data.length === 0 ? (
          q ? <EmptyState title={t('users.empty_search.title')}>{t('users.empty_search.body')}</EmptyState> : <EmptyState title={t('users.empty.title')}>{t('users.empty.body')}</EmptyState>
        ) : (
          <>
            <DataTable
              caption={t('users.table_caption')}
              rows={users.data.data}
              rowKey={(u) => u.id}
              columns={[
                { header: t('users.col.user'), primary: true, cell: (u) => <><strong>{u.name}</strong>{u.is_tenant_admin && <> <Badge tone="info">{t('users.admin')}</Badge></>}<div className="muted">{u.email}</div></> },
                { header: t('users.col.roles'), cell: (u) => <div className="chips">{u.roles.length === 0 ? <span className="muted">{t('users.no_roles')}</span> : u.roles.map((r) => <Badge key={r.id} tone="info">{r.name}</Badge>)}</div> },
                { header: t('users.col.scope'), cell: (u) => (
                  <div className="chips">
                    {!u.local ? <span className="muted">{t('users.scope_pending')}</span> : u.local.data_scopes.length === 0 ? <span className="muted">{t('users.no_scope')}</span> : u.local.data_scopes.map((s) => <Badge key={s.id}>{scopeLabel(s.scope_type)}{nameOf(s) ? `: ${nameOf(s)}` : ''}</Badge>)}
                  </div>
                ) },
                { header: t('users.col.status'), cell: (u) => <StatusBadge status={u.local?.status ?? u.membership_status} /> },
                { header: t('users.col.actions'), actions: true, cell: (u) => (
                  <div className="actions">
                    {u.is_tenant_admin && !u.is_self ? <span className="muted">{t('users.admin_managed')}</span> : null}
                    {!u.is_tenant_admin && !u.is_self && canManage && <Button size="sm" onClick={() => setRolesFor(u)}>{t('users.action.roles')}</Button>}
                    {u.local && canScope && <Button size="sm" onClick={() => setScopeFor(u)}>{t('users.action.scope')}</Button>}
                    {!u.is_tenant_admin && !u.is_self && canManage && <Button size="sm" variant="danger" onClick={() => setRemoving(u)}>{t('users.action.remove')}</Button>}
                  </div>
                ) },
              ]}
            />
            <Pagination page={users.data.meta.pagination.current_page} lastPage={users.data.meta.pagination.last_page} total={users.data.meta.pagination.total} onPage={setPage} />
          </>
        )}
      </Card>

      {creating && (
        <CreateUser
          org={org}
          canScope={canScope}
          onClose={() => setCreating(false)}
          onDone={(r) => {
            setCreating(false)
            users.reload()
            toast.success(r.projection === 'DEFERRED' ? t('users.toast.deferred') : t(`users.toast.${r.outcome}`))
          }}
        />
      )}
      {rolesFor && <EditRoles user={rolesFor} onClose={() => setRolesFor(null)} onDone={() => { setRolesFor(null); users.reload(); toast.success(t('users.toast.roles')) }} />}
      {scopeFor?.local && <EditScopes user={scopeFor} org={org} onClose={() => setScopeFor(null)} onDone={() => { setScopeFor(null); users.reload(); toast.success(t('users.toast.scope')) }} />}
      {removing && <RemoveAccess user={removing} onClose={() => setRemoving(null)} onDone={() => { setRemoving(null); users.reload(); toast.success(t('users.toast.removed')) }} />}
    </>
  )
}

function RolesField({ selected, onChange }: { selected: Set<string>; onChange: (next: Set<string>) => void }) {
  const { t } = useI18n()
  const roles = useAssignableRoles()
  if (roles.loading && !roles.data) return <Loading label={t('users.roles.loading')} />
  if (roles.error) return <ErrorNotice error={roles.error} onRetry={roles.reload} />
  return <RoleChecklist roles={asChecklist(roles.data ?? [])} selected={selected} onChange={onChange} legend={t('users.roles.legend')} />
}

function CreateUser({ org, canScope, onClose, onDone }: { org: ReturnType<typeof useOrganizationLists>; canScope: boolean; onClose: () => void; onDone: (result: CreateOutcome) => void }) {
  const { t } = useI18n()
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ name: '', email: '', password: '' })
  const [selected, setSelected] = useState(new Set<string>())
  const [scopes, setScopes] = useState<ScopeDraft[]>([{ scope_type: 'OWN', branch_id: '', business_unit_id: '' }])
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  async function submit() {
    const body = { name: f.name.trim(), email: f.email.trim(), ...(f.password ? { initial_password: f.password } : {}), role_ids: [...selected], ...(canScope ? { data_scopes: toPayload(scopes) } : {}) }
    const r = await run(async () => (await api.post<CreateOutcome>('/app/delegation/users', body)).data)
    if (r.ok) onDone(r.value)
  }

  return (
    <FormModal title={t('users.create.title')} submitLabel={t('users.create.submit')} wide busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <Banner tone="info">{t('users.create.notice')}</Banner>
      <div className="form-grid">
        <Field label={t('users.create.name')} error={fieldError(error, 'name')}>{(p) => <input className="input" required value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label={t('users.create.email')} error={fieldError(error, 'email')}>{(p) => <input className="input" type="email" required autoComplete="off" value={f.email} onChange={set('email')} {...p} />}</Field>
        <Field label={t('users.create.password')} error={fieldError(error, 'initial_password')} hint={t('users.create.password_hint')} full>
          {(p) => <input className="input" type="password" autoComplete="new-password" value={f.password} onChange={set('password')} {...p} />}
        </Field>
      </div>
      <RolesField selected={selected} onChange={setSelected} />
      {fieldError(error, 'role_ids') && <span className="error">{fieldError(error, 'role_ids')}</span>}
      {canScope && (org.error ? <ErrorNotice error={org.error} /> : <ScopeEditor rows={scopes} onChange={setScopes} branches={org.branches.filter((b) => b.status === 'ACTIVE')} units={org.units.filter((u) => u.status === 'ACTIVE')} />)}
    </FormModal>
  )
}

function EditRoles({ user, onClose, onDone }: { user: DelegatedUser; onClose: () => void; onDone: () => void }) {
  const { t } = useI18n()
  const { busy, error, run } = useAction()
  const [selected, setSelected] = useState(new Set(user.roles.map((r) => r.id)))

  async function submit() {
    const r = await run(() => api.put(`/app/delegation/users/${user.id}/roles`, { role_ids: [...selected] }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={t('users.roles.title', { name: user.name })} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <RolesField selected={selected} onChange={setSelected} />
    </FormModal>
  )
}

function EditScopes({ user, org, onClose, onDone }: { user: DelegatedUser; org: ReturnType<typeof useOrganizationLists>; onClose: () => void; onDone: () => void }) {
  const { t } = useI18n()
  const { busy, error, run } = useAction()
  const [rows, setRows] = useState<ScopeDraft[]>((user.local?.data_scopes ?? []).map(toDraft))

  async function submit() {
    const r = await run(() => api.put(`/app/users/${user.local?.member_id}/data-scopes`, { data_scopes: toPayload(rows) }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={t('users.scope.title', { name: user.name })} wide busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      {org.error ? <ErrorNotice error={org.error} /> : <ScopeEditor rows={rows} onChange={setRows} branches={org.branches.filter((b) => b.status === 'ACTIVE' || rows.some((r) => r.branch_id === b.id))} units={org.units.filter((u) => u.status === 'ACTIVE' || rows.some((r) => r.business_unit_id === u.id))} />}
    </FormModal>
  )
}

function RemoveAccess({ user, onClose, onDone }: { user: DelegatedUser; onClose: () => void; onDone: () => void }) {
  const { t } = useI18n()
  const { busy, error, run } = useAction()
  return (
    <ConfirmDialog
      title={t('users.remove.title')}
      confirmLabel={t('users.remove.confirm')}
      danger
      busy={busy}
      error={error}
      message={t('users.remove.body', { name: user.name })}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`/app/delegation/users/${user.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
