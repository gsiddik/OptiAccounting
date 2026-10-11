import type { ReactNode } from 'react'
import { ConfirmDialog } from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { useAction } from '../../../lib/hooks'
import { definitionError } from './labels'

/** A confirmation for a state change of a definition: asks, runs the request, shows the API's refusal inside the dialog, and reports back on success. */
export function ActionConfirm<T>({ title, message, confirmLabel, danger, success, request, onDone, onClose }: {
  title: string
  message: ReactNode
  confirmLabel: string
  danger?: boolean
  success: string
  request: () => Promise<T>
  onDone: (value: T) => void
  onClose: () => void
}) {
  const toast = useToast()
  const { busy, error, run } = useAction()

  async function confirm() {
    const r = await run(request)
    if (r.ok) {
      toast.success(success)
      onDone(r.value)
    }
  }

  return <ConfirmDialog title={title} message={message} confirmLabel={confirmLabel} danger={danger} busy={busy} error={definitionError(error)} onConfirm={() => void confirm()} onClose={onClose} />
}
