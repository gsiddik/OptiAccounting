import { useState } from 'react'
import { DataTable } from '../../../components/DataTable'
import { ErrorState } from '../../../components/ErrorState'
import { ConfirmDialog, FormModal } from '../../../components/Modal'
import { ScopeEditor } from '../../../components/ScopeEditor'
import { useToast } from '../../../components/Toast'
import { Badge, Banner, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge, Tabs } from '../../../components/ui'
import { useI18n } from '../../../i18n'
import { api } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { useAction, useDebounced, useResource } from '../../../lib/hooks'
import { scopeLabel } from '../../../lib/labels'
import { toDraft, toPayload, type ScopeDraft } from '../../../lib/scopes'
import type { Branch, BusinessUnit } from '../../../lib/types'
import { InvitationList, InviteUser } from './Invitations'
import { RolesField } from './RolesField'
import { SeatsBanner } from './SeatsBanner'
import type { DelegatedUser, DelegatedUserPage, DelegationStatus } from './types'

function useOrganizationLists(enabled: boolean) {
  const branches = useResource(async () => (enabled ? (await api.get<{ data: Branch[] }>('/app/branches')).data.data : []), [enabled])
  const units = useResource(async () => (enabled ? (await api.get<{ data: BusinessUnit[] }>('/app/business-units')).data.data : []), [enabled])
  return { branches: branches.data ?? [], units: units.data ?? [], error: branches.error ?? units.error }
}

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
  const [tab, setTab] = useState<'users' | 'invitations'>('users')
  const [inviting, setInviting] = useState(false)
  const [invitationsTick, setInvitationsTick] = useState(0)
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

  return (
    <>
      <PageHeader
        title={t('users.title')}
        description={t('users.description')}
        actions={canManage && !users.error && <Button variant="primary" onClick={() => setInviting(true)}>{t('users.invite')}</Button>}
      />
      <Banner tone="info">{t('users.managed_notice')}</Banner>
      <Tabs tabs={[{ id: 'users' as const, label: t('users.tab.users') }, { id: 'invitations' as const, label: t('users.tab.invitations') }]} value={tab} onChange={setTab} />
      {tab === 'invitations' && <InvitationList canManage={canManage} refreshKey={invitationsTick} />}
      {tab === 'users' && (
        <>
          <SeatsBanner seats={users.data?.meta.seats} />
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
        </>
      )}

      {inviting && (
        <InviteUser
          onClose={() => setInviting(false)}
          onDone={(invitation) => {
            setInviting(false)
            setTab('invitations')
            setInvitationsTick((n) => n + 1)
            toast.success(t('invite.toast', { email: invitation.email }))
          }}
        />
      )}
      {rolesFor && <EditRoles user={rolesFor} onClose={() => setRolesFor(null)} onDone={() => { setRolesFor(null); users.reload(); toast.success(t('users.toast.roles')) }} />}
      {scopeFor?.local && <EditScopes user={scopeFor} org={org} onClose={() => setScopeFor(null)} onDone={() => { setScopeFor(null); users.reload(); toast.success(t('users.toast.scope')) }} />}
      {removing && <RemoveAccess user={removing} onClose={() => setRemoving(null)} onDone={() => { setRemoving(null); users.reload(); toast.success(t('users.toast.removed')) }} />}
    </>
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
