import { useState } from 'react'
import { DataTable } from '../../../components/DataTable'
import { ErrorState } from '../../../components/ErrorState'
import { ConfirmDialog, FormModal } from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { Badge, Banner, Button, Card, EmptyState, Field, Loading, PageHeader, Tabs } from '../../../components/ui'
import { useI18n } from '../../../i18n'
import { api } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { fieldError } from '../../../lib/forms'
import { useAction, useResource } from '../../../lib/hooks'
import { PermissionCatalogPicker } from './PermissionCatalogPicker'
import type { CatalogPermission, DelegatedRole, DelegationStatus, RoleCatalog } from './types'

type Tab = 'roles' | 'permissions'

/**
 * Settings > Roles while OptiNexus is the identity authority. Roles live in OptiNexus; a custom role may only contain permissions
 * OptiNexus delegates, and it refuses the rest, so this page offers exactly that set and shows the others as unavailable.
 */
export function DelegatedRoles({ fallback }: { fallback: React.ReactNode }) {
  const { t } = useI18n()
  const status = useResource(async () => (await api.get<DelegationStatus>('/app/delegation/status')).data, [])

  if (status.loading && !status.data) return <Loading />
  if (status.error || !status.data) return <ErrorState error={status.error} onRetry={status.reload} />
  if (!status.data.available) {
    const reason = status.data.reason ?? 'DELEGATION_NOT_CONFIGURED'
    return (
      <>
        <PageHeader title={t('roles.title')} description={t('roles.description')} />
        <ErrorState error={new Error(reason)} kind="setup" />
        <Banner tone="warn">{t(`error.${reason}` as 'error.DELEGATION_NOT_CONFIGURED')}</Banner>
        {fallback}
      </>
    )
  }
  return <RoleScreen />
}

function RoleScreen() {
  const { t } = useI18n()
  const { can } = useCapabilities()
  const toast = useToast()
  const [tab, setTab] = useState<Tab>('roles')
  const [editing, setEditing] = useState<DelegatedRole | 'new' | null>(null)
  const [removing, setRemoving] = useState<DelegatedRole | null>(null)
  const catalog = useResource(async () => (await api.get<RoleCatalog>('/app/delegation/roles')).data, [])
  // Role changes are made in OptiNexus on behalf of the signed-in Admin Tenant, so the local "managed elsewhere" switch does not apply here.
  const canManage = can('access.role.manage')

  return (
    <>
      <PageHeader title={t('roles.title')} description={t('roles.description')} actions={canManage && catalog.data && <Button variant="primary" onClick={() => setEditing('new')}>{t('roles.new')}</Button>} />
      <Banner tone="info">{t('roles.managed_notice')}</Banner>
      <Card flush>
        <Tabs tabs={[{ id: 'roles' as const, label: t('roles.tab.roles') }, { id: 'permissions' as const, label: t('roles.tab.permissions') }]} value={tab} onChange={setTab} />
        {catalog.loading && !catalog.data ? <Loading /> : catalog.error || !catalog.data ? <ErrorState error={catalog.error} onRetry={catalog.reload} /> : tab === 'roles' ? (
          catalog.data.roles.length === 0 ? <EmptyState title={t('roles.empty')} /> : (
            <DataTable
              caption={t('roles.table_caption')}
              rows={catalog.data.roles}
              rowKey={(r) => r.id}
              columns={[
                { header: t('roles.col.name'), primary: true, cell: (r) => <><strong>{r.name}</strong>{r.description && <div className="muted">{r.description}</div>}</> },
                { header: t('roles.col.kind'), cell: (r) => (r.template ? <Badge tone="info">{t('roles.kind.template')}</Badge> : <Badge>{t('roles.kind.custom')}</Badge>) },
                { header: t('roles.col.permissions'), align: 'right', cell: (r) => r.permission_count },
                { header: t('roles.col.actions'), actions: true, cell: (r) => (
                  <div className="actions">
                    <Button size="sm" onClick={() => setEditing(r)}>{r.template || !r.editable || !canManage ? t('common.view') : t('common.edit')}</Button>
                    {canManage && r.editable && !r.template && <Button size="sm" variant="danger" onClick={() => setRemoving(r)}>{t('common.delete')}</Button>}
                  </div>
                ) },
              ]}
            />
          )
        ) : (
          <div className="card-body">
            <p className="muted" style={{ marginBottom: 12 }}>{t('roles.permissions.intro')}</p>
            <PermissionCatalogPicker permissions={catalog.data.permissions} selected={new Set()} readOnly />
          </div>
        )}
      </Card>

      {editing && catalog.data && (
        <RoleEditor
          role={editing === 'new' ? null : editing}
          permissions={catalog.data.permissions}
          readOnly={!canManage || (editing !== 'new' && (editing.template || !editing.editable))}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); catalog.reload(); toast.success(t('roles.toast.saved')) }}
        />
      )}
      {removing && <RoleRemoval role={removing} onClose={() => setRemoving(null)} onDone={() => { setRemoving(null); catalog.reload(); toast.success(t('roles.toast.deleted')) }} />}
    </>
  )
}

