import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Banner, Button, Card, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { useI18n } from '../../i18n'
import { api } from '../../lib/api'
import { formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { ReadOnlyNotice } from '../operational/shared'
import { BooksBadge, DocumentLink, DocumentRef, EventBadge, Facts, JournalLink, OutcomeBadge } from './components'
import { ENDPOINTS, PATHS, useIntegrationAccess } from './data'
import { envelopeDifferences } from './diff'
import { ConflictDialog, DismissDialog, RetryDialog } from './EventDialogs'
import { bookHint, documentStatusText, eventTypeLabel, integrationError, processingText, sourceLabel, statusHint } from './labels'
import { RETRYABLE, type EventConflict, type EventDetailData } from './types'

const text = (value: unknown): string | undefined => (typeof value === 'string' && value !== '' ? value : undefined)
const json = (value: unknown) => JSON.stringify(value ?? null, null, 2)

/** Differences shown per conflict; the full received content is one click away. */
const MAX_DIFFERENCES = 40

function ConflictCard({ event, canResolve, onResolve }: { event: EventDetailData; canResolve: boolean; onResolve: () => void }) {
  const { t } = useI18n()
  const conflicts = event.conflicts ?? []
  if (conflicts.length === 0 && !event.conflict) return null
  const open = conflicts.filter((c) => c.resolution === null)
  return (
    <Card title={t('it.conflict.title')} actions={canResolve && event.conflict && <Button variant="primary" onClick={onResolve}>{t('it.event.resolve')}</Button>}>
      <p className="muted">{t('it.conflict.intro')}</p>
      {conflicts.map((c) => <ConflictEntry key={c.id} conflict={c} stored={event.envelope} />)}
      {open.length === 0 && conflicts.length === 0 && <p className="muted">{t('it.conflict.open')}</p>}
    </Card>
  )
}

function ConflictEntry({ conflict, stored }: { conflict: EventConflict; stored: Record<string, unknown> }) {
  const { t } = useI18n()
  const differences = envelopeDifferences(stored, conflict.envelope)
  const shown = differences.slice(0, MAX_DIFFERENCES)
  const missing = t('it.conflict.missing')
  return (
    <div className="stack" style={{ marginTop: 12 }}>
      <div>
        <strong>{t('it.conflict.received', { when: formatDateTime(conflict.received_at) })}</strong>{' '}
        {conflict.resolution === null
          ? <Badge tone="warn">{t('it.conflict.open')}</Badge>
          : <Badge tone={conflict.resolution === 'USE_ORIGINAL' ? 'ok' : 'bad'}>{t(conflict.resolution === 'USE_ORIGINAL' ? 'it.conflict.USE_ORIGINAL' : 'it.conflict.REJECT')}</Badge>}
        <div className="muted mono">{t('it.conflict.hash', { hash: conflict.received_hash.slice(0, 12) })}</div>
        {conflict.resolved_at && <div className="muted">{t('it.conflict.by', { what: t(conflict.resolution === 'USE_ORIGINAL' ? 'it.conflict.USE_ORIGINAL' : 'it.conflict.REJECT'), when: formatDateTime(conflict.resolved_at) })}</div>}
        {conflict.resolution_reason && <div>{t('it.conflict.reason', { reason: conflict.resolution_reason })}</div>}
      </div>
      <strong>{t('it.conflict.diff')}</strong>
      {shown.length === 0 ? <p className="muted">{t('it.conflict.diff_none')}</p> : (
        <DataTable
          caption={t('it.conflict.diff')}
          rows={shown}
          rowKey={(d) => d.path}
          scroll
          columns={[
            { header: t('it.conflict.col.field'), primary: true, cell: (d) => <span className="mono">{d.path}</span> },
            { header: t('it.conflict.col.stored'), cell: (d) => <span className="mono">{d.stored ?? missing}</span> },
            { header: t('it.conflict.col.received'), cell: (d) => <span className="mono">{d.received ?? missing}</span> },
          ]}
        />
      )}
      {differences.length > shown.length && <p className="muted">{t('it.conflict.diff_more', { n: differences.length - shown.length })}</p>}
      <details>
        <summary>{t('it.conflict.raw')}</summary>
        <pre className="mono" tabIndex={0} style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: 360, overflow: 'auto' }}>{json(conflict.envelope)}</pre>
      </details>
    </div>
  )
}

