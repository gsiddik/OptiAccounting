import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useAction, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { ReadOnlyNotice } from '../operational/shared'
import { ConnectionBadge, Facts } from './components'
import { ConnectionForm } from './ConnectionForm'
import { LifecycleDialog, SettingsDialog, type Lifecycle } from './ConnectionDialogs'
import { ConfigCard, SettingsCard, SummaryCard } from './ConnectionPanels'
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
const LIFECYCLE: [Lifecycle, string][] = [['activate', 'Aktifkan'], ['pause', 'Jeda'], ['resume', 'Lanjutkan'], ['disconnect', 'Putuskan']]

export default function ConnectionDetail() {
  const { id = '' } = useParams()
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
      toast.success('Sinkronisasi selesai.')
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
            <Link className="btn" to={PATHS.connections}>Semua koneksi</Link>
            <Link className="btn" to={PATHS.connectionEvents(c.id)}>Kotak masuk kejadian</Link>
            {access.canManage && offered('sync') && <Button loading={sync.busy} onClick={() => void syncNow()}>Sinkronkan sekarang</Button>}
            {access.canManage && LIFECYCLE.filter(([action]) => offered(action)).map(([action, label]) => (
              <Button key={action} variant={action === 'activate' ? 'primary' : action === 'disconnect' ? 'danger' : 'secondary'} onClick={() => setDialog(action)}>{label}</Button>
            ))}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      {!access.can('accounting.integration.manage') && <Banner tone="info">Tampilan hanya baca: Anda tidak memiliki izin mengelola koneksi.</Banner>}
      {c.status === 'ERROR' && <Banner tone="bad">Penarikan gagal {c.consecutive_failures} kali berturut-turut dan koneksi dihentikan. Perbaiki penyebabnya, lalu lanjutkan atau jeda koneksi.</Banner>}
      {c.last_error && <Banner tone="warn"><strong>Galat tarikan terakhir.</strong> {c.last_error}</Banner>}
      {sync.error != null && <Banner tone="bad">{describeError(integrationError(sync.error))}</Banner>}
      {result && <SyncCard result={result} />}

      <SummaryCard connection={c} summary={summary.data} error={summary.error} onRetry={summary.reload} />
      <ConfigCard connection={c} onEdit={access.canManage ? () => setDialog('edit') : undefined} />
      {c.source_system === OPTIFLEET && <SettingsCard connection={c} onEdit={access.canManage ? () => setDialog('settings') : undefined} />}

      {dialog === 'edit' && <ConnectionForm connection={c} locked={(summary.data?.received ?? 0) > 0} onDone={() => { reloadAll(); setDialog(null) }} onClose={() => setDialog(null)} />}
      {dialog === 'settings' && <SettingsDialog connection={c} current={settingsOf(c)} onDone={reloadAll} onClose={() => setDialog(null)} />}
      {dialog && dialog !== 'edit' && dialog !== 'settings' && <LifecycleDialog connection={c} action={dialog} onDone={reloadAll} onClose={() => setDialog(null)} />}
    </>
  )
}

function SyncCard({ result }: { result: SyncResult }) {
  return (
    <Card title="Hasil sinkronisasi">
      {result.error && <Banner tone="warn"><strong>Tarikan berhenti dengan galat.</strong> {result.error}</Banner>}
      <Facts rows={[
        ['Halaman ditarik', String(result.pages)],
        ['Kejadian baru', String(result.received)],
        ['Terkirim ganda', String(result.duplicates)],
        ['Konflik isi', String(result.conflicts)],
        ['Diproses', String(result.processed)],
        ['Diposting', String(result.posted)],
      ]} />
    </Card>
  )
}
