import { ApiError } from '../../lib/api'

// Indonesian wording of the OA5 filters of the trial balance, the general ledger and the account balance report.

const ERRORS: Record<string, string> = {
  DATE_FILTER_CONFLICT: 'Pilih satu cara menentukan tanggal: periode, tahun fiskal atau rentang tanggal, atau per tanggal; tidak dicampur.',
  DATE_FILTER_INVALID: 'Jenis tanggal ini tidak cocok dengan laporan yang diminta.',
  ACCOUNT_RANGE_INVALID: 'Kode akun awal berada setelah kode akun akhir.',
  CURRENCY_FILTER_UNAVAILABLE: 'Filter mata uang asing membutuhkan modul multi-mata uang. Mata uang fungsional selalu dapat dipilih.',
  RANGE_OUTSIDE_FISCAL_YEAR: 'Tanggal harus berada di dalam tahun fiskal yang dipilih.',
}

/** The same refusal with an Indonesian message when it is one of the filter codes of these reports. */
export function ledgerError(error: unknown): unknown {
  if (!(error instanceof ApiError) || !error.code) return error
  const text = ERRORS[error.code]
  return text ? new ApiError(error.status, text, error.code, error.details, error.fields) : error
}

export const ACCOUNT_TYPE_OPTIONS: [string, string][] = [
  ['ASSET', 'Aset'],
  ['LIABILITY', 'Kewajiban'],
  ['EQUITY', 'Ekuitas'],
  ['REVENUE', 'Pendapatan'],
  ['EXPENSE', 'Beban'],
]

/** Source types the posting engine and the sub-ledgers write; the source filter accepts any text, these are only suggestions. */
export const SOURCE_TYPE_SUGGESTIONS = ['AP_INVOICE', 'AR_INVOICE', 'AR_CREDIT_NOTE', 'VENDOR_PAYMENT', 'CUSTOMER_RECEIPT', 'EXPENSE', 'YEAR_END_CLOSING']

export const NOT_COMPLETE_NOTE = 'Laporan ini hanya memuat sebagian data: cakupan data Anda, filter dimensi, akun, mata uang, sumber, atau jenis jurnal membatasi barisnya, sehingga total tidak mencerminkan seluruh buku besar.'
