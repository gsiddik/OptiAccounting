// Response shapes of the OptiEntry API (OA0). Kept deliberately close to the backend.

export type Scope = 'identity' | 'tenant' | 'platform'
export type Mode = 'FULL' | 'READ_ONLY' | 'NONE'

export type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number; per_page: number }

export type TenantRef = { id: string; code: string; name: string }
export type SessionUser = { id: string; name: string; email: string }

export type Me = {
  user: SessionUser
  scope: Scope
  tenant_id: string | null
  tenants: TenantRef[]
  platform_access: boolean
}

export type LoginResponse = Me & { token: string; expires_at: string | null; sso?: { logout_url: string | null } }

/** Which doors the login page offers (public endpoint; no secrets). */
export type SsoStatus = { identity_mode: 'standalone' | 'optinexus'; sso_enabled: boolean; password_login: boolean }

/** Who owns users, roles and subscriptions in this installation. */
export type IdentityInfo = { mode: 'standalone' | 'optinexus'; managed_externally: boolean }

export type TenantStatus = 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'INACTIVE' | 'TERMINATED'

export type Tenant = {
  id: string
  code: string
  name: string
  legal_name: string | null
  status: TenantStatus
  timezone: string
  default_locale: string
  default_currency: string
  contact_name: string | null
  contact_email: string | null
  contact_phone: string | null
  created_at: string
}

export type SubscriptionStatus = 'PENDING' | 'ACTIVE' | 'PAST_DUE' | 'SUSPENDED' | 'EXPIRED' | 'CANCELLED'

export type Subscription = {
  id: string
  tenant_id: string
  status: SubscriptionStatus
  starts_on: string
  ends_on: string | null
  notes: string | null
  bundle: { id: string; code: string; name: string } | null
}

export type EffectiveSubscription = { status: string | null; mode: Mode; starts_on: string | null; ends_on: string | null }

export type Capabilities =
  | { scope: 'platform'; permissions: string[]; identity: IdentityInfo }
  | {
      scope: 'tenant'
      identity: IdentityInfo
      tenant: { id: string; code: string; name: string; status: TenantStatus; timezone: string; default_currency: string }
      permissions: string[]
      subscription: EffectiveSubscription
      modules: Record<string, Mode>
      features: Record<string, boolean>
      data_scope: { tenant: boolean; own: boolean; branch_ids: string[]; business_unit_ids: string[] }
      business_date: string
    }

export type Feature = { id: string; code: string; name: string; description: string | null; status: 'ACTIVE' | 'INACTIVE'; module_id: string; sort_order: number }

export type Module = {
  id: string
  code: string
  name: string
  description: string | null
  status: 'ACTIVE' | 'INACTIVE'
  commercially_available: boolean
  sort_order: number
  features: Feature[]
  requires: { id: string; code: string; name: string }[]
}

export type Bundle = {
  id: string
  code: string
  name: string
  description: string | null
  status: 'ACTIVE' | 'INACTIVE'
  modules: { id: string; code: string; name: string }[]
  capacities: { limit_code: string; limit_value: number | null }[]
}

export type ModuleEntitlementState = 'ACTIVE' | 'READ_ONLY' | 'SUSPENDED' | 'DISABLED'
export type EntitlementSource = 'BUNDLE' | 'ADD_ON' | 'CUSTOM_CONTRACT' | 'MANUAL_OVERRIDE' | 'OPTINEXUS'

export type ModuleEntitlement = {
  id: string
  state: ModuleEntitlementState
  source: EntitlementSource
  effective_from: string
  effective_until: string | null
  module: { id: string; code: string; name: string }
}

export type FeatureEntitlement = {
  id: string
  state: 'ACTIVE' | 'DISABLED'
  source: EntitlementSource
  effective_from: string
  effective_until: string | null
  feature: { id: string; code: string; name: string; module_id: string }
}

export type CapacityUsage = { code: string; limit: number | null; used: number; remaining: number | null }

export type TenantEntitlements = {
  effective: { date: string; subscription: EffectiveSubscription; modules: Record<string, { state: string; mode: Mode }>; features: Record<string, boolean> }
  modules: ModuleEntitlement[]
  features: FeatureEntitlement[]
  capacity: CapacityUsage[]
}

export type Branch = { id: string; code: string; name: string; address?: string | null; status: 'ACTIVE' | 'INACTIVE' }
export type BusinessUnit = Branch & { branch_id: string | null; branch: { id: string; code: string; name: string } | null }

export type Permission = { id: string; code: string; group: string; description: string }
export type Role = { id: string; name: string; description: string | null; is_system: boolean; permissions: { id: string; code: string }[] }

export type DataScopeRow = { scope_type: 'TENANT' | 'BRANCH' | 'BUSINESS_UNIT' | 'OWN'; branch_id?: string | null; business_unit_id?: string | null }

export type Member = {
  id: string
  status: 'INVITED' | 'ACTIVE' | 'SUSPENDED' | 'INACTIVE'
  joined_at: string | null
  user: { id: string; name: string; email: string; status: string }
  roles: { id: string; name: string }[]
  data_scopes?: (DataScopeRow & { id: string })[]
  dataScopes?: (DataScopeRow & { id: string })[]
}

export type PlatformUser = {
  id: string
  name: string
  email: string
  status: 'ACTIVE' | 'INACTIVE' | 'SUSPENDED'
  last_login_at: string | null
  platform_roles?: { id: string; name: string }[]
  platformRoles?: { id: string; name: string }[]
}

export type AuditEntry = {
  id: string
  tenant_id: string | null
  actor_user_id: string | null
  actor_scope: 'platform' | 'tenant' | 'system'
  action: string
  resource_type: string
  resource_id: string | null
  changes: { before: unknown; after: unknown } | null
  context: { request_id?: string; ip?: string } | null
  occurred_at: string
}
