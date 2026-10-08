import { describe, expect, it } from 'vitest'
import { ApiError } from './api'
import { describeError, statusLabel } from './labels'

const apiError = (status: number, code: string | null, details: Record<string, unknown> = {}, fields: Record<string, string[]> = {}, message = 'raw') =>
  new ApiError(status, message, code, details, fields)

describe('describeError', () => {
  it('names the capacity that was exhausted and the numbers', () => {
    const text = describeError(apiError(409, 'CAPACITY_EXCEEDED', { limit_code: 'USER_LIMIT', limit: 5, used: 5 }))
    expect(text).toContain('pengguna')
    expect(text).toContain('5 dari 5')
  })

  it('lists the permissions that cannot be granted', () => {
    expect(describeError(apiError(403, 'PRIVILEGE_ESCALATION', { permissions: ['access.role.manage'] }))).toContain('access.role.manage')
  })

  it('maps known codes to Indonesian regardless of the status', () => {
    expect(describeError(apiError(403, 'SUBSCRIPTION_READ_ONLY'))).toMatch(/menunggak/)
    expect(describeError(apiError(409, 'SYSTEM_ROLE_IMMUTABLE'))).toMatch(/bawaan sistem/)
  })

  it('falls back to the first validation message for unknown 422s', () => {
    expect(describeError(apiError(422, null, {}, { email: ['E-mail tidak valid.'] }))).toBe('E-mail tidak valid.')
  })

  it('does not leak server internals on 5xx and explains a lost connection', () => {
    expect(describeError(apiError(500, null, {}, {}, 'SQLSTATE[23505] leaked detail'))).not.toContain('SQLSTATE')
    expect(describeError(apiError(0, null))).toMatch(/terhubung/)
  })

  it('treats a bare 403 as a permission problem', () => {
    expect(describeError(apiError(403, null))).toMatch(/izin/)
  })
})

describe('statusLabel', () => {
  it('shows overdue subscriptions as a warning and unknown values verbatim', () => {
    expect(statusLabel('PAST_DUE')).toEqual(['Menunggak', 'warn'])
    expect(statusLabel('SOMETHING_NEW')).toEqual(['SOMETHING_NEW', 'neutral'])
    expect(statusLabel(null)).toEqual(['—', 'neutral'])
  })
})
