import { api } from '../../lib/api'
import type { AccountingProfile } from '../../lib/accounting'
import { useCapabilities } from '../../lib/capabilities'
import { useResource } from '../../lib/hooks'
import { API, type Page } from '../../lib/operational'
import type { Currency } from './types'

/**
 * The functional currency of the accounting profile. Reading the profile needs `accounting.profile.view`; without it (or when the profile
 * is not saved yet) `profile` is null and `unavailable` says which of the two it was, so the page can explain instead of failing.
 */
export function useFunctionalCurrency() {
  const { can } = useCapabilities()
  const allowed = can('accounting.profile.view')
  const r = useResource(async () => (allowed ? (await api.get<{ data: AccountingProfile | null }>(`${API}/profile`)).data.data : null), [allowed])
  return { ...r, profile: r.data, allowed, unavailable: !allowed || r.error != null }
}

/**
 * Foreign currencies for a select box (up to 200, by code). Reading them needs `accounting.currency.view`; a user who may only read rates
 * gets none and the page falls back to typing the ISO code (`available` is false) instead of failing.
 */
export function useCurrencyOptions(status?: 'ACTIVE') {
  const { can } = useCapabilities()
  const allowed = can('accounting.currency.view')
  const r = useResource(async () => (allowed ? (await api.get<Page<Currency>>(`${API}/currencies`, { params: { per_page: 200, ...(status && { status }) } })).data.data : []), [allowed, status])
  return { ...r, currencies: r.data ?? [], available: allowed && r.error == null }
}
