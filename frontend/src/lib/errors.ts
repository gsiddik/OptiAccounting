import { ApiError } from './api'

/**
 * What kind of trouble a failure is, for the screens that explain it (error boundaries, pages that depend on OptiNexus).
 * The kind decides the title and the action; the text under it still comes from the server's code when there is one.
 */
export type ErrorKind = 'offline' | 'session' | 'forbidden' | 'entitlement' | 'provider' | 'setup' | 'not_found' | 'server' | 'chunk' | 'unexpected'

const ENTITLEMENT = new Set(['MODULE_NOT_ENTITLED', 'MODULE_READ_ONLY', 'FEATURE_NOT_ENTITLED', 'SUBSCRIPTION_INACTIVE', 'SUBSCRIPTION_READ_ONLY', 'APPLICATION_NOT_SUBSCRIBED', 'MODULE_NOT_AVAILABLE'])
const SETUP = new Set(['DELEGATION_NOT_CONFIGURED', 'DELEGATION_NOT_AVAILABLE', 'DELEGATION_TENANT_UNKNOWN', 'TENANT_NOT_LINKED', 'ACTOR_NOT_LINKED'])
const PROVIDER = new Set(['IDENTITY_PROVIDER_UNAVAILABLE'])

/** A lazily loaded route or module that the browser could not fetch (usually a deploy replaced the file). */
export function isChunkLoadError(error: unknown): boolean {
  const text = error instanceof Error ? `${error.name} ${error.message}` : String(error ?? '')
  return /ChunkLoadError|Loading chunk .* failed|Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed/i.test(text)
}

export function isOffline(): boolean {
  return typeof navigator !== 'undefined' && navigator.onLine === false
}

export function classifyError(error: unknown): ErrorKind {
  if (isChunkLoadError(error)) return 'chunk'
  if (error instanceof ApiError) {
    if (error.status === 0) return isOffline() ? 'offline' : 'server'
    if (error.code && PROVIDER.has(error.code)) return 'provider'
    if (error.code && SETUP.has(error.code)) return 'setup'
    if (error.code && ENTITLEMENT.has(error.code)) return 'entitlement'
    if (error.status === 401) return 'session'
    if (error.status === 403) return 'forbidden'
    if (error.status === 404) return 'not_found'
    if (error.status >= 500) return 'server'
    return 'unexpected'
  }
  if (isOffline()) return 'offline'
  return 'unexpected'
}

/**
 * An API refusal put in the wording of a screen (in the language chosen at the top). `describeError` shows its message as it is, and the
 * request id of the original refusal is still the reference a person quotes.
 */
export class WordedError extends Error {
  readonly source: ApiError

  constructor(message: string, source: ApiError) {
    super(message)
    this.name = 'WordedError'
    this.source = source
  }
}

/** The reference to quote to support: the server's request id (also written in its logs and audit trail), else the one a delegation answer carries. */
export function errorReference(error: unknown): string | null {
  if (error instanceof WordedError) return errorReference(error.source)
  if (!(error instanceof ApiError)) return null
  if (error.requestId) return error.requestId
  const reference = error.details.reference
  return typeof reference === 'string' && reference !== '' ? reference : null
}
