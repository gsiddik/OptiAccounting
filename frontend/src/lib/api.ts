import axios from 'axios'

// Single HTTP client for the SPA. The backend is authoritative for every
// authorization, entitlement and accounting calculation.
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
  headers: { Accept: 'application/json' },
})

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
