import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from '../../i18n'
import { renderApp } from '../../test/render'
import { IT, access, all, boot, connection, detail, event, gap, link, manager, optionRoutes, page, summary, viewer, writes } from './testkit'
import type { BookDocument, ConnectionSummary, HealthAlert } from './types'

afterEach(() => vi.restoreAllMocks())

const ONE = `GET ${IT}/connections/c-1`
const SUMMARY = `GET ${IT}/connections/c-1/summary`
const GAPS = `GET ${IT}/gaps`
const dialog = () => within(screen.getByRole('dialog'))

const routes = (over: Record<string, unknown> = {}) => ({ [ONE]: { data: connection({ access: access() }) }, [SUMMARY]: { data: summary() }, ...optionRoutes(), ...over })
const openConnection = async (permissions: string[], over: Record<string, unknown> = {}, options = {}) => {
  const calls = boot(permissions, routes(over), options)
  renderApp('/app/integrasi/koneksi/c-1')
  await screen.findByRole('heading', { name: 'OptiFleet Maju Jaya' })
  return calls
}
const card = (title: string) => within(screen.getByRole('heading', { name: title }).closest('section') as HTMLElement)

const alert = (over: Partial<HealthAlert>): HealthAlert => ({ code: 'DEAD_LETTERS', severity: 'ALERT', count: 1, ...over })
const health = (...alerts: HealthAlert[]): ConnectionSummary['health'] => ({ level: alerts.some((a) => a.severity === 'ALERT') ? 'ALERT' : alerts.length ? 'WARN' : 'OK', alerts })

describe('why a connection does not deliver (A19)', () => {
  it('says that OptiEntry itself holds the connection, apart from any source problem', async () => {
    await openConnection(all, { [ONE]: { data: connection({ access: access({ entry: { code: 'CONNECTOR_NOT_ENTITLED', reasons: ['x'] } }) }) } })
    const banner = screen.getByText(/OptiEntry sendiri tidak menarik atau memproses koneksi ini sekarang/)
    expect(banner).toHaveTextContent('Konektor tidak tersedia untuk organisasi ini')
    expect(banner).toHaveTextContent('bukan gangguan sumber dan bukan berhenti berlangganan')
    expect(screen.queryByText(/Sumber menolak penarikan/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Sumber sedang tidak menjawab/)).not.toBeInTheDocument()
  })

  it('says that the source refuses on purpose, with the source\'s own reason, and what stays intact', async () => {
    await openConnection(all, { [ONE]: { data: connection({ access: access({ state: 'REFUSED', code: 'FEED_UNAUTHENTICATED', since: '2026-10-10T02:00:00Z' }) }) } })
    expect(screen.getByText(/Sumber menolak penarikan \(bukan gangguan\)/)).toHaveTextContent('Kredensial tidak dikenal, dicabut, atau kedaluwarsa')
    expect(screen.getByText(/Riwayat yang sudah diterima dan dibukukan tetap utuh/)).toBeInTheDocument()
    expect(screen.queryByText(/OptiEntry sendiri tidak menarik/)).not.toBeInTheDocument()
    const facts = card('Akses dan pemasangan')
    expect(facts.getByText('Ditolak sumber')).toBeInTheDocument()
    expect(facts.getByText('FEED_UNAUTHENTICATED')).toBeInTheDocument()
  })

  it('says that the source is not answering as an outage, not an unsubscription', async () => {
    await openConnection(all, { [ONE]: { data: connection({ access: access({ state: 'UNAVAILABLE', code: 'FEED_GOVERNANCE_UNAVAILABLE' }) }) } })
    expect(screen.getByText(/Sumber sedang tidak menjawab \(gangguan, bukan berhenti berlangganan\)/)).toHaveTextContent('tidak ada riwayat yang berubah')
  })

  it('names the pairing problems of a page, in the words of the pairing check', async () => {
    await openConnection(all, { [ONE]: { data: connection({ access: access({ state: 'REFUSED', code: 'FEED_PAIRING_MISMATCH', pairing: { required: true, linked: true, verified_nexus_tenant_id: null } }) }) } })
    expect(screen.getByText(/Sumber menolak penarikan/)).toHaveTextContent('tenant OptiNexus lain daripada organisasi ini')
    expect(card('Akses dan pemasangan').getByText('Wajib, belum ada jawaban sumber yang diverifikasi')).toBeInTheDocument()
  })

  it('shows the verified pairing and no banner while everything is fine', async () => {
    await openConnection(all)
    expect(card('Akses dan pemasangan').getByText('Terverifikasi untuk tenant OptiNexus nx-tenant-1')).toBeInTheDocument()
    expect(card('Akses dan pemasangan').getByText('Tidak ada yang menahan koneksi ini')).toBeInTheDocument()
    expect(screen.queryByText(/OptiEntry sendiri tidak menarik|Sumber menolak penarikan|Sumber sedang tidak menjawab/)).not.toBeInTheDocument()
  })

  it('shows a stopped (paused) connection\'s open gap with the policy that applies when it is switched on again', async () => {
    await openConnection(all, { [ONE]: { data: connection({ status: 'PAUSED', open_gap: { id: 'g-9', open_reason: 'PAUSED', policy: 'REQUIRES_REVIEW', sequence_at_open: 41, opened_at: '2026-10-08T03:00:00Z' } }) } })
    expect(screen.getByText(/Koneksi sedang tidak mendengarkan sejak/)).toHaveTextContent('Koneksi dijeda')
    expect(screen.getByText(/Koneksi sedang tidak mendengarkan sejak/)).toHaveTextContent('urutan 41')
    expect(screen.getAllByText(/Kejadian selama jeda ditahan sampai seseorang memutuskan/).length).toBeGreaterThan(0)
  })
})

