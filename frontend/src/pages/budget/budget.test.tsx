import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { accounts, API, boot, budget, line, noDimensions, version, versionRef, year } from './testkit'

afterEach(() => vi.restoreAllMocks())

const view = ['accounting.budget.view', 'accounting.period.view']
const manage = [...view, 'accounting.budget.manage']
const approve = [...view, 'accounting.budget.approve']

describe('budgets list', () => {
  const list = { [`GET ${API}/budgets`]: { data: page([budget(), budget({ id: 'b-2', code: 'BUD-DRAFT', name: 'Draf', status: 'DRAFT', versions: [] })]) }, [`GET ${API}/fiscal-years`]: { data: { data: [year] } } }

  it('lists budgets with their version in force and hides creation without the manage permission', async () => {
    boot(view, list)
    renderApp('/app/akuntansi/anggaran')

    expect(await screen.findByText('BUD-2026')).toBeInTheDocument()
    expect(screen.getByText('Anggaran operasional 2026')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Anggaran baru' })).not.toBeInTheDocument()
  })

  it('creates a budget for a fiscal year and opens it', async () => {
    const calls = boot(manage, { ...list, [`POST ${API}/budgets`]: { status: 201, data: budget({ id: 'b-9', code: 'NEW' }) }, [`GET ${API}/budgets/b-9`]: { data: budget({ id: 'b-9', code: 'NEW', versions: [] }) } })
    renderApp('/app/akuntansi/anggaran')
    await userEvent.click(await screen.findByRole('button', { name: 'Anggaran baru' }))

    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'NEW')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Anggaran baru')
    await userEvent.selectOptions(dialog.getByLabelText('Tahun fiskal'), 'fy-1')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${API}/budgets`)).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ code: 'NEW', name: 'Anggaran baru', description: null, fiscal_year_id: 'fy-1' })
    expect(await screen.findByRole('heading', { name: 'NEW' })).toBeInTheDocument()
  })

  it('offers no creation in a read-only subscription', async () => {
    boot(manage, list, { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/anggaran')
    expect(await screen.findByText('BUD-2026')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Anggaran baru' })).not.toBeInTheDocument()
  })
})

describe('budget detail', () => {
  const detail = (b = budget()) => ({ [`GET ${API}/budgets/b-1`]: { data: b } })

  it('shows the versions and only the actions the status and permission allow', async () => {
    boot(approve, detail(budget({ status: 'DRAFT' })))
    renderApp('/app/akuntansi/anggaran/b-1')

    expect(await screen.findByRole('heading', { name: 'BUD-2026' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Buka anggaran' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Versi baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tutup anggaran' })).not.toBeInTheDocument()
  })

  it('does not offer cancelling a budget that ever had an approved version', async () => {
    boot([...manage, ...approve], detail(budget({ versions: [versionRef({ status: 'SUPERSEDED' }) as never, versionRef({ id: 'v-2', version_number: 2, status: 'ACTIVE' }) as never] })))
    renderApp('/app/akuntansi/anggaran/b-1')

    expect(await screen.findByRole('button', { name: 'Tutup anggaran' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Batalkan' })).not.toBeInTheDocument()
  })

  it('opens a draft budget', async () => {
    const calls = boot(approve, { ...detail(budget({ status: 'DRAFT' })), [`POST ${API}/budgets/b-1/open`]: { data: budget() } })
    renderApp('/app/akuntansi/anggaran/b-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Buka anggaran' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Buka' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${API}/budgets/b-1/open`)).toBe(true))
    expect(await screen.findByText('Anggaran dibuka.')).toBeInTheDocument()
  })

  it('shows a server refusal in Indonesian', async () => {
    boot(approve, { ...detail(budget({ status: 'DRAFT' })), [`POST ${API}/budgets/b-1/open`]: { status: 409, data: { message: 'x', code: 'BUDGET_INVALID_TRANSITION' } } })
    renderApp('/app/akuntansi/anggaran/b-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Buka anggaran' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Buka' }))

    expect(await screen.findByText('Perubahan status anggaran ini tidak diizinkan dari status saat ini.')).toBeInTheDocument()
  })
})

