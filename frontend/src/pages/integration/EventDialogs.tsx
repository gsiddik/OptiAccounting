import { ConfirmDialog } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { api } from '../../lib/api'
import { useAction } from '../../lib/hooks'
import { ENDPOINTS } from './data'
import { eventStatusText, eventTypeLabel, integrationError } from './labels'
import type { IntegrationEvent } from './types'

/** Put a stuck event back in the queue. The reason is required (3 to 500 characters) and recorded in the audit trail; the event is processed again at once. */
export function RetryDialog({ event, onDone, onClose }: { event: IntegrationEvent; onDone: () => void; onClose: () => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()

  async function retry(reason: string) {
    const result = await run(async () => (await api.post<IntegrationEvent>(ENDPOINTS.retry(event.id), { reason })).data)
    if (result.ok) {
      toast.success(`Kejadian diproses ulang. Status sekarang: ${eventStatusText(result.value.status)[0]}.`)
      onDone()
      onClose()
    }
  }

  return (
    <ConfirmDialog
      title="Ulangi pemrosesan"
      confirmLabel="Ulangi"
      reasonRequired
      busy={busy}
      error={integrationError(error)}
      message={<p>{eventTypeLabel(event.event_type)} (<span className="mono">{event.event_id}</span>) dikembalikan ke antrean dan langsung diproses lagi dari awal. Isi kejadian tidak berubah. Ulangi setelah penyebabnya diperbaiki, misalnya membuka periode atau melengkapi pemetaan akun.</p>}
      onConfirm={(reason) => void retry(reason)}
      onClose={onClose}
    />
  )
}
