import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { currentToken, setToken } from './lib/api'
import { navigation } from './lib/navigation'
import { mockApi, page } from './test/fakeApi'
import { platformCaps, platformMe, renderApp, tenantCaps, tenantMe } from './test/render'

const status = (over: Partial<{ identity_mode: string; sso_enabled: boolean; password_login: boolean }> = {}) => ({
  data: { identity_mode: 'optinexus', sso_enabled: true, password_login: true, ...over },
})

const exchanged = (logoutUrl: string | null = 'https://nexus.test/oidc/logout?client_id=oa') => ({
  data: { token: 'tenant-token', expires_at: null, token_type: 'Bearer', scope: 'tenant', tenant_id: 't-1', user: tenantMe.user, tenants: tenantMe.tenants, platform_access: false, sso: { logout_url: logoutUrl } },
})

afterEach(() => {
  vi.restoreAllMocks()
  try {
    sessionStorage.clear()
  } catch {
    /* ignore */
  }
})

describe('login page doors', () => {
  it('offers only the password form in a standalone installation', async () => {
    mockApi({ 'GET /auth/sso/status': status({ identity_mode: 'standalone', sso_enabled: false }) })
    renderApp('/login')

    expect(await screen.findByLabelText('E-mail')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /OptiNexus/ })).not.toBeInTheDocument()
    expect(screen.queryByText('Masuk sebagai operator platform')).not.toBeInTheDocument()
  })

  it('leads with OptiNexus and keeps the password form as a collapsed operator door', async () => {
    mockApi({ 'GET /auth/sso/status': status() })
    renderApp('/login')

    const link = await screen.findByRole('link', { name: 'Masuk dengan OptiNexus' })
    expect(link.getAttribute('href')).toMatch(/\/auth\/sso\/redirect$/)
    const door = screen.getByText('Masuk sebagai operator platform').closest('details')!
    expect(door).not.toHaveAttribute('open')
    expect(within(door).getByLabelText('Kata sandi')).toBeInTheDocument()
  })

  it('drops the password door when the installation does not allow it', async () => {
    mockApi({ 'GET /auth/sso/status': status({ password_login: false }) })
    renderApp('/login')

    await screen.findByRole('link', { name: 'Masuk dengan OptiNexus' })
    expect(screen.queryByLabelText('Kata sandi')).not.toBeInTheDocument()
    expect(screen.queryByText('Masuk sebagai operator platform')).not.toBeInTheDocument()
  })

  it('says so when no door is open, instead of showing a form that can only fail', async () => {
    mockApi({ 'GET /auth/sso/status': status({ sso_enabled: false, password_login: false }) })
    renderApp('/login')

    expect(await screen.findByText(/belum dapat digunakan/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Kata sandi')).not.toBeInTheDocument()
  })

  it('warns operators when OptiNexus sign-in is not switched on yet', async () => {
    mockApi({ 'GET /auth/sso/status': status({ sso_enabled: false }) })
    renderApp('/login')

    expect(await screen.findByText(/belum diaktifkan/)).toBeInTheDocument()
    expect(screen.getByLabelText('Kata sandi')).toBeInTheDocument()
  })

  it('falls back to the password form when the status cannot be read', async () => {
    mockApi({ 'GET /auth/sso/status': { status: 500, data: { message: 'boom' } } })
    renderApp('/login')

    expect(await screen.findByLabelText('Kata sandi')).toBeInTheDocument()
  })

  it('explains in Indonesian why OptiNexus turned the person away', async () => {
    mockApi({ 'GET /auth/sso/status': status() })
    renderApp('/login?sso_error=no_permissions')

    expect(await screen.findByText(/belum diberi izin apa pun untuk OptiEntry/)).toBeInTheDocument()
  })

  it('never shows an unknown error code verbatim', async () => {
    mockApi({ 'GET /auth/sso/status': status() })
    renderApp('/login?sso_error=<script>alert(1)</script>')

    expect(await screen.findByText('Masuk melalui OptiNexus gagal. Silakan coba lagi.')).toBeInTheDocument()
    expect(document.body.innerHTML).not.toContain('<script>')
  })
})

