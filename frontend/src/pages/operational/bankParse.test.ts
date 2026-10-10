import { describe, expect, it } from 'vitest'
import { directionOf, parseSignedDecimal, plainAmount, unsigned } from './bank/amounts'
import { buildLines, checkLine, emptyRow, isIsoDate, parsePastedLines } from './bank/lines'

const value = (input: string) => {
  const r = parseSignedDecimal(input)
  return r.ok ? r.value : `ERR:${r.error}`
}

describe('parseSignedDecimal: exact, signed, Indonesian and US notation', () => {
  it.each([
    ['5000000', '5000000.0000'],
    ['1.250.000,50', '1250000.5000'],
    ['1,250,000.50', '1250000.5000'],
    ['1250000.5', '1250000.5000'],
    ['1250000,5', '1250000.5000'],
    ['-25.000,00', '-25000.0000'],
    ['-25000', '-25000.0000'],
    ['−1.000.000', '-1000000.0000'], // unicode minus from a spreadsheet
    ['+500', '500.0000'],
    ['  - 1 234,5  ', '-1234.5000'], // spaces and a non-breaking space are ignored
    ['0,5', '0.5000'],
    ['0.123', '0.1230'],
    ['1.2345', '1.2345'],
    ['12345.678', '12345.6780'], // not a valid thousands group (five-digit integer part): a decimal
    ['1.234', '1234.0000'], // one separator followed by exactly three digits: thousands
    ['1,234', '1234.0000'],
    ['1.234.567', '1234567.0000'],
    ['1,234,567', '1234567.0000'],
    ['007', '7.0000'],
    ['9999999999999999.9999', '9999999999999999.9999'], // 16 integer digits + 4 decimals, no float can hold this exactly
  ])('%s -> %s', (input, expected) => {
    expect(value(input)).toBe(expected)
  })

  it('never goes through floating point: a figure beyond 2^53 keeps every digit', () => {
    expect(value('-9007199254740993,07')).toBe('-9007199254740993.0700')
  })

  it('keeps zero unsigned and flags it', () => {
    expect(parseSignedDecimal('-0,00')).toEqual({ ok: true, value: '0.0000', zero: true })
    expect(parseSignedDecimal('0')).toEqual({ ok: true, value: '0.0000', zero: true })
  })

  it.each(['', '   ', '-', '+', 'abc', '12abc', '1..2', '1.,5', '1,2,3', '1.234,567.8', '1.23,4', '100.', '.5', '1,5.000', '1.5,000', '0.000,50', '1.23456', '99999999999999999', '--5', '5-'])(
    'refuses %j instead of guessing',
    (input) => {
      expect(parseSignedDecimal(input).ok).toBe(false)
    },
  )

  it('splits an API amount into figure and direction, and plain editable text', () => {
    expect(unsigned('-25000.0000')).toBe('25000.0000')
    expect(unsigned('25000.0000')).toBe('25000.0000')
    expect(directionOf('-0.0100')).toBe('OUT')
    expect(directionOf('5000000.0000')).toBe('IN')
    expect(plainAmount('-25000.0000')).toBe('-25000')
    expect(plainAmount('100.0000')).toBe('100')
    expect(plainAmount('12.5000')).toBe('12.5')
    expect(plainAmount('10.0500')).toBe('10.05')
  })
})

describe('isIsoDate', () => {
  it('accepts real calendar dates only', () => {
    expect(isIsoDate('2026-03-31')).toBe(true)
    expect(isIsoDate('2028-02-29')).toBe(true)
    expect(isIsoDate('2026-02-29')).toBe(false)
    expect(isIsoDate('2026-13-01')).toBe(false)
    expect(isIsoDate('31/03/2026')).toBe(false)
    expect(isIsoDate('2026-3-1')).toBe(false)
  })
})

