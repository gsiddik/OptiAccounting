import { useCallback, useEffect, useRef, useState } from 'react'

export type Resource<T> = {
  data: T | null
  error: unknown
  loading: boolean
  reload: () => void
  setData: (updater: (current: T) => T) => void
}

/** Load data from the API; reloads when `deps` change or `reload()` is called. Stale responses are ignored. */
export function useResource<T>(load: () => Promise<T>, deps: readonly unknown[]): Resource<T> {
  const [state, setState] = useState<{ data: T | null; error: unknown; loading: boolean }>({ data: null, error: null, loading: true })
  const [tick, setTick] = useState(0)
  const loader = useRef(load)
  loader.current = load

  useEffect(() => {
    let alive = true
    setState((s) => ({ ...s, loading: true, error: null }))
    loader
      .current()
      .then((data) => alive && setState({ data, error: null, loading: false }))
      .catch((error: unknown) => alive && setState({ data: null, error, loading: false }))
    return () => {
      alive = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick])

  const reload = useCallback(() => setTick((t) => t + 1), [])
  const setData = useCallback((updater: (current: T) => T) => setState((s) => (s.data === null ? s : { ...s, data: updater(s.data) })), [])

  return { ...state, reload, setData }
}

/** Run a mutation with a busy flag and the last error; never throws to the caller. */
export function useAction() {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<unknown>(null)

  const run = useCallback(async <A extends () => Promise<unknown>>(action: A): Promise<{ ok: true; value: Awaited<ReturnType<A>> } | { ok: false }> => {
    setBusy(true)
    setError(null)
    try {
      return { ok: true as const, value: (await action()) as Awaited<ReturnType<A>> }
    } catch (e) {
      setError(e)
      return { ok: false as const }
    } finally {
      setBusy(false)
    }
  }, [])

  return { busy, error, run, clearError: useCallback(() => setError(null), []) }
}

/** Debounced copy of a value (search boxes). */
export function useDebounced<T>(value: T, ms = 300): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), ms)
    return () => clearTimeout(id)
  }, [value, ms])
  return debounced
}
