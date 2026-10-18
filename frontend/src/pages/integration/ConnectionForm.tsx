import { useState } from 'react'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Field } from '../../components/ui'
import { useI18n } from '../../i18n'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction } from '../../lib/hooks'
import { ENDPOINTS, OPTIFLEET } from './data'
import { cutoverPolicyLabel, integrationError, sourceSystemLabels } from './labels'
import type { Connection } from './types'

const OTHER = 'other'
const SYSTEM_CODE = /^[a-z][a-z0-9_]{1,39}$/
const POLICIES = ['OPENING_BALANCE_ONLY', 'START_FROM_CUTOVER_DATE']

type Draft = { name: string; system: string; code: string; reference: string; baseUrl: string; credential: string; cutoverDate: string; policy: string; interval: string }

const blank: Draft = { name: '', system: OPTIFLEET, code: '', reference: '', baseUrl: '', credential: '', cutoverDate: '', policy: 'OPENING_BALANCE_ONLY', interval: '5' }

const toDraft = (c: Connection): Draft => ({
  name: c.name, system: c.source_system, code: '', reference: c.source_tenant_reference ?? '', baseUrl: c.base_url ?? '', credential: '', cutoverDate: c.cutover_date ?? '',
  policy: c.cutover_policy, interval: String(c.pull_interval_minutes),
})

/** The create body: what was filled in. The credential is sent once and never read back. */
function createBody(d: Draft) {
  const body: Record<string, unknown> = { name: d.name.trim(), source_system: d.system === OTHER ? d.code.trim() : d.system, cutover_policy: d.policy, pull_interval_minutes: Number(d.interval) }
  if (d.reference.trim()) body.source_tenant_reference = d.reference.trim()
  if (d.baseUrl.trim()) body.base_url = d.baseUrl.trim()
  if (d.credential) body.credential = d.credential
  if (d.cutoverDate) body.cutover_date = d.cutoverDate
  return body
}

/** The update body: only what differs from the saved connection; the credential only when a new one was typed. */
function updateBody(c: Connection, d: Draft, locked: boolean) {
  const before = toDraft(c)
  const body: Record<string, unknown> = {}
  if (d.name.trim() !== before.name) body.name = d.name.trim()
  if (d.baseUrl.trim() !== before.baseUrl) body.base_url = d.baseUrl.trim() || null
  if (d.credential) body.credential = d.credential
  if (d.interval !== before.interval) body.pull_interval_minutes = Number(d.interval)
  if (!locked) {
    if (d.reference.trim() !== before.reference) body.source_tenant_reference = d.reference.trim() || null
    if (d.cutoverDate !== before.cutoverDate) body.cutover_date = d.cutoverDate || null
    if (d.policy !== before.policy) body.cutover_policy = d.policy
  }
  return body
}

/**
 * Create a connection, or (with `connection`) change its configuration. `locked` is true once events were received: the source tenant
 * reference and the cutover then identify what was received and cannot change (the API answers CONNECTION_LOCKED otherwise).
 */
