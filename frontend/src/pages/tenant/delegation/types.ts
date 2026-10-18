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

/** An invitation exactly as OptiNexus describes it (contract 2b). The token never reaches this application, let alone the browser. */
export type InvitationStatus = 'PENDING' | 'ACCEPTED' | 'REVOKED' | 'EXPIRED'
export type DeliveryStatus = 'QUEUED' | 'SENT' | 'FAILED'
export type InvitationLocale = 'en' | 'id'

export type Invitation = {
  id: string
  email: string
  name: string
  status: InvitationStatus
  locale: InvitationLocale | null
  role_ids: string[]
  roles: { id: string; code: string; name: string }[]
  invited_by: { id: string; name: string } | null
  created_at: string | null
  expires_at: string | null
  last_sent_at: string | null
  send_count: number
  delivery: { status: DeliveryStatus | null; attempted_at: string | null; error_code: string | null }
  accepted_at: string | null
  revoked_at: string | null
  can_resend: boolean
  can_revoke: boolean
}

export type InvitationPage = { data: Invitation[]; meta: { pagination: { current_page: number; per_page: number; total: number; last_page: number } } }

export type PermissionState = 'AVAILABLE' | 'NOT_ENTITLED' | 'NOT_DELEGABLE'
export type CatalogPermission = { code: string; group: string; description: string; state: PermissionState; capability: string | null }
export type RoleCatalog = { permissions: CatalogPermission[]; roles: DelegatedRole[] }

export type DelegationStatus = { available: boolean; reason: string | null; mode: 'optinexus' | 'standalone' }
