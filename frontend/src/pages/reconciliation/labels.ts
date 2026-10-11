import { formatAmount } from '../../lib/accounting'
import { ApiError } from '../../lib/api'
import type { ComponentStatus, ExceptionStatus, ReconComponent, ReconType } from './types'

// Indonesian wording of the reconciliation pages: types, statuses, component titles and the refusals of the reconciliation API.

type Tone = 'ok' | 'warn' | 'bad' | 'info' | 'neutral'

export const typeLabels: Record<ReconType, string> = {
  AP_GL: 'Utang vs buku besar',
  AR_GL: 'Piutang vs buku besar',
  CASH_BANK_GL: 'Kas/bank vs buku besar',
  FIXED_ASSET_GL: 'Aset tetap vs buku besar',
  TAX_GL: 'Pajak vs buku besar',
  FX_GL: 'Selisih kurs vs buku besar',
}

export const typeHints: Record<ReconType, string> = {
  AP_GL: 'Sub-buku utang terhadap akun kontrol utang usaha.',
  AR_GL: 'Sub-buku piutang terhadap akun kontrol piutang usaha.',
  CASH_BANK_GL: 'Dokumen kas dan bank terhadap jurnal pada akun kas/bank.',
  FIXED_ASSET_GL: 'Register aset tetap terhadap akun aset dan akumulasi penyusutan.',
  TAX_GL: 'Transaksi pajak terhadap akun kontrol pajak (rekonsiliasi akuntansi, bukan rekonsiliasi SPT).',
  FX_GL: 'Selisih kurs terealisasi dan saldo valas terbuka terhadap buku besar.',
}

/** `AP_GL` in a URL: `ap-gl`. */
export const slugOf = (type: string): string => type.toLowerCase().replace(/_/g, '-')

/** The type a URL slug names, or null. */
export function typeOfSlug(slug: string | undefined): ReconType | null {
  const type = (slug ?? '').toUpperCase().replace(/-/g, '_')
  return type in typeLabels ? (type as ReconType) : null
}

export const statusTexts: Record<ComponentStatus, [string, Tone]> = {
  MATCHED: ['Cocok', 'ok'],
  MISMATCH: ['Selisih', 'bad'],
  INCOMPLETE: ['Tidak lengkap', 'warn'],
  NOT_APPLICABLE: ['Tidak berlaku', 'neutral'],
  INFORMATIONAL: ['Informasi', 'info'],
}

export const statusText = (status: ComponentStatus | string | null | undefined): [string, Tone] => (status && statusTexts[status as ComponentStatus]) || [status ?? '—', 'neutral']

export const exceptionTexts: Record<ExceptionStatus, [string, Tone]> = {
  OPEN: ['Terbuka', 'warn'],
  EXPLAINED: ['Dijelaskan', 'info'],
  RESOLVED: ['Selesai', 'ok'],
}

export const exceptionText = (status: string): [string, Tone] => exceptionTexts[status as ExceptionStatus] ?? [status, 'neutral']

/** Statuses of the exception list filter; ACTIVE is every status but Selesai. */
export const exceptionFilterOptions: [string, string][] = [
  ['ACTIVE', 'Belum selesai'],
  ['OPEN', 'Terbuka'],
  ['EXPLAINED', 'Dijelaskan'],
  ['RESOLVED', 'Selesai'],
  ['', 'Semua status'],
]

export const purposeLabels: Record<string, string> = { MANUAL: 'Manual', CLOSE_CHECK: 'Pemeriksaan penutupan' }

/** The pre-OA5 page of a type, with the permission that opens it. The generic pages above them need only `accounting.reconciliation.view`. */
export const LEGACY: Partial<Record<ReconType, { path: string; permission: string }>> = {
  AP_GL: { path: '/app/akuntansi/rekonsiliasi/utang', permission: 'accounting.reconciliation.ap.view' },
  AR_GL: { path: '/app/akuntansi/rekonsiliasi/piutang', permission: 'accounting.reconciliation.ar.view' },
  CASH_BANK_GL: { path: '/app/akuntansi/rekonsiliasi/kas-bank', permission: 'accounting.reconciliation.cash_bank.view' },
  FIXED_ASSET_GL: { path: '/app/akuntansi/rekonsiliasi/aset-tetap', permission: 'accounting.asset.reconciliation.view' },
}

const SUBLEDGER: Partial<Record<ReconType, string>> = { AP_GL: 'Sub-buku utang terhadap akun kontrol utang usaha', AR_GL: 'Sub-buku piutang terhadap akun kontrol piutang usaha' }

