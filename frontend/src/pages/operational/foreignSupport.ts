import { formatAmount } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { useDebounced, useResource } from '../../lib/hooks'
import { API, MODULES, type Page } from '../../lib/operational'
import type { ForeignFields } from './payables/types'

// Foreign-currency support of the invoice, payment and receipt screens (OA4 multi-currency): types, request building and hooks. Everything here is
// presentation and request building; the rate, the functional amount and the realised exchange difference are chosen and computed by the server.
// A tenant without the module (or a user without accounting.currency.view) never reaches a currencies endpoint. The components live in foreign.tsx.

/** The exchange-rates page, where a missing rate is entered. */
export const EXCHANGE_RATES_PATH = '/app/akuntansi/kurs'

export type CurrencyOption = { id: string; code: string; name: string; symbol: string | null; decimal_places: number; status: 'ACTIVE' | 'INACTIVE' }

/** What the rate lookup returns (GET exchange-rates/lookup): the rate the server would use for a document of that currency on that date. */
export type RateLookup = { currency: string; functional_currency: string; rate: string; rate_id: string; effective_date: string; rate_type: string; source: string | null }

/** The form's currency choice: '' means the functional currency (the API default); `exchange_rate_type` '' means "let the server choose". */
export type CurrencyChoice = { currency: string; exchange_rate_type: string }

/** True when the stored document is in a foreign currency: it cites a rate and carries a functional amount (a functional document has neither). */
export function isForeignDoc(doc: ForeignFields | null | undefined): boolean {
  return !!doc && (doc.exchange_rate_id != null || doc.functional_total_amount != null || doc.functional_amount != null)
}

/** The state a document form starts with: a foreign document keeps its currency and the rate type of the rate it used. */
export function currencyChoiceFrom(doc: ForeignFields & { currency?: string } | null): CurrencyChoice {
  return doc && isForeignDoc(doc) ? { currency: doc.currency ?? '', exchange_rate_type: doc.exchange_rate_type ?? '' } : { currency: '', exchange_rate_type: '' }
}

/** A rate as a string with Indonesian grouping and its significant decimals ("15800.0000000000" -> "15.800,00"). */
export function formatRate(rate: string | null | undefined): string {
  return formatAmount(rate)
}

/** The currency fields of the request: nothing at all for a functional document that was never foreign, so the payload of a single-currency tenant is unchanged. */
export function currencyPayload(choice: CurrencyChoice, options: { shown: boolean; wasForeign: boolean; functional: string }): { currency?: string; exchange_rate_type?: string | null } {
  if (choice.currency) return { currency: choice.currency, exchange_rate_type: choice.exchange_rate_type || null }
  // A draft that was foreign and is now functional must say so: leaving the currency out would keep the old one.
  if (options.shown && options.wasForeign) return { currency: options.functional, exchange_rate_type: null }
  return {}
}

/** Decimal places of a typed amount (comma or point, trailing zeros ignored), or null when the text is not a plain decimal. */
export function typedDecimals(text: string): number | null {
  const m = /^\d+(?:[.,](\d+))?$/.exec(text.trim())
  if (!m) return null
  return (m[1] ?? '').replace(/0+$/, '').length
}

/** "Dalam USD, 2 desimal." and, when the typed amount has more decimals than the currency allows, a warning. A hint only: the server validates. */
export function placesHint(fx: { code: string; places: number } | null, text: string): string | undefined {
  if (!fx) return undefined
  const typed = typedDecimals(text)
  if (typed !== null && typed > fx.places) return `${fx.code} memakai ${fx.places} desimal; jumlah ini memiliki lebih banyak dan akan ditolak server.`
  return `Dalam ${fx.code}, ${fx.places} desimal.`
}

/** Which side of the books the document is: it decides whether a positive exchange difference is a loss (payables) or a gain (receivables). */
export type FxKind = 'AP' | 'AR'

/** "Laba selisih kurs" / "Rugi selisih kurs" from the sign of the server's `fx_difference` (positive = loss for payables, gain for receivables). */
export function fxDifferenceLabel(value: string, kind: FxKind): string {
  if (/^-?0+(\.0+)?$/.test(value)) return 'Selisih kurs'
  const positive = !value.startsWith('-')
  const gain = kind === 'AR' ? positive : !positive
  return gain ? 'Laba selisih kurs' : 'Rugi selisih kurs'
}

/** Whether a row of a report (aging) is in a foreign currency: its rate is not 1 (the server sends 1 for a functional document) or, with no rate, its currency is not the functional one. */
export function isForeignRow(row: { currency?: string | null; exchange_rate?: string | null }, functional: string): boolean {
  if (row.exchange_rate != null && row.exchange_rate !== '') return !/^1(\.0+)?$/.test(row.exchange_rate)
  return !!row.currency && row.currency !== functional
}

// ------------------------------------------------------------------------------------------------ hooks

export type ForeignSupport = {
  /** The currency controls show: the module is entitled (not NONE), the feature is on and the user holds accounting.currency.view. */
  visible: boolean
  /** A foreign document can be saved: the module is writable. */
  writable: boolean
  /** The functional currency, from the accounting profile when the user may read it, else the tenant default. */
  functional: string
  currencies: CurrencyOption[]
  loading: boolean
  /** Decimal places of a currency the form may use (null when it is not known). */
  placesOf: (code: string) => number | null
}

/**
 * Currency options for a document form. Nothing is requested unless the controls show, so a single-currency organisation (module not
 * entitled) or a user without the permission makes no call to the currencies endpoints.
 */
export function useForeignSupport(): ForeignSupport {
  const { can, moduleMode, featureEnabled, tenant } = useCapabilities()
  const mode = moduleMode(MODULES.multiCurrency)
  const visible = mode !== 'NONE' && featureEnabled('EXCHANGE_RATE') && can('accounting.currency.view')
  const readsProfile = visible && can('accounting.profile.view')

  const list = useResource(async () => (visible ? (await api.get<Page<CurrencyOption>>(`${API}/currencies`, { params: { status: 'ACTIVE', per_page: 200 } })).data.data : []), [visible])
  const profile = useResource(async () => {
    if (!readsProfile) return null
    try {
      return (await api.get<{ data: { functional_currency: string } | null }>(`${API}/profile`)).data.data?.functional_currency ?? null
    } catch {
      return null // the tenant default below is good enough for a label
    }
  }, [readsProfile])

  const currencies = list.data ?? []
  const functional = profile.data ?? tenant?.tenant.default_currency ?? 'IDR'
  return {
    visible,
    writable: visible && mode === 'FULL',
    functional,
    currencies,
    loading: list.loading,
    placesOf: (code) => currencies.find((c) => c.code === code)?.decimal_places ?? null,
  }
}

/** The rate the server would use now, for the "Kurs yang akan dipakai" line. Needs accounting.exchange_rate.view; without it nothing is requested and the server decides alone. */
export function useRateLookup(currency: string, date: string, type: string) {
  const { can } = useCapabilities()
  const allowed = can('accounting.exchange_rate.view')
  const day = useDebounced(date)
  const active = allowed && currency !== '' && /^\d{4}-\d{2}-\d{2}$/.test(day)
  const lookup = useResource(async () => (active ? (await api.get<RateLookup>(`${API}/exchange-rates/lookup`, { params: { currency, date: day, ...(type && { rate_type: type }) } })).data : null), [active, currency, day, type])
  return { allowed, active, ...lookup }
}
