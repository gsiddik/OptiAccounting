import { useMemo } from 'react'
import { ApiError } from '../../../lib/api'
import { fieldError } from '../../../lib/forms'
import { useAction } from '../../../lib/hooks'
import { describeError } from '../../../lib/labels'

// Indonesian texts for payables refusals that the shared label tables do not cover yet. The shared components render errors with
// `describeError`, which falls back to the ApiError message, so a localized copy of the error is enough.

const EXTRA_ERRORS: Record<string, string> = {
  VENDOR_CODE_TAKEN: 'Vendor dengan kode ini sudah ada.',
  VENDOR_EXTERNAL_REFERENCE_TAKEN: 'Vendor lain sudah memakai referensi eksternal ini.',
  AP_INVOICE_ALREADY_REVERSED: 'Faktur ini sudah dibalik.',
  AP_INVOICE_NOT_POSTED: 'Hanya faktur yang sudah diposting yang dapat dibalik.',
  AP_PAYMENT_ALREADY_REVERSED: 'Pembayaran ini sudah dibalik.',
  AP_PAYMENT_NOT_POSTED: 'Hanya pembayaran yang sudah diposting yang dapat dibalik.',
  ACCOUNT_TYPE_INVALID: 'Tipe akun ini tidak dapat dipakai untuk isian tersebut.',
}

/** The same error with an Indonesian message when its code is one of the extra ones above; any other value is returned untouched. */
export function localizeError(error: unknown): unknown {
  if (error instanceof ApiError && error.code && EXTRA_ERRORS[error.code]) {
    return new ApiError(error.status, EXTRA_ERRORS[error.code], error.code, error.details, error.fields)
  }
  return error
}

/** `useAction` whose error is already localized. */
export function useAct() {
  const action = useAction()
  const error = useMemo(() => localizeError(action.error), [action.error])
  return { ...action, error }
}

/** Validation message for a field: Laravel's `errors.<field>`, or a domain refusal whose `details.field` names the field. */
export function fieldMessage(error: unknown, name: string): string | undefined {
  const validation = fieldError(error, name)
  if (validation) return validation
  if (error instanceof ApiError && error.details.field === name) return describeError(localizeError(error))
  return undefined
}

/** Wording for a refusal shown on the line it points at, where the general text (written for journals) would mislead. */
const LINE_WORDING: Record<string, string> = { LINE_AMOUNT_INVALID: 'Jumlah baris harus lebih besar dari nol.' }

/** The message of a domain refusal that points at line `number` (1-based) of a document, if it does. */
export function lineMessage(error: unknown, number: number): string | undefined {
  if (!(error instanceof ApiError) || error.details.line !== number) return undefined
  return (error.code && LINE_WORDING[error.code]) || describeError(localizeError(error))
}

/** The message of a domain refusal that points at allocation `number` (1-based, in the order the allocations were sent), if it does. */
export function allocationMessage(error: unknown, number: number): string | undefined {
  if (error instanceof ApiError && error.details.allocation === number) return describeError(localizeError(error))
  return undefined
}

/** Text of any error for a banner or a notice. */
export function errorText(error: unknown): string {
  return describeError(localizeError(error))
}

/** A refusal that points at allocation N of the request (validation key `allocations.N-1.amount` or `details.allocation`) is shown on that invoice's row. */
export function allocationErrorFor(error: unknown, sentIds: string[], invoiceId: string): string | undefined {
  const index = sentIds.indexOf(invoiceId)
  return index < 0 ? undefined : (fieldMessage(error, `allocations.${index}.amount`) ?? allocationMessage(error, index + 1))
}
