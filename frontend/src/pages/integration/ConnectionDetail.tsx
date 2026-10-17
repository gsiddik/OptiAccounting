import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { useI18n, type MessageKey } from '../../i18n'
import { api } from '../../lib/api'
import { useAction, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { ReadOnlyNotice } from '../operational/shared'
import { ConnectionBadge, Facts } from './components'
import { ConnectionForm } from './ConnectionForm'
import { LifecycleDialog, SettingsDialog, type Lifecycle } from './ConnectionDialogs'
import { AccessBanners, AccessCard, BooksCard, ConfigCard, HealthCard, SettingsCard, SourceCard, SummaryCard } from './ConnectionPanels'
import { GapsCard, OpenGapBanner } from './GapPanel'
import { ENDPOINTS, OPTIFLEET, PATHS, settingsOf, useIntegrationAccess } from './data'
import { integrationError, sourceLabel } from './labels'
import type { Connection, ConnectionSummary, SyncResult } from './types'

/** Which moves the buttons offer for a status. Only a hint: the API checks the move again and answers CONNECTION_INVALID_TRANSITION. */
const OFFERED: Record<Lifecycle | 'sync', string[]> = {
  activate: ['READY'],
  pause: ['ACTIVE', 'ERROR'],
  resume: ['PAUSED', 'ERROR'],
  disconnect: ['DRAFT', 'CONFIGURING', 'READY', 'ACTIVE', 'PAUSED', 'ERROR'],
  sync: ['ACTIVE'],
}
const LIFECYCLE: [Lifecycle, MessageKey][] = [['activate', 'it.action.activate'], ['pause', 'it.action.pause'], ['resume', 'it.action.resume'], ['disconnect', 'it.action.disconnect']]

export default function ConnectionDetail() {
  const { id = '' } = useParams()
  const { t } = useI18n()
  const access = useIntegrationAccess()
  const toast = useToast()
  const sync = useAction()
  const connection = useResource(async () => (await api.get<Connection>(ENDPOINTS.connection(id))).data, [id])
  const summary = useResource(async () => (await api.get<ConnectionSummary>(ENDPOINTS.summary(id))).data, [id])
  const [dialog, setDialog] = useState<Lifecycle | 'edit' | 'settings' | null>(null)
  const [result, setResult] = useState<SyncResult | null>(null)
  const c = connection.data

  const reloadAll = () => {
    connection.reload()
    summary.reload()
  }

  async function syncNow() {
    setResult(null)
    const done = await sync.run(async () => (await api.post<SyncResult>(ENDPOINTS.action(id, 'sync-now'))).data)
    if (done.ok) {
      setResult(done.value)
      toast.success(t('it.detail.sync_done'))
    }
    reloadAll()
  }

  if (connection.error) return <ErrorNotice error={integrationError(connection.error)} onRetry={connection.reload} />
  if (!c) return <Loading />

  const offered = (action: keyof typeof OFFERED) => OFFERED[action].includes(c.status)
  return (
    <>
      <PageHeader
        title={c.name}
        description={<>{sourceLabel(c.source_system)} · <ConnectionBadge status={c.status} /></>}
        actions={
          <>
            <Link className="btn" to={PATHS.connections}>{t('it.detail.all')}</Link>
            <Link className="btn" to={PATHS.connectionEvents(c.id)}>{t('it.detail.inbox')}</Link>
            {access.canManage && offered('sync') && <Button loading={sync.busy} onClick={() => void syncNow()}>{t('it.detail.sync')}</Button>}
            {access.canManage && LIFECYCLE.filter(([action]) => offered(action)).map(([action, label]) => (
              <Button key={action} variant={action === 'activate' ? 'primary' : action === 'disconnect' ? 'danger' : 'secondary'} onClick={() => setDialog(action)}>{t(label)}</Button>
            ))}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      {!access.can('accounting.integration.manage') && <Banner tone="info">{t('it.detail.viewonly')}</Banner>}
      {c.status === 'ERROR' && <Banner tone="bad">{t('it.detail.error_state', { n: c.consecutive_failures })}</Banner>}
      <AccessBanners connection={c} />
      <OpenGapBanner connection={c} />
      {c.last_error && <Banner tone="warn"><strong>{t('it.detail.last_error')}</strong> {c.last_error}</Banner>}
      {sync.error != null && <Banner tone="bad">{describeError(integrationError(sync.error))}</Banner>}
      {result && <SyncCard result={result} />}

      <HealthCard connection={c} summary={summary.data} />
      <SummaryCard connection={c} summary={summary.data} error={summary.error} onRetry={summary.reload} />
      <BooksCard summary={summary.data} />
      <SourceCard summary={summary.data} />
      <AccessCard connection={c} />
      <GapsCard connection={c} canManage={access.canManage} onChanged={reloadAll} />
      <ConfigCard connection={c} onEdit={access.canManage ? () => setDialog('edit') : undefined} />
      {c.source_system === OPTIFLEET && <SettingsCard connection={c} onEdit={access.canManage ? () => setDialog('settings') : undefined} />}

      {dialog === 'edit' && <ConnectionForm connection={c} locked={(summary.data?.received ?? 0) > 0} onDone={() => { reloadAll(); setDialog(null) }} onClose={() => setDialog(null)} />}
      {dialog === 'settings' && <SettingsDialog connection={c} current={settingsOf(c)} onDone={reloadAll} onClose={() => setDialog(null)} />}
      {dialog && dialog !== 'edit' && dialog !== 'settings' && <LifecycleDialog connection={c} action={dialog} onDone={reloadAll} onClose={() => setDialog(null)} />}
    </>
  )
}

function SyncCard({ result }: { result: SyncResult }) {
  const { t } = useI18n()
  return (
    <Card title={t('it.sync.title')}>
      {result.error && <Banner tone="warn"><strong>{t('it.sync.stopped')}</strong> {result.error}</Banner>}
      <Facts rows={[
        [t('it.sync.pages'), String(result.pages)],
        [t('it.sync.received'), String(result.received)],
        [t('it.sync.duplicates'), String(result.duplicates)],
        [t('it.sync.conflicts'), String(result.conflicts)],
        [t('it.sync.processed'), String(result.processed)],
        [t('it.sync.finished'), String(result.posted)],
      ]} />
    </Card>
  )
}
