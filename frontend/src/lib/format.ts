import { currentLocale } from '../i18n/core'

// Dates and numbers follow the language chosen in the top bar: Indonesian conventions for `id`, day-first English for `en`.
const TAGS = { id: 'id-ID', en: 'en-GB' } as const

const cache = new Map<string, Intl.DateTimeFormat | Intl.NumberFormat>()
function formatter<T extends Intl.DateTimeFormat | Intl.NumberFormat>(kind: string, make: (tag: string) => T): T {
  const tag = TAGS[currentLocale()]
  const key = `${kind}:${tag}`
  let found = cache.get(key)
  if (!found) {
    found = make(tag)
    cache.set(key, found)
  }
  return found as T
}

const dateFmt = () => formatter('date', (tag) => new Intl.DateTimeFormat(tag, { day: '2-digit', month: 'short', year: 'numeric' }))
const dateTimeFmt = () => formatter('datetime', (tag) => new Intl.DateTimeFormat(tag, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }))
const numberFmt = () => formatter('number', (tag) => new Intl.NumberFormat(tag))

/** Date-only strings (YYYY-MM-DD) are shown as written, never shifted by the browser time zone. */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const [y, m, d] = value.slice(0, 10).split('-').map(Number)
  return dateFmt().format(new Date(Date.UTC(y, m - 1, d, 12)))
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : dateTimeFmt().format(date)
}

export function formatNumber(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : numberFmt().format(value)
}

/** Today as YYYY-MM-DD in the given IANA time zone (the tenant's business date, not the browser's). */
export function todayIn(timeZone = 'Asia/Jakarta'): string {
  return new Intl.DateTimeFormat('sv-SE', { timeZone }).format(new Date())
}

/** Time zones offered when creating or editing a tenant (Indonesian zones plus UTC). */
export const TIMEZONES = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura', 'UTC']
