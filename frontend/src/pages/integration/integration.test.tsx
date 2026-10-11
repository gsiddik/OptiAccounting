import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../test/render'
import { IT, all, boot, connection, detail, event, gets, link, manager, optionRoutes, page, retrier, summary, viewer, writes } from './testkit'

afterEach(() => vi.restoreAllMocks())

const LIST = `GET ${IT}/connections`
const ONE = `GET ${IT}/connections/c-1`
const SUMMARY = `GET ${IT}/connections/c-1/summary`
const dialog = () => within(screen.getByRole('dialog'))

const detailRoutes = (over: Record<string, unknown> = {}) => ({ [ONE]: { data: connection() }, [SUMMARY]: { data: summary() }, ...optionRoutes(), ...over })
const open = async (permissions: string[], routes = detailRoutes(), options = {}) => {
  const calls = boot(permissions, routes, options)
  renderApp('/app/integrasi/koneksi/c-1')
  await screen.findByRole('heading', { name: 'OptiFleet Maju Jaya' })
  return calls
}

describe('connection list', () => {
  it('lists connections with status, cutover, last pull and failures, and links to the detail', async () => {
    boot(manager, { [LIST]: { data: page([connection(), connection({ id: 'c-2', name: 'Cadangan', status: 'PAUSED', consecutive_failures: 3, last_pulled_at: null, cutover_date: null })]) } })
    renderApp('/app/integrasi')

    const table = within(await screen.findByRole('table', { name: 'Koneksi integrasi' }))
    expect(table.getByRole('link', { name: 'OptiFleet Maju Jaya' })).toHaveAttribute('href', '/app/integrasi/koneksi/c-1')
    expect(table.getByText('Aktif')).toBeInTheDocument()
    expect(table.getByText('Dijeda')).toBeInTheDocument()
    expect(table.getAllByText('OptiFleet').length).toBe(2)
    expect(table.getByText('3')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Koneksi baru' })).toBeInTheDocument()
  })

  it('explains the connection concept when there is none, and offers the first one to a manager only', async () => {
    boot(manager, { [LIST]: { data: page([]) } })
    renderApp('/app/integrasi')
    expect(await screen.findByText('Belum ada koneksi')).toBeInTheDocument()
    expect(screen.getByText(/OptiEntry berjalan penuh tanpa koneksi/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Buat koneksi pertama' })).toBeInTheDocument()
  })

  it('shows no create button to a viewer or in a read-only subscription', async () => {
    boot(viewer, { [LIST]: { data: page([]) } })
    const first = renderApp('/app/integrasi')
    await screen.findByText('Belum ada koneksi')
    expect(screen.queryByRole('button', { name: /Koneksi baru|Buat koneksi pertama/ })).not.toBeInTheDocument()
    first.unmount()

    boot(manager, { [LIST]: { data: page([connection()]) } }, { mode: 'READ_ONLY' })
    renderApp('/app/integrasi')
    await screen.findByRole('table', { name: 'Koneksi integrasi' })
    expect(screen.queryByRole('button', { name: 'Koneksi baru' })).not.toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
  })

  it('shows the error with a retry that loads the list again', async () => {
    let attempts = 0
    boot(manager, { [LIST]: () => (attempts++ === 0 ? { status: 500, data: { message: 'boom' } } : { data: page([connection()]) }) })
    renderApp('/app/integrasi')
    expect(await screen.findByText(/Terjadi kesalahan pada server/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('link', { name: 'OptiFleet Maju Jaya' })).toBeInTheDocument()
  })

  it('creates a connection with the credential sent once, then opens it without showing the secret', async () => {
    const created = connection({ id: 'c-9', name: 'Fleet baru', status: 'READY', settings: {} })
    const calls = boot(manager, {
      [LIST]: { data: page([]) },
      [`POST ${IT}/connections`]: { status: 201, data: created },
      [`GET ${IT}/connections/c-9`]: { data: created },
      [`GET ${IT}/connections/c-9/summary`]: { data: summary({ connection_id: 'c-9', received: 0, posted: 0, counts: {}, duplicates: 0, conflicts: 0 }) },
      ...optionRoutes(),
    })
    renderApp('/app/integrasi')
    await userEvent.click(await screen.findByRole('button', { name: 'Buat koneksi pertama' }))

    await userEvent.type(dialog().getByLabelText('Nama koneksi'), 'Fleet baru')
    await userEvent.type(dialog().getByLabelText('Referensi tenant sumber'), 'fleet-co-1')
    await userEvent.type(dialog().getByLabelText('URL sistem sumber'), 'https://fleet.example.test')
    await userEvent.type(dialog().getByLabelText('Kredensial'), 'rahasia-sekali-123')
    fireEvent.change(dialog().getByLabelText('Tanggal cutover'), { target: { value: '2026-10-01' } })
    await userEvent.click(dialog().getByRole('button', { name: 'Buat koneksi' }))

    expect(await screen.findByRole('heading', { name: 'Fleet baru' })).toBeInTheDocument()
    expect(writes(calls)).toEqual([expect.objectContaining({
      method: 'POST', url: `${IT}/connections`,
      data: { name: 'Fleet baru', source_system: 'optifleet', cutover_policy: 'OPENING_BALANCE_ONLY', pull_interval_minutes: 5, source_tenant_reference: 'fleet-co-1', base_url: 'https://fleet.example.test', credential: 'rahasia-sekali-123', cutover_date: '2026-10-01' },
    })])
    expect(screen.getByText('Tersimpan (tidak ditampilkan)')).toBeInTheDocument()
    expect(document.body.textContent).not.toContain('rahasia-sekali-123')
  })

  it('checks the name locally and shows the API message when the source tenant is already connected', async () => {
    const calls = boot(manager, {
      [LIST]: { data: page([]) },
      [`POST ${IT}/connections`]: { status: 409, data: { message: 'This source tenant is already connected.', code: 'CONNECTION_EXISTS' } },
    })
    renderApp('/app/integrasi')
    await userEvent.click(await screen.findByRole('button', { name: 'Buat koneksi pertama' }))
    await userEvent.click(dialog().getByRole('button', { name: 'Buat koneksi' }))
    expect(dialog().getByText('Nama minimal 2 karakter.')).toBeInTheDocument()
    expect(writes(calls)).toHaveLength(0)

    await userEvent.type(dialog().getByLabelText('Nama koneksi'), 'Fleet')
    await userEvent.click(dialog().getByRole('button', { name: 'Buat koneksi' }))
    expect(await dialog().findByText(/Tenant sumber ini sudah terhubung/)).toBeInTheDocument()
  })
})

describe('connection detail', () => {
  it('shows the received-vs-processed counts, the configuration and the OptiFleet settings by name', async () => {
    await open(all)

    expect(screen.getByText('Aktif', { selector: '.badge' })).toBeInTheDocument()
    const counts = within(screen.getByRole('heading', { name: 'Kejadian diterima' }).closest('section') as HTMLElement)
    expect(counts.getByRole('link', { name: '2' })).toHaveAttribute('href', '/app/integrasi/kejadian?connection_id=c-1&status=NEEDS_REVIEW')
    expect(counts.getByText('Gagal permanen (dead letter)').nextSibling).toHaveTextContent('1')
    expect(counts.getByText('Gagal permanen (dead letter)').nextSibling?.firstChild).toHaveAttribute('href', '/app/integrasi/kejadian?connection_id=c-1&status=DEAD_LETTER')
    expect(counts.getByText('Gagal permanen (dead letter)')).toBeInTheDocument()
    expect(screen.getByText('Tersimpan (tidak ditampilkan)')).toBeInTheDocument()
    expect(await screen.findByText('BANK-01 · Bank BCA Operasional')).toBeInTheDocument()
    expect(screen.getByText('BENGKEL · Beban perawatan bengkel')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Kotak masuk kejadian' }).map((a) => a.getAttribute('href'))).toContain('/app/integrasi/kejadian?connection_id=c-1')
  })

  it('offers only the moves the status allows and activates after a confirmation', async () => {
    let status = 'READY'
    const calls = await open(all, detailRoutes({
      [ONE]: () => ({ data: connection({ status: status as 'READY' }) }),
      [`POST ${IT}/connections/c-1/activate`]: () => {
        status = 'ACTIVE'
        return { data: connection({ status: 'ACTIVE' }) }
      },
    }))
    expect(screen.getByRole('button', { name: 'Aktifkan' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Putuskan' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Jeda' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sinkronkan sekarang' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Aktifkan' }))
    expect(dialog().getByText(/riwayat sebelumnya tidak pernah diposting otomatis/)).toBeInTheDocument()
    await userEvent.click(dialog().getByRole('button', { name: 'Aktifkan' }))

    await waitFor(() => expect(screen.getByRole('button', { name: 'Jeda' })).toBeInTheDocument())
    expect(writes(calls)).toEqual([expect.objectContaining({ method: 'POST', url: `${IT}/connections/c-1/activate` })])
    expect(screen.getByRole('button', { name: 'Sinkronkan sekarang' })).toBeInTheDocument()
  })

  it('shows the API refusal when activation is not allowed, in Indonesian for a known code and verbatim otherwise', async () => {
    await open(all, detailRoutes({
      [ONE]: { data: connection({ status: 'READY' }) },
      [`POST ${IT}/connections/c-1/activate`]: { status: 422, data: { message: 'incomplete', code: 'CONNECTION_INCOMPLETE', details: { missing: ['credential', 'settings.expense_category_id'] } } },
    }))
    await userEvent.click(screen.getByRole('button', { name: 'Aktifkan' }))
    await userEvent.click(dialog().getByRole('button', { name: 'Aktifkan' }))
    expect(await dialog().findByText('Koneksi belum lengkap: kredensial, kategori beban harus diisi.')).toBeInTheDocument()
  })

  it('shows the message of an unknown refusal as the API sent it', async () => {
    await open(all, detailRoutes({
      [ONE]: { data: connection({ status: 'READY', settings: [] as never }) }, // an empty settings object may arrive as []
      [`POST ${IT}/connections/c-1/activate`]: { status: 409, data: { message: 'Pilih akun kas/bank pembayaran dahulu.', code: 'CONNECTION_SETTINGS_REQUIRED' } },
    }))
    expect(screen.getByText(/wajib diisi sebelum koneksi OptiFleet dapat diaktifkan/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Aktifkan' }))
    await userEvent.click(dialog().getByRole('button', { name: 'Aktifkan' }))
    expect(await dialog().findByText('Pilih akun kas/bank pembayaran dahulu.')).toBeInTheDocument()
  })

  it('runs "sync now" and shows the counts of that run', async () => {
    const calls = await open(all, detailRoutes({
      [`POST ${IT}/connections/c-1/sync-now`]: { data: { skipped: false, pages: 2, received: 5, duplicates: 1, conflicts: 0, processed: 4, posted: 3, error: null } },
    }))
    await userEvent.click(screen.getByRole('button', { name: 'Sinkronkan sekarang' }))

    const card = within((await screen.findByRole('heading', { name: 'Hasil sinkronisasi' })).closest('section') as HTMLElement)
    expect(card.getByText('Halaman ditarik').nextSibling).toHaveTextContent('2')
    expect(card.getByText('Diposting').nextSibling).toHaveTextContent('3')
    expect(writes(calls)).toEqual([expect.objectContaining({ method: 'POST', url: `${IT}/connections/c-1/sync-now` })])
    expect(gets(calls, `${IT}/connections/c-1/summary`).length).toBeGreaterThan(1) // the counts were read again
  })

  it('disconnects only after a confirmation', async () => {
    const calls = await open(all, detailRoutes({ [`POST ${IT}/connections/c-1/disconnect`]: { data: connection({ status: 'DISCONNECTED' }) } }))
    await userEvent.click(screen.getByRole('button', { name: 'Putuskan' }))
    expect(writes(calls)).toHaveLength(0)
    await userEvent.click(dialog().getByRole('button', { name: 'Putuskan' }))
    await waitFor(() => expect(writes(calls)).toEqual([expect.objectContaining({ url: `${IT}/connections/c-1/disconnect` })]))
  })

  it('edits only what changed, never sends an empty credential, and locks the source reference and cutover once events exist', async () => {
    const calls = await open(all, detailRoutes({ [`PATCH ${IT}/connections/c-1`]: { data: connection({ name: 'Nama baru' }) } }))
    await userEvent.click(screen.getByRole('button', { name: 'Ubah' }))

    expect(dialog().getByLabelText('Referensi tenant sumber')).toBeDisabled()
    expect(dialog().getByLabelText('Tanggal cutover')).toBeDisabled()
    expect(dialog().getByLabelText('Kredensial')).toHaveValue('')
    const name = dialog().getByLabelText('Nama koneksi')
    await userEvent.clear(name)
    await userEvent.type(name, 'Nama baru')
    await userEvent.click(dialog().getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(writes(calls)).toEqual([expect.objectContaining({ method: 'PATCH', url: `${IT}/connections/c-1`, data: { name: 'Nama baru' } })]))
  })

  it('explains CONNECTION_LOCKED when the API refuses a change to the cutover', async () => {
    await open(all, detailRoutes({
      [SUMMARY]: { data: summary({ received: 0, posted: 0, counts: {} }) },
      [`PATCH ${IT}/connections/c-1`]: { status: 409, data: { message: 'locked', code: 'CONNECTION_LOCKED', details: { fields: ['cutover_date'] } } },
    }))
    await userEvent.click(screen.getByRole('button', { name: 'Ubah' }))
    fireEvent.change(dialog().getByLabelText('Tanggal cutover'), { target: { value: '2026-09-01' } })
    await userEvent.click(dialog().getByRole('button', { name: 'Simpan' }))
    expect(await dialog().findByText('Kejadian sudah diterima pada koneksi ini, jadi tanggal cutover tidak dapat diubah lagi.')).toBeInTheDocument()
  })

  it('saves the OptiFleet settings as ids of the tenant cash/bank account and expense category', async () => {
    const calls = await open(all, detailRoutes({
      [ONE]: { data: connection({ settings: {} }) },
      [`PATCH ${IT}/connections/c-1`]: { data: connection() },
    }))
    await userEvent.click(screen.getByRole('button', { name: 'Ubah pengaturan' }))
    await userEvent.selectOptions(await dialog().findByLabelText('Akun kas/bank pembayaran utang'), 'cb-1')
    expect(dialog().queryByRole('option', { name: /Beban lain/ })).not.toBeInTheDocument() // an inactive category is not offered
    await userEvent.selectOptions(dialog().getByLabelText('Kategori beban faktur bengkel'), 'ec-1')
    await userEvent.click(dialog().getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(writes(calls)).toEqual([expect.objectContaining({
      method: 'PATCH', url: `${IT}/connections/c-1`, data: { settings: { payables_cash_bank_account_id: 'cb-1', expense_category_id: 'ec-1' } },
    })]))
  })

  it('shows the API refusal of a setting inside the dialog', async () => {
    await open(all, detailRoutes({ [`PATCH ${IT}/connections/c-1`]: { status: 422, data: { message: 'The expense category does not exist or is inactive.', code: 'CONNECTION_SETTINGS_INVALID', details: { field: 'settings.expense_category_id' } } } }))
    await userEvent.click(screen.getByRole('button', { name: 'Ubah pengaturan' }))
    await userEvent.selectOptions(await dialog().findByLabelText('Akun kas/bank pembayaran utang'), 'cb-2')
    await userEvent.click(dialog().getByRole('button', { name: 'Simpan' }))
    expect(await dialog().findByText(/Pengaturan koneksi tidak valid/)).toBeInTheDocument()
  })

  it('sends only the setting that changed (the server merges them key by key)', async () => {
    const calls = await open(all, detailRoutes({ [`PATCH ${IT}/connections/c-1`]: { data: connection() } }))
    await userEvent.click(screen.getByRole('button', { name: 'Ubah pengaturan' }))
    await userEvent.click(dialog().getByRole('button', { name: 'Simpan' }))
    expect(writes(calls)).toHaveLength(0) // nothing changed, nothing sent
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Ubah pengaturan' }))
    await userEvent.selectOptions(await dialog().findByLabelText('Akun kas/bank pembayaran utang'), 'cb-2')
    await userEvent.click(dialog().getByRole('button', { name: 'Simpan' }))
    await waitFor(() => expect(writes(calls)).toEqual([expect.objectContaining({ method: 'PATCH', data: { settings: { payables_cash_bank_account_id: 'cb-2' } } })]))
  })

  it('has no OptiFleet settings for another source system', async () => {
    await open(all, detailRoutes({ [ONE]: { data: connection({ source_system: 'pos_toko', settings: undefined }) } }))
    expect(screen.queryByRole('heading', { name: 'Pengaturan OptiFleet' })).not.toBeInTheDocument()
  })

  it('is read-only for a viewer', async () => {
    await open(viewer)
    expect(screen.queryByRole('button', { name: /Putuskan|Jeda|Ubah|Sinkronkan/ })).not.toBeInTheDocument()
    expect(screen.getByText(/Anda tidak memiliki izin mengelola koneksi/)).toBeInTheDocument()
  })

  it('is read-only in a read-only subscription even for a manager', async () => {
    await open(all, detailRoutes(), { mode: 'READ_ONLY' })
    expect(screen.queryByRole('button', { name: /Putuskan|Jeda|Ubah|Sinkronkan/ })).not.toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
  })

  it('shows the error with a retry when the connection cannot be loaded', async () => {
    boot(all, { [ONE]: { status: 404, data: { message: 'x', code: 'CONNECTION_NOT_FOUND' } }, [SUMMARY]: { status: 404, data: { message: 'x', code: 'CONNECTION_NOT_FOUND' } } })
    renderApp('/app/integrasi/koneksi/c-1')
    expect(await screen.findByText('Koneksi tidak ditemukan.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })
})

const EVENTS = `GET ${IT}/events`
const eventRoutes = (rows = [event(), event({ id: 'ev-2', event_id: 'evt-0002', status: 'POSTED', last_error_code: null, last_error: null, resulting_journal_id: 'j-1' }), event({ id: 'ev-3', event_id: 'evt-0003', status: 'REJECTED', conflict: true })]) => ({
  [EVENTS]: { data: page(rows) },
  [LIST]: { data: page([connection(), connection({ id: 'c-2', name: 'Cadangan' })]) },
})

describe('event inbox', () => {
  it('lists events with labelled type, status, attempts and error code, and a retry only on retryable ones', async () => {
    boot(all, eventRoutes())
    renderApp('/app/integrasi/kejadian')

    const table = within(await screen.findByRole('table', { name: 'Kejadian integrasi' }))
    expect(table.getAllByText('Faktur bengkel dicatat').length).toBe(3)
    expect(table.getByRole('link', { name: 'evt-0001' })).toHaveAttribute('href', '/app/integrasi/kejadian/ev-1')
    expect(table.getByText('Perlu ditinjau')).toBeInTheDocument()
    expect(table.getByText('Diposting')).toBeInTheDocument()
    expect(table.getByText('Ditolak')).toBeInTheDocument()
    expect(table.getByText('Konflik isi')).toBeInTheDocument()
    expect(table.getAllByText('PERIOD_CLOSED').length).toBe(2)
    expect(table.getByRole('button', { name: 'Ulangi evt-0001' })).toBeInTheDocument()
    expect(table.queryByRole('button', { name: 'Ulangi evt-0002' })).not.toBeInTheDocument() // POSTED
    expect(table.queryByRole('button', { name: 'Ulangi evt-0003' })).not.toBeInTheDocument() // REJECTED is final
  })

  it('sends the filters it was opened with and the ones the user picks', async () => {
    const calls = boot(all, eventRoutes())
    renderApp('/app/integrasi/kejadian?connection_id=c-1&status=FAILED')
    await screen.findByRole('table', { name: 'Kejadian integrasi' })
    expect(gets(calls, `${IT}/events`)[0].params).toEqual({ page: 1, connection_id: 'c-1', status: 'FAILED' })
    expect(screen.getByLabelText('Koneksi')).toHaveValue('c-1')

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'DEAD_LETTER')
    await userEvent.selectOptions(screen.getByLabelText('Jenis kejadian'), 'optifleet.workshop_invoice.payment_recorded')
    fireEvent.change(screen.getByLabelText('Terjadi dari'), { target: { value: '2026-10-01' } })
    await userEvent.click(screen.getByRole('checkbox', { name: /Hanya yang konflik/ }))
    await waitFor(() => expect(gets(calls, `${IT}/events`).at(-1)?.params).toEqual({
      page: 1, connection_id: 'c-1', status: 'DEAD_LETTER', event_type: 'optifleet.workshop_invoice.payment_recorded', from: '2026-10-01', conflict: 1,
    }))
  })

  it('retries after a confirmation that needs a reason, then reads the list again', async () => {
    const calls = boot(all, { ...eventRoutes(), [`POST ${IT}/events/ev-1/retry`]: { data: detail({ status: 'POSTED' }) } })
    renderApp('/app/integrasi/kejadian')
    await userEvent.click(await screen.findByRole('button', { name: 'Ulangi evt-0001' }))

    await userEvent.type(dialog().getByLabelText('Alasan (dicatat di audit)'), 'Periode sudah dibuka')
    await userEvent.click(dialog().getByRole('button', { name: 'Ulangi' }))

    await waitFor(() => expect(writes(calls)).toEqual([expect.objectContaining({ method: 'POST', url: `${IT}/events/ev-1/retry`, data: { reason: 'Periode sudah dibuka' } })]))
    await waitFor(() => expect(gets(calls, `${IT}/events`).length).toBe(2))
    expect(await screen.findByText(/Status sekarang: Diposting/)).toBeInTheDocument()
  })

  it('does not send a retry without a reason', async () => {
    const calls = boot(all, eventRoutes())
    renderApp('/app/integrasi/kejadian')
    await userEvent.click(await screen.findByRole('button', { name: 'Ulangi evt-0001' }))
    await userEvent.click(dialog().getByRole('button', { name: 'Ulangi' }))
    expect(writes(calls)).toHaveLength(0)
  })

  it('shows the API refusal of a retry inside the dialog', async () => {
    boot(all, { ...eventRoutes(), [`POST ${IT}/events/ev-1/retry`]: { status: 409, data: { message: 'A POSTED event cannot be retried.', code: 'EVENT_NOT_RETRYABLE' } } })
    renderApp('/app/integrasi/kejadian')
    await userEvent.click(await screen.findByRole('button', { name: 'Ulangi evt-0001' }))
    await userEvent.type(dialog().getByLabelText('Alasan (dicatat di audit)'), 'Coba lagi')
    await userEvent.click(dialog().getByRole('button', { name: 'Ulangi' }))
    expect(await dialog().findByText('Kejadian dengan status ini tidak dapat diulang.')).toBeInTheDocument()
  })

  it('has no retry without the retry permission or in a read-only subscription', async () => {
    boot(viewer, eventRoutes())
    const first = renderApp('/app/integrasi/kejadian')
    await screen.findByRole('table', { name: 'Kejadian integrasi' })
    expect(screen.queryByRole('button', { name: /Ulangi/ })).not.toBeInTheDocument()
    first.unmount()

    boot(all, eventRoutes(), { mode: 'READ_ONLY' })
    renderApp('/app/integrasi/kejadian')
    await screen.findByRole('table', { name: 'Kejadian integrasi' })
    expect(screen.queryByRole('button', { name: /Ulangi/ })).not.toBeInTheDocument()
  })

  it('shows the empty state, and the error with a retry', async () => {
    boot(all, { [EVENTS]: { data: page([]) }, [LIST]: { data: page([]) } })
    const first = renderApp('/app/integrasi/kejadian')
    expect(await screen.findByText('Belum ada kejadian')).toBeInTheDocument()
    first.unmount()

    boot(all, { [EVENTS]: { status: 500, data: { message: 'boom' } }, [LIST]: { data: page([]) } })
    renderApp('/app/integrasi/kejadian')
    expect(await screen.findByText(/Terjadi kesalahan pada server/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })
})

describe('event detail', () => {
  const ONE_EVENT = `GET ${IT}/events/ev-1`
  const openEvent = async (permissions: string[], data = detail(), routes: Record<string, unknown> = {}, options = {}) => {
    const calls = boot(permissions, { [ONE_EVENT]: { data }, ...routes }, options)
    renderApp('/app/integrasi/kejadian/ev-1')
    await screen.findByRole('heading', { name: 'Faktur bengkel dicatat' })
    return calls
  }

  it('shows the envelope, the read-only payload, the explained error and the document and journal links', async () => {
    await openEvent(all, detail({ source_links: [link()], conflict: true, resulting_journal_id: 'j-1', status: 'NEEDS_REVIEW', last_error_code: 'INVOICE_NOT_FOUND', last_error: 'Invoice wi-1 not found.' }))

    expect(screen.getByText(/Faktur yang dirujuk belum ada di OptiEntry/)).toBeInTheDocument()
    expect(screen.getByText('Pesan server: Invoice wi-1 not found.')).toBeInTheDocument()
    expect(screen.getByText(/Konflik isi\./)).toBeInTheDocument()
    expect(screen.getByLabelText('Muatan kejadian')).toHaveTextContent('"total_amount": "1110000.00"')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument() // the payload is never editable
    expect(screen.getAllByText('workshop_invoice · WI-001')).toHaveLength(2) // the envelope and the link table
    expect(screen.getByText('fleet-co-1')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Faktur vendor' })).toHaveAttribute('href', '/app/akuntansi/faktur-vendor/ap-1')
    expect(screen.getAllByRole('link', { name: 'Buka jurnal' })[0]).toHaveAttribute('href', '/app/akuntansi/jurnal/j-1')
    expect(screen.getByRole('link', { name: 'Buka koneksi' })).toHaveAttribute('href', '/app/integrasi/koneksi/c-1')
  })

  it('falls back to the API message for an error code it does not know', async () => {
    await openEvent(all, detail({ last_error_code: 'QueryException', last_error: 'SQLSTATE[40001]: serialization failure', status: 'FAILED', attempts: 2, next_attempt_at: '2026-10-10T03:05:00Z' }))
    expect(screen.getByText('QueryException', { selector: 'strong' }).parentElement).toHaveTextContent('SQLSTATE[40001]: serialization failure')
  })

  it('retries from the detail with the reason and shows the new state', async () => {
    let status = 'NEEDS_REVIEW'
    const calls = await openEvent(all, detail(), {
      [ONE_EVENT]: () => ({ data: detail({ status: status as 'POSTED', last_error_code: status === 'POSTED' ? null : 'PERIOD_CLOSED' }) }),
      [`POST ${IT}/events/ev-1/retry`]: () => {
        status = 'POSTED'
        return { data: detail({ status: 'POSTED' }) }
      },
    })
    await userEvent.click(screen.getByRole('button', { name: 'Ulangi pemrosesan' }))
    await userEvent.type(dialog().getByLabelText('Alasan (dicatat di audit)'), 'Periode dibuka')
    await userEvent.click(dialog().getByRole('button', { name: 'Ulangi' }))

    await waitFor(() => expect(writes(calls)).toEqual([expect.objectContaining({ url: `${IT}/events/ev-1/retry`, data: { reason: 'Periode dibuka' } })]))
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Ulangi pemrosesan' })).not.toBeInTheDocument())
  })

  it('has no retry for a posted event, without the permission, or in a read-only subscription', async () => {
    await openEvent(all, detail({ status: 'POSTED', last_error_code: null, last_error: null }))
    expect(screen.queryByRole('button', { name: 'Ulangi pemrosesan' })).not.toBeInTheDocument()
  })

  it('has no retry for a viewer', async () => {
    await openEvent(viewer)
    expect(screen.queryByRole('button', { name: 'Ulangi pemrosesan' })).not.toBeInTheDocument()
  })

  it('has no retry in a read-only subscription', async () => {
    await openEvent(retrier, detail(), {}, { mode: 'READ_ONLY' })
    expect(screen.queryByRole('button', { name: 'Ulangi pemrosesan' })).not.toBeInTheDocument()
  })

  it('shows the error with a retry when the event cannot be loaded', async () => {
    boot(all, { [ONE_EVENT]: { status: 404, data: { message: 'x', code: 'EVENT_NOT_FOUND' } } })
    renderApp('/app/integrasi/kejadian/ev-1')
    expect(await screen.findByText('Kejadian tidak ditemukan.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })
})

describe('access', () => {
  it('refuses the pages to a user without the permission or the feature', async () => {
    boot(['accounting.journal.view'], {})
    const first = renderApp('/app/integrasi')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
    first.unmount()

    boot(viewer, {}, { features: { INTEGRATION_CONNECTION: false } })
    renderApp('/app/integrasi/kejadian')
    expect(await screen.findByText('Fitur tidak tersedia')).toBeInTheDocument()
  })

  it('shows the Integrasi menu group to a user who may view it', async () => {
    boot(viewer, { [LIST]: { data: page([]) } })
    renderApp('/app/integrasi')
    await screen.findByText('Belum ada koneksi')
    expect(screen.getAllByRole('link', { name: 'Kotak masuk kejadian' })[0]).toHaveAttribute('href', '/app/integrasi/kejadian')
  })
})
