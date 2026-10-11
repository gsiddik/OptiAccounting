import { accountFilterParams, windowParams, type AccountFilter } from '../../accounting/ledger-params'
import type { Range } from '../../accounting/data'
import { PATHS } from './data'
import type { AccountBalanceReport, BalanceRow } from './types'

/** Everything the account balance filters hold. `mode` decides whether the report is dated (as of a day) or covers a range. */
export type BalanceState = {
  mode: 'AS_OF' | 'PERIOD'
  asOf: string
  range: Range
  year: string
  account: string
  acc: AccountFilter
  dims: { branch_id: string; business_unit_id: string; cost_center_id: string }
  hierarchy: boolean
  includeZero: boolean
}

/** The query of the account balance report and of its export (the same filters, so the file equals the screen). Empty values are never sent; flags are sent as 1 / 0. */
export function balanceParams(s: BalanceState): Record<string, string | number> {
  const out: Record<string, string | number> = s.mode === 'AS_OF' ? (s.asOf ? { as_of: s.asOf } : {}) : windowParams(s.range, s.year)
  Object.assign(out, accountFilterParams(s.acc))
  if (s.account) out.account_id = s.account
  for (const [k, v] of Object.entries(s.dims)) if (v) out[k] = v
  out.hierarchy = s.hierarchy ? 1 : 0
  out.include_zero = s.includeZero ? 1 : 0
  return out
}

/**
 * The general ledger page for one account of the report. A dated balance links with its date as the end of the window (the ledger starts at the
 * fiscal year of that date and its opening balance is cumulative); a range links with its own dates. The dimensions travel along.
 */
export function ledgerLink(report: AccountBalanceReport, row: BalanceRow, s: BalanceState): string {
  const query = new URLSearchParams({ account_id: row.account_id })
  if (report.mode === 'AS_OF' && report.as_of) query.set('to', report.as_of)
  else if (report.range) {
    query.set('from', report.range.from)
    query.set('to', report.range.to)
  }
  for (const [k, v] of Object.entries(s.dims)) if (v) query.set(k, v)
  return `${PATHS.ledger}?${query.toString()}`
}
