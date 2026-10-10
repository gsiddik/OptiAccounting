import { api } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { useResource } from '../../../lib/hooks'
import { API, type Page } from '../../../lib/operational'
import type { BankStatement, BookMovement } from './types'

export type MatchedJournal = { journal_entry_id: string; journal_number: string | null }

/**
 * Which journal each matched statement line belongs to. A statement item only stores the journal LINE it is matched to, so the
 * journal is learned from the account's ledger movements (matched lines, which carry the matched item and the journal). That
 * needs permission to see the account; without it, or when the request fails, items simply show no journal link.
 */
export function useMatchedJournals(statement: BankStatement | null): Map<string, MatchedJournal> {
  const { can } = useCapabilities()
  const accountId = statement?.cash_bank_account_id
  const matched = statement?.summary?.matched_items ?? 0
  const wanted = Boolean(accountId) && matched > 0 && can('accounting.cash_bank.view')

  const lines = useResource(async () => {
    if (!wanted) return [] as BookMovement[]
    try {
      return (await api.get<Page<BookMovement>>(`${API}/cash-bank-accounts/${accountId}/transactions`, { params: { matched: 1, per_page: 200 } })).data.data
    } catch {
      return [] as BookMovement[]
    }
  }, [accountId, wanted, matched])

  const byItem = new Map<string, MatchedJournal>()
  for (const line of lines.data ?? []) if (line.matched_item_id) byItem.set(line.matched_item_id, { journal_entry_id: line.journal_entry_id, journal_number: line.journal_number })
  return byItem
}