function RoleEditor({ role, permissions, readOnly, onClose, onSaved }: { role: DelegatedRole | null; permissions: CatalogPermission[]; readOnly: boolean; onClose: () => void; onSaved: () => void }) {
  const { t } = useI18n()
  const [name, setName] = useState(role?.name ?? '')
  const [description, setDescription] = useState(role?.description ?? '')
  const [selected, setSelected] = useState(new Set(role?.permissions ?? []))
  const { busy, error, run } = useAction()

  async function save() {
    const body = { name: name.trim(), description: description.trim() || null, permissions: [...selected] }
    const result = await run(() => (role ? api.put(`/app/delegation/roles/${role.id}`, body) : api.post('/app/delegation/roles', body)))
    if (result.ok) onSaved()
  }

  if (readOnly) {
    return (
      <FormModal title={role?.name ?? t('roles.editor.new')} wide submitLabel={t('common.close')} onSubmit={onClose} onClose={onClose}>
        <Banner tone="info">{role?.template ? t('roles.editor.readonly_template') : t('roles.editor.readonly')}</Banner>
        <PermissionCatalogPicker permissions={permissions.filter((p) => selected.has(p.code))} selected={selected} readOnly />
      </FormModal>
    )
  }

  return (
    <FormModal title={role ? t('roles.editor.edit', { name: role.name }) : t('roles.editor.new')} wide busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <div className="form-grid">
        <Field label={t('roles.editor.name')} error={fieldError(error, 'name')}>{(p) => <input className="input" required minLength={2} maxLength={120} value={name} onChange={(e) => setName(e.target.value)} {...p} />}</Field>
        <Field label={t('roles.editor.description')} error={fieldError(error, 'description')}>{(p) => <input className="input" maxLength={500} value={description} onChange={(e) => setDescription(e.target.value)} {...p} />}</Field>
      </div>
      <div className="field">
        <span className="label">{t('roles.editor.permissions', { count: selected.size })}</span>
        <span className="hint">{t('roles.editor.hint')}</span>
        {fieldError(error, 'permissions') && <span className="error">{fieldError(error, 'permissions')}</span>}
      </div>
      <PermissionCatalogPicker permissions={permissions} selected={selected} onChange={setSelected} />
    </FormModal>
  )
}

function RoleRemoval({ role, onClose, onDone }: { role: DelegatedRole; onClose: () => void; onDone: () => void }) {
  const { t } = useI18n()
  const { busy, error, run } = useAction()
  return (
    <ConfirmDialog
      title={t('roles.delete.title')}
      danger
      confirmLabel={t('common.delete')}
      busy={busy}
      error={error}
      message={t('roles.delete.body', { name: role.name })}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`/app/delegation/roles/${role.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
