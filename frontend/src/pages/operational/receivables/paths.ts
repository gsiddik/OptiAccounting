// Routes of the receivables screens (the paths the menu, the lists and the detail pages link to).
export const AR_PATH = {
  customers: '/app/akuntansi/pelanggan',
  invoices: '/app/akuntansi/faktur-pelanggan',
  receipts: '/app/akuntansi/penerimaan-pelanggan',
  creditNotes: '/app/akuntansi/nota-kredit',
  aging: '/app/akuntansi/umur-piutang',
  reconciliation: '/app/akuntansi/rekonsiliasi/piutang',
} as const
