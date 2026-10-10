// Shapes of the multi-currency API (backend: Currency\Services\CurrencyService, ExchangeRateService, ExchangeRateResolver, FxSetupService).
// A rate is a decimal string with up to ten decimals exactly as the server returns it; it is only formatted for display and never computed here.

export type CurrencyStatus = 'ACTIVE' | 'INACTIVE'
export type RateType = 'SPOT' | 'DAILY' | 'MONTH_END' | 'MANUAL'
export const RATE_TYPES: RateType[] = ['SPOT', 'DAILY', 'MONTH_END', 'MANUAL']

/** A foreign currency of the tenant (the functional currency has no row). `in_use` is true once a rate or an invoice names it. */
export type Currency = {
  id: string
  code: string
  name: string
  symbol: string | null
  decimal_places: number
  status: CurrencyStatus
  in_use?: boolean
}

/** 1 unit of `from_currency` = `rate` units of `to_currency` (the functional currency) on `effective_date`. A rate is a fact: it is withdrawn, never edited. */
export type ExchangeRate = {
  id: string
  from_currency: string
  to_currency: string
  rate: string
  effective_date: string
  rate_type: RateType
  source: string | null
  notes: string | null
  status: CurrencyStatus
  in_use?: boolean
}

/** The rate the server would use for a document of that currency on a date. `rate_id` is null for the functional currency itself (rate 1). */
export type RateLookup = {
  currency: string
  functional_currency: string
  rate: string
  rate_id: string | null
  effective_date: string | null
  rate_type: RateType | null
  source: string | null
}

export type FxEventStatus = { event_type: string; name: string; ready: boolean; rule_code: string | null; effective_from: string | null }
export type FxSetup = { events: FxEventStatus[]; unmapped_roles: string[] }
export type FxDefaultsResult = { created: string[]; skipped: { event_type: string; reason: string }[] }