describe('health and source position (A17)', () => {
  it('lists each alert with what to do and links the inbox where one applies', async () => {
    await openConnection(all, {
      [SUMMARY]: { data: summary({ health: health(
        alert({ code: 'MISSING_RECORDED_EVENT', count: 2 }),
        alert({ code: 'SOURCE_BEHIND_CURSOR', cursor: 41, high_water: 30 }),
        alert({ code: 'QUARANTINED_EVENTS', severity: 'WARN', count: 3 }),
        alert({ code: 'OPEN_CONFLICTS', severity: 'WARN', count: 1 }),
      ) }) },
    })
    const health_ = card('Kesehatan koneksi')
    expect(health_.getByText('Perlu tindakan')).toBeInTheDocument()
    expect(health_.getByText('Kejadian pencatatan yang hilang')).toBeInTheDocument()
    expect(health_.getByText(/2 kejadian \(pembayaran, koreksi, atau pembatalan\) menunggu faktur yang tidak pernah tiba/)).toHaveTextContent('tidak menyentuh data yang sudah diposting')
    expect(health_.getByText(/Kursor kami \(41\) lebih maju daripada high water sumber \(30\)/)).toBeInTheDocument()
    expect(health_.getByText(/3 kejadian dibangun ulang dari keadaan saat ini/)).toHaveTextContent('Tidak pernah dibukukan otomatis')
    const links = health_.getAllByRole('link', { name: 'Buka kotak masuk' }).map((a) => a.getAttribute('href'))
    expect(links).toContain('/app/integrasi/kejadian?connection_id=c-1&status=NEEDS_REVIEW')
    expect(links).toContain('/app/integrasi/kejadian?connection_id=c-1&conflict=1')
  })

  it('says there are no alerts when the connection is healthy', async () => {
    await openConnection(all, { [SUMMARY]: { data: summary({ health: health() }) } })
    expect(card('Kesehatan koneksi').getByText('Tidak ada peringatan.')).toBeInTheDocument()
  })

  it('shows the source high water against our cursor and the queue', async () => {
    await openConnection(all, {
      [SUMMARY]: { data: summary({
        source: { high_water: 50, high_water_at: '2026-10-10T03:00:00Z', cursor: 41, lag: 9 },
        backlog: { due: 3, oldest_due_at: '2026-10-10T02:00:00Z', oldest_due_age_seconds: 3600, blocked_by: 'CONNECTOR_NOT_ENTITLED', stalled: false },
      }) },
    })
    const source = card('Posisi umpan sumber')
    expect(source.getByText('50')).toBeInTheDocument()
    expect(source.getByText('41')).toBeInTheDocument()
    expect(source.getByText('9 kejadian belum ditarik')).toBeInTheDocument()
    expect(source.getByText('60 menit')).toBeInTheDocument()
    expect(source.getByText('konektor tidak tersedia untuk organisasi')).toBeInTheDocument()
  })

  it('shows the health level in the connection list only for a listening connection', async () => {
    boot(manager, { [`GET ${IT}/connections`]: { data: page([connection({ health_level: 'ALERT' }), connection({ id: 'c-2', name: 'Cadangan', status: 'PAUSED', health_level: 'OK' })]) } })
    renderApp('/app/integrasi')
    const table = within(await screen.findByRole('table', { name: 'Koneksi integrasi' }))
    expect(table.getByText('Perlu tindakan')).toBeInTheDocument()
    expect(table.queryByText('Sehat')).not.toBeInTheDocument()
  })
})

