import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from './i18n'
import { setToken } from './lib/api'
import { navigation } from './lib/navigation'
import { mockApi } from './test/fakeApi'
import { renderApp, tenantCaps, tenantMe } from './test/render'

const HINT = '7b1c5d0e-2f55-4f3e-9a58-0d3d2a6c1111'

const products = [
  { code: 'optiaccounting', name: 'OptiEntry', current: true, launch_url: 'https://entry.test/', switch_url: null },
  { code: 'optifleet', name: 'OptiFleet', current: false, launch_url: 'https://fleet.test/app', switch_url: `https://fleet.test/app?tenant_hint=${HINT}` },
  { code: 'optiradar', name: 'OptiRadar', current: false, launch_url: null, switch_url: null },
]
const allowed = (list = products) => ({ available: true, allowed: true, session_ended: false, products: list, current: 'optiaccounting', ttl_seconds: 300, expires_at: '2026-10-17T10:05:00Z', source: 'nexus' })
const denied = (reason: string) => ({ available: true, allowed: false, session_ended: true, deny_reason: reason, products: [] })

const shell = (context: unknown) => ({
  'GET /auth/me': { data: tenantMe },
  'GET /app/capabilities': { data: tenantCaps({ mode: 'optinexus' }) },
  'GET /app/product-context': context,
  'GET /auth/sso/status': { data: { identity_mode: 'optinexus', sso_enabled: true, password_login: true } },
}) as Parameters<typeof mockApi>[0]

afterEach(() => {
  vi.restoreAllMocks()
  try {
    sessionStorage.clear()
  } catch {
    /* ignore */
  }
})

