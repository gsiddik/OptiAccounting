import { currentLocale, hasMessage, translate, type MessageKey, type Params } from '../../i18n'
import { ACCOUNTING_ERRORS } from '../../lib/accountingLabels'
import { ApiError } from '../../lib/api'
import { WordedError } from '../../lib/errors'
import { OA4_ERRORS } from '../../lib/oa4Labels'
import { OPERATIONAL_ERRORS } from '../../lib/operationalLabels'
import type { HealthAlert } from './types'

// Wording of the integration pages in the language chosen at the top: statuses, event types, processing error codes, what the source said
// and the refusals of the integration API. Every text lives in i18n/messages/integration.ts (one entry carries both languages).

export type Tone = 'ok' | 'warn' | 'bad' | 'info' | 'neutral'
type Text = [string, Tone]

/** The catalog text `<prefix>.<key>` with a tone; a key the catalog does not know is shown as it was sent. */
function pick(prefix: string, tones: Record<string, Tone>, key: string | null | undefined): Text {
  const full = `${prefix}.${key ?? ''}`
  return key && hasMessage(full) ? [translate(full), tones[key] ?? 'neutral'] : [key ?? '—', 'neutral']
}

/** Plain text of a catalog key built at run time (a server code); the code itself when there is no text for it. */
function lookup(prefix: string, key: string | null | undefined, params?: Params): string {
  const full = `${prefix}.${key ?? ''}`
  return key && hasMessage(full) ? translate(full, params) : (key ?? '—')
}

const CONNECTION_TONES: Record<string, Tone> = { DRAFT: 'neutral', CONFIGURING: 'info', READY: 'info', ACTIVE: 'ok', PAUSED: 'warn', ERROR: 'bad', DISCONNECTED: 'neutral' }
export const connectionText = (status: string | null | undefined): Text => pick('it.conn', CONNECTION_TONES, status)

const EVENT_TONES: Record<string, Tone> = { RECEIVED: 'info', VALIDATED: 'info', POSTED: 'ok', NEEDS_REVIEW: 'warn', REJECTED: 'bad', FAILED: 'bad', DEAD_LETTER: 'bad' }
export const eventStatusText = (status: string | null | undefined): Text => pick('it.event', EVENT_TONES, status)

/** The short explanation of an event status on the detail page, if the status has one. */
export function statusHint(status: string): string | undefined {
  const key = `it.hint.${status}`
  return hasMessage(key) ? translate(key) : undefined
}

// What processing decided for the books (`accounting_outcome`) and where the books stand now (`books_state`, read live). Two different
// questions, never merged into the processing status (ecosystem audit A27).
const OUTCOME_TONES: Record<string, Tone> = { JOURNAL_POSTED: 'ok', AWAITING_APPROVAL: 'warn', DRAFT_DOCUMENT: 'warn', INFORMATIONAL: 'info', NO_EFFECT: 'neutral', UNCLASSIFIED: 'neutral', PENDING: 'neutral' }
export const outcomeText = (outcome: string | null | undefined): Text => pick('it.outcome', OUTCOME_TONES, outcome ?? 'UNCLASSIFIED')

const BOOK_TONES: Record<string, Tone> = { IN_LEDGER: 'ok', AWAITING_APPROVAL: 'warn', DRAFT: 'warn', REVERSED: 'neutral', VOID: 'neutral', INFORMATIONAL: 'info', NO_EFFECT: 'neutral', UNCLASSIFIED: 'neutral' }
export const bookText = (state: string | null | undefined): Text => pick('it.books', BOOK_TONES, state)
export const bookHint = (state: string | null | undefined): string | undefined => {
  const key = `it.books.hint.${state ?? ''}`
  return state && hasMessage(key) ? translate(key) : undefined
}

export const documentStatusText = (status: string | null | undefined): string => lookup('it.doc', status)
export const documentTypeText = (type: string | null | undefined): string => lookup('it.doc', type)

const ACCESS_TONES: Record<string, Tone> = { UNKNOWN: 'neutral', OK: 'ok', REFUSED: 'bad', UNAVAILABLE: 'warn', INVALID: 'bad' }
export const accessText = (state: string | null | undefined): Text => pick('it.access', ACCESS_TONES, state)

const HEALTH_TONES: Record<string, Tone> = { OK: 'ok', WARN: 'warn', ALERT: 'bad' }
export const healthText = (level: string | null | undefined): Text => (level ? pick('it.health', HEALTH_TONES, level) : [translate('it.health.unknown'), 'neutral'])

/** What the source (or this application's check of its answer) said, by its FEED_* / SOURCE_* code; the code itself when unknown. */
export const feedCodeText = (code: string | null | undefined): string => (code ? lookup('it.feed', code) : '')