describe('OptiNexus callback', () => {
  it('trades the one-time ticket for a session once and lands in the organisation', async () => {
    const calls = mockApi({
      'POST /auth/sso/exchange': exchanged(),
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps({ mode: 'optinexus' }) },
    })
    renderApp('/sso/callback?ticket=one-time-ticket')

    expect(await screen.findByRole('heading', { name: 'PT Maju Jaya' })).toBeInTheDocument()
    const exchanges = calls.filter((c) => c.url === '/auth/sso/exchange')
    expect(exchanges).toHaveLength(1)
    expect(exchanges[0].data).toEqual({ ticket: 'one-time-ticket' })
    expect(currentToken()).toBe('tenant-token')
  })

  it('shows the refusal and a way back when the ticket is spent', async () => {
    mockApi({ 'POST /auth/sso/exchange': { status: 422, data: { message: 'invalid', code: 'INVALID_TICKET' } } })
    renderApp('/sso/callback?ticket=spent')

    expect(await screen.findByText(/tidak valid atau sudah kedaluwarsa/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Kembali ke halaman masuk' })).toHaveAttribute('href', '/login')
    expect(currentToken()).toBeNull()
  })

  it('sends a visitor without a ticket back to sign-in without calling the API', async () => {
    const calls = mockApi({ 'GET /auth/sso/status': status() })
    renderApp('/sso/callback')

    expect(await screen.findByRole('heading', { name: 'Masuk' })).toBeInTheDocument()
    expect(calls.some((c) => c.url === '/auth/sso/exchange')).toBe(false)
  })

  it('ends the OptiNexus session too when signing out of an OptiNexus sign-in', async () => {
    const go = vi.spyOn(navigation, 'go').mockImplementation(() => {})
    const calls = mockApi({
      'POST /auth/sso/exchange': exchanged(),
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps({ mode: 'optinexus' }) },
      'POST /auth/logout': { data: {} },
    })
    renderApp('/sso/callback?ticket=t1')

    await screen.findByRole('heading', { name: 'PT Maju Jaya' })
    await userEvent.click(screen.getByRole('button', { name: /Keluar/ }))

    await waitFor(() => expect(go).toHaveBeenCalledWith('https://nexus.test/oidc/logout?client_id=oa'))
    expect(calls.some((c) => c.url === '/auth/logout')).toBe(true)
  })

  it('never follows a logout address that is not http(s)', async () => {
    const go = vi.spyOn(navigation, 'go').mockImplementation(() => {})
    mockApi({
      'POST /auth/sso/exchange': exchanged('javascript:alert(1)'),
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps({ mode: 'optinexus' }) },
      'POST /auth/logout': { data: {} },
      'GET /auth/sso/status': status(),
    })
    renderApp('/sso/callback?ticket=t2')

    await screen.findByRole('heading', { name: 'PT Maju Jaya' })
    await userEvent.click(screen.getByRole('button', { name: /Keluar/ }))

    expect(await screen.findByRole('heading', { name: 'Masuk' })).toBeInTheDocument()
    expect(go).not.toHaveBeenCalled()
  })
})

