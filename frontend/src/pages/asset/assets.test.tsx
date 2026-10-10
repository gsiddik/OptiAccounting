import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { AP, asset, boot, capitalized, category, chart, scheduleRows } from './testkit'

afterEach(() => vi.restoreAllMocks())

const view = ['accounting.asset.view']

describe('asset register', () => {
  const lists = (rows: unknown[]) => ({
    'GET /app/accounting/assets': (req: { params?: Record<string, unknown> }) => ({ data: { data: rows, current_page: Number(req.params?.page ?? 1), last_page: 2, total: 40, per_page: 25 } }),
    'GET /app/accounting/asset-categories': { data: page([category()]) },
  })

  // The list endpoint does not carry the detail-only fields (net book value, schedule summary, sod).
  const row = (a: Record<string, unknown>) => {
    const { net_book_value: _nbv, schedule_summary: _summary, sod: _sod, ...rest } = a
    return rest
  }

  it('shows the amounts the server sent and links to the detail page', async () => {
    boot(view, lists([row(capitalized({ accumulated_depreciation: '9000000.0000' })), row(asset({ id: 'as-2', name: 'Laptop' }))]))
    renderApp('/app/akuntansi/aset')

    expect(await screen.findByRole('link', { name: 'FA-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/aset/as-1')
    expect(screen.getByRole('link', { name: 'Draf' })).toHaveAttribute('href', '/app/akuntansi/aset/as-2')
    expect(screen.getAllByText('120.000.000,00').length).toBeGreaterThan(0)
    expect(screen.getByText('9.000.000,00')).toBeInTheDocument()
    expect(screen.getAllByText('Aktif').length).toBeGreaterThan(0)
    expect(screen.queryByRole('columnheader', { name: 'Nilai buku' })).not.toBeInTheDocument() // the list endpoint does not send it, and React never computes it
    expect(screen.queryByRole('button', { name: 'Aset baru' })).not.toBeInTheDocument()
  })

  it('shows the book value column when the API carries it', async () => {
    boot(view, lists([capitalized({ net_book_value: '111000000.0000' })]))
    renderApp('/app/akuntansi/aset')
    expect(await screen.findByRole('columnheader', { name: 'Nilai buku' })).toBeInTheDocument()
    expect(screen.getByText('111.000.000,00')).toBeInTheDocument()
  })

  it('sends every filter to the server and returns to page 1 when one changes', async () => {
    const calls = boot(view, lists([capitalized()]))
    renderApp('/app/akuntansi/aset')
    await screen.findByRole('link', { name: 'FA-FY2026-000001' })
    const assetCalls = () => calls.filter((c) => c.method === 'GET' && c.url === `${AP}/assets`)

    await userEvent.click(screen.getByRole('button', { name: 'Berikutnya' }))
    await waitFor(() => expect(assetCalls().at(-1)?.params?.page).toBe(2))

    await userEvent.selectOptions(screen.getByLabelText('Status aset'), 'FULLY_DEPRECIATED')
    await waitFor(() => expect(assetCalls().at(-1)?.params).toEqual({ page: 1, status: 'FULLY_DEPRECIATED' }))
    await screen.findByRole('option', { name: 'KEND · Kendaraan' })
    await userEvent.selectOptions(screen.getByLabelText('Kategori aset'), 'c-1')
    fireEvent.change(screen.getByLabelText('Tanggal kapitalisasi dari'), { target: { value: '2026-01-01' } })
    fireEvent.change(screen.getByLabelText('Tanggal kapitalisasi sampai'), { target: { value: '2026-12-31' } })
    await userEvent.click(screen.getByRole('checkbox', { name: 'Buatan saya' }))
    await userEvent.type(screen.getByLabelText('Cari aset'), 'truk')

    await waitFor(() => expect(assetCalls().at(-1)?.params).toEqual({ page: 1, status: 'FULLY_DEPRECIATED', asset_category_id: 'c-1', capitalized_from: '2026-01-01', capitalized_to: '2026-12-31', mine: 1, q: 'truk' }))
  })

  it('offers the branch filter when the user has branches', async () => {
    const calls = boot(view, { ...lists([capitalized()]), 'GET /app/accounting/dimensions': { data: { branches: [{ id: 'b-1', code: 'JKT', name: 'Jakarta' }], business_units: [], cost_centers: [] } } })
    renderApp('/app/akuntansi/aset')
    await screen.findByRole('link', { name: 'FA-FY2026-000001' })
    await userEvent.selectOptions(await screen.findByLabelText('Cabang'), 'b-1')
    await waitFor(() => expect(calls.filter((c) => c.url === `${AP}/assets`).at(-1)?.params).toEqual({ page: 1, branch_id: 'b-1' }))
  })

  it('offers the new-asset button only to a user who may manage assets, and not in a read-only subscription', async () => {
    boot([...view, 'accounting.asset.manage'], lists([capitalized()]))
    renderApp('/app/akuntansi/aset')
    expect(await screen.findByRole('button', { name: 'Aset baru' })).toBeInTheDocument()
  })

  it('hides the new-asset button in a read-only subscription', async () => {
    boot([...view, 'accounting.asset.manage'], lists([capitalized()]), { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/aset')
    await screen.findByRole('link', { name: 'FA-FY2026-000001' })
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Aset baru' })).not.toBeInTheDocument()
  })

  it('shows an empty state, and an error with a retry', async () => {
    let fail = true
    boot(view, { 'GET /app/accounting/assets': () => (fail ? { status: 500, data: { message: 'x' } } : { data: page([]) }), 'GET /app/accounting/asset-categories': { data: page([]) } })
    renderApp('/app/akuntansi/aset')
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Belum ada aset')).toBeInTheDocument()
  })
})

describe('asset editor', () => {
  const permissions = [...view, 'accounting.asset.manage']
  const masters = {
    'GET /app/accounting/asset-categories': { data: page([category(), category({ id: 'c-9', code: 'LAMA', name: 'Kategori lama', status: 'INACTIVE' })]) },
    'GET /app/accounting/accounts': { data: { data: chart } },
  }
  const afterSave = { 'GET /app/accounting/assets/as-1': { data: asset() }, 'GET /app/accounting/assets/as-1/schedule': { data: { preview: true, rows: scheduleRows } } }

  async function fillCommon() {
    await screen.findByRole('option', { name: 'KEND · Kendaraan' })
    await userEvent.selectOptions(screen.getByLabelText('Kategori'), 'c-1')
    await userEvent.type(screen.getByLabelText('Nama aset'), 'Truk Hino')
  }

  it('offers only active categories and shows the defaults of the chosen one as hints', async () => {
    boot(permissions, masters)
    renderApp('/app/akuntansi/aset/baru')
    await screen.findByRole('heading', { name: 'Aset baru' })
    await screen.findByRole('option', { name: 'KEND · Kendaraan' })
    expect(screen.queryByRole('option', { name: 'LAMA · Kategori lama' })).not.toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Kategori'), 'c-1')

    expect(screen.getByText('Default kategori: Garis lurus, 48 bulan.')).toBeInTheDocument()
    expect(screen.getByText('Kosong berarti mengikuti kategori (Persentase dari biaya: 10,00%).')).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Ikuti kategori (Garis lurus)' })).toBeInTheDocument()
    expect(screen.getByText('Kosong berarti 48 bulan (default kategori).')).toBeInTheDocument()
  })

  it('sends a posting-mode draft with decimal strings and leaves category defaults unsent', async () => {
    const calls = boot(permissions, { ...masters, ...afterSave, 'POST /app/accounting/assets': { status: 201, data: asset() } })
    renderApp('/app/akuntansi/aset/baru')
    await screen.findByRole('heading', { name: 'Aset baru' })
    await fillCommon()
    await userEvent.type(screen.getByLabelText('Harga perolehan'), '120000000,5')
    await screen.findByRole('option', { name: '1120 · Bank BCA' })
    // Only active, postable, non-control asset, liability and equity accounts can be the credit side.
    expect(within(screen.getByLabelText('Akun sumber')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Belum dipilih', '1120 · Bank BCA', '1500 · Kendaraan', '1590 · Akumulasi Penyusutan Kendaraan', '2190 · Utang Pembelian Aset'])
    await userEvent.selectOptions(screen.getByLabelText('Akun sumber'), 'a-1120')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/assets`)).toBeDefined())
    const sent = calls.find((c) => c.method === 'POST' && c.url === `${AP}/assets`)!
    expect(sent.data).toEqual({
      asset_category_id: 'c-1', name: 'Truk Hino', description: null, acquisition_date: '2026-10-08', acquisition_cost: '120000000.5000', capitalization_mode: 'POST',
      source_reference: null, branch_id: null, business_unit_id: null, cost_center_id: null, source_account_id: 'a-1120',
    })
    expect(JSON.stringify(sent.data)).not.toMatch(/"acquisition_cost":\d/) // never a JSON number
    expect(await screen.findByRole('heading', { name: 'Draf: Truk Hino' })).toBeInTheDocument()
    expect(await screen.findByText('Draf aset disimpan.')).toBeInTheDocument()
  })

  it('sends explicit depreciation terms, including the declining balance factor', async () => {
    const calls = boot(permissions, { ...masters, ...afterSave, 'POST /app/accounting/assets': { status: 201, data: asset() } })
    renderApp('/app/akuntansi/aset/baru')
    await screen.findByRole('heading', { name: 'Aset baru' })
    await fillCommon()
    expect(screen.queryByLabelText('Faktor saldo menurun')).not.toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Harga perolehan'), '50000000')
    await userEvent.type(screen.getByLabelText('Nilai sisa'), '0')
    await userEvent.type(screen.getByLabelText('Umur manfaat (bulan)'), '60')
    await userEvent.selectOptions(screen.getByLabelText('Metode penyusutan'), 'DECLINING_BALANCE')
    await userEvent.type(await screen.findByLabelText('Faktor saldo menurun'), '2,5')
    await userEvent.selectOptions(screen.getByLabelText('Mulai disusutkan'), 'NEXT_MONTH')
    fireEvent.change(screen.getByLabelText('Tanggal kapitalisasi'), { target: { value: '2026-10-15' } })
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/assets`)).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toMatchObject({
      acquisition_cost: '50000000.0000', residual_value: '0.0000', useful_life_months: 60, method: 'DECLINING_BALANCE', method_params: { factor: '2.5' }, start_policy: 'NEXT_MONTH', capitalization_date: '2026-10-15',
    })
  })

  it('registers cost already in the ledger from a posted vendor invoice line and sends no source account', async () => {
    const calls = boot([...permissions, 'accounting.ap_invoice.view'], {
      ...masters,
      ...afterSave,
      'GET /app/accounting/ap-invoices': { data: page([{ id: 'inv-1', document_number: 'AP-FY2026-000009', vendor_invoice_number: 'INV-77', total_amount: '120000000.0000', vendor: { id: 'v-1', code: 'DLR', name: 'PT Dealer' } }]) },
      'GET /app/accounting/ap-invoices/inv-1': {
        data: {
          id: 'inv-1',
          lines: [
            { id: 'ln-1', line_number: 1, description: 'Truk Hino', amount: '120000000.0000', account_id: 'a-1500', account: { id: 'a-1500', code: '1500', name: 'Kendaraan' } },
            { id: 'ln-2', line_number: 2, description: 'Biaya kirim', amount: '500000.0000', account_id: null, account: null },
          ],
        },
      },
      'POST /app/accounting/assets': { status: 201, data: asset({ capitalization_mode: 'REGISTER_ONLY' }) },
    })
    renderApp('/app/akuntansi/aset/baru')
    await screen.findByRole('heading', { name: 'Aset baru' })
    await fillCommon()
    await userEvent.type(screen.getByLabelText('Harga perolehan'), '120000000')
    await userEvent.click(screen.getByRole('radio', { name: 'Catat saja (sudah dijurnal di faktur vendor)' }))
    expect(screen.queryByLabelText('Akun sumber')).not.toBeInTheDocument()
    await userEvent.selectOptions(await screen.findByLabelText('Faktur vendor'), await screen.findByRole('option', { name: /AP-FY2026-000009/ }))
    const lines = within(screen.getByLabelText('Baris faktur'))
    await waitFor(() => expect(lines.getAllByRole('option').map((o) => o.textContent)).toEqual(['Pilih baris…', '#1 · Truk Hino · 120.000.000,00 · 1500'])) // the line without an account is not offered
    await userEvent.selectOptions(screen.getByLabelText('Baris faktur'), 'ln-1')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/assets`)).toBeDefined())
    const body = calls.find((c) => c.method === 'POST' && c.url === `${AP}/assets`)!.data as Record<string, unknown>
    expect(body).toMatchObject({ capitalization_mode: 'REGISTER_ONLY', ap_invoice_line_id: 'ln-1' })
    expect(body).not.toHaveProperty('source_account_id')
  })

  it('asks for the line id when the user may not open vendor invoices, and refuses to save without one', async () => {
    const calls = boot(permissions, masters)
    renderApp('/app/akuntansi/aset/baru')
    await screen.findByRole('heading', { name: 'Aset baru' })
    await fillCommon()
    await userEvent.type(screen.getByLabelText('Harga perolehan'), '1000')
    await userEvent.click(screen.getByRole('radio', { name: 'Catat saja (sudah dijurnal di faktur vendor)' }))
    expect(screen.getByLabelText('ID baris faktur vendor')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    expect(await screen.findByText('Pilih baris faktur vendor yang biayanya sudah dijurnal.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('refuses an amount that is not a plain decimal, and a zero cost, before calling the API', async () => {
    const calls = boot(permissions, masters)
    renderApp('/app/akuntansi/aset/baru')
    await screen.findByRole('heading', { name: 'Aset baru' })
    await fillCommon()
    await userEvent.type(screen.getByLabelText('Harga perolehan'), '1.000.000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(/Harga perolehan harus berupa angka tanpa pemisah ribuan/)).toBeInTheDocument()

    await userEvent.clear(screen.getByLabelText('Harga perolehan'))
    await userEvent.type(screen.getByLabelText('Harga perolehan'), '0')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Harga perolehan harus lebih besar dari nol.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('shows the API validation message under its field and translates a business-rule refusal', async () => {
    boot(permissions, { ...masters, 'POST /app/accounting/assets': { status: 422, data: { message: 'The given data was invalid.', errors: { acquisition_cost: ['Harga perolehan tidak valid.'] } } } })
    renderApp('/app/akuntansi/aset/baru')
    await screen.findByRole('heading', { name: 'Aset baru' })
    await fillCommon()
    await userEvent.type(screen.getByLabelText('Harga perolehan'), '1000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect((await screen.findAllByText('Harga perolehan tidak valid.')).some((m) => m.classList.contains('error'))).toBe(true)
    expect(screen.getByLabelText('Harga perolehan')).toHaveAttribute('aria-invalid', 'true')
  })

  it('attaches a domain refusal to the field its details name', async () => {
    boot(permissions, { ...masters, 'POST /app/accounting/assets': { status: 422, data: { message: 'life', code: 'ASSET_LIFE_INVALID', details: { field: 'useful_life_months' } } } })
    renderApp('/app/akuntansi/aset/baru')
    await screen.findByRole('heading', { name: 'Aset baru' })
    await fillCommon()
    await userEvent.type(screen.getByLabelText('Harga perolehan'), '1000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    expect((await screen.findAllByText('Umur manfaat harus antara 1 dan 1200 bulan.')).length).toBe(2)
    expect(screen.getByLabelText('Umur manfaat (bulan)')).toHaveAttribute('aria-invalid', 'true')
  })

  it('edits a draft with its saved values and sends them explicitly', async () => {
    const calls = boot(permissions, { ...masters, 'GET /app/accounting/assets/as-1': { data: asset() }, 'PATCH /app/accounting/assets/as-1': { data: asset() }, 'GET /app/accounting/assets/as-1/schedule': { data: { preview: true, rows: [] } } })
    renderApp('/app/akuntansi/aset/as-1/ubah')
    await screen.findByRole('heading', { name: 'Ubah draf aset Truk Hino' })
    expect(screen.getByLabelText('Nama aset')).toHaveValue('Truk Hino')
    expect(screen.getByLabelText('Harga perolehan')).toHaveValue('120000000')
    expect(screen.getByLabelText('Nilai sisa')).toHaveValue('12000000')
    expect(screen.getByLabelText('Umur manfaat (bulan)')).toHaveValue('48')
    await waitFor(() => expect(screen.getByLabelText('Akun sumber')).toHaveValue('a-1120'))
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true))
    expect(calls.find((c) => c.method === 'PATCH')!.data).toMatchObject({
      acquisition_cost: '120000000.0000', residual_value: '12000000.0000', useful_life_months: 48, method: 'STRAIGHT_LINE', start_policy: 'CAPITALIZATION_MONTH', capitalization_date: '2026-10-01', source_account_id: 'a-1120',
    })
  })

  it('refuses to edit an asset that is no longer a draft', async () => {
    boot(permissions, { ...masters, 'GET /app/accounting/assets/as-1': { data: capitalized() } })
    renderApp('/app/akuntansi/aset/as-1/ubah')
    expect(await screen.findByText('Aset tidak dapat diubah')).toBeInTheDocument()
  })
})

describe('asset detail', () => {
  const all = [...view, 'accounting.asset.manage', 'accounting.asset.capitalize', 'accounting.asset.dispose']
  const routes = (a: unknown, schedule: unknown = { preview: false, rows: scheduleRows }) => ({ 'GET /app/accounting/assets/as-1': { data: a }, 'GET /app/accounting/assets/as-1/schedule': { data: schedule } })

  it('shows a draft with the server preview of its schedule and the actions of a draft', async () => {
    boot(all, routes(asset(), { preview: true, rows: scheduleRows }))
    renderApp('/app/akuntansi/aset/as-1')

    expect(await screen.findByRole('heading', { name: 'Draf: Truk Hino' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ubah' })).toHaveAttribute('href', '/app/akuntansi/aset/as-1/ubah')
    expect(screen.getByRole('button', { name: 'Kapitalisasi' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Buang draf' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Balik kapitalisasi' })).not.toBeInTheDocument()
    expect(await screen.findByText(/pratinjau yang dihitung server/)).toBeInTheDocument()
    expect(await screen.findByText('117.750.000,00')).toBeInTheDocument() // book value after the first month, from the API
    expect(screen.getAllByText('120.000.000,00').length).toBeGreaterThan(0) // cost and net book value
  })

  it('offers no change to a user who only views, or in a read-only subscription', async () => {
    boot(view, routes(asset()))
    const first = renderApp('/app/akuntansi/aset/as-1')
    await screen.findByRole('heading', { name: 'Draf: Truk Hino' })
    for (const label of ['Kapitalisasi', 'Buang draf']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Ubah' })).not.toBeInTheDocument()
    first.unmount()

    boot(all, routes(capitalized()), { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/aset/as-1')
    await screen.findByRole('heading', { name: 'FA-FY2026-000001' })
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Balik kapitalisasi' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Siapkan pelepasan' })).not.toBeInTheDocument()
  })

  it('disables capitalization with the reason when the server says segregation of duties forbids it', async () => {
    boot(all, routes(asset({ sod: { capitalize: false } })))
    renderApp('/app/akuntansi/aset/as-1')
    expect(await screen.findByRole('button', { name: 'Kapitalisasi' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat mengkapitalisasi/)).toBeInTheDocument()
  })

  it('capitalizes through a confirmation and reloads', async () => {
    const calls = boot(all, { ...routes(asset()), 'POST /app/accounting/assets/as-1/capitalize': { data: capitalized() } })
    renderApp('/app/akuntansi/aset/as-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Kapitalisasi' }))
    await userEvent.click(within(screen.getByRole('dialog', { name: 'Kapitalisasi aset' })).getByRole('button', { name: 'Kapitalisasi' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${AP}/assets/as-1/capitalize`)).toBe(true))
    expect(await screen.findByText('Aset dikapitalisasi.')).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'GET' && c.url === `${AP}/assets/as-1`).length).toBeGreaterThan(1)
  })

  it('shows an Indonesian message in the dialog when the API refuses to capitalize', async () => {
    boot(all, { ...routes(asset()), 'POST /app/accounting/assets/as-1/capitalize': { status: 422, data: { message: 'Name the account.', code: 'ASSET_SOURCE_ACCOUNT_REQUIRED', details: { field: 'source_account_id' } } } })
    renderApp('/app/akuntansi/aset/as-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Kapitalisasi' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.click(dialog.getByRole('button', { name: 'Kapitalisasi' }))
    expect(await dialog.findByText('Pilih akun sumber (utang, bank, atau penampung) untuk jurnal kapitalisasi.')).toBeInTheDocument()
  })

  it('warns that a posting-mode draft has no source account yet', async () => {
    boot(all, routes(asset({ source_account_id: null, source_account: null })))
    renderApp('/app/akuntansi/aset/as-1')
    expect(await screen.findByText(/Akun sumber belum dipilih/)).toBeInTheDocument()
  })

  it('discards a draft with a required reason', async () => {
    const calls = boot(all, { ...routes(asset()), 'POST /app/accounting/assets/as-1/discard': { data: asset({ status: 'INACTIVE' }) } })
    renderApp('/app/akuntansi/aset/as-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Buang draf' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.click(dialog.getByRole('button', { name: 'Buang draf' }))
    expect(calls.some((c) => c.url.endsWith('/discard'))).toBe(false)
    await userEvent.type(dialog.getByLabelText(/Alasan/), 'Pembelian dibatalkan')
    await userEvent.click(dialog.getByRole('button', { name: 'Buang draf' }))
    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/discard'))?.data).toEqual({ reason: 'Pembelian dibatalkan' }))
  })

  it('shows a capitalized asset with its server figures, frozen accounts and links, and offers reversal and disposal', async () => {
    boot(all, routes(capitalized()))
    renderApp('/app/akuntansi/aset/as-1')

    expect(await screen.findByRole('heading', { name: 'FA-FY2026-000001' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Lihat jurnal' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-10')
    expect(screen.getByText('108.000.000,00')).toBeInTheDocument() // depreciable basis
    expect(screen.getByText('1500 · Kendaraan')).toBeInTheDocument()
    expect(screen.getByText(/48 bulan: 0 diposting, 0 dalam proses draf, 48 terjadwal/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Siapkan pelepasan' })).toHaveAttribute('href', '/app/akuntansi/pelepasan-aset/baru?aset=as-1')
    expect(screen.getByRole('button', { name: 'Balik kapitalisasi' })).toBeInTheDocument()
    for (const label of ['Kapitalisasi', 'Buang draf']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
  })

  it('reverses a capitalization with a reason and posting date', async () => {
    const calls = boot(all, { ...routes(capitalized()), 'POST /app/accounting/assets/as-1/reverse-capitalization': { data: asset({ status: 'INACTIVE' }) } })
    renderApp('/app/akuntansi/aset/as-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Balik kapitalisasi' }))
    const dialog = within(screen.getByRole('dialog', { name: 'Balik kapitalisasi' }))
    await userEvent.type(dialog.getByLabelText(/Alasan/), 'Salah catat')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik kapitalisasi' }))

    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/reverse-capitalization'))?.data).toEqual({ reason: 'Salah catat', posting_date: '2026-10-08' }))
    expect(await screen.findByText('Kapitalisasi dibalik.')).toBeInTheDocument()
  })

  it('does not offer to reverse the capitalization once a month was posted, and lets the disposal link go on', async () => {
    boot(all, routes(capitalized({ accumulated_depreciation: '2250000.0000', schedule_summary: { rows: 48, planned: 47, in_run: 0, posted: 1, cancelled: 0, next_period: '2026-11-01' } })))
    renderApp('/app/akuntansi/aset/as-1')
    await screen.findByRole('heading', { name: 'FA-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Balik kapitalisasi' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Siapkan pelepasan' })).toBeInTheDocument()
  })

  it('links a disposed asset to its disposal and offers no disposal', async () => {
    boot(all, routes(capitalized({ status: 'DISPOSED', disposed_on: '2026-10-08', disposal_id: 'd-1' })))
    renderApp('/app/akuntansi/aset/as-1')
    expect(await screen.findByRole('link', { name: 'Lihat pelepasan' })).toHaveAttribute('href', '/app/akuntansi/pelepasan-aset/d-1')
    expect(screen.queryByRole('link', { name: 'Siapkan pelepasan' })).not.toBeInTheDocument()
  })

  it('shows the status history and a method with no schedule', async () => {
    boot(all, routes(asset({ method: 'NONE', useful_life_months: null }), { preview: true, rows: [] }))
    renderApp('/app/akuntansi/aset/as-1')
    const history = within((await screen.findByRole('heading', { name: 'Riwayat' })).closest('section')!)
    expect(history.getByText(/Budi Santoso/)).toBeInTheDocument()
    expect(await screen.findByText('Tidak ada jadwal penyusutan')).toBeInTheDocument()
  })
})
