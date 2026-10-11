import { ApiError } from '../../lib/api'
import { ACCOUNTING_ERRORS } from '../../lib/accountingLabels'
import { OA4_ERRORS } from '../../lib/oa4Labels'
import { OPERATIONAL_ERRORS } from '../../lib/operationalLabels'

// Indonesian wording of the integration pages: statuses, event types, processing error codes and the refusals of the integration API.

export type Tone = 'ok' | 'warn' | 'bad' | 'info' | 'neutral'
type Text = [string, Tone]

const pick = (table: Record<string, Text>, key: string | null | undefined): Text => (key && table[key]) || [key ?? '—', 'neutral']

const CONNECTION: Record<string, Text> = {
  DRAFT: ['Draf', 'neutral'],
  CONFIGURING: ['Sedang dikonfigurasi', 'info'],
  READY: ['Siap diaktifkan', 'info'],
  ACTIVE: ['Aktif', 'ok'],
  PAUSED: ['Dijeda', 'warn'],
  ERROR: ['Galat', 'bad'],
  DISCONNECTED: ['Terputus', 'neutral'],
}
export const connectionText = (status: string | null | undefined): Text => pick(CONNECTION, status)

const EVENT: Record<string, Text> = {
  RECEIVED: ['Diterima', 'info'],
  VALIDATED: ['Tervalidasi', 'info'],
  POSTED: ['Diposting', 'ok'],
  NEEDS_REVIEW: ['Perlu ditinjau', 'warn'],
  REJECTED: ['Ditolak', 'bad'],
  FAILED: ['Gagal, dicoba lagi', 'bad'],
  DEAD_LETTER: ['Gagal permanen', 'bad'],
}
export const eventStatusText = (status: string | null | undefined): Text => pick(EVENT, status)

export const sourceSystemLabels: Record<string, string> = { optifleet: 'OptiFleet' }
export const sourceLabel = (system: string): string => sourceSystemLabels[system] ?? system

const EVENT_TYPES: Record<string, string> = {
  'optifleet.workshop_invoice.recorded': 'Faktur bengkel dicatat',
  'optifleet.workshop_invoice.corrected': 'Faktur bengkel dikoreksi',
  'optifleet.workshop_invoice.cancelled': 'Faktur bengkel dibatalkan',
  'optifleet.workshop_invoice.payment_recorded': 'Pembayaran faktur bengkel',
  'optifleet.maintenance_memo.billed': 'Memo perawatan (informasi)',
  'optifleet.maintenance_memo.paid': 'Memo perawatan (informasi)',
}
export const KNOWN_EVENT_TYPES = Object.keys(EVENT_TYPES)
/** The readable name of an event type; an unknown type is shown as it was sent. */
export const eventTypeLabel = (type: string): string => EVENT_TYPES[type] ?? EVENT_TYPES[`optifleet.${type}`] ?? type

export const cutoverPolicyLabels: Record<string, string> = {
  OPENING_BALANCE_ONLY: 'Saldo awal saja, lalu kejadian sejak tanggal cutover',
  START_FROM_CUTOVER_DATE: 'Mulai dari tanggal cutover',
  HISTORICAL_BACKFILL: 'Isi ulang riwayat',
}
export const gapPolicyLabels: Record<string, string> = {
  REQUIRES_REVIEW: 'Perlu ditinjau',
  IGNORE_GAP: 'Abaikan jeda',
  BACKFILL_GAP: 'Isi jeda',
  START_NEW_CUTOVER: 'Mulai cutover baru',
}

const FIELDS: Record<string, string> = {
  credential: 'kredensial',
  base_url: 'URL sumber',
  source_tenant_reference: 'referensi tenant sumber',
  cutover_date: 'tanggal cutover',
  cutover_policy: 'kebijakan cutover',
  payables_cash_bank_account_id: 'akun kas/bank pembayaran utang',
  expense_category_id: 'kategori beban',
}
/** Field names the API lists (`missing`, `fields`); a setting is listed as `settings.<key>`. */
const fieldNames = (list: unknown): string => (Array.isArray(list) ? list.map((f) => FIELDS[String(f).replace(/^settings\./, '')] ?? String(f)).join(', ') : '')

