import { defineMessages } from '../core'

/** Titles, explanations and actions of the screens that replace a page (or part of one) when something went wrong. */
export const states = defineMessages({
  'state.offline.title': { id: 'Anda sedang offline', en: 'You are offline' },
  'state.offline.body': { id: 'Tidak ada koneksi internet. Data yang belum disimpan tidak hilang; coba lagi setelah tersambung.', en: 'There is no internet connection. Nothing was lost; try again once you are connected.' },
  'state.session.title': { id: 'Sesi Anda berakhir', en: 'Your session has ended' },
  'state.session.body': { id: 'Masuk kembali untuk melanjutkan. Pekerjaan yang sudah disimpan tetap aman.', en: 'Sign in again to continue. Work that was saved is safe.' },
  'state.forbidden.title': { id: 'Anda tidak memiliki akses', en: 'You do not have access' },
  'state.forbidden.body': { id: 'Akun Anda tidak diberi izin untuk halaman atau tindakan ini. Hubungi administrator organisasi Anda.', en: 'Your account has no permission for this page or action. Contact your organization\'s administrator.' },
  'state.entitlement.title': { id: 'Tidak termasuk dalam langganan', en: 'Not included in the subscription' },
  'state.entitlement.body': { id: 'Modul atau fitur ini tidak aktif untuk organisasi Anda. Hubungi administrator organisasi untuk berlangganan.', en: 'This module or feature is not active for your organization. Ask your administrator to subscribe.' },
  'state.provider.title': { id: 'OptiNexus belum dapat dijangkau', en: 'OptiNexus is not reachable' },
  'state.provider.body': { id: 'Layanan identitas sedang tidak menjawab. Tidak ada perubahan yang dibuat; coba lagi sebentar lagi.', en: 'The identity service is not answering. Nothing was changed; try again shortly.' },
  'state.setup.title': { id: 'Pengelolaan lewat OptiNexus belum siap', en: 'Management through OptiNexus is not ready' },
  'state.setup.body': { id: 'Instalasi atau akun Anda belum terhubung penuh ke OptiNexus. Hubungi administrator platform.', en: 'This installation or your account is not fully linked to OptiNexus. Contact the platform administrator.' },
  'state.not_found.title': { id: 'Tidak ditemukan', en: 'Not found' },
  'state.not_found.body': { id: 'Halaman atau data yang Anda cari tidak ada atau sudah dihapus.', en: 'The page or record you are looking for does not exist or was removed.' },
  'state.server.title': { id: 'Terjadi gangguan di server', en: 'The server had a problem' },
  'state.server.body': { id: 'Permintaan Anda tidak dapat diselesaikan. Coba lagi nanti; sebutkan kode referensi jika menghubungi dukungan.', en: 'Your request could not be completed. Try again later; quote the reference if you contact support.' },
  'state.chunk.title': { id: 'Versi baru tersedia', en: 'A new version is available' },
  'state.chunk.body': { id: 'Bagian halaman ini tidak dapat dimuat karena aplikasi sudah diperbarui. Muat ulang untuk melanjutkan.', en: 'Part of this page could not be loaded because the application was updated. Reload to continue.' },
  'state.unexpected.title': { id: 'Terjadi kesalahan tak terduga', en: 'Something unexpected happened' },
  'state.unexpected.body': { id: 'Halaman ini tidak dapat ditampilkan. Data Anda tidak berubah. Muat ulang, atau hubungi dukungan dengan kode referensi.', en: 'This page could not be displayed. Your data was not changed. Reload, or contact support with the reference.' },
  'state.action.retry': { id: 'Coba lagi', en: 'Try again' },
  'state.action.reload': { id: 'Muat ulang', en: 'Reload' },
  'state.action.sign_in': { id: 'Masuk kembali', en: 'Sign in again' },
  'state.action.home': { id: 'Ke beranda', en: 'Go to home' },
})
