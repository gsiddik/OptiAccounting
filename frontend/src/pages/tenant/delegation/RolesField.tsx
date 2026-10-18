import { RoleChecklist } from '../../../components/RoleChecklist'
import { ErrorNotice, Loading } from '../../../components/ui'
import { useI18n } from '../../../i18n'
import { api } from '../../../lib/api'
import { useResource } from '../../../lib/hooks'
import type { Role } from '../../../lib/types'
import type { AssignableRole } from './types'

/** The roles OptiNexus offers for this application and organization (never a local list). */
function useAssignableRoles() {
  return useResource(async () => (await api.get<{ data: AssignableRole[] }>('/app/delegation/assignable-roles')).data.data, [])
}

/** The checklist wants the shape of a local role; OptiNexus roles only need an id, a name and a description there. */
const asChecklist = (roles: AssignableRole[]): Role[] => roles.map((r) => ({ id: r.id, name: r.name, description: r.description, is_system: r.template, permissions: [] }))

export function RolesField({ selected, onChange }: { selected: Set<string>; onChange: (next: Set<string>) => void }) {
  const { t } = useI18n()
  const roles = useAssignableRoles()
  if (roles.loading && !roles.data) return <Loading label={t('users.roles.loading')} />
  if (roles.error) return <ErrorNotice error={roles.error} onRetry={roles.reload} />
  return <RoleChecklist roles={asChecklist(roles.data ?? [])} selected={selected} onChange={onChange} legend={t('users.roles.legend')} />
}
