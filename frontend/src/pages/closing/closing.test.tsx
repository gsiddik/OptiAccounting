import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../test/render'
import { CL, boot, check, event, gets, history, overviewData, page, view, years, yearView } from './testkit'

afterEach(() => vi.restoreAllMocks())

const OVERVIEW = '/app/akuntansi/penutupan'
const HISTORY = `${OVERVIEW}/riwayat`
const URL_OVERVIEW = `${CL}/closing/overview`
const URL_HISTORY = `${CL}/closing/history`
const URL_YEARS = `${CL}/fiscal-years`

const main = () => within(screen.getByRole('main'))

describe('closing overview', () => {
  const routes = (over: Record<string, unknown> = {}) => ({ [`GET ${URL_OVERVIEW}`]: { data: overviewData() }, ...over })

  it('lists every period with its state, the readiness of the server, the counts and the last action', async () => {
    const calls = boot(view, routes())
    renderApp(OVERVIEW)

    expect(await screen.findByRole('heading', { name: 'Penutupan periode' })).toBeInTheDocument()
    const table = within(await screen.findByRole('table', { name: 'Periode akuntansi dan kesiapan penutupannya' }))
    expect(table.getByRole('link', { name: '2026-09' })).toHaveAttribute('href', '/app/akuntansi/penutupan/periode/p-9')
    expect(table.getByRole('link', { name: 'Buka periode 2026-09' })).toBeInTheDocument()
    expect(table.getByText('Ditutup', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getAllByText('Terbuka', { selector: '.badge' })).toHaveLength(2)
    expect(table.getByText('Belum siap', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('PENDING_JOURNALS, AP_RECONCILIATION')).toBeInTheDocument()
    expect(table.getByText('DRAFT_JOURNALS')).toBeInTheDocument()
    // The closed period and the one that has not ended carry no readiness; the page says why instead of inventing one.
    expect(table.getByText('Sudah ditutup')).toBeInTheDocument()
    expect(table.getByText('Periode belum berakhir')).toBeInTheDocument()
    expect(table.getByText('Tutup periode', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText(/Sari Dewi/)).toBeInTheDocument()
    expect(table.getByText(/Pra-tutup terakhir/)).toBeInTheDocument()
    expect(calls.filter((c) => c.url === URL_OVERVIEW)).toHaveLength(1)
  })

  it('states that the screen never decides', async () => {
    boot(view, routes())
    renderApp(OVERVIEW)
    expect(await screen.findByText(/Server memeriksa ulang kesiapan dan hak akses pada setiap tindakan/)).toBeInTheDocument()
  })

  it('shows the READY badge the server computed', async () => {
    const data = overviewData()
    data.periods[1].readiness = { result: 'READY', blocking: [], warnings: [] }
    boot(view, routes({ [`GET ${URL_OVERVIEW}`]: { data } }))
    renderApp(OVERVIEW)
    const table = within(await screen.findByRole('table', { name: 'Periode akuntansi dan kesiapan penutupannya' }))
    expect(table.getByText('Siap ditutup', { selector: '.badge' })).toBeInTheDocument()
  })

  it('offers the year-end shortcut and its links only to a user who holds the permission', async () => {
    boot(view, routes())
    const first = renderApp(OVERVIEW)
    await screen.findByRole('table', { name: 'Tahun fiskal dan status tutup tahun' })
    expect(main().getByRole('link', { name: 'Riwayat' })).toHaveAttribute('href', HISTORY)
    expect(main().getByRole('link', { name: 'Kebijakan' })).toHaveAttribute('href', `${OVERVIEW}/kebijakan`)
    expect(main().queryByRole('link', { name: 'Tutup tahun' })).not.toBeInTheDocument()
    expect(main().queryByRole('link', { name: 'Tutup tahun FY2026' })).not.toBeInTheDocument()
    first.unmount()

    boot(yearView, routes())
    renderApp(OVERVIEW)
    await screen.findByRole('table', { name: 'Tahun fiskal dan status tutup tahun' })
    expect(main().getByRole('link', { name: 'Tutup tahun' })).toHaveAttribute('href', `${OVERVIEW}/akhir-tahun`)
    expect(main().getByRole('link', { name: 'Tutup tahun FY2026' })).toHaveAttribute('href', `${OVERVIEW}/akhir-tahun/fy-1`)
    expect(main().getByText('Belum dijalankan', { selector: '.badge' })).toBeInTheDocument()
  })

  it('filters the periods by fiscal year without asking the server again', async () => {
    const data = overviewData()
    data.fiscal_years.push({ id: 'fy-0', code: 'FY2025', name: 'TF 2025', start_date: '2025-01-01', end_date: '2025-12-31', status: 'CLOSED', year_end: { status: 'POSTED', run_no: 1, journal_entry_id: 'j-9', run_at: null } })
    data.periods.push({ ...data.periods[0], id: 'p-old', code: '2025-12', name: 'Desember 2025', fiscal_year_id: 'fy-0', fiscal_year_code: 'FY2025' })
    const calls = boot(view, routes({ [`GET ${URL_OVERVIEW}`]: { data } }))
    renderApp(OVERVIEW)
    const table = within(await screen.findByRole('table', { name: 'Periode akuntansi dan kesiapan penutupannya' }))
    expect(table.getAllByRole('link', { name: /^2026-|^2025-/ }).length).toBeGreaterThanOrEqual(4)

    await userEvent.selectOptions(screen.getByLabelText('Tahun fiskal'), 'fy-0')
    expect(table.getByRole('link', { name: '2025-12' })).toBeInTheDocument()
    expect(table.queryByRole('link', { name: '2026-09' })).not.toBeInTheDocument()
    expect(calls.filter((c) => c.url === URL_OVERVIEW)).toHaveLength(1)
  })

  it('shows the plain refusal for a narrower data scope', async () => {
    boot(view, routes({ [`GET ${URL_OVERVIEW}`]: { status: 403, data: { message: 'x', code: 'CLOSING_REQUIRES_FULL_SCOPE' } } }))
    renderApp(OVERVIEW)
    expect(await screen.findByText(/Penutupan memerlukan cakupan data seluruh organisasi/)).toBeInTheDocument()
  })

  it('says so when there is no period, and tells a read-only subscription', async () => {
    const data = { ...overviewData(), periods: [] }
    boot(view, routes({ [`GET ${URL_OVERVIEW}`]: { data } }), { mode: 'READ_ONLY' })
    renderApp(OVERVIEW)
    expect(await screen.findByText('Belum ada periode')).toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
  })

  it('shows an error with retry', async () => {
    let attempt = 0
    boot(view, routes({ [`GET ${URL_OVERVIEW}`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: overviewData() }) }))
    renderApp(OVERVIEW)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('table', { name: 'Periode akuntansi dan kesiapan penutupannya' })).toBeInTheDocument()
    expect(attempt).toBe(2)
  })
})

describe('closing history', () => {
  const routes = (over: Record<string, unknown> = {}) => ({ [`GET ${URL_HISTORY}`]: { data: history() }, [`GET ${URL_YEARS}`]: { data: years() }, ...over })

  it('lists the events with action, period, outcome, actor, reason and the evidence the actor saw', async () => {
    const calls = boot(view, routes())
    renderApp(HISTORY)

    expect(await screen.findByRole('heading', { name: 'Riwayat penutupan' })).toBeInTheDocument()
    expect(screen.getByText(/Riwayat ini hanya bertambah/)).toBeInTheDocument()
    const table = within(await screen.findByRole('table', { name: 'Riwayat penutupan periode dan tahun' }))
    expect(table.getByText('Tutup sementara', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Buka kembali', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByRole('link', { name: '2026-09' })).toHaveAttribute('href', '/app/akuntansi/penutupan/periode/p-9')
    expect(table.getByText('Terbuka → Ditutup sementara')).toBeInTheDocument()
    expect(table.getByText('Ditutup → Ditutup sementara')).toBeInTheDocument()
    expect(table.getAllByText('Sari Dewi')).toHaveLength(2)
    expect(table.getByText('Mulai penutupan September')).toBeInTheDocument()
    expect(table.getByText('Koreksi akrual')).toBeInTheDocument()
    expect(table.getByText('Belum siap', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Bukti kesiapan (2 pemeriksaan)')).toBeInTheDocument()
    expect(calls.find((c) => c.url === URL_HISTORY)!.params).toEqual({ page: 1 })
  })

  it('filters on the server by period, fiscal year and action, going back to the first page', async () => {
    const calls = boot(view, routes())
    renderApp(HISTORY)
    await screen.findByRole('table', { name: 'Riwayat penutupan periode dan tahun' })

    await screen.findByRole('option', { name: '2026-09 · September 2026' })
    await userEvent.selectOptions(screen.getByLabelText('Periode'), 'p-9')
    await waitFor(() => expect(gets(calls, URL_HISTORY).at(-1)?.params).toEqual({ page: 1, period_id: 'p-9' }))
    await userEvent.selectOptions(screen.getByLabelText('Jenis tindakan'), 'REOPEN')
    await waitFor(() => expect(gets(calls, URL_HISTORY).at(-1)?.params).toEqual({ page: 1, period_id: 'p-9', event_type: 'REOPEN' }))
    await userEvent.selectOptions(screen.getByLabelText('Tahun fiskal'), 'fy-1')
    await waitFor(() => expect(gets(calls, URL_HISTORY).at(-1)?.params).toEqual({ page: 1, period_id: 'p-9', fiscal_year_id: 'fy-1', event_type: 'REOPEN' }))
  })

  it('starts on the period a link names', async () => {
    const calls = boot(view, routes())
    renderApp(`${HISTORY}?period_id=p-9`)
    await screen.findByRole('table', { name: 'Riwayat penutupan periode dan tahun' })
    expect(calls.find((c) => c.url === URL_HISTORY)!.params).toEqual({ page: 1, period_id: 'p-9' })
  })

  it('pages through the history as the API does', async () => {
    const two = { ...page([event()]), last_page: 2, total: 40 }
    const calls = boot(view, routes({ [`GET ${URL_HISTORY}`]: { data: two } }))
    renderApp(HISTORY)
    expect(await screen.findByText('40 data · halaman 1 dari 2')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Berikutnya' }))
    await waitFor(() => expect(gets(calls, URL_HISTORY).at(-1)?.params).toEqual({ page: 2 }))
  })

  it('links a year-end event to its fiscal year and its journal only for a user who may open them', async () => {
    const ye = event({ id: 'e-9', event_type: 'YEAR_END_RUN', accounting_period_id: null, period_code: null, from_status: 'NOT_RUN', to_status: 'POSTED', readiness: null, readiness_checks: null, reference: 'JV-2026-0099', journal_entry_id: 'j-ye', reason: null })
    boot([...yearView, 'accounting.journal.view'], routes({ [`GET ${URL_HISTORY}`]: { data: page([ye]) } }))
    renderApp(HISTORY)
    const table = within(await screen.findByRole('table', { name: 'Riwayat penutupan periode dan tahun' }))
    expect(table.getByRole('link', { name: 'Tahun fiskal FY2026' })).toHaveAttribute('href', '/app/akuntansi/penutupan/akhir-tahun/fy-1')
    expect(table.getByRole('link', { name: 'JV-2026-0099' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-ye')
    expect(table.getByText('Belum dijalankan → Terposting')).toBeInTheDocument()
  })

  it('says so when nothing matches, and shows an error with retry', async () => {
    let attempt = 0
    boot(view, routes({ [`GET ${URL_HISTORY}`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: page([]) }) }))
    renderApp(HISTORY)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Belum ada riwayat')).toBeInTheDocument()
  })

  it('keeps the evidence checks available as written (code and result)', async () => {
    boot(view, routes({ [`GET ${URL_HISTORY}`]: { data: page([event({ readiness_checks: [check('PENDING_JOURNALS', { title: 'Jurnal tertunda', result: 'FAIL' })] })]) } }))
    renderApp(HISTORY)
    const table = within(await screen.findByRole('table', { name: 'Riwayat penutupan periode dan tahun' }))
    expect(table.getByText('Jurnal tertunda')).toBeInTheDocument()
    expect(table.getByText('Gagal', { selector: '.badge' })).toBeInTheDocument()
  })
})
