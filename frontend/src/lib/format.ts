const dateFmt = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
const dateTimeFmt = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
const numberFmt = new Intl.NumberFormat('id-ID')

/** Date-only strings (YYYY-MM-DD) are shown as written, never shifted by the browser time zone. */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const [y, m, d] = value.slice(0, 10).split('-').map(Number)
  return dateFmt.format(new Date(Date.UTC(y, m - 1, d, 12)))
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : dateTimeFmt.format(date)
}

export function formatNumber(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : numberFmt.format(value)
}

/** Today as YYYY-MM-DD in the given IANA time zone (the tenant's business date, not the browser's). */
export function todayIn(timeZone = 'Asia/Jakarta'): string {
  return new Intl.DateTimeFormat('sv-SE', { timeZone }).format(new Date())
}

/** Time zones offered when creating or editing a tenant (Indonesian zones plus UTC). */
export const TIMEZONES = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura', 'UTC']
