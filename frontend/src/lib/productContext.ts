import { useCallback, useEffect, useRef, useState } from 'react'
import { api, ApiError } from './api'

/** `GET /app/product-context`: the products this person may open from here, built by the API from OptiNexus' answer (audit A29). */
export type Product = { code: string; name: string; current: boolean; launch_url: string | null; switch_url: string | null }

export type ProductContext =
  | { available: false; reason: string; session_ended: false }
  | { available: true; allowed: true; session_ended: false; products: Product[]; current: string; ttl_seconds: number; expires_at: string; source: 'nexus' | 'cache' }
  | { available: true; allowed: false; session_ended: true; deny_reason: string; products: [] }

export type ProductContextState =
  | { status: 'loading' }
  | { status: 'hidden' } // standalone, not linked, or an OptiNexus that does not offer it
  | { status: 'ready'; products: Product[] }
  | { status: 'unavailable'; error: unknown } // OptiNexus did not answer: no list, nothing guessed from an old one
  | { status: 'ended'; reason: string }

const MIN_POLL_MS = 30_000
const MAX_POLL_MS = 300_000

/**
 * Keeps the switcher's list fresh and the session honest: asks on mount, when the tab becomes visible again and every `ttl_seconds`
 * (never more than 5 minutes; the API serves a cached answer inside that time, so this is cheap). `onEnded` runs once when OptiNexus
 * says this person may no longer be here; the API has already ended the session.
 */
export function useProductContext(enabled: boolean, onEnded: (reason: string) => void): ProductContextState & { reload: () => Promise<ProductContext | null> } {
  const [state, setState] = useState<ProductContextState>({ status: 'loading' })
  const ended = useRef(onEnded)
  ended.current = onEnded
  const done = useRef(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const load = useCallback(async (): Promise<ProductContext | null> => {
    try {
      const { data } = await api.get<ProductContext>('/app/product-context')
      if (data.available === false) {
        setState({ status: 'hidden' })
        return data
      }
      if (data.allowed === false) {
        if (!done.current) {
          done.current = true
          setState({ status: 'ended', reason: data.deny_reason })
          ended.current(data.deny_reason)
        }
        return data
      }
      setState({ status: 'ready', products: data.products })
      return data
    } catch (error) {
      // A 401 is the session ending (the api client already signed out); anything else is "could not ask", which hides nothing it knew.
      if (!(error instanceof ApiError && error.status === 401)) setState({ status: 'unavailable', error })
      return null
    }
  }, [])

  useEffect(() => {
    if (!enabled) return
    let alive = true
    const tick = async () => {
      const answer = await load()
      if (!alive || done.current) return
      const ttl = answer && answer.available && answer.allowed ? answer.ttl_seconds * 1000 : MIN_POLL_MS
      timer.current = setTimeout(() => void tick(), Math.min(MAX_POLL_MS, Math.max(MIN_POLL_MS, ttl)))
    }
    void tick()
    const onVisible = () => {
      if (document.visibilityState === 'visible' && !done.current) void load()
    }
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      alive = false
      if (timer.current) clearTimeout(timer.current)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [enabled, load])

  return { ...state, reload: load }
}
