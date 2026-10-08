import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { setToken } from './lib/api'
import { mockApi, page } from './test/fakeApi'
import { platformCaps, platformMe, renderApp, tenantCaps, tenantMe } from './test/render'

const nav = () => within(screen.getByRole('navigation', { name: /Menu/ }))

describe('routing and guards', () => {
  it('sends an anonymous visitor to sign-in and keeps protected pages closed', async () => {
    renderApp('/app/pengguna')
    expect(await screen.findByRole('heading', { name: 'Masuk' })).toBeInTheDocument()
  })

  it('does not let a tenant session open the platform portal', async () => {
    mockApi({
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps() },
    })
    setToken('t')
    renderApp('/platform/tenants')
    expect(await screen.findByRole('heading', { name: 'PT Maju Jaya' })).toBeInTheDocument()
  })
})

describe('sign-in', () => {
  it('lets a user with several organisations choose, then lands in that organisation', async () => {
    const calls = mockApi({
      'GET /auth/sso/status': { data: { identity_mode: 'standalone', sso_enabled: false, password_login: true } },
      'POST /auth/login': { data: { token: 'identity-token', expires_at: null, user: tenantMe.user, scope: 'identity', tenant_id: null, tenants: [{ id: 't-1', code: 'maju-jaya', name: 'PT Maju Jaya' }, { id: 't-2', code: 'sinar-abadi', name: 'CV Sinar Abadi' }], platform_access: false } },
      'POST /auth/switch-tenant': { data: { token: 'tenant-token' } },
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps() },
    })
    renderApp('/login')

    await userEvent.type(await screen.findByLabelText('E-mail'), 'multi@demo.test')
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'Demo#Passw0rd2026')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByRole('heading', { name: 'Pilih tujuan' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /CV Sinar Abadi/ }))

    expect(await screen.findByRole('heading', { name: 'PT Maju Jaya' })).toBeInTheDocument()
    expect(calls.find((c) => c.url === '/auth/switch-tenant')?.data).toEqual({ tenant_id: 't-2' })
  })

  it('shows the API refusal in Indonesian and does not keep the password', async () => {
    mockApi({
      'GET /auth/sso/status': { data: { identity_mode: 'standalone', sso_enabled: false, password_login: true } },
      'POST /auth/login': { status: 401, data: { message: 'Invalid', code: 'INVALID_CREDENTIALS' } },
    })
    renderApp('/login')

    await userEvent.type(await screen.findByLabelText('E-mail'), 'x@demo.test')
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'salah')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByText('E-mail atau kata sandi salah.')).toBeInTheDocument()
    expect(screen.getByLabelText('Kata sandi')).toHaveValue('')
  })
})

describe('capability driven UI (permissions, never role names)', () => {
  const platformRoutes = (permissions: string[]) => ({
    'GET /auth/me': { data: platformMe },
    'GET /platform/capabilities': { data: platformCaps(permissions) },
  })

  it('shows only the navigation the operator holds permissions for', async () => {
    mockApi({
      ...platformRoutes(['platform.tenant.view', 'platform.audit.view']),
      'GET /platform/tenants': { data: page([]) },
      'GET /platform/audit-logs': { data: page([]) },
    })
    setToken('p')
    renderApp('/platform')

    await screen.findByRole('heading', { name: 'Dashboard platform' })
    expect(nav().getByRole('link', { name: /Tenant/ })).toBeInTheDocument()
    expect(nav().getByRole('link', { name: /Audit/ })).toBeInTheDocument()
    expect(nav().queryByRole('link', { name: /Paket/ })).not.toBeInTheDocument()
    expect(nav().queryByRole('link', { name: /Operator/ })).not.toBeInTheDocument()
  })

  it('refuses a page opened by URL without its permission, without calling the API', async () => {
    const calls = mockApi(platformRoutes(['platform.tenant.view']))
    setToken('p')
    renderApp('/platform/bundles')

    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
    expect(calls.some((c) => c.url === '/platform/bundles')).toBe(false)
  })

  it('offers "create" only to those who may manage', async () => {
    const tenants = { 'GET /platform/tenants': { data: page([]) } }
    mockApi({ ...platformRoutes(['platform.tenant.view']), ...tenants })
    setToken('p')
    const first = renderApp('/platform/tenants')
    await screen.findByText('Tidak ada tenant')
    expect(screen.queryByRole('button', { name: 'Tenant baru' })).not.toBeInTheDocument()
    first.unmount()

    mockApi({ ...platformRoutes(['platform.tenant.view', 'platform.tenant.create']), ...tenants })
    setToken('p')
    renderApp('/platform/tenants')
    expect(await screen.findByRole('button', { name: 'Tenant baru' })).toBeInTheDocument()
  })

  it('hides tenant management buttons from a read-only viewer', async () => {
    mockApi({
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps({ permissions: ['organization.view'] }) },
      'GET /app/branches': { data: { data: [{ id: 'b-1', code: 'JKT', name: 'Kantor Pusat', status: 'ACTIVE' }] } },
    })
    setToken('t')
    renderApp('/app/organisasi')

    expect(await screen.findByText('Kantor Pusat')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cabang baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ubah' })).not.toBeInTheDocument()
  })

  it('warns that an overdue subscription is read-only', async () => {
    mockApi({
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps({ subscriptionMode: 'READ_ONLY' }) },
    })
    setToken('t')
    renderApp('/app')

    const banners = await screen.findAllByRole('alert')
    expect(banners.some((b) => /menunggak/i.test(b.textContent ?? ''))).toBe(true)
  })

  it('returns to sign-in when the server says the session is gone', async () => {
    mockApi({
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps({ permissions: ['organization.view'] }) },
      'GET /app/branches': { status: 401, data: { message: 'Unauthenticated.' } },
    })
    setToken('t')
    renderApp('/app/organisasi')

    expect(await screen.findByRole('heading', { name: 'Masuk' })).toBeInTheDocument()
  })
})

