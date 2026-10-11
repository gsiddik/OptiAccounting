import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { AC, bootLedger, ledger, ledgerLine, noDimensions, trialBalance, years, type LedgerCalls } from './ledger-testkit'

afterEach(() => vi.restoreAllMocks())

const TB = '/app/akuntansi/neraca-saldo'
const GL = '/app/akuntansi/buku-besar'
const URL_TB = `${AC}/trial-balance`
const URL_GL = `${AC}/general-ledger`
const accounts = { data: [{ id: 'a-1110', code: '1110', name: 'Kas', description: null, parent_id: null, account_type: 'ASSET', normal_balance: 'DEBIT', is_postable: true, is_control: false, currency: null, status: 'ACTIVE' }] }
const base = (over: Record<string, unknown> = {}) => ({ [`GET ${AC}/fiscal-years`]: { data: years }, [`GET ${AC}/dimensions`]: { data: noDimensions }, [`GET ${AC}/accounts`]: { data: accounts }, ...over })
const tbRoutes = (over: Record<string, unknown> = {}) => base({ [`GET ${URL_TB}`]: { data: trialBalance() }, ...over })
const glRoutes = (over: Record<string, unknown> = {}) => base({ [`GET ${URL_GL}`]: { data: ledger() }, ...over })
const lastParams = (calls: LedgerCalls, url: string) => calls.filter((c) => c.method === 'GET' && c.url === url).at(-1)?.params
const TB_VIEW = ['accounting.trial_balance.view']
const GL_VIEW = ['accounting.gl.view']
const MULTI = { modules: { ACCOUNTING_MULTI_CURRENCY: 'FULL' }, features: { EXCHANGE_RATE: true } }

