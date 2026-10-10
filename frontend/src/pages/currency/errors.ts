import { ApiError } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { describeError } from '../../lib/labels'

/** The message to show under one form field: the API's validation message for it, or a business-rule refusal whose `details.field` names it. */
export function fieldMessage(error: unknown, name: string): string | undefined {
  const validation = fieldError(error, name)
  if (validation) return validation
  if (error instanceof ApiError && error.code && error.details.field === name) return describeError(error)
  return undefined
}
