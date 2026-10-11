import { ApiError } from '../../../lib/api'
import type { BalanceSide, CashFlowCategory, DefinitionStatus, Finding, LineType, StatementType } from './types'

// Indonesian wording of the report definition pages: statement types, statuses, line types, validator findings and the refusals of the definition API.

type Tone = 'ok' | 'warn' | 'bad' | 'info' | 'neutral'

export const statementLabels: Record<StatementType, string> = {
  BALANCE_SHEET: 'Neraca (posisi keuangan)',
  PROFIT_LOSS: 'Laba rugi',
  CASH_FLOW: 'Arus kas',
}

export const statementHints: Record<StatementType, string> = {
  BALANCE_SHEET: 'Saldo kumulatif per tanggal: aset, kewajiban, ekuitas.',
  PROFIT_LOSS: 'Pendapatan dan beban dalam satu periode.',
  CASH_FLOW: 'Arus kas metode tidak langsung dari perubahan akun neraca.',
}

/** ACTIVE is a published (terbit) definition: the layout statements use; it never changes. */
export const statusTexts: Record<DefinitionStatus, [string, Tone]> = {
  DRAFT: ['Draf', 'neutral'],
  ACTIVE: ['Terbit', 'ok'],
  ARCHIVED: ['Diarsipkan', 'info'],
}

export const statusText = (status: string): [string, Tone] => statusTexts[status as DefinitionStatus] ?? [status, 'neutral']

export const lineTypeLabels: Record<LineType, string> = {
  HEADER: 'Judul',
  ACCOUNT: 'Akun',
  ACCOUNT_GROUP: 'Kelompok akun',
  SUBTOTAL: 'Subtotal',
  CALCULATED_TOTAL: 'Total hitung',
  SYSTEM: 'Angka sistem',
}

export const lineTypeHints: Record<LineType, string> = {
  HEADER: 'Hanya memberi judul; tidak memiliki angka.',
  ACCOUNT: 'Angkanya dari akun yang dipetakan ke baris ini.',
  ACCOUNT_GROUP: 'Angkanya dari satu kelompok akun (akun induk beserta akun di bawahnya).',
  SUBTOTAL: 'Menjumlahkan baris yang berada di bawahnya.',
  CALCULATED_TOTAL: 'Menggabungkan baris lain dengan tanda tambah atau kurang.',
  SYSTEM: 'Angka bawaan sistem dari daftar tertutup (mis. laba tahun berjalan).',
}

/** How a line computes its amount from the accounts mapped to it. */
export const sideLabels: Record<BalanceSide, string> = { DEBIT: 'Debit - kredit', CREDIT: 'Kredit - debit' }

/** The normal balance of an account. */
export const sideNames: Record<BalanceSide, string> = { DEBIT: 'Debit', CREDIT: 'Kredit' }

export const categoryLabels: Record<CashFlowCategory, string> = {
  OPERATING: 'Operasi',
  INVESTING: 'Investasi',
  FINANCING: 'Pendanaan',
  NON_CASH: 'Non-kas',
  UNCLASSIFIED: 'Belum diklasifikasi',
}

export const systemKeyLabels: Record<string, string> = {
  CURRENT_RESULT: 'Laba (rugi) tahun berjalan',
  NET_RESULT: 'Laba (rugi) bersih',
  CASH_OPENING: 'Kas awal',
  CASH_CLOSING: 'Kas akhir',
  NET_CASH_CHANGE: 'Perubahan kas bersih',
  UNCLASSIFIED_CASH_FLOW: 'Arus kas belum terklasifikasi',
}

/** Which statement types may use each built-in figure (a picker aid only; the API enforces it). */
const SYSTEM_KEY_TYPES: Record<string, StatementType[]> = {
  CURRENT_RESULT: ['BALANCE_SHEET'],
  NET_RESULT: ['PROFIT_LOSS', 'CASH_FLOW'],
  CASH_OPENING: ['CASH_FLOW'],
  CASH_CLOSING: ['CASH_FLOW'],
  NET_CASH_CHANGE: ['CASH_FLOW'],
  UNCLASSIFIED_CASH_FLOW: ['CASH_FLOW'],
}

export const systemKeysFor = (type: StatementType): string[] => Object.keys(SYSTEM_KEY_TYPES).filter((k) => SYSTEM_KEY_TYPES[k].includes(type))

/** Account types a statement of this kind can use (a picker aid; the validator is the authority). */
export const accountTypesFor = (type: StatementType): string[] => (type === 'PROFIT_LOSS' ? ['REVENUE', 'EXPENSE'] : ['ASSET', 'LIABILITY', 'EQUITY'])

export const accountTypeText: Record<string, string> = { ASSET: 'Aset', LIABILITY: 'Kewajiban', EQUITY: 'Ekuitas', REVENUE: 'Pendapatan', EXPENSE: 'Beban' }

// ------------------------------------------------------------------------------------------------ validator findings

type Lookup = (accountId: unknown) => string | null

