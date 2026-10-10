import { ApiError } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { formatDate } from '../../lib/format'
import { describeError } from '../../lib/labels'

/**
 * The same refusal with wording for the situation it came from, where the shared label table (written for one situation) would mislead.
 * The shared helper looks texts up by code, so the reworded copy carries a derived code; anything else is returned untouched.
 */
export function taxError(error: unknown, context?: 'update'): unknown {
  if (!(error instanceof ApiError) || !error.code) return error
  let message: string | null = null
  if (error.code === 'TAX_RATE_OVERLAP' && typeof error.details.latest_effective_from === 'string') {
    message = `Tarif baru harus berlaku setelah tarif terakhir kode ini, yang mulai berlaku ${formatDate(error.details.latest_effective_from)}.`
  } else if (error.code === 'TAX_CODE_IN_USE' && context === 'update') {
    message = 'Kode pajak ini sudah dipakai dokumen, sehingga jenis, metode, perlakuan, dan pengaturan dapat dikreditkannya tidak dapat diubah lagi. Buat kode pajak baru.'
  }
  return message ? new ApiError(error.status, message, `${error.code}_DETAIL`, error.details, error.fields) : error
}

/** The message to show under one form field: the API's validation message for it, or a business-rule refusal whose `details.field` names it. */
export function fieldMessage(error: unknown, name: string): string | undefined {
  const validation = fieldError(error, name)
  if (validation) return validation
  if (error instanceof ApiError && error.code && error.details.field === name) return describeError(error)
  return undefined
}