describe('what is processed is not what is in the ledger (A27)', () => {
  it('counts a draft apart from the ledger and says so', async () => {
    await openConnection(all, {
      [SUMMARY]: { data: summary({ posted: 6, books: { IN_LEDGER: 2, AWAITING_APPROVAL: 1, DRAFT: 2, REVERSED: 0, VOID: 0, INFORMATIONAL: 1, NO_EFFECT: 0, UNCLASSIFIED: 0 } }) },
    })
    expect(card('Kejadian diterima').getByText('Selesai diproses').nextSibling).toHaveTextContent('6')
    const books = card('Hasil pembukuan')
    expect(books.getByText('Di buku besar').closest('div')).toHaveTextContent('2')
    expect(books.getByText('Draf, belum di buku besar').closest('div')).toHaveTextContent('2')
    expect(books.getByText('Menunggu persetujuan').closest('div')).toHaveTextContent('1')
    expect(books.getByText(/draf dan dokumen yang menunggu persetujuan belum dihitung/)).toBeInTheDocument()
    expect(books.queryByText('Sudah dibalik')).not.toBeInTheDocument() // states with no event are left out
  })

  const document_ = (over: Partial<BookDocument> = {}): BookDocument => ({
    document_type: 'ap_invoice', document_id: 'ap-1', status: 'DRAFT', state: 'DRAFT', journal_id: null, journal_status: null, source_type: 'workshop_invoice', source_id: 'wi-1', ...over,
  })
  const openEvent = async (data = detail({ status: 'POSTED', last_error_code: null, last_error: null, accounting_outcome: 'DRAFT_DOCUMENT', document_status: 'DRAFT', books: { state: 'DRAFT', documents: [document_()] }, source_links: [link({ journal_id: null })] }), permissions = all, routes_: Record<string, unknown> = {}) => {
    const calls = boot(permissions, { [`GET ${IT}/events/ev-1`]: { data }, ...routes_ })
    renderApp('/app/integrasi/kejadian/ev-1')
    await screen.findByRole('heading', { name: 'Faktur bengkel dicatat' })
    return calls
  }

  it('separates the processing status, what processing decided and where the books stand now', async () => {
    await openEvent()
    const result = card('Hasil pembukuan')
    expect(result.getByText('Pemrosesan').nextSibling).toHaveTextContent('Selesai diproses')
    expect(result.getByText('Diputuskan saat diproses').nextSibling).toHaveTextContent('Dokumen draf disiapkan')
    expect(result.getByText('Keadaan pembukuan sekarang', { selector: 'dt' }).nextSibling).toHaveTextContent('Draf, belum di buku besar')
    expect(result.getByText(/Dokumennya baru disiapkan sebagai draf/)).toBeInTheDocument()
    expect(result.getByRole('link', { name: 'Faktur vendor' })).toHaveAttribute('href', '/app/akuntansi/faktur-vendor/ap-1')
    expect(screen.getByText(/Ini belum berarti sudah masuk buku besar/)).toBeInTheDocument()
  })

  it('links a vendor payment document (its type is vendor_payment) and shows the live journal status', async () => {
    await openEvent(detail({
      status: 'POSTED', last_error_code: null, last_error: null, accounting_outcome: 'JOURNAL_POSTED', resulting_journal_id: 'j-2',
      books: { state: 'IN_LEDGER', documents: [document_({ document_type: 'vendor_payment', document_id: 'vp-1', status: 'POSTED', state: 'IN_LEDGER', journal_id: 'j-2', journal_status: 'POSTED' })] },
      source_links: [link({ document_type: 'vendor_payment', document_id: 'vp-1' })],
    }), [...all, 'accounting.ap_payment.view'])
    const result = card('Hasil pembukuan')
    expect(result.getByRole('link', { name: 'Pembayaran vendor' })).toHaveAttribute('href', '/app/akuntansi/pembayaran-vendor/vp-1')
    expect(result.getAllByText('Di buku besar', { selector: '.badge' }).length).toBeGreaterThan(0)
    expect(result.getByText(/jurnal Diposting/)).toBeInTheDocument()
  })

  it('names an informational event as such, and an unprocessed one as not processed', async () => {
    await openEvent(detail({ status: 'POSTED', last_error_code: null, last_error: null, accounting_outcome: 'INFORMATIONAL', books: { state: 'INFORMATIONAL', documents: [] } }))
    expect(card('Hasil pembukuan').getByText('Informasi saja, tidak dibukukan')).toBeInTheDocument()
    expect(card('Hasil pembukuan').getByText('Informasi saja', { selector: '.badge' })).toBeInTheDocument()
  })

  it('shows an event that did not finish as not processed, with no book state', async () => {
    await openEvent(detail({ status: 'NEEDS_REVIEW' }))
    const result = card('Hasil pembukuan')
    expect(result.getByText('Belum selesai diproses', { selector: '.badge' })).toBeInTheDocument()
    expect(result.getByText('Belum selesai diproses, jadi belum ada hasil pembukuan.')).toBeInTheDocument()
  })

  it('lists the book state of processed events in the inbox, and nothing for the others', async () => {
    boot(all, {
      [`GET ${IT}/events`]: { data: page([
        event({ id: 'ev-a', event_id: 'evt-a', status: 'POSTED', last_error_code: null, accounting_outcome: 'DRAFT_DOCUMENT', books_state: 'DRAFT' }),
        event({ id: 'ev-b', event_id: 'evt-b', status: 'POSTED', last_error_code: null, accounting_outcome: 'JOURNAL_POSTED', books_state: 'IN_LEDGER' }),
        event({ id: 'ev-c', event_id: 'evt-c', status: 'NEEDS_REVIEW', last_error_code: 'PERIOD_CLOSED' }),
      ]) },
      [`GET ${IT}/connections`]: { data: page([connection()]) },
    })
    renderApp('/app/integrasi/kejadian')
    const table = within(await screen.findByRole('table', { name: 'Kejadian integrasi' }))
    expect(table.getByText('Draf, belum di buku besar')).toBeInTheDocument()
    expect(table.getByText('Di buku besar')).toBeInTheDocument()
    expect(table.getAllByText('Selesai diproses')).toHaveLength(2)
  })
})

