import { defineMessages } from '../core'

/** The product switcher and the end of a session that OptiNexus no longer allows (audit A29). */
export const session = defineMessages({
  'auth.sso.email_not_verified': {
    id: 'E-mail akun OptiNexus Anda belum diverifikasi. Selesaikan verifikasi melalui undangan OptiNexus atau hubungi operator ecosystem untuk verifikasi manual, lalu masuk kembali.',
    en: 'Your OptiNexus email is not verified. Complete verification through your OptiNexus invitation or contact an ecosystem operator for manual verification, then sign in again.',
  },
  'switcher.label': { id: 'Produk', en: 'Products' },
  'switcher.menu': { id: 'Pindah ke produk lain', en: 'Switch to another product' },
  'switcher.current': { id: 'Sedang dibuka', en: 'Open now' },
  'switcher.no_link': { id: 'Alamat produk belum diatur di OptiNexus', en: 'No address set for this product in OptiNexus' },
  'switcher.loading': { id: 'Memuat daftar produk…', en: 'Loading products…' },
  'switcher.unavailable': { id: 'Daftar produk tidak dapat dimuat sekarang karena OptiNexus tidak menjawab.', en: 'The product list cannot be loaded now because OptiNexus is not answering.' },
  'switcher.retry': { id: 'Coba lagi', en: 'Try again' },
  'switcher.cannot_switch': { id: 'Tidak dapat berpindah produk sekarang: OptiNexus tidak menjawab. Coba lagi sebentar lagi.', en: 'Cannot switch product now: OptiNexus is not answering. Try again shortly.' },
  'switcher.not_listed': { id: 'Produk ini tidak lagi tersedia untuk Anda.', en: 'This product is no longer available to you.' },

  'session.ended.title': { id: 'Sesi Anda diakhiri', en: 'Your session was ended' },
  'session.ended.session_expired': { id: 'Sesi Anda sudah berakhir. Masuk kembali untuk melanjutkan.', en: 'Your session has expired. Sign in again to continue.' },
  'session.ended.generic': { id: 'OptiNexus tidak lagi mengizinkan sesi ini. Masuk kembali untuk melanjutkan.', en: 'OptiNexus no longer allows this session. Sign in again to continue.' },
  'session.ended.user_disabled': { id: 'Akun Anda dinonaktifkan di OptiNexus. Hubungi administrator organisasi Anda.', en: 'Your account has been disabled in OptiNexus. Contact your organization\'s administrator.' },
  'session.ended.user_suspended': { id: 'Akun Anda sedang ditangguhkan di OptiNexus. Hubungi administrator organisasi Anda.', en: 'Your account is suspended in OptiNexus. Contact your organization\'s administrator.' },
  'session.ended.user_not_found': { id: 'Akun Anda tidak ditemukan di OptiNexus.', en: 'Your account was not found in OptiNexus.' },
  'session.ended.tenant_not_found': { id: 'Organisasi ini tidak ditemukan di OptiNexus.', en: 'This organization was not found in OptiNexus.' },
  'session.ended.tenant_suspended': { id: 'Organisasi Anda sedang ditangguhkan di OptiNexus.', en: 'Your organization is suspended in OptiNexus.' },
  'session.ended.tenant_inactive': { id: 'Organisasi Anda tidak aktif di OptiNexus.', en: 'Your organization is not active in OptiNexus.' },
  'session.ended.tenant_terminated': { id: 'Organisasi Anda telah dihentikan di OptiNexus.', en: 'Your organization has been terminated in OptiNexus.' },
  'session.ended.tenant_membership_removed': { id: 'Anda tidak lagi menjadi anggota organisasi ini.', en: 'You are no longer a member of this organization.' },
  'session.ended.application_access_revoked': { id: 'Akses Anda ke OptiEntry dicabut. Hubungi administrator organisasi Anda.', en: 'Your access to OptiEntry was removed. Contact your organization\'s administrator.' },
  'session.ended.application_not_assigned': { id: 'Organisasi Anda tidak lagi berlangganan OptiEntry.', en: 'Your organization is no longer subscribed to OptiEntry.' },
  'session.ended.application_unavailable': { id: 'OptiEntry sedang tidak tersedia di OptiNexus.', en: 'OptiEntry is not available in OptiNexus right now.' },
})
