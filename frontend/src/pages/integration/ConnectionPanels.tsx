import { Link } from 'react-router-dom'
import { Banner, Button, Card, ErrorNotice } from '../../components/ui'
import { useI18n } from '../../i18n'
import { formatDate, formatDateTime, formatNumber } from '../../lib/format'
import { AccessBadge, BooksBadge, Facts, HealthBadge } from './components'
import { PATHS, settingsOf, useSettingOptions } from './data'
import { alertCopy, alertEventFilter, bookHint, cutoverPolicyLabel, entryReasonText, feedCodeText, gapPolicyHint, gapPolicyLabel, integrationError, sourceLabel } from './labels'
import { BOOK_STATES, type Connection, type ConnectionAccess, type ConnectionSummary } from './types'

/** Event counts by processing state; a non-zero count opens the inbox narrowed to that state. "Processing finished" is not "in the ledger": the books card says that. */
export function SummaryCard({ connection, summary, error, onRetry }: { connection: Connection; summary: ConnectionSummary | null; error: unknown; onRetry: () => void }) {
  const { t } = useI18n()
  if (error) return <Card title={t('it.summary.title')}><ErrorNotice error={integrationError(error)} onRetry={onRetry} /></Card>
  if (!summary) return <Card title={t('it.summary.title')}><p className="muted">{t('it.summary.loading')}</p></Card>
  const count = (status: string) => summary.counts[status] ?? 0
  const link = (n: number, status?: string) => (n > 0 ? <Link to={PATHS.connectionEvents(connection.id, status)}>{formatNumber(n)}</Link> : formatNumber(n))
  return (
    <Card title={t('it.summary.title')}>
      <Facts rows={[
        [t('it.summary.received'), link(summary.received)],
        [t('it.summary.finished'), link(summary.posted, 'POSTED')],
        [t('it.summary.waiting'), link(count('RECEIVED'), 'RECEIVED')],
        [t('it.summary.review'), link(count('NEEDS_REVIEW'), 'NEEDS_REVIEW')],
        [t('it.summary.failed'), link(count('FAILED'), 'FAILED')],
        [t('it.summary.dead'), link(count('DEAD_LETTER'), 'DEAD_LETTER')],
        [t('it.summary.rejected'), link(count('REJECTED'), 'REJECTED')],
        [t('it.summary.duplicates'), formatNumber(summary.duplicates)],
        [t('it.summary.conflicts'), summary.conflicts > 0 ? <Link key="c" to={`${PATHS.events}?connection_id=${connection.id}&conflict=1`}>{formatNumber(summary.conflicts)}</Link> : formatNumber(summary.conflicts)],
      ]} />
      <p className="muted preview-note">{t('it.summary.note')}</p>
    </Card>
  )
}

/** Where the processed events stand in the books now. Only "In the ledger" is in the general ledger: a draft or an awaiting-approval document is not counted as booked. */
export function BooksCard({ summary }: { summary: ConnectionSummary | null }) {
  const { t } = useI18n()
  if (!summary?.books) return null
  const books = summary.books
  const rows = BOOK_STATES.filter((state) => (books[state] ?? 0) > 0 || state === 'IN_LEDGER')
  const total = BOOK_STATES.reduce((sum, state) => sum + (books[state] ?? 0), 0)
  return (
    <Card title={t('it.books.title')}>
      {total === 0 ? <p className="muted">{t('it.books.none')}</p> : (
        <dl className="facts">
          {rows.map((state) => (
            <div key={state}>
              <dt><BooksBadge state={state} /></dt>
              <dd title={bookHint(state)}>{formatNumber(books[state] ?? 0)}</dd>
            </div>
          ))}
        </dl>
      )}
      <p className="muted preview-note">{t('it.books.note')}</p>
    </Card>
  )
}

