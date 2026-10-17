import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { setLocale } from '../../../i18n'
import { setToken } from '../../../lib/api'
import { mockApi } from '../../../test/fakeApi'
import { renderApp, tenantCaps, tenantMe } from '../../../test/render'

const ADMIN = '11111111-1111-4111-8111-111111111111'
const SARI = '22222222-2222-4222-8222-222222222222'
const NEW = '33333333-3333-4333-8333-333333333333'

const role = (id: string, name: string, over: Record<string, unknown> = {}) => ({ id, code: name.toLowerCase(), name, description: null, template: false, editable: true, permissions: [], permission_count: 0, ...over })
const accountant = role('r-acc', 'Accountant', { template: true, editable: false, description: 'Day-to-day bookkeeping', permissions: ['accounting.journal.view'], permission_count: 1 })
const cashier = role('r-cash', 'Kasir Cabang', { permissions: ['accounting.journal.view'], permission_count: 1 })

const user = (id: string, name: string, over: Record<string, unknown> = {}) => ({
  id, name, email: `${name.split(' ')[0].toLowerCase()}@majujaya.test`, status: 'ACTIVE', membership_status: 'ACTIVE', is_tenant_admin: false, application_access: 'ACTIVE',
  roles: [], local: { member_id: `m-${id.slice(0, 2)}`, status: 'ACTIVE', last_login_at: null, data_scopes: [{ id: 's-1', scope_type: 'OWN', branch_id: null, business_unit_id: null }] }, is_self: false, ...over,
})

const list = (data: unknown[], seats = { limit: 5, used: 2, remaining: 3 }) => ({ data, meta: { pagination: { current_page: 1, per_page: 25, total: data.length, last_page: 1 }, seats } })

const PERMS = ['access.user.view', 'access.user.manage', 'access.scope.manage', 'access.role.view', 'access.role.manage']
const base = (permissions: string[] = PERMS) => ({
  'GET /auth/me': { data: tenantMe },
  'GET /app/capabilities': { data: tenantCaps({ mode: 'optinexus', permissions }) },
  'GET /app/delegation/status': { data: { available: true, reason: null, mode: 'optinexus' } },
})

const users = [user(ADMIN, 'Budi Admin', { is_tenant_admin: true, is_self: true, roles: [role('r-own', 'Owner', { template: true, editable: false })] }), user(SARI, 'Sari Keuangan', { roles: [accountant] })]

function usersRoutes(extra: Record<string, unknown> = {}) {
  return {
    ...base(),
    'GET /app/delegation/users': { data: list(users) },
    'GET /app/delegation/assignable-roles': { data: { data: [accountant, cashier].map(({ id, code, name, description, template }) => ({ id, code, name, description, template })) } },
    'GET /app/branches': { data: { data: [] } },
    'GET /app/business-units': { data: { data: [] } },
    ...extra,
  } as Parameters<typeof mockApi>[0]
}

