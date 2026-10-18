import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { setLocale } from '../../../i18n'
import { setToken } from '../../../lib/api'
import { mockApi } from '../../../test/fakeApi'
import { renderApp, tenantCaps, tenantMe } from '../../../test/render'

const ADMIN = '11111111-1111-4111-8111-111111111111'
const ID = (n: number) => `aaaaaaaa-aaaa-4aaa-8aaa-00000000000${n}`

const accountant = { id: 'r-acc', code: 'ACCOUNTANT', name: 'Accountant', description: 'Day-to-day bookkeeping', template: true }
const cashier = { id: 'r-cash', code: 'CASHIER', name: 'Kasir Cabang', description: null, template: false }

const invitation = (n: number, name: string, over: Record<string, unknown> = {}) => ({
  id: ID(n), email: `${name.split(' ')[0].toLowerCase()}@majujaya.test`, name, status: 'PENDING', locale: 'id', role_ids: [], roles: [], invited_by: { id: ADMIN, name: 'Budi Admin' },
  created_at: '2026-10-17T08:00:00+00:00', expires_at: '2026-10-20T08:00:00+00:00', last_sent_at: '2026-10-17T08:00:00+00:00', send_count: 1,
  delivery: { status: 'SENT', attempted_at: '2026-10-17T08:00:30+00:00', error_code: null }, accepted_at: null, revoked_at: null, can_resend: true, can_revoke: true, ...over,
})

const pending = invitation(1, 'Rina Pending', { roles: [{ id: 'r-acc', code: 'ACCOUNTANT', name: 'Accountant' }], role_ids: ['r-acc'] })
const queued = invitation(2, 'Queen Queued', { delivery: { status: 'QUEUED', attempted_at: null, error_code: null } })
const failed = invitation(3, 'Fajar Failed', { delivery: { status: 'FAILED', attempted_at: '2026-10-17T08:00:30+00:00', error_code: 'MAIL_REJECTED' } })
const expired = invitation(4, 'Eko Expired', { status: 'EXPIRED', expires_at: '2026-10-10T08:00:00+00:00' })
const accepted = invitation(5, 'Alya Accepted', { status: 'ACCEPTED', accepted_at: '2026-10-18T09:00:00+00:00', can_resend: false, can_revoke: false })
const revoked = invitation(6, 'Rudi Revoked', { status: 'REVOKED', revoked_at: '2026-10-18T10:00:00+00:00', can_resend: false, can_revoke: false })
const exhausted = invitation(7, 'Lina Limit', { send_count: 5, can_resend: false })

const list = (data: unknown[]) => ({ data, meta: { pagination: { current_page: 1, per_page: 25, total: data.length, last_page: 1 } } })
const usersPage = { data: [], meta: { pagination: { current_page: 1, per_page: 25, total: 0, last_page: 1 }, seats: { limit: 5, used: 1, remaining: 4 } } }

const PERMS = ['access.user.view', 'access.user.manage', 'access.scope.manage', 'access.role.view', 'access.role.manage']
const routes = (extra: Record<string, unknown> = {}, permissions: string[] = PERMS) => ({
  'GET /auth/me': { data: tenantMe },
  'GET /app/capabilities': { data: tenantCaps({ mode: 'optinexus', permissions }) },
  'GET /app/delegation/status': { data: { available: true, reason: null, mode: 'optinexus' } },
  'GET /app/delegation/users': { data: usersPage },
  'GET /app/delegation/assignable-roles': { data: { data: [accountant, cashier] } },
  'GET /app/delegation/invitations': { data: list([pending, queued, failed, expired, accepted, revoked, exhausted]) },
  'GET /app/branches': { data: { data: [] } },
  'GET /app/business-units': { data: { data: [] } },
  ...extra,
}) as Parameters<typeof mockApi>[0]

const row = (name: string) => within(screen.getByRole('table', { name: /Daftar undangan|^Invitations$/ })).getByText(name).closest('tr')!

