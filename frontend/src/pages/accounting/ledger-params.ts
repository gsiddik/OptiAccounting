import { rangeParams, type Range } from './data'

// Query parameters of the OA5 filters shared by the trial balance, the general ledger and the account balance report. Empty values are never sent.

/** Account selection filters: the type, a code range and (where the module is on) the transaction currency. */
export type AccountFilter = { account_type: string; account_code_from: string; account_code_to: string; currency: string }
export const NO_ACCOUNT_FILTER: AccountFilter = { account_type: '', account_code_from: '', account_code_to: '', currency: '' }

/**
 * The report window: a period alone (it names its own dates), or a fiscal year (whole, or narrowed by dates inside it), or plain dates.
 * A fiscal year is never sent together with a period: the API refuses that combination.
 */
export function windowParams(range: Range, fiscalYearId: string): Record<string, string> {
  if (range.period_id) return rangeParams(range)
  return { ...(fiscalYearId && { fiscal_year_id: fiscalYearId }), ...rangeParams(range) }
}

export function accountFilterParams(f: AccountFilter): Record<string, string> {
  const out: Record<string, string> = {}
  for (const [key, value] of Object.entries(f)) if (value.trim() !== '') out[key] = value.trim()
  return out
}

export const hasAccountFilter = (f: AccountFilter): boolean => Object.keys(accountFilterParams(f)).length > 0