describe('Settings > Users through OptiNexus delegation', () => {
  it('lists the answer of OptiNexus with roles, seats and the local data scope, and protects the admin and the signed-in person', async () => {
    mockApi(usersRoutes())
    setToken('t')
    renderApp('/app/pengguna')

    const table = await screen.findByRole('table', { name: 'Daftar pengguna' })
    expect(screen.getByText(/dikelola di OptiNexus/)).toBeInTheDocument()
    expect(screen.getByText('Kursi pengguna: 2 dari 5 terpakai')).toBeInTheDocument()

    const sari = within(table).getByText('Sari Keuangan').closest('tr')!
    expect(within(sari).getByText('Accountant')).toBeInTheDocument()
    expect(within(sari).getByText('Milik sendiri')).toBeInTheDocument()
    for (const name of ['Peran', 'Cakupan', 'Cabut akses']) expect(within(sari).getByRole('button', { name })).toBeInTheDocument()

    const admin = within(table).getByText('Budi Admin').closest('tr')!
    expect(within(admin).getByText('Admin Tenant')).toBeInTheDocument()
    expect(within(admin).queryByRole('button', { name: 'Cabut akses' })).not.toBeInTheDocument()
    expect(within(admin).queryByRole('button', { name: 'Peran' })).not.toBeInTheDocument()
  })

  it('creates a person in OptiNexus: sends what was typed, never a tenant, and says which outcome happened', async () => {
    const calls = mockApi(usersRoutes({ 'POST /app/delegation/users': { status: 201, data: { ...user(NEW, 'Rina Baru', { local: null }), outcome: 'created', projection: 'CREATED' } } }))
    setToken('t')
    renderApp('/app/pengguna')

    await userEvent.click(await screen.findByRole('button', { name: 'Tambah pengguna' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Nama'), 'Rina Baru')
    await userEvent.type(within(dialog).getByLabelText('E-mail'), 'rina@majujaya.test')
    await userEvent.type(within(dialog).getByLabelText(/Kata sandi awal/), 'Str0ng!Passw0rd#1')
    await userEvent.click(await within(dialog).findByLabelText(/Accountant/))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tambah' }))

    expect(await screen.findByText('Pengguna dibuat di OptiNexus dan diberi akses.')).toBeInTheDocument()
    const post = calls.find((c) => c.method === 'POST')!
    expect(post.url).toBe('/app/delegation/users')
    expect(post.data).toMatchObject({ name: 'Rina Baru', email: 'rina@majujaya.test', initial_password: 'Str0ng!Passw0rd#1', role_ids: ['r-acc'], data_scopes: [{ scope_type: 'OWN' }] })
    expect(JSON.stringify(post.data)).not.toMatch(/tenant|acting_user/)
  })

  it.each([
    ['linked', 'CREATED', 'Akun OptiNexus yang sudah ada dihubungkan ke organisasi ini.'],
    ['existing', 'EXISTING', 'Pengguna ini sudah punya akses. Tidak ada yang berubah.'],
    ['linked', 'DEFERRED', 'Pengguna ditambahkan di OptiNexus. E-mail ini dipakai akun lokal, jadi penghubungan terjadi saat pengguna pertama kali masuk lewat OptiNexus.'],
  ])('reports the outcome %s / %s in words', async (outcome, projection, text) => {
    mockApi(usersRoutes({ 'POST /app/delegation/users': { status: outcome === 'existing' ? 200 : 201, data: { ...user(NEW, 'Rina Baru'), outcome, projection } } }))
    setToken('t')
    renderApp('/app/pengguna')

    await userEvent.click(await screen.findByRole('button', { name: 'Tambah pengguna' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Nama'), 'Rina Baru')
    await userEvent.type(within(dialog).getByLabelText('E-mail'), 'rina@majujaya.test')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tambah' }))

    expect(await screen.findByText(text)).toBeInTheDocument()
  })

  it('shows the refusal inside the dialog, with the reference to quote, and keeps what was typed', async () => {
    mockApi(usersRoutes({
      'POST /app/delegation/users': { status: 409, headers: { 'x-request-id': 'req-77' }, data: { message: 'full', code: 'CAPACITY_EXCEEDED', details: { limit_code: 'USER_LIMIT', limit: 5, used: 5 } } },
    }))
    setToken('t')
    renderApp('/app/pengguna')

    await userEvent.click(await screen.findByRole('button', { name: 'Tambah pengguna' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Nama'), 'Rina Baru')
    await userEvent.type(within(dialog).getByLabelText('E-mail'), 'rina@majujaya.test')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tambah' }))

    expect(await within(dialog).findByText(/Batas pengguna tercapai \(5 dari 5\)/)).toBeInTheDocument()
    expect(within(dialog).getByText('Kode referensi: req-77')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Nama')).toHaveValue('Rina Baru')
  })

  it('changes roles and removes access through OptiNexus', async () => {
    const calls = mockApi(usersRoutes({
      'PUT /app/delegation/users/22222222-2222-4222-8222-222222222222/roles': { data: user(SARI, 'Sari Keuangan', { roles: [accountant, cashier] }) },
      'DELETE /app/delegation/users/22222222-2222-4222-8222-222222222222': { data: { message: 'Access removed.' } },
    }))
    setToken('t')
    renderApp('/app/pengguna')

    const row = () => within(screen.getByRole('table', { name: 'Daftar pengguna' })).getByText('Sari Keuangan').closest('tr')!
    await screen.findByRole('table', { name: 'Daftar pengguna' })
    await userEvent.click(within(row()).getByRole('button', { name: 'Peran' }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByLabelText(/Accountant/)).toBeChecked()
    await userEvent.click(within(dialog).getByLabelText('Kasir Cabang'))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan' }))
    expect(await screen.findByText('Peran pengguna diperbarui.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'PUT')!.data).toEqual({ role_ids: ['r-acc', 'r-cash'] })

    await userEvent.click(within(row()).getByRole('button', { name: 'Cabut akses' }))
    const confirm = await screen.findByRole('dialog')
    expect(within(confirm).getByText(/Pengguna langsung keluar dari OptiEntry/)).toBeInTheDocument()
    await userEvent.click(within(confirm).getByRole('button', { name: 'Cabut akses' }))
    expect(await screen.findByText('Akses pengguna ke OptiEntry dicabut.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'DELETE')).toBe(true)
  })

  it('does not offer changes to someone who may only view users', async () => {
    mockApi(usersRoutes({ ...base(['access.user.view']), 'GET /app/delegation/users': { data: list(users) } }))
    setToken('t')
    renderApp('/app/pengguna')

    await screen.findByRole('table', { name: 'Daftar pengguna' })
    expect(screen.queryByRole('button', { name: 'Tambah pengguna' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cabut akses' })).not.toBeInTheDocument()
  })

  it('warns when every seat is used', async () => {
    mockApi(usersRoutes({ 'GET /app/delegation/users': { data: list(users, { limit: 2, used: 2, remaining: 0 }) } }))
    setToken('t')
    renderApp('/app/pengguna')

    expect(await screen.findByText(/Semua kursi terpakai/)).toBeInTheDocument()
  })

  it('shows an empty state', async () => {
    mockApi(usersRoutes({ 'GET /app/delegation/users': { data: list([]) } }))
    setToken('t')
    renderApp('/app/pengguna')

    expect(await screen.findByText('Belum ada pengguna')).toBeInTheDocument()
  })

  it.each([
    ['forbidden', 403, 'ACTOR_NOT_TENANT_ADMIN', /Hanya Admin Tenant di OptiNexus/, 'Anda tidak memiliki akses'],
    ['provider-unavailable', 503, 'IDENTITY_PROVIDER_UNAVAILABLE', /OptiNexus sedang tidak dapat dihubungi/, 'OptiNexus belum dapat dijangkau'],
    ['not set up', 503, 'DELEGATION_NOT_CONFIGURED', /belum disiapkan untuk instalasi ini/, 'Pengelolaan lewat OptiNexus belum siap'],
    ['unexpected', 502, 'DELEGATION_UNEXPECTED_RESPONSE', /jawaban yang tidak dikenali/, 'Terjadi gangguan di server'],
  ])('shows the %s state with the reference and a way to retry', async (_name, status, code, sentence, title) => {
    mockApi(usersRoutes({ 'GET /app/delegation/users': { status, headers: { 'x-request-id': 'req-42' }, data: { message: 'x', code, details: { reference: 'req-42' } } } }))
    setToken('t')
    renderApp('/app/pengguna')

    expect(await screen.findByRole('heading', { name: title })).toBeInTheDocument()
    expect(screen.getByText(sentence)).toBeInTheDocument()
    expect(screen.getByText('Kode referensi: req-42')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })

  it('never shows a stack trace for a server fault', async () => {
    mockApi(usersRoutes({ 'GET /app/delegation/users': { status: 500, headers: { 'x-request-id': 'req-9' }, data: { message: 'SQLSTATE[08006] connection to server at "db" failed', exception: 'PDOException', trace: [{ file: '/app/x.php' }] } } }))
    setToken('t')
    renderApp('/app/pengguna')

    expect(await screen.findByRole('heading', { name: 'Terjadi gangguan di server' })).toBeInTheDocument()
    expect(screen.queryByText(/SQLSTATE|PDOException|x\.php/)).not.toBeInTheDocument()
    expect(screen.getByText('Kode referensi: req-9')).toBeInTheDocument()
  })

  it('reads in English when the language is switched', async () => {
    mockApi(usersRoutes())
    setToken('t')
    renderApp('/app/pengguna')

    await screen.findByRole('table', { name: 'Daftar pengguna' })
    await userEvent.selectOptions(screen.getByLabelText('Bahasa'), 'en')

    expect(await screen.findByRole('heading', { name: 'Users' })).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Users' })).toBeInTheDocument()
    expect(screen.getByText('User seats: 2 of 5 used')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add user' })).toBeInTheDocument()
    expect(document.documentElement.lang).toBe('en')
  })
})

describe('Settings > Roles through OptiNexus delegation', () => {
  const permissions = [
    { code: 'accounting.journal.view', group: 'Journals', description: 'View journals', state: 'AVAILABLE', capability: 'ACCOUNTING_CORE' },
    { code: 'accounting.journal.create', group: 'Journals', description: 'Create journals', state: 'AVAILABLE', capability: 'ACCOUNTING_CORE' },
    { code: 'accounting.period.close', group: 'Closing', description: 'Close a period', state: 'NOT_DELEGABLE', capability: null },
    { code: 'budget.view', group: 'Budget', description: 'View budgets', state: 'NOT_ENTITLED', capability: null },
  ]
  const routes = (extra: Record<string, unknown> = {}) => ({ ...base(), 'GET /app/delegation/roles': { data: { permissions, roles: [accountant, cashier] } }, ...extra }) as Parameters<typeof mockApi>[0]

  it('lists templates and custom roles; a template cannot be edited or deleted', async () => {
    mockApi(routes())
    setToken('t')
    renderApp('/app/peran')

    const table = await screen.findByRole('table', { name: 'Daftar peran' })
    const template = within(table).getByText('Accountant').closest('tr')!
    expect(within(template).getByText('Templat bawaan')).toBeInTheDocument()
    expect(within(template).getByRole('button', { name: 'Lihat' })).toBeInTheDocument()
    expect(within(template).queryByRole('button', { name: 'Hapus' })).not.toBeInTheDocument()
    const custom = within(table).getByText('Kasir Cabang').closest('tr')!
    expect(within(custom).getByRole('button', { name: 'Ubah' })).toBeInTheDocument()
    expect(within(custom).getByRole('button', { name: 'Hapus' })).toBeInTheDocument()
  })

  it('offers only delegable permissions and shows the others as unavailable', async () => {
    mockApi(routes())
    setToken('t')
    renderApp('/app/peran')

    await userEvent.click(await screen.findByRole('button', { name: 'Peran baru' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText(/accounting\.journal\.view/)).toBeEnabled()
    const closing = within(dialog).getByLabelText(/accounting\.period\.close/)
    expect(closing).toBeDisabled()
    expect(within(dialog).getByText('Tidak dapat didelegasikan')).toBeInTheDocument()
    expect(within(dialog).getByLabelText(/budget\.view/)).toBeDisabled()
    expect(within(dialog).getByText('Tidak termasuk langganan')).toBeInTheDocument()
    expect(within(dialog).getByText('Penutupan')).toBeInTheDocument()
  })

  it('creates a custom role inside the delegable set', async () => {
    const calls = mockApi(routes({ 'POST /app/delegation/roles': { status: 201, data: role('r-new', 'Staf Jurnal') } }))
    setToken('t')
    renderApp('/app/peran')

    await userEvent.click(await screen.findByRole('button', { name: 'Peran baru' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Nama peran'), 'Staf Jurnal')
    await userEvent.click(within(dialog).getByLabelText(/accounting\.journal\.create/))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan' }))

    expect(await screen.findByText('Peran disimpan.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ name: 'Staf Jurnal', description: null, permissions: ['accounting.journal.create'] })
  })

  it('shows the refusal of OptiNexus for a grant outside the delegable set', async () => {
    mockApi(routes({ 'POST /app/delegation/roles': { status: 422, headers: { 'x-request-id': 'req-5' }, data: { message: 'x', code: 'PERMISSION_NOT_DELEGABLE', details: { permissions: ['accounting.journal.create'], reference: 'req-5' } } } }))
    setToken('t')
    renderApp('/app/peran')

    await userEvent.click(await screen.findByRole('button', { name: 'Peran baru' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Nama peran'), 'Staf Jurnal')
    await userEvent.click(within(dialog).getByLabelText(/accounting\.journal\.create/))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan' }))

    expect(await within(dialog).findByText(/tidak dapat didelegasikan: accounting\.journal\.create/)).toBeInTheDocument()
    expect(within(dialog).getByText('Kode referensi: req-5')).toBeInTheDocument()
  })

  it('edits and deletes a custom role, and explains a role that is still in use', async () => {
    const calls = mockApi(routes({
      'PUT /app/delegation/roles/r-cash': { data: cashier },
      'DELETE /app/delegation/roles/r-cash': { status: 409, data: { message: 'x', code: 'ROLE_IN_USE', details: { reference: 'r' } } },
    }))
    setToken('t')
    renderApp('/app/peran')

    const row = (await screen.findByText('Kasir Cabang')).closest('tr')!
    await userEvent.click(within(row).getByRole('button', { name: 'Ubah' }))
    let dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText(/accounting\.journal\.view/)).toBeChecked()
    await userEvent.click(within(dialog).getByLabelText(/accounting\.journal\.create/))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan' }))
    expect(await screen.findByText('Peran disimpan.')).toBeInTheDocument()
    const put = calls.find((c) => c.method === 'PUT')!.data as { name: string; permissions: string[] }
    expect(put.name).toBe('Kasir Cabang')
    expect([...put.permissions].sort()).toEqual(['accounting.journal.create', 'accounting.journal.view'])

    await userEvent.click(within(row).getByRole('button', { name: 'Hapus' }))
    dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Hapus' }))
    expect(await within(dialog).findByText(/Peran masih dipakai pengguna/)).toBeInTheDocument()
  })

  it('lists the permission catalog with the state of each permission', async () => {
    mockApi(routes())
    setToken('t')
    renderApp('/app/peran')

    await userEvent.click(await screen.findByRole('tab', { name: 'Izin' }))
    expect(await screen.findByText('accounting.period.close')).toBeInTheDocument()
    expect(screen.getByText('Tidak dapat didelegasikan')).toBeInTheDocument()
  })

  it('shows the forbidden state when OptiNexus does not accept the person as Admin Tenant', async () => {
    mockApi(routes({ 'GET /app/delegation/roles': { status: 403, headers: { 'x-request-id': 'req-3' }, data: { message: 'x', code: 'ACTOR_NOT_TENANT_ADMIN', details: { reference: 'req-3' } } } }))
    setToken('t')
    renderApp('/app/peran')

    expect(await screen.findByRole('heading', { name: 'Anda tidak memiliki akses' })).toBeInTheDocument()
    expect(screen.getByText('Kode referensi: req-3')).toBeInTheDocument()
  })

  it('reads in English', async () => {
    setLocale('en')
    mockApi(routes())
    setToken('t')
    renderApp('/app/peran')

    expect(await screen.findByRole('heading', { name: 'Roles & permissions' })).toBeInTheDocument()
    expect(await screen.findByText('Built-in template')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'New role' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('Not delegable')).toBeInTheDocument()
    expect(within(dialog).getByText('Closing')).toBeInTheDocument()
  })
})
