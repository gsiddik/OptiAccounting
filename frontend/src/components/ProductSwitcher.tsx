import { useEffect, useRef, useState, type MouseEvent } from 'react'
import { useI18n } from '../i18n'
import { useAuth } from '../lib/auth'
import { navigation } from '../lib/navigation'
import { useProductContext, type Product } from '../lib/productContext'
import { Icon } from './Icon'
import { useToast } from './Toast'
import { Button } from './ui'

/**
 * Other products of the same organisation this person may open, exactly as OptiNexus reports them (the governance portal is never
 * among them). The list is asked again whenever the menu opens and before every switch, so a product that was unsubscribed or an
 * access that was withdrawn is not offered from an old list. When OptiNexus does not answer, nothing is guessed: the menu says so.
 */
export function ProductSwitcher() {
  const { t } = useI18n()
  const { endSession } = useAuth()
  const toast = useToast()
  const context = useProductContext(true, endSession)
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const root = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const close = (event: Event) => {
      if (event instanceof KeyboardEvent ? event.key === 'Escape' : !root.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', close)
    return () => {
      document.removeEventListener('mousedown', close)
      document.removeEventListener('keydown', close)
    }
  }, [open])

  if (context.status === 'loading' || context.status === 'hidden' || context.status === 'ended') return null
  if (context.status === 'ready' && context.products.length < 2) return null

  async function toggle() {
    const next = !open
    setOpen(next)
    if (next) void context.reload() // revalidate whenever the menu is opened
  }

  async function go(event: MouseEvent, product: Product) {
    event.preventDefault()
    if (!product.switch_url || busy) return
    setBusy(product.code)
    try {
      const answer = await context.reload() // the last word before leaving: is this still allowed and still listed?
      if (answer === null) {
        toast.error(t('switcher.cannot_switch'))
      } else if (answer.available && answer.allowed) {
        const target = answer.products.find((p) => p.code === product.code)
        if (target?.switch_url) navigation.go(target.switch_url)
        else toast.error(t('switcher.not_listed'))
      }
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="switcher" ref={root}>
      <Button size="sm" aria-haspopup="true" aria-expanded={open} aria-controls="product-menu" aria-label={t('switcher.menu')} onClick={() => void toggle()}>
        <Icon name="grid" /> <span className="hide-sm">{t('switcher.label')}</span>
      </Button>
      {open && (
        <div id="product-menu" className="switcher-menu" role="menu" aria-label={t('switcher.menu')}>
          {context.status === 'unavailable' ? (
            <div className="switcher-note" role="status">
              <span>{t('switcher.unavailable')}</span>
              <Button size="sm" onClick={() => void context.reload()}>{t('switcher.retry')}</Button>
            </div>
          ) : (
            context.products.map((p) =>
              p.current ? (
                <span key={p.code} role="menuitem" aria-current="true" className="switcher-item current">
                  <strong>{p.name}</strong>
                  <small>{t('switcher.current')}</small>
                </span>
              ) : p.switch_url ? (
                <a key={p.code} role="menuitem" className="switcher-item" href={p.switch_url} onClick={(e) => void go(e, p)} aria-busy={busy === p.code || undefined}>
                  <strong>{p.name}</strong>
                </a>
              ) : (
                <span key={p.code} role="menuitem" aria-disabled="true" className="switcher-item disabled">
                  <strong>{p.name}</strong>
                  <small>{t('switcher.no_link')}</small>
                </span>
              ),
            )
          )}
        </div>
      )}
    </div>
  )
}
