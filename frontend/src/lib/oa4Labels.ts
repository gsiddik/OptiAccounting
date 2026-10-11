// Indonesian texts for the OA4 modules (budget, fixed assets, tax): statuses, option labels and API refusals.

type Tone = 'ok' | 'warn' | 'bad' | 'info' | 'neutral'

export const OA4_STATUS: Record<string, [string, Tone]> = {
  SUPERSEDED: ['Digantikan', 'neutral'],
  FULLY_DEPRECIATED: ['Susut penuh', 'info'],
  DISPOSED: ['Dilepas', 'neutral'],
  PLANNED: ['Terjadwal', 'neutral'],
  IN_RUN: ['Dalam proses', 'info'],
}

export const budgetGroupLabels: Record<string, string> = {
  account: 'Per akun',
  period: 'Per periode',
  branch: 'Per cabang',
  business_unit: 'Per unit bisnis',
  cost_center: 'Per pusat biaya',
  line: 'Per baris anggaran',
}

export const assetMethodLabels: Record<string, string> = { STRAIGHT_LINE: 'Garis lurus', DECLINING_BALANCE: 'Saldo menurun', NONE: 'Tidak disusutkan' }
export const residualTypeLabels: Record<string, string> = { NONE: 'Tanpa nilai sisa', AMOUNT: 'Jumlah tetap', PERCENT: 'Persentase dari biaya' }
export const startPolicyLabels: Record<string, string> = { CAPITALIZATION_MONTH: 'Bulan kapitalisasi', NEXT_MONTH: 'Bulan berikutnya' }
export const capitalizationModeLabels: Record<string, string> = { POST: 'Posting jurnal kapitalisasi', REGISTER_ONLY: 'Catat saja (sudah dijurnal di faktur vendor)' }
export const disposalTypeLabels: Record<string, string> = { SALE: 'Penjualan', SCRAP: 'Penghapusan' }
export const assetRuleEventLabels: Record<string, string> = { ASSET_CAPITALIZED: 'Kapitalisasi aset', DEPRECIATION_RECOGNIZED: 'Penyusutan', ASSET_DISPOSED: 'Pelepasan aset' }

export const taxTypeLabels: Record<string, string> = { INPUT_TAX: 'Pajak masukan', OUTPUT_TAX: 'Pajak keluaran', WITHHOLDING: 'Pemotongan / pemungutan', OTHER: 'Lainnya' }
export const taxMethodLabels: Record<string, string> = { EXCLUSIVE: 'Eksklusif (pajak ditambahkan)', INCLUSIVE: 'Inklusif (pajak sudah termasuk)' }
export const taxTreatmentLabels: Record<string, string> = { STANDARD: 'Standar', ZERO_RATED: 'Tarif nol', EXEMPT: 'Dibebaskan' }
export const taxDirectionLabels: Record<string, string> = { INPUT: 'Masukan', OUTPUT: 'Keluaran' }
export const taxBasisLabels: Record<string, string> = { posting_date: 'Tanggal posting', tax_date: 'Tanggal pajak' }
export const taxSourceLabels: Record<string, string> = { ap_invoice: 'Faktur vendor', ar_invoice: 'Faktur pelanggan', expense: 'Beban' }

export const rateTypeLabels: Record<string, string> = { SPOT: 'Spot', DAILY: 'Harian', MONTH_END: 'Akhir bulan', MANUAL: 'Manual' }
export const fxRuleEventLabels: Record<string, string> = { VENDOR_PAYMENT_FX: 'Selisih kurs terealisasi (pembayaran vendor)', CUSTOMER_RECEIPT_FX: 'Selisih kurs terealisasi (penerimaan pelanggan)' }

export const OA4_MODULE_LABELS: Record<string, string> = {
  ACCOUNTING_BUDGET: 'Anggaran',
  ACCOUNTING_FIXED_ASSET: 'Aset tetap',
  ACCOUNTING_TAX: 'Pajak',
  ACCOUNTING_MULTI_CURRENCY: 'Multi mata uang',
}