describe('what OptiNexus manages is not offered for editing', () => {
  const members = page([
    { id: 'm-2', status: 'ACTIVE', joined_at: null, user: { id: 'u-2', name: 'Sari Keuangan', email: 'keuangan@majujaya.demo.test', status: 'ACTIVE' }, roles: [], data_scopes: [] },
  ])
  const tenantRoutes = (mode: 'standalone' | 'optinexus') => ({
    'GET /auth/me': { data: tenantMe },
    'GET /app/capabilities': { data: tenantCaps({ mode, permissions: ['access.user.view', 'access.user.manage', 'access.scope.manage', 'access.role.view', 'access.role.manage'] }) },
    'GET /app/users': { data: members },
    'GET /app/roles': { data: { data: [{ id: 'r-9', name: 'Kasir Cabang', is_system: false, permissions: [] }] } },
    'GET /app/permissions': { data: { data: [] } },
  })

  it('keeps member and role actions in a standalone installation', async () => {
    mockApi(tenantRoutes('standalone'))
    setToken('t')
    renderApp('/app/pengguna')

    const row = within((await screen.findByRole('table', { name: 'Daftar pengguna' }))).getByText('Sari Keuangan').closest('tr')!
    expect(screen.getByRole('button', { name: 'Pengguna baru' })).toBeInTheDocument()
    expect(within(row).getByRole('button', { name: 'Peran' })).toBeInTheDocument()
    expect(within(row).getByRole('button', { name: 'Tangguhkan' })).toBeInTheDocument()
    expect(screen.queryByText(/dikelola di OptiNexus/)).not.toBeInTheDocument()
  })

  it('shows members read-only but leaves data scope editable in OptiNexus mode', async () => {
    mockApi(tenantRoutes('optinexus'))
    setToken('t')
    renderApp('/app/pengguna')

    const row = within((await screen.findByRole('table', { name: 'Daftar pengguna' }))).getByText('Sari Keuangan').closest('tr')!
    expect(screen.getByText(/dikelola di OptiNexus/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Pengguna baru' })).not.toBeInTheDocument()
    expect(within(row).queryByRole('button', { name: 'Peran' })).not.toBeInTheDocument()
    expect(within(row).queryByRole('button', { name: 'Tangguhkan' })).not.toBeInTheDocument()
    expect(within(row).getByRole('button', { name: 'Cakupan' })).toBeInTheDocument()
  })

  it('shows roles read-only in OptiNexus mode', async () => {
    mockApi(tenantRoutes('optinexus'))
    setToken('t')
    renderApp('/app/peran')

    expect(await screen.findByText('Kasir Cabang')).toBeInTheDocument()
    expect(screen.getByText(/Izin pengguna ditentukan di OptiNexus/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Peran baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Hapus' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Lihat' })).toBeInTheDocument()
  })

  it('does not offer tenant creation to a platform operator in OptiNexus mode', async () => {
    mockApi({
      'GET /auth/me': { data: platformMe },
      'GET /platform/capabilities': { data: platformCaps(['platform.tenant.view', 'platform.tenant.create'], 'optinexus') },
      'GET /platform/tenants': { data: page([]) },
    })
    setToken('p')
    renderApp('/platform/tenants')

    await screen.findByText('Tidak ada tenant')
    expect(screen.getByText(/dihubungkan dari OptiNexus/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tenant baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Buat tenant pertama' })).not.toBeInTheDocument()
  })

  it('shows the subscription as synchronised, with no way to edit it, in OptiNexus mode', async () => {
    mockApi({
      'GET /auth/me': { data: platformMe },
      'GET /platform/capabilities': { data: platformCaps(['platform.tenant.view', 'platform.subscription.view', 'platform.subscription.manage'], 'optinexus') },
      'GET /platform/tenants/t-1': { data: { id: 't-1', code: 'maju-jaya', name: 'PT Maju Jaya', legal_name: null, status: 'ACTIVE', timezone: 'Asia/Jakarta', default_locale: 'id', default_currency: 'IDR', contact_name: null, contact_email: null, contact_phone: null, created_at: '2026-01-01T00:00:00Z' } },
      'GET /platform/tenants/t-1/subscriptions': { data: { data: [] } },
    })
    setToken('p')
    renderApp('/platform/tenants/t-1')

    await userEvent.click(await screen.findByRole('tab', { name: 'Langganan' }))
    expect(await screen.findByText(/disinkronkan dari OptiNexus/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Langganan baru' })).not.toBeInTheDocument()
  })
})
