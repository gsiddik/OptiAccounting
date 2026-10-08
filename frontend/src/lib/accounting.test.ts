import { describe, expect, it } from 'vitest'
import { amountToApi, formatAmount, normalizeAmountInput, parseAmount, previewTotals } from './accounting'

describe('decimal-safe amounts', () => {
  it('parses plain decimals with a comma or a point and refuses everything else', () => {
    expect(parseAmount('1234,5')).toBe(12_345_000n)
    expect(parseAmount('1234.5')).toBe(12_345_000n)
    expect(parseAmount('0.0001')).toBe(1n)
    expect(parseAmount('')).toBe(0n)
    expect(parseAmount('  ')).toBe(0n)
    for (const bad of ['-5', '1e3', '1.23456', '1.2.3', 'abc', '1 000', '1,000.50', '+1']) expect(parseAmount(bad)).toBeNull()
  })

  it('never loses a cent to floating point', () => {
    const lines = [{ account_id: 'a', debit: '0.1', credit: '' }, { account_id: 'b', debit: '0.2', credit: '' }, { account_id: 'c', debit: '', credit: '0.3' }]
    const totals = previewTotals(lines)
    expect(totals.difference).toBe(0n)
    expect(totals.balanced).toBe(true)
    expect(0.1 + 0.2 === 0.3).toBe(false) // the trap this avoids
    const big = previewTotals([{ account_id: 'a', debit: '9999999999999999.99', credit: '' }, { account_id: 'b', debit: '', credit: '9999999999999999.99' }])
    expect(big.balanced).toBe(true)
  })

  it('reports an unbalanced or invalid preview', () => {
    expect(previewTotals([{ account_id: 'a', debit: '100', credit: '' }, { account_id: 'b', debit: '', credit: '99,99' }])).toMatchObject({ difference: 100n, balanced: false })
    expect(previewTotals([{ account_id: 'a', debit: 'x', credit: '' }, { account_id: 'b', debit: '', credit: '1' }]).invalid).toEqual([1])
    expect(previewTotals([]).balanced).toBe(false)
  })

  it('sends normalised strings, never numbers', () => {
    expect(normalizeAmountInput('1500000,5')).toBe('1500000.5000')
    expect(normalizeAmountInput('')).toBe('')
    expect(normalizeAmountInput('0')).toBe('')
    expect(amountToApi(12_345n)).toBe('1.2345')
  })

  it('formats API amounts in Indonesian grouping', () => {
    expect(formatAmount('1500000.5000')).toBe('1.500.000,50')
    expect(formatAmount('0.0000')).toBe('0,00')
    expect(formatAmount('12.3456')).toBe('12,3456')
    expect(formatAmount('-1234.5000')).toBe('-1.234,50')
    expect(formatAmount(null)).toBe('—')
  })
})