/** The three different reasons a connection does not deliver, kept apart: OptiEntry holds it, the source refuses, the source does not answer. */
export function AccessBanners({ connection }: { connection: Connection }) {
  const { t } = useI18n()
  const access = connection.access
  if (!access) return null
  const listening = connection.status === 'ACTIVE' || connection.status === 'ERROR'
  return (
    <>
      {connection.status === 'ACTIVE' && access.entry && <Banner tone="warn">{t('it.access.banner_entry', { reason: entryReasonText(access.entry.code) })}</Banner>}
      {listening && access.state === 'REFUSED' && <Banner tone="bad">{t('it.access.banner_refused', { reason: feedCodeText(access.code) })}</Banner>}
      {listening && access.state === 'UNAVAILABLE' && <Banner tone="warn">{t('it.access.banner_unavailable', { reason: feedCodeText(access.code) })}</Banner>}
      {listening && access.state === 'INVALID' && <Banner tone="bad">{t('it.access.banner_invalid', { reason: feedCodeText(access.code) })}</Banner>}
    </>
  )
}

function pairingText(access: ConnectionAccess, t: ReturnType<typeof useI18n>['t']) {
  if (!access.pairing.required) return t('it.access.pairing_off')
  if (!access.pairing.linked) return t('it.access.pairing_not_linked')
  return access.pairing.verified_nexus_tenant_id ? t('it.access.pairing_verified', { id: access.pairing.verified_nexus_tenant_id }) : t('it.access.pairing_pending')
}

/** The state of the access to the source's feed and to the pairing with OptiNexus, and whether OptiEntry itself holds the connection. */
export function AccessCard({ connection }: { connection: Connection }) {
  const { t } = useI18n()
  const access = connection.access
  if (!access) return null
  return (
    <Card title={t('it.access.title')}>
      <Facts rows={[
        [t('it.access.source'), <AccessBadge key="s" state={access.state} />],
        [t('it.access.code'), access.code && <span key="c"><span className="mono">{access.code}</span><div className="muted">{feedCodeText(access.code)}</div></span>],
        [t('it.access.since'), access.since && formatDateTime(access.since)],
        [t('it.access.checked'), access.checked_at && formatDateTime(access.checked_at)],
        [t('it.access.next'), access.next_attempt_at && formatDateTime(access.next_attempt_at)],
        [t('it.access.pairing'), pairingText(access, t)],
        [t('it.access.entry'), access.entry ? t('it.access.entry_held', { reason: entryReasonText(access.entry.code) }) : t('it.access.entry_ok')],
      ]} />
    </Card>
  )
}

/** Where the source's feed stands (its high water) next to our cursor, and what waits in our own queue. */
export function SourceCard({ summary }: { summary: ConnectionSummary | null }) {
  const { t } = useI18n()
  if (!summary?.source) return null
  const { source, backlog } = summary
  return (
    <Card title={t('it.source.title')}>
      {source.high_water === null ? <p className="muted">{t('it.source.unknown')}</p> : (
        <Facts rows={[
          [t('it.source.high_water'), formatNumber(source.high_water)],
          [t('it.source.high_water_at'), source.high_water_at && formatDateTime(source.high_water_at)],
          [t('it.source.cursor'), source.cursor === null ? '0' : formatNumber(source.cursor)],
          [t('it.source.lag'), source.lag === null ? null : t('it.source.lag_value', { n: formatNumber(source.lag) })],
        ]} />
      )}
      {backlog && (
        <Facts rows={[
          [t('it.backlog.due'), formatNumber(backlog.due)],
          [t('it.backlog.oldest'), backlog.oldest_due_age_seconds === null ? null : t('it.backlog.minutes', { n: formatNumber(Math.round(backlog.oldest_due_age_seconds / 60)) })],
          [t('it.backlog.blocked'), backlog.blocked_by && (backlog.blocked_by === 'CONNECTION_NOT_ACTIVE' ? t('it.backlog.blocked.CONNECTION_NOT_ACTIVE') : backlog.blocked_by === 'CONNECTOR_NOT_ENTITLED' ? t('it.backlog.blocked.CONNECTOR_NOT_ENTITLED') : backlog.blocked_by)],
          [t('it.backlog.stalled'), backlog.stalled && t('common.yes')],
        ]} />
      )}
      <p className="muted preview-note">{t('it.source.note')}</p>
    </Card>
  )
}

