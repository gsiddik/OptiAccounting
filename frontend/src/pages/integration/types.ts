// Shapes of the integration API (backend: Domain/Integration/Services/{ConnectionService,EventReader}). Everything shown comes from these responses.

export const CONNECTION_STATUSES = ['DRAFT', 'CONFIGURING', 'READY', 'ACTIVE', 'PAUSED', 'ERROR', 'DISCONNECTED'] as const
export type ConnectionStatus = (typeof CONNECTION_STATUSES)[number]

export const EVENT_STATUSES = ['RECEIVED', 'VALIDATED', 'POSTED', 'NEEDS_REVIEW', 'REJECTED', 'FAILED', 'DEAD_LETTER'] as const
export type EventStatus = (typeof EVENT_STATUSES)[number]

/** The states a person may put back in the queue (the API refuses the others with EVENT_NOT_RETRYABLE). */
export const RETRYABLE: readonly string[] = ['NEEDS_REVIEW', 'FAILED', 'DEAD_LETTER']

export type CutoverPolicy = 'OPENING_BALANCE_ONLY' | 'START_FROM_CUTOVER_DATE'

/** Tenant configuration of the OptiFleet connector: ids of a tenant cash/bank account and expense category. */
export type ConnectionSettings = { payables_cash_bank_account_id?: string | null; expense_category_id?: string | null }

/** What the last contact with the source's feed showed (backend: ConnectionHealth::access). */
export const FEED_ACCESS = ['UNKNOWN', 'OK', 'REFUSED', 'UNAVAILABLE', 'INVALID'] as const
export type FeedAccess = (typeof FEED_ACCESS)[number]

/** Why OptiEntry itself does not run a connection right now (its own tenant, module, feature, link or connector entitlement). */
export type EntryHold = { code: string; reasons: string[] }

export type ConnectionAccess = {
  state: FeedAccess
  /** The source's own refusal/failure code (FEED_*), when it gave one. */
  code: string | null
  since: string | null
  checked_at: string | null
  next_attempt_at: string | null
  pairing: { required: boolean; linked: boolean; verified_nexus_tenant_id: string | null }
  entry: EntryHold | null
}

export type OpenGap = { id: string; open_reason: string; policy: string; sequence_at_open: number; opened_at: string }

export type HealthLevel = 'OK' | 'WARN' | 'ALERT'
export type HealthAlert = { code: string; severity: 'WARN' | 'ALERT'; reason?: string | null; reasons?: string[]; since?: string | null; count?: number; cursor?: number; high_water?: number; minutes?: number; due?: number | null }
export type ConnectionHealth = { level: HealthLevel; alerts: HealthAlert[] }

/** Where the source's feed stands relative to what was consumed. `lag` is events committed at the source and not pulled yet. */
export type SourceFigures = { high_water: number | null; high_water_at: string | null; cursor: number | null; lag: number | null }

export type Connection = {
  id: string
  name: string
  source_system: string
  source_tenant_reference: string | null
  status: ConnectionStatus
  base_url: string | null
  /** The credential itself is write-only and never returned. */
  has_credential: boolean
  cutover_policy: string
  cutover_date: string | null
  gap_policy: string
  pull_interval_minutes: number
  last_cursor: string | null
  last_pulled_at: string | null
  last_error: string | null
  consecutive_failures: number
  settings?: ConnectionSettings | null
  open_gap?: OpenGap | null
  /** Absent on an older server; the pages then show no health, never a guessed one. */
  access?: ConnectionAccess
  health_level?: HealthLevel
  created_at: string | null
  updated_at: string | null
}

/** What processing decided for the books (`accounting_outcome`). */
export const OUTCOMES = ['JOURNAL_POSTED', 'AWAITING_APPROVAL', 'DRAFT_DOCUMENT', 'INFORMATIONAL', 'NO_EFFECT', 'UNCLASSIFIED'] as const

/** Where a processed event stands in the books now, read live from its document (backend: BookStatus). Only IN_LEDGER is in the general ledger. */
export const BOOK_STATES = ['IN_LEDGER', 'AWAITING_APPROVAL', 'DRAFT', 'REVERSED', 'VOID', 'INFORMATIONAL', 'NO_EFFECT', 'UNCLASSIFIED'] as const
export type BookState = (typeof BOOK_STATES)[number]

export type Backlog = { due: number; oldest_due_at: string | null; oldest_due_age_seconds: number | null; blocked_by: string | null; stalled: boolean }

export type ConnectionSummary = {
  connection_id: string
  status: ConnectionStatus
  received: number
  /** Events whose processing finished (status POSTED). Not "in the ledger": see `books`. */
  posted: number
  counts: Record<string, number>
  outcomes?: Record<string, number>
  duplicates: number
  conflicts: number
  backlog?: Backlog
  open_gap?: OpenGap | null
  access?: ConnectionAccess
  source?: SourceFigures
  health?: ConnectionHealth
  /** Live count of processed events by book state. */
  books?: Record<string, number>
  last_cursor: string | null
  last_pulled_at: string | null
  last_error: string | null
  consecutive_failures: number
}

export type GapEvents = { total: number; held: number; processed: number; refused: number }
export type Gap = {
  id: string
  connection_id: string
  status: 'OPEN' | 'CLOSED'
  open_reason: string
  policy: string
  sequence_at_open: number
  ceiling_sequence: number | null
  new_cutover_date: string | null
  opened_at: string
  closed_at: string | null
  events: GapEvents
}
export type GapResolution = { decision: 'PROCESS' | 'DISMISS'; handled: number; processed: number; remaining: number }

/** What "sync now" answers: this run's counts. */
export type SyncResult = { pages: number; received: number; duplicates: number; conflicts: number; processed: number; posted: number; error: string | null }

export type IntegrationEvent = {
  id: string
  connection_id: string
  source_system: string
  event_id: string
  event_type: string
  schema_version: string
  occurred_at: string
  sequence: number | null
  status: EventStatus
  attempts: number
  next_attempt_at: string | null
  last_error_code: string | null
  last_error: string | null
  conflict: boolean
  duplicate_count: number
  resulting_journal_id: string | null
  received_at: string
  processed_at: string | null
  /** What processing decided for the books, once finished; null before, or when it cannot be told. */
  accounting_outcome?: string | null
  /** Status of the document when it was processed (informational; the live one is `books`). */
  document_status?: string | null
  gap_id?: string | null
  /** The list only: the live book state of a processed event. */
  books_state?: BookState | null
}

export type SourceLink = {
  id: string
  journal_id: string | null
  document_type: string | null
  document_id: string | null
  source_system: string
  source_type: string
  source_id: string
  source_document_number: string | null
  source_event_id: string | null
  created_at: string | null
}

export type EventConflict = {
  id: string
  received_hash: string
  received_at: string
  envelope: Record<string, unknown>
  resolution: 'USE_ORIGINAL' | 'REJECT' | null
  resolution_reason: string | null
  resolved_at: string | null
}

export type BookDocument = {
  document_type: string
  document_id: string
  status: string
  state: BookState | null
  journal_id: string | null
  journal_status: string | null
  source_type: string
  source_id: string
}

export type EventDetailData = IntegrationEvent & {
  envelope: Record<string, unknown>
  conflicts?: EventConflict[]
  books?: { state: BookState | null; documents: BookDocument[] }
  source_links: SourceLink[]
}