const account = (f: Finding, lookup?: Lookup): string => lookup?.(f.details.account_id) ?? 'yang dipilih'
const line = (f: Finding): string => f.line ?? ''

const FINDINGS: Record<string, (f: Finding, lookup?: Lookup) => string> = {
  DEFINITION_EMPTY: () => 'Definisi belum memiliki baris.',
  LINE_CODE_DUPLICATE: (f) => `Kode baris ${line(f)} dipakai lebih dari satu baris.`,
  LINE_SYSTEM_KEY_INVALID: (f) => `Angka sistem pada baris ${line(f)} tidak berlaku untuk jenis laporan ini.`,
  SYSTEM_KEY_DUPLICATE: (f) => `Angka sistem pada baris ${line(f)} dipakai lebih dari satu baris.`,
  REQUIRED_SYSTEM_LINE_MISSING: (f) => `Laporan ini membutuhkan baris angka sistem "${systemKeyLabels[String(f.details.system_key)] ?? f.details.system_key}".`,
  SUBTOTAL_EMPTY: (f) => `Subtotal ${line(f)} membutuhkan minimal satu baris bernilai di bawahnya.`,
  LINE_SHAPE_INVALID: (f) => `Bentuk baris ${line(f)} tidak valid: jenis, sisi saldo, angka sistem, atau operand tidak sesuai.`,
  LINE_PARENT_MISSING: (f) => `Induk baris ${line(f)} tidak ada dalam definisi ini.`,
  LINE_PARENT_TYPE_INVALID: (f) => `Baris ${line(f)} berada di bawah baris yang bukan judul atau subtotal.`,
  LINE_BALANCE_SIDE_MISSING: (f) => `Baris akun ${line(f)} membutuhkan sisi saldo (debit atau kredit).`,
  LINE_CASH_FLOW_CATEGORY_INVALID: (f) => `Baris akun ${line(f)} pada laporan arus kas membutuhkan kategori.`,
  LINE_CASH_FLOW_CATEGORY_NOT_ALLOWED: (f) => `Kategori arus kas pada baris ${line(f)} tidak diperbolehkan (hanya untuk baris akun pada laporan arus kas).`,
  OPERANDS_MISSING: (f) => `Total hitung ${line(f)} membutuhkan minimal satu operand.`,
  OPERAND_SIGN_INVALID: (f) => `Tanda operand pada ${line(f)} harus + atau -.`,
  OPERAND_LINE_MISSING: (f) => `Operand ${String(f.details.operand ?? '')} pada ${line(f)} tidak ada dalam definisi ini.`,
  OPERAND_SELF_REFERENCE: (f) => `Total hitung ${line(f)} tidak boleh memakai dirinya sendiri.`,
  OPERAND_LINE_NOT_NUMERIC: (f) => `Operand ${String(f.details.operand ?? '')} pada ${line(f)} adalah judul dan tidak memiliki angka.`,
  LINE_PARENT_CYCLE: (f) => `Rantai induk baris ${line(f)} berputar kembali ke dirinya sendiri.`,
  CALCULATION_CYCLE: (f) => `Baris ${line(f)} bergantung pada dirinya sendiri, langsung atau lewat total lain.`,
  MAPPING_LINE_UNKNOWN: () => 'Ada pemetaan ke baris yang tidak ada dalam definisi ini.',
  MAPPING_LINE_NOT_MAPPABLE: (f) => `Akun hanya dapat dipetakan ke baris akun atau kelompok akun; ${line(f)} bukan keduanya.`,
  MAPPING_ACCOUNT_NOT_FOUND: () => 'Akun yang dipetakan tidak ada di bagan akun organisasi ini.',
  MAPPING_DUPLICATE_ACCOUNT: (f, l) => `Akun ${account(f, l)} dipetakan lebih dari satu kali pada baris ${line(f)}.`,
  MAPPING_ACCOUNT_INACTIVE: (f, l) => `Akun ${account(f, l)} tidak aktif.`,
  MAPPING_HEADER_NEEDS_DESCENDANTS: (f, l) => `Akun ${account(f, l)} adalah akun induk; petakan dengan pilihan "termasuk akun di bawahnya".`,
  MAPPING_HEADER_EMPTY: (f, l) => `Akun induk ${account(f, l)} tidak memiliki akun posting di bawahnya.`,
  MAPPING_ACCOUNT_TYPE_INVALID: (f, l) => `Tipe akun ${account(f, l)} tidak dipakai oleh laporan jenis ini.`,
  MAPPING_CASH_ACCOUNT: (f, l) => `Akun ${account(f, l)} adalah akun kas; laporan arus kas menjelaskan kas, sehingga akun kas tidak boleh menjadi baris.`,
  MAPPING_OVERLAP: (f, l) => `Akun ${account(f, l)} tercakup oleh lebih dari satu pemetaan${Array.isArray(f.details.lines) ? ` (baris ${(f.details.lines as string[]).join(', ')})` : ''} sehingga akan terhitung ganda.`,
  LINE_WITHOUT_MAPPING: (f) => `Baris ${line(f)} belum memiliki akun, sehingga nilainya selalu nol.`,
  UNCLASSIFIED_LINE_MISSING: () => 'Tanpa baris "Arus kas belum terklasifikasi", pergerakan akun yang tidak terklasifikasi hanya tercantum di blok integritas, bukan di laporan.',
  SECTION_MIXES_ASSETS_AND_FUNDING: (f) => `Subtotal ${line(f)} menjumlahkan aset dengan kewajiban atau ekuitas; pakai total hitung untuk angka bersih.`,
  UNMAPPED_ACCOUNT_WITH_BALANCE: (f) => `${String(f.details.count ?? '')} akun bersaldo atau beraktivitas belum tercakup oleh baris mana pun (lihat daftar akun belum dipetakan).`,
}

