import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../../test/render'
import { RC, account, asOfReport, balanceRow, balanceView, boot, noDimensions, periodReport, type Calls } from './testkit'

afterEach(() => vi.restoreAllMocks())

const PAGE = '/app/akuntansi/laporan/saldo-akun'
const URL_REPORT = `${RC}/reports/account-balance`
const URL_EXPORT = `${URL_REPORT}/export`

const accounts = [account('a-1000', '1000', 'Aset', { account_type: 'ASSET', is_postable: false }), account('a-1110', '1110', 'Kas', { account_type: 'ASSET', parent_id: 'a-1000' })]
const years = { data: [{ id: 'fy-1', code: 'FY2026', name: 'TF 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN', periods: [
  { id: 'p-9', fiscal_year_id: 'fy-1', number: 9, code: '2026-09', name: 'September 2026', start_date: '2026-09-01', end_date: '2026-09-30', status: 'OPEN' },
] }] }
const routes = (over: Record<string, unknown> = {}) => ({
  [`GET ${URL_REPORT}`]: { data: asOfReport() },
  [`GET ${RC}/accounts`]: { data: { data: accounts } },
  [`GET ${RC}/fiscal-years`]: { data: years },
  [`GET ${RC}/dimensions`]: { data: noDimensions },
  ...over,
})
const reports = (calls: Calls) => calls.filter((c) => c.method === 'GET' && c.url === URL_REPORT)
const lastParams = (calls: Calls) => reports(calls).at(-1)?.params
const chooseMode = (mode: string) => userEvent.selectOptions(screen.getByLabelText('Jenis tanggal'), mode)

