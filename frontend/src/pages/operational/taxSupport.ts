import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { useDebounced, useResource } from '../../lib/hooks'
import { API, MODULES, type Page } from '../../lib/operational'

// Tax codes on documents (OA4 tax). The server resolves the rate on the document's tax date, calculates base and tax with its one calculator, and
// returns the totals after saving. The only tax figure the screens show before that is the server's own answer to POST tax-codes/{id}/preview,
// labelled as a preview; nothing here calculates tax. Components are in taxOptions.tsx.

export type TaxCodeOption = {
  id: string
  code: string
  name: string
  tax_type: string
  calculation_method: 'EXCLUSIVE' | 'INCLUSIVE'
  treatment: string
  is_recoverable: boolean
  status: 'ACTIVE' | 'INACTIVE'
}

/** What POST tax-codes/{id}/preview answers: the calculation a document would make for an amount on a date. */
export type TaxPreview = {
  tax_code: string
  calculation_method: string
  treatment: string
  is_recoverable: boolean
  tax_type: string
  date: string
  rate: string
  entered_amount: string
  base_amount: string
  tax_amount: string
  total_amount: string
}

/** Which side of the books a document is: purchases take input tax codes, sales take output tax codes. */
export type TaxDirection = 'INPUT' | 'OUTPUT'

/** The tax controls of a line or document form; the editors pass this down only when the user may use tax codes. */
export type TaxSupport = {
  codes: TaxCodeOption[]
  /** The tax date: the document date (the expense date for an expense). */
  date: string
  /** The document is in a foreign currency: the server preview rounds at the functional scale, so it is not offered and the server calculates on save. */
  foreign?: boolean
}

// ------------------------------------------------------------------------------------------------ access and options

/**
 * `visible`: the user may read tax codes (accounting.tax.view) and the tax module and its feature are entitled (not NONE). `writable`: the module is
 * not read-only, so a document can be saved with a tax code. Cosmetic: the API enforces both.
 */
export function useTaxAccess() {
  const { can, moduleMode, featureEnabled } = useCapabilities()
  const mode = moduleMode(MODULES.tax)
  const visible = mode !== 'NONE' && featureEnabled('TAX_CONFIGURATION') && can('accounting.tax.view')
  return { visible, writable: visible && mode === 'FULL' }
}

/** The active tax codes a document of this side can use. Nothing is requested unless the select shows. */
export function useTaxCodes(direction: TaxDirection): { enabled: boolean; codes: TaxCodeOption[] } {
  const access = useTaxAccess()
  const list = useResource(async () => (access.writable ? (await api.get<Page<TaxCodeOption>>(`${API}/tax-codes`, { params: { status: 'ACTIVE', direction, per_page: 200 } })).data.data : []), [access.writable, direction])
  // A refusal (the feature is off for this tenant) leaves the editor exactly as it is without tax codes.
  return { enabled: access.writable && !list.error, codes: list.data ?? [] }
}

// ------------------------------------------------------------------------------------------------ preview (server)

/** "11.000000" -> "11", "2.500000" -> "2,5". */
export function formatPercent(rate: string | null | undefined): string {
  if (!rate) return '—'
  const trimmed = rate.includes('.') ? rate.replace(/0+$/, '').replace(/\.$/, '') : rate
  return trimmed.replace('.', ',')
}

/** The server's calculation of a tax code for one amount on a date (debounced). `amount` is a normalized decimal string, or '' when there is nothing to calculate. */
export function useTaxPreview(codeId: string, amount: string, date: string, enabled: boolean) {
  const settled = useDebounced(amount)
  const ready = enabled && codeId !== '' && settled !== '' && /^\d{4}-\d{2}-\d{2}$/.test(date)
  return useResource(async () => (ready ? (await api.post<TaxPreview>(`${API}/tax-codes/${codeId}/preview`, { amount: settled, date })).data : null), [ready, codeId, settled, date])
}

// ------------------------------------------------------------------------------------------------ detail pages

/** What a detail page shows about the tax code of a line or of an expense. */
export type TaxFact = { code: string; rate: string | null; tax_amount: string | null }

type CodeDetail = { code: string; rates?: { rate: string; effective_from: string; effective_until: string | null }[] }

/** The rate in force on a date from a code's rate history (a date comparison, not a calculation). */
function rateInForce(rates: CodeDetail['rates'], date: string): string | null {
  return (rates ?? []).find((r) => r.effective_from.slice(0, 10) <= date && (r.effective_until === null || r.effective_until.slice(0, 10) >= date))?.rate ?? null
}

/**
 * The tax code, rate and tax amount of saved lines, from the server: the preview for the line's entered amount on the document's tax date gives
 * the same figures the saved snapshot holds (rates are effective-dated and never move behind a used date). Where the preview is not available (a
 * read-only tax module refuses POST, or the document is foreign and rounds at its own currency's scale) only the code and its rate come back.
 * Keys are the ids the caller gave. Empty without accounting.tax.view or the tax module.
 */
export function useTaxFacts(items: { id: string; codeId: string; amount: string }[], date: string, options: { calculate: boolean } = { calculate: true }): Record<string, TaxFact> {
  const { visible } = useTaxAccess()
  const key = JSON.stringify(items)
  const facts = useResource(async () => {
    const out: Record<string, TaxFact> = {}
    if (!visible || items.length === 0) return out
    await Promise.all(items.map(async (item) => {
      if (options.calculate) {
        try {
          const p = (await api.post<TaxPreview>(`${API}/tax-codes/${item.codeId}/preview`, { amount: item.amount, date })).data
          out[item.id] = { code: p.tax_code, rate: p.rate, tax_amount: p.tax_amount }
          return
        } catch {
          // fall through to the code itself
        }
      }
      try {
        const c = (await api.get<CodeDetail>(`${API}/tax-codes/${item.codeId}`)).data
        out[item.id] = { code: c.code, rate: rateInForce(c.rates, date), tax_amount: null }
      } catch {
        // the code stays unnamed
      }
    }))
    return out
  }, [visible, key, date, options.calculate])
  return facts.data ?? {}
}

/** Tax facts for the saved lines of an invoice: `taxed` when any line has a tax code (the detail page then adds the tax columns). A foreign invoice gets the code and rate only. */
export function useLineTaxFacts(lines: { id: string; amount: string; tax_code_id?: string | null; entered_amount?: string | null }[], date: string, foreign: boolean) {
  const items = lines.filter((l) => l.tax_code_id).map((l) => ({ id: l.id, codeId: l.tax_code_id as string, amount: l.entered_amount ?? l.amount }))
  const facts = useTaxFacts(items, date, { calculate: !foreign })
  return { taxed: items.length > 0, facts }
}

