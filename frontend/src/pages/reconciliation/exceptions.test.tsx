import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../test/render'
import { RC, boot, exception, manage, page, view, type Calls } from './testkit'

afterEach(() => vi.restoreAllMocks())

const PATH = '/app/akuntansi/rekonsiliasi/pengecualian'
const LIST = `${RC}/reconciliation-exceptions`

const open = exception()
const explained = exception({ id: 'ex-2', type: 'CASH_BANK_GL', status: 'EXPLAINED', difference: '-250.0000', owner_id: 'u-9', owner: { id: 'u-9', name: 'Sari Dewi' }, notes: 'Menunggu koreksi' })
const resolved = exception({ id: 'ex-3', type: 'AR_GL', status: 'RESOLVED', calculated_status: 'MATCHED', difference: '0.0000', as_of_date: '2026-09-30' })
const routes = (over: Record<string, unknown> = {}) => ({ [`GET ${LIST}`]: { data: page([open, explained, resolved]) }, ...over })
const gets = (calls: Calls) => calls.filter((c) => c.method === 'GET' && c.url === LIST)

describe('reconciliation exceptions list', () => {
  it('lists the outstanding work with status, owner and the figures of the last computation', async () => {
    const calls = boot(view, routes())
    renderApp(PATH)

    expect(await screen.findByRole('heading', { name: 'Pengecualian rekonsiliasi' })).toBeInTheDocument()
    const table = within(await screen.findByRole('table', { name: 'Daftar pengecualian rekonsiliasi' }))
    expect(table.getByRole('link', { name: 'Utang vs buku besar' })).toHaveAttribute('href', '/app/akuntansi/rekonsiliasi/jenis/ap-gl?as_of=2026-10-08')
    expect(table.getByRole('link', { name: 'Kas/bank vs buku besar' })).toHaveAttribute('href', '/app/akuntansi/rekonsiliasi/jenis/cash-bank-gl?as_of=2026-10-08')
    expect(table.getByText('Terbuka', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Dijelaskan', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Selesai', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Sari Dewi')).toBeInTheDocument()
    expect(table.getByText('-5.000,00')).toBeInTheDocument()
    expect(table.getByText('-250,00')).toBeInTheDocument()
    expect(calls.find((c) => c.url === LIST)!.params).toEqual({ page: 1, status: 'ACTIVE' })
  })

  it('filters on the server by type, status and date, going back to the first page', async () => {
    const calls = boot(view, routes())
    renderApp(PATH)
    await screen.findByRole('table', { name: 'Daftar pengecualian rekonsiliasi' })

    await userEvent.selectOptions(screen.getByLabelText('Jenis rekonsiliasi'), 'AP_GL')
    await waitFor(() => expect(gets(calls).at(-1)?.params).toEqual({ page: 1, status: 'ACTIVE', type: 'ap-gl' }))
    await userEvent.selectOptions(screen.getByLabelText('Status penanganan'), 'RESOLVED')
    await waitFor(() => expect(gets(calls).at(-1)?.params).toEqual({ page: 1, status: 'RESOLVED', type: 'ap-gl' }))
    await userEvent.selectOptions(screen.getByLabelText('Status penanganan'), '')
    fireEvent.change(screen.getByLabelText('Per tanggal dari'), { target: { value: '2026-09-01' } })
    await waitFor(() => expect(gets(calls).at(-1)?.params).toEqual({ page: 1, type: 'ap-gl', as_of_from: '2026-09-01' }))
  })

  it('filters by owner: my own exceptions, or a member', async () => {
    const members = { data: [{ id: 'm-1', status: 'ACTIVE', joined_at: null, roles: [], user: { id: 'u-9', name: 'Sari Dewi', email: 'sari@majujaya.test', status: 'ACTIVE' } }], current_page: 1, last_page: 1, total: 1, per_page: 25 }
    const calls = boot([...view, 'access.user.view'], routes({ 'GET /app/users': { data: members } }))
    renderApp(PATH)
    await screen.findByRole('table', { name: 'Daftar pengecualian rekonsiliasi' })

    await userEvent.selectOptions(screen.getByLabelText('Pemilik'), 'me')
    await waitFor(() => expect(gets(calls).at(-1)?.params).toEqual({ page: 1, status: 'ACTIVE', mine: 1 }))
    await screen.findByRole('option', { name: 'Sari Dewi' })
    await userEvent.selectOptions(screen.getByLabelText('Pemilik'), 'u-9')
    await waitFor(() => expect(gets(calls).at(-1)?.params).toEqual({ page: 1, status: 'ACTIVE', owner_id: 'u-9' }))
  })

  it('offers no actions without the manage permission, and none on a resolved exception', async () => {
    boot(view, routes())
    const first = renderApp(PATH)
    await screen.findByRole('table', { name: 'Daftar pengecualian rekonsiliasi' })
    expect(screen.queryByRole('button', { name: 'Tetapkan' })).not.toBeInTheDocument()
    first.unmount()

    boot(manage, routes())
    renderApp(PATH)
    await screen.findByRole('table', { name: 'Daftar pengecualian rekonsiliasi' })
    // Two outstanding rows have actions; the resolved one is history.
    expect(screen.getAllByRole('button', { name: 'Tetapkan' })).toHaveLength(2)
    expect(screen.getAllByRole('button', { name: 'Jelaskan' })).toHaveLength(2)
    expect(screen.getAllByRole('button', { name: 'Selesaikan' })).toHaveLength(2)
  })

  it('offers no actions in a read-only subscription', async () => {
    boot(manage, routes(), { mode: 'READ_ONLY' })
    renderApp(PATH)
    await screen.findByRole('table', { name: 'Daftar pengecualian rekonsiliasi' })
    expect(screen.queryByRole('button', { name: 'Jelaskan' })).not.toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
  })

  it('explains the difference of one row and reads the list again', async () => {
    const calls = boot(manage, routes({ [`POST ${LIST}/ex-1/explain`]: { data: { ...open, status: 'EXPLAINED', notes: 'Selisih pembulatan vendor' } } }))
    renderApp(PATH)
    await screen.findByRole('table', { name: 'Daftar pengecualian rekonsiliasi' })
    const row = within(screen.getByRole('link', { name: 'Utang vs buku besar' }).closest('tr')!)
    await userEvent.click(row.getByRole('button', { name: 'Jelaskan' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Penjelasan'), 'Selisih pembulatan vendor')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan penjelasan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.url).toBe(`${LIST}/ex-1/explain`)
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ notes: 'Selisih pembulatan vendor' })
    await waitFor(() => expect(gets(calls).length).toBeGreaterThan(1))
  })

  it('keeps the resolve dialog open with the plain refusal while the numbers still differ', async () => {
    const refusal = { message: 'x', code: 'RECON_STILL_MISMATCHED', details: { status: 'MISMATCH', difference: '-250.0000' } }
    boot(manage, routes({ [`POST ${LIST}/ex-2/resolve`]: { status: 409, data: refusal } }))
    renderApp(PATH)
    await screen.findByRole('table', { name: 'Daftar pengecualian rekonsiliasi' })
    const row = within(screen.getByRole('link', { name: 'Kas/bank vs buku besar' }).closest('tr')!)
    await userEvent.click(row.getByRole('button', { name: 'Selesaikan' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Catatan penyelesaian'), 'Sudah beres')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Hitung ulang & selesaikan' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Rekonsiliasi masih tidak cocok setelah dihitung ulang')
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('says so when nothing matches the filter', async () => {
    boot(view, routes({ [`GET ${LIST}`]: { data: page([]) } }))
    renderApp(PATH)
    expect(await screen.findByText('Tidak ada pengecualian')).toBeInTheDocument()
  })

  it('shows an error with retry', async () => {
    let attempt = 0
    boot(view, routes({ [`GET ${LIST}`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: page([open]) }) }))
    renderApp(PATH)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('table', { name: 'Daftar pengecualian rekonsiliasi' })).toBeInTheDocument()
  })

  it('shows no figures for a narrower data scope (the API sends none)', async () => {
    boot(view, routes({ [`GET ${LIST}`]: { data: page([exception({ source_balance: null, gl_balance: null, difference: null })]) } }))
    renderApp(PATH)
    const table = within(await screen.findByRole('table', { name: 'Daftar pengecualian rekonsiliasi' }))
    expect(table.getAllByText('—').length).toBeGreaterThan(0)
  })
})