/** Why OptiEntry itself holds a connection. */
export const entryReasonText = (code: string | null | undefined): string => lookup('it.entry', code)

export const sourceSystemLabels: Record<string, string> = { optifleet: 'OptiFleet' }
export const sourceLabel = (system: string): string => sourceSystemLabels[system] ?? system

const EVENT_TYPES = [
  'optifleet.workshop_invoice.recorded',
  'optifleet.workshop_invoice.corrected',
  'optifleet.workshop_invoice.cancelled',
  'optifleet.workshop_invoice.payment_recorded',
  'optifleet.maintenance_memo.billed',
  'optifleet.maintenance_memo.paid',
]
export const KNOWN_EVENT_TYPES: readonly string[] = EVENT_TYPES
/** The readable name of an event type; an unknown type is shown as it was sent. */
export function eventTypeLabel(type: string): string {
  for (const candidate of [type, `optifleet.${type}`]) {
    const key = `it.type.${candidate}`
    if (hasMessage(key)) return translate(key)
  }
  return type
}

export const cutoverPolicyLabel = (policy: string): string => lookup('it.cutover', policy)
export const gapPolicyLabel = (policy: string): string => lookup('it.gappolicy', policy)
export const gapPolicyHint = (policy: string): string | undefined => {
  const key = `it.gappolicy.hint.${policy}`
  return hasMessage(key) ? translate(key) : undefined
}
export const gapReasonLabel = (reason: string): string => lookup('it.gap.reason', reason)

/** Field names the API lists (`missing`, `fields`); a setting is listed as `settings.<key>`. */
function fieldNames(list: unknown): string {
  return Array.isArray(list) ? list.map((f) => lookup('it.field', String(f).replace(/^settings\./, ''))).join(', ') : ''
}

/**
 * How the processing of an event ended (`last_error_code`) and what to do about it. In Indonesian the accounting tables of the other
 * screens fill in the codes this catalog does not list; in English the API's own message does, so no Indonesian shows in English.
 */
export function processingText(code: string | null | undefined, message?: string | null): string {
  if (!code) return message ?? '—'
  const key = `it.code.${code}`
  if (hasMessage(key)) return translate(key)
  if (currentLocale() === 'id') return ACCOUNTING_ERRORS[code] ?? OPERATIONAL_ERRORS[code] ?? OA4_ERRORS[code] ?? message ?? code
  return message ?? code
}

/** The title and explanation of one health alert, with the figures it carries. */
export function alertCopy(alert: HealthAlert): { title: string; body: string } {
  const titleKey = `it.alert.${alert.code}.title`
  if (!hasMessage(titleKey)) return { title: translate('it.alert.unknown.title'), body: alert.code }
  const reason = alert.code === 'ENTRY_HELD' ? entryReasonText(alert.reason) : feedCodeText(alert.reason)
  const params: Params = { reason, count: alert.count ?? 0, minutes: alert.minutes ?? 0, cursor: alert.cursor, high_water: alert.high_water, due: alert.due ?? 0 }
  const bodyKey = `it.alert.${alert.code}.body`
  return { title: translate(titleKey), body: hasMessage(bodyKey) ? translate(bodyKey, params).trim() : '' }
}

/** The inbox filter an alert points at, when there is one. */
export function alertEventFilter(alert: HealthAlert): { status?: string; conflict?: boolean } | null {
  switch (alert.code) {
    case 'MISSING_RECORDED_EVENT':
    case 'QUARANTINED_EVENTS':
      return { status: 'NEEDS_REVIEW' }
    case 'DEAD_LETTERS':
      return { status: 'DEAD_LETTER' }
    case 'OPEN_CONFLICTS':
      return { conflict: true }
    default:
      return null
  }
}

/**
 * The refusals of the integration API in the language chosen at the top. The result keeps the request id of the original refusal;
 * any other error passes through unchanged (describeError then shows the API's own message).
 */
export function integrationError(error: unknown): unknown {
  if (!(error instanceof ApiError) || !error.code) return error
  const key = `it.refusal.${error.code}`
  if (!hasMessage(key)) return error
  const d = error.details
  const params: Params = {
    missing: fieldNames(d.missing) || translate('it.field.some_empty'),
    fields: fieldNames(d.fields) || translate('it.field.locked_default'),
    reason: feedCodeText(typeof d.feed_code === 'string' ? d.feed_code : null),
  }
  const reasons = ['CONNECTOR_NOT_ENTITLED', 'TENANT_NOT_LINKED', 'CONNECTION_HELD'].includes(error.code) && Array.isArray(d.reasons) ? translate('it.refusal.reasons', { reasons: d.reasons.join('; ') }) : ''
  return new WordedError(translate(key as MessageKey, params).trim() + reasons, error)
}
