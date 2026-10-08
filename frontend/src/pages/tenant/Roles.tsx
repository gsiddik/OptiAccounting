import { useState } from 'react'
import { PermissionList, RolesPanel } from '../../components/RolesPanel'
import { Card, PageHeader, Tabs } from '../../components/ui'
import { useCapabilities } from '../../lib/capabilities'

type Tab = 'roles' | 'permissions'

export default function Roles() {
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
