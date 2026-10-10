// Shapes of the tax API (backend: Tax\Services\TaxCodeService, TaxDocumentService, TaxReportService). Percentages and amounts are decimal
// strings exactly as the server returns them and are only formatted for display: every tax, base and total is calculated by the backend.

export type TaxType = 'INPUT_TAX' | 'OUTPUT_TAX' | 'WITHHOLDING' | 'OTHER'
export type TaxMethod = 'EXCLUSIVE' | 'INCLUSIVE'
export type TaxTreatment = 'STANDARD' | 'ZERO_RATED' | 'EXEMPT'
export type TaxDirection = 'INPUT' | 'OUTPUT'
export type TaxBasis = 'posting_date' | 'tax_date'
export type TaxTransactionStatus = 'DRAFT' | 'POSTED' | 'REVERSED'

export const TAX_TYPES: TaxType[] = ['INPUT_TAX', 'OUTPUT_TAX', 'WITHHOLDING', 'OTHER']
export const TAX_METHODS: TaxMethod[] = ['EXCLUSIVE', 'INCLUSIVE']
export const TAX_TREATMENTS: TaxTreatment[] = ['STANDARD', 'ZERO_RATED', 'EXEMPT']
export const TAX_DIRECTIONS: TaxDirection[] = ['INPUT', 'OUTPUT']
export const TAX_BASES: TaxBasis[] = ['posting_date', 'tax_date']
export const TAX_TRANSACTION_STATUSES: TaxTransactionStatus[] = ['POSTED', 'DRAFT', 'REVERSED']

export type AccountRef = { id: string; code: string; name: string }

/** One effective-dated rate of a code; `rate` is a percentage with six decimals. */
export type TaxRate = {
  id: string
  tax_code_id: string
  rate: string
  effective_from: string
  effective_until: string | null
}

/** The list carries no rates; the detail adds `rates` (newest first), the rate in force today and whether any document used the code. */
export type TaxCode = {
  id: string
  code: string
  name: string
  description: string | null
  tax_type: TaxType
  calculation_method: TaxMethod
  treatment: TaxTreatment
  is_recoverable: boolean
  account_role: string | null
  account_id: string | null
  account?: AccountRef | null
  status: 'ACTIVE' | 'INACTIVE'
  rates?: TaxRate[]
  current_rate?: string | null
  in_use?: boolean
}

/** What a document would calculate for an amount on a date, straight from the server's calculator (nothing is saved). */
export type TaxPreview = {
  tax_code: string
  tax_type: TaxType
  calculation_method: TaxMethod
  treatment: TaxTreatment
  is_recoverable: boolean
  date: string
  rate: string
  entered_amount: string
  base_amount: string
  tax_amount: string
  total_amount: string
}

/** The frozen snapshot of the tax of one document line. Foreign documents also carry the functional amounts the report adds up. */
export type TaxTransaction = {
  id: string
  tax_code_id: string
  tax_code: string
  tax_name: string
  tax_type: TaxType
  treatment: TaxTreatment
  calculation_method: TaxMethod
  is_recoverable: boolean
  rate: string
  direction: TaxDirection
  source_type: string
  source_id: string
  line_number: number
  entered_amount: string
  base_amount: string
  tax_amount: string
  tax_date: string
  posting_date: string | null
  document_number: string | null
  counterparty_type: string | null
  counterparty_id: string | null
  counterparty_name: string | null
  counterparty_tax_id: string | null
  status: TaxTransactionStatus
  currency?: string | null
  exchange_rate?: string | null
  functional_base_amount?: string | null
  functional_tax_amount?: string | null
}

export type TaxReportRow = {
  tax_code_id: string
  tax_code: string
  tax_name: string
  tax_type: TaxType
  direction: TaxDirection
  treatment: TaxTreatment
  is_recoverable: boolean
  rate: string
  base_amount: string
  tax_amount: string
  transactions: number
}

export type TaxReport = {
  basis: TaxBasis
  filters: Record<string, string>
  rows: TaxReportRow[]
  totals: {
    output_base: string
    output_tax: string
    input_base: string
    input_tax_recoverable: string
    input_tax_non_recoverable: string
    net_payable: string
  }
  credit_note_tax: { informational: boolean; ar_credit_note_tax: string; ar_credit_notes: number }
  /** False when data scope hid part of the tenant: the figures are then not the organisation's totals. */
  complete: boolean
}
