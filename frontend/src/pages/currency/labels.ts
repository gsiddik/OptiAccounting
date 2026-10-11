import { rateTypeLabels } from '../../lib/oa4Labels'

export { rateTypeLabels }

/** "16250,5" typed by a person becomes the decimal string the API expects; the digits are never changed. */
export const decimalText = (value: string): string => value.trim().replace(',', '.')

/** Account roles the foreign settlement rules post to, in plain words (the API's own role catalog needs a permission not every user has). */
export const fxRoleLabels: Record<string, string> = {
  ACCOUNTS_PAYABLE: 'Utang usaha',
  ACCOUNTS_RECEIVABLE: 'Piutang usaha',
  FX_GAIN: 'Laba selisih kurs (terealisasi)',
  FX_LOSS: 'Rugi selisih kurs (terealisasi)',
  CASH_BANK_ACCOUNT: 'Akun kas/bank pada dokumen',
}

export const fxSkipReasons: Record<string, string> = {
  ALREADY_PUBLISHED: 'sudah ada aturan yang terbit',
  CODE_TAKEN: 'kode aturan bawaan sudah dipakai aturan lain',
}
