import { formatAmount } from '../../lib/accounting'
import { ApiError } from '../../lib/api'
import { statusLabel } from '../../lib/labels'
import type { EventType, ItemStatus, ReadinessResult, Severity, YearEndState } from './types'

// Indonesian wording of the closing pages: states, check results, events, blocker codes and the refusals of the closing API.

export type Tone = 'ok' | 'warn' | 'bad' | 'info' | 'neutral'
type Text = [string, Tone]

const pick = (table: Record<string, Text>, key: string | null | undefined): Text => (key && table[key]) || [key ?? '—', 'neutral']

const READINESS: Record<ReadinessResult, Text> = { READY: ['Siap ditutup', 'ok'], NOT_READY: ['Belum siap', 'bad'] }
export const readinessText = (result: string | null | undefined): Text => pick(READINESS, result)

const CHECK_RESULT: Record<string, Text> = { PASS: ['Lolos', 'ok'], FAIL: ['Gagal', 'bad'], WARN: ['Peringatan', 'warn'], SKIPPED: ['Dilewati', 'neutral'] }
export const checkResultText = (result: string): Text => pick(CHECK_RESULT, result)

const SEVERITY: Record<Severity, Text> = { BLOCK: ['Menghalangi', 'bad'], WARN: ['Peringatan', 'warn'], OFF: ['Nonaktif', 'neutral'] }
export const severityText = (severity: string): Text => pick(SEVERITY, severity)

const ITEM: Record<ItemStatus, Text> = { PENDING: ['Menunggu', 'warn'], COMPLETED: ['Selesai', 'ok'], WAIVED: ['Dikecualikan', 'info'] }
export const itemText = (status: string): Text => pick(ITEM, status)

const EVENT: Record<EventType, Text> = {
  PRE_CLOSE: ['Pra-tutup', 'info'],
  SOFT_CLOSE: ['Tutup sementara', 'warn'],
  HARD_CLOSE: ['Tutup periode', 'ok'],
  REOPEN: ['Buka kembali', 'bad'],
  YEAR_END_RUN: ['Tutup tahun', 'ok'],
  YEAR_END_REVERSE: ['Balik tutup tahun', 'bad'],
}
export const eventText = (type: string): Text => pick(EVENT, type)

const YEAR_END: Record<YearEndState, Text> = { NOT_RUN: ['Belum dijalankan', 'neutral'], POSTED: ['Terposting', 'ok'], REVERSED: ['Dibalik', 'warn'] }
export const yearEndText = (status: string | null | undefined): Text => pick(YEAR_END, status)

const RESULT: Record<string, Text> = { PROFIT: ['Laba', 'ok'], LOSS: ['Rugi', 'bad'], BREAK_EVEN: ['Impas', 'neutral'] }
export const resultText = (result: string): Text => pick(RESULT, result)

const RECON: Record<string, Text> = { MATCHED: ['Cocok', 'ok'], MISMATCH: ['Selisih', 'bad'], INCOMPLETE: ['Tidak lengkap', 'warn'], NOT_APPLICABLE: ['Tidak berlaku', 'neutral'] }
export const reconText = (status: string | null | undefined): Text => pick(RECON, status)

/** "Terbuka → Ditutup sementara" for an event's from/to status (period, fiscal year or year-end run states). */
export function transitionText(from: string | null, to: string | null): string {
  const name = (s: string) => (s === 'NOT_RUN' ? yearEndText(s)[0] : statusLabel(s)[0])
  return [from, to].filter((s): s is string => Boolean(s)).map(name).join(' → ') || '—'
}

export const fiscalYearStates: Record<string, string> = { DRAFT: 'Draf', OPEN: 'Terbuka', CLOSED: 'Ditutup' }

export const reconTypeLabels: Record<string, string> = {
  AP_GL: 'Utang vs buku besar',
  AR_GL: 'Piutang vs buku besar',
  CASH_BANK_GL: 'Kas/bank vs buku besar',
  FIXED_ASSET_GL: 'Aset tetap vs buku besar',
  TAX_GL: 'Pajak vs buku besar',
  FX_GL: 'Selisih kurs vs buku besar',
}

