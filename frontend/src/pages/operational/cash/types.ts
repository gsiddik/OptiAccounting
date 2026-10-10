import type { CashBankAccount, DocSod, DocStatus, DocTransition, Ref } from '../../../lib/operational'

export type CashKind = 'PAYMENT' | 'RECEIPT'

/** Everything that differs between a cash payment (money out) and a cash receipt (money in): API path, route, texts. */
export const KIND: Record<CashKind, { path: string; route: string; title: string; noun: string; newLabel: string; counterLabel: string; counterShort: string; direction: string; posting: string }> = {
  PAYMENT: {
    path: 'cash-payments',
    route: '/app/akuntansi/pembayaran-kas',
    title: 'Pembayaran kas',
    noun: 'transaksi kas',
    newLabel: 'Pembayaran baru',
    counterLabel: 'Akun tujuan (lawan)',
    counterShort: 'Akun lawan',
    direction: 'Uang keluar dari kas/bank',
    posting: 'Saat diposting: Debit akun lawan, Kredit akun kas/bank. Saldo kas/bank berkurang.',
  },
  RECEIPT: {
    path: 'cash-receipts',
    route: '/app/akuntansi/penerimaan-kas',
    title: 'Penerimaan kas',
    noun: 'transaksi kas',
    newLabel: 'Penerimaan baru',
    counterLabel: 'Akun sumber (lawan)',
    counterShort: 'Akun lawan',
    direction: 'Uang masuk ke kas/bank',
    posting: 'Saat diposting: Debit akun kas/bank, Kredit akun lawan. Saldo kas/bank bertambah.',
  },
}

export type CashTransaction = {
  id: string
  document_number: string | null
  kind: CashKind
  status: DocStatus
  cash_bank_account_id: string
  counter_account_id: string
  transaction_date: string
  posting_date: string
  currency: string
  amount: string
  purpose: string
  description: string
  reference: string | null
  counterparty_name: string | null
  branch_id: string | null
  business_unit_id: string | null
  cost_center_id: string | null
  created_by: string | null
  posted_at: string | null
  journal_entry_id: string | null
  reversal_journal_id: string | null
  reversal_reason: string | null
  reversal_posting_date: string | null
  cancel_reason: string | null
  creator?: { id: string; name: string } | null
  cash_bank_account?: { id: string; code: string; name: string; kind: 'CASH' | 'BANK'; bank_name: string | null; account_number_masked: string | null; status: string } | null
  counter_account?: { id: string; code: string; name: string; account_type: string } | null
  gl_account?: Ref | null
  branch?: Ref | null
  business_unit?: Ref | null
  cost_center?: Ref | null
  transitions?: DocTransition[]
  sod?: DocSod
}

/** A cash/bank account as the list and the detail return it. */
export type CashAccount = CashBankAccount & { business_unit?: Ref | null; in_use?: boolean }

/** One posted ledger line of a cash/bank account; the running balance and the match state are computed by the API. */
export type Movement = {
  journal_line_id: string
  journal_entry_id: string
  journal_number: string | null
  journal_type: string
  source_type: string | null
  source_id: string | null
  posting_date: string
  description: string | null
  reference: string | null
  debit: string
  credit: string
  amount: string
  direction: 'IN' | 'OUT'
  running_balance: string
  document_number: string | null
  matched_item_id: string | null
  matched_statement: string | null
  matched_statement_id: string | null
}

export const movementSourceLabels: Record<string, string> = {
  vendor_payment: 'Pembayaran vendor',
  expense: 'Beban',
  cash_transaction: 'Transaksi kas',
  ap_invoice: 'Faktur vendor',
}
