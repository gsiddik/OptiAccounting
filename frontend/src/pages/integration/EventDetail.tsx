import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Banner, Button, Card, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { ReadOnlyNotice } from '../operational/shared'
import { DocumentLink, EventBadge, Facts, JournalLink } from './components'
import { RetryDialog } from './EventDialogs'
import { ENDPOINTS, PATHS, useIntegrationAccess } from './data'
import { eventTypeLabel, integrationError, processingText, sourceLabel, statusHints } from './labels'
import { RETRYABLE, type EventDetailData } from './types'

const text = (value: unknown): string | undefined => (typeof value === 'string' && value !== '' ? value : undefined)
const json = (value: unknown) => JSON.stringify(value ?? null, null, 2)

export default function EventDetail() {
  const { id = '' } = useParams()
  const access = useIntegrationAccess()
  const detail = useResource(async () => (await api.get<EventDetailData>(ENDPOINTS.event(id))).data, [id])
  const [retrying, setRetrying] = useState(false)
  const e = detail.data

  if (detail.error) return <ErrorNotice error={integrationError(detail.error)} onRetry={detail.reload} />
  if (!e) return <Loading />

  const source = e.envelope.source as Record<string, unknown> | undefined
  const sourceDoc = [text(source?.type), text(source?.number) ?? text(source?.id)].filter(Boolean).join(' · ') || undefined
  return (
    <>
      <PageHeader
        title={eventTypeLabel(e.event_type)}
        description={<>{sourceLabel(e.source_system)} · <span className="mono">{e.event_id}</span> · <EventBadge status={e.status} /></>}
        actions={
          <>
            <Link className="btn" to={PATHS.events}>Semua kejadian</Link>
            {access.canRetry && RETRYABLE.includes(e.status) && <Button variant="primary" onClick={() => setRetrying(true)}>Ulangi pemrosesan</Button>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      {e.conflict && (
        <Banner tone="warn">
          <strong>Konflik isi.</strong> ID kejadian yang sama pernah diterima lagi dengan isi yang berbeda. Isi yang tersimpan di sini tidak berubah dan tidak diganti; periksa kejadian ini di sistem sumber.
        </Banner>
      )}
      {statusHints[e.status] && <Banner tone={e.status === 'POSTED' ? 'ok' : e.status === 'RECEIVED' ? 'info' : 'warn'}>{statusHints[e.status]}</Banner>}
      {e.last_error_code && (
        <Banner tone="bad">
          <strong>{e.last_error_code}</strong>: {processingText(e.last_error_code, e.last_error)}
          {e.last_error && processingText(e.last_error_code, e.last_error) !== e.last_error && <div className="muted">Pesan server: {e.last_error}</div>}
        </Banner>
      )}

      <Card title="Pemrosesan">
        <Facts rows={[
          ['Status', <EventBadge key="s" status={e.status} />],
          ['Percobaan', String(e.attempts)],
          ['Percobaan berikutnya', e.next_attempt_at && formatDateTime(e.next_attempt_at)],
          ['Diterima', formatDateTime(e.received_at)],
          ['Diproses', e.processed_at ? formatDateTime(e.processed_at) : 'Belum'],
          ['Terkirim ganda', String(e.duplicate_count)],
          ['Konflik isi', e.conflict ? <Badge key="c" tone="warn">Ya</Badge> : 'Tidak'],
          ['Jurnal hasil', e.resulting_journal_id && <JournalLink key="j" id={e.resulting_journal_id} />],
        ]} />
      </Card>

      <Card title="Amplop kejadian">
        <Facts rows={[
          ['Jenis kejadian', <><div>{eventTypeLabel(e.event_type)}</div><span className="mono muted">{e.event_type}</span></>],
          ['ID kejadian', <span key="i" className="mono">{e.event_id}</span>],
          ['Versi skema', e.schema_version],
          ['Sistem sumber', sourceLabel(e.source_system)],
          ['Tenant sumber', text(e.envelope.source_tenant_reference)],
          ['Terjadi pada', formatDateTime(e.occurred_at)],
          ['Urutan di sumber', typeof e.sequence === 'number' && String(e.sequence)],
          ['Dokumen sumber', sourceDoc],
          ['Korelasi', text(e.envelope.correlation_id)],
          ['Koneksi', <Link key="k" to={PATHS.connection(e.connection_id)}>Buka koneksi</Link>],
        ]} />
      </Card>

      <Card title="Muatan (hanya baca)">
        <p className="muted preview-note">Isi kejadian persis seperti diterima. Tidak dapat diubah; koreksi dilakukan di sistem sumber.</p>
        <pre className="mono" tabIndex={0} aria-label="Muatan kejadian" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: 420, overflow: 'auto' }}>{json(e.envelope.payload)}</pre>
        <details>
          <summary>Amplop lengkap</summary>
          <pre className="mono" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: 420, overflow: 'auto' }}>{json(e.envelope)}</pre>
        </details>
      </Card>

      <Card title="Dokumen dan jurnal terkait" flush>
        {e.source_links.length === 0 ? (
          <p className="muted" style={{ padding: 16 }}>Belum ada dokumen atau jurnal yang tertaut ke kejadian ini.</p>
        ) : (
          <DataTable
            caption="Tautan ke dokumen sumber"
            rows={e.source_links}
            rowKey={(l) => l.id}
            columns={[
              { header: 'Dokumen sumber', primary: true, cell: (l) => [l.source_type, l.source_document_number ?? l.source_id].join(' · ') },
              { header: 'Dokumen OptiEntry', cell: (l) => <DocumentLink link={l} /> },
              { header: 'Jurnal', cell: (l) => l.journal_id ? <JournalLink id={l.journal_id} /> : '—' },
            ]}
          />
        )}
      </Card>

      {retrying && <RetryDialog event={e} onDone={detail.reload} onClose={() => setRetrying(false)} />}
    </>
  )
}
