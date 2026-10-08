import { describe, expect, it, vi } from 'vitest'
import { ApiError, api, currentToken, setToken, setUnauthorizedHandler } from './api'
import { mockApi } from '../test/fakeApi'

describe('api client', () => {
  it('sends the bearer token and nothing about tenants', async () => {
    const calls = mockApi({ 'GET /auth/me': { data: {} } })
    setToken('abc')
    await api.get('/auth/me')
    expect(calls[0].headers.Authorization).toBe('Bearer abc')
    expect(Object.keys(calls[0].headers).map((h) => h.toLowerCase())).not.toContain('x-tenant-id')
  })

  it('turns a validation response into an ApiError with fields', async () => {
    mockApi({ 'POST /x': { status: 422, data: { message: 'Invalid', errors: { name: ['Wajib diisi.'] } } } })
    const error = await api.post('/x').catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).fields.name).toEqual(['Wajib diisi.'])
    expect((error as ApiError).status).toBe(422)
  })

  it('keeps the domain error code and details', async () => {
    mockApi({ 'POST /x': { status: 409, data: { message: 'full', code: 'CAPACITY_EXCEEDED', details: { limit: 3 } } } })
    const error = (await api.post('/x').catch((e: unknown) => e)) as ApiError
    expect(error.code).toBe('CAPACITY_EXCEEDED')
    expect(error.details.limit).toBe(3)
  })

  it('drops the session and tells the app when the token is rejected', async () => {
    const handler = vi.fn()
    setUnauthorizedHandler(handler)
    setToken('expired')
    mockApi({ 'GET /y': { status: 401, data: { message: 'Unauthenticated.' } } })
    await api.get('/y').catch(() => undefined)
    expect(currentToken()).toBeNull()
    expect(handler).toHaveBeenCalledOnce()
  })

  it('does not treat a failed sign-in as an expired session', async () => {
    const handler = vi.fn()
    setUnauthorizedHandler(handler)
    mockApi({ 'POST /auth/login': { status: 401, data: { message: 'bad', code: 'INVALID_CREDENTIALS' } } })
    await api.post('/auth/login').catch(() => undefined)
    expect(handler).not.toHaveBeenCalled()
  })
})
