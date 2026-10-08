import type { ReactNode } from 'react'
import { useCapabilities } from '../lib/capabilities'
import { Banner } from './ui'

/** Shown only when OptiNexus is the authority for what the page lists, so nobody looks for a button that is not there. */
export function ManagedNotice({ children }: { children: ReactNode }) {
  const { managedExternally } = useCapabilities()
  if (!managedExternally) return null
  return <Banner tone="info">{children}</Banner>
}
