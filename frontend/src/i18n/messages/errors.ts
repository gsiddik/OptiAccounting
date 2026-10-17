import { defineMessages } from '../core'

/**
 * Refusals the API (or the transport) can give, by `error.<CODE>`; `error.status.*` are the fallbacks by HTTP status.
 * Codes that are not listed here keep the Indonesian text from lib/labels.ts until their screen moves over.
 */
export const errors = defineMessages({
  'error.status.network': { id: 'Tidak dapat terhubung ke server. Periksa koneksi Anda.', en: 'Cannot reach the server. Check your connection.' },
  'error.status.rate_limited': { id: 'Terlalu banyak percobaan. Coba lagi sebentar lagi.', en: 'Too many attempts. Try again in a moment.' },
  'error.status.server': { id: 'Terjadi kesalahan pada server. Coba lagi nanti.', en: 'Something went wrong on the server. Try again later.' },
  'error.status.forbidden': { id: 'Anda tidak memiliki izin untuk tindakan ini.', en: 'You do not have permission for this action.' },
  'error.status.not_found': { id: 'Data tidak ditemukan.', en: 'The record was not found.' },
  'error.status.unexpected': { id: 'Terjadi kesalahan.', en: 'Something went wrong.' },

  'error.PERMISSION_DENIED': { id: 'Anda tidak memiliki izin untuk tindakan ini.', en: 'You do not have permission for this action.' },
  'error.SUBSCRIPTION_READ_ONLY': { id: 'Langganan menunggak: hanya dapat membaca data.', en: 'The subscription is overdue: data can be read but not changed.' },
  'error.SUBSCRIPTION_INACTIVE': { id: 'Langganan tidak aktif.', en: 'The subscription is not active.' },
  'error.MODULE_NOT_ENTITLED': { id: 'Modul ini tidak termasuk dalam langganan.', en: 'This module is not part of the subscription.' },
  'error.FEATURE_NOT_ENTITLED': { id: 'Fitur ini tidak termasuk dalam langganan.', en: 'This feature is not part of the subscription.' },
  'error.CAPACITY_EXCEEDED': {
    id: 'Batas {what} tercapai ({used} dari {limit}). Hubungi administrator platform untuk menambah kapasitas.',
    en: 'The {what} limit has been reached ({used} of {limit}). Ask the platform administrator to raise it.',
  },
  'error.MANAGED_BY_OPTINEXUS': { id: 'Data ini dikelola di OptiNexus dan tidak dapat diubah di sini.', en: 'This is managed in OptiNexus and cannot be changed here.' },
  'error.IDENTITY_PROVIDER_UNAVAILABLE': {
    id: 'OptiNexus sedang tidak dapat dihubungi. Coba lagi sebentar lagi.',
    en: 'OptiNexus cannot be reached right now. Try again shortly.',
  },
  'error.IDENTITY_PROVIDER_UNAVAILABLE.write': {
    id: 'OptiNexus tidak menjawab. Perubahan ini mungkin sudah diterapkan: muat ulang daftar sebelum mengulang.',
    en: 'OptiNexus did not answer. The change may already have been applied: reload the list before repeating it.',
  },
  'error.ROLE_NAME_TAKEN': { id: 'Nama peran sudah dipakai.', en: 'A role with this name already exists.' },
  'error.UNKNOWN_PERMISSION': { id: 'Kode izin tidak dikenal: {permissions}.', en: 'Unknown permission code: {permissions}.' },
  'error.PRIVILEGE_ESCALATION': { id: 'Anda tidak dapat memberikan izin yang tidak Anda miliki.', en: 'You cannot grant permissions you do not hold yourself.' },

  // OptiNexus delegation: the refusals are decisions of OptiNexus and are shown as such.
  'error.ACTOR_NOT_TENANT_ADMIN': {
    id: 'Hanya Admin Tenant di OptiNexus yang dapat mengelola pengguna dan peran. Minta Admin Tenant organisasi Anda.',
    en: 'Only an Admin Tenant in OptiNexus can manage users and roles. Ask your organization\'s Admin Tenant.',
  },
  'error.TENANT_NOT_ACTIVE': { id: 'Organisasi ini tidak aktif di OptiNexus.', en: 'This organization is not active in OptiNexus.' },
  'error.APPLICATION_NOT_ACTIVE': { id: 'OptiEntry tidak aktif di OptiNexus.', en: 'OptiEntry is not active in OptiNexus.' },
  'error.APPLICATION_NOT_SUBSCRIBED': { id: 'Organisasi ini belum berlangganan OptiEntry di OptiNexus.', en: 'This organization has no OptiEntry subscription in OptiNexus.' },
  'error.PERMISSION_NOT_DELEGABLE': {
    id: 'OptiNexus menolak izin yang tidak dapat didelegasikan: {permissions}.',
    en: 'OptiNexus refused permissions that cannot be delegated: {permissions}.',
  },
  'error.ROLE_NOT_ASSIGNABLE': { id: 'OptiNexus menolak peran yang tidak dapat diberikan ke pengguna ini.', en: 'OptiNexus refused a role that cannot be assigned to this user.' },
  'error.ROLE_NOT_EDITABLE': { id: 'Peran ini tidak dapat diubah atau dihapus (peran bawaan atau tidak ditemukan).', en: 'This role cannot be changed or deleted (built-in role or not found).' },
  'error.ROLE_IN_USE': { id: 'Peran masih dipakai pengguna. Lepaskan dulu dari pengguna.', en: 'The role is still assigned to users. Remove it from them first.' },
  'error.USER_NOT_IN_TENANT': { id: 'Pengguna ini bukan anggota organisasi. Muat ulang daftar.', en: 'This user is not a member of the organization. Reload the list.' },
  'error.TENANT_ADMIN_PROTECTED': { id: 'Admin Tenant tidak dapat diubah atau dicabut dari sini. Kelola di OptiNexus.', en: 'An Admin Tenant cannot be changed or removed from here. Manage them in OptiNexus.' },
  'error.IDENTITY_NOT_LINKABLE': {
    id: 'Akun dengan e-mail ini tidak dapat dihubungkan ke organisasi. Hubungi administrator OptiNexus.',
    en: 'The account with this e-mail cannot be linked to the organization. Contact the OptiNexus administrator.',
  },
  'error.MEMBERSHIP_NOT_ACTIVE': { id: 'Keanggotaan pengguna ini tidak aktif di OptiNexus.', en: 'This user\'s membership is not active in OptiNexus.' },
  'error.SELF_REVOCATION_DENIED': { id: 'Anda tidak dapat mencabut akses Anda sendiri.', en: 'You cannot remove your own access.' },
  'error.DELEGATION_NOT_CONFIGURED': {
    id: 'Pengelolaan pengguna belum disiapkan untuk instalasi ini di OptiNexus. Hubungi administrator platform.',
    en: 'User management is not set up for this installation in OptiNexus. Contact the platform administrator.',
  },
  'error.DELEGATION_NOT_AVAILABLE': { id: 'Pengelolaan pengguna melalui OptiNexus tidak aktif pada instalasi ini.', en: 'User management through OptiNexus is not enabled on this installation.' },
  'error.TENANT_NOT_LINKED': { id: 'Organisasi ini belum terhubung ke OptiNexus.', en: 'This organization is not linked to OptiNexus yet.' },
  'error.ACTOR_NOT_LINKED': { id: 'Akun Anda belum terhubung ke OptiNexus. Masuk melalui OptiNexus terlebih dahulu.', en: 'Your account is not linked to OptiNexus yet. Sign in through OptiNexus first.' },
  'error.DELEGATION_TENANT_UNKNOWN': { id: 'OptiNexus tidak mengenal organisasi ini. Hubungi administrator platform.', en: 'OptiNexus does not know this organization. Contact the platform administrator.' },
  'error.DELEGATION_UNEXPECTED_RESPONSE': { id: 'OptiNexus memberi jawaban yang tidak dikenali. Coba lagi atau hubungi dukungan dengan kode referensi.', en: 'OptiNexus gave an answer this application does not understand. Try again or contact support with the reference.' },
  'error.DELEGATION_IN_PROGRESS': { id: 'Peran dengan nama ini sedang dibuat. Tunggu sebentar lalu muat ulang.', en: 'A role with this name is already being created. Wait a moment, then reload.' },
})