describe('parsePastedLines', () => {
  it('reads tab and semicolon separated lines, signed amounts included, and sends strings', () => {
    const { items, problems } = parsePastedLines('2026-03-01\tSetoran modal\t5.000.000,00\r\n2026-03-10;Transfer keluar vendor;-1.000.000\n\n2026-03-15\tBiaya admin\t-25000.1234\t\n')
    expect(problems).toEqual([])
    expect(items).toEqual([
      { item_date: '2026-03-01', description: 'Setoran modal', reference: null, amount: '5000000.0000' },
      { item_date: '2026-03-10', description: 'Transfer keluar vendor', reference: null, amount: '-1000000.0000' },
      { item_date: '2026-03-15', description: 'Biaya admin', reference: null, amount: '-25000.1234' }, // a trailing empty column is tolerated
    ])
  })

  it('lists every unreadable line with its number in the pasted text (blank lines count, but are not errors)', () => {
    const { items, problems } = parsePastedLines(
      ['2026-03-01;Setoran;5.000.000', '2026-02-30;Tanggal salah;100', 'hanya dua;kolom', '', '2026-03-05;Saldo bukan jumlah;100;12.345', '2026-03-06;Nol;0', '2026-03-07;Abc;12x', ';;'].join('\n'),
    )
    expect(items).toHaveLength(1)
    expect(problems.map((p) => p.line)).toEqual([2, 3, 5, 6, 7, 8])
    expect(problems[0].message).toMatch(/Tanggal harus berformat/)
    expect(problems[1].message).toMatch(/Butuh tiga kolom/)
    expect(problems[2].message).toMatch(/Terlalu banyak kolom/) // a fourth column (a running balance) is never taken for the amount
    expect(problems[3].message).toMatch(/tidak boleh nol/)
    expect(problems[4].message).toMatch(/Jumlah tidak valid/)
    expect(problems[5].message).toMatch(/Tanggal harus berformat/) // an entirely empty line of delimiters reports each missing piece
  })

  it('refuses descriptions that are empty or too long', () => {
    expect(parsePastedLines('2026-03-01;;100').problems[0].message).toMatch(/Keterangan wajib/)
    expect(parsePastedLines(`2026-03-01;${'x'.repeat(256)};100`).problems[0].message).toMatch(/255/)
  })
})

describe('buildLines', () => {
  it('puts typed rows first, skips blank rows, and maps a server error index back to its row', () => {
    const [blank, first, second] = [emptyRow(), { ...emptyRow(), item_date: '2026-03-31', description: 'Pajak bunga', amount: '-10.000,5' }, { ...emptyRow(), item_date: '2026-03-30', description: 'Bunga', reference: ' B-1 ', amount: '1500' }]
    const built = buildLines([blank, first, second], '2026-03-01;Setoran;5000000')
    expect(built.ready).toBe(true)
    expect(built.items.map((i) => i.amount)).toEqual(['-10000.5000', '1500.0000', '5000000.0000'])
    expect(built.items[1].reference).toBe('B-1')
    expect(built.indexOf.get(blank.key)).toBeUndefined()
    expect(built.indexOf.get(second.key)).toBe(1)
    expect(built.pastedCount).toBe(1)
  })

  it('is not ready while a row or a pasted line is unreadable, or when there are too many lines', () => {
    const bad = { ...emptyRow(), description: 'tanpa tanggal dan jumlah' }
    expect(buildLines([bad], '').ready).toBe(false)
    expect(buildLines([bad], '').rowErrors.get(bad.key)).toMatchObject({ item_date: expect.any(String), amount: expect.any(String) })
    expect(buildLines([], '2026-03-01;x;abc').ready).toBe(false)
    const many = Array.from({ length: 1001 }, () => '2026-03-01;x;1').join('\n')
    const tooMany = buildLines([], many)
    expect(tooMany.tooMany).toBe(true)
    expect(tooMany.ready).toBe(false)
  })

  it('checkLine returns the cleaned payload', () => {
    expect(checkLine({ item_date: ' 2026-03-01 ', description: '  Setoran ', reference: '', amount: ' 1.000 ' })).toEqual({
      item: { item_date: '2026-03-01', description: 'Setoran', reference: null, amount: '1000.0000' },
      errors: {},
    })
  })
})
