import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Account, Journal } from '../../lib/accounting'
import { setToken } from '../../lib/api'
import { mockApi, page } from '../../test/fakeApi'
import { renderApp, tenantCaps, tenantMe } from '../../test/render'

const nav = () => within(screen.getByRole('navigation', { name: /Menu/ }))

const account = (id: string, code: string, name: string, over: Partial<Account> = {}): Account => ({
  id, code, name, description: null, parent_id: null, account_type: 'ASSET', normal_balance: 'DEBIT', is_postable: true, is_control: false, currency: null, status: 'ACTIVE', ...over,
})
const accounts = [account('a-1', '1110', 'Kas'), account('a-2', '4100', 'Penjualan', { account_type: 'REVENUE', normal_balance: 'CREDIT' }), account('a-3', '1130', 'Piutang usaha', { is_control: true })]
const noDimensions = { branches: [], business_units: [], cost_centers: [] }

const journal = (over: Partial<Journal> = {}): Journal => ({
  id: 'j-1', journal_number: null, journal_type: 'MANUAL', status: 'DRAFT', document_date: '2026-10-08', posting_date: '2026-10-08', description: 'Penjualan tunai', reference: null, currency: 'IDR',
  total_debit: '1500000.0000', total_credit: '1500000.0000', created_by: 'u-t', creator: { id: 'u-t', name: 'Budi Santoso' }, reverses_journal_id: null, reversed_by_journal_id: null, source_type: null, source_id: null, posted_at: null,
  lines: [
    { id: 'l-1', line_number: 1, account_id: 'a-1', account: { id: 'a-1', code: '1110', name: 'Kas', normal_balance: 'DEBIT' }, description: null, reference: null, debit: '1500000.0000', credit: '0.0000', branch_id: null, business_unit_id: null, cost_center_id: null },
    { id: 'l-2', line_number: 2, account_id: 'a-2', account: { id: 'a-2', code: '4100', name: 'Penjualan', normal_balance: 'CREDIT' }, description: null, reference: null, debit: '0.0000', credit: '1500000.0000', branch_id: null, business_unit_id: null, cost_center_id: null },
  ],
  transitions: [{ id: 't-1', from_status: null, to_status: 'DRAFT', actor_user_id: 'u-t', actor: { id: 'u-t', name: 'Budi Santoso' }, reason: null, occurred_at: '2026-10-08T03:00:00Z' }],
  sod: { approve: true, post: true }, approval_required: true, ...over,
})

function boot(options: Parameters<typeof tenantCaps>[0], routes: Parameters<typeof mockApi>[0] = {}, modules?: Record<string, string>) {
  const caps = tenantCaps(options)
  mockApi({
    'GET /auth/me': { data: tenantMe },
    'GET /app/capabilities': { data: modules ? { ...caps, modules } : caps },
    ...routes,
  })
  setToken('t')
}

describe('accounting navigation and guards', () => {
  it('lists only the accounting pages the user holds permission for', async () => {
    boot({ permissions: ['accounting.journal.view', 'accounting.gl.view'] }, { 'GET /app/accounting/dashboard': { data: dashboard() } })
    renderApp('/app/akuntansi')

    await screen.findByRole('heading', { name: 'Akuntansi' })
    expect(nav().getByRole('link', { name: 'Jurnal' })).toBeInTheDocument()
    expect(nav().getByRole('link', { name: 'Buku besar' })).toBeInTheDocument()
    expect(nav().queryByRole('link', { name: 'Neraca saldo' })).not.toBeInTheDocument()
    expect(nav().queryByRole('link', { name: 'Profil akuntansi' })).not.toBeInTheDocument()
    expect(nav().queryByRole('link', { name: 'Aturan posting' })).not.toBeInTheDocument()
  })

  it('hides the whole accounting group and refuses the URL when the module is not in the subscription', async () => {
    boot({ permissions: ['accounting.journal.view', 'accounting.gl.view'] }, {}, {})
    renderApp('/app/akuntansi/jurnal')

    expect(await screen.findByText('Modul tidak tersedia')).toBeInTheDocument()
    expect(nav().queryByRole('link', { name: 'Jurnal' })).not.toBeInTheDocument()
    expect(nav().queryByText('Akuntansi')).not.toBeInTheDocument()
  })

  it('refuses a page whose permission is missing even when the URL is typed', async () => {
    boot({ permissions: ['accounting.journal.view'] })
    renderApp('/app/akuntansi/neraca-saldo')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
  })
})

