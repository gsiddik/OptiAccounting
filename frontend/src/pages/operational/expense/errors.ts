import { ApiError } from '../../../lib/api'
import { fieldError } from '../../../lib/forms'
import { describeError } from '../../../lib/labels'

// Form-error helpers shared by the expense and cash/bank pages (the cash pages import this file too).

/** Refusals of the expense and cash/bank endpoints that lib/operationalLabels does not translate. */
const EXTRA_ERRORS: Record<string, string> = {
  ACCOUNT_TYPE_INVALID: 'Jenis akun ini tidak dapat dipakai di sini.',
  CASH_BANK_CODE_TAKEN: 'Akun kas/bank dengan kode ini sudah ada.',
  CASH_BANK_GL_ACCOUNT_TAKEN: 'Akun buku besar ini sudah dipakai akun kas/bank aktif lain.',
  CASH_TRANSACTION_ALREADY_REVERSED: 'Transaksi ini sudah dibalik.',
  CASH_TRANSACTION_NOT_POSTED: 'Hanya transaksi yang sudah diposting yang dapat dibalik.',
  EXPENSE_ALREADY_REVERSED: 'Beban ini sudah dibalik.',
  EXPENSE_NOT_POSTED: 'Hanya beban yang sudah diposting yang dapat dibalik.',
}

/** The same error with an Indonesian message where the shared label table has no text for its code. Pass the result on to dialogs and banners. */
export function localized(error: unknown): unknown {
  if (error instanceof ApiError && error.code && EXTRA_ERRORS[error.code]) {
    return new ApiError(error.status, EXTRA_ERRORS[error.code], error.code, error.details, error.fields)
  }
  return error
}

/**
 * The message to show under one form field: the API's validation message for it, or a business-rule refusal whose `details.field` names it.
 * (Validation errors arrive as `errors.<field>`; business rules arrive as a code and message with the field in `details`.)
 */
export function fieldMessage(error: unknown, name: string): string | undefined {
  const validation = fieldError(error, name)
  if (validation) return validation
  if (error instanceof ApiError && error.code && error.details.field === name) return describeError(localized(error))
  return undefined
}