describe('tenant management', () => {
  it('creates a branch and reloads the list, surfacing the capacity refusal', async () => {
    let created = false
    const calls = mockApi({
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps({ permissions: ['organization.view', 'organization.manage'] }) },
      'GET /app/branches': () => ({ data: { data: created ? [{ id: 'b-1', code: 'JKT', name: 'Pusat', status: 'ACTIVE' }] : [] } }),
      'POST /app/branches': (r) => {
        if ((r.data as { code: string }).code === 'FULL') return { status: 409, data: { message: 'limit', code: 'CAPACITY_EXCEEDED', details: { limit_code: 'BRANCH_LIMIT', limit: 1, used: 1 } } }
        created = true
        return { status: 201, data: { id: 'b-1' } }
      },
    })
    setToken('t')
    renderApp('/app/organisasi')

    await userEvent.click(await screen.findByRole('button', { name: 'Cabang baru' }))
    await userEvent.type(screen.getByLabelText('Kode'), 'FULL')
    await userEvent.type(screen.getByLabelText('Nama'), 'Penuh')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan' }))
    expect(await screen.findByText(/Batas cabang tercapai \(1 dari 1\)/)).toBeInTheDocument()

    await userEvent.clear(screen.getByLabelText('Kode'))
    await userEvent.type(screen.getByLabelText('Kode'), 'JKT')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await screen.findByText('Pusat')).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST')).toHaveLength(2)
  })

  it('never offers actions on your own account', async () => {
    mockApi({
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps({ permissions: ['access.user.view', 'access.user.manage'] }) },
      'GET /app/users': { data: page([
        { id: 'm-1', status: 'ACTIVE', joined_at: null, user: { id: 'u-t', name: 'Budi Santoso', email: 'admin@majujaya.demo.test', status: 'ACTIVE' }, roles: [{ id: 'r-1', name: 'Tenant Administrator' }], data_scopes: [{ id: 's-1', scope_type: 'TENANT' }] },
        { id: 'm-2', status: 'ACTIVE', joined_at: null, user: { id: 'u-2', name: 'Sari Keuangan', email: 'keuangan@majujaya.demo.test', status: 'ACTIVE' }, roles: [], data_scopes: [] },
      ]) },
    })
    setToken('t')
    renderApp('/app/pengguna')

    const table = within(await screen.findByRole('table', { name: 'Daftar pengguna' }))
    const mine = table.getByText('Budi Santoso').closest('tr')!
    const theirs = table.getByText('Sari Keuangan').closest('tr')!
    expect(within(mine).queryByRole('button')).not.toBeInTheDocument()
    expect(within(theirs).getByRole('button', { name: 'Tangguhkan' })).toBeInTheDocument()
    expect(within(theirs).getByText('Tanpa peran')).toBeInTheDocument()
  })
})
