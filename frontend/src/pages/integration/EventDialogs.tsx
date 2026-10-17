import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Field } from '../../components/ui'
import { useI18n } from '../../i18n'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction } from '../../lib/hooks'
import { ENDPOINTS } from './data'
import { eventStatusText, eventTypeLabel, integrationError } from './labels'
import type { IntegrationEvent } from './types'

const Subject = ({ event }: { event: IntegrationEvent }) => <>{eventTypeLabel(event.event_type)} (<span className="mono">{event.event_id}</span>)</>

/** Put a stuck event back in the queue. The reason is required (3 to 500 characters) and recorded in the audit trail; the event is processed again at once. */
export function RetryDialog({ event, onDone, onClose }: { event: IntegrationEvent; onDone: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const toast = useToast()
  const { busy, error, run } = useAction()

  async function retry(reason: string) {
    const result = await run(async () => (await api.post<IntegrationEvent>(ENDPOINTS.retry(event.id), { reason })).data)
    if (result.ok) {
      toast.success(t('it.events.retried', { status: eventStatusText(result.value.status)[0] }))
      onDone()
      onClose()
    }
  }

  return (
    <ConfirmDialog
      title={t('it.retry.title')}
      confirmLabel={t('it.events.retry')}
      reasonRequired
      busy={busy}
      error={integrationError(error)}
      message={<p><Subject event={event} /> {t('it.retry.body')}</p>}
      onConfirm={(reason) => void retry(reason)}
      onClose={onClose}
    />
  )
}

/** The reviewer decides an event must never be booked. Refused for good, the books are not touched, the reason goes to the audit trail. */
export function DismissDialog({ event, onDone, onClose }: { event: IntegrationEvent; onDone: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const toast = useToast()
  const { busy, error, run } = useAction()

  async function dismiss(reason: string) {
    const result = await run(async () => (await api.post<IntegrationEvent>(ENDPOINTS.dismiss(event.id), { reason })).data)
    if (result.ok) {
      toast.success(t('it.events.dismissed'))
      onDone()
      onClose()
    }
  }

  return (
    <ConfirmDialog
      title={t('it.dismiss.title')}
      confirmLabel={t('it.dismiss.confirm')}
      danger
      reasonRequired
      busy={busy}
      error={integrationError(error)}
      message={<p><Subject event={event} /> {t('it.dismiss.body')}</p>}
      onConfirm={(reason) => void dismiss(reason)}
      onClose={onClose}
    />
  )
}

type Decision = 'USE_ORIGINAL' | 'REJECT'

/** Decide about an event the source delivered twice with different content: keep the stored original, or reject the event. */
export function ConflictDialog({ event, onDone, onClose }: { event: IntegrationEvent; onDone: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [decision, setDecision] = useState<Decision>('USE_ORIGINAL')
  const [reason, setReason] = useState('')
  const [local, setLocal] = useState<string | null>(null)

  async function save() {
    if (reason.trim().length < 3) {
      setLocal(t('common.reason_hint'))
      return
    }
    setLocal(null)
    const result = await run(async () => (await api.post<IntegrationEvent>(ENDPOINTS.conflictResolution(event.id), { decision, reason: reason.trim() })).data)
    if (result.ok) {
      toast.success(t('it.conflict.done'))
      onDone()
      onClose()
    }
  }

  return (
    <FormModal title={t('it.conflict.dialog.title')} submitLabel={t('it.conflict.dialog.submit')} danger={decision === 'REJECT'} busy={busy} error={integrationError(error)} onSubmit={() => void save()} onClose={onClose}>
      <p><Subject event={event} />: {t('it.conflict.dialog.body')}</p>
      <fieldset className="field full">
        <legend className="label">{t('it.gaps.dialog.decision')}</legend>
        <label className="check">
          <input type="radio" name="conflict-decision" checked={decision === 'USE_ORIGINAL'} onChange={() => setDecision('USE_ORIGINAL')} /> {t('it.conflict.dialog.use_original')}
        </label>
        <label className="check">
          <input type="radio" name="conflict-decision" checked={decision === 'REJECT'} onChange={() => setDecision('REJECT')} /> {t('it.conflict.dialog.reject')}
        </label>
      </fieldset>
      <Field label={t('common.reason')} hint={t('common.reason_hint')} error={local ?? fieldError(error, 'reason')}>
        {(p) => <textarea className="textarea" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}
