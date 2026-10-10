import { useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useLocation } from 'react-router-dom'
import { CrumbLabelContext } from '../lib/crumbContext'

/** Remembers the title each page announced for its own address, so the breadcrumb of a detail page can read as its document number. */
export function CrumbLabelProvider({ children }: { children: ReactNode }) {
  const [labels, setLabels] = useState<Record<string, string>>({})
  const announce = useCallback((pathname: string, label: string) => {
    setLabels((current) => (current[pathname] === label ? current : { ...current, [pathname]: label }))
  }, [])
  const value = useMemo(() => ({ labels, announce }), [labels, announce])
  return <CrumbLabelContext.Provider value={value}>{children}</CrumbLabelContext.Provider>
}

/** Rendered by `PageHeader`: the page title becomes the label of this address in the breadcrumb (used for `/:id` pages only). */
export function AnnounceCrumbLabel({ title }: { title: string }) {
  const { announce } = useContext(CrumbLabelContext)
  const { pathname } = useLocation()
  useEffect(() => {
    if (title) announce(pathname, title)
  }, [announce, pathname, title])
  return null
}
