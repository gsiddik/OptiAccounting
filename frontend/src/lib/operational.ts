import { useMemo } from 'react'
import { api } from './api'
import { useCapabilities } from './capabilities'
import { useResource } from './hooks'

// Types and hooks shared by every OA2 page (payables, expense, cash & bank). Amounts are strings from the API and are only
// formatted for display; every total, balance and allocation proposal comes from the backend.

export const API = '/app/accounting'

export type Page<T> = { data: T[]; current_page: number; last_page: number; total: number; per_page: number }

export type DocStatus = 'DRAFT' | 'SUBMITTED' | 'APPROVED' | 'REJECTED' | 'POSTED' | 'CANCELLED' | 'REVERSED'
export const DOC_STATUSES: DocStatus[] = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'POSTED', 'CANCELLED', 'REVERSED']

/** What the signed-in user may still do with a document under the segregation-of-duties policy (the server decides; the UI only reflects it). */
export type DocSod = { approve: boolean; post: boolean; approval_required?: boolean }

export type DocTransition = { id: string; from_status: string | null; to_status: string; actor_user_id: string | null; actor?: { id: string; name: string } | null; reason: string | null; occurred_at: string }

export type Ref = { id: string; code: string; name: string }

export type PaymentTerm = {
  id: string
  code: string
  name: string
  term_type: 'NET_DAYS' | 'END_OF_MONTH' | 'CUSTOM'
  due_days: number | null
  allows_due_date_override: boolean
  description: string | null
  status: 'ACTIVE' | 'INACTIVE'
}

export type Vendor = {
  id: string
  code: string
  name: string
  legal_name: string | null
  status: 'ACTIVE' | 'INACTIVE'
  contact_name: string | null
  email: string | null
  phone: string | null
  address: string | null
  tax_id: string | null
  tax_registered: boolean
  payment_term_id: string | null
  payment_term?: Pick<PaymentTerm, 'id' | 'code' | 'name'> | null
  default_currency: string | null
  payable_account_id: string | null
  default_expense_account_id: string | null
  payable_account?: { id: string; code: string; name: string } | null
  default_expense_account?: { id: string; code: string; name: string } | null
  external_source: string | null
  external_id: string | null
  notes: string | null
}

export type CashBankAccount = {
  id: string
  code: string
  name: string
  kind: 'CASH' | 'BANK'
  status: 'ACTIVE' | 'INACTIVE'
  currency: string
  account_id: string
  gl_account?: { id: string; code: string; name: string } | null
  bank_name: string | null
  account_holder: string | null
  /** Only the masked number ever reaches the browser. */
  account_number_masked: string | null
  branch_id: string | null
  business_unit_id: string | null
  branch?: Ref | null
  notes: string | null
  /** Derived from posted general-ledger lines. */
  book_balance?: string
}

export type ExpenseCategory = {
  id: string
  code: string
  name: string
  description: string | null
  account_role: string | null
  account_id: string | null
  account?: { id: string; code: string; name: string } | null
  status: 'ACTIVE' | 'INACTIVE'
}

/** OA2 counters for the accounting home. A section is null when the user may not open the matching list. */
export type OperationalSummary = {
  business_date: string
  payables: {
    outstanding: { amount: string; invoices: number }
    overdue: { amount: string; invoices: number }
    due_soon: { days: number; amount: string; invoices: number }
    pending_approval: number
    awaiting_posting: number
  } | null
  payments: { pending_approval: number; awaiting_posting: number } | null
  expenses: { pending_approval: number; awaiting_posting: number } | null
  cash_bank: { book_balance: string; cash: string; bank: string; accounts: number; as_of: string } | null
  complete: boolean
}

export const MODULES = { core: 'ACCOUNTING_CORE', ap: 'ACCOUNTING_AP', expense: 'ACCOUNTING_EXPENSE', cashBank: 'ACCOUNTING_CASH_BANK' } as const

/**
 * Permission and entitlement check for an OA2 page. A change needs the permission AND this module AND the accounting core to be
 * writable (a READ_ONLY subscription keeps reading). Cosmetic only: the API enforces every one of them.
 */
export function useModuleAccess(module: string) {
  const { can, moduleMode } = useCapabilities()
  const writable = moduleMode(module) === 'FULL' && moduleMode(MODULES.core) === 'FULL'
  return {
    can,
    writable,
    readOnly: moduleMode(module) === 'READ_ONLY',
    canChange: (permission: string) => writable && can(permission),
  }
}

type Options<T> = { data: T[] }

/** Vendors for a select box (up to 200, active first by code). */
export function useVendors(status?: 'ACTIVE') {
  const r = useResource(async () => (await api.get<Page<Vendor>>(`${API}/vendors`, { params: { per_page: 200, ...(status && { status }) } })).data.data, [status])
  return { ...r, vendors: r.data ?? [] }
}

export function usePaymentTerms() {
  const r = useResource(async () => (await api.get<Options<PaymentTerm>>(`${API}/payment-terms`)).data.data, [])
  return { ...r, terms: r.data ?? [] }
}

export function useCashBankAccounts(status?: 'ACTIVE') {
  const r = useResource(async () => (await api.get<Page<CashBankAccount>>(`${API}/cash-bank-accounts`, { params: { per_page: 200, ...(status && { status }) } })).data.data, [status])
  const byId = useMemo(() => new Map((r.data ?? []).map((a) => [a.id, a])), [r.data])
  return { ...r, accounts: r.data ?? [], byId }
}

export function useExpenseCategories() {
  const r = useResource(async () => (await api.get<Options<ExpenseCategory>>(`${API}/expense-categories`)).data.data, [])
  return { ...r, categories: r.data ?? [] }
}

/** Query params for a list from a filter object: empty values are dropped, booleans only when true. */
export function listParams(filter: Record<string, string | boolean | number>, page: number): Record<string, string | number | boolean> {
  const params: Record<string, string | number | boolean> = { page }
  for (const [k, v] of Object.entries(filter)) if (v !== '' && v !== false) params[k] = v
  return params
}
