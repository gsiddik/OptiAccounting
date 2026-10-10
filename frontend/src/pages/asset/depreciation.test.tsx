import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { AP, boot, postedRun, run } from './testkit'

afterEach(() => vi.restoreAllMocks())

const view = ['accounting.asset.view']
const periods = {
  data: [
    {
      id: 'fy-1', code: 'FY2026', name: 'Tahun 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN',
      periods: [
        { id: 'p-09', fiscal_year_id: 'fy-1', number: 9, code: '2026-09', name: 'September 2026', start_date: '2026-09-01', end_date: '2026-09-30', status: 'CLOSED' },
        { id: 'p-10', fiscal_year_id: 'fy-1', number: 10, code: '2026-10', name: 'Oktober 2026', start_date: '2026-10-01', end_date: '2026-10-31', status: 'OPEN' },
        { id: 'p-11', fiscal_year_id: 'fy-1', number: 11, code: '2026-11', name: 'November 2026', start_date: '2026-11-01', end_date: '2026-11-30', status: 'FUTURE' },
      ],
    },
  ],
}

describe('depreciation runs', () => {
  const runner = [...view, 'accounting.asset.depreciation.run']
  const lists = (rows: unknown[]) => ({ 'GET /app/accounting/depreciation-runs': { data: page(rows) }, 'GET /app/accounting/fiscal-years': { data: periods } })

  it('lists the runs with the totals the server computed and hides the calculate button without the permission', async () => {
    boot(view, lists([postedRun(), run({ id: 'r-2' })]))
    renderApp('/app/akuntansi/penyusutan')

    expect(await screen.findByRole('link', { name: 'DEP-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/penyusutan/r-1')
    expect(screen.getByRole('link', { name: 'Draf' })).toHaveAttribute('href', '/app/akuntansi/penyusutan/r-2')
    expect(screen.getAllByText('4.500.000,00').length).toBe(2)
    expect(screen.queryByRole('button', { name: 'Hitung penyusutan' })).not.toBeInTheDocument()
  })

  it('hides the calculate button in a read-only subscription', async () => {
    boot(runner, lists([postedRun()]), { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/penyusutan')
    await screen.findByRole('link', { name: 'DEP-FY2026-000001' })
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Hitung penyusutan' })).not.toBeInTheDocument()
  })

  it('sends the filters to the server', async () => {
    const calls = boot(view, lists([postedRun()]))
    renderApp('/app/akuntansi/penyusutan')
    await screen.findByRole('link', { name: 'DEP-FY2026-000001' })

    await userEvent.selectOptions(screen.getByLabelText('Status proses'), 'POSTED')
    fireEvent.change(screen.getByLabelText('Tanggal posting dari'), { target: { value: '2026-10-01' } })
    await userEvent.type(screen.getByLabelText('Cari proses penyusutan'), 'okt')
    await waitFor(() => expect(calls.filter((c) => c.url === `${AP}/depreciation-runs`).at(-1)?.params).toEqual({ page: 1, status: 'POSTED', posting_from: '2026-10-01', q: 'okt' }))
  })

  it('calculates a run for an open period and opens the draft it returns', async () => {
    const calls = boot(runner, { ...lists([]), 'POST /app/accounting/depreciation-runs': { status: 201, data: run() }, 'GET /app/accounting/depreciation-runs/r-1': { data: run() } })
    renderApp('/app/akuntansi/penyusutan')
    await userEvent.click(await screen.findByRole('button', { name: 'Hitung penyusutan' }))

    const dialog = within(screen.getByRole('dialog', { name: 'Hitung penyusutan' }))
    // A closed period and a future period cannot be calculated, so they are not offered.
    await waitFor(() => expect(dialog.getAllByRole('option').map((o) => o.textContent)).toEqual(['Pilih periode…', '2026-10 · Oktober 2026']))
    await userEvent.selectOptions(dialog.getByLabelText('Periode'), 'p-10')
    await userEvent.type(dialog.getByLabelText('Deskripsi'), 'Penyusutan Oktober')
    await userEvent.click(dialog.getByRole('button', { name: 'Hitung penyusutan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/depreciation-runs`)).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ accounting_period_id: 'p-10', posting_date: null, description: 'Penyusutan Oktober', reference: null })
    expect(await screen.findByRole('heading', { name: 'Draf penyusutan 2026-10' })).toBeInTheDocument()
  })

  it('sends an explicit posting date and shows the refusal when nothing is eligible', async () => {
    const calls = boot(runner, {
      ...lists([]),
      'POST /app/accounting/depreciation-runs': { status: 422, data: { message: 'Nothing.', code: 'DEPRECIATION_NOTHING_ELIGIBLE', details: { period: '2026-10' } } },
    })
    renderApp('/app/akuntansi/penyusutan')
    await userEvent.click(await screen.findByRole('button', { name: 'Hitung penyusutan' }))
    const dialog = within(screen.getByRole('dialog'))
    await waitFor(() => expect(dialog.getAllByRole('option').length).toBe(2))
    await userEvent.selectOptions(dialog.getByLabelText('Periode'), 'p-10')
    fireEvent.change(dialog.getByLabelText('Tanggal posting'), { target: { value: '2026-10-30' } })
    await userEvent.click(dialog.getByRole('button', { name: 'Hitung penyusutan' }))

    expect(await dialog.findByText('Tidak ada aset yang perlu disusutkan sampai periode ini.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')!.data).toMatchObject({ posting_date: '2026-10-30' })
  })

  it('shows an empty state', async () => {
    boot(runner, lists([]))
    renderApp('/app/akuntansi/penyusutan')
    expect(await screen.findByText('Belum ada proses penyusutan')).toBeInTheDocument()
  })
})

describe('depreciation run detail', () => {
  const all = [...view, 'accounting.asset.depreciation.run', 'accounting.asset.depreciation.post']
  const routes = (r: unknown) => ({ 'GET /app/accounting/depreciation-runs/r-1': { data: r } })
  const buttons = () => screen.queryAllByRole('button').map((b) => b.textContent)

  it('shows the per-asset amounts exactly as the server calculated them, with post and cancel on a draft', async () => {
    boot(all, routes(run()))
    renderApp('/app/akuntansi/penyusutan/r-1')

    expect(await screen.findByRole('heading', { name: 'Draf penyusutan 2026-10' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'FA-FY2026-000001 · Truk Hino' })).toHaveAttribute('href', '/app/akuntansi/aset/as-1')
    expect(screen.getAllByText('4.500.000,00').length).toBeGreaterThan(1) // run total and line amount
    expect(screen.getByText('115.500.000,00')).toBeInTheDocument() // book value after, from the API
    expect(screen.getByRole('button', { name: 'Posting' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Batalkan' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Balik' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ajukan' })).not.toBeInTheDocument() // a calculated draft is the review step; there is no approval
  })

  it('posts a draft through a confirmation', async () => {
    const calls = boot(all, { ...routes(run()), 'POST /app/accounting/depreciation-runs/r-1/post': { data: postedRun() } })
    renderApp('/app/akuntansi/penyusutan/r-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Posting' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Posting' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${AP}/depreciation-runs/r-1/post`)).toBe(true))
    expect(await screen.findByText('Proses penyusutan diposting.')).toBeInTheDocument()
  })

  it('shows the refusal in the dialog when the run is stale', async () => {
    boot(all, { ...routes(run()), 'POST /app/accounting/depreciation-runs/r-1/post': { status: 409, data: { message: 'stale', code: 'DEPRECIATION_RUN_STALE', details: { asset: 'FA-1' } } } })
    renderApp('/app/akuntansi/penyusutan/r-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Posting' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.click(dialog.getByRole('button', { name: 'Posting' }))
    expect(await dialog.findByText('Data aset berubah sejak proses ini dihitung. Batalkan lalu hitung ulang.')).toBeInTheDocument()
  })

  it('disables posting with the reason when the server says segregation of duties forbids it', async () => {
    boot(all, routes(run({ sod: { approve: false, post: false } })))
    renderApp('/app/akuntansi/penyusutan/r-1')
    expect(await screen.findByRole('button', { name: 'Posting' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat memposting/)).toBeInTheDocument()
  })

  it('cancels a draft with a required reason', async () => {
    const calls = boot(all, { ...routes(run()), 'POST /app/accounting/depreciation-runs/r-1/cancel': { data: run({ status: 'CANCELLED' }) } })
    renderApp('/app/akuntansi/penyusutan/r-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Batalkan' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.click(dialog.getByRole('button', { name: 'Batalkan proses penyusutan' }))
    expect(calls.some((c) => c.url.endsWith('/cancel'))).toBe(false)
    await userEvent.type(dialog.getByLabelText(/Alasan/), 'Salah periode')
    await userEvent.click(dialog.getByRole('button', { name: 'Batalkan proses penyusutan' }))
    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/cancel'))?.data).toEqual({ reason: 'Salah periode' }))
  })

  it('reverses a posted run with a reason and posting date and shows the refusal for a later run', async () => {
    const calls = boot(all, {
      ...routes(postedRun()),
      'POST /app/accounting/depreciation-runs/r-1/reverse': { status: 409, data: { message: 'later', code: 'DEPRECIATION_RUN_NOT_LAST', details: { asset: 'FA-1' } } },
    })
    renderApp('/app/akuntansi/penyusutan/r-1')
    expect(await screen.findByRole('link', { name: 'Lihat jurnal' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-20')
    for (const label of ['Posting', 'Batalkan']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Balik' }))
    const dialog = within(screen.getByRole('dialog', { name: 'Balik proses penyusutan' }))
    await userEvent.type(dialog.getByLabelText(/Alasan/), 'Salah hitung')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))

    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/reverse'))?.data).toEqual({ reason: 'Salah hitung', posting_date: '2026-10-08' }))
    expect(await dialog.findByText('Pembalikan dilakukan dari proses terbaru terlebih dahulu: ada proses yang lebih baru untuk aset yang sama.')).toBeInTheDocument()
  })

  it('offers no action to a viewer or in a read-only subscription', async () => {
    boot(view, routes(run()))
    const first = renderApp('/app/akuntansi/penyusutan/r-1')
    await screen.findByRole('heading', { name: 'Draf penyusutan 2026-10' })
    expect(buttons().filter((b) => ['Posting', 'Batalkan', 'Balik'].includes(b ?? ''))).toEqual([])
    first.unmount()

    boot(all, routes(postedRun()), { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/penyusutan/r-1')
    await screen.findByRole('heading', { name: 'DEP-FY2026-000001' })
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(buttons().filter((b) => ['Posting', 'Batalkan', 'Balik'].includes(b ?? ''))).toEqual([])
  })

  it('shows the status history and the cancel reason', async () => {
    boot(all, routes(run({ status: 'CANCELLED', cancel_reason: 'Salah periode' })))
    renderApp('/app/akuntansi/penyusutan/r-1')
    expect(await screen.findByText('Dibatalkan: Salah periode')).toBeInTheDocument()
    const history = within(screen.getByRole('heading', { name: 'Riwayat' }).closest('section')!)
    expect(history.getByText(/Budi Santoso/)).toBeInTheDocument()
  })
})
