import { amountToApi, parseAmount, previewTotals, type JournalLine } from '../../lib/accounting'

export type LineState = {
  key: string
  account_id: string
  description: string
  debit: string
  credit: string
  branch_id: string
  business_unit_id: string
  cost_center_id: string
}

let counter = 0
export const emptyLine = (): LineState => ({ key: `l${++counter}`, account_id: '', description: '', debit: '', credit: '', branch_id: '', business_unit_id: '', cost_center_id: '' })

export function linesFrom(lines: JournalLine[] | undefined): LineState[] {
  if (!lines || lines.length === 0) return [emptyLine(), emptyLine()]
  return lines.map((l) => ({
    key: `l${++counter}`,
    account_id: l.account_id,
    description: l.description ?? '',
    debit: isZero(l.debit) ? '' : trimZeros(l.debit),
    credit: isZero(l.credit) ? '' : trimZeros(l.credit),
    branch_id: l.branch_id ?? '',
    business_unit_id: l.business_unit_id ?? '',
    cost_center_id: l.cost_center_id ?? '',
  }))
}

const isZero = (v: string) => /^0+(\.0+)?$/.test(v)
const trimZeros = (v: string) => (v.includes('.') ? v.replace(/\.?0+$/, '') : v)

/** What the API gets for a typed amount: a normalised decimal string, null when empty, the raw text when it is not a number (so the API refuses it). */
function amountPayload(input: string): string | null {
  const units = parseAmount(input)
  if (units === null) return input.trim()
  return units === 0n ? null : amountToApi(units)
}

export function linesPayload(lines: LineState[]) {
  return lines.map((l) => ({
    account_id: l.account_id,
    description: l.description.trim() || null,
    debit: amountPayload(l.debit),
    credit: amountPayload(l.credit),
    branch_id: l.branch_id || null,
    business_unit_id: l.business_unit_id || null,
    cost_center_id: l.cost_center_id || null,
  }))
}

/** Client-side hints while typing. The API decides; these only save a round trip for obvious mistakes. */
export function lineProblems(lines: LineState[]): string | null {
  const totals = previewTotals(lines)
  if (totals.invalid.length > 0) return `Jumlah pada baris ${totals.invalid.join(', ')} tidak valid. Gunakan angka dengan maksimal empat desimal.`
  const missing = lines.findIndex((l) => (parseAmount(l.debit) || parseAmount(l.credit)) && !l.account_id)
  if (missing >= 0) return `Pilih akun untuk baris ${missing + 1}.`
  const both = lines.findIndex((l) => (parseAmount(l.debit) ?? 0n) > 0n && (parseAmount(l.credit) ?? 0n) > 0n)
  if (both >= 0) return `Baris ${both + 1} berisi debit dan kredit sekaligus. Isi salah satu.`
  return null
}