/** The alerts of the connection's health with what to do about each; nothing here changes anything. */
export function HealthCard({ connection, summary }: { connection: Connection; summary: ConnectionSummary | null }) {
  const { t } = useI18n()
  const health = summary?.health
  if (!health) return null
  return (
    <Card title={t('it.healthcard.title')} actions={<HealthBadge level={health.level} />}>
      {health.alerts.length === 0 ? <p className="muted">{t('it.health.none')}</p> : (
        <ul className="plain-list" style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 12 }}>
          {health.alerts.map((alert) => {
            const copy = alertCopy(alert)
            const filter = alertEventFilter(alert)
            return (
              <li key={alert.code}>
                <Banner tone={alert.severity === 'ALERT' ? 'bad' : 'warn'}>
                  <strong>{copy.title}</strong>
                  {typeof alert.since === 'string' && <span className="muted"> · {t('it.healthcard.since', { when: formatDateTime(alert.since) })}</span>}
                  <div>{copy.body}</div>
                  {filter && <div><Link to={filter.conflict ? `${PATHS.events}?connection_id=${connection.id}&conflict=1` : PATHS.connectionEvents(connection.id, filter.status)}>{t('it.healthcard.open_inbox')}</Link></div>}
                </Banner>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}

export function ConfigCard({ connection, onEdit }: { connection: Connection; onEdit?: () => void }) {
  const { t } = useI18n()
  return (
    <Card title={t('it.config.title')} actions={onEdit && <Button onClick={onEdit}>{t('it.config.edit')}</Button>}>
      <Facts rows={[
        [t('it.config.source'), sourceLabel(connection.source_system)],
        [t('it.config.reference'), connection.source_tenant_reference ?? t('it.config.not_set')],
        [t('it.config.url'), connection.base_url ?? t('it.config.not_set')],
        [t('it.config.credential'), connection.has_credential ? t('it.config.stored') : t('it.config.not_set')],
        [t('it.config.cutover'), connection.cutover_date ? formatDate(connection.cutover_date) : t('it.config.not_set')],
        [t('it.config.cutover_policy'), cutoverPolicyLabel(connection.cutover_policy)],
        [t('it.config.gap_policy'), <span key="g">{gapPolicyLabel(connection.gap_policy)}<div className="muted">{gapPolicyHint(connection.gap_policy)}</div></span>],
        [t('it.config.interval'), t('it.config.interval_value', { n: connection.pull_interval_minutes })],
        [t('it.config.last_pull'), connection.last_pulled_at ? formatDateTime(connection.last_pulled_at) : t('it.config.never')],
        [t('it.config.last_cursor'), connection.last_cursor && <span key="cursor" className="mono">{connection.last_cursor}</span>],
        [t('it.config.failures'), String(connection.consecutive_failures)],
      ]} />
    </Card>
  )
}

/** OptiFleet connector settings (required before the connection can be activated). Names come from the tenant's own lists; an id that cannot be resolved is not guessed. */
export function SettingsCard({ connection, onEdit }: { connection: Connection; onEdit?: () => void }) {
  const { t } = useI18n()
  const options = useSettingOptions()
  const current = settingsOf(connection)
  const cash = current.payables_cash_bank_account_id
  const category = current.expense_category_id
  const cashName = options.accounts.find((a) => a.id === cash)
  const categoryName = options.categories.find((c) => c.id === category)
  const missing = !cash || !category
  return (
    <Card title={t('it.settings.title')} actions={onEdit && <Button onClick={onEdit}>{t('it.settings.edit')}</Button>}>
      {missing && <Banner tone="warn">{t('it.settings.missing')}</Banner>}
      <Facts rows={[
        [t('it.settings.cash'), cash ? (cashName ? `${cashName.code} · ${cashName.name}` : t('it.settings.chosen')) : t('it.settings.unchosen')],
        [t('it.settings.category'), category ? (categoryName ? `${categoryName.code} · ${categoryName.name}` : t('it.settings.chosen')) : t('it.settings.unchosen')],
      ]} />
    </Card>
  )
}
