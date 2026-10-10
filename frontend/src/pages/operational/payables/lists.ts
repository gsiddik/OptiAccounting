import { useCapabilities } from '../../../lib/capabilities'
import { todayIn } from '../../../lib/format'
import { api } from '../../../lib/api'
import { useResource } from '../../../lib/hooks'
import { API, listParams } from '../../../lib/operational'

type Scalar = string | number | boolean

/**
 * Query parameters of a filtered list. Empty values are dropped, and a checked box is sent as `1`: the API validates its flags with
 * Laravel's `boolean` rule, which accepts 1 / 0 but rejects the text "true" that a JavaScript boolean would be serialized to.
 */
export function listQuery(filter: Record<string, Scalar>, page: number): Record<string, string | number> {
  return flagsAsNumbers(listParams(filter, page))
}

/** The same filter without the page, for the CSV export (which applies the filter to every row). */
export function exportQuery(filter: Record<string, Scalar>): Record<string, string | number> {
  const params = listQuery(filter, 1)
  delete params.page
  return params
}

function flagsAsNumbers(params: Record<string, Scalar>): Record<string, string | number> {
  return Object.fromEntries(Object.entries(params).map(([k, v]) => [k, typeof v === 'boolean' ? 1 : v]))
}

/** A whole number of days between `min` and `max` as text, or '' when the input is not one (so a half-typed value is never sent). */
export function boundedInteger(input: string, min: number, max: number): string {
  const text = input.trim()
  if (!/^\d{1,5}$/.test(text)) return ''
  const n = Number(text)
  return n >= min && n <= max ? String(n) : ''
}

/** The tenant's business date (YYYY-MM-DD), the date a new document and a report default to. */
export function useBusinessDate(): string {
  const { tenant } = useCapabilities()
  return tenant?.business_date ?? todayIn()
}

/** Account roles the user may classify an invoice line to. Best effort: the list needs the account-mapping permission, so a refusal yields none. */
export function useLineRoles() {
  const resource = useResource(async () => {
    try {
      return (await api.get<{ roles: { code: string; name: string; mapped: boolean }[] }>(`${API}/account-mappings`)).data.roles
    } catch {
      return []
    }
  }, [])
  return resource.data ?? []
}

/** Roles that belong to a subledger or to cash and bank are refused as a line destination by the API; they are not offered. */
const NOT_A_DESTINATION = ['ACCOUNTS_PAYABLE', 'ACCOUNTS_RECEIVABLE', 'CASH', 'BANK', 'RETAINED_EARNINGS', 'CASH_BANK_ACCOUNT']

export function destinationRoles(roles: { code: string; name: string; mapped: boolean }[]) {
  return roles.filter((r) => r.mapped && !NOT_A_DESTINATION.includes(r.code))
}
