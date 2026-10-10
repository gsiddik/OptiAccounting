import { parseSignedDecimal } from './amounts'

// Drafts of bank statement lines as the user types them (rows) or pastes them from a spreadsheet, and their exact conversion
// to the API payload. Amounts stay strings end to end: parsed by parseSignedDecimal, sent as decimal strings, never numbers.

export const MAX_ITEMS = 1000

/** A line as the API takes it: the amount is signed (deposit positive, withdrawal negative). */
export type ItemPayload = { item_date: string; description: string; reference: string | null; amount: string }

export type RowDraft = { key: number; item_date: string; description: string; reference: string; amount: string }
export type RowField = 'item_date' | 'description' | 'reference' | 'amount'
export type RowErrors = Partial<Record<RowField, string>>
export type PastedProblem = { line: number; message: string }

let nextKey = 1
export const emptyRow = (): RowDraft => ({ key: nextKey++, item_date: '', description: '', reference: '', amount: '' })

export const isBlankRow = (row: RowDraft) => [row.item_date, row.description, row.reference, row.amount].every((v) => v.trim() === '')

/** A real calendar date written YYYY-MM-DD. */
export function isIsoDate(value: string): boolean {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!m) return false
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])]
  const date = new Date(Date.UTC(y, mo - 1, d))
  return date.getUTCFullYear() === y && date.getUTCMonth() === mo - 1 && date.getUTCDate() === d
}

/** Validates one line; returns the payload when it is sound, otherwise a message per field. */
export function checkLine(input: { item_date: string; description: string; reference: string; amount: string }): { item: ItemPayload | null; errors: RowErrors } {
  const errors: RowErrors = {}
  const date = input.item_date.trim()
  const description = input.description.trim()
  const reference = input.reference.trim()

  if (!isIsoDate(date)) errors.item_date = 'Tanggal harus berformat TTTT-BB-HH.'
  if (description === '') errors.description = 'Keterangan wajib diisi.'
  else if (description.length > 255) errors.description = 'Keterangan paling banyak 255 karakter.'
  if (reference.length > 100) errors.reference = 'Referensi paling banyak 100 karakter.'

  const amount = parseSignedDecimal(input.amount)
  if (!amount.ok) errors.amount = amount.error
  else if (amount.zero) errors.amount = 'Jumlah tidak boleh nol (setoran positif, penarikan negatif).'

  if (Object.keys(errors).length > 0 || !amount.ok) return { item: null, errors }
  return { item: { item_date: date, description, reference: reference || null, amount: amount.value }, errors }
}

/**
 * Lines pasted from a spreadsheet: one per text line, exactly three columns separated by tabs (spreadsheet copy) or semicolons —
 * date (YYYY-MM-DD), description and the signed amount. Blank lines are skipped; every other line that cannot be read is reported
 * with its line number in the pasted text, and the paste is not usable until none is left. Extra columns are refused rather than
 * guessed at (a fourth column is often the running balance, which must never be taken for the amount).
 */
export function parsePastedLines(text: string): { items: ItemPayload[]; problems: PastedProblem[] } {
  const items: ItemPayload[] = []
  const problems: PastedProblem[] = []

  text.split(/\r\n|\r|\n/).forEach((raw, index) => {
    if (raw.trim() === '') return
    const fields = raw.split(raw.includes('\t') ? '\t' : ';')
    while (fields.length > 3 && fields[fields.length - 1].trim() === '') fields.pop()
    if (fields.length !== 3) {
      problems.push({ line: index + 1, message: fields.length < 3 ? 'Butuh tiga kolom: tanggal, keterangan, jumlah (dipisah tab atau titik koma).' : 'Terlalu banyak kolom: hanya tanggal, keterangan, dan jumlah.' })
      return
    }
    const { item, errors } = checkLine({ item_date: fields[0], description: fields[1], reference: '', amount: fields[2] })
    if (item) items.push(item)
    else problems.push({ line: index + 1, message: Object.values(errors).join(' ') })
  })

  return { items, problems }
}

export type BuiltLines = {
  /** Typed rows first (blank ones skipped), then the pasted lines: the order they are sent in. */
  items: ItemPayload[]
  /** Errors per typed row key. */
  rowErrors: Map<number, RowErrors>
  /** Index of each non-blank typed row in `items`, by row key (to attribute a server error `items.N.*` to its row). */
  indexOf: Map<number, number>
  problems: PastedProblem[]
  /** How many of `items` came from the pasted text. */
  pastedCount: number
  tooMany: boolean
  /** Every typed row and pasted line is readable and the count is within the limit. */
  ready: boolean
}

export function buildLines(rows: RowDraft[], pasted: string): BuiltLines {
  const items: ItemPayload[] = []
  const rowErrors = new Map<number, RowErrors>()
  const indexOf = new Map<number, number>()

  for (const row of rows) {
    if (isBlankRow(row)) continue
    const { item, errors } = checkLine(row)
    if (item) {
      indexOf.set(row.key, items.length)
      items.push(item)
    } else rowErrors.set(row.key, errors)
  }

  const paste = parsePastedLines(pasted)
  items.push(...paste.items)
  const tooMany = items.length > MAX_ITEMS
  return { items, rowErrors, indexOf, problems: paste.problems, pastedCount: paste.items.length, tooMany, ready: rowErrors.size === 0 && paste.problems.length === 0 && !tooMany }
}
