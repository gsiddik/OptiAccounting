import { formatAmount } from './accounting'
import { ApiError } from './api'
import { ACCOUNTING_ERRORS, ACCOUNTING_STATUS } from './accountingLabels'
import { OA4_ERRORS, OA4_STATUS } from './oa4Labels'
import { OPERATIONAL_ERRORS, OPERATIONAL_STATUS, operationalModuleLabels } from './operationalLabels'

// Indonesian UI strings for statuses, codes and API refusals (UI is Indonesian-only for now).

type Tone = 'ok' | 'warn' | 'bad' | 'info' | 'neutral'

const STATUS: Record<string, [string, Tone]> = {
  ACTIVE: ['Aktif', 'ok'],
  DRAFT: ['Draf', 'neutral'],
  PENDING: ['Menunggu', 'info'],
  INVITED: ['Diundang', 'info'],
  PAST_DUE: ['Menunggak', 'warn'],
  SUSPENDED: ['Ditangguhkan', 'warn'],
  INACTIVE: ['Nonaktif', 'neutral'],
  EXPIRED: ['Kedaluwarsa', 'neutral'],
  CANCELLED: ['Dibatalkan', 'neutral'],
  TERMINATED: ['Dihentikan', 'bad'],
  DISABLED: ['Dinonaktifkan', 'neutral'],
  READ_ONLY: ['Hanya baca', 'warn'],
  FULL: ['Penuh', 'ok'],
  NONE: ['Tidak ada akses', 'bad'],
}

export function statusLabel(status: string | null | undefined): [string, Tone] {
  if (!status) return ['—', 'neutral']
  return STATUS[status] ?? ACCOUNTING_STATUS[status] ?? OPERATIONAL_STATUS[status] ?? OA4_STATUS[status] ?? [status, 'neutral']
}

export const scopeLabels: Record<string, string> = {
  TENANT: 'Seluruh organisasi',
  BRANCH: 'Cabang',
  BUSINESS_UNIT: 'Unit bisnis',
  OWN: 'Milik sendiri',
}

export const sourceLabels: Record<string, string> = {
  BUNDLE: 'Paket',
  ADD_ON: 'Add-on',
  CUSTOM_CONTRACT: 'Kontrak khusus',
  MANUAL_OVERRIDE: 'Penyesuaian manual',
  OPTINEXUS: 'OptiNexus',
}

export const capacityLabels: Record<string, string> = {
  USER_LIMIT: 'Pengguna',
  BRANCH_LIMIT: 'Cabang',
  BUSINESS_UNIT_LIMIT: 'Unit bisnis',
}

export const groupLabels: Record<string, string> = {
  Access: 'Akses & pengguna',
  Organization: 'Organisasi',
  Account: 'Langganan',
  Audit: 'Audit',
  Tenants: 'Tenant',
  Memberships: 'Keanggotaan',
  Catalog: 'Katalog modul',
  Bundles: 'Paket',
  Subscriptions: 'Langganan',
  Entitlements: 'Hak akses modul',
  Operators: 'Operator platform',
  Roles: 'Peran',
  Permissions: 'Izin',
}

