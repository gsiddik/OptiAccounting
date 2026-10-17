import { useState } from 'react'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Field } from '../../components/ui'
import { useI18n } from '../../i18n'
import { api } from '../../lib/api'
import { formatDateTime, formatNumber } from '../../lib/format'
import { fieldError } from '../../lib/forms'
import { useAction, useResource } from '../../lib/hooks'
import type { Page } from '../../lib/operational'
import { ENDPOINTS, PATHS } from './data'
import { gapPolicyHint, gapPolicyLabel, gapReasonLabel, integrationError } from './labels'
import type { Connection, Gap, GapResolution } from './types'

/** The banner for a connection that is not listening right now: where its feed stood and what will happen to what the source does meanwhile. */
export function OpenGapBanner({ connection }: { connection: Connection }) {
  const { t } = useI18n()
  const gap = connection.open_gap
  if (!gap) return null
  return (
    <Banner tone="warn">
      {t('it.gaps.open_banner', { when: formatDateTime(gap.opened_at), reason: gapReasonLabel(gap.open_reason), sequence: gap.sequence_at_open, policy: gapPolicyLabel(gap.policy) })}
      {gapPolicyHint(gap.policy) && <div className="muted">{gapPolicyHint(gap.policy)}</div>}
    </Banner>
  )
}

/**
 * Process or dismiss the events a gap held. One decision for the whole batch (at most 100 per call, repeat while some remain); the server books
 * through the normal path, so periods, mappings and approvals still apply, and a dismissal never touches the books.
 */
function GapDialog({ gap, onDone, onClose }: { gap: Gap; onDone: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [decision, setDecision] = useState<'PROCESS' | 'DISMISS'>('PROCESS')
  const [reason, setReason] = useState('')
  const [local, setLocal] = useState<string | null>(null)
  const [result, setResult] = useState<GapResolution | null>(null)

  async function apply() {
    if (reason.trim().length < 3) {
      setLocal(t('common.reason_hint'))
      return
    }
    setLocal(null)
    const done = await run(async () => (await api.post<GapResolution>(ENDPOINTS.gapResolution(gap.id), { decision, reason: reason.trim() })).data)
    if (done.ok) {
      setResult(done.value)
      toast.success(t('it.gaps.dialog.result', { handled: done.value.handled, processed: done.value.processed, remaining: done.value.remaining }))
      onDone()
      if (done.value.remaining === 0) onClose()
    }
  }

  return (
    <FormModal title={t('it.gaps.dialog.title')} submitLabel={t('it.gaps.dialog.submit')} danger={decision === 'DISMISS'} busy={busy} error={integrationError(error)} onSubmit={() => void apply()} onClose={onClose}>
      <p>{t('it.gaps.dialog.body', { held: formatNumber(gap.events.held) })}</p>
      {result && result.remaining > 0 && <Banner tone="info">{t('it.gaps.dialog.again')}</Banner>}
      <fieldset className="field full">
        <legend className="label">{t('it.gaps.dialog.decision')}</legend>
        <label className="check"><input type="radio" name="gap-decision" checked={decision === 'PROCESS'} onChange={() => setDecision('PROCESS')} /> {t('it.gaps.dialog.process')}</label>
        <label className="check"><input type="radio" name="gap-decision" checked={decision === 'DISMISS'} onChange={() => setDecision('DISMISS')} /> {t('it.gaps.dialog.dismiss')}</label>
      </fieldset>
      <Field label={t('common.reason')} hint={t('common.reason_hint')} error={local ?? fieldError(error, 'reason')}>
        {(p) => <textarea className="textarea" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}

/** The pauses and disconnections of one connection with the events each one held, and the decision about them for a manager. */
export function GapsCard({ connection, canManage, onChanged }: { connection: Connection; canManage: boolean; onChanged: () => void }) {
  const { t } = useI18n()
  const gaps = useResource(async () => (await api.get<Page<Gap>>(ENDPOINTS.gaps, { params: { connection_id: connection.id, per_page: 25 } })).data.data, [connection.id, connection.status])
  const [deciding, setDeciding] = useState<Gap | null>(null)
  const rows = gaps.data ?? []

  const window = (g: Gap) => (g.ceiling_sequence === null ? t('it.gaps.window_open', { from: g.sequence_at_open }) : t('it.gaps.window', { from: g.sequence_at_open, to: g.ceiling_sequence }))
  return (
    <Card title={t('it.gaps.title')} flush>
      <p className="muted" style={{ padding: '12px 16px 0' }}>{t('it.gaps.intro')}</p>
      {gaps.error ? <ErrorNotice error={integrationError(gaps.error)} onRetry={gaps.reload} /> : !gaps.data ? (
        <p className="muted" style={{ padding: 16 }}>{t('common.loading')}</p>
      ) : rows.length === 0 ? (
        <p className="muted" style={{ padding: 16 }}>{t('it.gaps.none')}</p>
      ) : (
        <DataTable
          caption={t('it.gaps.caption')}
          rows={rows}
          rowKey={(g) => g.id}
          scroll
          columns={[
            { header: t('it.gaps.col.opened'), primary: true, cell: (g) => formatDateTime(g.opened_at) },
            { header: t('it.gaps.col.reason'), cell: (g) => gapReasonLabel(g.open_reason) },
            { header: t('it.gaps.col.window'), cell: window },
            { header: t('it.gaps.col.policy'), cell: (g) => gapPolicyLabel(g.policy) },
            { header: t('it.gaps.col.state'), cell: (g) => t(g.status === 'OPEN' ? 'it.gaps.state.OPEN' : 'it.gaps.state.CLOSED') },
            { header: t('it.gaps.col.held'), align: 'right', cell: (g) => g.events.held > 0 ? <Link to={`${PATHS.connectionEvents(connection.id, 'NEEDS_REVIEW')}`} aria-label={t('it.gaps.held_link')}>{formatNumber(g.events.held)}</Link> : formatNumber(g.events.held) },
            { header: t('it.gaps.col.processed'), align: 'right', cell: (g) => formatNumber(g.events.processed) },
            { header: t('it.gaps.col.refused'), align: 'right', cell: (g) => formatNumber(g.events.refused) },
            {
              header: t('it.gaps.col.action'),
              actions: true,
              cell: (g) => canManage && g.status === 'CLOSED' && g.events.held > 0
                ? <Button size="sm" aria-label={t('it.gaps.review_aria', { when: formatDateTime(g.opened_at) })} onClick={() => setDeciding(g)}>{t('it.gaps.review')}</Button>
                : null,
            },
          ]}
        />
      )}
      {deciding && <GapDialog gap={deciding} onDone={() => { gaps.reload(); onChanged() }} onClose={() => setDeciding(null)} />}
    </Card>
  )
}
