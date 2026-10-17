import type { DataScopeRow } from '../../../lib/types'

/** Shapes of /app/delegation/*: the answers of OptiNexus (docs/architecture/OPTINEXUS_ADAPTER.md §10) plus the local projection. */
export type DelegatedRole = {
  id: string
  code: string
  name: string
  description: string | null
  template: boolean
  editable: boolean
  permissions: string[]
  permission_count: number
}

export type AssignableRole = Pick<DelegatedRole, 'id' | 'code' | 'name' | 'description' | 'template'>

export type LocalMember = {
  member_id: string
  status: 'INVITED' | 'ACTIVE' | 'SUSPENDED' | 'INACTIVE'
  last_login_at: string | null
  data_scopes: (DataScopeRow & { id: string })[]
}

export type DelegatedUser = {
  id: string
  name: string
  email: string
  status: string | null
  membership_status: string | null
  is_tenant_admin: boolean
  application_access: string | null
  roles: DelegatedRole[]
  local: LocalMember | null
  is_self: boolean
}

export type Seats = { limit: number | null; used: number; remaining: number | null }

export type DelegatedUserPage = {
  data: DelegatedUser[]
  meta: { pagination: { current_page: number; per_page: number; total: number; last_page: number }; seats: Seats }
}

export type CreateOutcome = DelegatedUser & { outcome: 'created' | 'linked' | 'existing'; projection: 'CREATED' | 'REACTIVATED' | 'EXISTING' | 'DEFERRED' }

export type PermissionState = 'AVAILABLE' | 'NOT_ENTITLED' | 'NOT_DELEGABLE'
export type CatalogPermission = { code: string; group: string; description: string; state: PermissionState; capability: string | null }
export type RoleCatalog = { permissions: CatalogPermission[]; roles: DelegatedRole[] }

export type DelegationStatus = { available: boolean; reason: string | null; mode: 'optinexus' | 'standalone' }
