import { ApiError } from './api'

/** First validation message the API returned for a field (nested keys use dots, e.g. "admin.email"). */
export function fieldError(error: unknown, name: string): string | undefined {
  return error instanceof ApiError ? error.fields[name]?.[0] : undefined
}
