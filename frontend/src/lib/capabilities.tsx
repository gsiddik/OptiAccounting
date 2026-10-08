import { createContext, useContext, useMemo, type ReactNode } from 'react'
import { api } from './api'
import { useResource } from './hooks'
import type { Capabilities, Mode } from './types'

type TenantCaps = Extract<Capabilities, { scope: 'tenant' }>

type CapabilityValue = {
  loading: boolean
  failed: boolean
  caps: Capabilities | null
  tenant: TenantCaps | null
  /** True when the permission code was granted to the user. Cosmetic only: the API re-checks every request. */
  can: (permission: string) => boolean
  moduleMode: (code: string) => Mode
  featureEnabled: (code: string) => boolean
  /** The subscription allows reading but not changing accounting data. */
  readOnly: boolean
}

const CapabilityContext = createContext<CapabilityValue | null>(null)

/**
 * The one place the UI learns what the signed-in user may see. Navigation, buttons and pages ask
 * `can('resource.action')`; they never look at role names. Built from the same resolver data as the API gate.
 */
export function CapabilityProvider({ scope, tenantId, children }: { scope: 'tenant' | 'platform'; tenantId: string | null; children: ReactNode }) {
  const { data, loading, error } = useResource(
    async () => (await api.get<Capabilities>(scope === 'tenant' ? '/app/capabilities' : '/platform/capabilities')).data,
    [scope, tenantId],
  )

  const value = useMemo<CapabilityValue>(() => {
    const granted = new Set(data?.permissions ?? [])
    const tenant = data?.scope === 'tenant' ? data : null
    return {
      loading,
      failed: error !== null,
      caps: data,
      tenant,
      can: (permission) => granted.has(permission),
      moduleMode: (code) => tenant?.modules[code] ?? 'NONE',
      featureEnabled: (code) => tenant?.features[code] ?? false,
      readOnly: tenant?.subscription.mode === 'READ_ONLY',
    }
  }, [data, loading, error])

  return <CapabilityContext.Provider value={value}>{children}</CapabilityContext.Provider>
}

export function useCapabilities(): CapabilityValue {
  const value = useContext(CapabilityContext)
  if (!value) throw new Error('useCapabilities must be used inside CapabilityProvider')
  return value
}