describe('account balance: a dated balance', () => {
  it('shows the balance the server computed per account, the total and the drill link to the ledger', async () => {
    const calls = boot(balanceView, routes())
    renderApp(PAGE)

    expect(await screen.findByRole('heading', { name: 'Saldo akun' })).toBeInTheDocument()
    const table = within(await screen.findByRole('table', { name: 'Saldo akun' }))
    expect(table.getByText('1110')).toBeInTheDocument()
    expect(table.getByText('Aset', { selector: 'td' })).toBeInTheDocument()
    expect(table.getByText('Debit', { selector: 'td' })).toBeInTheDocument()
    expect(table.getByText('7.200.000,00')).toBeInTheDocument()
    expect(table.getByRole('link', { name: 'Buku besar 1110' })).toHaveAttribute('href', '/app/akuntansi/buku-besar?account_id=a-1110&to=2026-10-08')
    expect(screen.getByText('Saldo bersih debit - kredit')).toBeInTheDocument()
    expect(screen.getByText('Per 08 Okt 2026')).toBeInTheDocument()
    expect(screen.getAllByText('7.200.000,00')).toHaveLength(2) // the row and the total card, both from the API
    expect(screen.getByText(/mencakup seluruh buku besar/i)).toBeInTheDocument()
    expect(screen.queryByText(/hanya memuat sebagian data/)).not.toBeInTheDocument()
    expect(reports(calls)[0].params).toEqual({ as_of: '2026-10-08', hierarchy: 1, include_zero: 0 })
  })

  it('warns that a partial view does not stand for the whole ledger', async () => {
    boot(balanceView, routes({ [`GET ${URL_REPORT}`]: { data: asOfReport({ complete: false }) } }))
    renderApp(PAGE)
    expect(await screen.findByText(/Laporan ini hanya memuat sebagian data/)).toBeInTheDocument()
    expect(screen.queryByText(/mencakup seluruh buku besar/i)).not.toBeInTheDocument()
  })

  it('asks the server again for the date the user picks', async () => {
    const calls = boot(balanceView, routes())
    renderApp(PAGE)
    await screen.findByRole('table', { name: 'Saldo akun' })
    fireEvent.change(screen.getByLabelText('Per tanggal'), { target: { value: '2026-09-30' } })
    await waitFor(() => expect(lastParams(calls)).toEqual({ as_of: '2026-09-30', hierarchy: 1, include_zero: 0 }))
  })

  it('selects an account group, shows its normal balance and sends the account id', async () => {
    const group = asOfReport({
      account: { id: 'a-1000', code: '1000', name: 'Aset', account_type: 'ASSET', normal_balance: 'DEBIT', is_postable: false },
      data: [balanceRow({ account_id: 'a-1000', code: '1000', name: 'Aset', is_header: true, balance: '14100000.0000' }), balanceRow({ balance: '7200000.0000', depth: 1 })],
      totals: { normal_balance: 'DEBIT', balance: '14100000.0000', closing_net_debit_minus_credit: '14100000.0000' },
    })
    const calls = boot(balanceView, routes({ [`GET ${URL_REPORT}`]: (r: { params?: Record<string, unknown> }) => ({ data: r.params?.account_id ? group : asOfReport() }) }))
    renderApp(PAGE)
    await screen.findByRole('table', { name: 'Saldo akun' })

    await userEvent.selectOptions(screen.getByLabelText('Akun'), await screen.findByRole('option', { name: '1000 · Aset (induk)' }))
    expect(await screen.findByText(/Akun induk mewakili seluruh akun posting di bawahnya/)).toBeInTheDocument()
    expect(screen.getByText('Saldo menurut saldo normal debit')).toBeInTheDocument()
    expect(lastParams(calls)).toEqual({ as_of: '2026-10-08', account_id: 'a-1000', hierarchy: 1, include_zero: 0 })
    const table = within(screen.getByRole('table', { name: 'Saldo akun' }))
    expect(table.getAllByText('14.100.000,00').length).toBeGreaterThan(0)
    expect(table.queryByRole('link', { name: 'Buku besar 1000' })).not.toBeInTheDocument() // a header has no ledger of its own
    expect(table.getByRole('link', { name: 'Buku besar 1110' })).toBeInTheDocument()
  })

  it('sends the account type, the code range, the dimension and the display options', async () => {
    const catalog = { branches: [{ id: 'b-1', code: 'JKT', name: 'Jakarta' }], business_units: [], cost_centers: [] }
    const calls = boot(balanceView, routes({ [`GET ${RC}/dimensions`]: { data: catalog } }))
    renderApp(PAGE)
    await screen.findByRole('table', { name: 'Saldo akun' })

    await userEvent.selectOptions(screen.getByLabelText('Tipe akun'), 'ASSET')
    await userEvent.type(screen.getByLabelText('Kode akun dari'), '1100')
    await userEvent.type(screen.getByLabelText('Kode akun sampai'), '1199')
    await userEvent.selectOptions(await screen.findByLabelText('Cabang'), 'b-1')
    await userEvent.click(screen.getByRole('checkbox', { name: 'Tampilkan akun induk' }))
    await userEvent.click(screen.getByRole('checkbox', { name: 'Sertakan saldo nol' }))

    await waitFor(() => expect(lastParams(calls)).toEqual({
      as_of: '2026-10-08', account_type: 'ASSET', account_code_from: '1100', account_code_to: '1199', branch_id: 'b-1', hierarchy: 0, include_zero: 1,
    }))
  })

  it('says there is nothing to show when the server returns no account', async () => {
    boot(balanceView, routes({ [`GET ${URL_REPORT}`]: { data: asOfReport({ data: [] }) } }))
    renderApp(PAGE)
    expect(await screen.findByText('Tidak ada saldo')).toBeInTheDocument()
  })
})

