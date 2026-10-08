import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, currentToken, setToken, setUnauthorizedHandler } from './api'
import type { LoginResponse, Me } from './types'

type AuthState = { status: 'loading' } | { status: 'anonymous' } | { status: 'ready'; me: Me }

export type EnterTarget = { kind: 'tenant'; tenantId: string } | { kind: 'platform' }

type AuthValue = {
  state: AuthState
  login: (email: string, password: string) => Promise<Me>
  enter: (target: EnterTarget) => Promise<Me>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthValue | null>(null)

/**
 * Who is signed in and in which scope. The scope and tenant come from the server (token abilities);
 * the SPA never decides them and never sends a tenant id except to ask to switch (the server verifies membership).
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>(() => (currentToken() ? { status: 'loading' } : { status: 'anonymous' }))

  useEffect(() => {
    setUnauthorizedHandler(() => setState({ status: 'anonymous' }))
    return () => setUnauthorizedHandler(null)
  }, [])

  useEffect(() => {
    if (!currentToken()) return
    let alive = true
    api
      .get<Me>('/auth/me')
      .then(({ data }) => alive && setState({ status: 'ready', me: data }))
      .catch(() => {
        setToken(null)
        if (alive) setState({ status: 'anonymous' })
      })
    return () => {
      alive = false
    }
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const { data } = await api.post<LoginResponse>('/auth/login', { email, password })
    setToken(data.token)
    const me: Me = { user: data.user, scope: data.scope, tenant_id: data.tenant_id, tenants: data.tenants, platform_access: data.platform_access }
    setState({ status: 'ready', me })
    return me
  }, [])

  const enter = useCallback(async (target: EnterTarget) => {
    const { data } = target.kind === 'tenant'
      ? await api.post<{ token: string }>('/auth/switch-tenant', { tenant_id: target.tenantId })
      : await api.post<{ token: string }>('/auth/switch-platform')
    setToken(data.token)
    const me = (await api.get<Me>('/auth/me')).data
    setState({ status: 'ready', me })
    return me
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      /* the token may already be gone; clearing locally is what matters */
    }
    setToken(null)
    setState({ status: 'anonymous' })
  }, [])

  const value = useMemo(() => ({ state, login, enter, logout }), [state, login, enter, logout])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext)
  if (!value) throw new Error('useAuth must be used inside AuthProvider')
  return value
}

/** Where a session should land. */
export function homePath(me: Me): string {
  if (me.scope === 'platform') return '/platform'
  if (me.scope === 'tenant') return '/app'
  return '/pilih-akses'
}