/** `AP_GL` in a URL: `ap-gl`. */
export const reconSlug = (type: string): string => type.toLowerCase().replace(/_/g, '-')

export const accountTypeShort: Record<string, string> = { REVENUE: 'Pendapatan', EXPENSE: 'Beban' }
export const sideLabels: Record<string, string> = { DEBIT: 'Debit', CREDIT: 'Kredit' }
export const targetLabels: Record<string, string> = { SOFT_CLOSED: 'Ditutup sementara', OPEN: 'Terbuka' }

/** What each close check proves and how to clear it. The title and the figures come from the API; this is only the hint. */
export const checkHints: Record<string, string> = {
  TRIAL_BALANCE: 'Total debit dan kredit semua jurnal terposting sampai akhir periode harus sama. Selisih berarti ada data yang rusak: hubungi administrator.',
  PENDING_JOURNALS: 'Posting atau tolak jurnal yang masih diajukan atau disetujui; periode tidak boleh ditutup dengan jurnal menggantung.',
  DRAFT_JOURNALS: 'Jurnal draf tidak masuk buku besar. Selesaikan atau batalkan agar tidak tertinggal setelah periode ditutup.',
  PENDING_DOCUMENTS: 'Dokumen sumber (faktur, pembayaran, penerimaan) yang belum diposting tidak ada di buku besar.',
  AP_RECONCILIATION: 'Sub-buku utang harus sama dengan akun kontrol utang usaha pada akhir periode.',
  AR_RECONCILIATION: 'Sub-buku piutang harus sama dengan akun kontrol piutang usaha pada akhir periode.',
  CASH_BANK_RECONCILIATION: 'Dokumen kas dan bank harus sama dengan jurnal pada akun kas/bank.',
  FIXED_ASSET_RECONCILIATION: 'Register aset tetap harus sama dengan akun aset dan akumulasi penyusutan.',
  DEPRECIATION_COMPLETE: 'Seluruh penyusutan sampai akhir periode harus sudah diposting lewat proses penyusutan.',
  TAX_RECONCILIATION: 'Transaksi pajak terhadap akun kontrol pajak.',
  FX_RECONCILIATION: 'Selisih kurs terealisasi dan saldo valas terbuka terhadap buku besar.',
  PREVIOUS_PERIODS_CLOSED: 'Periode sebelumnya sebaiknya ditutup lebih dulu agar saldo awal periode ini final.',
  REPORT_MAPPING: 'Akun bersaldo yang belum terpetakan tidak muncul di neraca atau laba rugi. Lengkapi pemetaan laporan.',
  CHECKLIST: 'Butir wajib daftar periksa penutupan harus diselesaikan atau dikecualikan dengan alasan.',
}

/** Wording of the year-end blockers (the codes of `blockers` in the preview and of the 409 of a run). */
export const blockerTitles: Record<string, string> = {
  YEAR_END_ALREADY_POSTED: 'Tutup tahun untuk tahun fiskal ini sudah terposting. Balik terlebih dahulu bila perlu dijalankan ulang.',
  YEAR_END_LATER_YEAR_POSTED: 'Tahun fiskal berikutnya sudah ditutup dan menyapu saldo tahun ini. Balik tutup tahun yang lebih baru lebih dulu.',
  FISCAL_YEAR_NOT_OPEN: 'Hanya tahun fiskal yang terbuka yang dapat ditutup pada akhir tahun.',
  YEAR_END_FUTURE_PERIODS: 'Semua periode tahun fiskal harus sudah dibuka.',
  YEAR_END_LAST_PERIOD_CLOSED: 'Periode terakhir sudah ditutup; jurnal penutupan diposting ke periode terbuka atau ditutup sementara.',
  YEAR_END_PENDING_JOURNALS: 'Posting atau batalkan jurnal yang diajukan atau disetujui sampai akhir tahun.',
  YEAR_END_MAPPING_MISSING: 'Akun laba ditahan belum dipetakan pada peran RETAINED_EARNINGS.',
  YEAR_END_RULE_MISSING: 'Aturan posting YEAR_END_CLOSING belum terbit untuk tanggal penutupan.',
  YEAR_END_RULE_INVALID: 'Aturan posting YEAR_END_CLOSING tidak menghasilkan jurnal penutupan yang seimbang.',
  YEAR_END_ACCOUNT_INACTIVE: 'Ada akun nonaktif yang masih bersaldo; aktifkan agar dapat ditutup.',
  YEAR_END_NOTHING_TO_CLOSE: 'Tidak ada akun pendapatan atau beban yang bersaldo untuk ditutup.',
}