/** Indonesian text of a validator finding; a code this table does not know keeps the API's own message. */
export function findingText(f: Finding, lookup?: Lookup): string {
  return FINDINGS[f.code]?.(f, lookup) ?? f.message
}

// ------------------------------------------------------------------------------------------------ API refusals

const ERRORS: Record<string, string> = {
  REPORT_DEFINITION_NOT_DRAFT: 'Definisi yang sudah terbit tidak dapat diubah. Buat versi baru untuk merevisinya.',
  REPORT_DEFINITION_IS_DRAFT: 'Versi ini masih draf; ubah langsung tanpa membuat versi baru.',
  REPORT_DEFINITION_NOT_ACTIVE: 'Hanya definisi yang sudah terbit yang dapat diarsipkan.',
  REPORT_DRAFT_EXISTS: 'Sudah ada draf untuk jenis laporan ini. Terbitkan atau hapus draf itu lebih dulu.',
  REPORT_DEFINITION_CODE_MISMATCH: 'Kode definisi untuk jenis laporan ini sudah ditetapkan oleh versi sebelumnya.',
  REPORT_DEFINITION_CONFLICT: 'Versi definisi ini baru saja dibuat oleh pengguna lain. Coba lagi.',
  REPORT_DEFINITION_INVALID: 'Definisi memiliki kesalahan konfigurasi sehingga tidak dapat dipakai atau diterbitkan. Perbaiki temuan berikut.',
  REPORT_MAPPING_INVALID: 'Pemetaan memiliki kesalahan dan tidak disimpan. Perbaiki temuan berikut.',
  REPORT_EFFECTIVE_FROM_INVALID: 'Tanggal berlaku versi baru harus setelah versi sebelumnya mulai berlaku.',
  REPORT_EFFECTIVE_TO_INVALID: 'Tanggal berakhir tidak boleh sebelum definisi mulai berlaku.',
  REPORT_WINDOW_OVERLAP: 'Versi lain dari laporan ini sudah mencakup tanggal tersebut. Pilih tanggal berlaku yang tidak bertabrakan.',
  REPORT_LINE_CODE_DUPLICATE: 'Kode baris sudah dipakai di definisi ini.',
  REPORT_LINE_PARENT_UNKNOWN: 'Baris induk tidak ditemukan di definisi ini.',
  REPORT_LINE_INVALID: 'Bentuk baris tidak valid. Periksa jenis, sisi saldo, angka sistem, dan operand.',
  REPORT_LINE_NOT_FOUND: 'Baris tidak ditemukan di definisi ini.',
  REPORT_LINE_HAS_CHILDREN: 'Pindahkan atau hapus dulu baris yang berada di bawah baris ini.',
  LINE_PARENT_CYCLE: 'Baris tidak dapat dipindahkan ke bawah dirinya sendiri.',
}

/** A refusal of the definition API with an Indonesian message when it is one of its codes; any other value is returned untouched. */
export function definitionError(error: unknown): unknown {
  if (!(error instanceof ApiError) || !error.code) return error
  const text = ERRORS[error.code]
  return text ? new ApiError(error.status, text, error.code, error.details, error.fields) : error
}

/** The validator findings a refusal carries (publish and the mapping save send them as `details.errors` / `details.warnings`). */
export function refusalFindings(error: unknown): { errors: Finding[]; warnings: Finding[] } {
  const d = error instanceof ApiError ? error.details : {}
  const list = (value: unknown): Finding[] => (Array.isArray(value) ? (value as Finding[]).filter((f) => f && typeof f.code === 'string') : [])
  return { errors: list(d.errors), warnings: list(d.warnings) }
}

/** What the defaults endpoint reports for a statement type it left alone. */
export const skippedTexts: Record<string, string> = {
  REPORT_DRAFT_EXISTS: 'Sudah ada draf; tinjau dan terbitkan, atau hapus lebih dulu.',
  REPORT_DEFINITION_EXISTS: 'Sudah ada definisi terbit; buat versi baru untuk merevisi tata letaknya.',
  NO_ACCOUNTS: 'Bagan akun belum memiliki akun posting aktif untuk jenis laporan ini.',
}
