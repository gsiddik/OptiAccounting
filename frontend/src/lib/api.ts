import axios, { isAxiosError } from 'axios'

// Single HTTP client for the SPA. The backend is authoritative for every
// authorization, entitlement and accounting decision; the UI only reflects it.
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
  headers: { Accept: 'application/json' },
})

const TOKEN_KEY = 'oa.token'

/** Bearer token for this browser tab only (sessionStorage); it never outlives the tab and is never sent to another origin. */
export const tokenStore = {
  get(): string | null {
    try {
      return sessionStorage.getItem(TOKEN_KEY)
    } catch {
      return null
    }
  },
  set(token: string): void {
    try {
      sessionStorage.setItem(TOKEN_KEY, token)
    } catch {
      /* storage blocked: the session lives until reload */
    }
  },
  clear(): void {
    try {
      sessionStorage.removeItem(TOKEN_KEY)
    } catch {
      /* ignore */
    }
  },
}

let memoryToken: string | null = null
export function setToken(token: string | null): void {
  memoryToken = token
  if (token) tokenStore.set(token)
  else tokenStore.clear()
}
export function currentToken(): string | null {
  return memoryToken ?? tokenStore.get()
}

export class ApiError extends Error {
  status: number
  code: string | null
  details: Record<string, unknown>
  fields: Record<string, string[]>

  constructor(status: number, message: string, code: string | null, details: Record<string, unknown>, fields: Record<string, string[]>) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.details = details
    this.fields = fields
  }
}

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(handler: (() => void) | null): void {
  onUnauthorized = handler
}

api.interceptors.request.use((config) => {
  const token = currentToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    if (isAxiosError(error)) {
      const status = error.response?.status ?? 0
      const body = (error.response?.data ?? {}) as {
        message?: string
        code?: string
        details?: Record<string, unknown>
        errors?: Record<string, string[]>
      }
      if (status === 401 && currentToken()) {
        setToken(null)
        onUnauthorized?.()
      }
      return Promise.reject(new ApiError(status, body.message ?? error.message, body.code ?? null, body.details ?? {}, body.errors ?? {}))
    }
    return Promise.reject(error)
  },
)

export type Health = {
  service: string
  api_version: string
  identity_mode: 'standalone' | 'optinexus'
  checks: { database: 'ok' | 'unavailable' }
}

export async function fetchHealth(): Promise<Health> {
  const { data } = await api.get<Health>('/health')
  return data
}
