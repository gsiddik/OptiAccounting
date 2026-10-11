import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatDate } from '../../../lib/format'
import { renderApp } from '../../../test/render'
import { RP, boot, bsIntegrity, cline, comparative, exporter, gets, pnlView } from './testkit'

afterEach(() => vi.restoreAllMocks())

const PAGE = '/app/akuntansi/laporan/komparatif'
const URL_CMP = `${RP}/reports/comparative`
const table = () => screen.findByRole('table', { name: 'Baris komparatif laba rugi' })
const routes = (over: Record<string, unknown> = {}) => ({ [`GET ${URL_CMP}`]: { data: comparative() }, ...over })
const budgets = { data: [{ id: 'bg-1', code: 'BGT-2026', name: 'Anggaran 2026', status: 'ACTIVE', fiscal_year_id: 'fy-1', currency: 'IDR' }], current_page: 1, last_page: 1, total: 1, per_page: 100 }
const budgetView = [...pnlView, 'accounting.budget.view']

const budgetReport = () => comparative({
  mode: 'BUDGET', range: { from: '2026-03-01', to: '2026-03-31' },
  comparison: { mode: 'BUDGET', range: { from: '2026-03-01', to: '2026-03-31' }, budget: { id: 'bg-1', code: 'BGT-2026', name: 'Anggaran 2026' }, version: { id: 'v-1', version_number: 1, label: 'Versi awal' } },
  integrity: { current: comparative().integrity.current, budget: { budget_result: '3000000.0000', unplaced: [{ id: 'a-6000', code: '6000', name: 'Beban Operasional', budget: '700000.0000' }] } },
  warnings: [{ code: 'BUDGET_NOT_ON_STATEMENT', message: 'x', details: { count: 1, accounts: [{ id: 'a-6000', code: '6000', name: 'Beban Operasional', budget: '700000.0000' }] } }],
})

