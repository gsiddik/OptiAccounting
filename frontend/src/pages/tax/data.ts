import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { useResource } from '../../lib/hooks'
import { API, type Page } from '../../lib/operational'
import type { TaxCode } from './types'

/**
 * Tax codes for a select box (up to 200, by code). Reading them needs `accounting.tax.view`; a user who may only read the tax report (or whose plan has
 * no tax configuration feature) gets none and the page hides the selector (`available` is false) instead of failing.
 */
export function useTaxCodeOptions(status?: 'ACTIVE') {
  const { can, featureEnabled } = useCapabilities()
  const allowed = can('accounting.tax.view') && featureEnabled('TAX_CONFIGURATION')
  const r = useResource(async () => (allowed ? (await api.get<Page<TaxCode>>(`${API}/tax-codes`, { params: { per_page: 200, ...(status && { status }) } })).data.data : []), [allowed, status])
  return { ...r, codes: r.data ?? [], available: allowed && r.error == null }
}

export type RoleOption = { code: string; name: string; binding?: string }

/**
 * Account roles a tax code may name, read from the API's role catalog (it needs the permission to view account mappings). Only roles
 * resolved through a tenant mapping qualify; whether a role may be used is decided by the API when the code is saved.
 */
export function useTaxRoleOptions(): RoleOption[] {
  const { can } = useCapabilities()
  const allowed = can('accounting.account_mapping.view')
  const roles = useResource(async () => (allowed ? (await api.get<{ roles: RoleOption[] }>(`${API}/account-mappings`)).data.roles : []), [allowed])
  return (roles.data ?? []).filter((r) => r.binding === undefined || r.binding === 'MAPPED')
}
