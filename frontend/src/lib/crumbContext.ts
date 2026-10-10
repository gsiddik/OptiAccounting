import { createContext, useContext } from 'react'

export type CrumbRegistry = { labels: Record<string, string>; announce: (pathname: string, label: string) => void }

export const CrumbLabelContext = createContext<CrumbRegistry>({ labels: {}, announce: () => undefined })

/** The titles pages announced for their own address (see `AnnounceCrumbLabel`), keyed by pathname. */
export const useCrumbLabels = () => useContext(CrumbLabelContext).labels
