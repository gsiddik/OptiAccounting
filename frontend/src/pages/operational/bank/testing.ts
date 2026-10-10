import { setToken } from '../../../lib/api'
import type { Mode } from '../../../lib/types'
import { mockApi } from '../../../test/fakeApi'
import { tenantCaps, tenantMe } from '../../../test/render'
import type { BankStatement, CashBankReport, ReportAccount, StatementAccount, StatementItem, StatementSummary } from './types'

// Test fixtures for the bank reconciliation pages, the operational summary and the OA2 navigation tests. Not part of the app.

export const MODULES_ALL: Record<string, Mode> = { ACCOUNTING_CORE: 'FULL', ACCOUNTING_AP: 'FULL', ACCOUNTING_EXPENSE: 'FULL', ACCOUNTING_CASH_BANK: 'FULL' }
export const FEATURES_ALL: Record<string, boolean> = {
  VENDOR: true, VENDOR_INVOICE: true, AP_PAYMENT: true, AP_AGING: true, EXPENSE: true, CASH_BANK_ACCOUNT: true, PAYMENT: true, RECEIPT: true, BANK_RECONCILIATION: true,
}

export const VIEW = ['accounting.bank_reconciliation.view']
export const MANAGE = ['accounting.bank_reconciliation.view', 'accounting.bank_reconciliation.manage']

type BootOptions = { permissions: string[]; subscriptionMode?: 'FULL' | 'READ_ONLY'; modules?: Record<string, Mode>; features?: Record<string, boolean> }

/** Signed-in tenant user with the given permissions, entitlements and mocked routes. Returns the recorded requests. */
export function boot(options: BootOptions, routes: Parameters<typeof mockApi>[0] = {}) {
  const caps = tenantCaps({ permissions: options.permissions, subscriptionMode: options.subscriptionMode })
  const calls = mockApi({
    'GET /auth/me': { data: tenantMe },
    'GET /app/capabilities': { data: { ...caps, modules: options.modules ?? MODULES_ALL, features: options.features ?? FEATURES_ALL } },
    ...routes,
  })
  setToken('t')
  return calls
}

export const bankAccount: StatementAccount = {
  id: 'cb-1', code: 'BCA', name: 'Bank BCA', kind: 'BANK', bank_name: 'Bank Central Asia', account_number_masked: '••••0123', account_id: 'gl-1', status: 'ACTIVE',
}

/** A cash-bank-accounts list row (the shape of lib/operational CashBankAccount) for the account selects. */
export const cashBankAccountRow = (over: Record<string, unknown> = {}) => ({
  ...bankAccount, currency: 'IDR', gl_account: { id: 'gl-1', code: '1120', name: 'Bank BCA' }, account_holder: null, branch_id: null, business_unit_id: null, notes: null, ...over,
})

export const item = (over: Partial<StatementItem> = {}): StatementItem => ({
  id: 'it-1', bank_statement_id: 'st-1', line_number: 1, item_date: '2026-03-01', description: 'Setoran modal', reference: null, amount: '5000000.0000', status: 'UNMATCHED',
  matched_journal_line_id: null, matched_by: null, matched_at: null, notes: null, matcher: null, ...over,
})

export const summary = (over: Partial<StatementSummary> = {}): StatementSummary => ({
  book_balance: '3775000.0000', statement_balance: '3965000.0000', unmatched_book_net: '3775000.0000', unmatched_book_lines: 4, exception_statement_net: '0.0000',
  unexplained_difference: '190000.0000', status: 'DIFFERENCE', book_minus_statement: '-190000.0000', items_net: '3965000.0000', statement_consistent: null,
  unmatched_items: 1, matched_items: 0, exception_items: 0, ...over,
})

export const statement = (over: Partial<BankStatement> = {}): BankStatement => ({
  id: 'st-1', cash_bank_account_id: 'cb-1', reference: 'BCA-2026-03', statement_date: '2026-03-31', period_start: null, opening_balance: null, closing_balance: '3965000.0000', currency: 'IDR',
  notes: null, status: 'OPEN', completed_at: null, book_balance: null, unmatched_book_net: null, exception_statement_net: null, unexplained_difference: null,
  cash_bank_account: bankAccount, creator: { id: 'u-t', name: 'Budi Santoso' }, completer: null, branch: null, summary: summary(), items: [item()], ...over,
})

export const dashboard = () => ({ business_date: '2026-10-08', fiscal_year: null, period: null, journals: { draft: 0, pending_approval: 0, awaiting_posting: 0 }, recent_posted: [] })

export const reportAccount = (over: Partial<ReportAccount> = {}): ReportAccount => ({
  cash_bank_account_id: 'cb-1', code: 'BCA', name: 'Bank BCA', kind: 'BANK', status: 'ACTIVE', gl_account: { id: 'gl-1', code: '1120', name: 'Bank BCA' }, book_balance: '4075000.0000',
  documents: { receipts: '5000000.0000', cash_payments: '225000.0000', vendor_payments: '1000000.0000', paid_expenses: '0.0000', net: '3775000.0000', ledger_net: '3775000.0000', difference: '0.0000', status: 'MATCHED' },
  other_activity: '300000.0000',
  statement: { id: 'st-1', reference: 'BCA-2026-03', statement_date: '2026-03-31', status: 'OPEN', statement_balance: '4000000.0000', book_minus_statement: '75000.0000', unexplained_difference: null, reconciliation: 'IN_PROGRESS' },
  ...over,
})

export const report = (over: Partial<CashBankReport> = {}): CashBankReport => ({
  as_of: '2026-03-31', accounts: [reportAccount()], mismatched_accounts: 0, status: 'MATCHED', book_balance: '4075000.0000', complete: true, ...over,
})
