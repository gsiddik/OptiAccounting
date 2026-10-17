import { useCallback, useSyncExternalStore } from 'react'
import { CATALOG, type MessageKey } from './catalog'
import { currentLocale, interpolate, setLocale, subscribeLocale, type Locale, type Params } from './core'

export { LOCALES, setLocale, currentLocale } from './core'
export type { Locale, Params } from './core'
export type { MessageKey } from './catalog'

/** Translate outside React (error mapping, labels). Inside components prefer `useI18n().t`, which also re-renders on a language change. */
export function translate(key: MessageKey, params?: Params): string {
  return interpolate(CATALOG[key][currentLocale()], params)
}

/** True when the catalog has this key (used for server codes, which are not known at compile time). */
export function hasMessage(key: string): key is MessageKey {
  return Object.prototype.hasOwnProperty.call(CATALOG, key)
}

/** A shell label (menu, breadcrumb) in the chosen language when the catalog has it (`nav.<Indonesian label>`), else as written. */
export function navLabel(text: string): string {
  const key = `nav.${text}`
  return hasMessage(key) ? translate(key) : text
}

export function useI18n() {
  const locale = useSyncExternalStore(subscribeLocale, currentLocale, currentLocale)
  const t = useCallback((key: MessageKey, params?: Params) => interpolate(CATALOG[key][locale], params), [locale])
  return { locale, t, setLocale: (next: Locale) => setLocale(next) }
}
