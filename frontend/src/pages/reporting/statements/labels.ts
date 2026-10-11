import { formatAmount } from '../../../lib/accounting'
import { formatDate } from '../../../lib/format'
import type { CompareMode, DateRange, DefinitionStatus, Integrity, StatementKind, StatementReport, Warning } from './types'

// Indonesian wording of the financial statement pages: titles, line and status labels, the warnings of the API and its refusals.

type Tone = 'ok' | 'warn' | 'bad' | 'info' | 'neutral'

export const STATEMENT_META: Record<StatementKind, { title: string; description: string; slug: string; file: string; view: string }> = {
  BALANCE_SHEET: {
    title: 'Neraca',
    description: 'Posisi keuangan per tanggal tertentu, disusun dari jurnal yang sudah diposting dan pemetaan laporan. Saldo bersifat kumulatif.',
    slug: 'balance-sheet',
    file: 'neraca',
    view: 'accounting.reporting.balance_sheet.view',
  },
  PROFIT_LOSS: {
    title: 'Laba rugi',
    description: 'Pendapatan dan beban selama satu periode, disusun dari jurnal yang sudah diposting. Yang dihitung adalah mutasi periode, bukan saldo.',
    slug: 'profit-loss',
    file: 'laba-rugi',
    view: 'accounting.reporting.pnl.view',
  },
  CASH_FLOW: {
    title: 'Arus kas',
    description: 'Arus kas metode tidak langsung selama satu periode, dibuktikan terhadap saldo akun kas dan bank di buku besar.',
    slug: 'cash-flow',
    file: 'arus-kas',
    view: 'accounting.reporting.cash_flow.view',
  },
}

export const statementTitle = (kind: StatementKind): string => STATEMENT_META[kind].title

export const modeLabels: Record<CompareMode, string> = {
  PRIOR_PERIOD: 'Periode sebelumnya',
  PRIOR_YEAR: 'Tahun sebelumnya',
  BUDGET: 'Anggaran',
}

export const categoryLabels: Record<string, string> = {
  OPERATING: 'Operasional',
  INVESTING: 'Investasi',
  FINANCING: 'Pendanaan',
  NON_CASH: 'Non-kas',
  UNCLASSIFIED: 'Belum diklasifikasi',
}

export const accountTypeLabels: Record<string, string> = {
  ASSET: 'Aset',
  LIABILITY: 'Kewajiban',
  EQUITY: 'Ekuitas',
  REVENUE: 'Pendapatan',
  EXPENSE: 'Beban',
}

export const definitionStatusTexts: Record<DefinitionStatus, [string, Tone]> = {
  DRAFT: ['Draf', 'warn'],
  ACTIVE: ['Aktif', 'ok'],
  ARCHIVED: ['Arsip', 'neutral'],
}

export const definitionStatusText = (status: string): [string, Tone] => definitionStatusTexts[status as DefinitionStatus] ?? [status, 'neutral']

export const reasonLabels: Record<string, string> = {
  NOT_MAPPED: 'Tidak ada di definisi laporan',
  CATEGORY_UNCLASSIFIED: 'Kategori belum dipilih',
}

/** Why a system line has the figure it has, in the reader's words (the API names only the key). */
export const systemLabels: Record<string, string> = {
  CURRENT_RESULT: 'Dihitung sistem dari akun pendapatan dan beban yang belum ditutup',
  NET_RESULT: 'Dihitung sistem dari seluruh akun pendapatan dan beban',
  CASH_OPENING: 'Dihitung sistem dari akun kas dan bank',
  CASH_CLOSING: 'Dihitung sistem dari akun kas dan bank',
  NET_CASH_CHANGE: 'Dihitung sistem dari akun kas dan bank',
  UNCLASSIFIED_CASH_FLOW: 'Pergerakan akun yang belum diklasifikasi',
}

/** The explanation of a system figure on the drill-down, in Indonesian (the API sends English; it names the key). */
export const systemExplanations: Record<string, string> = {
  CURRENT_RESULT: 'Hasil akun pendapatan dan beban yang belum ditutup ke ekuitas per tanggal laporan: pendapatan dikurangi beban, untuk setiap akun yang punya saldo.',
  NET_RESULT: 'Hasil seluruh akun pendapatan dan beban selama periode: pendapatan dikurangi beban, termasuk akun yang belum dipetakan (angka buku besar).',
  CASH_OPENING: 'Saldo akun kas dan bank pada awal periode.',
  CASH_CLOSING: 'Saldo akun kas dan bank pada akhir periode, menurut buku besar.',
  NET_CASH_CHANGE: 'Mutasi akun kas dan bank selama periode, menurut buku besar.',
  UNCLASSIFIED_CASH_FLOW: 'Dampak kas dari akun neraca yang bergerak tetapi tidak diklasifikasi oleh baris mana pun pada definisi laporan.',
}