/** Where this event stands in the books, apart from the processing status: what processing decided, and the live state of the document behind it. */
function ResultCard({ event }: { event: EventDetailData }) {
  const { t } = useI18n()
  const processed = event.status === 'POSTED'
  const books = event.books
  const documents = books?.documents ?? []
  return (
    <Card title={t('it.result.title')}>
      <p className="muted">{t('it.result.intro')}</p>
      <Facts rows={[
        [t('it.result.processing'), <EventBadge key="p" status={event.status} />],
        [t('it.result.decided'), <OutcomeBadge key="o" outcome={processed ? event.accounting_outcome : 'PENDING'} />],
        [t('it.result.now'), processed && books ? <span key="b"><BooksBadge state={books.state} /><div className="muted">{bookHint(books.state)}</div></span> : null],
        [t('it.result.doc_status'), processed && event.document_status ? documentStatusText(event.document_status) : null],
      ]} />
      {!processed && <p className="muted">{t('it.result.not_processed')}</p>}
      {processed && documents.length > 0 && (
        <DataTable
          caption={t('it.result.documents')}
          rows={documents}
          rowKey={(d) => `${d.document_type}:${d.document_id}`}
          columns={[
            { header: t('it.result.documents'), primary: true, cell: (d) => <DocumentRef type={d.document_type} id={d.document_id} /> },
            { header: t('it.proc.status'), cell: (d) => documentStatusText(d.status) },
            { header: t('it.result.now'), cell: (d) => <BooksBadge state={d.state} /> },
            { header: t('it.links.col.journal'), cell: (d) => d.journal_id ? <span><JournalLink id={d.journal_id} />{d.journal_status && <span className="muted"> · {t('it.result.journal_status', { status: documentStatusText(d.journal_status) })}</span>}</span> : '—' },
          ]}
        />
      )}
    </Card>
  )
}

