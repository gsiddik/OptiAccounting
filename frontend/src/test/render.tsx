import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import App from '../App'
import { ToastProvider } from '../components/Toast'
import { AuthProvider } from '../lib/auth'

/** The real application (router guards, providers, pages) mounted at `path`. */
export function renderApp(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <ToastProvider>
        <AuthProvider>
          <App />
        </AuthProvider>
      </ToastProvider>
    </MemoryRouter>,
  )
}

export const platformMe = { user: { id: 'u-p', name: 'Admin Platform', email: 'platform.admin@demo.test' }, scope: 'platform', tenant_id: null, tenants: [], platform_access: true }

export const tenantMe = { user: { id: 'u-t', name: 'Budi Santoso', email: 'admin@majujaya.demo.test' }, scope: 'tenant', tenant_id: 't-1', tenants: [{ id: 't-1', code: 'maju-jaya', name: 'PT Maju Jaya' }], platform_access: false }

export const identityInfo = (mode: 'standalone' | 'optinexus' = 'standalone') => ({ mode, managed_externally: mode === 'optinexus' })

export function platformCaps(permissions: string[], mode: 'standalone' | 'optinexus' = 'standalone') {
  return { scope: 'platform', permissions, identity: identityInfo(mode) }
}

export function tenantCaps(over: { permissions?: string[]; subscriptionMode?: 'FULL' | 'READ_ONLY'; mode?: 'standalone' | 'optinexus' } = {}) {
  return {
    scope: 'tenant',
    identity: identityInfo(over.mode),
    tenant: { id: 't-1', code: 'maju-jaya', name: 'PT Maju Jaya', status: 'ACTIVE', timezone: 'Asia/Jakarta', default_currency: 'IDR' },
    permissions: over.permissions ?? [],
    subscription: { status: over.subscriptionMode === 'READ_ONLY' ? 'PAST_DUE' : 'ACTIVE', mode: over.subscriptionMode ?? 'FULL', starts_on: '2026-01-01', ends_on: '2026-12-31' },
    modules: { ACCOUNTING_CORE: over.subscriptionMode === 'READ_ONLY' ? 'READ_ONLY' : 'FULL' },
    features: {},
    data_scope: { tenant: true, own: false, branch_ids: [], business_unit_ids: [] },
    business_date: '2026-10-08',
  }
}