/** How the general ledger relates to the figure of an account on the statement. */
export function ledgerNote(statement: StatementKind, closingJournalsListed: boolean): string {
  if (statement === 'BALANCE_SHEET') {
    return 'Buku besar menampilkan seluruh baris jurnal terposting pada rentang tanggal tersebut. Neraca bersifat kumulatif, jadi saldo awal buku besar pada tanggal pertama ditambah mutasinya sama dengan jumlah akun di sini.'
  }
  const closing = closingJournalsListed
    ? ' Buku besar juga memuat jurnal penutup akhir tahun beserta pembaliknya, yang tidak dihitung pada laporan periode ini; pada periode yang memuat jurnal penutup, total akun pendapatan dan beban di buku besar berbeda dari laporan tepat sebesar jurnal tersebut.'
    : ''
  return `Jumlah akun adalah mutasinya selama periode (total debit dan kredit pada kueri buku besar); saldo awal dan akhir di buku besar bersifat kumulatif.${closing}`
}

// ------------------------------------------------------------------------------------------------ dates

/** "Per 31 Mar 2026" for a balance sheet, "1 Jan 2026 – 31 Mar 2026" for a period. */
export function dateText(dates: { as_of?: string; range?: DateRange }): string {
  if (dates.as_of) return `Per ${formatDate(dates.as_of)}`
  if (dates.range) return `${formatDate(dates.range.from)} – ${formatDate(dates.range.to)}`
  return '—'
}

/** The dates a statement echoes: `as_of` for a balance sheet, `range` for a period statement. */
export const datesOf = (report: StatementReport): { as_of?: string; range?: DateRange } => (report.statement === 'BALANCE_SHEET' ? { as_of: report.as_of } : { range: report.range })

export const fileDate = (dates: { as_of?: string; range?: DateRange }): string => dates.as_of ?? (dates.range ? `${dates.range.from}_${dates.range.to}` : 'laporan')

// ------------------------------------------------------------------------------------------------ warnings

function count(w: Warning): number {
  const n = w.details?.count
  return typeof n === 'number' ? n : 0
}

/** The Indonesian text of a warning of the API; an unknown code keeps the message the server sent. */
export function warningText(w: Warning): string {
  const side = w.side === 'comparison' ? ' (sisi pembanding)' : ''
  switch (w.code) {
    case 'UNMAPPED_ACCOUNT_WITH_BALANCE':
      return `${count(w)} akun yang punya angka tidak ada di satu pun baris definisi laporan, sehingga tidak ikut tampil. Daftarnya ada di bagian Pemeriksaan.${side}`
    case 'STATEMENT_DIFFERENCE': {
      const difference = typeof w.details?.difference === 'string' ? `Selisih ${formatAmount(w.details.difference)} ` : 'Selisihnya '
      return `Laporan tidak sama dengan buku besar. ${difference}ditampilkan apa adanya dan tidak diserap ke baris mana pun.${side}`
    }
    case 'DEFINITION_IS_DRAFT':
      return `Laporan memakai definisi yang masih draf dan belum diterbitkan.${side}`
    case 'DEFINITION_CHANGED_IN_RANGE':
      return `Versi definisi laporan berubah di dalam periode ini; versi yang berlaku pada tanggal akhir dipakai untuk seluruh periode.${side}`
    case 'SECTION_MIXES_ASSETS_AND_FUNDING':
      return `Sebuah subtotal pada definisi menjumlahkan aset dengan kewajiban atau ekuitas; angkanya perlu ditinjau di definisi laporan.${side}`
    case 'CASH_FLOW_CATEGORY_UNCLASSIFIED':
      return `${count(w)} akun yang bergerak berada pada baris dengan kategori arus kas "Belum diklasifikasi"; pilih kategorinya di definisi laporan.${side}`
    case 'BUDGET_NOT_ON_STATEMENT':
      return `${count(w)} akun beranggaran tidak berada pada tepat satu baris definisi, sehingga anggarannya tidak ikut perbandingan.${side}`
    default:
      return `${w.message}${side}`
  }
}

// ------------------------------------------------------------------------------------------------ reliability

export const PARTIAL_TEXT = 'Cakupan data Anda lebih sempit dari seluruh perusahaan (cabang, unit bisnis, pusat biaya atau filter yang dipilih). Angka ini bukan total perusahaan dan tidak diharapkan seimbang sendiri, jadi tidak dapat dipakai sebagai bukti.'