/** Labels of the keys in `details` of a blocker or a refusal. */
export const detailLabels: Record<string, string> = {
  periods: 'Periode', count: 'Jumlah', pending: 'Jurnal tertunda', accounts: 'Akun', fiscal_year: 'Tahun fiskal', status: 'Status', period: 'Periode', account_role: 'Peran akun',
  reason: 'Sebab', event_type: 'Jenis peristiwa', rule: 'Aturan', version: 'Versi', error: 'Kesalahan',
}

const ERRORS: Record<string, string> = {
  ...blockerTitles,
  PERIOD_BUSY: 'Periode sedang dipakai transaksi lain (posting yang berjalan). Tunggu sebentar, lalu coba lagi.',
  PERIOD_NOT_CLOSABLE: 'Periode ini tidak dapat diproses: hanya periode terbuka atau ditutup sementara yang dapat dievaluasi.',
  PERIOD_INVALID_TRANSITION: 'Perubahan status periode ini tidak diizinkan dari status saat ini. Muat ulang halaman untuk melihat status terbaru.',
  SOFT_CLOSE_REQUIRED: 'Kebijakan penutupan mewajibkan periode ditutup sementara lebih dulu sebelum ditutup.',
  REOPEN_TARGET_INVALID: 'Periode hanya dapat dibuka kembali ke status ditutup sementara atau terbuka.',
  REOPEN_REASON_REQUIRED: 'Alasan membuka kembali periode wajib diisi, minimal 10 karakter.',
  YEAR_END_POSTED: 'Tahun fiskal periode ini sudah memiliki tutup tahun terposting. Balik tutup tahun lebih dulu sebelum membuka periodenya.',
  YEAR_END_NO_PERIODS: 'Tahun fiskal ini belum memiliki periode.',
  YEAR_END_NOT_POSTED: 'Tahun fiskal ini tidak memiliki tutup tahun terposting untuk dibalik.',
  YEAR_END_REASON_REQUIRED: 'Alasan membalik tutup tahun wajib diisi, minimal 10 karakter.',
  CLOSING_REQUIRES_FULL_SCOPE: 'Penutupan memerlukan cakupan data seluruh organisasi; cakupan cabang atau unit bisnis tidak dapat melihat seluruh buku besar.',
  CLOSING_CHECK_LOCKED: 'Pemeriksaan ini adalah aturan akuntansi yang tetap dan selalu menghalangi penutupan.',
  CLOSING_CHECK_UNKNOWN: 'Pemeriksaan penutupan tidak dikenal.',
  CLOSING_SEVERITY_INVALID: 'Perilaku pemeriksaan harus menghalangi, peringatan atau nonaktif.',
  CHECKLIST_SYSTEM_CHECK: 'Pemeriksaan sistem dihitung dari buku besar dan tidak dapat diubah dengan tangan.',
  CHECKLIST_REASON_REQUIRED: 'Alasan pengecualian wajib diisi.',
  CHECKLIST_PERIOD_CLOSED: 'Daftar periksa periode yang sudah ditutup adalah bukti dan tidak dapat diubah. Buka kembali periode terlebih dahulu.',
  CHECKLIST_ITEM_NOT_FOUND: 'Butir daftar periksa tidak ditemukan.',
  CHECKLIST_ITEM_NOT_PENDING: 'Butir ini sudah selesai atau dikecualikan. Setel ulang dulu bila ingin mengubahnya.',
  CHECKLIST_ITEM_PENDING: 'Butir ini masih menunggu.',
  CHECKLIST_CODE_TAKEN: 'Kode butir sudah dipakai.',
  CHECKLIST_CODE_RESERVED: 'Kode ini milik pemeriksaan sistem dan tidak dapat dipakai untuk butir manual.',
  CHECKLIST_TEMPLATE_IN_USE: 'Butir ini sudah dipakai pada daftar periksa periode, jadi tidak dapat dihapus. Nonaktifkan saja.',
  FISCAL_YEAR_MISSING: 'Buat tahun fiskal terlebih dahulu sebelum menerapkan aturan posting bawaan.',
}

