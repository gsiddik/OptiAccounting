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
  created_at: string | null
  updated_at: string | null
}

export type ConnectionSummary = {
  connection_id: string
  status: ConnectionStatus
  received: number
  posted: number
  counts: Record<string, number>
  duplicates: number
  conflicts: number
  last_cursor: string | null
  last_pulled_at: string | null
  last_error: string | null
  consecutive_failures: number
}

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

export type EventDetailData = IntegrationEvent & {
  envelope: Record<string, unknown>
  source_links: SourceLink[]
}
