import { amountToApi, formatAmount, parseAmount } from '../../../lib/accounting'
import { amountInput } from '../expense/payload'
import type { CashTransaction } from './types'

// The cash payment / receipt form's state and request body. The amount shown while typing is only a preview; the API stores its own.

export type CashForm = {
  cash_bank_account_id: string
  counter_account_id: string
  amount: string
  transaction_date: string
  posting_date: string
  purpose: string
  description: string
  counterparty_name: string
  reference: string
  branch_id: string
  business_unit_id: string
  cost_center_id: string
}

export const emptyCashForm = (today: string): CashForm => ({
  cash_bank_account_id: '', counter_account_id: '', amount: '', transaction_date: today, posting_date: today, purpose: '', description: '', counterparty_name: '', reference: '',
  branch_id: '', business_unit_id: '', cost_center_id: '',
})

export function cashFormFrom(t: CashTransaction): CashForm {
  return {
    cash_bank_account_id: t.cash_bank_account_id, counter_account_id: t.counter_account_id, amount: amountInput(t.amount), transaction_date: t.transaction_date.slice(0, 10), posting_date: t.posting_date.slice(0, 10),
    purpose: t.purpose, description: t.description, counterparty_name: t.counterparty_name ?? '', reference: t.reference ?? '',
    branch_id: t.branch_id ?? '', business_unit_id: t.business_unit_id ?? '', cost_center_id: t.cost_center_id ?? '',
  }
}

/** The amount as it will be formatted once stored, or null when the input is not a plain decimal. */
export function previewAmount(input: string): string | null {
  const units = parseAmount(input)
  return units === null ? null : formatAmount(amountToApi(units))
}

export type CashBody = { body: Record<string, string | null> } | { problem: string }

export function buildCashBody(f: CashForm): CashBody {
  const amount = parseAmount(f.amount)
  if (amount === null) return { problem: 'Jumlah harus berupa angka tanpa pemisah ribuan, dengan maksimal empat desimal.' }
  return {
    body: {
      cash_bank_account_id: f.cash_bank_account_id,
      counter_account_id: f.counter_account_id,
      amount: amountToApi(amount),
      transaction_date: f.transaction_date,
      posting_date: f.posting_date,
      purpose: f.purpose.trim(),
      description: f.description.trim(),
      counterparty_name: f.counterparty_name.trim() || null,
      reference: f.reference.trim() || null,
      branch_id: f.branch_id || null,
      business_unit_id: f.business_unit_id || null,
      cost_center_id: f.cost_center_id || null,
    },
  }
}