describe('trial balance filters', () => {
  it('keeps the current query when no new filter is used', async () => {
    const calls = bootLedger(TB_VIEW, tbRoutes())
    renderApp(TB)
    expect(await screen.findByText(/Seimbang: total debit sama dengan total kredit/)).toBeInTheDocument()
    expect(lastParams(calls, URL_TB)).toEqual({ from: '2026-10-01', to: '2026-10-08', hierarchy: 1, include_zero: 0 })
  })

  it('sends a fiscal year without dates, lets a period replace it, and goes back to the default window', async () => {
    const calls = bootLedger(TB_VIEW, tbRoutes())
    renderApp(TB)
    await screen.findByText(/Seimbang/)

    await userEvent.selectOptions(await screen.findByLabelText('Tahun fiskal'), 'fy-1')
    await waitFor(() => expect(lastParams(calls, URL_TB)).toEqual({ fiscal_year_id: 'fy-1', hierarchy: 1, include_zero: 0 }))

    fireEvent.change(screen.getByLabelText('Sampai'), { target: { value: '2026-06-30' } }) // narrowing inside the year is allowed
    await waitFor(() => expect(lastParams(calls, URL_TB)).toEqual({ fiscal_year_id: 'fy-1', to: '2026-06-30', hierarchy: 1, include_zero: 0 }))

    await userEvent.selectOptions(screen.getByLabelText('Periode'), 'p-9')
    await waitFor(() => expect(lastParams(calls, URL_TB)).toEqual({ period_id: 'p-9', hierarchy: 1, include_zero: 0 }))
    expect((screen.getByLabelText('Tahun fiskal') as HTMLSelectElement).value).toBe('')

    await userEvent.selectOptions(screen.getByLabelText('Tahun fiskal'), 'fy-1')
    await userEvent.selectOptions(screen.getByLabelText('Tahun fiskal'), '')
    await waitFor(() => expect(lastParams(calls, URL_TB)).toEqual({ from: '2026-10-01', to: '2026-10-08', hierarchy: 1, include_zero: 0 }))
  })

  it('sends the account type and the code range, and says a filtered trial balance cannot be tested for balance', async () => {
    const calls = bootLedger(TB_VIEW, tbRoutes({ [`GET ${URL_TB}`]: (r: { params?: Record<string, unknown> }) => ({ data: r.params?.account_type ? trialBalance({ complete: false, reconciliation: { ...trialBalance().reconciliation, reconciled: null } }) : trialBalance() }) }))
    renderApp(TB)
    await screen.findByText(/Seimbang/)

    await userEvent.selectOptions(screen.getByLabelText('Tipe akun'), 'LIABILITY')
    await userEvent.type(screen.getByLabelText('Kode akun dari'), '2100')
    await userEvent.type(screen.getByLabelText('Kode akun sampai'), '2299')
    await waitFor(() => expect(lastParams(calls, URL_TB)).toEqual({ from: '2026-10-01', to: '2026-10-08', account_type: 'LIABILITY', account_code_from: '2100', account_code_to: '2299', hierarchy: 1, include_zero: 0 }))
    expect(await screen.findByText(/keseimbangannya tidak dapat diuji/)).toBeInTheDocument()
    expect(screen.queryByText(/Seimbang: total debit/)).not.toBeInTheDocument()
  })

  it('offers the transaction currency only with the multi-currency module and the right to read currencies', async () => {
    const without = bootLedger(TB_VIEW, tbRoutes())
    const first = renderApp(TB)
    await screen.findByText(/Seimbang/)
    expect(screen.queryByLabelText('Mata uang transaksi')).not.toBeInTheDocument()
    expect(without.some((c) => c.url === `${AC}/currencies`)).toBe(false)
    first.unmount()

    const currencies = page([{ id: 'c-1', code: 'USD', name: 'Dolar AS', symbol: '$', decimal_places: 2, status: 'ACTIVE' }, { id: 'c-2', code: 'IDR', name: 'Rupiah', symbol: 'Rp', decimal_places: 0, status: 'ACTIVE' }])
    const calls = bootLedger([...TB_VIEW, 'accounting.currency.view'], tbRoutes({ [`GET ${AC}/currencies`]: { data: currencies } }), MULTI)
    renderApp(TB)
    const select = await screen.findByLabelText('Mata uang transaksi')
    await userEvent.selectOptions(select, await within(select).findByRole('option', { name: 'USD · Dolar AS' }))
    await waitFor(() => expect(lastParams(calls, URL_TB)).toEqual({ from: '2026-10-01', to: '2026-10-08', currency: 'USD', hierarchy: 1, include_zero: 0 }))
    expect(within(select).getAllByRole('option').filter((o) => (o as HTMLOptionElement).value === 'IDR')).toHaveLength(1) // the functional currency is offered once
  })

  it('shows a refused filter in Indonesian and loads again on retry', async () => {
    let attempt = 0
    bootLedger(TB_VIEW, tbRoutes({ [`GET ${URL_TB}`]: () => (++attempt === 1 ? { status: 422, data: { code: 'ACCOUNT_RANGE_INVALID', message: 'The first account code is after the last.' } } : { data: trialBalance() }) }))
    renderApp(TB)
    expect(await screen.findByText('Kode akun awal berada setelah kode akun akhir.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText(/Seimbang/)).toBeInTheDocument()
  })

  it('exports with the same filters as the screen', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const calls = bootLedger([...TB_VIEW, 'accounting.report.export'], tbRoutes({ [`GET ${URL_TB}/export`]: { data: 'csv' } }))
    renderApp(TB)
    await screen.findByText(/Seimbang/)
    await userEvent.selectOptions(screen.getByLabelText('Tipe akun'), 'ASSET')
    await waitFor(() => expect(lastParams(calls, URL_TB)).toMatchObject({ account_type: 'ASSET' }))
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${URL_TB}/export`)).toBeDefined())
    expect(calls.find((c) => c.url === `${URL_TB}/export`)!.params).toEqual({ from: '2026-10-01', to: '2026-10-08', account_type: 'ASSET', hierarchy: 1, include_zero: 0 })
  })

  it('tells that year-end closing journals are part of the trial balance', async () => {
    bootLedger(TB_VIEW, tbRoutes())
    renderApp(TB)
    expect(await screen.findByText(/Jurnal penutup akhir tahun \(sumber YEAR_END_CLOSING\) ikut dihitung/)).toBeInTheDocument()
  })
})

describe('general ledger filters', () => {
  it('keeps the current query when no new filter is used', async () => {
    const calls = bootLedger(GL_VIEW, glRoutes())
    renderApp(GL)
    expect(await screen.findByRole('table', { name: 'Buku besar' })).toBeInTheDocument()
    expect(lastParams(calls, URL_GL)).toEqual({ from: '2026-10-01', to: '2026-10-08', page: 1 })
  })

  it('sends the fiscal year, the account type and code range, the source and the journal type', async () => {
    const calls = bootLedger(GL_VIEW, glRoutes())
    renderApp(GL)
    await screen.findByRole('table', { name: 'Buku besar' })

    await userEvent.selectOptions(await screen.findByLabelText('Tahun fiskal'), 'fy-1')
    await userEvent.selectOptions(screen.getByLabelText('Tipe akun'), 'EXPENSE')
    await userEvent.type(screen.getByLabelText('Kode akun dari'), '6000')
    await userEvent.type(screen.getByLabelText('Kode akun sampai'), '6999')
    await userEvent.type(screen.getByLabelText('Sumber jurnal'), 'AP_INVOICE')
    await userEvent.selectOptions(screen.getByLabelText('Jenis jurnal'), 'SYSTEM')

    await waitFor(() => expect(lastParams(calls, URL_GL)).toEqual({
      fiscal_year_id: 'fy-1', account_type: 'EXPENSE', account_code_from: '6000', account_code_to: '6999', source_type: 'AP_INVOICE', journal_type: 'SYSTEM', page: 1,
    }))
  })

  it('starts from the account and window of a link from another report', async () => {
    const calls = bootLedger(GL_VIEW, glRoutes())
    const first = renderApp(`${GL}?account_id=a-1110&from=2026-09-01&to=2026-09-30&branch_id=b-1`)
    await screen.findByRole('table', { name: 'Buku besar' })
    expect(calls.find((c) => c.url === URL_GL)!.params).toEqual({ from: '2026-09-01', to: '2026-09-30', account_id: 'a-1110', branch_id: 'b-1', page: 1 })
    await waitFor(() => expect((screen.getByLabelText('Akun') as HTMLSelectElement).value).toBe('a-1110'))
    expect((screen.getByLabelText('Dari') as HTMLInputElement).value).toBe('2026-09-01')
    first.unmount()

    // A dated balance links with its date only: the ledger then starts at the fiscal year of that date.
    const dated = bootLedger(GL_VIEW, glRoutes())
    renderApp(`${GL}?account_id=a-1110&to=2026-10-08`)
    await screen.findByRole('table', { name: 'Buku besar' })
    expect(dated.find((c) => c.url === URL_GL)!.params).toEqual({ to: '2026-10-08', account_id: 'a-1110', page: 1 })
  })

  it('shows the foreign amount and rate of a foreign line, and the source of a system journal', async () => {
    const foreign = ledgerLine({
      line_id: 'ln-2', journal_type: 'SYSTEM', source_type: 'AR_INVOICE', source_id: 'inv-7', currency: 'USD', foreign_debit: '100.0000', foreign_credit: '0.0000', exchange_rate: '15000.0000', debit: '1500000.0000',
    })
    bootLedger(GL_VIEW, glRoutes({ [`GET ${URL_GL}`]: { data: ledger({ data: [foreign, ledgerLine({ line_id: 'ln-3', currency: 'IDR', foreign_debit: '5.0000', exchange_rate: '1.0000' })], meta: { page: 1, per_page: 50, total: 2, last_page: 1 } }) } }))
    renderApp(GL)
    const table = within(await screen.findByRole('table', { name: 'Buku besar' }))
    expect(table.getByText('USD 100,00 · kurs 15.000,00')).toBeInTheDocument()
    expect(table.getByText('AR_INVOICE')).toBeInTheDocument()
    expect(table.getByText('inv-7')).toBeInTheDocument()
    expect(table.getAllByText(/kurs/)).toHaveLength(1) // a functional-currency line carries no foreign note
  })

  it('says a filtered ledger is a partial view and shows a refusal of the API', async () => {
    bootLedger(GL_VIEW, glRoutes({ [`GET ${URL_GL}`]: { data: ledger({ complete: false }) } }))
    const first = renderApp(GL)
    expect(await screen.findByText(/Laporan ini hanya memuat sebagian data/)).toBeInTheDocument()
    first.unmount()

    bootLedger(GL_VIEW, glRoutes({ [`GET ${URL_GL}`]: { status: 422, data: { code: 'CURRENCY_FILTER_UNAVAILABLE', message: 'needs module' } } }))
    renderApp(GL)
    expect(await screen.findByText(/Filter mata uang asing membutuhkan modul multi-mata uang/)).toBeInTheDocument()
  })

  it('exports with the same filters as the screen', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const calls = bootLedger([...GL_VIEW, 'accounting.report.export'], glRoutes({ [`GET ${URL_GL}/export`]: { data: 'csv' } }))
    renderApp(GL)
    await screen.findByRole('table', { name: 'Buku besar' })
    await userEvent.selectOptions(screen.getByLabelText('Jenis jurnal'), 'REVERSAL')
    await waitFor(() => expect(lastParams(calls, URL_GL)).toMatchObject({ journal_type: 'REVERSAL' }))
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${URL_GL}/export`)).toBeDefined())
    expect(calls.find((c) => c.url === `${URL_GL}/export`)!.params).toEqual({ from: '2026-10-01', to: '2026-10-08', journal_type: 'REVERSAL' })
  })
})
