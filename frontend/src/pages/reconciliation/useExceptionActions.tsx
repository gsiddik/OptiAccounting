import { useState } from 'react'
import { ExceptionDialog, type ExceptionAction } from './ExceptionDialogs'
import type { ReconException } from './types'

/** The dialogs of the three exception actions, opened with `start(action, exception)`. `onChanged` reloads whatever shows the exception. */
export function useExceptionActions(onChanged: () => void) {
  const [active, setActive] = useState<{ action: ExceptionAction; exception: ReconException } | null>(null)
  const close = () => setActive(null)
  const dialogs = active && <ExceptionDialog action={active.action} exception={active.exception} onChanged={onChanged} onClose={close} />
  return { start: (action: ExceptionAction, exception: ReconException) => setActive({ action, exception }), dialogs }
}
