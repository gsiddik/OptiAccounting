import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../test/render'
import { RC, boot, component, exception, manage, matched, notApplicable, overview, page, result, run, storedRun, view, type Calls } from './testkit'

afterEach(() => vi.restoreAllMocks())

const OVERVIEW = '/app/akuntansi/rekonsiliasi'
const DETAIL = `${OVERVIEW}/jenis/ap-gl`
const URL_OVERVIEW = `${RC}/reconciliations`
const URL_AP = `${RC}/reconciliations/ap-gl`

const overviewRoutes = (over: Record<string, unknown> = {}) => ({ [`GET ${URL_OVERVIEW}`]: { data: overview() }, ...over })
const detailRoutes = (over: Record<string, unknown> = {}) => ({
  [`GET ${URL_AP}`]: { data: result() },
  [`GET ${RC}/reconciliation-exceptions`]: { data: page([exception()]) },
  [`GET ${RC}/reconciliations/runs`]: { data: page([storedRun()]) },
  ...over,
})
const gets = (calls: Calls, url: string) => calls.filter((c) => c.method === 'GET' && c.url === url)

describe('reconciliation overview', () => {
  it('shows every type the API returned with its status and the figures of the server', async () => {
    const calls = boot(view, overviewRoutes())
    renderApp(OVERVIEW)

    expect(await screen.findByRole('heading', { name: 'Rekonsiliasi' })).toBeInTheDocument()
    const table = within(await screen.findByRole('table', { name: 'Ringkasan rekonsiliasi per jenis' }))
    expect(table.getByRole('link', { name: 'Utang vs buku besar' })).toHaveAttribute('href', `${DETAIL}?as_of=2026-10-08`)
    expect(table.getByText('Piutang vs buku besar')).toBeInTheDocument()
    expect(table.getByText('Pajak vs buku besar')).toBeInTheDocument()
    expect(table.getByText('Selisih', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Cocok', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Tidak berlaku', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('700.000,00')).toBeInTheDocument()
    expect(table.getByText('705.000,00')).toBeInTheDocument()
    expect(table.getByText('-5.000,00')).toBeInTheDocument()
    expect(screen.getByText('1 rekonsiliasi berselisih')).toBeInTheDocument()
    expect(screen.queryByText(/Sebagian rekonsiliasi tidak lengkap/)).not.toBeInTheDocument()
    expect(calls.find((c) => c.url === URL_OVERVIEW)!.params).toEqual({ as_of: '2026-10-08' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
  })

  it('confirms a clean day without inventing anything', async () => {
    boot(view, overviewRoutes({ [`GET ${URL_OVERVIEW}`]: { data: overview([matched('AP_GL'), matched('AR_GL'), notApplicable('FX_GL')]) } }))
    renderApp(OVERVIEW)
    expect(await screen.findByText(/Semua rekonsiliasi yang berlaku sama dengan buku besar/)).toBeInTheDocument()
    expect(screen.queryByText(/berselisih/)).not.toBeInTheDocument()
  })

  it('links to the pre-OA5 page of a type only for a user who holds its permission', async () => {
    boot([...view, 'accounting.reconciliation.ap.view'], overviewRoutes())
    renderApp(OVERVIEW)
    const link = await screen.findByRole('link', { name: 'Halaman khusus Utang vs buku besar' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/rekonsiliasi/utang')
    expect(screen.queryByRole('link', { name: 'Halaman khusus Piutang vs buku besar' })).not.toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: /^Rincian / })).toHaveLength(3)
  })

  it('warns that an incomplete comparison does not prove a match', async () => {
    const partial = result({ status: 'INCOMPLETE', complete: false, source_balance: null, gl_balance: null, difference: null, components: [] })
    boot(view, overviewRoutes({ [`GET ${URL_OVERVIEW}`]: { data: overview([partial]) } }))
    renderApp(OVERVIEW)
    expect(await screen.findByText(/Sebagian rekonsiliasi tidak lengkap/)).toBeInTheDocument()
    expect(screen.getByText('Tidak lengkap', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.queryByText(/berselisih/)).not.toBeInTheDocument()
  })

  it('says so when no reconciliation is available', async () => {
    boot(view, overviewRoutes({ [`GET ${URL_OVERVIEW}`]: { data: overview([]) } }))
    renderApp(OVERVIEW)
    expect(await screen.findByText('Belum ada rekonsiliasi')).toBeInTheDocument()
  })

  it('shows a refusal in Indonesian and loads again on retry', async () => {
    let attempt = 0
    boot(view, overviewRoutes({ [`GET ${URL_OVERVIEW}`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: overview() }) }))
    renderApp(OVERVIEW)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('table', { name: 'Ringkasan rekonsiliasi per jenis' })).toBeInTheDocument()
    expect(attempt).toBe(2)
  })

  it('asks the server again for the date the user picks and carries it to the detail link', async () => {
    const calls = boot(view, overviewRoutes())
    renderApp(OVERVIEW)
    await screen.findByRole('table', { name: 'Ringkasan rekonsiliasi per jenis' })

    fireEvent.change(screen.getByLabelText('Per tanggal'), { target: { value: '2026-09-30' } })
    await waitFor(() => expect(gets(calls, URL_OVERVIEW).at(-1)?.params).toEqual({ as_of: '2026-09-30' }))
  })

  it('offers the accounting periods only to a user who may see them, and sends the period instead of a date', async () => {
    const years = { data: [{ id: 'fy-1', code: 'FY2026', name: 'TF 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN', periods: [
      { id: 'p-9', fiscal_year_id: 'fy-1', number: 9, code: '2026-09', name: 'September 2026', start_date: '2026-09-01', end_date: '2026-09-30', status: 'OPEN' },
    ] }] }
    const without = boot(view, overviewRoutes())
    const first = renderApp(OVERVIEW)
    await screen.findByRole('table', { name: 'Ringkasan rekonsiliasi per jenis' })
    expect(screen.queryByLabelText('Periode')).not.toBeInTheDocument()
    expect(without.some((c) => c.url === `${RC}/fiscal-years`)).toBe(false)
    first.unmount()

    const calls = boot([...view, 'accounting.period.view'], overviewRoutes({ [`GET ${RC}/fiscal-years`]: { data: years } }))
    renderApp(OVERVIEW)
    await userEvent.selectOptions(await screen.findByLabelText('Periode'), 'p-9')
    await waitFor(() => expect(gets(calls, URL_OVERVIEW).at(-1)?.params).toEqual({ period_id: 'p-9' }))
    expect(screen.queryByLabelText('Per tanggal')).not.toBeInTheDocument()
    expect(await screen.findByRole('link', { name: 'Utang vs buku besar' })).toHaveAttribute('href', `${DETAIL}?period_id=p-9`)
  })

  it('offers the export only to a user who may export, for the chosen date', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const calls = boot([...view, 'accounting.report.export'], overviewRoutes({ [`GET ${RC}/reconciliations/export`]: { data: 'csv' } }))
    renderApp(OVERVIEW)
    await screen.findByRole('table', { name: 'Ringkasan rekonsiliasi per jenis' })
    fireEvent.change(screen.getByLabelText('Per tanggal'), { target: { value: '2026-09-30' } })
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${RC}/reconciliations/export`)).toBeDefined())
    expect(calls.find((c) => c.url === `${RC}/reconciliations/export`)!.params).toEqual({ as_of: '2026-09-30' })
  })

  it('explains a read-only subscription and still shows the views', async () => {
    boot(view, overviewRoutes(), { mode: 'READ_ONLY' })
    renderApp(OVERVIEW)
    expect(await screen.findByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(await screen.findByRole('table', { name: 'Ringkasan rekonsiliasi per jenis' })).toBeInTheDocument()
  })
})

describe('reconciliation detail', () => {
  it('shows the result, its components with the informational ones marked, the notes and the tolerance', async () => {
    const calls = boot(view, detailRoutes())
    renderApp(DETAIL)

    expect(await screen.findByRole('heading', { name: 'Utang vs buku besar' })).toBeInTheDocument()
    expect(await screen.findByText('Selisih.')).toBeInTheDocument()
    expect(calls.find((c) => c.url === URL_AP)!.params).toEqual({ as_of: '2026-10-08' })

    const table = within(screen.getByRole('table', { name: 'Komponen Utang vs buku besar' }))
    expect(table.getByText('Sub-buku utang terhadap akun kontrol utang usaha')).toBeInTheDocument()
    expect(table.getByText('700.000,00')).toBeInTheDocument()
    expect(table.getAllByText('705.000,00')).toHaveLength(2)
    expect(table.getByText('Akun kontrol (seluruh saldo buku besar, tidak dibandingkan)')).toBeInTheDocument()
    expect(table.getByText('2110 · Utang Usaha')).toBeInTheDocument()
    expect(table.getByText('Informasi', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Selisih', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText('Compared: open AP documents as of the date against the posted ledger lines.')).toBeInTheDocument()
    expect(screen.getByText(/toleransi/)).toBeInTheDocument()
    expect(screen.getByText('0,00', { selector: '.money' })).toBeInTheDocument()

    expect(calls.find((c) => c.url === `${RC}/reconciliation-exceptions`)!.params).toEqual({ type: 'ap-gl', as_of_from: '2026-10-08', as_of_to: '2026-10-08', per_page: 20 })
    expect(calls.find((c) => c.url === `${RC}/reconciliations/runs`)!.params).toEqual({ type: 'ap-gl', per_page: 10 })
  })

  it('takes the cut-off from the link it was opened with', async () => {
    const calls = boot(view, detailRoutes({ [`GET ${URL_AP}`]: { data: result({ as_of: '2026-09-30' }) } }))
    renderApp(`${DETAIL}?as_of=2026-09-30`)
    expect(await screen.findByText('Selisih.')).toBeInTheDocument()
    expect(calls.find((c) => c.url === URL_AP)!.params).toEqual({ as_of: '2026-09-30' })
    expect((screen.getByLabelText('Per tanggal') as HTMLInputElement).value).toBe('2026-09-30')
  })

  it('says that a clean comparison matched, and that an incomplete one proves nothing', async () => {
    boot(view, detailRoutes({ [`GET ${URL_AP}`]: { data: matched('AP_GL') } }))
    const first = renderApp(DETAIL)
    expect(await screen.findByText('Cocok.')).toBeInTheDocument()
    expect(screen.queryByText('Selisih.')).not.toBeInTheDocument()
    first.unmount()

    boot(view, detailRoutes({ [`GET ${URL_AP}`]: { data: result({ status: 'INCOMPLETE', complete: false, source_balance: null, gl_balance: null, difference: null, components: [] }) } }))
    renderApp(DETAIL)
    expect(await screen.findByText('Tidak lengkap.')).toBeInTheDocument()
    expect(screen.getByText(/Perbandingan ini tidak lengkap/)).toBeInTheDocument()
    expect(screen.getByText('Belum ada komponen')).toBeInTheDocument()
  })

  it('lists the rows behind a component: counterparties of a subledger and tax codes of a tax component', async () => {
    const ap = component({
      breakdown: [{ code: 'V1', name: 'PT Pemasok', source_balance: '700000.0000', gl_balance: '705000.0000', difference: '-5000.0000' }], breakdown_total: 61, breakdown_truncated: true,
    })
    const first = boot(view, detailRoutes({ [`GET ${URL_AP}`]: { data: result({ components: [ap] }) } }))
    const render = renderApp(DETAIL)
    expect(await screen.findByText('Rincian: Sub-buku utang terhadap akun kontrol utang usaha (61)')).toBeInTheDocument()
    const rows = within(screen.getByRole('table', { name: 'Rincian Sub-buku utang terhadap akun kontrol utang usaha', hidden: true }))
    expect(rows.getByText('V1 · PT Pemasok')).toBeInTheDocument()
    expect(screen.getByText(/Menampilkan 1 dari 61 baris yang berselisih/)).toBeInTheDocument()
    expect(first.length).toBeGreaterThan(0)
    render.unmount()

    const tax = component({ code: 'TAX_OUTPUT', label: 'Output tax', breakdown: [{ tax_code: 'PPN11', tax_name: 'PPN 11%', tax_amount: '110000.0000', transactions: 4 }] })
    boot(view, detailRoutes({ [`GET ${RC}/reconciliations/tax-gl`]: { data: result({ type: 'TAX_GL', components: [tax] }) }, [`GET ${RC}/reconciliation-exceptions`]: { data: page([]) } }))
    renderApp(`${OVERVIEW}/jenis/tax-gl`)
    expect(await screen.findByText('Rincian: Pajak keluaran tercatat terhadap akun kontrol pajak (1)')).toBeInTheDocument()
    expect(screen.getByText('PPN11')).toBeInTheDocument()
    expect(screen.getByText('110.000,00')).toBeInTheDocument()
  })

  it('refuses an unknown type without asking the server for it', async () => {
    const calls = boot(view, detailRoutes())
    renderApp(`${OVERVIEW}/jenis/tidak-ada`)
    expect(await screen.findByText('Jenis rekonsiliasi tidak dikenal')).toBeInTheDocument()
    expect(calls.some((c) => c.url.includes('/reconciliations/'))).toBe(false)
  })

  it('explains a type the subscription does not include', async () => {
    boot(view, detailRoutes({ [`GET ${URL_AP}`]: { status: 403, data: { message: 'no', code: 'MODULE_NOT_ENTITLED' } } }))
    renderApp(DETAIL)
    expect(await screen.findByText('Modul ini tidak termasuk dalam langganan.')).toBeInTheDocument()
    expect(screen.queryByText('Selisih.')).not.toBeInTheDocument()
  })

  it('exports the type for the chosen date, only for a user who may export', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    boot(view, detailRoutes())
    const first = renderApp(DETAIL)
    await screen.findByText('Selisih.')
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    first.unmount()

    const calls = boot([...view, 'accounting.report.export'], detailRoutes({ [`GET ${URL_AP}/export`]: { data: 'csv' } }))
    renderApp(DETAIL)
    await screen.findByText('Selisih.')
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${URL_AP}/export`)).toBeDefined())
    expect(calls.find((c) => c.url === `${URL_AP}/export`)!.params).toEqual({ as_of: '2026-10-08' })
  })

  it('lists the recent runs as evidence and says when there are none', async () => {
    boot(view, detailRoutes())
    const first = renderApp(DETAIL)
    const table = within(await screen.findByRole('table', { name: 'Hasil rekonsiliasi tersimpan' }))
    expect(table.getByText('Manual')).toBeInTheDocument()
    expect(table.getByText('Selisih', { selector: '.badge' })).toBeInTheDocument()
    first.unmount()

    boot(view, detailRoutes({ [`GET ${RC}/reconciliations/runs`]: { data: page([]) } }))
    renderApp(DETAIL)
    expect(await screen.findByText('Belum ada hasil tersimpan')).toBeInTheDocument()
  })
})