describe('gaps', () => {
  const gapRoutes = (rows = [gap(), gap({ id: 'g-2', sequence_at_open: 10, ceiling_sequence: 20, events: { total: 2, held: 0, processed: 2, refused: 0 }, opened_at: '2026-10-01T03:00:00Z', closed_at: '2026-10-02T03:00:00Z', open_reason: 'DISCONNECTED' })]) => ({ [GAPS]: { data: page(rows) } })

  it('lists the gaps of the connection with what each one held, processed and refused', async () => {
    await openConnection(all, gapRoutes())
    const table = within(await screen.findByRole('table', { name: 'Jeda koneksi' }))
    expect(table.getAllByText('Ditutup')).toHaveLength(2)
    expect(table.getByText('41 sampai 47')).toBeInTheDocument()
    expect(table.getByText('Koneksi diputus')).toBeInTheDocument()
    expect(table.getByRole('link', { name: 'Lihat yang ditahan' })).toHaveAttribute('href', '/app/integrasi/kejadian?connection_id=c-1&status=NEEDS_REVIEW')
    expect(table.getAllByRole('button', { name: /Tinjau jeda/ })).toHaveLength(1) // only the gap that still holds events
  })

  it('processes the held events after a reason, and offers another round while some remain', async () => {
    const calls = await openConnection(all, { ...gapRoutes(), [`POST ${IT}/gaps/g-1/resolution`]: { data: { decision: 'PROCESS', handled: 100, processed: 98, remaining: 12 } } })
    await userEvent.click(await screen.findByRole('button', { name: /Tinjau jeda/ }))
    expect(dialog().getByText(/Ada 4 kejadian yang ditahan/)).toBeInTheDocument()

    await userEvent.click(dialog().getByRole('button', { name: 'Jalankan keputusan' }))
    expect(writes(calls)).toHaveLength(0) // a reason is required first
    await userEvent.type(dialog().getByLabelText('Alasan (dicatat di audit)'), 'Sudah diperiksa dengan sumber')
    await userEvent.click(dialog().getByRole('button', { name: 'Jalankan keputusan' }))

    await waitFor(() => expect(writes(calls)).toEqual([expect.objectContaining({ url: `${IT}/gaps/g-1/resolution`, data: { decision: 'PROCESS', reason: 'Sudah diperiksa dengan sumber' } })]))
    expect(await dialog().findByText('Masih ada yang ditahan. Jalankan lagi untuk kelompok berikutnya.')).toBeInTheDocument()
  })

  it('dismisses all of them when chosen, and closes when nothing remains', async () => {
    const calls = await openConnection(all, { ...gapRoutes(), [`POST ${IT}/gaps/g-1/resolution`]: { data: { decision: 'DISMISS', handled: 4, processed: 0, remaining: 0 } } })
    await userEvent.click(await screen.findByRole('button', { name: /Tinjau jeda/ }))
    await userEvent.click(dialog().getByRole('radio', { name: 'Tolak semuanya (tidak dibukukan)' }))
    await userEvent.type(dialog().getByLabelText('Alasan (dicatat di audit)'), 'Tidak berlaku lagi')
    await userEvent.click(dialog().getByRole('button', { name: 'Jalankan keputusan' }))
    await waitFor(() => expect(writes(calls)).toEqual([expect.objectContaining({ data: { decision: 'DISMISS', reason: 'Tidak berlaku lagi' } })]))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('translates the refusal of a gap that is not closed yet', async () => {
    await openConnection(all, { ...gapRoutes(), [`POST ${IT}/gaps/g-1/resolution`]: { status: 409, data: { message: 'x', code: 'GAP_NOT_CLOSED' } } })
    await userEvent.click(await screen.findByRole('button', { name: /Tinjau jeda/ }))
    await userEvent.type(dialog().getByLabelText('Alasan (dicatat di audit)'), 'Coba')
    await userEvent.click(dialog().getByRole('button', { name: 'Jalankan keputusan' }))
    expect(await dialog().findByText(/jeda masih terbuka dan belum dapat diputuskan/)).toBeInTheDocument()
  })

  it('offers no decision to a viewer or in a read-only subscription', async () => {
    await openConnection(viewer, gapRoutes())
    await screen.findByRole('table', { name: 'Jeda koneksi' })
    expect(screen.queryByRole('button', { name: /Tinjau jeda/ })).not.toBeInTheDocument()
  })

  it('shows the error with a retry when the gaps cannot be loaded', async () => {
    await openConnection(all, { [GAPS]: { status: 500, data: { message: 'boom' } } })
    expect(await within(screen.getByRole('heading', { name: 'Jeda dan kejadian yang ditahan' }).closest('section') as HTMLElement).findByText(/Terjadi kesalahan pada server/)).toBeInTheDocument()
  })
})

describe('content conflicts', () => {
  const stored = detail().envelope
  const conflicting = {
    id: 'cf-1', received_hash: 'abcdef0123456789abcdef', received_at: '2026-10-10T04:00:00Z', resolution: null, resolution_reason: null, resolved_at: null,
    envelope: { ...stored, payload: { total_amount: '1250000.00', tax_total: '110000.00', currency: 'IDR' } },
  } as const
  const open = async (data = detail({ conflict: true, conflicts: [conflicting] }), permissions = all, routes_: Record<string, unknown> = {}) => {
    const calls = boot(permissions, { [`GET ${IT}/events/ev-1`]: { data }, ...routes_ })
    renderApp('/app/integrasi/kejadian/ev-1')
    await screen.findByRole('heading', { name: 'Faktur bengkel dicatat' })
    return calls
  }

  it('shows the stored value against the one received later, field by field', async () => {
    await open()
    const conflict = card('Konflik isi')
    const diff = within(conflict.getByRole('table', { name: 'Perbedaan muatan' }))
    expect(diff.getByText('payload.total_amount')).toBeInTheDocument()
    expect(diff.getByText('1110000.00')).toBeInTheDocument()
    expect(diff.getByText('1250000.00')).toBeInTheDocument()
    expect(diff.queryByText('payload.currency')).not.toBeInTheDocument() // equal values are not listed
    expect(conflict.getByText('Menunggu keputusan')).toBeInTheDocument()
    expect(conflict.getByText('Sidik isi abcdef012345')).toBeInTheDocument()
  })

  it('hides retry and dismiss while the conflict is open and offers the decision to a manager', async () => {
    await open()
    expect(screen.queryByRole('button', { name: 'Ulangi pemrosesan' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tolak kejadian' })).not.toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Putuskan konflik isi' }).length).toBeGreaterThan(0)
  })

  it('does not tell the reader to fix a cause and retry an event that waits for a decision', async () => {
    await open()
    expect(screen.queryByText(/Tidak bisa diproses otomatis/)).not.toBeInTheDocument()
    expect(screen.getAllByText(/Isi kejadian berbeda|konflik isi/i).length).toBeGreaterThan(0)
  })

  it('keeps the original after a reason', async () => {
    const calls = await open(undefined, all, { [`POST ${IT}/events/ev-1/conflict-resolution`]: { data: detail() } })
    await userEvent.click(screen.getAllByRole('button', { name: 'Putuskan konflik isi' })[0])
    await userEvent.click(dialog().getByRole('button', { name: 'Simpan keputusan' }))
    expect(writes(calls)).toHaveLength(0)
    await userEvent.type(dialog().getByLabelText('Alasan (dicatat di audit)'), 'Isi asli benar')
    await userEvent.click(dialog().getByRole('button', { name: 'Simpan keputusan' }))
    await waitFor(() => expect(writes(calls)).toEqual([expect.objectContaining({ url: `${IT}/events/ev-1/conflict-resolution`, data: { decision: 'USE_ORIGINAL', reason: 'Isi asli benar' } })]))
  })

  it('rejects the event when chosen and explains the refusal of an event that is already processed', async () => {
    const calls = await open(undefined, all, { [`POST ${IT}/events/ev-1/conflict-resolution`]: { status: 409, data: { message: 'x', code: 'EVENT_ALREADY_POSTED' } } })
    await userEvent.click(screen.getAllByRole('button', { name: 'Putuskan konflik isi' })[0])
    await userEvent.click(dialog().getByRole('radio', { name: 'Tolak kejadian ini' }))
    await userEvent.type(dialog().getByLabelText('Alasan (dicatat di audit)'), 'Isi baru salah')
    await userEvent.click(dialog().getByRole('button', { name: 'Simpan keputusan' }))
    await waitFor(() => expect(writes(calls)[0].data).toEqual({ decision: 'REJECT', reason: 'Isi baru salah' }))
    expect(await dialog().findByText(/Balik dokumennya, jangan tolak kejadiannya/)).toBeInTheDocument()
  })

  it('shows a decided conflict with its decision and reason, without a decision button', async () => {
    await open(detail({ conflict: false, conflicts: [{ ...conflicting, resolution: 'USE_ORIGINAL', resolution_reason: 'Isi asli benar', resolved_at: '2026-10-10T05:00:00Z' }] }))
    const conflict = card('Konflik isi')
    expect(conflict.getByText('Dipakai isi asli', { selector: '.badge' })).toBeInTheDocument()
    expect(conflict.getByText('Alasan: Isi asli benar')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Putuskan konflik isi' })).not.toBeInTheDocument()
  })

  it('offers no decision to someone who may only retry', async () => {
    await open(undefined, ['accounting.integration.view', 'accounting.integration.retry'])
    expect(screen.queryByRole('button', { name: 'Putuskan konflik isi' })).not.toBeInTheDocument()
  })
})

describe('dismiss and retry of a held or quarantined event', () => {
  it('explains a quarantined event and dismisses it with a reason, never touching the books', async () => {
    const calls = boot(all, {
      [`GET ${IT}/events/ev-1`]: { data: detail({ status: 'NEEDS_REVIEW', last_error_code: 'SOURCE_REVIEW_REQUIRED', last_error: 'x', envelope: { ...detail().envelope, review: { required: true, reason: 'backfill_current_state', state: 'QUARANTINED' } } }) },
      [`POST ${IT}/events/ev-1/dismiss`]: { data: detail({ status: 'REJECTED', last_error_code: 'REVIEW_DISMISSED' }) },
    })
    renderApp('/app/integrasi/kejadian/ev-1')
    await screen.findByRole('heading', { name: 'Faktur bengkel dicatat' })
    expect(screen.getByText(/Sumber menandai kejadian ini harus ditinjau orang/)).toBeInTheDocument()
    expect(screen.getByText('Wajib ditinjau orang (backfill_current_state)')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Tolak kejadian' }))
    expect(dialog().getByText(/Pembukuan tidak tersentuh/)).toBeInTheDocument()
    await userEvent.type(dialog().getByLabelText('Alasan (dicatat di audit)'), 'Tidak berlaku lagi')
    await userEvent.click(dialog().getByRole('button', { name: 'Tolak' }))
    await waitFor(() => expect(writes(calls)).toEqual([expect.objectContaining({ url: `${IT}/events/ev-1/dismiss`, data: { reason: 'Tidak berlaku lagi' } })]))
  })

  it('dismisses from the inbox row and shows the refusal in the dialog', async () => {
    boot(all, {
      [`GET ${IT}/events`]: { data: page([event()]) },
      [`GET ${IT}/connections`]: { data: page([connection()]) },
      [`POST ${IT}/events/ev-1/dismiss`]: { status: 409, data: { message: 'x', code: 'EVENT_NOT_DISMISSIBLE' } },
    })
    renderApp('/app/integrasi/kejadian')
    await userEvent.click(await screen.findByRole('button', { name: 'Tolak evt-0001' }))
    await userEvent.type(dialog().getByLabelText('Alasan (dicatat di audit)'), 'Salah kirim')
    await userEvent.click(dialog().getByRole('button', { name: 'Tolak' }))
    expect(await dialog().findByText('Kejadian dengan status ini tidak dapat ditolak.')).toBeInTheDocument()
  })

  it('explains an event held by a gap and links to the connection', async () => {
    boot(all, { [`GET ${IT}/events/ev-1`]: { data: detail({ status: 'NEEDS_REVIEW', last_error_code: 'GAP_REVIEW', gap_id: 'g-1' }) } })
    renderApp('/app/integrasi/kejadian/ev-1')
    await screen.findByRole('heading', { name: 'Faktur bengkel dicatat' })
    expect(screen.getByText('Kejadian ini terjadi saat koneksi tidak mendengarkan (jeda).')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Buka koneksi dan jedanya' })).toHaveAttribute('href', '/app/integrasi/koneksi/c-1')
  })

  it('has no dismiss without the retry permission', async () => {
    boot(manager, { [`GET ${IT}/events/ev-1`]: { data: detail() } })
    renderApp('/app/integrasi/kejadian/ev-1')
    await screen.findByRole('heading', { name: 'Faktur bengkel dicatat' })
    expect(screen.queryByRole('button', { name: 'Tolak kejadian' })).not.toBeInTheDocument()
  })
})

describe('English', () => {
  it('shows the connection detail in English, with no Indonesian left in the sections it shows', async () => {
    setLocale('en')
    await openConnection(all, {
      [ONE]: { data: connection({ status: 'ACTIVE', access: access({ state: 'REFUSED', code: 'FEED_PAIRING_MISMATCH' }), open_gap: null }) },
      [SUMMARY]: { data: summary({
        books: { IN_LEDGER: 2, AWAITING_APPROVAL: 0, DRAFT: 1, REVERSED: 0, VOID: 0, INFORMATIONAL: 0, NO_EFFECT: 0, UNCLASSIFIED: 0 },
        health: health(alert({ code: 'SOURCE_REFUSED', reason: 'FEED_PAIRING_MISMATCH' })), source: { high_water: 50, high_water_at: null, cursor: 41, lag: 9 },
      }) },
      [GAPS]: { data: page([gap()]) },
    })
    expect(screen.getByText(/The source refuses the pull \(not an outage\)/)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Result in the books' })).toBeInTheDocument()
    expect(screen.getByText('Draft, not in the ledger')).toBeInTheDocument()
    expect(await screen.findByRole('table', { name: 'Connection gaps' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Synchronize now' })).toBeInTheDocument()
    const main = document.querySelector('main') as HTMLElement
    for (const indonesian of [/Sumber/, /Kejadian/, /Hasil pembukuan/, /Koneksi/, /Tarikan/, /Belum/, /Jeda/]) expect(main.textContent).not.toMatch(indonesian)
  })

  it('shows the event detail in English, including the processing code and the conflict', async () => {
    setLocale('en')
    boot(all, {
      [`GET ${IT}/events/ev-1`]: { data: detail({ conflict: true, status: 'NEEDS_REVIEW', last_error_code: 'PAYLOAD_CONFLICT', last_error: 'x', conflicts: [{ id: 'cf', received_hash: 'abcdef0123456789', received_at: '2026-10-10T04:00:00Z', resolution: null, resolution_reason: null, resolved_at: null, envelope: { ...detail().envelope, payload: { total_amount: '1.00' } } }] }) },
    })
    renderApp('/app/integrasi/kejadian/ev-1')
    await screen.findByRole('heading', { name: 'Workshop invoice recorded' })
    expect(screen.getByText(/Nothing is booked until someone decides in the "Content conflict" section/)).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Payload differences' })).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Decide the content conflict' }).length).toBeGreaterThan(0)
    const main = document.querySelector('main') as HTMLElement
    for (const indonesian of [/Kejadian/, /Konflik/, /Muatan/, /Dokumen/, /Diterima/, /Percobaan/]) expect(main.textContent).not.toMatch(indonesian)
  })

  it('shows an accounting code that only the Indonesian tables know with the server message in English', async () => {
    setLocale('en')
    boot(all, { [`GET ${IT}/events/ev-1`]: { data: detail({ status: 'FAILED', last_error_code: 'ACCOUNT_NOT_POSTABLE', last_error: 'A header account cannot be posted to.' }) } })
    renderApp('/app/integrasi/kejadian/ev-1')
    await screen.findByRole('heading', { name: 'Workshop invoice recorded' })
    expect(screen.getByText('ACCOUNT_NOT_POSTABLE', { selector: 'strong' }).parentElement).toHaveTextContent('A header account cannot be posted to.')
  })

  it('words a refusal in English', async () => {
    setLocale('en')
    boot(all, routes({ [ONE]: { data: connection({ status: 'READY' }) }, [`POST ${IT}/connections/c-1/activate`]: { status: 409, data: { message: 'x', code: 'CONNECTION_FEED_REFUSED', details: { feed_code: 'FEED_UNAUTHENTICATED' } }, headers: { 'x-request-id': 'req-77' } } }))
    renderApp('/app/integrasi/koneksi/c-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Activate' }))
    await userEvent.click(dialog().getByRole('button', { name: 'Activate' }))
    expect(await dialog().findByText(/The source refused access to the event feed, so the connection cannot be switched on: The credential is unknown, revoked or expired/)).toBeInTheDocument()
  })

  it('formats dates in the chosen language', async () => {
    setLocale('en')
    boot(manager, { [`GET ${IT}/connections`]: { data: page([connection({ cutover_date: '2026-10-01' })]) } })
    renderApp('/app/integrasi')
    const table = within(await screen.findByRole('table', { name: 'Integration connections' }))
    expect(table.getByText(/01 Oct 2026/)).toBeInTheDocument()
  })
})
