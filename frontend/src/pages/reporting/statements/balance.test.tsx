import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatDate } from '../../../lib/format'
import { renderApp } from '../../../test/render'
import { RP, balanceSheet, balanceView, boot, bsIntegrity, drilldown, exporter, gets, ledgerView, type Calls } from './testkit'

afterEach(() => vi.restoreAllMocks())

const PAGE = '/app/akuntansi/laporan/neraca'
const URL_BS = `${RP}/reports/balance-sheet`
const URL_DRILL = `${RP}/reports/drilldown`
const table = () => screen.findByRole('table', { name: 'Baris neraca' })
const routes = (over: Record<string, unknown> = {}) => ({ [`GET ${URL_BS}`]: { data: balanceSheet() }, ...over })

describe('balance sheet', () => {
  it('shows what the report is: title, company, date, currency, when, definition version and scope', async () => {
    const calls = boot(balanceView, routes())
    renderApp(PAGE)

    expect(await screen.findByRole('heading', { name: 'Neraca', level: 1 })).toBeInTheDocument()
    const header = within((await screen.findByRole('heading', { name: 'Neraca', level: 2 })).closest('section')!)
    expect(header.getByText('PT Maju Jaya')).toBeInTheDocument()
    expect(header.getByText(`Per ${formatDate('2026-10-08')}`)).toBeInTheDocument()
    expect(header.getByText('IDR')).toBeInTheDocument()
    expect(header.getByText(/Neraca Standar · versi 2/)).toBeInTheDocument()
    expect(header.getByText('Aktif', { selector: '.badge' })).toBeInTheDocument()
    expect(header.getByText('Seluruh perusahaan')).toBeInTheDocument()
    expect(calls.find((c) => c.url === URL_BS)!.params).toEqual({ as_of: '2026-10-08' })
  })

  it('renders the lines of the API in order, indented, with the amounts of the server and no amount on a header', async () => {
    boot(balanceView, routes())
    renderApp(PAGE)
    const t = within(await table())

    const rows = t.getAllByRole('row').map((r) => r.textContent)
    expect(rows[1]).toBe('ASET')
    expect(rows[2]).toBe('Kas7.200.000,00')
    expect(rows[4]).toBe('Total aset11.600.000,00')
    expect(rows.find((r) => r?.startsWith('Laba tahun berjalan'))).toBe('Laba tahun berjalan SistemDihitung sistem dari akun pendapatan dan beban yang belum ditutup2.700.000,00')
    expect(t.getByText('Sistem', { selector: '.badge' })).toBeInTheDocument()
    expect(t.getAllByText('11.600.000,00')).toHaveLength(2)
    const kas = t.getByText('Kas').closest('td')!
    const total = t.getByText('Total aset').closest('td')!
    expect(parseInt(kas.style.paddingLeft)).toBeGreaterThan(parseInt(total.style.paddingLeft))
    expect(t.getByRole('columnheader', { name: 'Jumlah (IDR)' })).toBeInTheDocument()
  })

  it('confirms a balanced, complete statement and lists the checks from the integrity block', async () => {
    boot(balanceView, routes())
    renderApp(PAGE)
    expect(await screen.findByText(/Neraca seimbang: aset sama dengan kewajiban/)).toBeInTheDocument()
    expect(screen.queryByText('Laporan ini tidak dapat diandalkan.')).not.toBeInTheDocument()
    const checks = within((await screen.findByRole('heading', { name: 'Pemeriksaan' })).closest('section')!)
    expect(checks.getByText('Seimbang', { selector: '.badge' })).toBeInTheDocument()
    expect(checks.getByText('Total aset')).toBeInTheDocument()
    expect(checks.getAllByText('11.600.000,00')).toHaveLength(2)
  })

  it('makes an unbalanced statement with unmapped accounts look unreliable and shows the difference as the API gave it', async () => {
    const bad = bsIntegrity({
      liabilities_and_equity: '11100000.0000', difference: '500000.0000', balanced: false, reliable: false,
      unmapped: [{ id: 'a-1999', code: '1999', name: 'Piutang Lain', account_type: 'ASSET', amount: '500000.0000' }],
    })
    boot(balanceView, routes({
      [`GET ${URL_BS}`]: { data: balanceSheet({ integrity: bad, warnings: [
        { code: 'UNMAPPED_ACCOUNT_WITH_BALANCE', message: 'x', details: { count: 1, accounts: [] } },
        { code: 'STATEMENT_DIFFERENCE', message: 'x', details: { difference: '500000.0000' } },
        { code: 'SECTION_MIXES_ASSETS_AND_FUNDING', message: 'x' },
      ] }) },
    }))
    renderApp(PAGE)

    const banner = await screen.findByText('Laporan ini tidak dapat diandalkan.')
    expect(banner.closest('.banner')).toHaveClass('banner-bad')
    expect(banner.closest('.banner')).toHaveTextContent('1 akun yang punya angka tidak ada di definisi laporan')
    expect(banner.closest('.banner')).toHaveTextContent('aset tidak sama dengan kewajiban + ekuitas (selisih 500.000,00)')
    expect(screen.queryByText(/Neraca seimbang/)).not.toBeInTheDocument()
    expect(screen.getByText(/Laporan tidak sama dengan buku besar. Selisih 500.000,00 ditampilkan apa adanya/)).toBeInTheDocument()
    expect(screen.getByText(/menjumlahkan aset dengan kewajiban atau ekuitas/)).toBeInTheDocument()
    const unmapped = within(screen.getByRole('table', { name: 'Akun bersaldo yang tidak ada di definisi neraca' }))
    expect(unmapped.getByText('Piutang Lain', { exact: false })).toBeInTheDocument()
    expect(unmapped.getByText('Aset')).toBeInTheDocument()
    expect(screen.getByText('Tidak seimbang', { selector: '.badge' })).toBeInTheDocument()
  })

  it('warns that a narrower data scope proves nothing and does not claim a balance', async () => {
    boot(balanceView, routes({ [`GET ${URL_BS}`]: { data: balanceSheet({ complete: false, integrity: bsIntegrity({ balanced: null, difference: '300000.0000' }), warnings: [{ code: 'PARTIAL_LEDGER', message: 'x', details: {} }] }) } }))
    renderApp(PAGE)

    const banner = await screen.findByText('Cakupan sebagian.')
    expect(banner.closest('.banner')).toHaveClass('banner-warn')
    expect(banner.closest('.banner')).toHaveTextContent('Angka ini bukan total perusahaan')
    expect(screen.queryByText(/Neraca seimbang/)).not.toBeInTheDocument()
    expect(screen.queryByText('Laporan ini tidak dapat diandalkan.')).not.toBeInTheDocument()
    expect(screen.getByText('Sebagian', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText('Tidak dapat dipastikan (cakupan sebagian)', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.queryByText('Peringatan')).not.toBeInTheDocument() // PARTIAL_LEDGER is said once, by the scope banner
  })

  it('says so when the definition is a draft', async () => {
    boot(balanceView, routes({ [`GET ${URL_BS}`]: { data: balanceSheet({ definition: { ...balanceSheet().definition, status: 'DRAFT' }, warnings: [{ code: 'DEFINITION_IS_DRAFT', message: 'x' }] }) } }))
    renderApp(PAGE)
    expect(await screen.findByText(/masih draf dan belum diterbitkan/)).toBeInTheDocument()
    expect(screen.getByText('Draf', { selector: '.badge' })).toBeInTheDocument()
  })

  it('asks the server again for the date, dimensions, zero lines and definition the user picks', async () => {
    const dims = { branches: [{ id: 'b-1', code: 'JKT', name: 'Jakarta' }], business_units: [], cost_centers: [] }
    const defs = { data: [
      { id: 'def-1', statement_type: 'BALANCE_SHEET', code: 'BS-STD', name: 'Neraca Standar', version: 2, status: 'ACTIVE', effective_from: null, effective_to: null },
      { id: 'def-0', statement_type: 'BALANCE_SHEET', code: 'BS-STD', name: 'Neraca Standar', version: 1, status: 'ARCHIVED', effective_from: null, effective_to: null },
    ] }
    const calls = boot([...balanceView, 'accounting.report_definition.view'], routes({ [`GET ${RP}/dimensions`]: { data: dims }, [`GET ${RP}/report-definitions`]: { data: defs } }))
    renderApp(PAGE)
    await table()

    fireEvent.change(screen.getByLabelText('Per tanggal'), { target: { value: '2026-09-30' } })
    await waitFor(() => expect(gets(calls, URL_BS).at(-1)?.params).toEqual({ as_of: '2026-09-30' }))
    await userEvent.selectOptions(await screen.findByLabelText('Cabang'), 'b-1')
    await userEvent.selectOptions(await screen.findByLabelText('Versi definisi'), 'def-0')
    await userEvent.click(screen.getByLabelText('Tampilkan baris nol'))
    await waitFor(() => expect(gets(calls, URL_BS).at(-1)?.params).toEqual({ as_of: '2026-09-30', branch_id: 'b-1', definition_id: 'def-0', include_zero: 1 }))
    expect(calls.find((c) => c.url === `${RP}/report-definitions`)!.params).toEqual({ statement_type: 'BALANCE_SHEET' })
  })

  it('does not ask for definitions or periods a user may not see', async () => {
    const calls = boot(balanceView, routes())
    renderApp(PAGE)
    await table()
    expect(screen.queryByLabelText('Versi definisi')).not.toBeInTheDocument()
    expect(calls.some((c) => c.url === `${RP}/report-definitions` || c.url === `${RP}/fiscal-years`)).toBe(false)
  })

  it('shows the accounts under each line when asked, without asking the server again', async () => {
    const calls = boot(balanceView, routes())
    renderApp(PAGE)
    const t = within(await table())
    expect(t.queryByText('Kas Kecil', { exact: false })).not.toBeInTheDocument()

    await userEvent.click(screen.getByLabelText('Tampilkan akun'))
    expect(t.getByText('Kas Kecil', { exact: false })).toBeInTheDocument()
    expect(t.getByText('Bank BCA', { exact: false })).toBeInTheDocument()
    expect(gets(calls, URL_BS)).toHaveLength(1)
  })

  it('says so when the definition is missing and points to where it is fixed', async () => {
    boot([...balanceView, 'accounting.report_definition.view'], {
      [`GET ${URL_BS}`]: { status: 422, data: { code: 'REPORT_DEFINITION_NOT_FOUND', message: 'No published', details: { statement_type: 'BALANCE_SHEET', date: '2026-10-08' } } },
    })
    renderApp(PAGE)
    expect(await screen.findByText(/Belum ada definisi laporan yang berlaku pada/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Buka Pemetaan laporan' })).toHaveAttribute('href', '/app/akuntansi/pemetaan-laporan')
    expect(screen.queryByRole('table', { name: 'Baris neraca' })).not.toBeInTheDocument()
  })

  it('shows an invalid definition with the codes the API found, and a refusal in Indonesian, and loads again on retry', async () => {
    let attempt = 0
    boot(balanceView, {
      [`GET ${URL_BS}`]: () => (++attempt === 1
        ? { status: 422, data: { code: 'REPORT_DEFINITION_INVALID', message: 'bad', details: { errors: [{ code: 'REQUIRED_SYSTEM_LINE_MISSING' }] } } }
        : { data: balanceSheet() }),
    })
    renderApp(PAGE)
    expect(await screen.findByText(/kesalahan konfigurasi sehingga laporan tidak dapat dibuat/)).toBeInTheDocument()
    expect(screen.getByText(/REQUIRED_SYSTEM_LINE_MISSING/)).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Buka Pemetaan laporan' })).not.toBeInTheDocument() // no report_definition.view
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await table()).toBeInTheDocument()
    expect(attempt).toBe(2)
  })

  it('shows a permission refusal of the API plainly', async () => {
    boot(balanceView, { [`GET ${URL_BS}`]: { status: 403, data: { code: 'PERMISSION_DENIED', message: 'no' } } })
    renderApp(PAGE)
    expect(await screen.findByText('Anda tidak memiliki izin untuk tindakan ini.')).toBeInTheDocument()
  })

  it('keeps the view and the export in a read-only subscription', async () => {
    boot([...balanceView, ...exporter], routes(), { mode: 'READ_ONLY' })
    renderApp(PAGE)
    expect(await table()).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ekspor CSV' })).toBeInTheDocument()
  })

  it('shows the page only to a user who holds the permission', async () => {
    boot([], routes())
    renderApp(PAGE)
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
  })
})

describe('balance sheet export', () => {
  it('is offered only to a user who may export, with the filters on screen', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const without = boot(balanceView, routes())
    const first = renderApp(PAGE)
    await table()
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    expect(without.some((c) => c.url.endsWith('/export'))).toBe(false)
    first.unmount()

    const calls = boot([...balanceView, ...exporter], routes({ [`GET ${URL_BS}/export`]: { data: 'csv' } }))
    renderApp(PAGE)
    await table()
    fireEvent.change(screen.getByLabelText('Per tanggal'), { target: { value: '2026-09-30' } })
    await waitFor(() => expect(gets(calls, URL_BS).at(-1)?.params).toEqual({ as_of: '2026-09-30' }))
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${URL_BS}/export`)).toBeDefined())
    expect(calls.find((c) => c.url === `${URL_BS}/export`)!.params).toEqual({ as_of: '2026-09-30' })
  })

  it('shows a refused export without losing the report', async () => {
    boot([...balanceView, ...exporter], routes({ [`GET ${URL_BS}/export`]: { status: 403, data: { code: 'PERMISSION_DENIED', message: 'no' } } }))
    renderApp(PAGE)
    await table()
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Anda tidak memiliki izin untuk tindakan ini.')
    expect(screen.getByRole('table', { name: 'Baris neraca' })).toBeInTheDocument()
  })
})

describe('balance sheet drill-down', () => {
  const withLedger = (over: Record<string, unknown> = {}) => routes({ [`GET ${URL_DRILL}`]: { data: drilldown() }, ...over })

  it('opens the accounts behind a line for a user who may read the ledger, explained by the statement as shown', async () => {
    const calls = boot([...balanceView, ...ledgerView], withLedger())
    renderApp(PAGE)
    const t = within(await table())

    await userEvent.click(t.getByRole('button', { name: 'Rincian Total aset' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Rincian Neraca: Total aset' }))
    expect(calls.find((c) => c.url === URL_DRILL)!.params).toEqual({ statement: 'BALANCE_SHEET', definition_id: 'def-1', as_of: '2026-10-08', line: 'TOT_1000' })

    const components = within(await dialog.findByRole('table', { name: 'Komponen Total aset' }))
    expect(components.getByText('Kas')).toBeInTheDocument()
    expect(components.getAllByText('+ ditambah')).toHaveLength(2)
    const accounts = within(dialog.getByRole('table', { name: 'Akun di balik Total aset' }))
    expect(accounts.getByText('Kas Kecil', { exact: false })).toBeInTheDocument()
    expect(accounts.getByRole('link', { name: 'Buku besar 1110 Kas Kecil' })).toHaveAttribute('href', '/app/akuntansi/buku-besar?account_id=a-1110&from=2026-01-01&to=2026-10-08')
    expect(accounts.getByRole('link', { name: 'Buku besar 1120 Bank BCA' })).toHaveAttribute('href', '/app/akuntansi/buku-besar?account_id=a-1120&from=2026-01-01&to=2026-10-08')
    expect(dialog.getByText(/Neraca bersifat kumulatif/)).toBeInTheDocument()
    expect(dialog.queryByText(/tidak berasal dari akun mana pun/)).not.toBeInTheDocument()

    await userEvent.click(dialog.getByRole('button', { name: 'Tutup' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('also opens from a click on the row, and carries the scope the report was made with', async () => {
    const report = balanceSheet({ filters: { include_zero: true, branch_id: 'b-1' } })
    const calls = boot([...balanceView, ...ledgerView], withLedger({ [`GET ${URL_BS}`]: { data: report } }))
    renderApp(PAGE)
    const t = within(await table())
    await userEvent.click(t.getByText('Bank').closest('tr')!)
    await screen.findByRole('dialog')
    expect(calls.find((c) => c.url === URL_DRILL)!.params).toEqual({ statement: 'BALANCE_SHEET', definition_id: 'def-1', as_of: '2026-10-08', branch_id: 'b-1', include_zero: 1, line: 'ACC_1120' })
  })

  it('explains a system figure in Indonesian and flags what no account stands behind', async () => {
    const result = drilldown({
      line: { ...drilldown().line, code: 'CURRENT_RESULT', label: 'Laba tahun berjalan', line_type: 'SYSTEM', amount: '2700000.0000', system_key: 'CURRENT_RESULT' },
      explanation: 'English text', components: [], not_explained_by_accounts: '250000.0000',
      accounts: [{ id: 'a-4100', code: '4100', name: 'Penjualan', amount: '3000000.0000', line_code: 'CURRENT_RESULT', contribution: '2450000.0000', gl_query: { account_id: 'a-4100', from: '2026-01-01', to: '2026-10-08', branch_id: 'b-1' } }],
    })
    boot([...balanceView, ...ledgerView], withLedger({ [`GET ${URL_DRILL}`]: { data: result } }))
    renderApp(PAGE)
    await userEvent.click(within(await table()).getByRole('button', { name: 'Rincian Laba tahun berjalan' }))
    const dialog = within(await screen.findByRole('dialog'))
    expect(await dialog.findByText(/Hasil akun pendapatan dan beban yang belum ditutup ke ekuitas/)).toBeInTheDocument()
    expect(dialog.queryByText('English text')).not.toBeInTheDocument()
    expect(dialog.getByText(/tidak berasal dari akun mana pun/)).toHaveTextContent('250.000,00')
    expect(dialog.getByRole('link', { name: 'Buku besar 4100 Penjualan' })).toHaveAttribute('href', '/app/akuntansi/buku-besar?account_id=a-4100&from=2026-01-01&to=2026-10-08&branch_id=b-1')
  })

  it('does not offer a drill-down without the ledger permission', async () => {
    const calls = boot(balanceView, withLedger())
    renderApp(PAGE)
    const t = within(await table())
    expect(t.queryByRole('button', { name: 'Rincian Total aset' })).not.toBeInTheDocument()
    await userEvent.click(t.getByText('Total aset'))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.some((c) => c.url === URL_DRILL)).toBe(false)
    expect(screen.queryByText(/Pilih sebuah baris/)).not.toBeInTheDocument()
  })

  it('shows the refusal of the drill-down inside the dialog and loads again on retry', async () => {
    let attempt = 0
    boot([...balanceView, ...ledgerView], withLedger({
      [`GET ${URL_DRILL}`]: () => (++attempt === 1 ? { status: 404, data: { code: 'REPORT_LINE_NOT_FOUND', message: 'x', details: {} } } : { data: drilldown() }),
    }))
    renderApp(PAGE)
    await userEvent.click(within(await table()).getByRole('button', { name: 'Rincian Total aset' }))
    const dialog = within(await screen.findByRole('dialog'))
    expect(await dialog.findByText(/Baris laporan ini tidak ditemukan/)).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Coba lagi' }))
    expect(await dialog.findByRole('table', { name: 'Akun di balik Total aset' })).toBeInTheDocument()
    expect(attempt).toBe(2)
  })

  it('explains a ledger that the user cannot reach with a 403 inside the dialog', async () => {
    const calls: Calls = boot([...balanceView, ...ledgerView], withLedger({ [`GET ${URL_DRILL}`]: { status: 403, data: { code: 'PERMISSION_DENIED', message: 'no' } } }))
    renderApp(PAGE)
    await userEvent.click(within(await table()).getByRole('button', { name: 'Rincian Total aset' }))
    expect(await screen.findByText('Anda tidak memiliki izin untuk tindakan ini.')).toBeInTheDocument()
    expect(gets(calls, URL_DRILL)).toHaveLength(1)
  })
})
