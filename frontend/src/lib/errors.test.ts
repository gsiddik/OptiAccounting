import { describe, expect, it } from 'vitest'
import { ApiError } from './api'
import { classifyError, errorReference, isChunkLoadError } from './errors'

const api = (status: number, code: string | null = null, details: Record<string, unknown> = {}, requestId: string | null = null) => new ApiError(status, 'x', code, details, {}, requestId)

describe('classifyError', () => {
  it.each([
    [api(401), 'session'],
    [api(403, 'PERMISSION_DENIED'), 'forbidden'],
    [api(403, 'ACTOR_NOT_TENANT_ADMIN'), 'forbidden'],
    [api(403, 'MODULE_NOT_ENTITLED'), 'entitlement'],
    [api(403, 'SUBSCRIPTION_INACTIVE'), 'entitlement'],
    [api(503, 'IDENTITY_PROVIDER_UNAVAILABLE'), 'provider'],
    [api(503, 'DELEGATION_NOT_CONFIGURED'), 'setup'],
    [api(403, 'ACTOR_NOT_LINKED'), 'setup'],
    [api(404), 'not_found'],
    [api(500), 'server'],
    [api(502, 'DELEGATION_UNEXPECTED_RESPONSE'), 'server'],
    [api(0), 'server'],
    [api(422, 'ROLE_NAME_TAKEN'), 'unexpected'],
    [new Error('boom'), 'unexpected'],
    [new TypeError('Failed to fetch dynamically imported module: https://x/assets/Reports-abc.js'), 'chunk'],
    [Object.assign(new Error('Loading chunk 7 failed.'), { name: 'ChunkLoadError' }), 'chunk'],
  ])('%#', (error, kind) => {
    expect(classifyError(error)).toBe(kind)
  })

  it('treats a network failure as offline only while the browser says it is offline', () => {
    const descriptor = Object.getOwnPropertyDescriptor(Navigator.prototype, 'onLine')
    Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => false })
    try {
      expect(classifyError(api(0))).toBe('offline')
      expect(classifyError(new Error('x'))).toBe('offline')
    } finally {
      delete (navigator as unknown as Record<string, unknown>).onLine
      if (descriptor) Object.defineProperty(Navigator.prototype, 'onLine', descriptor)
    }
    expect(classifyError(api(0))).toBe('server')
  })

  it('recognises a module that could not be fetched', () => {
    expect(isChunkLoadError(new Error('error loading dynamically imported module'))).toBe(true)
    expect(isChunkLoadError(new Error('Importing a module script failed.'))).toBe(true)
    expect(isChunkLoadError(new Error('Cannot read properties of undefined'))).toBe(false)
  })
})

describe('errorReference', () => {
  it('prefers the request id of the failed call, then the reference an answer carries', () => {
    expect(errorReference(api(500, null, { reference: 'ref-1' }, 'req-1'))).toBe('req-1')
    expect(errorReference(api(503, 'IDENTITY_PROVIDER_UNAVAILABLE', { reference: 'ref-1' }))).toBe('ref-1')
    expect(errorReference(api(500))).toBeNull()
    expect(errorReference(new Error('x'))).toBeNull()
  })
})
