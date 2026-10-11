import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatDate } from '../../../lib/format'
import { renderApp } from '../../../test/render'
import { RP, boot, cashFlow, cashView, cfIntegrity, drilldown, exporter, gets, ledgerView, plIntegrity, pnlView, profitLoss } from './testkit'

afterEach(() => vi.restoreAllMocks())

const PL_PAGE = '/app/akuntansi/laporan/laba-rugi'
const CF_PAGE = '/app/akuntansi/laporan/arus-kas'
const URL_PL = `${RP}/reports/profit-loss`
const URL_CF = `${RP}/reports/cash-flow`
const URL_DRILL = `${RP}/reports/drilldown`
const plTable = () => screen.findByRole('table', { name: 'Baris laba rugi' })
const cfTable = () => screen.findByRole('table', { name: 'Baris arus kas' })
const years = { data: [{ id: 'fy-1', code: 'FY2026', name: 'TF 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN', periods: [
  { id: 'p-3', fiscal_year_id: 'fy-1', number: 3, code: '2026-03', name: 'Maret 2026', start_date: '2026-03-01', end_date: '2026-03-31', status: 'OPEN' },
] }] }

describe('profit and loss', () => {
  const routes = (over: Record<string, unknown> = {}) => ({ [`GET ${URL_PL}`]: { data: profitLoss() }, ...over })

  it('shows the period the server used, asking for none: the movement of the lines, in the API order', async () => {
    const calls = boot(pnlView, routes())
    renderApp(PL_PAGE)

    expect(await screen.findByRole('heading', { name: 'Laba rugi', level: 1 })).toBeInTheDocument()
    const t = within(await plTable())
    expect(t.getAllByRole('row').map((r) => r.textContent)).toEqual(['UraianJumlah (IDR)', 'PENDAPATAN', 'Penjualan2.800.000,00', 'Beban pokok penjualan400.000,00', 'Laba bersih1.900.000,00'])
    const header = within((await screen.findByRole('heading', { name: 'Laba rugi', level: 2 })).closest('section')!)
    expect(header.getByText('Periode')).toBeInTheDocument()
    expect(header.getByText(`${formatDate('2026-01-01')} – ${formatDate('2026-10-08')}`)).toBeInTheDocument()
    expect(header.getByText(/Laba Rugi Standar · versi 1/)).toBeInTheDocument()
    expect(calls.find((c) => c.url === URL_PL)!.params).toEqual({})
    expect(screen.getByText(/Tanpa tanggal, laporan memakai tahun fiskal berjalan/)).toBeInTheDocument()
    expect(screen.getByText(/Hasil laporan sama dengan hasil buku besar/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Per tanggal')).not.toBeInTheDocument()
  })

  it('sends the dates the user types, or a period when the user may see the periods, never both', async () => {
    const calls = boot([...pnlView, 'accounting.period.view'], routes({ [`GET ${RP}/fiscal-years`]: { data: years } }))
    renderApp(PL_PAGE)
    await plTable()

    fireEvent.change(screen.getByLabelText('Dari'), { target: { value: '2026-02-01' } })
    fireEvent.change(screen.getByLabelText('Sampai'), { target: { value: '2026-02-28' } })
    await waitFor(() => expect(gets(calls, URL_PL).at(-1)?.params).toEqual({ from: '2026-02-01', to: '2026-02-28' }))

    await userEvent.selectOptions(await screen.findByLabelText('Periode'), 'p-3')
    await waitFor(() => expect(gets(calls, URL_PL).at(-1)?.params).toEqual({ period_id: 'p-3' }))
    expect(screen.queryByLabelText('Dari')).not.toBeInTheDocument()
  })

  it('offers no period choice to a user who may not see the periods', async () => {
    const calls = boot(pnlView, routes())
    renderApp(PL_PAGE)
    await plTable()
    expect(screen.queryByLabelText('Periode')).not.toBeInTheDocument()
    expect(calls.some((c) => c.url === `${RP}/fiscal-years`)).toBe(false)
  })

  it('looks unreliable when accounts are left out or the result differs from the ledger', async () => {
    const bad = plIntegrity({
      ledger_result: '2000000.0000', mapped_result: '1900000.0000', difference: '100000.0000', reliable: false,
      unmapped: [{ id: 'a-6900', code: '6900', name: 'Beban Lain', account_type: 'EXPENSE', amount: '-100000.0000' }],
    })
    boot(pnlView, routes({ [`GET ${URL_PL}`]: { data: profitLoss({ integrity: bad, warnings: [{ code: 'UNMAPPED_ACCOUNT_WITH_BALANCE', message: 'x', details: { count: 1, accounts: [] } }] }) } }))
    renderApp(PL_PAGE)

    const banner = await screen.findByText('Laporan ini tidak dapat diandalkan.')
    expect(banner.closest('.banner')).toHaveClass('banner-bad')
    expect(banner.closest('.banner')).toHaveTextContent('1 akun yang punya angka tidak ada di definisi laporan')
    expect(screen.queryByText(/Hasil laporan sama dengan hasil buku besar/)).not.toBeInTheDocument()
    const unmapped = within(screen.getByRole('table', { name: 'Akun bergerak yang tidak ada di definisi laba rugi' }))
    expect(unmapped.getByText('Beban Lain', { exact: false })).toBeInTheDocument()
    expect(unmapped.getByText('Beban')).toBeInTheDocument()
    expect(unmapped.getByText('-100.000,00')).toBeInTheDocument()
    expect(screen.getByText('Tidak sesuai', { selector: '.badge' })).toBeInTheDocument()
  })

  it('reports a result that differs from the ledger even when every account is mapped', async () => {
    boot(pnlView, routes({ [`GET ${URL_PL}`]: { data: profitLoss({ integrity: plIntegrity({ difference: '50000.0000', reliable: false }) }) } }))
    renderApp(PL_PAGE)
    const banner = await screen.findByText('Laporan ini tidak dapat diandalkan.')
    expect(banner.closest('.banner')).toHaveTextContent('hasil laporan tidak sama dengan hasil buku besar (selisih 50.000,00)')
  })

  it('warns when the definition changed inside the period', async () => {
    boot(pnlView, routes({ [`GET ${URL_PL}`]: { data: profitLoss({ warnings: [{ code: 'DEFINITION_CHANGED_IN_RANGE', message: 'x' }] }) } }))
    renderApp(PL_PAGE)
    expect(await screen.findByText(/Versi definisi laporan berubah di dalam periode ini/)).toBeInTheDocument()
  })

  it('shows a refusal of the dates in Indonesian', async () => {
    boot(pnlView, { [`GET ${URL_PL}`]: { status: 422, data: { code: 'RANGE_OUTSIDE_FISCAL_YEAR', message: 'x', details: {} } } })
    renderApp(PL_PAGE)
    expect(await screen.findByText('Tanggal harus berada di dalam tahun fiskal yang dipilih.')).toBeInTheDocument()
  })

  it('drills a line over the range of the report and says what the ledger shows for a period', async () => {
    const result = drilldown({
      statement: 'PROFIT_LOSS', as_of: undefined, range: { from: '2026-01-01', to: '2026-10-08' },
      line: { ...drilldown().line, code: 'ACC_4100', label: 'Penjualan', line_type: 'ACCOUNT', amount: '2800000.0000' }, components: [],
      accounts: [{ id: 'a-4100', code: '4100', name: 'Penjualan Barang', amount: '2800000.0000', line_code: 'ACC_4100', contribution: '2800000.0000', gl_query: { account_id: 'a-4100', from: '2026-01-01', to: '2026-10-08' } }],
    })
    const calls = boot([...pnlView, ...ledgerView], routes({ [`GET ${URL_DRILL}`]: { data: result } }))
    renderApp(PL_PAGE)
    await userEvent.click(within(await plTable()).getByRole('button', { name: 'Rincian Penjualan' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Rincian Laba rugi: Penjualan' }))
    expect(await dialog.findByText(/Jumlah akun adalah mutasinya selama periode/)).toBeInTheDocument()
    expect(dialog.getByText(/jurnal penutup akhir tahun/)).toBeInTheDocument()
    expect(calls.find((c) => c.url === URL_DRILL)!.params).toEqual({ statement: 'PROFIT_LOSS', definition_id: 'def-2', from: '2026-01-01', to: '2026-10-08', line: 'ACC_4100' })
  })

  it('exports with the filters on screen, for a user who may export', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const calls = boot([...pnlView, ...exporter], routes({ [`GET ${URL_PL}/export`]: { data: 'csv' } }))
    renderApp(PL_PAGE)
    await plTable()
    fireEvent.change(screen.getByLabelText('Dari'), { target: { value: '2026-02-01' } })
    await waitFor(() => expect(gets(calls, URL_PL).at(-1)?.params).toEqual({ from: '2026-02-01' }))
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${URL_PL}/export`)).toBeDefined())
    expect(calls.find((c) => c.url === `${URL_PL}/export`)!.params).toEqual({ from: '2026-02-01' })
  })

  it('shows the page only to a user who holds the permission', async () => {
    boot(cashView, routes())
    renderApp(PL_PAGE)
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
  })
})

describe('cash flow', () => {
  const routes = (over: Record<string, unknown> = {}) => ({ [`GET ${URL_CF}`]: { data: cashFlow() }, ...over })

  it('shows the sections, the opening and closing cash and the proof against the cash and bank accounts', async () => {
    const calls = boot(cashView, routes())
    renderApp(CF_PAGE)

    expect(await screen.findByRole('heading', { name: 'Arus kas', level: 1 })).toBeInTheDocument()
    const t = within(await cfTable())
    expect(t.getByText('Arus kas operasi').closest('tr')).toHaveTextContent('2.300.000,00')
    expect(t.getByText('Arus kas investasi').closest('tr')).toHaveTextContent('-500.000,00')
    expect(t.getByText('Kas awal periode').closest('tr')).toHaveTextContent('9.800.000,00')
    expect(t.getByText('Kas akhir periode').closest('tr')).toHaveTextContent('11.600.000,00')
    expect(t.getByText('Pergerakan belum diklasifikasi').closest('tr')).toHaveTextContent('0,00')
    expect(calls.find((c) => c.url === URL_CF)!.params).toEqual({})

    expect(screen.getByText(/Kas akhir hasil hitung sama dengan saldo kas\/bank di buku besar/)).toBeInTheDocument()
    const checks = within((await screen.findByRole('heading', { name: 'Pemeriksaan' })).closest('section')!)
    expect(checks.getByText('Cocok', { selector: '.badge' })).toBeInTheDocument()
    expect(checks.getByText('Arus Operasional').nextSibling).toHaveTextContent('400.000,00')
    expect(checks.getByText('Arus Investasi').nextSibling).toHaveTextContent('-500.000,00')
    const cash = within(checks.getByRole('table', { name: 'Akun kas dan bank yang dijelaskan laporan' }))
    expect(cash.getByText('Bank BCA', { exact: false })).toBeInTheDocument()
    expect(cash.getByText('4.400.000,00')).toBeInTheDocument()
    expect(checks.queryByRole('table', { name: 'Pergerakan kas yang belum diklasifikasi' })).not.toBeInTheDocument()
  })

  it('lists the movement that no line classifies and says the statement is not reliable', async () => {
    const integrity = cfIntegrity({
      reliable: false,
      unclassified: [
        { id: 'a-2150', code: '2150', name: 'Utang Lain', account_type: 'LIABILITY', amount: '400000.0000', reason: 'NOT_MAPPED' },
        { id: 'a-1300', code: '1300', name: 'Persediaan', account_type: 'ASSET', amount: '-90000.0000', reason: 'CATEGORY_UNCLASSIFIED' },
      ],
    })
    boot(cashView, routes({ [`GET ${URL_CF}`]: { data: cashFlow({ integrity, warnings: [{ code: 'CASH_FLOW_CATEGORY_UNCLASSIFIED', message: 'x', details: { count: 1, accounts: [] } }] }) } }))
    renderApp(CF_PAGE)

    const banner = await screen.findByText('Laporan ini tidak dapat diandalkan.')
    expect(banner.closest('.banner')).toHaveTextContent('2 akun yang bergerak belum diklasifikasi arus kasnya')
    expect(screen.queryByText(/Kas akhir hasil hitung sama dengan/)).not.toBeInTheDocument()
    expect(screen.getByText(/kategori arus kas "Belum diklasifikasi"/)).toBeInTheDocument()
    const rows = within(screen.getByRole('table', { name: 'Pergerakan kas yang belum diklasifikasi' }))
    expect(rows.getByText('Tidak ada di definisi laporan')).toBeInTheDocument()
    expect(rows.getByText('Kategori belum dipilih')).toBeInTheDocument()
    expect(rows.getByText('-90.000,00')).toBeInTheDocument()
  })

  it('shows a cash balance that does not reconcile as a difference, not as a match', async () => {
    const integrity = cfIntegrity({ cash_closing_calculated: '11500000.0000', difference: '-100000.0000', reconciled: false, reliable: false })
    boot(cashView, routes({ [`GET ${URL_CF}`]: { data: cashFlow({ integrity }) } }))
    renderApp(CF_PAGE)
    const banner = await screen.findByText('Laporan ini tidak dapat diandalkan.')
    expect(banner.closest('.banner')).toHaveTextContent('kas akhir hasil hitung tidak sama dengan saldo kas/bank buku besar (selisih -100.000,00)')
    expect(screen.getByText('Selisih', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText('11.500.000,00')).toBeInTheDocument()
  })

  it('does not claim a reconciliation for a partial ledger', async () => {
    boot(cashView, routes({ [`GET ${URL_CF}`]: { data: cashFlow({ complete: false, integrity: cfIntegrity({ reconciled: null }) }) } }))
    renderApp(CF_PAGE)
    expect(await screen.findByText('Cakupan sebagian.')).toBeInTheDocument()
    expect(screen.queryByText(/Kas akhir hasil hitung sama dengan/)).not.toBeInTheDocument()
    expect(screen.getByText('Tidak dapat dipastikan (cakupan sebagian)', { selector: '.badge' })).toBeInTheDocument()
  })

  it('opens the accounts behind the closing cash for a user who may read the ledger, and exports', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const result = drilldown({
      statement: 'CASH_FLOW', as_of: undefined, range: { from: '2026-01-01', to: '2026-03-31' },
      line: { ...drilldown().line, code: 'CASH_CLOSING', label: 'Kas akhir periode', line_type: 'SYSTEM', amount: '11600000.0000', system_key: 'CASH_CLOSING' }, components: [], explanation: 'English',
    })
    const calls = boot([...cashView, ...ledgerView, ...exporter], routes({ [`GET ${URL_DRILL}`]: { data: result }, [`GET ${URL_CF}/export`]: { data: 'csv' } }))
    renderApp(CF_PAGE)
    await userEvent.click(within(await cfTable()).getByRole('button', { name: 'Rincian Kas akhir periode' }))
    const dialog = within(await screen.findByRole('dialog'))
    expect(await dialog.findByText('Saldo akun kas dan bank pada akhir periode, menurut buku besar.')).toBeInTheDocument()
    expect(calls.find((c) => c.url === URL_DRILL)!.params).toEqual({ statement: 'CASH_FLOW', definition_id: 'def-3', from: '2026-01-01', to: '2026-03-31', line: 'CASH_CLOSING' })
    await userEvent.click(dialog.getByRole('button', { name: 'Tutup' }))

    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${URL_CF}/export`)).toBeDefined())
  })

  it('keeps the view in a read-only subscription and shows a permission refusal plainly', async () => {
    boot(cashView, routes(), { mode: 'READ_ONLY' })
    const first = renderApp(CF_PAGE)
    expect(await cfTable()).toBeInTheDocument()
    first.unmount()

    boot(cashView, { [`GET ${URL_CF}`]: { status: 403, data: { code: 'PERMISSION_DENIED', message: 'no' } } })
    renderApp(CF_PAGE)
    expect(await screen.findByText('Anda tidak memiliki izin untuk tindakan ini.')).toBeInTheDocument()
  })
})
