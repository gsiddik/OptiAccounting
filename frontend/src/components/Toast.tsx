import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'

type Toast = { id: number; text: string; tone: 'ok' | 'bad' }
type ToastApi = { success: (text: string) => void; error: (text: string) => void }

const ToastContext = createContext<ToastApi | null>(null)

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Toast[]>([])

  const push = useCallback((text: string, tone: Toast['tone']) => {
    const id = Date.now() + Math.random()
    setItems((list) => [...list, { id, text, tone }])
    setTimeout(() => setItems((list) => list.filter((t) => t.id !== id)), 4500)
  }, [])

  const api = useMemo<ToastApi>(() => ({ success: (t) => push(t, 'ok'), error: (t) => push(t, 'bad') }), [push])

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="toasts" aria-live="polite">
        {items.map((t) => (
          <div key={t.id} className={`toast${t.tone === 'bad' ? ' bad' : ''}`} role="status">
            {t.text}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast(): ToastApi {
  const value = useContext(ToastContext)
  if (!value) throw new Error('useToast must be used inside ToastProvider')
  return value
}