/** Why the API marked a statement unreliable, from its own integrity block (booleans and list lengths; no arithmetic). */
export function reliabilityReasons(integrity: Integrity): string[] {
  const reasons: string[] = []
  const i = integrity as Partial<Record<string, unknown>>
  const unmapped = Array.isArray(i.unmapped) ? i.unmapped.length : 0
  const unclassified = Array.isArray(i.unclassified) ? i.unclassified.length : 0
  const difference = typeof i.difference === 'string' ? i.difference : null
  if (unmapped > 0) reasons.push(`${unmapped} akun yang punya angka tidak ada di definisi laporan`)
  if (unclassified > 0) reasons.push(`${unclassified} akun yang bergerak belum diklasifikasi arus kasnya`)
  if (i.balanced === false) reasons.push(`aset tidak sama dengan kewajiban + ekuitas (selisih ${formatAmount(difference)})`)
  if (i.reconciled === false) reasons.push(`kas akhir hasil hitung tidak sama dengan saldo kas/bank buku besar (selisih ${formatAmount(difference)})`)
  if (i.reliable === false && 'ledger_result' in i && unmapped === 0) reasons.push(`hasil laporan tidak sama dengan hasil buku besar (selisih ${formatAmount(difference)})`)
  return reasons
}

/** The sentence that confirms a statement which is complete and reliable. */
export function reliableText(report: Pick<StatementReport, 'statement'>): string {
  switch (report.statement) {
    case 'BALANCE_SHEET':
      return 'Neraca seimbang: aset sama dengan kewajiban + ekuitas, dan seluruh akun yang punya saldo ada di definisi laporan.'
    case 'PROFIT_LOSS':
      return 'Hasil laporan sama dengan hasil buku besar, dan seluruh akun yang bergerak ada di definisi laporan.'
    default:
      return 'Kas akhir hasil hitung sama dengan saldo kas/bank di buku besar, dan seluruh pergerakan sudah diklasifikasi.'
  }
}

// ------------------------------------------------------------------------------------------------ refusals

type Details = Record<string, unknown>

/**
 * An Indonesian sentence for the refusals of the statement endpoints; null when the code is not one of them (the shared describeError then speaks).
 * The sentence says what to do, e.g. that no published definition exists yet.
 */
export function statementMessage(code: string | null, details: Details = {}): string | null {
  switch (code) {
    case 'REPORT_DEFINITION_NOT_FOUND': {
      const date = typeof details.date === 'string' ? ` pada ${formatDate(details.date)}` : ''
      return `Belum ada definisi laporan yang berlaku${date}. Buat definisi (atau terapkan bawaan) lalu terbitkan di Pemetaan laporan, atau pilih versi definisi lain.`
    }
    case 'REPORT_DEFINITION_INVALID': {
      const errors = Array.isArray(details.errors) ? details.errors : []
      const first = errors.flatMap((e: unknown) => (typeof e === 'object' && e !== null && typeof (e as Details).code === 'string' ? [String((e as Details).code)] : [])).slice(0, 5)
      const codes = first.length > 0 ? ` Kode kesalahan: ${first.join(', ')}.` : ''
      return `Definisi laporan memiliki kesalahan konfigurasi sehingga laporan tidak dapat dibuat. Perbaiki di Pemetaan laporan.${codes}`
    }
    case 'DATE_FILTER_INVALID':
      return 'Neraca memakai tanggal (per tanggal), sedangkan laba rugi dan arus kas memakai periode atau rentang tanggal. Samakan pilihan tanggalnya dengan jenis laporan.'
    case 'DATE_FILTER_CONFLICT':
      return 'Periode sudah menentukan tanggalnya sendiri; pilih periode saja atau rentang tanggal saja.'
    case 'RANGE_OUTSIDE_FISCAL_YEAR':
      return 'Tanggal harus berada di dalam tahun fiskal yang dipilih.'
    case 'RANGE_INVALID':
      return 'Tanggal mulai tidak boleh setelah tanggal akhir.'
    case 'BUDGET_RANGE_NOT_PERIOD_ALIGNED':
      return 'Anggaran disimpan per periode akuntansi: rentang harus dimulai di hari pertama dan berakhir di hari terakhir periode pada tahun fiskal anggaran. Pilih periode, atau sesuaikan tanggalnya.'
    case 'COMPARISON_MODE_UNSUPPORTED':
      return 'Hanya laporan laba rugi yang dapat dibandingkan dengan anggaran.'
    case 'REPORT_LINE_NOT_FOUND':
      return 'Baris laporan ini tidak ditemukan pada laporan yang sama; muat ulang laporan lalu coba lagi.'
    case 'REPORT_LINE_NOT_DRILLABLE':
      return 'Baris judul tidak memiliki angka yang dapat dirinci.'
    case 'PERIOD_NOT_FOUND':
      return 'Periode akuntansi tidak ditemukan.'
    case 'FISCAL_YEAR_NOT_FOUND':
      return 'Tahun fiskal tidak ditemukan.'
    default:
      return null
  }
}