/** How the processing of an event ended (`last_error_code`) and what to do about it. The API's own message is the fallback for any other code. */
const PROCESSING: Record<string, string> = {
  CURRENCY_NOT_SUPPORTED: 'Mata uang kejadian ini belum didukung; hanya mata uang fungsional yang dibukukan. Tidak ada jurnal yang dibuat.',
  INVOICE_NOT_FOUND: 'Faktur yang dirujuk belum ada di OptiEntry (misalnya pembayaran tiba sebelum fakturnya). Ulangi setelah faktur dibukukan.',
  PAYMENT_ALREADY_APPLIED: 'Faktur ini sudah punya pembayaran di OptiEntry, jadi koreksi atau pembatalan tidak dijalankan otomatis. Tinjau pembayarannya lebih dulu.',
  NO_ADAPTER: 'Belum ada adaptor untuk sistem sumber ini, jadi kejadian disimpan tanpa diproses.',
  UNSUPPORTED_EVENT: 'Jenis kejadian atau versi skema ini belum didukung adaptor.',
  BEFORE_CUTOVER: 'Kejadian terjadi sebelum tanggal cutover, sehingga tidak dibukukan (riwayat tidak pernah diposting otomatis).',
  MONEY_NOT_DECIMAL_STRING: 'Nilai uang dikirim sebagai angka JSON, bukan teks desimal, sehingga kejadian ditolak.',
  CONNECTION_SETTINGS_MISSING: 'Pengaturan koneksi OptiFleet belum lengkap (akun kas/bank pembayaran dan kategori beban). Lengkapi di halaman koneksi, lalu ulangi.',
  VENDOR_MISSING: 'Faktur dari sumber tidak menyebut vendor, jadi tidak dapat dibukukan.',
  VENDOR_AMBIGUOUS: 'Lebih dari satu vendor di OptiEntry memakai NPWP yang sama. Petakan vendor OptiFleet secara manual, lalu ulangi.',
  INVOICE_NOT_POSTED: 'Faktur yang dibayar belum diposting, sehingga pembayaran belum dapat diterapkan. Posting fakturnya lalu ulangi.',
  TOTAL_MISMATCH: 'Baris, diskon dan pajak pada faktur sumber tidak menjumlah ke totalnya, sehingga tidak dibukukan.',
  LINE_AMOUNT_INVALID: 'Ada baris faktur tanpa jumlah yang dapat dihitung (tanpa total, atau tanpa kuantitas dan harga satuan).',
  INVALID_PAYLOAD: 'Isi kejadian tidak memuat data yang dibutuhkan untuk dibukukan. Periksa muatan di bawah.',
  INTEGRATION_ACTOR_INVALID: 'Pengguna layanan integrasi organisasi ini tidak valid. Hubungi administrator.',
  INTEGRATION_ACTOR_DISABLED: 'Pengguna layanan integrasi organisasi ini dinonaktifkan. Hubungi administrator.',
}
export function processingText(code: string | null | undefined, message?: string | null): string {
  if (!code) return message ?? '—'
  return PROCESSING[code] ?? ACCOUNTING_ERRORS[code] ?? OPERATIONAL_ERRORS[code] ?? OA4_ERRORS[code] ?? message ?? code
}

/** Event states with a short explanation on the detail page. */
export const statusHints: Record<string, string> = {
  RECEIVED: 'Tersimpan dan menunggu diproses.',
  POSTED: 'Sudah diproses; dokumen atau jurnal hasilnya tertaut di bawah (kejadian informasi tidak membuat jurnal).',
  NEEDS_REVIEW: 'Tidak bisa diproses otomatis. Perbaiki penyebabnya (misalnya buka periode atau lengkapi pemetaan), lalu ulangi.',
  REJECTED: 'Isi kejadian ditolak dan tidak dapat diulang.',
  FAILED: 'Pemrosesan gagal; sistem mencoba lagi otomatis dengan jeda yang makin panjang.',
  DEAD_LETTER: 'Sudah gagal berulang kali dan berhenti dicoba otomatis. Ulangi secara manual setelah penyebabnya diperbaiki.',
}

const REFUSALS: Record<string, (d: Record<string, unknown>) => string> = {
  CONNECTION_NOT_FOUND: () => 'Koneksi tidak ditemukan.',
  EVENT_NOT_FOUND: () => 'Kejadian tidak ditemukan.',
  CONNECTION_INCOMPLETE: (d) => `Koneksi belum lengkap: ${fieldNames(d.missing) || 'ada isian yang kosong'} harus diisi.`,
  CONNECTION_INVALID_TRANSITION: () => 'Status koneksi saat ini tidak mengizinkan tindakan itu. Muat ulang halaman untuk melihat status terbaru.',
  CONNECTION_LOCKED: (d) => `Kejadian sudah diterima pada koneksi ini, jadi ${fieldNames(d.fields) || 'referensi tenant sumber dan tanggal cutover'} tidak dapat diubah lagi.`,
  CONNECTION_EXISTS: () => 'Tenant sumber ini sudah terhubung. Satu tenant sumber hanya punya satu koneksi; ubah koneksi yang ada.',
  CONNECTION_NOT_ACTIVE: () => 'Hanya koneksi yang aktif yang dapat disinkronkan.',
  PULL_IN_PROGRESS: () => 'Sinkronisasi lain untuk koneksi ini sedang berjalan. Coba lagi sebentar lagi.',
  HISTORICAL_BACKFILL_NOT_SUPPORTED: () => 'Isi ulang riwayat belum tersedia; koneksi dimulai dari tanggal cutover.',
  EVENT_NOT_RETRYABLE: () => 'Kejadian dengan status ini tidak dapat diulang.',
  CONNECTION_SETTINGS_INVALID: () => 'Pengaturan koneksi tidak valid: akun kas/bank dan kategori beban harus milik organisasi ini dan masih aktif.',
  CONNECTION_SETTINGS_UNSUPPORTED: () => 'Sistem sumber ini tidak memiliki pengaturan khusus.',
  CONNECTOR_NOT_ENTITLED: (d) => `Konektor ini tidak tersedia untuk organisasi Anda: fitur konektornya dan modul Utang usaha harus aktif dan tidak dalam mode hanya baca.${Array.isArray(d.reasons) ? ` Rincian dari server: ${d.reasons.join('; ')}.` : ''}`,
}

/** The refusals of the integration API in Indonesian; any other error passes through unchanged (describeError shows the API's own message). */
export function integrationError(error: unknown): unknown {
  if (!(error instanceof ApiError) || !error.code) return error
  const text = REFUSALS[error.code]
  return text ? new Error(text(error.details)) : error
}
