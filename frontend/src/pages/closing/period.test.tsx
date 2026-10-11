import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../test/render'
import { CL, boot, checklist, checklistManager, closer, gets, history, item, pendingJournals, posts, readiness, ready, view, years } from './testkit'

afterEach(() => vi.restoreAllMocks())

const PATH = '/app/akuntansi/penutupan/periode/p-9'
const P = `${CL}/closing/periods/p-9`
const READINESS = `${P}/readiness`
const CHECKLIST = `${P}/checklist`
const HISTORY = `${CL}/closing/history`
const YEARS = `${CL}/fiscal-years`

type Status = 'OPEN' | 'SOFT_CLOSED' | 'CLOSED' | 'FUTURE'
const routes = (over: Record<string, unknown> = {}, status: Status = 'OPEN') => ({
  [`GET ${YEARS}`]: { data: years(status) },
  [`GET ${READINESS}`]: { data: readiness() },
  [`GET ${CHECKLIST}`]: { data: checklist() },
  [`GET ${HISTORY}`]: { data: history() },
  ...over,
})
const card = async (title: string) => within((await screen.findByRole('heading', { name: title })).closest('section')!)
const button = (name: string) => screen.queryByRole('button', { name })
const open = async (name: string) => {
  await userEvent.click(await screen.findByRole('button', { name }))
  return within(await screen.findByRole('dialog'))
}

