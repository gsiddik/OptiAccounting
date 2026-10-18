import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Field } from '../../components/ui'
import { useI18n, type MessageKey } from '../../i18n'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { fieldError } from '../../lib/forms'
import { useAction } from '../../lib/hooks'
import { ENDPOINTS, useSettingOptions } from './data'
import { gapPolicyLabel, integrationError, sourceLabel } from './labels'
import type { Connection, ConnectionSettings } from './types'

export type Lifecycle = 'activate' | 'pause' | 'resume' | 'disconnect'

const KEYS = {
  activate: { title: 'it.life.activate.title', confirm: 'it.action.activate', done: 'it.life.activate.done', body: 'it.life.activate.body' },
  pause: { title: 'it.life.pause.title', confirm: 'it.action.pause', done: 'it.life.pause.done', body: 'it.life.pause.body' },
  resume: { title: 'it.life.resume.title', confirm: 'it.action.resume', done: 'it.life.resume.done', body: 'it.life.resume.body' },
  disconnect: { title: 'it.life.disconnect.title', confirm: 'it.action.disconnect', done: 'it.life.disconnect.done', body: 'it.life.disconnect.body' },
} as const satisfies Record<Lifecycle, Record<string, MessageKey>>

/** Activate, pause, resume or disconnect a connection after a confirmation. The API decides whether the move is allowed from the current status. */
export function LifecycleDialog({ connection, action, onDone, onClose }: { connection: Connection; action: Lifecycle; onDone: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const toast = useToast()
  const { busy, error, run } = useAction()
  const copy = KEYS[action]

  async function send() {
    const result = await run(async () => (await api.post(ENDPOINTS.action(connection.id, action))).data)
    if (result.ok) {
      toast.success(t(copy.done))
      onDone()
      onClose()
    }
  }

  const body = t(copy.body, { source: sourceLabel(connection.source_system), cutover: formatDate(connection.cutover_date), policy: gapPolicyLabel(connection.gap_policy) })
  return <ConfirmDialog title={t(copy.title)} confirmLabel={t(copy.confirm)} danger={action === 'disconnect'} busy={busy} error={integrationError(error)} message={<p>{body}</p>} onConfirm={() => void send()} onClose={onClose} />
}

/**
 * The OptiFleet connector settings: which cash/bank account vendor payments are paid from and which expense category invoices are booked to.
 * Both are required before an OptiFleet connection can be activated; the API checks that the ids belong to this organisation.
 */
export function SettingsDialog({ connection, current, onDone, onClose }: { connection: Connection; current: ConnectionSettings; onDone: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const toast = useToast()
  const options = useSettingOptions()
  const { busy, error, run } = useAction()
  const [cash, setCash] = useState(current.payables_cash_bank_account_id ?? '')
  const [category, setCategory] = useState(current.expense_category_id ?? '')

  async function save() {
    // Settings merge key by key on the server, so only the keys that changed are sent (null removes one).
    const settings: ConnectionSettings = {}
    if (cash !== (current.payables_cash_bank_account_id ?? '')) settings.payables_cash_bank_account_id = cash || null
    if (category !== (current.expense_category_id ?? '')) settings.expense_category_id = category || null
    if (Object.keys(settings).length === 0) return onClose()
    const result = await run(async () => (await api.patch(ENDPOINTS.connection(connection.id), { settings })).data)
    if (result.ok) {
      toast.success(t('it.settings.saved'))
      onDone()
      onClose()
    }
  }

  return (
    <FormModal title={t('it.settings.title')} busy={busy} error={integrationError(error)} onSubmit={() => void save()} onClose={onClose}>
      <p>{t('it.settings.intro')}</p>
      {options.error != null && (
        <>
          <Banner tone="bad">{t('it.settings.options_failed')}</Banner>
          <Button onClick={options.reload}>{t('common.retry')}</Button>
        </>
      )}
      <div className="form-grid">
        <Field label={t('it.settings.cash')} error={fieldError(error, 'settings.payables_cash_bank_account_id')} full>
          {(p) => (
            <select className="select" value={cash} onChange={(e) => setCash(e.target.value)} {...p}>
              <option value="">{t('it.settings.unchosen')}</option>
              {cash && !options.accounts.some((a) => a.id === cash) && <option value={cash}>{t('it.settings.chosen_before')}</option>}
              {options.accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
            </select>
          )}
        </Field>
        <Field label={t('it.settings.category')} error={fieldError(error, 'settings.expense_category_id')} full>
          {(p) => (
            <select className="select" value={category} onChange={(e) => setCategory(e.target.value)} {...p}>
              <option value="">{t('it.settings.unchosen')}</option>
              {category && !options.categories.some((c) => c.id === category) && <option value={category}>{t('it.settings.chosen_category_before')}</option>}
              {options.categories.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
            </select>
          )}
        </Field>
      </div>
    </FormModal>
  )
}
