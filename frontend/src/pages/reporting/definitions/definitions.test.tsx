import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../../test/render'
import { RC, boot, detail, header, manage, published, unmapped, view, type Calls } from './testkit'

afterEach(() => vi.restoreAllMocks())

const LIST = '/app/akuntansi/pemetaan-laporan'
const URL_LIST = `${RC}/report-definitions`

const rows = [
  header({ id: 'd-pl', statement_type: 'PROFIT_LOSS', name: 'Laporan Laba Rugi', version: 2, status: 'DRAFT', lines_count: 12, mappings_count: 9 }),
  header({ id: 'd-pl1', statement_type: 'PROFIT_LOSS', name: 'Laporan Laba Rugi', version: 1, status: 'ACTIVE', effective_from: '2026-01-01', effective_to: null, lines_count: 10, mappings_count: 8 }),
  header({ id: 'd-bs', statement_type: 'BALANCE_SHEET', code: 'BALANCE_SHEET', name: 'Neraca', version: 1, status: 'ARCHIVED', effective_from: '2025-01-01', effective_to: '2025-12-31', lines_count: 20, mappings_count: 18 }),
]
const listRoutes = (over: Record<string, unknown> = {}) => ({
  [`GET ${URL_LIST}`]: { data: { data: rows } },
  [`GET ${URL_LIST}/d-pl/unmapped-accounts`]: { data: { data: [unmapped(), unmapped({ id: 'a-6100', code: '6100' }), unmapped({ id: 'a-6300', code: '6300' })] } },
  [`GET ${URL_LIST}/d-pl1/unmapped-accounts`]: { data: { data: [] } },
  ...over,
})
const gets = (calls: Calls, url: string) => calls.filter((c) => c.method === 'GET' && c.url === url)