describe('period closing: readiness, checklist and history', () => {
  it('shows the period and every check with its result and the evidence of the server', async () => {
    const calls = boot(view, routes())
    renderApp(PATH)

    expect(await screen.findByRole('heading', { name: 'Periode 2026-09' })).toBeInTheDocument()
    expect(screen.getByText(/September 2026/)).toBeInTheDocument()
    expect(screen.getByText(/Server memeriksa ulang kesiapan dan hak akses pada setiap tindakan/)).toBeInTheDocument()
    const section = await card('Kesiapan penutupan')
    expect(await section.findByText('Belum siap', { selector: '.badge' })).toBeInTheDocument()
    expect(section.getByText('2 penghalang · 1 peringatan')).toBeInTheDocument()
    const table = within(section.getByRole('table', { name: 'Pemeriksaan kesiapan penutupan periode' }))
    expect(table.getAllByText('Gagal', { selector: '.badge' })).toHaveLength(2)
    expect(table.getAllByText('Peringatan', { selector: '.badge' }).length).toBeGreaterThan(0)
    expect(table.getByText('Dilewati', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getAllByText('Menghalangi', { selector: '.badge' }).length).toBeGreaterThan(0)
    // Evidence: the figures of the trial balance, the pending journals and the reconciliation are the server's.
    expect(table.getAllByText('9.000.000,00')).toHaveLength(2)
    expect(table.getByText(/Akrual gaji/)).toBeInTheDocument()
    expect(table.getByText('-5.000,00')).toBeInTheDocument()
    expect(table.getByText('Utang vs buku besar')).toBeInTheDocument()
    expect(table.queryByRole('link', { name: /Akrual gaji/ })).not.toBeInTheDocument()
    expect(table.queryByRole('link', { name: 'Buka rincian rekonsiliasi' })).not.toBeInTheDocument()
    expect(calls.find((c) => c.url === READINESS)!.params).toBeUndefined()
    expect(calls.find((c) => c.url === HISTORY)!.params).toEqual({ period_id: 'p-9', per_page: 10 })
  })

  it('links the journals and the reconciliation only for a user who may open them', async () => {
    boot([...view, 'accounting.journal.view', 'accounting.reconciliation.view'], routes())
    renderApp(PATH)
    const table = within(await screen.findByRole('table', { name: 'Pemeriksaan kesiapan penutupan periode' }))
    expect(table.getByRole('link', { name: /Akrual gaji/ })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-1')
    expect(table.getByRole('link', { name: 'Buka rincian rekonsiliasi' })).toHaveAttribute('href', '/app/akuntansi/rekonsiliasi/jenis/ap-gl?as_of=2026-09-30')
  })

  it('confirms a period that is ready', async () => {
    boot(view, routes({ [`GET ${READINESS}`]: { data: ready() } }))
    renderApp(PATH)
    const section = await card('Kesiapan penutupan')
    expect(await section.findByText('Siap ditutup', { selector: '.badge' })).toBeInTheDocument()
    expect(section.getByText(/Tidak ada pemeriksaan penghalang yang gagal pada saat dihitung/)).toBeInTheDocument()
    expect(section.getByText('0 penghalang · 0 peringatan')).toBeInTheDocument()
  })

  it('lists the manual items, and the system checks without any control to pass them', async () => {
    boot(checklistManager, routes())
    renderApp(PATH)
    const items = within(await screen.findByRole('table', { name: 'Butir daftar periksa penutupan manual' }))
    expect(items.getByText('Tinjau akrual')).toBeInTheDocument()
    expect(items.getByText('Menunggu', { selector: '.badge' })).toBeInTheDocument()
    expect(items.getByText('Selesai', { selector: '.badge' })).toBeInTheDocument()
    expect(items.getByText('Sudah ditandatangani')).toBeInTheDocument()
    expect(items.getAllByText('Wajib', { selector: '.badge' })).toHaveLength(2)
    const system = within(screen.getByRole('table', { name: 'Pemeriksaan sistem (hanya baca)' }))
    expect(system.getAllByText('Otomatis, hanya baca')).toHaveLength(2)
    expect(system.queryAllByRole('button')).toHaveLength(0)
    expect(system.queryAllByRole('checkbox')).toHaveLength(0)
  })

  it('shows the latest events of the period with a link to the whole history', async () => {
    boot(view, routes())
    renderApp(PATH)
    const events = await card('Peristiwa terakhir periode')
    expect(await events.findByText('Mulai penutupan September')).toBeInTheDocument()
    expect(events.getByText('Koreksi akrual')).toBeInTheDocument()
    expect(events.getByRole('link', { name: 'Lihat seluruh riwayat periode ini' })).toHaveAttribute('href', '/app/akuntansi/penutupan/riwayat?period_id=p-9')
  })

  it('says so for a period that does not exist, and explains a future one', async () => {
    boot(view, routes())
    const first = renderApp('/app/akuntansi/penutupan/periode/p-99')
    expect(await screen.findByText('Periode tidak ditemukan')).toBeInTheDocument()
    first.unmount()

    boot(view, routes({}, 'FUTURE'))
    renderApp(PATH)
    expect(await screen.findByText(/Periode ini belum dibuka/)).toBeInTheDocument()
  })

  it('shows an error with retry for the readiness while the rest of the page stays', async () => {
    let attempt = 0
    boot(view, routes({ [`GET ${READINESS}`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: ready() }) }))
    renderApp(PATH)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Butir daftar periksa penutupan manual' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await (await card('Kesiapan penutupan')).findByText('Siap ditutup', { selector: '.badge' })).toBeInTheDocument()
  })

  it('shows the refusal of a narrower data scope in plain words', async () => {
    boot(view, routes({ [`GET ${READINESS}`]: { status: 403, data: { message: 'x', code: 'CLOSING_REQUIRES_FULL_SCOPE' } } }))
    renderApp(PATH)
    expect(await screen.findByText(/Penutupan memerlukan cakupan data seluruh organisasi/)).toBeInTheDocument()
  })
})

describe('period closing: actions', () => {
  it('offers no action to a user without the permissions', async () => {
    boot(view, routes())
    renderApp(PATH)
    await screen.findByRole('table', { name: 'Pemeriksaan kesiapan penutupan periode' })
    for (const name of ['Pra-tutup', 'Tutup sementara', 'Tutup periode', 'Buka kembali']) expect(button(name)).not.toBeInTheDocument()
  })

  it.each([
    ['OPEN', ['Pra-tutup', 'Tutup sementara', 'Tutup periode'], ['Buka kembali']],
    ['SOFT_CLOSED', ['Pra-tutup', 'Tutup periode', 'Buka kembali'], ['Tutup sementara']],
    ['CLOSED', ['Buka kembali'], ['Pra-tutup', 'Tutup sementara', 'Tutup periode']],
    ['FUTURE', [], ['Pra-tutup', 'Tutup sementara', 'Tutup periode', 'Buka kembali']],
  ] as [Status, string[], string[]][])('offers the actions that apply to a %s period', async (status, shown, hidden) => {
    boot(closer, routes({}, status))
    renderApp(PATH)
    await screen.findByRole('table', { name: 'Pemeriksaan kesiapan penutupan periode' })
    for (const name of shown) expect(button(name)).toBeInTheDocument()
    for (const name of hidden) expect(button(name)).not.toBeInTheDocument()
  })

  it('offers no action in a read-only subscription', async () => {
    boot(closer, routes(), { mode: 'READ_ONLY' })
    renderApp(PATH)
    await screen.findByRole('table', { name: 'Pemeriksaan kesiapan penutupan periode' })
    expect(button('Tutup periode')).not.toBeInTheDocument()
    expect(button('Pra-tutup')).not.toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
  })

  it('records a pre-close, says what the server found and reads everything again', async () => {
    const calls = boot(closer, routes({ [`POST ${P}/pre-close`]: { data: { ...readiness(), event_id: 'e-9' } } }))
    renderApp(PATH)
    const dialog = await open('Pra-tutup')
    expect(dialog.getByText(/Status periode tidak berubah dan pra-tutup dapat diulang/)).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Catat pra-tutup' }))

    expect(await screen.findByText('Pra-tutup 2026-09 dicatat: belum siap, 2 penghalang.')).toBeInTheDocument()
    expect(posts(calls)).toHaveLength(1)
    expect(posts(calls)[0].url).toBe(`${P}/pre-close`)
    expect(posts(calls)[0].data).toEqual({})
    await waitFor(() => expect(gets(calls, READINESS).length).toBeGreaterThan(1))
    expect(gets(calls, CHECKLIST).length).toBeGreaterThan(1)
    expect(gets(calls, HISTORY).length).toBeGreaterThan(1)
    expect(gets(calls, YEARS).length).toBeGreaterThan(1)
  })

  it('soft-closes with an optional reason and shows the new state', async () => {
    let status: Status = 'OPEN'
    const calls = boot(closer, routes({
      [`GET ${YEARS}`]: () => ({ data: years(status) }),
      [`POST ${P}/soft-close`]: () => {
        status = 'SOFT_CLOSED'
        return { data: { id: 'p-9', code: '2026-09', status: 'SOFT_CLOSED', closed_at: null, closed_by: null } }
      },
    }))
    renderApp(PATH)
    const dialog = await open('Tutup sementara')
    await userEvent.type(dialog.getByLabelText('Alasan (opsional)'), 'Mulai penutupan')
    await userEvent.click(dialog.getByRole('button', { name: 'Tutup sementara' }))

    expect(await screen.findByText('Periode 2026-09 ditutup sementara.')).toBeInTheDocument()
    expect(posts(calls)[0].url).toBe(`${P}/soft-close`)
    expect(posts(calls)[0].data).toEqual({ reason: 'Mulai penutupan' })
    await waitFor(() => expect(button('Tutup sementara')).not.toBeInTheDocument())
    expect(screen.getByText('Ditutup sementara', { selector: '.badge' })).toBeInTheDocument()
  })

  it('hard-closes after a confirmation that warns the last readiness was not ready, and sends no reason when none was typed', async () => {
    const calls = boot(closer, routes({ [`POST ${P}/hard-close`]: { data: { id: 'p-9', code: '2026-09', status: 'CLOSED', closed_at: '2026-10-08T03:00:00Z', closed_by: 'u-t' } } }))
    renderApp(PATH)
    await screen.findByText('2 penghalang · 1 peringatan')
    const dialog = await open('Tutup periode')
    expect(dialog.getByText(/Hasil kesiapan terakhir di layar: belum siap \(2 penghalang\)/)).toBeInTheDocument()
    expect(dialog.getByText(/keputusan ada di server/)).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Tutup periode' }))

    expect(await screen.findByText('Periode 2026-09 ditutup.')).toBeInTheDocument()
    expect(posts(calls)[0].url).toBe(`${P}/hard-close`)
    expect(posts(calls)[0].data).toEqual({})
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('shows the blockers when the server refuses a close as not ready, and keeps the dialog', async () => {
    const refusal = { message: 'Period 2026-09 is not ready to close.', code: 'PERIOD_NOT_READY', details: { period: '2026-09', blocking: [pendingJournals()], warning_count: 1 } }
    boot(closer, routes({ [`POST ${P}/hard-close`]: { status: 409, data: refusal } }))
    renderApp(PATH)
    const dialog = await open('Tutup periode')
    await userEvent.click(dialog.getByRole('button', { name: 'Tutup periode' }))

    expect(await dialog.findByText(/Periode 2026-09 belum siap ditutup: 1 pemeriksaan penghalang belum lolos/)).toBeInTheDocument()
    expect(dialog.getByText('Pemeriksaan penghalang')).toBeInTheDocument()
    expect(dialog.getByText('Jurnal diajukan atau disetujui belum diposting')).toBeInTheDocument()
    expect(dialog.getByText('2 jurnal diajukan atau disetujui yang belum diposting pada periode ini.')).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('tells plainly that the period is busy', async () => {
    boot(closer, routes({ [`POST ${P}/hard-close`]: { status: 409, data: { message: 'busy', code: 'PERIOD_BUSY' } } }))
    renderApp(PATH)
    const dialog = await open('Tutup periode')
    await userEvent.click(dialog.getByRole('button', { name: 'Tutup periode' }))
    expect(await dialog.findByText(/Periode sedang dipakai transaksi lain/)).toBeInTheDocument()
  })

  it('requires a reason of at least 10 characters to reopen, and sends the chosen target', async () => {
    const calls = boot(closer, routes({ [`POST ${P}/reopen`]: { data: { id: 'p-9', code: '2026-09', status: 'SOFT_CLOSED', closed_at: null, closed_by: null } } }, 'CLOSED'))
    renderApp(PATH)
    const dialog = await open('Buka kembali')
    await userEvent.type(dialog.getByLabelText('Alasan (wajib, dicatat permanen)'), 'terlalu')
    await userEvent.click(dialog.getByRole('button', { name: 'Buka kembali' }))
    expect(await dialog.findByText('Alasan minimal 10 karakter.')).toBeInTheDocument()
    expect(posts(calls)).toHaveLength(0)

    expect(dialog.getByLabelText('Buka kembali ke status')).toHaveValue('SOFT_CLOSED')
    await userEvent.type(dialog.getByLabelText('Alasan (wajib, dicatat permanen)'), ' sekali, koreksi akrual')
    await userEvent.click(dialog.getByRole('button', { name: 'Buka kembali' }))
    expect(await screen.findByText('Periode 2026-09 dibuka kembali.')).toBeInTheDocument()
    expect(posts(calls)[0].url).toBe(`${P}/reopen`)
    expect(posts(calls)[0].data).toEqual({ target: 'SOFT_CLOSED', reason: 'terlalu sekali, koreksi akrual' })
  })

  it('reopens a soft-closed period only to open', async () => {
    const calls = boot(closer, routes({ [`POST ${P}/reopen`]: { data: { id: 'p-9', code: '2026-09', status: 'OPEN', closed_at: null, closed_by: null } } }, 'SOFT_CLOSED'))
    renderApp(PATH)
    const dialog = await open('Buka kembali')
    const target = dialog.getByLabelText('Buka kembali ke status')
    expect(within(target).getAllByRole('option')).toHaveLength(1)
    await userEvent.type(dialog.getByLabelText('Alasan (wajib, dicatat permanen)'), 'Ada jurnal yang terlewat')
    await userEvent.click(dialog.getByRole('button', { name: 'Buka kembali' }))
    await waitFor(() => expect(posts(calls)).toHaveLength(1))
    expect(posts(calls)[0].data).toEqual({ target: 'OPEN', reason: 'Ada jurnal yang terlewat' })
  })

  it.each([
    ['the segregation of duties policy', 403, { code: 'SOD_VIOLATION', details: { rule: 'closer_not_reopener' } }, /orang yang menutup periode tidak boleh membukanya kembali/],
    ['a later closed period', 409, { code: 'REOPEN_LATER_PERIOD_CLOSED', details: { period: '2026-08', later_closed: ['2026-09', '2026-10'] } }, /Periode lebih baru yang tertutup: 2026-09, 2026-10/],
    ['the limit of periods back', 409, { code: 'REOPEN_TOO_FAR_BACK', details: { periods_back: 3, max_periods_back: 1 } }, /Batas kebijakan: 1 periode/],
    ['a posted year end', 409, { code: 'YEAR_END_POSTED', details: { fiscal_year: 'FY2026' } }, /Balik tutup tahun lebih dulu/],
  ])('shows the refusal of %s in the dialog', async (_name, status, body, expected) => {
    boot(closer, routes({ [`POST ${P}/reopen`]: { status, data: { message: 'x', ...body } } }, 'CLOSED'))
    renderApp(PATH)
    const dialog = await open('Buka kembali')
    await userEvent.type(dialog.getByLabelText('Alasan (wajib, dicatat permanen)'), 'Koreksi akrual yang terlewat')
    await userEvent.click(dialog.getByRole('button', { name: 'Buka kembali' }))
    expect(await dialog.findByText(expected)).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})

describe('period closing: checklist actions', () => {
  const ITEM = `${P}/checklist/REVIEW_ACCRUALS`

  it('offers the item actions only to a user who may manage the checklist', async () => {
    boot(view, routes())
    const first = renderApp(PATH)
    await screen.findByRole('table', { name: 'Butir daftar periksa penutupan manual' })
    expect(button('Selesaikan Tinjau akrual')).not.toBeInTheDocument()
    first.unmount()

    boot(checklistManager, routes())
    renderApp(PATH)
    expect(await screen.findByRole('button', { name: 'Selesaikan Tinjau akrual' })).toBeInTheDocument()
    expect(button('Kecualikan Tinjau akrual')).toBeInTheDocument()
    expect(button('Setel ulang Tanda tangan paket laporan')).toBeInTheDocument()
    expect(button('Setel ulang Tinjau akrual')).not.toBeInTheDocument()
  })

  it('offers none on a closed period (its checklist is evidence) nor in a read-only subscription', async () => {
    boot(checklistManager, routes({ [`GET ${CHECKLIST}`]: { data: checklist(undefined, 'CLOSED') } }, 'CLOSED'))
    const first = renderApp(PATH)
    expect(await screen.findByText(/Daftar periksa periode yang sudah ditutup adalah bukti/)).toBeInTheDocument()
    expect(button('Selesaikan Tinjau akrual')).not.toBeInTheDocument()
    first.unmount()

    boot(checklistManager, routes(), { mode: 'READ_ONLY' })
    renderApp(PATH)
    await screen.findByRole('table', { name: 'Butir daftar periksa penutupan manual' })
    expect(button('Selesaikan Tinjau akrual')).not.toBeInTheDocument()
  })

  it('completes an item with a note and reads the checklist again', async () => {
    const calls = boot(checklistManager, routes({ [`POST ${ITEM}/complete`]: { data: item({ status: 'COMPLETED' }) } }))
    renderApp(PATH)
    const dialog = await open('Selesaikan Tinjau akrual')
    await userEvent.type(dialog.getByLabelText('Catatan (opsional)'), 'Sudah ditinjau')
    await userEvent.click(dialog.getByRole('button', { name: 'Tandai selesai' }))

    expect(await screen.findByText('Butir ditandai selesai.')).toBeInTheDocument()
    expect(posts(calls)[0].url).toBe(`${ITEM}/complete`)
    expect(posts(calls)[0].data).toEqual({ notes: 'Sudah ditinjau' })
    await waitFor(() => expect(gets(calls, CHECKLIST).length).toBeGreaterThan(1))
    expect(gets(calls, READINESS).length).toBeGreaterThan(1)
  })

  it('needs a reason to waive an item and records it', async () => {
    const calls = boot(checklistManager, routes({ [`POST ${ITEM}/waive`]: { data: item({ status: 'WAIVED' }) } }))
    renderApp(PATH)
    const dialog = await open('Kecualikan Tinjau akrual')
    await userEvent.type(dialog.getByLabelText('Alasan pengecualian'), 'ok')
    await userEvent.click(dialog.getByRole('button', { name: 'Kecualikan' }))
    expect(await dialog.findByText('Alasan minimal 5 karakter.')).toBeInTheDocument()
    expect(posts(calls)).toHaveLength(0)

    await userEvent.type(dialog.getByLabelText('Alasan pengecualian'), ' tidak ada akrual bulan ini')
    await userEvent.click(dialog.getByRole('button', { name: 'Kecualikan' }))
    expect(await screen.findByText('Butir dikecualikan dengan alasan tercatat.')).toBeInTheDocument()
    expect(posts(calls)[0].url).toBe(`${ITEM}/waive`)
    expect(posts(calls)[0].data).toEqual({ reason: 'ok tidak ada akrual bulan ini' })
  })

  it('resets a completed item after a confirmation', async () => {
    const calls = boot(checklistManager, routes({ [`POST ${P}/checklist/SIGN_PACK/reset`]: { data: item({ code: 'SIGN_PACK' }) } }))
    renderApp(PATH)
    const dialog = await open('Setel ulang Tanda tangan paket laporan')
    await userEvent.click(dialog.getByRole('button', { name: 'Setel ulang' }))
    expect(await screen.findByText('Butir dikembalikan ke menunggu.')).toBeInTheDocument()
    expect(posts(calls)[0].url).toBe(`${P}/checklist/SIGN_PACK/reset`)
    expect(posts(calls)[0].data).toBeUndefined()
  })

  it('shows the refusal of a checklist change and keeps the dialog', async () => {
    boot(checklistManager, routes({ [`POST ${ITEM}/complete`]: { status: 409, data: { message: 'x', code: 'CHECKLIST_PERIOD_CLOSED' } } }))
    renderApp(PATH)
    const dialog = await open('Selesaikan Tinjau akrual')
    await userEvent.click(dialog.getByRole('button', { name: 'Tandai selesai' }))
    expect(await dialog.findByText(/Daftar periksa periode yang sudah ditutup adalah bukti dan tidak dapat diubah/)).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})
