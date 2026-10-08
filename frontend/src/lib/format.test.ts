import { describe, expect, it } from 'vitest'
import { formatDate, formatNumber, todayIn } from './format'

describe('format', () => {
  it('shows a date-only value as written, whatever the browser time zone', () => {
    expect(formatDate('2026-01-01')).toMatch(/01/)
    expect(formatDate('2026-12-31')).toMatch(/31/)
    expect(formatDate('2026-12-31T00:00:00.000000Z')).toMatch(/31/)
  })

  it('renders absent values as a dash', () => {
    expect(formatDate(null)).toBe('—')
    expect(formatNumber(undefined)).toBe('—')
  })

  it('computes the business date in the given time zone', () => {
    expect(todayIn('Asia/Jakarta')).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })
})