export const OA4_ERRORS: Record<string, string> = {
  // Budget
  BUDGET_CODE_TAKEN: 'Kode anggaran sudah dipakai.',
  BUDGET_CODE_INVALID: 'Kode anggaran hanya boleh berisi huruf, angka, titik, garis bawah, atau strip.',
  BUDGET_NOT_EDITABLE: 'Anggaran yang sudah ditutup atau dibatalkan tidak dapat diubah.',
  BUDGET_INVALID_TRANSITION: 'Perubahan status anggaran ini tidak diizinkan dari status saat ini.',
  BUDGET_HAS_APPROVED_VERSION: 'Anggaran sudah memiliki versi yang pernah disetujui, sehingga tidak dapat dibatalkan. Tutup anggarannya.',
  BUDGET_NOT_ACTIVE: 'Anggaran harus berstatus aktif untuk tindakan ini.',
  BUDGET_VERSION_NOT_DRAFT: 'Baris anggaran hanya dapat diubah selama versinya masih berstatus draf.',
  BUDGET_VERSION_EMPTY: 'Versi tanpa baris tidak dapat diajukan. Isi sedikitnya satu baris anggaran.',
  BUDGET_VERSION_NOT_APPROVED: 'Hanya versi yang sudah disetujui yang dapat diaktifkan.',
  BUDGET_VERSION_ALREADY_ACTIVE: 'Versi ini sudah aktif.',
  BUDGET_VERSION_WINDOW_INVALID: 'Tanggal berlaku versi harus setelah versi aktif sebelumnya dimulai dan berada di dalam tahun fiskal.',
  BUDGET_LINE_OVERLAP: 'Dua baris anggaran saling tumpang tindih: akun, periode, dan dimensinya mencakup anggaran yang sama.',
  BUDGET_PERIOD_INVALID: 'Periode harus termasuk dalam tahun fiskal anggaran ini.',
  BUDGET_TOO_MANY_LINES: 'Satu versi anggaran paling banyak 6.000 baris.',
  BUDGET_SCOPE_REPLACE_FORBIDDEN: 'Cakupan data Anda tidak mencakup seluruh versi ini, sehingga semua baris tidak dapat diganti sekaligus.',
  BUDGET_COPY_REQUIRES_FULL_SCOPE: 'Menyalin versi membutuhkan akses ke seluruh organisasi, karena cakupan data Anda tidak melihat semua barisnya.',
  BUDGET_VERSION_NOT_FOUND: 'Versi anggaran tidak ditemukan.',
  BUDGET_VERSION_NOT_COPYABLE: 'Versi ini tidak dapat disalin.',
  BUDGET_FISCAL_YEAR_CLOSED: 'Tahun fiskal anggaran ini sudah ditutup.',
  BUDGET_RESPONSIBLE_INVALID: 'Penanggung jawab harus pengguna aktif di organisasi ini.',
  BUDGET_NO_EFFECTIVE_VERSION: 'Anggaran ini tidak memiliki versi yang berlaku pada tanggal tersebut.',
  BUDGET_GROUP_INVALID: 'Pengelompokan laporan tidak dikenal.',
  LABEL_REQUIRED: 'Label versi wajib diisi.',
  NAME_REQUIRED: 'Nama wajib diisi.',
  FISCAL_YEAR_NOT_FOUND: 'Tahun fiskal tidak ditemukan.',
  // Fixed assets
  ASSET_ALREADY_CAPITALIZED: 'Aset ini sudah dikapitalisasi.',
  ASSET_CAPITALIZATION_NOT_REVERSIBLE: 'Kapitalisasi tidak dapat dibalik lagi, karena aset sudah disusutkan atau dilepas.',
  ASSET_HAS_DEPRECIATION: 'Aset ini sudah memiliki penyusutan yang diposting.',
  ASSET_NOT_DRAFT: 'Hanya aset berstatus draf yang dapat dibuang. Aset yang sudah dikapitalisasi dibatalkan dengan membalik kapitalisasinya.',
  ASSET_NOT_EDITABLE: 'Ketentuan keuangan aset yang sudah dikapitalisasi tidak dapat diubah.',
  ASSET_INACTIVE: 'Aset ini tidak aktif.',
  ASSET_NOT_FOUND: 'Aset tidak ditemukan.',
  ASSET_REQUIRED: 'Aset wajib dipilih.',
  ASSET_CATEGORY_CODE_TAKEN: 'Kode kategori aset sudah dipakai.',
  ASSET_CATEGORY_CODE_INVALID: 'Kode kategori hanya boleh berisi huruf, angka, titik, garis bawah, atau strip.',
  ASSET_CATEGORY_INACTIVE: 'Kategori aset ini tidak aktif.',
  ASSET_CATEGORY_NOT_FOUND: 'Kategori aset tidak ditemukan.',
  ASSET_CATEGORY_IN_USE: 'Kategori ini sudah dipakai aset: nonaktifkan, jangan hapus.',
  ASSET_CATEGORY_INVALID: 'Pengaturan kategori aset tidak valid.',
  ASSET_COST_INVALID: 'Harga perolehan harus lebih besar dari nol.',
  ASSET_DATE_INVALID: 'Tanggal kapitalisasi tidak boleh sebelum tanggal perolehan.',
  ASSET_DATE_REQUIRED: 'Tanggal perolehan dan tanggal kapitalisasi wajib diisi.',
  ASSET_LIFE_INVALID: 'Umur manfaat harus antara 1 dan 1200 bulan.',
  ASSET_RESIDUAL_INVALID: 'Nilai sisa tidak boleh melebihi harga perolehan.',
  ASSET_RESIDUAL_POLICY_INVALID: 'Kebijakan nilai sisa kategori tidak valid.',
  ASSET_START_POLICY_INVALID: 'Kebijakan mulai susut tidak dikenal.',
  ASSET_MODE_INVALID: 'Mode kapitalisasi harus posting jurnal atau catat saja.',
  ASSET_NAME_REQUIRED: 'Nama aset wajib diisi.',
  ASSET_SOURCE_ACCOUNT_REQUIRED: 'Pilih akun sumber (utang, bank, atau penampung) untuk jurnal kapitalisasi.',
  ASSET_SOURCE_REQUIRED: 'Mode catat saja membutuhkan baris faktur vendor sebagai sumber.',
  ASSET_SOURCE_NOT_FOUND: 'Baris faktur vendor sumber tidak ditemukan.',
  ASSET_SOURCE_FOREIGN: 'Biaya baris faktur vendor dalam mata uang asing tidak dapat dicatat sebagai aset. Kapitalisasi aset dengan akun sumber.',
  ASSET_SOURCE_NOT_POSTED: 'Faktur vendor sumber harus sudah diposting.',
  ASSET_SOURCE_ACCOUNT_MISSING: 'Baris faktur vendor sumber tidak memakai akun aset.',
  ASSET_SOURCE_EXCEEDED: 'Biaya aset yang tercatat dari baris faktur ini melebihi jumlah barisnya.',
  ASSET_JOURNAL_MISMATCH: 'Jurnal kapitalisasi tidak sesuai dengan aset. Hubungi administrator.',
  DEPRECIATION_NOTHING_ELIGIBLE: 'Tidak ada aset yang perlu disusutkan sampai periode ini.',
  DEPRECIATION_DATE_INVALID: 'Tanggal posting penyusutan harus berada dalam periode yang dipilih.',
  DEPRECIATION_RUN_NOT_DRAFT: 'Hanya proses penyusutan berstatus draf yang dapat diposting atau dibatalkan.',
  DEPRECIATION_RUN_STALE: 'Data aset berubah sejak proses ini dihitung. Batalkan lalu hitung ulang.',
  DEPRECIATION_ASSET_NOT_ACTIVE: 'Ada aset dalam proses ini yang sudah tidak aktif.',
  DEPRECIATION_ASSET_NOT_ON_BOOKS: 'Ada aset dalam proses ini yang belum atau sudah tidak tercatat di pembukuan.',
  DEPRECIATION_ROWS_TAKEN: 'Sebagian bulan penyusutan sudah diambil proses lain.',
  DEPRECIATION_RUN_NOT_LAST: 'Pembalikan dilakukan dari proses terbaru terlebih dahulu: ada proses yang lebih baru untuk aset yang sama.',
  DEPRECIATION_RUN_ALREADY_REVERSED: 'Proses penyusutan ini sudah dibalik.',
  DEPRECIATION_RUN_NOT_POSTED: 'Hanya proses penyusutan yang sudah diposting yang dapat dibalik.',
  DEPRECIATION_JOURNAL_MISMATCH: 'Jurnal penyusutan tidak sesuai dengan prosesnya. Hubungi administrator.',
  DEPRECIATION_METHOD_UNKNOWN: 'Metode penyusutan tidak dikenal.',
  DEPRECIATION_PARAMS_INVALID: 'Parameter metode penyusutan tidak valid (faktor saldo menurun 1 sampai 4).',
  ASSET_DISPOSAL_EXISTS: 'Aset ini sudah memiliki pelepasan yang masih berjalan atau sudah diposting.',
  ASSET_DISPOSAL_PROCEEDS_REQUIRED: 'Penjualan membutuhkan hasil penjualan dan akun penerimaannya.',
  ASSET_DEPRECIATION_PENDING: 'Penyusutan aset ini belum diposting sampai tanggal pelepasan. Jalankan dan posting penyusutan lebih dulu.',
  ASSET_DEPRECIATION_IN_RUN: 'Aset ini masih ada dalam proses penyusutan berstatus draf. Posting atau batalkan prosesnya lebih dulu.',
  ASSET_NOT_DISPOSABLE: 'Aset pada status ini tidak dapat dilepas. Hanya aset aktif atau yang sudah susut penuh yang dapat dilepas.',
  ASSET_DISPOSAL_BEFORE_CAPITALIZATION: 'Tanggal pelepasan tidak boleh sebelum tanggal kapitalisasi aset.',
  ASSET_DISPOSAL_DATE_INVALID: 'Tanggal pelepasan tidak valid.',
  ASSET_DISPOSAL_TYPE_INVALID: 'Jenis pelepasan harus penjualan atau penghapusan.',
  ASSET_DISPOSAL_ASSET_LOCKED: 'Aset pada pelepasan ini tidak dapat diganti. Buat pelepasan baru.',
  ASSET_DISPOSAL_NOT_POSTED: 'Hanya pelepasan yang sudah diposting yang dapat dibalik.',
  ASSET_DISPOSAL_ALREADY_REVERSED: 'Pelepasan ini sudah dibalik.',
  ASSET_DISPOSAL_JOURNAL_MISMATCH: 'Jurnal pelepasan tidak sesuai dengan dokumennya. Hubungi administrator.',
  DOCUMENT_NOT_EDITABLE: 'Dokumen ini tidak dapat diubah pada status saat ini.',
  // Tax
  TAX_CODE_TAKEN: 'Kode pajak sudah dipakai.',
  TAX_CODE_INVALID: 'Kode pajak hanya boleh berisi huruf, angka, titik, garis bawah, atau strip.',
  TAX_TYPE_INVALID: 'Jenis pajak tidak dikenal.',
  TAX_METHOD_INVALID: 'Metode perhitungan harus eksklusif atau inklusif.',
  TAX_TREATMENT_INVALID: 'Perlakuan pajak tidak dikenal.',
  TAX_RECOVERABLE_INVALID: 'Pengaturan dapat dikreditkan tidak sesuai dengan jenis pajak ini.',
  TAX_ACCOUNT_REQUIRED: 'Kode pajak ini membutuhkan akun pajak atau peran akun.',
  TAX_RATE_INVALID: 'Tarif adalah persentase antara 0 dan 100 dengan paling banyak enam desimal; kode tarif nol atau dibebaskan bertarif 0.',
  TAX_RATE_OVERLAP: 'Sudah ada tarif yang berlaku pada tanggal efektif tersebut.',
  TAX_RATE_RETROACTIVE_CONFLICT: 'Tarif tidak dapat diubah surut: kode ini sudah dipakai transaksi terposting pada atau setelah tanggal efektif tersebut.',
  TAX_CODE_IN_USE: 'Kode pajak ini sudah dipakai dokumen: nonaktifkan, jangan hapus.',
  TAX_CODE_NOT_FOUND: 'Kode pajak tidak ditemukan.',
  TAX_CODE_INACTIVE: 'Kode pajak ini tidak aktif.',
  TAX_RATE_NOT_FOUND: 'Kode pajak ini belum memiliki tarif yang berlaku pada tanggal tersebut.',
  TAX_BASE_INVALID: 'Jumlah baris berpajak harus menyisakan dasar pengenaan pajak lebih besar dari nol.',
  TAX_TYPE_MISMATCH: 'Jenis kode pajak ini tidak sesuai dengan dokumen (faktur vendor dan beban memakai pajak masukan; faktur pelanggan memakai pajak keluaran).',
  TAX_WITHHOLDING_UNSUPPORTED: 'Kode pajak pemotongan/pemungutan belum dapat dipakai pada dokumen; hanya untuk laporan pajak.',
  TAX_HEADER_ADJUSTMENT_UNSUPPORTED: 'Dokumen dengan kode pajak tidak boleh memakai diskon header: kurangi jumlah pada barisnya.',
  TAX_AMOUNT_CONFLICT: 'Pajak dokumen dengan kode pajak dihitung dari barisnya. Kosongkan pajak manual.',
  TAX_CONFIGURATION_CHANGED: 'Kode atau tarif pajak berubah sejak dokumen ini disimpan. Buka dokumen lalu simpan ulang agar pajaknya dihitung dengan pengaturan terbaru.',
  TAX_REPORT_BASIS_INVALID: 'Dasar laporan pajak harus tanggal posting atau tanggal pajak.',
  // Multi-currency
  CURRENCY_CODE_INVALID: 'Mata uang diidentifikasi dengan kode ISO 4217 tiga huruf.',
  CURRENCY_IS_FUNCTIONAL: 'Mata uang fungsional selalu tersedia dan tidak perlu didaftarkan.',
  CURRENCY_NAME_REQUIRED: 'Nama mata uang wajib diisi.',
  CURRENCY_IN_USE: 'Mata uang ini sudah dipakai: nonaktifkan, jangan hapus atau ubah presisinya.',
  CURRENCY_PRECISION_INVALID: 'Jumlah desimal adalah bilangan bulat 0 sampai 4.',
  CURRENCY_TAKEN: 'Mata uang ini sudah terdaftar.',
  CURRENCY_NOT_FOUND: 'Mata uang ini belum didaftarkan untuk organisasi Anda.',
  CURRENCY_INACTIVE: 'Mata uang ini tidak aktif.',
  EXCHANGE_RATE_NOT_FOUND: 'Belum ada kurs yang berlaku untuk mata uang dan tanggal ini (atau kursnya sudah terlalu lama). Masukkan kurs terlebih dulu.',
  EXCHANGE_RATE_CHANGED: 'Kurs dokumen ini berubah setelah disimpan. Kembalikan ke draf lalu simpan ulang, atau batalkan dan masukkan lagi.',
  EXCHANGE_RATE_TYPE_INVALID: 'Jenis kurs harus spot, harian, akhir bulan, atau manual.',
  EXCHANGE_RATE_INVALID: 'Kurs harus bilangan positif dengan paling banyak enam desimal.',
  EXCHANGE_RATE_DATE_INVALID: 'Tanggal berlaku kurs wajib diisi (YYYY-MM-DD).',
  EXCHANGE_RATE_DUPLICATE: 'Sudah ada kurs aktif untuk mata uang, jenis, dan tanggal ini. Tarik kurs itu lebih dulu untuk memasukkan yang lain.',
  EXCHANGE_RATE_IMMUTABLE: 'Mata uang, tanggal, dan jenis kurs tidak dapat diubah. Tarik kurs ini lalu masukkan yang baru.',
  EXCHANGE_RATE_IN_USE: 'Ada dokumen yang memakai kurs ini: tarik (nonaktifkan), jangan hapus.',
  FUNCTIONAL_CURRENCY_HAS_RATES: 'Hapus mata uang asing dan kurs sebelum mengubah mata uang fungsional.',
  FX_ROUNDING_UNRESOLVABLE: 'Pembulatan akibat kurs tidak dapat diserap oleh baris dokumen.',
  AP_ALLOCATION_CURRENCY_MISMATCH: 'Pembayaran hanya dapat melunasi faktur dalam mata uang yang sama.',
  AR_ALLOCATION_CURRENCY_MISMATCH: 'Penerimaan hanya dapat melunasi faktur dalam mata uang yang sama.',
  LINE_FX_DIFFERENCE_INVALID: 'Selisih kurs terealisasi adalah baris mata uang fungsional dari posting sistem.',
}
