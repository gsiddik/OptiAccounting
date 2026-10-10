// Exact decimal parsing for bank statement amounts. A statement line or balance is signed (a deposit is positive, a withdrawal
// negative; an overdraft balance may be negative), so the shared parseAmount of lib/accounting (non-negative only) does not fit.
// Everything here is string manipulation: no floating point ever touches a monetary value, and nothing is added or subtracted.

export type ParsedDecimal = { ok: true; value: string; zero: boolean } | { ok: false; error: string }

const MAX_INTEGER_DIGITS = 16
const MAX_DECIMALS = 4

const fail = (error: string): ParsedDecimal => ({ ok: false, error })
const countOf = (text: string, char: string) => text.split(char).length - 1
const escapeChar = (char: string) => (char === '.' ? '\\.' : char)

/** "1.234.567" or "1,234,567": a first group of 1-3 digits (no leading zero) and groups of exactly three. */
function groupedPattern(separator: string): RegExp {
  return new RegExp(`^[1-9]\\d{0,2}(?:${escapeChar(separator)}\\d{3})+$`)
}

/**
 * Parses an amount typed or pasted in Indonesian ("1.250.000,50") or US ("1,250,000.50") notation, with an optional leading
 * sign, into the canonical API string with four decimals ("-1250000.5000"). Rules:
 *  - both separators present: the last one is the decimal separator, the other must group the integer part in threes;
 *  - one separator repeated: grouping only ("1.234.567");
 *  - one separator once: a decimal separator ("1234,5", "0.50"), except when exactly three digits follow a 1-3 digit integer
 *    part that does not start with zero ("1.234" and "1,234" are read as 1234, the usual way thousands are written).
 * Anything else is refused so a mistyped figure never turns into a different amount silently.
 */
export function parseSignedDecimal(input: string): ParsedDecimal {
  let text = input.replace(/[\s ]/g, '')
  if (text === '') return fail('Jumlah wajib diisi.')

  let negative = false
  if (text[0] === '-' || text[0] === '−' || text[0] === '+') {
    negative = text[0] !== '+'
    text = text.slice(1)
  }
  if (!/^\d[\d.,]*\d$|^\d$/.test(text)) return fail('Jumlah tidak valid: gunakan angka, titik, dan koma (contoh 1.250.000,50).')

  const dots = countOf(text, '.')
  const commas = countOf(text, ',')
  let integer = text
  let fraction = ''

  if (dots > 0 && commas > 0) {
    const decimal = text.lastIndexOf('.') > text.lastIndexOf(',') ? '.' : ','
    const group = decimal === '.' ? ',' : '.'
    if (countOf(text, decimal) !== 1) return fail('Jumlah tidak valid: pemisah desimal hanya boleh satu.')
    const [whole, rest] = text.split(decimal)
    if (!groupedPattern(group).test(whole)) return fail('Jumlah tidak valid: pemisah ribuan harus mengelompokkan tiga angka.')
    if (!/^\d+$/.test(rest)) return fail('Jumlah tidak valid.')
    integer = whole.split(group).join('')
    fraction = rest
  } else if (dots + commas > 1) {
    const separator = dots > 0 ? '.' : ','
    if (!groupedPattern(separator).test(text)) return fail('Jumlah tidak valid: pemisah ribuan harus mengelompokkan tiga angka.')
    integer = text.split(separator).join('')
  } else if (dots + commas === 1) {
    const separator = dots > 0 ? '.' : ','
    const [whole, rest] = text.split(separator)
    if (rest.length === 3 && /^[1-9]\d{0,2}$/.test(whole)) integer = whole + rest
    else {
      integer = whole
      fraction = rest
    }
  }

  if (fraction.length > MAX_DECIMALS) return fail(`Jumlah paling banyak ${MAX_DECIMALS} angka desimal.`)
  integer = integer.replace(/^0+(?=\d)/, '')
  if (integer.length > MAX_INTEGER_DIGITS) return fail('Jumlah terlalu besar.')

  const decimals = fraction.padEnd(MAX_DECIMALS, '0')
  const zero = /^0+$/.test(integer + decimals)
  return { ok: true, value: `${negative && !zero ? '-' : ''}${integer}.${decimals}`, zero }
}

/** The amount without its sign, for showing a signed API amount as "masuk"/"keluar". */
export function unsigned(amount: string): string {
  return amount.startsWith('-') ? amount.slice(1) : amount
}

/** A signed statement amount is a deposit (IN) when positive and a withdrawal (OUT) when negative. */
export function directionOf(amount: string): 'IN' | 'OUT' {
  return amount.startsWith('-') ? 'OUT' : 'IN'
}

/** An API amount ("-25000.0000") as an editable plain figure ("-25000"): trailing decimal zeros removed. */
export function plainAmount(amount: string): string {
  return amount.includes('.') ? amount.replace(/\.?0+$/, '') : amount
}