const ERRORS: Record<string, string> = {
  ...ACCOUNTING_ERRORS,
  ...OPERATIONAL_ERRORS,
  ...OA4_ERRORS,
  INVALID_CREDENTIALS: 'E-mail atau kata sandi salah.',
  USER_INACTIVE: 'Akun ini tidak aktif.',
  TENANT_NOT_ENTERABLE: 'Anda tidak dapat masuk ke organisasi ini.',
  PLATFORM_NOT_ENTERABLE: 'Akun ini tidak memiliki akses platform.',
  TENANT_INACTIVE: 'Organisasi ini sedang tidak aktif.',
  MEMBERSHIP_INACTIVE: 'Keanggotaan Anda di organisasi ini tidak aktif.',
  PERMISSION_DENIED: 'Anda tidak memiliki izin untuk tindakan ini.',
  DATA_SCOPE_DENIED: 'Data ini di luar cakupan akses Anda.',
  MODULE_NOT_ENTITLED: 'Modul ini tidak termasuk dalam langganan.',
  MODULE_READ_ONLY: 'Modul ini dalam mode hanya baca.',
  SUBSCRIPTION_READ_ONLY: 'Langganan menunggak: hanya dapat membaca data.',
  SUBSCRIPTION_INACTIVE: 'Langganan tidak aktif.',
  FEATURE_NOT_ENTITLED: 'Fitur ini tidak termasuk dalam langganan.',
  TOKEN_SCOPE: 'Sesi ini tidak berlaku untuk portal tersebut.',
  PRIVILEGE_ESCALATION: 'Anda tidak dapat memberikan izin atau cakupan data yang tidak Anda miliki.',
  SYSTEM_ROLE_IMMUTABLE: 'Peran bawaan sistem tidak dapat diubah. Buat peran baru sebagai gantinya.',
  ROLE_NAME_TAKEN: 'Nama peran sudah dipakai.',
  ROLE_IN_USE: 'Peran masih dipakai pengguna. Lepaskan dulu dari pengguna.',
  UNKNOWN_ROLE: 'Peran tidak ditemukan.',
  UNKNOWN_PERMISSION: 'Kode izin tidak dikenal.',
  UNKNOWN_BRANCH: 'Cabang tidak ditemukan.',
  UNKNOWN_BUSINESS_UNIT: 'Unit bisnis tidak ditemukan.',
  BRANCH_HAS_ACTIVE_UNITS: 'Nonaktifkan dulu unit bisnis aktif di cabang ini.',
  INVALID_SCOPE: 'Jenis cakupan data tidak valid.',
  NO_INVITATION: 'Tidak ada undangan yang menunggu.',
  CODE_TAKEN: 'Kode sudah dipakai.',
  MEMBER_EXISTS: 'Pengguna ini sudah menjadi anggota organisasi.',
  PASSWORD_REQUIRED: 'Kata sandi wajib diisi untuk pengguna baru.',
  SELF_CHANGE_REFUSED: 'Anda tidak dapat mengubah status akun Anda sendiri.',
  EMAIL_TAKEN: 'E-mail sudah terdaftar.',
  INVALID_TRANSITION: 'Perubahan status ini tidak diizinkan dari status saat ini.',
  INVALID_STATUS: 'Status tidak valid untuk tindakan ini.',
  INVALID_WINDOW: 'Tanggal berakhir tidak boleh sebelum tanggal mulai.',
  SUBSCRIPTION_EXISTS: 'Tenant ini masih memiliki langganan yang berjalan.',
  BUNDLE_INACTIVE: 'Paket ini tidak aktif.',
  BUNDLE_DEPENDENCY_MISSING: 'Paket harus memuat modul yang dibutuhkan modul lain di dalamnya.',
  UNKNOWN_MODULE: 'Kode modul tidak dikenal.',
  UNKNOWN_CAPACITY: 'Kode batas kapasitas tidak dikenal.',
  MODULE_INACTIVE: 'Modul tidak aktif di katalog.',
  MODULE_NOT_AVAILABLE: 'Modul belum tersedia secara komersial.',
  MODULE_IN_USE: 'Modul masih dipakai tenant dan tidak dapat dinonaktifkan.',
  SELF_DEPENDENCY: 'Modul tidak dapat membutuhkan dirinya sendiri.',
  CIRCULAR_DEPENDENCY: 'Dependensi ini akan membentuk lingkaran.',
  DEPENDENCY_EXISTS: 'Dependensi ini sudah ada.',
  DEPENDENCY_NOT_FOUND: 'Dependensi tidak ditemukan.',
  DEPENDENCY_CONFLICT: 'Ada tenant yang memakai modul ini tanpa modul yang dibutuhkan.',
  DEPENDENCY_MISSING: 'Modul yang dibutuhkan belum aktif untuk tenant ini.',
  ACTIVE_DEPENDENTS: 'Modul ini masih dibutuhkan modul lain yang aktif.',
  ENTITLEMENT_OVERLAP: 'Periode hak akses bertabrakan dengan periode yang sudah ada.',
  MANAGED_BY_OPTINEXUS: 'Data ini dikelola di OptiNexus dan tidak dapat diubah di sini.',
  IDENTITY_PROVIDER_UNAVAILABLE: 'OptiNexus sedang tidak dapat dihubungi. Coba lagi sebentar lagi.',
  LOCAL_LOGIN_DISABLED: 'Masuk dengan kata sandi dinonaktifkan untuk akun ini. Gunakan OptiNexus.',
  INVALID_TICKET: 'Tautan masuk tidak valid atau sudah kedaluwarsa. Silakan masuk kembali.',
}