describe('account balance: a range', () => {
  it('shows opening, movement and closing as the server returned them and links the range to the ledger', async () => {
    const calls = boot(balanceView, routes({ [`GET ${URL_REPORT}`]: (r: { params?: Record<string, unknown> }) => ({ data: r.params?.as_of ? asOfReport() : periodReport() }) }))
    renderApp(PAGE)
    await screen.findByRole('table', { name: 'Saldo akun' })
    await chooseMode('PERIOD')

    const table = within(await screen.findByRole('table', { name: 'Saldo akun' }))
    expect(await table.findByText('Mutasi debit', { selector: 'th' })).toBeInTheDocument()
    for (const text of ['6.000.000,00', '1.500.000,00', '300.000,00']) expect(table.getByText(text)).toBeInTheDocument()
    expect(table.getByText('7.200.000,00')).toBeInTheDocument()
    expect(table.getByRole('link', { name: 'Buku besar 1110' })).toHaveAttribute('href', '/app/akuntansi/buku-besar?account_id=a-1110&from=2026-10-01&to=2026-10-08')
    expect(screen.getByText(/Saldo awal \(bersih debit - kredit\)/)).toBeInTheDocument()
    expect(lastParams(calls)).toEqual({ from: '2026-10-01', to: '2026-10-08', hierarchy: 1, include_zero: 0 })
  })

  it('sends a fiscal year alone, and a period instead of a fiscal year, never both', async () => {
    const calls = boot(balanceView, routes({ [`GET ${URL_REPORT}`]: (r: { params?: Record<string, unknown> }) => ({ data: r.params?.as_of ? asOfReport() : periodReport() }) }))
    renderApp(PAGE)
    await screen.findByRole('table', { name: 'Saldo akun' })
    await chooseMode('PERIOD')

    await userEvent.selectOptions(await screen.findByLabelText('Tahun fiskal'), 'fy-1')
    await waitFor(() => expect(lastParams(calls)).toEqual({ fiscal_year_id: 'fy-1', hierarchy: 1, include_zero: 0 }))

    await userEvent.selectOptions(screen.getByLabelText('Periode'), 'p-9')
    await waitFor(() => expect(lastParams(calls)).toEqual({ period_id: 'p-9', hierarchy: 1, include_zero: 0 }))
    expect((screen.getByLabelText('Tahun fiskal') as HTMLSelectElement).value).toBe('')
  })
})

describe('account balance: export, errors and access', () => {
  it('offers the export only to a user who may export and sends the same filters', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const first = boot(balanceView, routes())
    const view = renderApp(PAGE)
    await screen.findByRole('table', { name: 'Saldo akun' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    expect(first.some((c) => c.url === URL_EXPORT)).toBe(false)
    view.unmount()

    const calls = boot([...balanceView, 'accounting.report.export'], routes({ [`GET ${URL_EXPORT}`]: { data: 'csv' } }))
    renderApp(PAGE)
    await screen.findByRole('table', { name: 'Saldo akun' })
    fireEvent.change(screen.getByLabelText('Per tanggal'), { target: { value: '2026-09-30' } })
    await waitFor(() => expect(lastParams(calls)).toEqual({ as_of: '2026-09-30', hierarchy: 1, include_zero: 0 }))
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url === URL_EXPORT)).toBeDefined())
    expect(calls.find((c) => c.url === URL_EXPORT)!.params).toEqual({ as_of: '2026-09-30', hierarchy: 1, include_zero: 0 })
  })

  it('shows the refusal of the API in Indonesian and reloads on retry', async () => {
    let attempt = 0
    boot(balanceView, routes({
      [`GET ${URL_REPORT}`]: () => (++attempt === 1
        ? { status: 422, data: { code: 'DATE_FILTER_CONFLICT', message: 'Give either as_of or a period / date range, not both.' } }
        : { data: asOfReport() }),
    }))
    renderApp(PAGE)
    expect(await screen.findByText(/Pilih satu cara menentukan tanggal/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('table', { name: 'Saldo akun' })).toBeInTheDocument()
  })

  it('shows an unknown account and an invalid code range as the API reports them', async () => {
    boot(balanceView, routes({ [`GET ${URL_REPORT}`]: { status: 422, data: { code: 'ACCOUNT_RANGE_INVALID', message: 'The first account code is after the last.' } } }))
    renderApp(PAGE)
    expect(await screen.findByText('Kode akun awal berada setelah kode akun akhir.')).toBeInTheDocument()
  })

  it('refuses the page without the ledger permission', async () => {
    boot(['accounting.journal.view'], routes())
    renderApp(PAGE)
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
  })
})