describe('product switcher', () => {
  it('offers exactly the products OptiNexus returned, marks the current one and links with the tenant hint', async () => {
    const calls = mockApi(shell({ data: allowed() }))
    setToken('t')
    renderApp('/app')

    await userEvent.click(await screen.findByRole('button', { name: 'Pindah ke produk lain' }))
    const menu = await screen.findByRole('menu')
    expect(within(menu).getAllByRole('menuitem').map((i) => i.textContent)).toEqual(['OptiEntrySedang dibuka', 'OptiFleet', 'OptiRadarAlamat produk belum diatur di OptiNexus'])
    expect(within(menu).getByRole('menuitem', { name: 'OptiFleet' })).toHaveAttribute('href', `https://fleet.test/app?tenant_hint=${HINT}`)
    expect(within(menu).queryByText(/portal|governance|cgo/i)).not.toBeInTheDocument()
    expect(within(menu).getByText(/OptiEntry/).closest('[role=menuitem]')).toHaveAttribute('aria-current', 'true')
    // Asked on load and again when the menu opened (the API serves its short cache in between).
    await waitFor(() => expect(calls.filter((c) => c.url === '/app/product-context').length).toBeGreaterThanOrEqual(2))
    expect(calls.filter((c) => c.url === '/app/product-context').every((c) => !c.params && c.data === undefined)).toBe(true)
  })

  it.each([
    ['standalone or not linked', { data: { available: false, reason: 'NOT_LINKED', session_ended: false } }],
    ['only the current product', { data: allowed([products[0]]) }],
  ])('shows nothing for %s', async (_name, reply) => {
    mockApi(shell(reply))
    setToken('t')
    renderApp('/app')

    await screen.findByRole('heading', { name: 'PT Maju Jaya' })
    expect(screen.queryByRole('button', { name: 'Pindah ke produk lain' })).not.toBeInTheDocument()
  })

  it('revalidates before leaving and then goes to the target with the hint', async () => {
    const go = vi.spyOn(navigation, 'go').mockImplementation(() => undefined)
    const calls = mockApi(shell({ data: allowed() }))
    setToken('t')
    renderApp('/app')

    await userEvent.click(await screen.findByRole('button', { name: 'Pindah ke produk lain' }))
    const before = calls.filter((c) => c.url === '/app/product-context').length
    await userEvent.click(await screen.findByRole('menuitem', { name: 'OptiFleet' }))

    await waitFor(() => expect(go).toHaveBeenCalledWith(`https://fleet.test/app?tenant_hint=${HINT}`))
    expect(calls.filter((c) => c.url === '/app/product-context').length).toBeGreaterThan(before)
  })

  it('does not leave when the product is no longer listed', async () => {
    const go = vi.spyOn(navigation, 'go').mockImplementation(() => undefined)
    let n = 0
    mockApi(shell(() => ({ data: allowed(++n <= 2 ? products : [products[0]]) })))
    setToken('t')
    renderApp('/app')

    await userEvent.click(await screen.findByRole('button', { name: 'Pindah ke produk lain' }))
    await userEvent.click(await screen.findByRole('menuitem', { name: 'OptiFleet' }))

    expect(await screen.findByText('Produk ini tidak lagi tersedia untuk Anda.')).toBeInTheDocument()
    expect(go).not.toHaveBeenCalled()
  })

  it('does not leave on a guess when OptiNexus does not answer, and says so', async () => {
    const go = vi.spyOn(navigation, 'go').mockImplementation(() => undefined)
    let n = 0
    mockApi(shell(() => (++n <= 2 ? { data: allowed() } : { status: 503, data: { message: 'x', code: 'IDENTITY_PROVIDER_UNAVAILABLE' } })))
    setToken('t')
    renderApp('/app')

    await userEvent.click(await screen.findByRole('button', { name: 'Pindah ke produk lain' }))
    await userEvent.click(await screen.findByRole('menuitem', { name: 'OptiFleet' }))

    expect(await screen.findByText(/Tidak dapat berpindah produk sekarang/)).toBeInTheDocument()
    expect(go).not.toHaveBeenCalled()
  })

  it('shows no stale list when OptiNexus is not answering', async () => {
    mockApi(shell({ status: 503, data: { message: 'x', code: 'IDENTITY_PROVIDER_UNAVAILABLE' } }))
    setToken('t')
    renderApp('/app')

    await userEvent.click(await screen.findByRole('button', { name: 'Pindah ke produk lain' }))
    expect(await screen.findByText(/Daftar produk tidak dapat dimuat sekarang/)).toBeInTheDocument()
    expect(screen.queryByRole('menuitem')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })

  it('reads in English', async () => {
    setLocale('en')
    mockApi(shell({ data: allowed() }))
    setToken('t')
    renderApp('/app')

    await userEvent.click(await screen.findByRole('button', { name: 'Switch to another product' }))
    expect(await screen.findByText('Open now')).toBeInTheDocument()
    expect(screen.getByText('No address set for this product in OptiNexus')).toBeInTheDocument()
  })
})

describe('session that OptiNexus no longer allows', () => {
  it.each([
    ['application_access_revoked', /Akses Anda ke OptiEntry dicabut/],
    ['user_suspended', /Akun Anda sedang ditangguhkan/],
    ['tenant_membership_removed', /tidak lagi menjadi anggota/],
    ['application_not_assigned', /tidak lagi berlangganan OptiEntry/],
    ['something_new', /tidak lagi mengizinkan sesi ini/],
  ])('ends the session and says why (%s)', async (reason, text) => {
    mockApi(shell({ data: denied(reason) }))
    setToken('t')
    renderApp('/app')

    expect(await screen.findByRole('heading', { name: 'Masuk' })).toBeInTheDocument()
    expect(screen.getByText('Sesi Anda diakhiri')).toBeInTheDocument()
    expect(screen.getByText(text)).toBeInTheDocument()
    expect(sessionStorage.getItem('oa.token')).toBeNull()
  })

  it('says the session expired when the token is rejected', async () => {
    mockApi({ ...shell({ data: allowed() }), 'GET /app/capabilities': { status: 401, data: { message: 'Unauthenticated.' } } })
    setToken('t')
    renderApp('/app')

    expect(await screen.findByRole('heading', { name: 'Masuk' })).toBeInTheDocument()
    expect(screen.getByText(/Sesi Anda sudah berakhir/)).toBeInTheDocument()
  })
})

describe('arriving from another product', () => {
  const anonymous = (): Parameters<typeof mockApi>[0] => ({ 'GET /auth/sso/status': { data: { identity_mode: 'optinexus', sso_enabled: true, password_login: true } } })

  it('starts sign-in with the hint, once, and keeps it on the button', async () => {
    const go = vi.spyOn(navigation, 'go').mockImplementation(() => undefined)
    mockApi(anonymous())
    renderApp(`/?tenant_hint=${HINT}&utm=junk`)

    await waitFor(() => expect(go).toHaveBeenCalledTimes(1))
    expect(go.mock.calls[0][0]).toMatch(new RegExp(`/auth/sso/redirect\\?tenant_hint=${HINT}$`))
    expect(screen.getByRole('link', { name: 'Masuk dengan OptiNexus' }).getAttribute('href')).toContain(`tenant_hint=${HINT}`)
  })

  it('accepts a tenant code too', async () => {
    const go = vi.spyOn(navigation, 'go').mockImplementation(() => undefined)
    mockApi(anonymous())
    renderApp('/login?tenant_hint=ACME-ID')

    await waitFor(() => expect(go).toHaveBeenCalledWith(expect.stringMatching(/tenant_hint=ACME-ID$/)))
  })

  it.each([
    ['markup', '<script>alert(1)</script>'],
    ['a url', 'https://evil.test/'],
    ['too long', 'a'.repeat(65)],
  ])('ignores a hint that is %s', async (_name, hint) => {
    const go = vi.spyOn(navigation, 'go').mockImplementation(() => undefined)
    mockApi(anonymous())
    renderApp(`/login?tenant_hint=${encodeURIComponent(hint)}`)

    const link = await screen.findByRole('link', { name: 'Masuk dengan OptiNexus' })
    expect(link.getAttribute('href')).not.toContain('tenant_hint')
    expect(go).not.toHaveBeenCalled()
  })

  it('does not loop when OptiNexus turned the sign-in away', async () => {
    const go = vi.spyOn(navigation, 'go').mockImplementation(() => undefined)
    mockApi(anonymous())
    renderApp(`/login?tenant_hint=${HINT}&sso_error=access_denied`)

    expect(await screen.findByText(/Masuk dibatalkan atau ditolak/)).toBeInTheDocument()
    expect(go).not.toHaveBeenCalled()
  })
})