async function openInvitations() {
  setToken('t')
  renderApp('/app/pengguna')
  await userEvent.click(await screen.findByRole('tab', { name: /^(Undangan|Invitations)$/ }))
  await screen.findByRole('table', { name: /Daftar undangan|^Invitations$/ })
}

describe('Settings > Users: invite a person', () => {
  it('offers Invite user instead of Add user, and the dialog asks for no password', async () => {
    mockApi(routes())
    setToken('t')
    renderApp('/app/pengguna')

    await userEvent.click(await screen.findByRole('button', { name: 'Undang pengguna' }))
    expect(screen.queryByRole('button', { name: 'Tambah pengguna' })).not.toBeInTheDocument()
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByRole('heading', { name: 'Undang pengguna' })).toBeInTheDocument()
    expect(within(dialog).getByText(/Tidak ada kata sandi atau tautan yang melewati OptiEntry/)).toBeInTheDocument()
    for (const label of ['Nama', 'E-mail', 'Bahasa undangan']) expect(within(dialog).getByLabelText(label)).toBeInTheDocument()
    expect(within(dialog).queryByLabelText(/kata sandi/i)).not.toBeInTheDocument()
    expect(within(dialog).getByLabelText('Bahasa undangan')).toHaveValue('id')
    expect(await within(dialog).findByLabelText(/Accountant/)).not.toBeChecked()
  })

  it('sends name, e-mail, roles and the language to the API, never a password or a tenant, then shows the invitation', async () => {
    const calls = mockApi(routes({ 'POST /app/delegation/invitations': { status: 201, data: pending } }))
    setToken('t')
    renderApp('/app/pengguna')

    await userEvent.click(await screen.findByRole('button', { name: 'Undang pengguna' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Nama'), '  Rina Pending ')
    await userEvent.type(within(dialog).getByLabelText('E-mail'), 'rina@majujaya.test')
    await userEvent.click(await within(dialog).findByLabelText(/Accountant/))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Kirim undangan' }))

    expect(await screen.findByText('Undangan dibuat. OptiNexus mengirim e-mail ke rina@majujaya.test.')).toBeInTheDocument()
    const post = calls.find((c) => c.method === 'POST')!
    expect(post.url).toBe('/app/delegation/invitations')
    expect(post.data).toEqual({ name: 'Rina Pending', email: 'rina@majujaya.test', locale: 'id', role_ids: ['r-acc'] })
    expect(JSON.stringify(post.data)).not.toMatch(/password|token|tenant|acting_user|data_scopes/i)
    expect(screen.getByRole('tab', { name: 'Undangan' })).toHaveAttribute('aria-selected', 'true')
    expect(await screen.findByRole('table', { name: 'Daftar undangan' })).toBeInTheDocument()
  })

  it('defaults the language of the invitation to the language of the console and lets the Admin change it', async () => {
    setLocale('en')
    const calls = mockApi(routes({ 'POST /app/delegation/invitations': { status: 201, data: pending } }))
    setToken('t')
    renderApp('/app/pengguna')

    await userEvent.click(await screen.findByRole('button', { name: 'Invite user' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText('Language of the invitation')).toHaveValue('en')
    expect(within(dialog).getByText(/No password or link passes through OptiEntry/)).toBeInTheDocument()
    await userEvent.type(within(dialog).getByLabelText('Name'), 'Rina Pending')
    await userEvent.type(within(dialog).getByLabelText('E-mail'), 'rina@majujaya.test')
    await userEvent.selectOptions(within(dialog).getByLabelText('Language of the invitation'), 'id')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send invitation' }))

    expect(await screen.findByText('Invitation created. OptiNexus is sending an e-mail to rina@majujaya.test.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')!.data).toMatchObject({ locale: 'id', role_ids: [] })
  })

  it.each([
    ['INVITATION_PENDING', 409, {}, /Sudah ada undangan terbuka untuk e-mail ini/, /There is already an open invitation for this e-mail/],
    ['ALREADY_MEMBER', 409, {}, /sudah menjadi anggota dan punya akses/, /already a member with access/],
    ['ROLE_NOT_ASSIGNABLE', 422, {}, /OptiNexus menolak peran/, /OptiNexus refused a role/],
    ['INVITATION_RATE_LIMITED', 429, { retry_after: 120 }, /Coba lagi dalam 120 detik/, /Try again in 120 seconds/],
    ['IDENTITY_PROVIDER_UNAVAILABLE', 503, { outcome_unknown: true }, /mungkin sudah diterapkan/, /may already have been applied/],
    ['CAPACITY_EXCEEDED', 409, { limit_code: 'USER_LIMIT', limit: 5, used: 5 }, /Batas pengguna tercapai \(5 dari 5\)/, /user limit has been reached \(5 of 5\)/],
  ])('shows the refusal %s inside the dialog, in both languages, with the reference and what was typed', async (code, status, details, indonesian, english) => {
    for (const [locale, expected] of [['id', indonesian], ['en', english]] as const) {
      setLocale(locale)
      mockApi(routes({ 'POST /app/delegation/invitations': { status, headers: { 'x-request-id': 'req-7' }, data: { message: 'x', code, details: { reference: 'req-7', ...details } } } }))
      setToken('t')
      const view = renderApp('/app/pengguna')

      await userEvent.click(await screen.findByRole('button', { name: locale === 'id' ? 'Undang pengguna' : 'Invite user' }))
      const dialog = await screen.findByRole('dialog')
      await userEvent.type(within(dialog).getByLabelText(locale === 'id' ? 'Nama' : 'Name'), 'Rina Pending')
      await userEvent.type(within(dialog).getByLabelText('E-mail'), 'rina@majujaya.test')
      await userEvent.click(within(dialog).getByRole('button', { name: locale === 'id' ? 'Kirim undangan' : 'Send invitation' }))

      expect(await within(dialog).findByText(expected)).toBeInTheDocument()
      expect(within(dialog).getByText(locale === 'id' ? 'Kode referensi: req-7' : 'Reference: req-7')).toBeInTheDocument()
      expect(within(dialog).getByLabelText(locale === 'id' ? 'Nama' : 'Name')).toHaveValue('Rina Pending')
      view.unmount()
    }
  })
})

describe('Settings > Users: invitations', () => {
  it('lists every state with its chip and says in words what an expired, revoked or used link means', async () => {
    mockApi(routes())
    await openInvitations()

    const chips: [string, string, RegExp][] = [
      ['Rina Pending', 'Menunggu', /Berlaku sampai 20 Okt 2026.* Belum diterima/],
      ['Eko Expired', 'Kedaluwarsa', /Kedaluwarsa 10 Okt 2026.* Tautan tidak dapat dipakai\. Kirim ulang/],
      ['Alya Accepted', 'Diterima', /Diterima .*\. Tautan sudah terpakai dan tidak dapat dipakai lagi/],
      ['Rudi Revoked', 'Dicabut', /Dicabut .*\. Tautan tidak berlaku lagi/],
    ]
    for (const [name, chip, note] of chips) {
      const r = row(name)
      expect(within(r).getByText(chip)).toBeInTheDocument()
      expect(within(r).getByText(note)).toBeInTheDocument()
    }
    const first = row('Rina Pending')
    expect(within(first).getByText('Accountant')).toBeInTheDocument()
    expect(within(first).getByText(/Diundang oleh Budi Admin, 17 Okt 2026/)).toBeInTheDocument()
    expect(within(first).getByText(/Terakhir dikirim .*2026/)).toBeInTheDocument()
  })

  it('shows the delivery of the e-mail: queued, sent, and failed with the way to recover', async () => {
    mockApi(routes())
    await openInvitations()

    expect(within(row('Queen Queued')).getByText('Dalam antrean')).toBeInTheDocument()
    expect(within(row('Queen Queued')).getByText('OptiNexus belum mengirim e-mail ini.')).toBeInTheDocument()
    expect(within(row('Rina Pending')).getByText('Terkirim')).toBeInTheDocument()
    const failedRow = row('Fajar Failed')
    expect(within(failedRow).getByText('Gagal')).toBeInTheDocument()
    expect(within(failedRow).getByRole('note')).toHaveTextContent(/Periksa alamat e-mail, lalu pilih Kirim ulang/)
    expect(within(failedRow).getByRole('note')).toHaveTextContent('Kode: MAIL_REJECTED')
  })

  it('offers Resend and Revoke only where OptiNexus allows them', async () => {
    mockApi(routes())
    await openInvitations()

    expect(within(row('Rina Pending')).getByRole('button', { name: 'Kirim ulang' })).toBeEnabled()
    expect(within(row('Rina Pending')).getByRole('button', { name: 'Cabut' })).toBeInTheDocument()
    expect(within(row('Eko Expired')).getByRole('button', { name: 'Kirim ulang' })).toBeEnabled()
    // Resend limit reached: the button is there but disabled, and says why.
    const limit = within(row('Lina Limit')).getByRole('button', { name: 'Kirim ulang' })
    expect(limit).toBeDisabled()
    expect(limit).toHaveAttribute('title', 'Kirim ulang tidak tersedia untuk undangan ini.')
    // Accepted and revoked invitations have nothing left to do.
    for (const name of ['Alya Accepted', 'Rudi Revoked']) {
      expect(within(row(name)).queryByRole('button')).not.toBeInTheDocument()
    }
  })

  it('does not offer changes to someone who may only view users', async () => {
    mockApi(routes({}, ['access.user.view']))
    await openInvitations()

    expect(screen.queryByRole('button', { name: 'Undang pengguna' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Kirim ulang' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cabut' })).not.toBeInTheDocument()
    expect(within(row('Rina Pending')).getByText('Menunggu')).toBeInTheDocument()
  })

  it('resends: asks OptiNexus for a new link and says the old one stopped working', async () => {
    const calls = mockApi(routes({ [`POST /app/delegation/invitations/${ID(1)}/resend`]: { data: { ...pending, send_count: 2 } } }))
    await openInvitations()

    await userEvent.click(within(row('Rina Pending')).getByRole('button', { name: 'Kirim ulang' }))

    expect(await screen.findByText('Tautan baru dikirim ke rina@majujaya.test. Tautan sebelumnya tidak berlaku lagi.')).toBeInTheDocument()
    const post = calls.find((c) => c.method === 'POST')!
    expect(post.url).toBe(`/app/delegation/invitations/${ID(1)}/resend`)
    expect(JSON.stringify(post.data ?? {})).not.toMatch(/tenant|acting_user|token|password/)
    expect(calls.filter((c) => c.method === 'GET' && c.url === '/app/delegation/invitations')).toHaveLength(2) // the list is read again
  })

  it('explains the cooldown with the seconds OptiNexus asked for, and a state that no longer allows resending', async () => {
    mockApi(routes({
      [`POST /app/delegation/invitations/${ID(1)}/resend`]: { status: 429, headers: { 'x-request-id': 'req-8', 'retry-after': '45' }, data: { message: 'x', code: 'INVITATION_RESEND_COOLDOWN', details: { retry_after: 45, reference: 'req-8' } } },
      [`POST /app/delegation/invitations/${ID(4)}/resend`]: { status: 409, data: { message: 'x', code: 'INVITATION_NOT_PENDING', details: { status: 'ACCEPTED', reference: 'req-9' } } },
      [`POST /app/delegation/invitations/${ID(2)}/resend`]: { status: 409, data: { message: 'x', code: 'INVITATION_NOT_PENDING', details: { status: 'REVOKED', reference: 'req-10' } } },
      [`POST /app/delegation/invitations/${ID(3)}/resend`]: { status: 409, data: { message: 'x', code: 'INVITATION_RESEND_LIMIT', details: { reference: 'req-11' } } },
    }))
    await openInvitations()

    await userEvent.click(within(row('Rina Pending')).getByRole('button', { name: 'Kirim ulang' }))
    expect(await screen.findByText('Undangan baru saja dikirim. Tunggu 45 detik sebelum mengirim ulang.')).toBeInTheDocument()
    expect(screen.getByText('Kode referensi: req-8')).toBeInTheDocument()

    await userEvent.click(within(row('Eko Expired')).getByRole('button', { name: 'Kirim ulang' }))
    expect(await screen.findByText(/sudah diterima dan tautannya sudah terpakai/)).toBeInTheDocument()

    await userEvent.click(within(row('Queen Queued')).getByRole('button', { name: 'Kirim ulang' }))
    expect(await screen.findByText(/sudah dicabut dan tautannya tidak berlaku/)).toBeInTheDocument()

    await userEvent.click(within(row('Fajar Failed')).getByRole('button', { name: 'Kirim ulang' }))
    expect(await screen.findByText(/sudah dikirim ulang sebanyak yang diizinkan/)).toBeInTheDocument()
  })

  it('revokes with an optional reason after a confirmation', async () => {
    const calls = mockApi(routes({
      [`POST /app/delegation/invitations/${ID(1)}/revoke`]: { data: { ...pending, status: 'REVOKED', can_revoke: false, can_resend: false } },
      [`POST /app/delegation/invitations/${ID(2)}/revoke`]: { data: { ...queued, status: 'REVOKED', can_revoke: false, can_resend: false } },
    }))
    await openInvitations()

    await userEvent.click(within(row('Rina Pending')).getByRole('button', { name: 'Cabut' }))
    let dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/Cabut undangan untuk Rina Pending \(rina@majujaya\.test\)\? Tautan di e-mail langsung tidak berlaku/)).toBeInTheDocument()
    await userEvent.type(within(dialog).getByLabelText(/Alasan/), 'Salah orang')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cabut undangan' }))
    expect(await screen.findByText('Undangan untuk rina@majujaya.test dicabut. Tautannya tidak berlaku lagi.')).toBeInTheDocument()

    await userEvent.click(within(row('Queen Queued')).getByRole('button', { name: 'Cabut' }))
    dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cabut undangan' }))
    await screen.findByText('Undangan untuk queen@majujaya.test dicabut. Tautannya tidak berlaku lagi.')

    const posts = calls.filter((c) => c.method === 'POST')
    expect(posts.map((c) => c.data)).toEqual([{ reason: 'Salah orang' }, {}])
  })

  it('shows a refusal to revoke inside the dialog', async () => {
    mockApi(routes({ [`POST /app/delegation/invitations/${ID(1)}/revoke`]: { status: 409, headers: { 'x-request-id': 'req-12' }, data: { message: 'x', code: 'INVITATION_NOT_PENDING', details: { status: 'ACCEPTED', reference: 'req-12' } } } }))
    await openInvitations()

    await userEvent.click(within(row('Rina Pending')).getByRole('button', { name: 'Cabut' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cabut undangan' }))

    expect(await within(dialog).findByText(/sudah diterima dan tautannya sudah terpakai/)).toBeInTheDocument()
    expect(within(dialog).getByText('Kode referensi: req-12')).toBeInTheDocument()
  })

  it('filters by status and by search text through the API', async () => {
    const calls = mockApi(routes({
      'GET /app/delegation/invitations': (request: { params?: Record<string, unknown> }) => ({ data: list(request.params?.status === 'EXPIRED' ? [expired] : request.params?.search ? [] : [pending, expired]) }),
    }))
    await openInvitations()

    await userEvent.selectOptions(screen.getByLabelText('Status undangan'), 'EXPIRED')
    await screen.findByText('Kedaluwarsa', { selector: '.badge' })
    expect(screen.queryByText('Rina Pending')).not.toBeInTheDocument()
    expect(calls.some((c) => c.url === '/app/delegation/invitations' && c.params?.status === 'EXPIRED')).toBe(true)

    await userEvent.selectOptions(screen.getByLabelText('Status undangan'), '')
    await userEvent.type(screen.getByLabelText('Cari undangan'), 'zzz')
    expect(await screen.findByText('Tidak ada undangan yang cocok')).toBeInTheDocument()
    expect(calls.some((c) => c.params?.search === 'zzz')).toBe(true)
  })

  it('has an empty state when nobody has been invited yet', async () => {
    mockApi(routes({ 'GET /app/delegation/invitations': { data: list([]) } }))
    setToken('t')
    renderApp('/app/pengguna')
    await userEvent.click(await screen.findByRole('tab', { name: 'Undangan' }))

    expect(await screen.findByText('Belum ada undangan')).toBeInTheDocument()
    expect(screen.getByText(/Undang pengguna pertama; undangan muncul di sini/)).toBeInTheDocument()
  })

  it('shows the state of OptiNexus when the list cannot be read', async () => {
    mockApi(routes({ 'GET /app/delegation/invitations': { status: 403, headers: { 'x-request-id': 'req-3' }, data: { message: 'x', code: 'ACTOR_NOT_TENANT_ADMIN', details: { reference: 'req-3' } } } }))
    setToken('t')
    renderApp('/app/pengguna')
    await userEvent.click(await screen.findByRole('tab', { name: 'Undangan' }))

    expect(await screen.findByRole('heading', { name: 'Anda tidak memiliki akses' })).toBeInTheDocument()
    expect(screen.getByText('Kode referensi: req-3')).toBeInTheDocument()
  })

  it('reads in English: chips, delivery, notes and actions', async () => {
    setLocale('en')
    mockApi(routes())
    await openInvitations()

    const table = screen.getByRole('table', { name: 'Invitations' })
    for (const [name, chip, note] of [
      ['Rina Pending', 'Pending', /Valid until 20 Oct 2026.* Not accepted yet\./],
      ['Eko Expired', 'Expired', /Expired 10 Oct 2026.* The link cannot be used\. Resend to send a new one\./],
      ['Alya Accepted', 'Accepted', /Accepted .*\. The link has been used and cannot be used again/],
      ['Rudi Revoked', 'Revoked', /Revoked .*\. The link no longer works\./],
    ] as const) {
      const r = within(table).getByText(name).closest('tr')!
      expect(within(r).getByText(chip)).toBeInTheDocument()
      expect(within(r).getByText(note)).toBeInTheDocument()
    }
    const failedRow = within(table).getByText('Fajar Failed').closest('tr')!
    expect(within(failedRow).getByText('Failed')).toBeInTheDocument()
    expect(within(failedRow).getByRole('note')).toHaveTextContent(/Check the address, then choose Resend/)
    expect(within(within(table).getByText('Queen Queued').closest('tr')!).getByText('Queued')).toBeInTheDocument()
    expect(within(within(table).getByText('Rina Pending').closest('tr')!).getByRole('button', { name: 'Resend' })).toBeEnabled()
    expect(within(within(table).getByText('Rina Pending').closest('tr')!).getByRole('button', { name: 'Revoke' })).toBeEnabled()
    expect(screen.getByRole('columnheader', { name: 'E-mail delivery' })).toBeInTheDocument()
    expect(within(within(table).getByText('Rina Pending').closest('tr')!).getByText(/Invited by Budi Admin, 17 Oct 2026/)).toBeInTheDocument()
  })
})