/** The check titles of a refusal's blocking list, as bullets for a dialog. */
export function refusedChecks(error: unknown): { code: string; title: string; message: string }[] {
  if (!(error instanceof ApiError) || error.code !== 'PERIOD_NOT_READY' || !Array.isArray(error.details.blocking)) return []
  return (error.details.blocking as Record<string, unknown>[]).map((c) => ({ code: String(c.code ?? ''), title: String(c.title ?? c.code ?? ''), message: String(c.message ?? '') }))
}

/** The blockers a refused year-end run lists (`details.blockers`). */
export function refusedBlockers(error: unknown): { code: string; message: string }[] {
  if (!(error instanceof ApiError) || !Array.isArray(error.details.blockers)) return []
  return (error.details.blockers as Record<string, unknown>[]).map((b) => ({ code: String(b.code ?? ''), message: String(b.message ?? '') }))
}

/**
 * The same refusal with an Indonesian message when it is one of the closing codes (a plain Error, which `describeError` shows as written, so
 * the shared journal wording of SOD_VIOLATION does not replace the closing one). Other errors pass through unchanged.
 */
export function closingError(error: unknown): unknown {
  if (!(error instanceof ApiError) || !error.code) return error
  const d = error.details
  const text = (): string | null => {
    if (error.code === 'PERIOD_NOT_READY') {
      const n = Array.isArray(d.blocking) ? d.blocking.length : 0
      const who = typeof d.period === 'string' ? ` ${d.period}` : ''
      return `Periode${who} belum siap ditutup: ${n} pemeriksaan penghalang belum lolos. Server memeriksa ulang saat penutupan, jadi hasil di layar bisa sudah berubah.`
    }
    if (error.code === 'SOD_VIOLATION' && d.rule === 'closer_not_reopener') return 'Pemisahan tugas: orang yang menutup periode tidak boleh membukanya kembali. Minta pengguna lain yang berwenang.'
    if (error.code === 'REOPEN_LATER_PERIOD_CLOSED') {
      const later = Array.isArray(d.later_closed) ? ` Periode lebih baru yang tertutup: ${d.later_closed.join(', ')}.` : ''
      return `Periode yang lebih baru masih tertutup; buka kembali dari periode tertutup terakhir mundur ke belakang.${later}`
    }
    if (error.code === 'REOPEN_TOO_FAR_BACK') {
      const figures = typeof d.max_periods_back === 'number' ? ` Batas kebijakan: ${d.max_periods_back} periode (periode ini ${String(d.periods_back ?? '?')} periode ke belakang).` : ''
      return `Kebijakan penutupan membatasi seberapa jauh periode boleh dibuka kembali dari periode tertutup terakhir.${figures}`
    }
    if (error.code === 'YEAR_END_LATER_YEAR_POSTED' && typeof d.fiscal_year === 'string') return `${blockerTitles.YEAR_END_LATER_YEAR_POSTED} (tahun fiskal ${d.fiscal_year})`
    if (error.code === 'YEAR_END_POSTED' && typeof d.fiscal_year === 'string') return `${ERRORS.YEAR_END_POSTED} (tahun fiskal ${d.fiscal_year})`
    return ERRORS[error.code ?? ''] ?? null
  }
  const message = text()
  return message ? new Error(message) : error
}

/** A decimal string for a figure in running text. */
export const amountText = (value: unknown): string => (typeof value === 'string' ? formatAmount(value) : '—')