describe('read-only subscription', () => {
  it('keeps journals viewable but offers no mutation', async () => {
    const all = ['accounting.journal.view', 'accounting.journal.create', 'accounting.journal.update', 'accounting.journal.submit', 'accounting.journal.approve', 'accounting.journal.post']
    boot({ permissions: all, subscriptionMode: 'READ_ONLY' }, {
      'GET /app/accounting/journals': { data: page([journal()]) },
      'GET /app/accounting/journals/j-1': { data: journal() },
    })
    renderApp('/app/akuntansi/jurnal')

    expect(await screen.findByRole('link', { name: 'Draf' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Jurnal baru' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('link', { name: 'Draf' }))
    expect(await screen.findByRole('heading', { name: 'Draf jurnal' })).toBeInTheDocument()
    for (const label of ['Ubah', 'Ajukan', 'Posting', 'Batalkan']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
  })
})

describe('journal editor', () => {
  const editorPermissions = ['accounting.journal.view', 'accounting.journal.create', 'accounting.coa.view', 'accounting.dimension.view']

  it('previews the balance in exact arithmetic and sends amounts as decimal strings', async () => {
    const calls = mockApi({
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps({ permissions: editorPermissions }) },
      'GET /app/accounting/accounts': { data: { data: accounts } },
      'GET /app/accounting/dimensions': { data: noDimensions },
      'POST /app/accounting/journals': { status: 201, data: journal() },
      'GET /app/accounting/journals/j-1': { data: journal() },
    })
    setToken('t')
    renderApp('/app/akuntansi/jurnal/baru')

    await screen.findByRole('heading', { name: 'Jurnal baru' })
    const select = (name: string) => screen.getByLabelText(name)
    await userEvent.selectOptions(select('Akun baris 1'), 'a-1')
    await userEvent.selectOptions(select('Akun baris 2'), 'a-2')
    await userEvent.type(select('Debit baris 1'), '1500000,10')
    await userEvent.type(select('Kredit baris 2'), '1500000.0')

    expect(screen.getByText('Selisih 0,10')).toBeInTheDocument() // 0.1 would drift in floating point; BigInt does not
    await userEvent.clear(select('Kredit baris 2'))
    await userEvent.type(select('Kredit baris 2'), '1500000.10')
    expect(screen.getByText('Seimbang')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Deskripsi'), 'Penjualan tunai')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    const post = calls.find((c) => c.method === 'POST' && c.url === '/app/accounting/journals')
    expect(post).toBeDefined()
    const lines = (post!.data as { lines: Record<string, unknown>[] }).lines
    expect(lines[0]).toMatchObject({ account_id: 'a-1', debit: '1500000.1000', credit: null })
    expect(lines[1]).toMatchObject({ account_id: 'a-2', debit: null, credit: '1500000.1000' })
    expect(JSON.stringify(post!.data)).not.toMatch(/"(debit|credit)":\d/) // never a JSON number
    expect(await screen.findByRole('heading', { name: 'Draf jurnal' })).toBeInTheDocument()
  })

  it('does not offer control accounts or header accounts on a manual journal', async () => {
    mockApi({
      'GET /auth/me': { data: tenantMe },
      'GET /app/capabilities': { data: tenantCaps({ permissions: editorPermissions }) },
      'GET /app/accounting/accounts': { data: { data: [...accounts, account('a-4', '1000', 'Aset', { is_postable: false })] } },
      'GET /app/accounting/dimensions': { data: noDimensions },
    })
    setToken('t')
    renderApp('/app/akuntansi/jurnal/baru')

    await screen.findByRole('heading', { name: 'Jurnal baru' })
    const options = within(screen.getByLabelText('Akun baris 1')).getAllByRole('option').map((o) => o.textContent)
    expect(options).toEqual(['Pilih akun…', '1110 · Kas', '4100 · Penjualan'])
  })
})

describe('journal workflow buttons', () => {
  const permissions = ['accounting.journal.view', 'accounting.journal.approve', 'accounting.journal.post', 'accounting.journal.update']

  it('shows approve and reject when the policy allows, and explains a segregation-of-duties refusal', async () => {
    boot({ permissions }, { 'GET /app/accounting/journals/j-1': { data: journal({ status: 'SUBMITTED', sod: { approve: false, post: false } }) } })
    renderApp('/app/akuntansi/jurnal/j-1')

    expect(await screen.findByRole('button', { name: 'Setujui' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat menyetujui/)).toBeInTheDocument()
  })

  it('offers posting only on an approved journal and reversal only on a posted, not yet reversed one', async () => {
    boot({ permissions: [...permissions, 'accounting.journal.reverse'] }, {
      'GET /app/accounting/journals/j-1': { data: journal({ status: 'APPROVED' }) },
      'GET /app/accounting/journals/j-2': { data: journal({ id: 'j-2', status: 'POSTED', journal_number: 'SJ-FY2026-000001' }) },
    })
    const view = renderApp('/app/akuntansi/jurnal/j-1')
    expect(await screen.findByRole('button', { name: 'Posting' })).toBeEnabled()
    expect(screen.queryByRole('button', { name: 'Balik jurnal' })).not.toBeInTheDocument()
    view.unmount()

    renderApp('/app/akuntansi/jurnal/j-2')
    expect(await screen.findByRole('heading', { name: 'SJ-FY2026-000001' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Balik jurnal' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Posting' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Batalkan' })).not.toBeInTheDocument()
  })
})

describe('trial balance', () => {
  const report = (reconciled: boolean | null) => ({
    range: { from: '2026-10-01', to: '2026-10-08' },
    data: [{ account_id: 'a-1', code: '1110', name: 'Kas', account_type: 'ASSET', depth: 0, is_header: false, opening_debit: '0.0000', opening_credit: '0.0000', debit: '1500000.0000', credit: '0.0000', ending_debit: '1500000.0000', ending_credit: '0.0000' }],
    totals: { opening_debit: '0.0000', opening_credit: '0.0000', debit: '1500000.0000', credit: '1500000.0000', ending_debit: '1500000.0000', ending_credit: '1500000.0000' },
    reconciliation: { opening: { debit: '0', credit: '0', difference: '0', equal: true }, movement: { debit: '1500000.0000', credit: '1500000.0000', difference: '0.0000', equal: true }, ending: { debit: '1500000.0000', credit: '1500000.0000', difference: '0.0000', equal: true }, reconciled },
    complete: reconciled !== null,
  })
  const routes = (reconciled: boolean | null) => ({
    'GET /app/accounting/trial-balance': { data: report(reconciled) },
    'GET /app/accounting/fiscal-years': { data: { data: [] } },
    'GET /app/accounting/dimensions': { data: noDimensions },
  })

  it('reports a balanced trial balance and formats the figures the server computed', async () => {
    boot({ permissions: ['accounting.trial_balance.view'] }, routes(true))
    renderApp('/app/akuntansi/neraca-saldo')
    expect(await screen.findByText(/Seimbang: total debit sama dengan total kredit/)).toBeInTheDocument()
    expect(screen.getAllByText('1.500.000,00').length).toBeGreaterThan(0)
  })

  it('says the balance cannot be tested for a partial scope instead of claiming it balances', async () => {
    boot({ permissions: ['accounting.trial_balance.view'] }, routes(null))
    renderApp('/app/akuntansi/neraca-saldo')
    expect(await screen.findByText(/keseimbangannya tidak dapat diuji/)).toBeInTheDocument()
    expect(screen.queryByText(/Seimbang: total debit/)).not.toBeInTheDocument()
  })
})

function dashboard() {
  return { business_date: '2026-10-08', fiscal_year: null, period: null, journals: { draft: 0, pending_approval: 0, awaiting_posting: 0 }, recent_posted: [] }
}
