import { LOCALES, useI18n, type Locale } from '../i18n'

const LOCALE_NAMES: Record<Locale, string> = { id: 'Bahasa Indonesia', en: 'English' }

/** Language switch for the top bar and the sign-in page. */
export function LocaleSwitcher({ className }: { className?: string }) {
  const { locale, t, setLocale: change } = useI18n()
  return (
    <>
      <label htmlFor="locale-switch" className="sr-only">{t('common.language')}</label>
      <select id="locale-switch" className={`select${className ? ` ${className}` : ''}`} style={{ width: 'auto' }} value={locale} onChange={(e) => change(e.target.value as Locale)}>
        {LOCALES.map((l) => (
          <option key={l} value={l} lang={l}>{LOCALE_NAMES[l]}</option>
        ))}
      </select>
    </>
  )
}