describe('report definition list', () => {
  it('lists every definition with its type, version, status, effective dates and the counts the server sent', async () => {
    const calls = boot(view, listRoutes())
    renderApp(LIST)

    expect(await screen.findByRole('heading', { name: 'Pemetaan laporan keuangan' })).toBeInTheDocument()
    const table = within(await screen.findByRole('table', { name: 'Daftar definisi laporan' }))
    expect(table.getAllByRole('link', { name: 'Laporan Laba Rugi' })[0]).toHaveAttribute('href', '/app/akuntansi/pemetaan-laporan/d-pl')
    expect(table.getByRole('link', { name: 'Neraca' })).toHaveAttribute('href', '/app/akuntansi/pemetaan-laporan/d-bs')
    expect(table.getByText('Versi 2')).toBeInTheDocument()
    expect(table.getByText('Draf', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Terbit', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Diarsipkan', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Belum berlaku')).toBeInTheDocument()
    expect(table.getByText('01 Jan 2026 – sekarang')).toBeInTheDocument()
    expect(table.getByText('01 Jan 2025 – 31 Des 2025')).toBeInTheDocument()
    expect(table.getByText('12 / 9')).toBeInTheDocument()
    expect(table.getByText('BALANCE_SHEET', { selector: '.mono' })).toBeInTheDocument()
    expect(await table.findByText('3 akun', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Lengkap', { selector: '.badge' })).toBeInTheDocument()
    expect(calls.find((c) => c.url === URL_LIST)!.params).toEqual({})
  })

  it('asks the server for the unmapped accounts of draft and published definitions only', async () => {
    const calls = boot(view, listRoutes())
    renderApp(LIST)
    await screen.findByText('3 akun', { selector: '.badge' })
    expect(gets(calls, `${URL_LIST}/d-pl/unmapped-accounts`)).toHaveLength(1)
    expect(gets(calls, `${URL_LIST}/d-pl1/unmapped-accounts`)).toHaveLength(1)
    expect(gets(calls, `${URL_LIST}/d-bs/unmapped-accounts`)).toHaveLength(0)
  })

  it('shows a dash instead of a count when the unmapped accounts cannot be read', async () => {
    boot(view, listRoutes({ [`GET ${URL_LIST}/d-pl/unmapped-accounts`]: { status: 500, data: { message: 'boom' } } }))
    renderApp(LIST)
    const table = within(await screen.findByRole('table', { name: 'Daftar definisi laporan' }))
    await table.findByText('Lengkap', { selector: '.badge' })
    expect(table.queryByText(/akun$/, { selector: '.badge' })).not.toBeInTheDocument()
  })

  it('sends the type and status filters to the server', async () => {
    const calls = boot(view, listRoutes())
    renderApp(LIST)
    await screen.findByRole('table', { name: 'Daftar definisi laporan' })

    await userEvent.selectOptions(screen.getByLabelText('Jenis laporan'), 'PROFIT_LOSS')
    await waitFor(() => expect(gets(calls, URL_LIST).at(-1)?.params).toEqual({ statement_type: 'PROFIT_LOSS' }))
    await userEvent.selectOptions(screen.getByLabelText('Status definisi'), 'ACTIVE')
    await waitFor(() => expect(gets(calls, URL_LIST).at(-1)?.params).toEqual({ statement_type: 'PROFIT_LOSS', status: 'ACTIVE' }))
  })

  it('offers authoring only to a user who may manage definitions', async () => {
    boot(view, listRoutes())
    const first = renderApp(LIST)
    await screen.findByRole('table', { name: 'Daftar definisi laporan' })
    expect(screen.queryByRole('button', { name: 'Terapkan bawaan' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Definisi baru' })).not.toBeInTheDocument()
    first.unmount()

    boot(manage, listRoutes())
    renderApp(LIST)
    expect(await screen.findByRole('button', { name: 'Terapkan bawaan' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Definisi baru' })).toBeInTheDocument()
  })

  it('keeps the list and drops the authoring buttons on a read-only subscription', async () => {
    boot(manage, listRoutes(), { mode: 'READ_ONLY' })
    renderApp(LIST)
    expect(await screen.findByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(await screen.findByRole('table', { name: 'Daftar definisi laporan' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Terapkan bawaan' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Definisi baru' })).not.toBeInTheDocument()
  })

  it('says so when there is no definition yet and points a manager to the defaults', async () => {
    boot(manage, { [`GET ${URL_LIST}`]: { data: { data: [] } } })
    renderApp(LIST)
    expect(await screen.findByText('Belum ada definisi laporan')).toBeInTheDocument()
    expect(screen.getByText(/Pakai Terapkan bawaan/)).toBeInTheDocument()
  })

  it('shows a refusal and loads again on retry', async () => {
    let attempt = 0
    boot(view, listRoutes({ [`GET ${URL_LIST}`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: { data: rows } }) }))
    renderApp(LIST)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('table', { name: 'Daftar definisi laporan' })).toBeInTheDocument()
    expect(attempt).toBe(2)
  })

  it('refuses the page without the view permission', async () => {
    boot(['accounting.journal.view'], listRoutes())
    renderApp(LIST)
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
  })
})

describe('creating definitions', () => {
  it('posts the type, name and description of a new draft and opens it', async () => {
    const calls = boot(manage, listRoutes({
      [`POST ${URL_LIST}`]: { status: 201, data: detail({ id: 'd-new', name: 'Neraca baru', statement_type: 'BALANCE_SHEET', lines: [] }) },
      [`GET ${URL_LIST}/d-new`]: { data: detail({ id: 'd-new', name: 'Neraca baru', statement_type: 'BALANCE_SHEET', lines: [] }) },
      [`GET ${URL_LIST}/d-new/unmapped-accounts`]: { data: { data: [] } },
    }))
    renderApp(LIST)
    await userEvent.click(await screen.findByRole('button', { name: 'Definisi baru' }))

    const dialog = within(await screen.findByRole('dialog', { name: 'Definisi laporan baru' }))
    await userEvent.selectOptions(dialog.getByLabelText('Jenis laporan'), 'BALANCE_SHEET')
    await userEvent.type(dialog.getByLabelText('Nama'), ' Neraca baru ')
    await userEvent.type(dialog.getByLabelText('Keterangan'), 'Untuk audit')
    await userEvent.click(dialog.getByRole('button', { name: 'Buat draf' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === URL_LIST)).toBeDefined())
    expect(calls.find((c) => c.method === 'POST' && c.url === URL_LIST)!.data).toEqual({ statement_type: 'BALANCE_SHEET', name: 'Neraca baru', description: 'Untuk audit' })
    expect(await screen.findByRole('heading', { name: 'Neraca baru' })).toBeInTheDocument()
  })

  it('shows the refusal when a draft of that type already exists', async () => {
    boot(manage, listRoutes({ [`POST ${URL_LIST}`]: { status: 409, data: { code: 'REPORT_DRAFT_EXISTS', message: 'There is already a draft', details: { definition_id: 'd-pl' } } } }))
    renderApp(LIST)
    await userEvent.click(await screen.findByRole('button', { name: 'Definisi baru' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Definisi laporan baru' }))
    await userEvent.type(dialog.getByLabelText('Nama'), 'Laba rugi')
    await userEvent.click(dialog.getByRole('button', { name: 'Buat draf' }))
    expect(await dialog.findByText('Sudah ada draf untuk jenis laporan ini. Terbitkan atau hapus draf itu lebih dulu.')).toBeInTheDocument()
  })

  it('applies the defaults for the chosen types and reports what was created and what was left alone', async () => {
    const calls = boot(manage, listRoutes({
      [`POST ${URL_LIST}/defaults`]: {
        status: 201,
        data: {
          created: [{ ...header({ id: 'd-bs2', statement_type: 'BALANCE_SHEET', name: 'Laporan Posisi Keuangan (Neraca)', version: 3 }), validation: { valid: false, errors: [{ code: 'LINE_WITHOUT_MAPPING', message: 'x', line: null, details: {} }], warnings: [] } }],
          skipped: [{ statement_type: 'PROFIT_LOSS', definition_id: 'd-pl', code: 'REPORT_DRAFT_EXISTS', message: 'A draft PROFIT_LOSS definition already exists' }],
        },
      },
    }))
    renderApp(LIST)
    await userEvent.click(await screen.findByRole('button', { name: 'Terapkan bawaan' }))

    const dialog = within(await screen.findByRole('dialog', { name: 'Terapkan definisi bawaan' }))
    await userEvent.click(dialog.getByRole('checkbox', { name: 'Arus kas' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Terapkan' }))

    const result = within(await screen.findByRole('dialog', { name: 'Hasil penerapan bawaan' }))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${URL_LIST}/defaults`)!.data).toEqual({ statement_types: ['BALANCE_SHEET', 'PROFIT_LOSS'] })
    expect(result.getByRole('link', { name: /Laporan Posisi Keuangan \(Neraca\)/ })).toHaveAttribute('href', '/app/akuntansi/pemetaan-laporan/d-bs2')
    expect(result.getByText('1 kesalahan', { selector: '.badge' })).toBeInTheDocument()
    expect(result.getByText('Sudah ada draf; tinjau dan terbitkan, atau hapus lebih dulu.')).toBeInTheDocument()
    expect(gets(calls, URL_LIST).length).toBeGreaterThanOrEqual(2) // the list is loaded again behind the dialog
  })

  it('does not call the API when no type is chosen for the defaults', async () => {
    const calls = boot(manage, listRoutes())
    renderApp(LIST)
    await userEvent.click(await screen.findByRole('button', { name: 'Terapkan bawaan' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Terapkan definisi bawaan' }))
    for (const name of ['Neraca (posisi keuangan)', 'Laba rugi', 'Arus kas']) await userEvent.click(dialog.getByRole('checkbox', { name }))
    await userEvent.click(dialog.getByRole('button', { name: 'Terapkan' }))
    expect(dialog.getByText('Pilih minimal satu jenis laporan.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('keeps the published definition out of the way: its row links to the read-only detail', async () => {
    boot(view, listRoutes({ [`GET ${URL_LIST}`]: { data: { data: [published({ id: 'd-pl1' })] } } }))
    renderApp(LIST)
    expect(await screen.findByRole('link', { name: 'Buka Laporan Laba Rugi versi 1' })).toHaveAttribute('href', '/app/akuntansi/pemetaan-laporan/d-pl1')
  })
})