/** Why a sign-in through OptiNexus was turned away (the `sso_error` the API puts on the login URL). */
const SSO_ERRORS: Record<string, string> = {
  access_denied: 'Masuk dibatalkan atau ditolak di OptiNexus.',
  sso_disabled: 'Masuk melalui OptiNexus belum diaktifkan di instalasi ini.',
  sso_unavailable: 'OptiNexus sedang tidak dapat dihubungi. Coba lagi sebentar lagi.',
  sso_failed: 'Masuk melalui OptiNexus gagal. Silakan coba lagi.',
  state_invalid: 'Sesi masuk sudah kedaluwarsa. Silakan mulai masuk kembali.',
  token_exchange_failed: 'OptiNexus tidak dapat menyelesaikan proses masuk. Silakan coba lagi.',
  id_token_invalid: 'Jawaban OptiNexus tidak dapat diverifikasi. Silakan coba lagi.',
  tenant_not_linked: 'Organisasi Anda belum terhubung ke OptiEntry. Hubungi administrator OptiNexus.',
  tenant_not_entitled: 'Organisasi Anda belum berlangganan OptiEntry di OptiNexus.',
  tenant_inactive: 'Organisasi ini sedang tidak aktif.',
  no_permissions: 'Anda belum diberi izin apa pun untuk OptiEntry di OptiNexus. Hubungi administrator organisasi Anda.',
  capacity_exceeded: 'Batas jumlah pengguna organisasi ini tercapai. Hubungi administrator.',
  email_not_verified: 'E-mail akun OptiNexus Anda belum diverifikasi.',
  email_required: 'Akun OptiNexus Anda tidak memiliki e-mail.',
  account_conflict: 'E-mail ini sudah terhubung ke identitas OptiNexus lain. Hubungi administrator.',
  user_inactive: 'Akun ini tidak aktif.',
  membership_inactive: 'Keanggotaan Anda di organisasi ini tidak aktif.',
}

export function describeSsoError(code: string | null | undefined): string | null {
  if (!code) return null
  return SSO_ERRORS[code] ?? SSO_ERRORS.sso_failed
}

/**
 * Figures the server computed for a refusal (what the receipt amount and its allocation are, what is still outstanding on an invoice),
 * appended exactly as reported. Nothing is calculated here.
 */
function serverFigures(error: ApiError): string {
  const { code, details: d } = error
  const money = (value: unknown) => (typeof value === 'string' ? formatAmount(value) : null)
  const parts: (string | null)[] = []
  if (code === 'RECEIPT_NOT_FULLY_ALLOCATED') {
    parts.push(money(d.amount) && `jumlah penerimaan ${money(d.amount)}`, money(d.allocated) && `teralokasi ${money(d.allocated)}`)
  } else if (code === 'AR_ALLOCATION_EXCEEDS_OUTSTANDING' || code === 'AR_CREDIT_NOTE_EXCEEDS_OUTSTANDING') {
    parts.push(typeof d.document_number === 'string' ? `faktur ${d.document_number}` : null, money(d.outstanding) && `saldo piutang ${money(d.outstanding)}`)
  }
  const shown = parts.filter((part): part is string => Boolean(part))
  return shown.length > 0 ? ` (${shown.join(', ')})` : ''
}

/** A message a person can act on, from any thrown value. */
export function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.code === 'CAPACITY_EXCEEDED') {
      const d = error.details as { limit_code?: string; limit?: number; used?: number }
      const what = capacityLabels[d.limit_code ?? ''] ?? 'kapasitas'
      return `Batas ${what.toLowerCase()} tercapai (${d.used ?? '?'} dari ${d.limit ?? '?'}). Hubungi administrator platform untuk menambah kapasitas.`
    }
    if (error.code === 'PRIVILEGE_ESCALATION' && Array.isArray(error.details.permissions)) {
      return `${ERRORS.PRIVILEGE_ESCALATION} (${(error.details.permissions as string[]).join(', ')})`
    }
    if (error.code === 'DEPENDENCY_MISSING' && Array.isArray(error.details.missing)) {
      return `${ERRORS.DEPENDENCY_MISSING} Dibutuhkan: ${(error.details.missing as string[]).join(', ')}.`
    }
    if (error.code === 'ACTIVE_DEPENDENTS' && Array.isArray(error.details.dependents)) {
      return `${ERRORS.ACTIVE_DEPENDENTS} Dipakai oleh: ${(error.details.dependents as string[]).join(', ')}.`
    }
    if (error.code === 'MODULE_NOT_AVAILABLE' && typeof error.details.module === 'string') {
      return `Dokumen ini membutuhkan modul ${operationalModuleLabels[error.details.module] ?? error.details.module} yang aktif dan tidak dalam mode hanya baca.`
    }
    if (error.code && ERRORS[error.code]) return `${ERRORS[error.code]}${serverFigures(error)}`
    const first = Object.values(error.fields)[0]?.[0]
    if (error.status === 422 && first) return first
    if (error.status === 0) return 'Tidak dapat terhubung ke server. Periksa koneksi Anda.'
    if (error.status === 429) return 'Terlalu banyak percobaan. Coba lagi sebentar lagi.'
    if (error.status >= 500) return 'Terjadi kesalahan pada server. Coba lagi nanti.'
    if (error.status === 403) return ERRORS.PERMISSION_DENIED
    if (error.status === 404) return 'Data tidak ditemukan.'
    return error.message
  }
  return error instanceof Error ? error.message : 'Terjadi kesalahan.'
}