const COMPONENTS: Record<string, string> = {
  OPENING_BALANCE: 'Saldo awal pada akun kontrol (tanpa dokumen, tidak dibandingkan)',
  CONTROL_ACCOUNT: 'Akun kontrol (seluruh saldo buku besar, tidak dibandingkan)',
  CASH_BANK: 'Dokumen kas/bank terhadap jurnalnya di buku besar',
  STATEMENT: 'Saldo rekening koran terhadap saldo buku (info rekonsiliasi bank)',
  COST: 'Harga perolehan: register terhadap akun aset',
  ACCUMULATED_DEPRECIATION: 'Akumulasi penyusutan: register terhadap akun akumulasi',
  NET_BOOK_VALUE: 'Nilai buku: register terhadap buku besar',
  TAX_OUTPUT: 'Pajak keluaran tercatat terhadap akun kontrol pajak',
  TAX_INPUT_RECOVERABLE: 'Pajak masukan yang dapat dikreditkan terhadap akun kontrol pajak',
  TAX_INPUT_NON_RECOVERABLE: 'Pajak masukan yang menjadi biaya (tercatat, tidak dibandingkan)',
  TAX_CONTROL_ACCOUNT: 'Akun kontrol pajak (seluruh saldo buku besar, tidak dibandingkan)',
  REALISED_FX_GAIN: 'Laba selisih kurs terealisasi: penyelesaian terhadap akun laba kurs',
  REALISED_FX_LOSS: 'Rugi selisih kurs terealisasi: penyelesaian terhadap akun rugi kurs',
  FX_ACCOUNT: 'Akun selisih kurs (seluruh saldo buku besar, tidak dibandingkan)',
}

/** Indonesian title of a component by its code; an unknown code keeps the label the API sent. */
export function componentTitle(type: ReconType, c: ReconComponent): string {
  const [base, qualifier] = c.code.split(':')
  if (base === 'SUBLEDGER_VS_GL') return SUBLEDGER[type] ?? c.label
  if (base === 'FOREIGN_OPEN_AP') return `Utang valas terbuka (${qualifier}): nilai tercatat terhadap akun kontrol`
  if (base === 'FOREIGN_OPEN_AR') return `Piutang valas terbuka (${qualifier}): nilai tercatat terhadap akun kontrol`
  return COMPONENTS[base] ?? c.label
}

/** The account or bank account a component is about, e.g. "1120 · Bank BCA". */
export function componentReference(c: ReconComponent): string | null {
  const ref = c.reference
  return ref?.code ? [ref.code, ref.name].filter(Boolean).join(' · ') : null
}

const ERRORS: Record<string, string> = {
  RECON_EXCEPTION_RESOLVED: 'Pengecualian ini sudah selesai dan menjadi riwayat. Selisih baru pada tanggal yang sama akan membuka pengecualian baru.',
  RECON_OWNER_INVALID: 'Pemilik harus anggota aktif organisasi ini.',
  RECONCILIATION_EXCEPTION_NOT_FOUND: 'Pengecualian tidak ditemukan.',
  RECONCILIATION_TYPE_UNKNOWN: 'Jenis rekonsiliasi tidak dikenal.',
  AS_OF_PERIOD_CONFLICT: 'Tanggal dan periode menunjuk tanggal potong yang berbeda; pilih salah satu.',
  AS_OF_INVALID: 'Tanggal potong tidak valid.',
  PERIOD_NOT_FOUND: 'Periode akuntansi tidak ditemukan.',
}

/**
 * The same refusal with an Indonesian message when it is one of the reconciliation codes. RECON_STILL_MISMATCHED says what the server found when it
 * recomputed (its difference), because an explanation never changes the result and the person must see why the exception stays open.
 */
export function reconError(error: unknown): unknown {
  if (!(error instanceof ApiError) || !error.code) return error
  if (error.code === 'RECON_STILL_MISMATCHED') {
    const d = error.details
    const figure = typeof d.difference === 'string' ? ` Selisih hasil hitung ulang: ${formatAmount(d.difference)} (status ${statusText(typeof d.status === 'string' ? d.status : null)[0]}).` : ''
    return new ApiError(error.status, `Rekonsiliasi masih tidak cocok setelah dihitung ulang, jadi pengecualian belum dapat diselesaikan.${figure} Perbaiki penyebabnya di buku besar atau sub-buku; penjelasan tidak mengubah hasil.`, error.code, error.details, error.fields)
  }
  const text = ERRORS[error.code]
  return text ? new ApiError(error.status, text, error.code, error.details, error.fields) : error
}

export const isStillMismatched = (error: unknown): boolean => error instanceof ApiError && error.code === 'RECON_STILL_MISMATCHED'
