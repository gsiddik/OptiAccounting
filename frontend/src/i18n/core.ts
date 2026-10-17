// Message catalog plumbing for the screens that are available in English and Indonesian. The rest of the console is still
// Indonesian-only (docs/status/ECOSYSTEM_AUDIT_STATUS.md lists what is translated); a screen moves over by using `t()`.

export type Locale = 'id' | 'en'
export const LOCALES: readonly Locale[] = ['id', 'en']
export const DEFAULT_LOCALE: Locale = 'id'

/** One entry carries both languages, so a key can never exist in one language only (checked by the compiler and by i18n.test.ts). */
export type Message = { id: string; en: string }
export type Messages = Record<string, Message>

export const defineMessages = <T extends Messages>(messages: T): T => messages

const STORAGE_KEY = 'oa.locale'

function readStored(): Locale | null {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    return value === 'id' || value === 'en' ? value : null
  } catch {
    return null
  }
}

let current: Locale = readStored() ?? DEFAULT_LOCALE
const listeners = new Set<() => void>()

export const currentLocale = (): Locale => current

export function setLocale(next: Locale): void {
  if (next === current) return
  current = next
  try {
    localStorage.setItem(STORAGE_KEY, next)
  } catch {
    /* storage blocked: the choice lasts until reload */
  }
  if (typeof document !== 'undefined') document.documentElement.lang = next
  listeners.forEach((listener) => listener())
}

export function subscribeLocale(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export type Params = Record<string, string | number | null | undefined>

export function interpolate(template: string, params?: Params): string {
  if (!params) return template
  return template.replace(/\{(\w+)\}/g, (whole, name: string) => (params[name] === undefined || params[name] === null ? whole : String(params[name])))
}