describe('running and saving a reconciliation', () => {
  it('offers the run only with the permission and a writable module', async () => {
    boot(view, detailRoutes())
    const first = renderApp(DETAIL)
    await screen.findByText('Selisih.')
    expect(screen.queryByRole('button', { name: 'Jalankan & simpan' })).not.toBeInTheDocument()
    first.unmount()

    boot(run, detailRoutes(), { mode: 'READ_ONLY' })
    const second = renderApp(DETAIL)
    await screen.findByText('Selisih.')
    expect(screen.queryByRole('button', { name: 'Jalankan & simpan' })).not.toBeInTheDocument()
    second.unmount()

    boot(run, detailRoutes(), { modes: { ACCOUNTING_REPORTING: 'READ_ONLY' } })
    const third = renderApp(DETAIL)
    await screen.findByText('Selisih.')
    expect(screen.queryByRole('button', { name: 'Jalankan & simpan' })).not.toBeInTheDocument()
    third.unmount()

    boot(run, detailRoutes())
    renderApp(DETAIL)
    expect(await screen.findByRole('button', { name: 'Jalankan & simpan' })).toBeEnabled()
  })

  it('posts the cut-off, shows the stored run and the refreshed exception, and reads the page again', async () => {
    const stored = storedRun({ id: 'run-77', difference: '-5000.0000' })
    const calls = boot(run, detailRoutes({ [`POST ${URL_AP}/run`]: { status: 201, data: { run: stored, result: result(), exception: exception({ id: 'ex-9', status: 'OPEN' }) } } }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Jalankan & simpan' }))

    expect(await screen.findByText('Hasil jalankan tersimpan')).toBeInTheDocument()
    const post = calls.find((c) => c.method === 'POST')!
    expect(post.url).toBe(`${URL_AP}/run`)
    expect(post.data).toEqual({ as_of: '2026-10-08' })
    expect(screen.getByText('run-77')).toBeInTheDocument()
    expect(screen.getByText(/Pengecualian dibuka atau diperbarui dengan angka terbaru/)).toBeInTheDocument()
    expect(await screen.findByText('Hasil rekonsiliasi disimpan.')).toBeInTheDocument()
    await waitFor(() => expect(gets(calls, URL_AP).length).toBeGreaterThan(1))
    expect(gets(calls, `${RC}/reconciliation-exceptions`).length).toBeGreaterThan(1)
  })

  it('sends the period when one was picked, and reports an automatic resolution', async () => {
    const years = { data: [{ id: 'fy-1', code: 'FY2026', name: 'TF 2026', start_date: '2026-01-01', end_date: '2026-12-31', status: 'OPEN', periods: [
      { id: 'p-9', fiscal_year_id: 'fy-1', number: 9, code: '2026-09', name: 'September 2026', start_date: '2026-09-01', end_date: '2026-09-30', status: 'OPEN' },
    ] }] }
    const resolved = exception({ status: 'RESOLVED', calculated_status: 'MATCHED', difference: '0.0000' })
    const calls = boot([...run, 'accounting.period.view'], detailRoutes({
      [`GET ${RC}/fiscal-years`]: { data: years },
      [`POST ${URL_AP}/run`]: { status: 201, data: { run: storedRun({ status: 'MATCHED', difference: '0.0000' }), result: matched('AP_GL'), exception: resolved } },
    }))
    renderApp(DETAIL)
    await userEvent.selectOptions(await screen.findByLabelText('Periode'), 'p-9')
    await userEvent.click(screen.getByRole('button', { name: 'Jalankan & simpan' }))

    expect(await screen.findByText(/diselesaikan otomatis/)).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ period_id: 'p-9' })
  })

  it('shows the refusal of the server and keeps the page as it was', async () => {
    boot(run, detailRoutes({ [`POST ${URL_AP}/run`]: { status: 403, data: { message: 'no', code: 'MODULE_NOT_ENTITLED' } } }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Jalankan & simpan' }))
    expect(await screen.findByText('Modul ini tidak termasuk dalam langganan.')).toBeInTheDocument()
    expect(screen.queryByText('Hasil jalankan tersimpan')).not.toBeInTheDocument()
    expect(screen.getByText('Selisih.')).toBeInTheDocument()
  })
})

describe('exception panel on the detail page', () => {
  const explained = exception({ status: 'EXPLAINED', owner_id: 'u-9', owner: { id: 'u-9', name: 'Sari Dewi' }, notes: 'Jurnal manual salah akun, akan dikoreksi', explained_at: '2026-10-08T05:00:00Z' })
  const withException = (over: Record<string, unknown> = {}) => detailRoutes({ [`GET ${RC}/reconciliation-exceptions`]: { data: page([explained]) }, ...over })

  it('shows the work item and states that explaining never changes the calculated status', async () => {
    boot(view, withException())
    renderApp(DETAIL)

    expect(await screen.findByText(/Menjelaskan selisih tidak mengubah status hitung/)).toBeInTheDocument()
    expect(screen.getByText('Dijelaskan', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText('Sari Dewi')).toBeInTheDocument()
    expect(screen.getByText('Jurnal manual salah akun, akan dikoreksi')).toBeInTheDocument()
    // The calculated status stays what the live computation says.
    expect(screen.getAllByText('Selisih', { selector: '.badge' }).length).toBeGreaterThan(0)
    expect(screen.queryByRole('button', { name: 'Jelaskan' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tetapkan pemilik' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Selesaikan' })).not.toBeInTheDocument()
  })

  it('offers the actions to a user who may manage exceptions, but not in a read-only subscription', async () => {
    boot(manage, withException())
    const first = renderApp(DETAIL)
    expect(await screen.findByRole('button', { name: 'Tetapkan pemilik' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Jelaskan' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Selesaikan' })).toBeInTheDocument()
    first.unmount()

    boot(manage, withException(), { mode: 'READ_ONLY' })
    renderApp(DETAIL)
    await screen.findByText('Sari Dewi')
    expect(screen.queryByRole('button', { name: 'Tetapkan pemilik' })).not.toBeInTheDocument()
  })

  it('explains the difference: posts the notes, closes the dialog and reads the exception again', async () => {
    const calls = boot(manage, withException({ [`POST ${RC}/reconciliation-exceptions/ex-1/explain`]: { data: explained } }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Jelaskan' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/Penjelasan tidak mengubah status hitung maupun angkanya/)).toBeInTheDocument()
    const notes = within(dialog).getByLabelText('Penjelasan')
    await userEvent.clear(notes)
    await userEvent.type(notes, 'Jurnal manual salah akun')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan penjelasan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ notes: 'Jurnal manual salah akun' })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await screen.findByText('Penjelasan dicatat. Status hitung tidak berubah.')).toBeInTheDocument()
    await waitFor(() => expect(gets(calls, `${RC}/reconciliation-exceptions`).length).toBeGreaterThan(1))
  })

  it('does not send an explanation that is too short', async () => {
    const calls = boot(manage, withException())
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Jelaskan' }))
    const dialog = await screen.findByRole('dialog')
    const notes = within(dialog).getByLabelText('Penjelasan')
    await userEvent.clear(notes)
    await userEvent.type(notes, 'ok')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan penjelasan' }))
    expect(await within(dialog).findByText('Isi minimal 3 karakter.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('assigns the owner chosen among the active members', async () => {
    const members = { data: [{ id: 'm-1', status: 'ACTIVE', joined_at: null, roles: [], user: { id: 'u-9', name: 'Sari Dewi', email: 'sari@majujaya.test', status: 'ACTIVE' } }], current_page: 1, last_page: 1, total: 1, per_page: 25 }
    const calls = boot([...manage, 'access.user.view'], withException({ 'GET /app/users': { data: members }, [`POST ${RC}/reconciliation-exceptions/ex-1/assign`]: { data: explained } }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Tetapkan pemilik' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.selectOptions(await within(dialog).findByLabelText('Pemilik'), 'u-9')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tetapkan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.url).toBe(`${RC}/reconciliation-exceptions/ex-1/assign`)
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ owner_id: 'u-9' })
    expect(calls.find((c) => c.url === '/app/users')!.params).toEqual({ status: 'ACTIVE' })
    expect(await screen.findByText('Pemilik pengecualian ditetapkan.')).toBeInTheDocument()
  })

  it('lets a user who cannot list members assign the exception to themselves, and asks for a choice first', async () => {
    const calls = boot(manage, withException({
      [`GET ${RC}/reconciliation-exceptions`]: { data: page([exception()]) },
      [`POST ${RC}/reconciliation-exceptions/ex-1/assign`]: { data: explained },
    }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Tetapkan pemilik' }))
    const dialog = await screen.findByRole('dialog')
    expect(calls.some((c) => c.url === '/app/users')).toBe(false)
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tetapkan' }))
    expect(await within(dialog).findByText('Pilih pemilik.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)

    await userEvent.selectOptions(within(dialog).getByLabelText('Pemilik'), 'u-t')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tetapkan' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ owner_id: 'u-t' })
  })

  it('shows plainly that a still-mismatched reconciliation cannot be resolved, keeps the dialog and reads fresh numbers', async () => {
    const refusal = { message: 'The reconciliation still does not match', code: 'RECON_STILL_MISMATCHED', details: { type: 'AP_GL', as_of: '2026-10-08', status: 'MISMATCH', difference: '-5000.0000' } }
    const calls = boot(manage, withException({ [`POST ${RC}/reconciliation-exceptions/ex-1/resolve`]: { status: 409, data: refusal } }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Selesaikan' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Catatan penyelesaian'), 'Sudah dikoreksi')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Hitung ulang & selesaikan' }))

    const alert = await within(dialog).findByRole('alert')
    expect(alert).toHaveTextContent('Rekonsiliasi masih tidak cocok setelah dihitung ulang')
    expect(alert).toHaveTextContent('-5.000,00')
    expect(alert).toHaveTextContent('penjelasan tidak mengubah hasil')
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ note: 'Sudah dikoreksi' })
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    // The server recomputed and kept the verification, so the page reads again.
    await waitFor(() => expect(gets(calls, URL_AP).length).toBeGreaterThan(1))
  })

  it('resolves once the server recomputation matches', async () => {
    const done = exception({ status: 'RESOLVED', calculated_status: 'MATCHED', difference: '0.0000', resolution_note: 'Dikoreksi dengan jurnal balik' })
    const calls = boot(manage, withException({ [`POST ${RC}/reconciliation-exceptions/ex-1/resolve`]: { data: done } }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Selesaikan' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText('Catatan penyelesaian'), 'Dikoreksi dengan jurnal balik')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Hitung ulang & selesaikan' }))

    expect(await screen.findByText('Pengecualian diselesaikan: hitung ulang menunjukkan Cocok.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ note: 'Dikoreksi dengan jurnal balik' })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('shows resolved exceptions as history and points to running the comparison when none is open', async () => {
    const done = exception({ status: 'RESOLVED', calculated_status: 'MATCHED', resolved_at: '2026-10-07T09:00:00Z', resolution_note: 'Dikoreksi dengan jurnal balik' })
    boot(run, withException({ [`GET ${RC}/reconciliation-exceptions`]: { data: page([done]) } }))
    renderApp(DETAIL)
    expect(await screen.findByText('Tidak ada pengecualian aktif')).toBeInTheDocument()
    expect(screen.getByText(/Jalankan & simpan untuk membuka pengecualian/)).toBeInTheDocument()
    expect(screen.getByText(/Dikoreksi dengan jurnal balik/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Selesaikan' })).not.toBeInTheDocument()
  })

  it('shows an error with retry when the exceptions cannot be read', async () => {
    boot(view, withException({ [`GET ${RC}/reconciliation-exceptions`]: { status: 500, data: { message: 'boom' } } }))
    renderApp(DETAIL)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
    expect(screen.getByText('Selisih.')).toBeInTheDocument()
  })
})
