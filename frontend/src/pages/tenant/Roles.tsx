import { useState } from 'react'
import { PermissionList, RolesPanel } from '../../components/RolesPanel'
import { Card, PageHeader, Tabs } from '../../components/ui'
import { useCapabilities } from '../../lib/capabilities'
import { DelegatedRoles } from './delegation/DelegatedRoles'

type Tab = 'roles' | 'permissions'

/** Settings > Roles: delegated to OptiNexus when it is the identity authority, local (legacy standalone) otherwise. */
export default function Roles() {
  const { managedExternally } = useCapabilities()
  return managedExternally ? <DelegatedRoles fallback={<LegacyRoles />} /> : <LegacyRoles />
}

function LegacyRoles() {
  const { can } = useCapabilities()
  const [tab, setTab] = useState<Tab>('roles')
  const tabs: { id: Tab; label: string }[] = [{ id: 'roles', label: 'Peran' }, ...(can('access.permission.view') ? [{ id: 'permissions' as const, label: 'Izin' }] : [])]

  return (
    <>
      <PageHeader title="Peran & Izin" description="Peran adalah kumpulan izin. Setiap permintaan diperiksa terhadap izin, bukan nama peran." />
      <Card flush>
        <Tabs tabs={tabs} value={tab} onChange={setTab} />
        {tab === 'roles' ? <RolesPanel base="/app" managePermission="access.role.manage" /> : <PermissionList base="/app" />}
      </Card>
    </>
  )
}
