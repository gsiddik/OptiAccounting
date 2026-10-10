// Response shapes of the bank reconciliation API (OA2 batch H), kept close to the backend. Amounts are strings; the UI only formats them.

export type StatementStatus = 'OPEN' | 'COMPLETED'
export type ItemStatus = 'UNMATCHED' | 'MATCHED' | 'EXCEPTION'

/** The bank account a statement belongs to (only the masked number ever reaches the browser). */
export type StatementAccount = {
  id: string
  code: string
  name: string
  kind: 'CASH' | 'BANK'
  bank_name: string | null
  account_number_masked: string | null
  account_id: string
  status: 'ACTIVE' | 'INACTIVE'
}

export type StatementItem = {
  id: string
  bank_statement_id: string
  line_number: number
  item_date: string
  description: string
  reference: string | null
  /** Signed from the book's side: deposits positive, withdrawals negative. */
  amount: string
  status: ItemStatus
  matched_journal_line_id: string | null
  matched_by: string | null
  matched_at: string | null
  notes: string | null
  matcher?: { id: string; name: string } | null
}

/** The reconciliation figures exactly as the API computes them: (statement balance + unmatched book net) - (book balance + exception net). */
export type StatementSummary = {
  book_balance: string
  statement_balance: string
  unmatched_book_net: string
  /** null once the statement is completed (only the frozen amounts are kept). */
  unmatched_book_lines: number | null
  exception_statement_net: string
  unexplained_difference: string
  status: 'RECONCILED' | 'DIFFERENCE' | 'IN_PROGRESS'
  book_minus_statement: string
  items_net: string
  /** null when the statement has no opening balance to test against. */
  statement_consistent: boolean | null
  unmatched_items: number
  matched_items: number
  exception_items: number
}

export type BankStatement = {
  id: string
  cash_bank_account_id: string
  reference: string
  statement_date: string
  period_start: string | null
  opening_balance: string | null
  closing_balance: string
  currency: string
  notes: string | null
  status: StatementStatus
  completed_at: string | null
  /** Frozen evidence, present once the reconciliation is completed. */
  book_balance: string | null
  unmatched_book_net: string | null
  exception_statement_net: string | null
  unexplained_difference: string | null
  cash_bank_account?: StatementAccount | null
  creator?: { id: string; name: string } | null
  completer?: { id: string; name: string } | null
  branch?: { id: string; code: string; name: string } | null
  /** Line counts: present on list rows. */
  unmatched_items?: number
  matched_items?: number
  exception_items?: number
  /** Present on a single statement. */
  summary?: StatementSummary
  items?: StatementItem[]
}

/** A posted book line that a statement line could be matched with. */
export type Candidate = {
  journal_line_id: string
  journal_number: string | null
  posting_date: string
  description: string | null
  reference: string | null
  amount: string
  source_type: string | null
  source_id: string | null
  days_apart: number
}

/** One row of the account's ledger movements; used only to learn which journal a matched line belongs to. */
export type BookMovement = { journal_line_id: string; journal_entry_id: string; journal_number: string | null; matched_item_id: string | null }

// ---------------------------------------------------------------------------------------------- cash/bank vs general ledger report

export type ReportStatement = {
  id: string
  reference: string
  statement_date: string
  status: StatementStatus
  statement_balance: string
  book_minus_statement: string
  /** null until the statement is completed. */
  unexplained_difference: string | null
  reconciliation: 'IN_PROGRESS' | 'RECONCILED' | 'DIFFERENCE'
}

export type ReportAccount = {
  cash_bank_account_id: string
  code: string
  name: string
  kind: 'CASH' | 'BANK'
  status: 'ACTIVE' | 'INACTIVE'
  gl_account: { id: string; code: string | null; name: string | null }
  book_balance: string
  documents: {
    receipts: string
    cash_payments: string
    vendor_payments: string
    paid_expenses: string
    net: string
    ledger_net: string
    difference: string
    status: 'MATCHED' | 'MISMATCH'
  }
  /** What the ledger holds on the account beyond the documents (opening balance, manual journals): explained, not an error. */
  other_activity: string
  statement: ReportStatement | null
}

export type CashBankReport = {
  as_of: string
  accounts: ReportAccount[]
  mismatched_accounts: number
  status: 'MATCHED' | 'MISMATCH'
  book_balance: string
  /** false when the user's data scope covers only part of the organisation. */
  complete: boolean
}