describe('comparative report', () => {
  it('compares the profit and loss with the period before by default, with the columns exactly as the API returned them', async () => {
    const calls = boot(pnlView, routes())
    renderApp(PAGE)

    expect(await screen.findByRole('heading', { name: 'Laporan komparatif', level: 1 })).toBeInTheDocument()
    const t = within(await table())
    expect(calls.find((c) => c.url === URL_CMP)!.params).toEqual({ statement: 'PROFIT_LOSS', mode: 'PRIOR_PERIOD' })
    expect(t.getAllByRole('row').map((r) => r.textContent)).toEqual([
      expect.stringMatching(/^UraianSaat ini.*Periode sebelumnya.*Selisih.*Selisih %/),
      'PENDAPATAN',
      'Penjualan800.000,002.000.000,00-1.200.000,00-60,00%',
      'Beban sewa200.000,000,00200.000,00—',
      'Laba bersih600.000,001.700.000,00-1.100.000,00-64,71%',
    ])
    expect(t.getByRole('columnheader', { name: /Saat ini/ })).toHaveTextContent(`${formatDate('2026-02-01')} – ${formatDate('2026-02-28')}`)
    expect(t.getByRole('columnheader', { name: /Periode sebelumnya/ })).toHaveTextContent(`${formatDate('2026-01-01')} – ${formatDate('2026-01-31')}`)
    expect(screen.getByText(/Selisih adalah angka saat ini dikurangi angka pembanding, dihitung server/)).toBeInTheDocument()
    const header = within((await screen.findByRole('heading', { name: 'Komparatif Laba rugi', level: 2 })).closest('section')!)
    expect(header.getByText('PT Maju Jaya')).toBeInTheDocument()
    expect(header.getByText(/Periode sebelumnya · /)).toBeInTheDocument()
  })

  it('shows the integrity of both sides and confirms them only when both are reliable', async () => {
    boot(pnlView, routes())
    renderApp(PAGE)
    expect(await screen.findByText(/Berlaku untuk kedua sisi perbandingan/)).toBeInTheDocument()
    expect(await screen.findByRole('heading', { name: 'Pemeriksaan: sisi saat ini' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Pemeriksaan: sisi pembanding' })).toBeInTheDocument()
  })

  it('marks the report unreliable when the comparison side is, and says which side', async () => {
    const report = comparative({ integrity: { current: comparative().integrity.current, comparison: { ...comparative().integrity.comparison!, reliable: false, unmapped: [{ id: 'a-6900', code: '6900', name: 'Beban Lain', account_type: 'EXPENSE', amount: '-5.0000' }] } as never },
      warnings: [{ code: 'UNMAPPED_ACCOUNT_WITH_BALANCE', message: 'x', details: { count: 1 }, side: 'comparison' }] })
    boot(pnlView, routes({ [`GET ${URL_CMP}`]: { data: report } }))
    renderApp(PAGE)
    const banner = await screen.findByText('Laporan ini tidak dapat diandalkan.')
    expect(banner.closest('.banner')).toHaveTextContent('sisi pembanding: 1 akun yang punya angka tidak ada di definisi laporan')
    expect(banner.closest('.banner')).not.toHaveTextContent('sisi saat ini')
    expect(screen.queryByText(/Berlaku untuk kedua sisi/)).not.toBeInTheDocument()
    expect(screen.getByText(/\(sisi pembanding\)/)).toBeInTheDocument()
  })

  it('warns that a narrower data scope narrows both sides', async () => {
    boot(pnlView, routes({ [`GET ${URL_CMP}`]: { data: comparative({ complete: false }) } }))
    renderApp(PAGE)
    expect(await screen.findByText('Cakupan sebagian.')).toBeInTheDocument()
    expect(screen.queryByText(/Berlaku untuk kedua sisi/)).not.toBeInTheDocument()
  })

  it('asks for the prior year, and for a balance sheet a date instead of a period', async () => {
    const calls = boot([...pnlView, 'accounting.reporting.balance_sheet.view'], routes({
      [`GET ${URL_CMP}`]: (request: { params?: Record<string, unknown> }) => ({
        data: request.params?.statement === 'BALANCE_SHEET'
          ? { ...comparative({ statement: 'BALANCE_SHEET', mode: 'PRIOR_YEAR', range: undefined, as_of: '2026-10-08', comparison: { mode: 'PRIOR_YEAR', as_of: '2025-10-08' }, lines: [cline({ code: 'ACC_1110', label: 'Kas', current: '7200000.0000', comparison: '6000000.0000', variance: '1200000.0000', variance_pct: '20.00' })],
            integrity: { current: bsIntegrity(), comparison: bsIntegrity() } }) }
          : comparative(),
      }),
    }))
    renderApp(PAGE)
    await table()

    await userEvent.selectOptions(screen.getByLabelText('Pembanding'), 'PRIOR_YEAR')
    await waitFor(() => expect(gets(calls, URL_CMP).at(-1)?.params).toEqual({ statement: 'PROFIT_LOSS', mode: 'PRIOR_YEAR' }))

    await userEvent.selectOptions(screen.getByLabelText('Laporan'), 'BALANCE_SHEET')
    await waitFor(() => expect(gets(calls, URL_CMP).at(-1)?.params).toEqual({ statement: 'BALANCE_SHEET', mode: 'PRIOR_YEAR', as_of: '2026-10-08' }))
    const t = within(await screen.findByRole('table', { name: 'Baris komparatif neraca' }))
    expect(t.getByText('Kas').closest('tr')).toHaveTextContent('Kas7.200.000,006.000.000,001.200.000,0020,00%')
    expect(screen.getByLabelText('Per tanggal')).toBeInTheDocument()
    expect(screen.queryByLabelText('Dari')).not.toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: /Tahun sebelumnya/ })).toHaveTextContent(`Per ${formatDate('2025-10-08')}`)

    fireEvent.change(screen.getByLabelText('Per tanggal'), { target: { value: '2026-09-30' } })
    await waitFor(() => expect(gets(calls, URL_CMP).at(-1)?.params).toEqual({ statement: 'BALANCE_SHEET', mode: 'PRIOR_YEAR', as_of: '2026-09-30' }))
  })

  it('shows the accounts under each line when asked', async () => {
    boot(pnlView, routes())
    renderApp(PAGE)
    const t = within(await table())
    expect(t.queryByText('Penjualan Barang', { exact: false })).not.toBeInTheDocument()
    await userEvent.click(screen.getByLabelText('Tampilkan akun'))
    expect(t.getByText('Penjualan Barang', { exact: false }).closest('tr')).toHaveTextContent('800.000,002.000.000,00-1.200.000,00-60,00%')
  })

  it('offers the budget only for the profit and loss, to a user who may read budgets', async () => {
    boot(pnlView, routes())
    const first = renderApp(PAGE)
    await table()
    expect(within(screen.getByLabelText('Pembanding')).queryByRole('option', { name: 'Anggaran' })).not.toBeInTheDocument()
    first.unmount()

    boot(budgetView, routes({ [`GET ${RP}/budgets`]: { data: budgets } }), { modes: { ACCOUNTING_BUDGET: 'NONE' }})
    const second = renderApp(PAGE)
    await table()
    expect(within(screen.getByLabelText('Pembanding')).queryByRole('option', { name: 'Anggaran' })).not.toBeInTheDocument()
    second.unmount()

    boot([...budgetView, 'accounting.reporting.balance_sheet.view'], routes({ [`GET ${RP}/budgets`]: { data: budgets } }))
    renderApp(PAGE)
    await table()
    expect(within(screen.getByLabelText('Pembanding')).getByRole('option', { name: 'Anggaran' })).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Laporan'), 'BALANCE_SHEET')
    expect(within(screen.getByLabelText('Pembanding')).queryByRole('option', { name: 'Anggaran' })).not.toBeInTheDocument()
  })

  it('asks for nothing until a budget is chosen, then compares with it and lists the budget that could not be placed', async () => {
    const calls = boot(budgetView, routes({ [`GET ${RP}/budgets`]: { data: budgets }, [`GET ${URL_CMP}`]: { data: budgetReport() } }))
    renderApp(PAGE)
    await table()
    await userEvent.selectOptions(screen.getByLabelText('Pembanding'), 'BUDGET')
    expect(await screen.findByText('Pilih anggaran', { selector: 'strong' })).toBeInTheDocument()
    expect(screen.getByText(/Anggaran disimpan per periode akuntansi/)).toBeInTheDocument()
    expect(gets(calls, URL_CMP)).toHaveLength(1)
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()

    await userEvent.selectOptions(await screen.findByLabelText('Anggaran'), 'bg-1')
    await waitFor(() => expect(gets(calls, URL_CMP).at(-1)?.params).toEqual({ statement: 'PROFIT_LOSS', mode: 'BUDGET', budget_id: 'bg-1' }))
    const t = within(await table())
    expect(t.getByRole('columnheader', { name: /Anggaran/ })).toHaveTextContent('BGT-2026 · Anggaran 2026 · Versi awal')

    expect(screen.getByText(/1 akun beranggaran tidak berada pada tepat satu baris definisi/)).toBeInTheDocument()
    const unplaced = within(screen.getByRole('table', { name: 'Anggaran yang tidak tertempatkan pada satu baris' }))
    expect(unplaced.getByText('Beban Operasional', { exact: false })).toBeInTheDocument()
    expect(unplaced.getByText('700.000,00')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Pemeriksaan anggaran' })).toBeInTheDocument()
    expect(screen.getByText('3.000.000,00')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Pemeriksaan: sisi pembanding' })).not.toBeInTheDocument()
  })

  it('shows the refusal for a range that is not aligned with the budget periods', async () => {
    boot(budgetView, routes({ [`GET ${RP}/budgets`]: { data: budgets }, [`GET ${URL_CMP}`]: (r: { params?: Record<string, unknown> }) => (r.params?.mode === 'BUDGET'
      ? { status: 422, data: { code: 'BUDGET_RANGE_NOT_PERIOD_ALIGNED', message: 'x', details: {} } } : { data: comparative() }) }))
    renderApp(PAGE)
    await table()
    await userEvent.selectOptions(screen.getByLabelText('Pembanding'), 'BUDGET')
    await userEvent.selectOptions(await screen.findByLabelText('Anggaran'), 'bg-1')
    expect(await screen.findByText(/Anggaran disimpan per periode akuntansi: rentang harus dimulai di hari pertama/)).toBeInTheDocument()
  })

  it('shows a refusal of the statement or budget in Indonesian, and loads again on retry', async () => {
    let attempt = 0
    boot(pnlView, routes({ [`GET ${URL_CMP}`]: () => (++attempt === 1 ? { status: 403, data: { code: 'PERMISSION_DENIED', message: 'no', details: { permission: 'accounting.budget.view' } } } : { data: comparative() }) }))
    renderApp(PAGE)
    expect(await screen.findByText('Anda tidak memiliki izin untuk tindakan ini.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await table()).toBeInTheDocument()
  })

  it('exports the comparison with the choices on screen, for a user who may export', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const calls = boot([...pnlView, ...exporter], routes({ [`GET ${URL_CMP}/export`]: { data: 'csv' } }))
    renderApp(PAGE)
    await table()
    fireEvent.change(screen.getByLabelText('Dari'), { target: { value: '2026-02-01' } })
    await waitFor(() => expect(gets(calls, URL_CMP).at(-1)?.params).toEqual({ statement: 'PROFIT_LOSS', mode: 'PRIOR_PERIOD', from: '2026-02-01' }))
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${URL_CMP}/export`)).toBeDefined())
    expect(calls.find((c) => c.url === `${URL_CMP}/export`)!.params).toEqual({ statement: 'PROFIT_LOSS', mode: 'PRIOR_PERIOD', from: '2026-02-01' })
  })

  it('has no export for a user without the permission, keeps the view read-only and gates the page', async () => {
    boot(pnlView, routes(), { mode: 'READ_ONLY' })
    const first = renderApp(PAGE)
    expect(await table()).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    first.unmount()

    boot([], routes())
    renderApp(PAGE)
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
  })
})
