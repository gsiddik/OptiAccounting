import { describe, expect, it } from 'vitest'
import { CATALOG } from './catalog'
import { currentLocale, setLocale, translate } from './index'
import { interpolate } from './core'
import { ApiError } from '../lib/api'
import { describeError, statusLabel } from '../lib/labels'

const placeholders = (text: string) => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort()

describe('message catalog', () => {
  const entries = Object.entries(CATALOG)

  it('has both languages for every key, with the same placeholders', () => {
    expect(entries.length).toBeGreaterThan(100)
    for (const [key, message] of entries) {
      expect(message.id.trim(), `${key} (id)`).not.toBe('')
      expect(message.en.trim(), `${key} (en)`).not.toBe('')
      expect(placeholders(message.en), `${key} placeholders`).toEqual(placeholders(message.id))
    }
  })

  it('does not leave a translation identical to a placeholder or a key', () => {
    for (const [key, message] of entries) {
      expect(message.en, key).not.toBe(key)
      expect(message.id, key).not.toBe(key)
    }
  })
})

describe('language', () => {
  it('follows the chosen language, remembers it and sets the document language', () => {
    expect(currentLocale()).toBe('id')
    expect(translate('users.title')).toBe('Pengguna')
    setLocale('en')
    expect(translate('users.title')).toBe('Users')
    expect(localStorage.getItem('oa.locale')).toBe('en')
    expect(document.documentElement.lang).toBe('en')
  })

  it('fills placeholders and leaves an unknown one visible rather than printing "undefined"', () => {
    expect(interpolate('{a} of {b}', { a: 1 })).toBe('1 of {b}')
    expect(translate('users.seats', { used: 2, limit: 5 })).toBe('Kursi pengguna: 2 dari 5 terpakai')
  })

  it('shows refusals and statuses in the chosen language', () => {
    const refusal = new ApiError(409, 'x', 'ROLE_IN_USE', {}, {})
    expect(describeError(refusal)).toMatch(/Peran masih dipakai/)
    expect(statusLabel('INVITED')[0]).toBe('Diundang')
    setLocale('en')
    expect(describeError(refusal)).toMatch(/still assigned to users/)
    expect(statusLabel('INVITED')[0]).toBe('Invited')
    expect(describeError(new ApiError(0, 'x', null, {}, {}))).toBe('Cannot reach the server. Check your connection.')
    expect(describeError(new ApiError(409, 'x', 'CAPACITY_EXCEEDED', { limit_code: 'USER_LIMIT', limit: 3, used: 3 }, {}))).toBe('The user limit has been reached (3 of 3). Ask the platform administrator to raise it.')
  })

  it('tells the person that a write may have gone through when OptiNexus did not answer', () => {
    const unknown = new ApiError(503, 'x', 'IDENTITY_PROVIDER_UNAVAILABLE', { outcome_unknown: true }, {})
    expect(describeError(unknown)).toMatch(/mungkin sudah diterapkan/)
    expect(describeError(new ApiError(503, 'x', 'IDENTITY_PROVIDER_UNAVAILABLE', {}, {}))).toMatch(/tidak dapat dihubungi/)
  })

  it('lists the permissions OptiNexus refused', () => {
    expect(describeError(new ApiError(422, 'x', 'PERMISSION_NOT_DELEGABLE', { permissions: ['a.b', 'c.d'] }, {}))).toContain('a.b, c.d')
  })
})