export function ConnectionForm({ connection, locked = false, onDone, onClose }: { connection?: Connection; locked?: boolean; onDone: (saved: Connection) => void; onClose: () => void }) {
  const { t } = useI18n()
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [d, setD] = useState<Draft>(connection ? toDraft(connection) : blank)
  const [local, setLocal] = useState<Record<string, string>>({})
  const set = (patch: Partial<Draft>) => setD((s) => ({ ...s, ...patch }))
  const editing = connection !== undefined

  async function submit() {
    const problems: Record<string, string> = {}
    if (d.name.trim().length < 2) problems.name = t('it.form.name_short')
    if (!editing && d.system === OTHER && !SYSTEM_CODE.test(d.code.trim())) problems.source_system = t('it.form.system_code_bad')
    if (!/^\d+$/.test(d.interval) || Number(d.interval) < 1 || Number(d.interval) > 1440) problems.pull_interval_minutes = t('it.form.interval_bad')
    setLocal(problems)
    if (Object.keys(problems).length > 0) return

    const result = await run(async () => {
      if (!connection) return (await api.post<Connection>(ENDPOINTS.connections, createBody(d))).data
      return (await api.patch<Connection>(ENDPOINTS.connection(connection.id), updateBody(connection, d, locked))).data
    })
    if (result.ok) {
      toast.success(editing ? t('it.form.saved') : t('it.form.created'))
      onDone(result.value)
    }
  }

  const err = (name: string) => local[name] ?? fieldError(error, name)
  const lockedHint = t('it.form.locked')
  return (
    <FormModal title={editing ? t('it.form.title_edit') : t('it.form.title_new')} submitLabel={editing ? t('common.save') : t('it.form.submit_new')} busy={busy} error={integrationError(error)} onSubmit={() => void submit()} onClose={onClose} wide>
      {editing && connection.status === 'DISCONNECTED' && <Banner tone="info">{t('it.form.reconnect')}</Banner>}
      <div className="form-grid">
        <Field label={t('it.form.name')} error={err('name')}>
          {(p) => <input className="input" value={d.name} maxLength={120} onChange={(e) => set({ name: e.target.value })} {...p} />}
        </Field>
        {editing ? (
          <Field label={t('it.form.system')} hint={t('it.form.system_fixed')}>
            {(p) => <input className="input" value={sourceSystemLabels[connection.source_system] ?? connection.source_system} disabled {...p} />}
          </Field>
        ) : (
          <Field label={t('it.form.system')} error={err('source_system')}>
            {(p) => (
              <select className="select" value={d.system} onChange={(e) => set({ system: e.target.value })} {...p}>
                <option value={OPTIFLEET}>{sourceSystemLabels[OPTIFLEET]}</option>
                <option value={OTHER}>{t('it.form.system_other')}</option>
              </select>
            )}
          </Field>
        )}
        {!editing && d.system === OTHER && (
          <Field label={t('it.form.system_code')} error={err('source_system')} hint={t('it.form.system_code_hint')} full>
            {(p) => <input className="input" value={d.code} maxLength={40} onChange={(e) => set({ code: e.target.value })} {...p} />}
          </Field>
        )}
        <Field label={t('it.form.reference')} error={err('source_tenant_reference')} hint={locked ? lockedHint : t('it.form.reference_hint')}>
          {(p) => <input className="input" value={d.reference} maxLength={120} disabled={locked} onChange={(e) => set({ reference: e.target.value })} {...p} />}
        </Field>
        <Field label={t('it.form.url')} error={err('base_url')} hint={t('it.form.url_hint')}>
          {(p) => <input className="input" type="url" value={d.baseUrl} maxLength={255} placeholder="https://" onChange={(e) => set({ baseUrl: e.target.value })} {...p} />}
        </Field>
        <Field label={t('it.form.credential')} error={err('credential')} hint={connection?.has_credential ? t('it.form.credential_hint_set') : t('it.form.credential_hint_new')}>
          {(p) => <input className="input" type="password" autoComplete="new-password" value={d.credential} maxLength={500} onChange={(e) => set({ credential: e.target.value })} {...p} />}
        </Field>
        <Field label={t('it.form.cutover')} error={err('cutover_date')} hint={locked ? lockedHint : t('it.form.cutover_hint')}>
          {(p) => <input className="input" type="date" value={d.cutoverDate} disabled={locked} onChange={(e) => set({ cutoverDate: e.target.value })} {...p} />}
        </Field>
        <Field label={t('it.form.cutover_policy')} error={err('cutover_policy')} hint={locked ? lockedHint : undefined}>
          {(p) => (
            <select className="select" value={d.policy} disabled={locked} onChange={(e) => set({ policy: e.target.value })} {...p}>
              {POLICIES.map((v) => <option key={v} value={v}>{cutoverPolicyLabel(v)}</option>)}
            </select>
          )}
        </Field>
        <Field label={t('it.form.interval')} error={err('pull_interval_minutes')} hint={t('it.form.interval_hint')}>
          {(p) => <input className="input" inputMode="numeric" value={d.interval} onChange={(e) => set({ interval: e.target.value })} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}
