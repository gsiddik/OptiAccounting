import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../test/render'
import { CL, boot, gets, posts, preview, ruleStatus, run, view, yearRunner, yearShow, yearSummary, yearView } from './testkit'

afterEach(() => vi.restoreAllMocks())

const LIST = '/app/akuntansi/penutupan/akhir-tahun'
const DETAIL = `${LIST}/fy-1`
const URL_LIST = `${CL}/year-end`
const URL_RULE = `${CL}/year-end/rule-status`
const URL_DEFAULTS = `${CL}/year-end/rule-defaults`
const FY = `${CL}/year-end/fy-1`
const card = async (title: string) => within((await screen.findByRole('heading', { name: title })).closest('section')!)
const button = (name: string) => screen.queryByRole('button', { name })

describe('year end list', () => {
  const missing = ruleStatus({
    events: [{ event_type: 'YEAR_END_CLOSING', name: 'Penutupan akhir tahun', ready: false, rule_code: null, effective_from: null }],
    retained_earnings: { mapped: false, account: null, problem: 'ACCOUNT_MAPPING_MISSING' }, unmapped_roles: ['RETAINED_EARNINGS'],
  })
  const routes = (over: Record<string, unknown> = {}) => ({
    [`GET ${URL_LIST}`]: { data: { fiscal_years: [
      yearSummary(),
      yearSummary({ id: 'fy-0', code: 'FY2025', name: 'TF 2025', start_date: '2025-01-01', end_date: '2025-12-31', status: 'CLOSED', closing_date: '2025-12-31', last_period: { id: 'p-24', code: '2025-12', status: 'CLOSED' }, year_end: { status: 'POSTED', run_no: 1, journal_entry_id: 'j-25', journal_number: 'JV-2025-0099', run_at: '2025-12-31T10:00:00Z' }, can_run: false, blockers: ['YEAR_END_ALREADY_POSTED'] }),
      yearSummary({ id: 'fy-2', code: 'FY2027', name: 'TF 2027', can_run: false, blockers: ['YEAR_END_PENDING_JOURNALS', 'YEAR_END_FUTURE_PERIODS'] }),
    ] } },
    [`GET ${URL_RULE}`]: { data: ruleStatus() },
    ...over,
  })

  it('lists every fiscal year with its year-end state and what blocks it, in the words of the server codes', async () => {
    boot(yearView, routes())
    renderApp(LIST)

    expect(await screen.findByRole('heading', { name: 'Tutup tahun' })).toBeInTheDocument()
    expect(screen.getByText(/Server memeriksa ulang kesiapan dan hak akses pada setiap tindakan/)).toBeInTheDocument()
    const table = within(await screen.findByRole('table', { name: 'Tahun fiskal dan status tutup tahun' }))
    expect(table.getByRole('link', { name: 'FY2026' })).toHaveAttribute('href', `${LIST}/fy-1`)
    expect(table.getByText('Dapat dijalankan', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getAllByText('Belum dijalankan', { selector: '.badge' })).toHaveLength(2)
    expect(table.getByText('Terposting', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Sudah diproses; balik dulu untuk menjalankan ulang.')).toBeInTheDocument()
    expect(table.getByText('JV-2025-0099')).toBeInTheDocument()
    expect(table.getByText('2 penghalang', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText(/Posting atau batalkan jurnal yang diajukan atau disetujui sampai akhir tahun/)).toBeInTheDocument()
    expect(table.getByText('(YEAR_END_FUTURE_PERIODS)')).toBeInTheDocument()
    expect(table.getAllByRole('link', { name: '2026-12' })[0]).toHaveAttribute('href', '/app/akuntansi/penutupan/periode/p-12')
  })

  it('shows the posting rule and the retained earnings account that the year end needs', async () => {
    boot(yearView, routes())
    renderApp(LIST)
    const section = await card('Prasyarat tutup tahun')
    expect(await section.findByText('Terbit', { selector: '.badge' })).toBeInTheDocument()
    expect(section.getByText('YEAR-END-CLOSING')).toBeInTheDocument()
    expect(section.getByText('3200 · Laba Ditahan')).toBeInTheDocument()
    expect(section.queryByRole('button', { name: 'Terapkan aturan bawaan' })).not.toBeInTheDocument()
  })

  it('explains a missing rule and mapping, and offers the default rule only to a user who may publish rules', async () => {
    boot([...yearView, 'accounting.account_mapping.view'], routes({ [`GET ${URL_RULE}`]: { data: missing } }))
    const first = renderApp(LIST)
    expect(await screen.findByText(/YEAR_END_RULE_MISSING/)).toBeInTheDocument()
    expect(screen.getByText(/Minta pengguna yang berwenang mengelola aturan posting/)).toBeInTheDocument()
    expect(screen.getByText('Belum terbit', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText('Belum dipetakan', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText(/ACCOUNT_MAPPING_MISSING/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Buka pemetaan akun' })).toHaveAttribute('href', '/app/akuntansi/pemetaan-akun')
    expect(button('Terapkan aturan bawaan')).not.toBeInTheDocument()
    first.unmount()

    boot([...yearView, 'accounting.posting_rule.manage'], routes({ [`GET ${URL_RULE}`]: { data: missing } }), { mode: 'READ_ONLY' })
    const second = renderApp(LIST)
    await screen.findByText(/YEAR_END_RULE_MISSING/)
    expect(button('Terapkan aturan bawaan')).not.toBeInTheDocument()
    second.unmount()

    boot([...yearView, 'accounting.posting_rule.manage'], routes({ [`GET ${URL_RULE}`]: { data: missing } }))
    renderApp(LIST)
    expect(await screen.findByRole('button', { name: 'Terapkan aturan bawaan' })).toBeInTheDocument()
  })

  it('applies the default rule with an optional date and reads the status again', async () => {
    let applied = false
    const calls = boot([...yearView, 'accounting.posting_rule.manage'], routes({
      [`GET ${URL_RULE}`]: () => ({ data: applied ? ruleStatus() : missing }),
      [`POST ${URL_DEFAULTS}`]: () => {
        applied = true
        return { status: 201, data: { created: ['YEAR_END_CLOSING'], skipped: [] } }
      },
    }))
    renderApp(LIST)
    await userEvent.click(await screen.findByRole('button', { name: 'Terapkan aturan bawaan' }))
    const dialog = within(await screen.findByRole('dialog'))
    expect(dialog.getByText(/idempoten dan tidak menimpa aturan yang sudah ada/)).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Terapkan' }))

    expect(await screen.findByText('Aturan posting tutup tahun diterbitkan.')).toBeInTheDocument()
    expect(posts(calls)[0].url).toBe(URL_DEFAULTS)
    expect(posts(calls)[0].data).toEqual({})
    await waitFor(() => expect(gets(calls, URL_RULE).length).toBeGreaterThan(1))
    expect(await screen.findByText('Terbit', { selector: '.badge' })).toBeInTheDocument()
    expect(button('Terapkan aturan bawaan')).not.toBeInTheDocument()
  })

  it('sends the date the user typed and shows a refusal', async () => {
    const calls = boot([...yearView, 'accounting.posting_rule.manage'], routes({
      [`GET ${URL_RULE}`]: { data: missing },
      [`POST ${URL_DEFAULTS}`]: { status: 409, data: { message: 'x', code: 'FISCAL_YEAR_MISSING' } },
    }))
    renderApp(LIST)
    await userEvent.click(await screen.findByRole('button', { name: 'Terapkan aturan bawaan' }))
    const dialog = within(await screen.findByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Berlaku mulai (opsional)'), '2026-01-01')
    await userEvent.click(dialog.getByRole('button', { name: 'Terapkan' }))
    expect(await dialog.findByText(/Buat tahun fiskal terlebih dahulu/)).toBeInTheDocument()
    expect(posts(calls)[0].data).toEqual({ effective_from: '2026-01-01' })
  })

  it('shows errors with retry for the list and the rule status, and an empty state', async () => {
    let attempt = 0
    boot(yearView, routes({ [`GET ${URL_LIST}`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: { fiscal_years: [] } }) }))
    renderApp(LIST)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Belum ada tahun fiskal')).toBeInTheDocument()
  })

  it('shows the plain refusal for a narrower data scope', async () => {
    boot(yearView, routes({ [`GET ${URL_LIST}`]: { status: 403, data: { message: 'x', code: 'CLOSING_REQUIRES_FULL_SCOPE' } } }))
    renderApp(LIST)
    expect(await screen.findByText(/Penutupan memerlukan cakupan data seluruh organisasi/)).toBeInTheDocument()
  })

  it('is not reachable without the year-end permission', async () => {
    const calls = boot(view, routes())
    renderApp(LIST)
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
    expect(screen.queryByRole('table', { name: 'Tahun fiskal dan status tutup tahun' })).not.toBeInTheDocument()
    expect(calls.some((c) => c.url === URL_LIST)).toBe(false)
  })
})

describe('year end detail', () => {
  const routes = (over: Record<string, unknown> = {}) => ({
    [`GET ${FY}`]: { data: yearShow() },
    [`GET ${FY}/preview`]: { data: preview() },
    ...over,
  })
  const posted = () => yearShow({ year_end: { status: 'POSTED', run_no: 1, journal_entry_id: 'j-ye', journal_number: 'JV-2026-0099', run_at: '2026-12-31T10:00:00Z' }, can_run: false, blockers: ['YEAR_END_ALREADY_POSTED'], runs: [run()] })

  it('shows the preview exactly as the server computed it', async () => {
    boot(yearView, routes())
    renderApp(DETAIL)

    expect(await screen.findByRole('heading', { name: 'Tutup tahun FY2026' })).toBeInTheDocument()
    const status = await card('Tahun fiskal')
    expect(await status.findByText('YEAR-END-CLOSING v1 · sejak 01 Jan 2026')).toBeInTheDocument()
    expect(status.getByText('3200 · Laba Ditahan')).toBeInTheDocument()
    expect(status.getByRole('link', { name: '2026-12' })).toHaveAttribute('href', '/app/akuntansi/penutupan/periode/p-12')
    expect(screen.getByText(/Dapat dijalankan\./)).toBeInTheDocument()

    const totals = await card('Hasil yang akan ditutup')
    expect(totals.getByText('Laba', { selector: '.badge' })).toBeInTheDocument()
    expect(totals.getAllByText('1.500.000,00').length).toBeGreaterThan(0)
    expect(totals.getByText('Seimbang', { selector: '.badge' })).toBeInTheDocument()
    expect(totals.getAllByText('5.000.000,00')).toHaveLength(3)
    const accounts = within(screen.getByRole('table', { name: 'Akun pendapatan dan beban yang ditutup jurnal penutupan' }))
    expect(accounts.getByText('4100 · Pendapatan Jasa')).toBeInTheDocument()
    expect(accounts.getByText('Debit')).toBeInTheDocument()
    expect(accounts.getByText('Kredit')).toBeInTheDocument()
    expect(accounts.getByText('Pendapatan')).toBeInTheDocument()
    const groups = within(screen.getByRole('table', { name: 'Hasil tahun berjalan per cabang dan unit bisnis' }))
    expect(groups.getByText('Tanpa cabang')).toBeInTheDocument()
    expect(screen.getByText('Belum pernah dijalankan')).toBeInTheDocument()
  })

  it('lists every blocker with its code and details, and does not offer to run', async () => {
    const blocked = preview({
      can_run: false, posting_rule: null, retained_earnings_account: null, journal: null,
      blockers: [
        { code: 'YEAR_END_PENDING_JOURNALS', message: 'Post or cancel the submitted and approved journals first.', details: { pending: 2 } },
        { code: 'YEAR_END_FUTURE_PERIODS', message: 'Every period must have been opened.', details: { periods: ['2026-11', '2026-12'], count: 2 } },
        { code: 'YEAR_END_RULE_MISSING', message: 'Publish the rule.', details: { event_type: 'YEAR_END_CLOSING' } },
      ],
    })
    boot(yearRunner, routes({ [`GET ${FY}`]: { data: yearShow({ can_run: false, blockers: ['YEAR_END_PENDING_JOURNALS'] }) }, [`GET ${FY}/preview`]: { data: blocked } }))
    renderApp(DETAIL)
    expect(await screen.findByText(/Server melaporkan 3 penghalang/)).toBeInTheDocument()
    expect(screen.getByText(/Posting atau batalkan jurnal yang diajukan atau disetujui sampai akhir tahun/)).toBeInTheDocument()
    expect(screen.getByText('Jurnal tertunda: 2')).toBeInTheDocument()
    expect(screen.getByText('Periode: 2026-11, 2026-12')).toBeInTheDocument()
    expect(screen.getByText('(YEAR_END_RULE_MISSING)')).toBeInTheDocument()
    expect(screen.getByText(/belum dapat disimulasikan karena aturan posting belum terbit/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Jalankan tutup tahun' })).toBeDisabled()
  })

  it('shows a closing journal that does not balance', async () => {
    boot(yearView, routes({ [`GET ${FY}/preview`]: { data: preview({ journal: { balanced: false, total_debit: '5000000.0000', total_credit: '4000000.0000', line_count: 3, error: 'POSTING_UNBALANCED' } }) } }))
    renderApp(DETAIL)
    const totals = await card('Hasil yang akan ditutup')
    expect(await totals.findByText('Tidak seimbang', { selector: '.badge' })).toBeInTheDocument()
    expect(totals.getByText('POSTING_UNBALANCED')).toBeInTheDocument()
  })

  it('offers run and reverse only to a user who holds the permission on a writable module', async () => {
    boot(yearView, routes())
    const first = renderApp(DETAIL)
    await screen.findByText(/Dapat dijalankan\./)
    expect(button('Jalankan tutup tahun')).not.toBeInTheDocument()
    expect(button('Balik tutup tahun')).not.toBeInTheDocument()
    first.unmount()

    boot(yearRunner, routes(), { mode: 'READ_ONLY' })
    const second = renderApp(DETAIL)
    await screen.findByText(/Dapat dijalankan\./)
    expect(button('Jalankan tutup tahun')).not.toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    second.unmount()

    boot(yearRunner, routes())
    const third = renderApp(DETAIL)
    expect(await screen.findByRole('button', { name: 'Jalankan tutup tahun' })).toBeEnabled()
    expect(button('Balik tutup tahun')).not.toBeInTheDocument()
    third.unmount()

    boot(yearRunner, routes({ [`GET ${FY}`]: { data: posted() } }))
    renderApp(DETAIL)
    expect(await screen.findByRole('button', { name: 'Balik tutup tahun' })).toBeInTheDocument()
    expect(button('Jalankan tutup tahun')).not.toBeInTheDocument()
  })

  it('runs the year end after a confirmation that names what it will do, and reads everything again', async () => {
    const calls = boot(yearRunner, routes({ [`POST ${FY}/run`]: { status: 201, data: { ...run(), totals: preview().totals } } }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Jalankan tutup tahun' }))
    const dialog = within(await screen.findByRole('dialog'))
    expect(dialog.getByText(/jurnal sistem bertanggal 2026-12-31 yang menutup 2 baris saldo/)).toBeInTheDocument()
    expect(dialog.getByText('3200 · Laba Ditahan')).toBeInTheDocument()
    expect(dialog.getByText(/angka dihitung ulang server di dalam transaksi/i)).toBeInTheDocument()
    await userEvent.type(dialog.getByLabelText('Alasan atau catatan (opsional)'), 'Penutupan tahunan')
    await userEvent.click(dialog.getByRole('button', { name: 'Jalankan tutup tahun' }))

    expect(await screen.findByText('Tutup tahun FY2026 dijalankan: jurnal JV-2026-0099.')).toBeInTheDocument()
    expect(posts(calls)[0].url).toBe(`${FY}/run`)
    expect(posts(calls)[0].data).toEqual({ reason: 'Penutupan tahunan' })
    await waitFor(() => expect(gets(calls, FY).length).toBeGreaterThan(1))
    expect(gets(calls, `${FY}/preview`).length).toBeGreaterThan(1)
  })

  it('sends no reason when none was typed', async () => {
    const calls = boot(yearRunner, routes({ [`POST ${FY}/run`]: { status: 201, data: run() } }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Jalankan tutup tahun' }))
    await userEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Jalankan tutup tahun' }))
    await waitFor(() => expect(posts(calls)).toHaveLength(1))
    expect(posts(calls)[0].data).toEqual({})
  })

  it('shows the first blocker and the whole list when the server refuses the run, and keeps the dialog', async () => {
    const refusal = {
      message: 'Post or cancel the submitted and approved journals first.', code: 'YEAR_END_PENDING_JOURNALS',
      details: { pending: 1, blockers: [
        { code: 'YEAR_END_PENDING_JOURNALS', message: 'Post or cancel the submitted and approved journals first.', details: { pending: 1 } },
        { code: 'YEAR_END_FUTURE_PERIODS', message: 'Every period of the fiscal year must have been opened.', details: {} },
      ] },
    }
    boot(yearRunner, routes({ [`POST ${FY}/run`]: { status: 409, data: refusal } }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Jalankan tutup tahun' }))
    const dialog = within(await screen.findByRole('dialog'))
    await userEvent.click(dialog.getByRole('button', { name: 'Jalankan tutup tahun' }))

    expect(await dialog.findByText('Penghalang yang dilaporkan server')).toBeInTheDocument()
    expect(dialog.getAllByText(/Posting atau batalkan jurnal yang diajukan atau disetujui sampai akhir tahun/).length).toBeGreaterThan(1)
    expect(dialog.getByText('YEAR_END_FUTURE_PERIODS')).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('shows the posted run with links to its journal, and reverses it with a reason', async () => {
    const reversed = run({ status: 'REVERSED', reversal_journal_id: 'j-rev', reversal_journal_number: 'JV-2026-0100', reversed_at: '2027-01-03T08:00:00Z', reverse_reason: 'Salah akun laba ditahan' })
    const calls = boot([...yearRunner, 'accounting.journal.view'], routes({ [`GET ${FY}`]: { data: posted() }, [`POST ${FY}/reverse`]: { data: reversed } }))
    renderApp(DETAIL)
    const runs = within(await screen.findByRole('table', { name: 'Proses tutup tahun tahun fiskal ini' }))
    expect(runs.getByRole('link', { name: 'JV-2026-0099' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-ye')
    expect(runs.getByText('Terposting', { selector: '.badge' })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Balik tutup tahun' }))
    const dialog = within(await screen.findByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Alasan (wajib, dicatat permanen)'), 'salah')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik tutup tahun' }))
    expect(await dialog.findByText('Alasan minimal 10 karakter.')).toBeInTheDocument()
    expect(posts(calls)).toHaveLength(0)

    await userEvent.type(dialog.getByLabelText('Alasan (wajib, dicatat permanen)'), ' akun laba ditahan')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik tutup tahun' }))
    expect(await screen.findByText('Tutup tahun FY2026 dibalik: jurnal pembalik JV-2026-0100.')).toBeInTheDocument()
    expect(posts(calls)[0].url).toBe(`${FY}/reverse`)
    expect(posts(calls)[0].data).toEqual({ reason: 'salah akun laba ditahan' })
    await waitFor(() => expect(gets(calls, FY).length).toBeGreaterThan(1))
  })

  it('shows a reversed run with its reversal journal and reason, without the journal link for a user who cannot open it', async () => {
    const reversed = run({ status: 'REVERSED', reversal_journal_id: 'j-rev', reversal_journal_number: 'JV-2026-0100', reversed_at: '2027-01-03T08:00:00Z', reverse_reason: 'Salah akun laba ditahan' })
    boot(yearView, routes({ [`GET ${FY}`]: { data: yearShow({ year_end: { status: 'REVERSED', run_no: 1, journal_entry_id: 'j-ye', journal_number: 'JV-2026-0099', run_at: null }, runs: [reversed] }) } }))
    renderApp(DETAIL)
    const runs = within(await screen.findByRole('table', { name: 'Proses tutup tahun tahun fiskal ini' }))
    expect(runs.getByText('Dibalik', { selector: '.badge' })).toBeInTheDocument()
    expect(runs.getByText('JV-2026-0100')).toBeInTheDocument()
    expect(runs.getByText('Salah akun laba ditahan')).toBeInTheDocument()
    expect(runs.queryByRole('link', { name: 'JV-2026-0100' })).not.toBeInTheDocument()
  })

  it.each([
    ['a later year that is still posted', 409, { code: 'YEAR_END_LATER_YEAR_POSTED', details: { fiscal_year: 'FY2027' } }, /tahun fiskal FY2027/],
    ['a missing reopen permission', 403, { code: 'PERMISSION_DENIED' }, /Anda tidak memiliki izin untuk tindakan ini/],
    ['a short reason', 422, { code: 'YEAR_END_REASON_REQUIRED' }, /minimal 10 karakter/],
  ])('shows the refusal of a reversal because of %s', async (_name, status, body, expected) => {
    boot(yearRunner, routes({ [`GET ${FY}`]: { data: posted() }, [`POST ${FY}/reverse`]: { status, data: { message: 'x', ...body } } }))
    renderApp(DETAIL)
    await userEvent.click(await screen.findByRole('button', { name: 'Balik tutup tahun' }))
    const dialog = within(await screen.findByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Alasan (wajib, dicatat permanen)'), 'Salah akun laba ditahan')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik tutup tahun' }))
    expect(await dialog.findByText(expected)).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('shows an error with retry for the preview while the year stays, and a missing year', async () => {
    let attempt = 0
    boot(yearView, routes({ [`GET ${FY}/preview`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: preview() }) }))
    const first = renderApp(DETAIL)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Tutup tahun FY2026' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText(/Dapat dijalankan\./)).toBeInTheDocument()
    first.unmount()

    boot(yearView, routes({ [`GET ${FY}`]: { status: 404, data: { message: 'x' } } }))
    renderApp(DETAIL)
    expect(await screen.findByText('Data tidak ditemukan.')).toBeInTheDocument()
  })
})