export default function EventDetail() {
  const { id = '' } = useParams()
  const { t } = useI18n()
  const access = useIntegrationAccess()
  const detail = useResource(async () => (await api.get<EventDetailData>(ENDPOINTS.event(id))).data, [id])
  const [dialog, setDialog] = useState<'retry' | 'dismiss' | 'conflict' | null>(null)
  const e = detail.data

  if (detail.error) return <ErrorNotice error={integrationError(detail.error)} onRetry={detail.reload} />
  if (!e) return <Loading />

  const source = e.envelope.source as Record<string, unknown> | undefined
  const sourceDoc = [text(source?.type), text(source?.number) ?? text(source?.id)].filter(Boolean).join(' · ') || undefined
  const review = e.envelope.review as Record<string, unknown> | undefined
  const reviewReason = review?.required === true ? (text(review.reason) ?? '—') : undefined
  const hint = statusHint(e.status)
  const processing = processingText(e.last_error_code, e.last_error)
  const canAct = access.canRetry && RETRYABLE.includes(e.status) && !e.conflict
  return (
    <>
      <PageHeader
        title={eventTypeLabel(e.event_type)}
        description={<>{sourceLabel(e.source_system)} · <span className="mono">{e.event_id}</span> · <EventBadge status={e.status} /></>}
        actions={
          <>
            <Link className="btn" to={PATHS.events}>{t('it.event.all')}</Link>
            {canAct && <Button variant="primary" onClick={() => setDialog('retry')}>{t('it.event.retry')}</Button>}
            {canAct && <Button variant="danger" onClick={() => setDialog('dismiss')}>{t('it.event.dismiss')}</Button>}
            {access.canManage && e.conflict && <Button variant="primary" onClick={() => setDialog('conflict')}>{t('it.event.resolve')}</Button>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      {e.conflict && <Banner tone="warn">{t('it.event.conflict_banner')}</Banner>}
      {e.gap_id && (
        <Banner tone="info">
          {t('it.event.gap_banner')} <Link to={PATHS.connection(e.connection_id)}>{t('it.event.gap_link')}</Link>
        </Banner>
      )}
      {hint && <Banner tone={e.status === 'POSTED' ? 'ok' : e.status === 'RECEIVED' ? 'info' : 'warn'}>{hint}</Banner>}
      {e.last_error_code && (
        <Banner tone="bad">
          <strong>{e.last_error_code}</strong>: {processing}
          {e.last_error && processing !== e.last_error && <div className="muted">{t('it.event.server_message', { message: e.last_error })}</div>}
        </Banner>
      )}

      <ResultCard event={e} />

      <ConflictCard event={e} canResolve={access.canManage} onResolve={() => setDialog('conflict')} />

      <Card title={t('it.proc.title')}>
        <Facts rows={[
          [t('it.proc.status'), <EventBadge key="s" status={e.status} />],
          [t('it.proc.attempts'), String(e.attempts)],
          [t('it.proc.next'), e.next_attempt_at && formatDateTime(e.next_attempt_at)],
          [t('it.proc.received'), formatDateTime(e.received_at)],
          [t('it.proc.processed'), e.processed_at ? formatDateTime(e.processed_at) : t('it.proc.not_yet')],
          [t('it.proc.dupes'), String(e.duplicate_count)],
          [t('it.proc.conflict'), e.conflict ? <Badge key="c" tone="warn">{t('common.yes')}</Badge> : t('common.no')],
          [t('it.proc.journal'), e.resulting_journal_id && <JournalLink key="j" id={e.resulting_journal_id} />],
        ]} />
      </Card>

      <Card title={t('it.env.title')}>
        <Facts rows={[
          [t('it.env.type'), <><div>{eventTypeLabel(e.event_type)}</div><span className="mono muted">{e.event_type}</span></>],
          [t('it.env.id'), <span key="i" className="mono">{e.event_id}</span>],
          [t('it.env.schema'), e.schema_version],
          [t('it.env.source'), sourceLabel(e.source_system)],
          [t('it.env.tenant'), text(e.envelope.source_tenant_reference)],
          [t('it.env.occurred'), formatDateTime(e.occurred_at)],
          [t('it.env.sequence'), typeof e.sequence === 'number' && String(e.sequence)],
          [t('it.env.document'), sourceDoc],
          [t('it.env.correlation'), text(e.envelope.correlation_id)],
          [t('it.env.review'), reviewReason && t('it.env.review_value', { reason: reviewReason })],
          [t('it.env.connection'), <Link key="k" to={PATHS.connection(e.connection_id)}>{t('it.env.open_connection')}</Link>],
        ]} />
      </Card>

      <Card title={t('it.payload.title')}>
        <p className="muted preview-note">{t('it.payload.note')}</p>
        <pre className="mono" tabIndex={0} aria-label={t('it.payload.aria')} style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: 420, overflow: 'auto' }}>{json(e.envelope.payload)}</pre>
        <details>
          <summary>{t('it.payload.full')}</summary>
          <pre className="mono" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: 420, overflow: 'auto' }}>{json(e.envelope)}</pre>
        </details>
      </Card>

      <Card title={t('it.links.title')} flush>
        {e.source_links.length === 0 ? (
          <p className="muted" style={{ padding: 16 }}>{t('it.links.none')}</p>
        ) : (
          <DataTable
            caption={t('it.links.caption')}
            rows={e.source_links}
            rowKey={(l) => l.id}
            columns={[
              { header: t('it.links.col.source'), primary: true, cell: (l) => [l.source_type, l.source_document_number ?? l.source_id].join(' · ') },
              { header: t('it.links.col.ours'), cell: (l) => <DocumentLink link={l} /> },
              { header: t('it.links.col.journal'), cell: (l) => l.journal_id ? <JournalLink id={l.journal_id} /> : '—' },
            ]}
          />
        )}
      </Card>

      {dialog === 'retry' && <RetryDialog event={e} onDone={detail.reload} onClose={() => setDialog(null)} />}
      {dialog === 'dismiss' && <DismissDialog event={e} onDone={detail.reload} onClose={() => setDialog(null)} />}
      {dialog === 'conflict' && <ConflictDialog event={e} onDone={detail.reload} onClose={() => setDialog(null)} />}
    </>
  )
}