describe('budget version', () => {
  const base = (v = version()) => ({
    [`GET ${API}/budget-versions/v-1`]: { data: v },
    [`GET ${API}/fiscal-years`]: { data: { data: [year] } },
    [`GET ${API}/accounts`]: { data: accounts },
    [`GET ${API}/dimensions`]: { data: noDimensions },
  })
  const submit = ['accounting.budget.submit']

  it('shows the lines and the total from the server, with edit controls on a draft only', async () => {
    boot(manage, base())
    renderApp('/app/akuntansi/anggaran/b-1/versi/v-1')

    expect(await screen.findByRole('heading', { name: 'Versi 1 · Original' })).toBeInTheDocument()
    expect(screen.getAllByText('5.000.000,00').length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: 'Tambah baris' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ubah' })).toBeInTheDocument()
  })

  it('has no line controls once the version is approved', async () => {
    boot([...manage, ...approve], base(version({ status: 'APPROVED' })))
    renderApp('/app/akuntansi/anggaran/b-1/versi/v-1')

    expect(await screen.findByRole('button', { name: 'Aktifkan' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tambah baris' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Hapus' })).not.toBeInTheDocument()
  })

  it('adds a single line with one POST', async () => {
    const calls = boot(manage, { ...base(), [`POST ${API}/budget-versions/v-1/lines`]: { status: 201, data: version() } })
    renderApp('/app/akuntansi/anggaran/b-1/versi/v-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Tambah baris' }))

    const dialog = within(screen.getByRole('dialog'))
    await userEvent.selectOptions(await dialog.findByLabelText('Akun'), 'a-6400')
    await userEvent.type(dialog.getByLabelText('Jumlah periode 2026-02'), '2000000')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan baris' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ account_id: 'a-6400', accounting_period_id: 'p-2', amount: '2000000.0000', description: null, branch_id: null, business_unit_id: null, cost_center_id: null })
    expect(await screen.findByText('Baris anggaran disimpan.')).toBeInTheDocument()
  })

  it('adds several periods in one replace that keeps the existing lines', async () => {
    const calls = boot(manage, { ...base(), [`PUT ${API}/budget-versions/v-1/lines`]: { data: version() } })
    renderApp('/app/akuntansi/anggaran/b-1/versi/v-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Tambah baris' }))

    const dialog = within(screen.getByRole('dialog'))
    await userEvent.selectOptions(await dialog.findByLabelText('Akun'), 'a-6400')
    await userEvent.type(dialog.getByLabelText('Isi semua periode'), '1000')
    await userEvent.click(dialog.getByRole('button', { name: 'Terapkan ke semua periode' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan 2 baris' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')).toBeDefined())
    const sent = (calls.find((c) => c.method === 'PUT')!.data as { lines: Array<{ account_id: string; accounting_period_id: string; amount: string }> }).lines
    expect(sent.map((l) => [l.account_id, l.accounting_period_id, l.amount])).toEqual([['a-6300', 'p-1', '5000000.0000'], ['a-6400', 'p-1', '1000.0000'], ['a-6400', 'p-2', '1000.0000']])
  })

  it('edits and removes a line', async () => {
    const calls = boot(manage, { ...base(), [`PATCH ${API}/budget-versions/v-1/lines/l-1`]: { data: version() }, [`DELETE ${API}/budget-versions/v-1/lines/l-1`]: { data: version({ lines: [], lines_total: '0.0000' }) } })
    renderApp('/app/akuntansi/anggaran/b-1/versi/v-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Ubah' }))
    const edit = within(screen.getByRole('dialog'))
    await userEvent.clear(edit.getByLabelText('Jumlah'))
    await userEvent.type(edit.getByLabelText('Jumlah'), '7500000')
    await userEvent.click(edit.getByRole('button', { name: 'Simpan' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')).toBeDefined())
    expect(calls.find((c) => c.method === 'PATCH')!.data).toEqual({ amount: '7500000.0000', description: null })

    await userEvent.click(await screen.findByRole('button', { name: 'Hapus' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Hapus baris' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'DELETE')).toBe(true))
  })

  it('submits a draft for approval for a user who may submit', async () => {
    const calls = boot([...view, ...submit], { ...base(), [`POST ${API}/budget-versions/v-1/submit`]: { data: version({ status: 'SUBMITTED' }) } })
    renderApp('/app/akuntansi/anggaran/b-1/versi/v-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Ajukan' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${API}/budget-versions/v-1/submit`)).toBe(true))
    expect(await screen.findByText('Versi diajukan untuk persetujuan.')).toBeInTheDocument()
  })

  it('keeps approval disabled when segregation of duties forbids it and requires a reason to reject', async () => {
    boot(approve, base(version({ status: 'SUBMITTED', sod: { approve: false, post: false } })))
    renderApp('/app/akuntansi/anggaran/b-1/versi/v-1')

    expect(await screen.findByRole('button', { name: 'Setujui' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Tolak' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas/)).toBeInTheDocument()
  })

  it('rejects with a reason', async () => {
    const calls = boot(approve, { ...base(version({ status: 'SUBMITTED' })), [`POST ${API}/budget-versions/v-1/reject`]: { data: version({ status: 'REJECTED' }) } })
    renderApp('/app/akuntansi/anggaran/b-1/versi/v-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Tolak' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText(/Alasan/), 'Terlalu besar')
    await userEvent.click(dialog.getByRole('button', { name: 'Tolak' }))

    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/reject'))).toBeDefined())
    expect(calls.find((c) => c.url.endsWith('/reject'))!.data).toEqual({ reason: 'Terlalu besar' })
  })

  it('activates an approved version, sending the date only when one was chosen', async () => {
    const calls = boot(approve, { ...base(version({ status: 'APPROVED' })), [`POST ${API}/budget-versions/v-1/activate`]: { data: version({ status: 'ACTIVE' }) } })
    renderApp('/app/akuntansi/anggaran/b-1/versi/v-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Aktifkan' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Aktifkan' }))

    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/activate'))).toBeDefined())
    expect(calls.find((c) => c.url.endsWith('/activate'))!.data).toEqual({})
  })

  it('warns when the data scope hides part of the version', async () => {
    boot(view, base(version({ lines_complete: false })))
    renderApp('/app/akuntansi/anggaran/b-1/versi/v-1')
    expect(await screen.findByText(/hanya menampilkan sebagian baris/)).toBeInTheDocument()
  })
})

describe('budget vs actual', () => {
  const report = {
    budget: { id: 'b-1', code: 'BUD-2026', name: 'Anggaran operasional 2026', currency: 'IDR', status: 'ACTIVE' }, fiscal_year: { id: 'fy-1', code: 'FY2026' },
    version: { id: 'v-1', version_number: 1, label: 'Original', status: 'ACTIVE', effective_from: '2026-01-01', effective_until: null },
    period_from: { id: 'p-1', code: '2026-01' }, period_to: { id: 'p-2', code: '2026-02' }, from: '2026-01-01', to: '2026-02-28', group_by: 'account',
    rows: [
      { key: 'a-6300', code: '6300', name: 'Beban Utilitas', account_type: 'EXPENSE', unbudgeted: false, lines: 2, budget: '10000000.0000', actual: '12000000.0000', variance: '2000000.0000', variance_pct: '20.0000', favorable: false },
      { key: 'a-6500', code: '6500', name: 'Beban Lain', account_type: 'EXPENSE', unbudgeted: true, lines: 0, budget: '0.0000', actual: '300000.0000', variance: '300000.0000', variance_pct: null, favorable: false },
    ],
    totals: { budget: '10000000.0000', actual: '12300000.0000', variance: '2300000.0000', variance_pct: '23.0000', favorable: false, unbudgeted_actual: '300000.0000' }, complete: true,
  }
  const routes = (data: unknown = report) => ({
    [`GET ${API}/budgets`]: { data: page([budget()]) }, [`GET ${API}/fiscal-years`]: { data: { data: [year] } }, [`GET ${API}/dimensions`]: { data: noDimensions }, [`GET ${API}/budget-vs-actual`]: { data },
  })

  it('shows the server figures, flags unbudgeted actuals and hides export without the permission', async () => {
    const calls = boot(view, routes())
    renderApp('/app/akuntansi/anggaran-vs-aktual')

    expect(await screen.findByText(/Beban Utilitas/)).toBeInTheDocument()
    expect(screen.getByText('Tanpa anggaran', { selector: '.badge, span' })).toBeInTheDocument()
    expect(screen.getByText('12.300.000,00')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    await waitFor(() => expect(calls.some((c) => c.url === `${API}/budget-vs-actual` && c.params?.budget_id === 'b-1')).toBe(true))
  })

  it('offers the export to a user who may export, and sends the chosen grouping', async () => {
    const calls = boot([...view, 'accounting.report.export'], routes())
    renderApp('/app/akuntansi/anggaran-vs-aktual')
    await screen.findByText(/Beban Utilitas/)

    expect(screen.getByRole('button', { name: 'Ekspor CSV' })).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Kelompokkan'), 'period')
    await waitFor(() => expect(calls.some((c) => c.url === `${API}/budget-vs-actual` && c.params?.group_by === 'period')).toBe(true))
  })

  it('explains a missing version in Indonesian', async () => {
    boot(view, { ...routes(), [`GET ${API}/budget-vs-actual`]: { status: 422, data: { message: 'x', code: 'BUDGET_NO_EFFECTIVE_VERSION' } } })
    renderApp('/app/akuntansi/anggaran-vs-aktual')
    expect(await screen.findByText('Anggaran ini tidak memiliki versi yang berlaku pada tanggal tersebut.')).toBeInTheDocument()
  })
})

void line
