import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { AP, boot, capitalized, chart, disposal, postedDisposal } from './testkit'

afterEach(() => vi.restoreAllMocks())

const view = ['accounting.asset.view']
const dispose = [...view, 'accounting.asset.dispose']

describe('asset disposals list', () => {
  const lists = (rows: unknown[]) => ({ 'GET /app/accounting/asset-disposals': { data: page(rows) } })

  it('shows the gain or loss the server stored for posted disposals only', async () => {
    boot(view, lists([postedDisposal(), disposal({ id: 'd-2' }), postedDisposal({ id: 'd-3', document_number: 'AD-FY2026-000002', disposal_type: 'SCRAP', proceeds_amount: '0.0000', gain_amount: '0.0000', loss_amount: '75000000.0000' })]))
    renderApp('/app/akuntansi/pelepasan-aset')

    expect(await screen.findByRole('link', { name: 'AD-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/pelepasan-aset/d-1')
    expect(screen.getByRole('link', { name: 'Draf' })).toHaveAttribute('href', '/app/akuntansi/pelepasan-aset/d-2')
    expect(screen.getByText(/Laba/, { selector: 'span:not(.muted)' })).toHaveTextContent('Laba 15.000.000,00')
    expect(screen.getByText(/Rugi/, { selector: 'span:not(.muted)' })).toHaveTextContent('Rugi 75.000.000,00')
    const table = within(screen.getByRole('table'))
    expect(table.getAllByText('Penjualan').length).toBeGreaterThan(0)
    expect(table.getByText('Penghapusan')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Pelepasan baru' })).not.toBeInTheDocument()
  })

  it('offers the new-disposal button to a user who may dispose, but not in a read-only subscription', async () => {
    boot(dispose, lists([disposal()]))
    const first = renderApp('/app/akuntansi/pelepasan-aset')
    expect(await screen.findByRole('button', { name: 'Pelepasan baru' })).toBeInTheDocument()
    first.unmount()

    boot(dispose, lists([disposal()]), { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/pelepasan-aset')
    await screen.findByRole('link', { name: 'Draf' })
    expect(screen.queryByRole('button', { name: 'Pelepasan baru' })).not.toBeInTheDocument()
  })

  it('sends every filter to the server', async () => {
    const calls = boot(view, lists([disposal()]))
    renderApp('/app/akuntansi/pelepasan-aset')
    await screen.findByRole('link', { name: 'Draf' })

    await userEvent.selectOptions(screen.getByLabelText('Status pelepasan'), 'POSTED')
    await userEvent.selectOptions(screen.getByLabelText('Jenis pelepasan'), 'SALE')
    fireEvent.change(screen.getByLabelText('Tanggal posting dari'), { target: { value: '2026-10-01' } })
    await userEvent.click(screen.getByRole('checkbox', { name: 'Buatan saya' }))
    await userEvent.type(screen.getByLabelText('Cari pelepasan'), 'dealer')
    await waitFor(() => expect(calls.filter((c) => c.url === `${AP}/asset-disposals`).at(-1)?.params).toEqual({ page: 1, status: 'POSTED', disposal_type: 'SALE', posting_from: '2026-10-01', mine: 1, q: 'dealer' }))
  })
})

describe('asset disposal editor', () => {
  const assetsByStatus = (active: unknown[], full: unknown[] = []) => ({
    'GET /app/accounting/assets': (req: { params?: Record<string, unknown> }) => ({ data: page(req.params?.status === 'ACTIVE' ? active : req.params?.status === 'FULLY_DEPRECIATED' ? full : []) }),
  })
  const masters = (extra: Record<string, unknown> = {}) => ({
    ...assetsByStatus([capitalized()], [capitalized({ id: 'as-2', asset_number: 'FA-FY2026-000002', name: 'Genset', status: 'FULLY_DEPRECIATED' })]),
    'GET /app/accounting/accounts': { data: { data: chart } },
    ...extra,
  })
  const afterSave = { 'GET /app/accounting/asset-disposals/d-1': { data: disposal() } }

  it('offers only assets that are still on the books and shows their register position', async () => {
    const calls = boot(dispose, masters())
    renderApp('/app/akuntansi/pelepasan-aset/baru')
    await screen.findByRole('heading', { name: 'Pelepasan aset baru' })
    await screen.findByRole('option', { name: 'FA-FY2026-000001 · Truk Hino' })
    expect(screen.getByRole('option', { name: 'FA-FY2026-000002 · Genset' })).toBeInTheDocument()
    // Two requests, one per disposable status: a draft, discarded or disposed asset is never asked for.
    expect(calls.filter((c) => c.url === `${AP}/assets`).map((c) => c.params?.status).sort()).toEqual(['ACTIVE', 'FULLY_DEPRECIATED'])

    await userEvent.selectOptions(screen.getByLabelText('Aset'), 'as-1')
    const position = within(screen.getByLabelText('Posisi register aset'))
    expect(position.getByText('Harga perolehan').nextSibling).toHaveTextContent('120.000.000,00')
    expect(position.getByText('Akumulasi penyusutan saat ini').nextSibling).toHaveTextContent('0,00')
    expect(screen.getByText(/Nilai buku, laba, dan rugi dihitung server setelah draf disimpan/)).toBeInTheDocument()
  })

  it('searches assets on the server', async () => {
    const calls = boot(dispose, masters())
    renderApp('/app/akuntansi/pelepasan-aset/baru')
    await screen.findByRole('option', { name: 'FA-FY2026-000001 · Truk Hino' })
    await userEvent.type(screen.getByLabelText('Cari aset'), 'truk')
    await waitFor(() => expect(calls.filter((c) => c.url === `${AP}/assets`).at(-1)?.params).toMatchObject({ q: 'truk', per_page: 100 }))
  })

  it('sends a sale with its proceeds and receiving account, preselecting the asset it was started from', async () => {
    const calls = boot(dispose, masters({ 'GET /app/accounting/assets/as-1': { data: capitalized() }, 'POST /app/accounting/asset-disposals': { status: 201, data: disposal() }, ...afterSave }))
    renderApp('/app/akuntansi/pelepasan-aset/baru?aset=as-1')
    await screen.findByRole('heading', { name: 'Pelepasan aset baru' })
    await waitFor(() => expect(screen.getByLabelText('Aset')).toHaveValue('as-1'))
    await userEvent.type(screen.getByLabelText('Hasil penjualan'), '90000000')
    await screen.findByRole('option', { name: '1120 · Bank BCA' })
    // The receiving account is an asset account (cash or bank); liabilities and revenue are not offered.
    expect(within(screen.getByLabelText('Akun penerimaan')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Pilih akun…', '1120 · Bank BCA', '1500 · Kendaraan', '1590 · Akumulasi Penyusutan Kendaraan'])
    await userEvent.selectOptions(screen.getByLabelText('Akun penerimaan'), 'a-1120')
    await userEvent.type(screen.getByLabelText('Alasan'), 'Dijual ke dealer')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/asset-disposals`)).toBeDefined())
    const sent = calls.find((c) => c.method === 'POST' && c.url === `${AP}/asset-disposals`)!
    expect(sent.data).toEqual({
      fixed_asset_id: 'as-1', disposal_type: 'SALE', disposal_date: '2026-10-08', document_date: '2026-10-08', posting_date: '2026-10-08', reason: 'Dijual ke dealer', reference: null,
      proceeds_amount: '90000000.0000', proceeds_account_id: 'a-1120',
    })
    expect(Object.keys(sent.data as object).some((k) => ['gain', 'loss', 'gain_amount', 'loss_amount', 'book_value'].includes(k))).toBe(false) // React sends no calculated figure
    expect(await screen.findByText('Draf pelepasan disimpan.')).toBeInTheDocument()
    expect(await screen.findByRole('heading', { name: 'Draf pelepasan FA-FY2026-000001' })).toBeInTheDocument()
  })

  it('sends a scrap without proceeds or receiving account, and the posting date follows the disposal date', async () => {
    const calls = boot(dispose, masters({ 'POST /app/accounting/asset-disposals': { status: 201, data: disposal({ disposal_type: 'SCRAP' }) }, ...afterSave }))
    renderApp('/app/akuntansi/pelepasan-aset/baru')
    await screen.findByRole('option', { name: 'FA-FY2026-000001 · Truk Hino' })
    await userEvent.selectOptions(screen.getByLabelText('Aset'), 'as-1')
    await userEvent.click(screen.getByRole('radio', { name: 'Penghapusan' }))
    expect(screen.queryByLabelText('Hasil penjualan')).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Tanggal pelepasan'), { target: { value: '2026-10-20' } })
    await userEvent.type(screen.getByLabelText('Alasan'), 'Rusak berat')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({
      fixed_asset_id: 'as-1', disposal_type: 'SCRAP', disposal_date: '2026-10-20', document_date: '2026-10-20', posting_date: '2026-10-20', reason: 'Rusak berat', reference: null,
      proceeds_amount: '0.0000', proceeds_account_id: null,
    })
  })

  it('refuses a sale without proceeds or account, and an asset or reason left empty, before calling the API', async () => {
    const calls = boot(dispose, masters())
    renderApp('/app/akuntansi/pelepasan-aset/baru')
    await screen.findByRole('option', { name: 'FA-FY2026-000001 · Truk Hino' })
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Pilih aset yang akan dilepas.')).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Aset'), 'as-1')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Alasan pelepasan wajib diisi.')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Alasan'), 'Dijual')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(/Penjualan membutuhkan hasil penjualan lebih besar dari nol/)).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Hasil penjualan'), '1.000.000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(/Hasil penjualan harus berupa angka tanpa pemisah ribuan/)).toBeInTheDocument()

    await userEvent.clear(screen.getByLabelText('Hasil penjualan'))
    await userEvent.type(screen.getByLabelText('Hasil penjualan'), '1000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Pilih akun penerimaan hasil penjualan.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('shows the API refusal in Indonesian and on the field it names', async () => {
    boot(dispose, masters({ 'POST /app/accounting/asset-disposals': { status: 409, data: { message: 'exists', code: 'ASSET_DISPOSAL_EXISTS', details: {} } } }))
    renderApp('/app/akuntansi/pelepasan-aset/baru')
    await screen.findByRole('option', { name: 'FA-FY2026-000001 · Truk Hino' })
    await userEvent.selectOptions(screen.getByLabelText('Aset'), 'as-1')
    await userEvent.click(screen.getByRole('radio', { name: 'Penghapusan' }))
    await userEvent.type(screen.getByLabelText('Alasan'), 'Rusak')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Aset ini sudah memiliki pelepasan yang masih berjalan atau sudah diposting.')).toBeInTheDocument()
  })

  it('warns when the asset it was started from can no longer be disposed', async () => {
    boot(dispose, masters({ 'GET /app/accounting/assets/as-3': { data: capitalized({ id: 'as-3', status: 'DISPOSED' }) } }))
    renderApp('/app/akuntansi/pelepasan-aset/baru?aset=as-3')
    expect(await screen.findByText(/berstatus DISPOSED dan tidak dapat dilepas/)).toBeInTheDocument()
  })

  it('edits a draft with its saved values, locks its asset and shows the server calculation', async () => {
    const calls = boot(dispose, masters({ 'GET /app/accounting/asset-disposals/d-1': { data: disposal() }, 'PATCH /app/accounting/asset-disposals/d-1': { data: disposal() } }))
    renderApp('/app/akuntansi/pelepasan-aset/d-1/ubah')
    await screen.findByRole('heading', { name: 'Ubah draf pelepasan FA-FY2026-000001' })
    expect(screen.getByLabelText('Aset')).toBeDisabled()
    expect(screen.getByLabelText('Aset')).toHaveValue('as-1')
    expect(screen.getByLabelText('Hasil penjualan')).toHaveValue('90000000')
    expect(screen.getByLabelText('Alasan')).toHaveValue('Dijual ke dealer')
    expect(screen.getByText('75.000.000,00')).toBeInTheDocument() // book value from the server
    expect(screen.getByText('15.000.000,00')).toBeInTheDocument() // gain from the server
    await waitFor(() => expect(screen.getByLabelText('Akun penerimaan')).toHaveValue('a-1120'))
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true))
    expect(calls.find((c) => c.method === 'PATCH')!.data).toMatchObject({ fixed_asset_id: 'as-1', disposal_type: 'SALE', proceeds_amount: '90000000.0000', proceeds_account_id: 'a-1120' })
  })

  it('refuses to edit a disposal that is no longer a draft', async () => {
    boot(dispose, masters({ 'GET /app/accounting/asset-disposals/d-1': { data: postedDisposal() } }))
    renderApp('/app/akuntansi/pelepasan-aset/d-1/ubah')
    expect(await screen.findByText('Pelepasan tidak dapat diubah')).toBeInTheDocument()
  })
})

describe('asset disposal detail', () => {
  const approver = [...dispose, 'accounting.asset.disposal.approve', 'accounting.asset.disposal.post']
  const routes = (d: unknown) => ({ 'GET /app/accounting/asset-disposals/d-1': { data: d } })
  const buttons = () => screen.queryAllByRole('button').map((b) => b.textContent)

  it('offers edit, submit and cancel on a draft that needs approval, and shows the server calculation', async () => {
    boot(approver, routes(disposal()))
    renderApp('/app/akuntansi/pelepasan-aset/d-1')

    expect(await screen.findByRole('heading', { name: 'Draf pelepasan FA-FY2026-000001' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ubah' })).toHaveAttribute('href', '/app/akuntansi/pelepasan-aset/d-1/ubah')
    expect(screen.getByRole('button', { name: 'Ajukan' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Batalkan' })).toBeInTheDocument()
    for (const label of ['Posting', 'Setujui', 'Tolak', 'Balik']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
    const calc = within(screen.getByRole('heading', { name: 'Perhitungan server' }).closest('section')!)
    expect(calc.getByText('75.000.000,00')).toBeInTheDocument()
    expect(calc.getByText('15.000.000,00')).toBeInTheDocument()
    expect(calc.getByText(/Perkiraan server/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'FA-FY2026-000001 · Truk Hino' })).toHaveAttribute('href', '/app/akuntansi/aset/as-1')
  })

  it('submits and explains where to post the pending depreciation when the API refuses', async () => {
    const calls = boot(approver, {
      ...routes(disposal()),
      'POST /app/accounting/asset-disposals/d-1/submit': { status: 409, data: { message: 'pending', code: 'ASSET_DEPRECIATION_PENDING', details: { first_pending_period: '2026-10-01', pending_months: 3 } } },
    })
    renderApp('/app/akuntansi/pelepasan-aset/d-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Ajukan' }))

    await waitFor(() => expect(calls.some((c) => c.url.endsWith('/submit'))).toBe(true))
    expect(await screen.findByText('Penyusutan aset ini belum diposting sampai tanggal pelepasan. Jalankan dan posting penyusutan lebih dulu.')).toBeInTheDocument()
    expect(screen.getByText(/Penyusutan yang belum diposting dimulai .* \(3 bulan\)/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Buka halaman penyusutan' })).toHaveAttribute('href', '/app/akuntansi/penyusutan')
  })

  it('approves and rejects a submitted disposal, and disables both when segregation of duties forbids it', async () => {
    const calls = boot(approver, { ...routes(disposal({ status: 'SUBMITTED' })), 'POST /app/accounting/asset-disposals/d-1/reject': { data: disposal({ status: 'REJECTED' }) } })
    renderApp('/app/akuntansi/pelepasan-aset/d-1')
    expect(await screen.findByRole('button', { name: 'Setujui' })).toBeEnabled()
    await userEvent.click(screen.getByRole('button', { name: 'Tolak' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText(/Alasan/), 'Nilai jual terlalu rendah')
    await userEvent.click(dialog.getByRole('button', { name: 'Tolak' }))
    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/reject'))?.data).toEqual({ reason: 'Nilai jual terlalu rendah' }))
  })

  it('shows approval disabled with the reason for the preparer', async () => {
    boot(approver, routes(disposal({ status: 'SUBMITTED', sod: { approve: false, post: false, approval_required: true } })))
    renderApp('/app/akuntansi/pelepasan-aset/d-1')
    expect(await screen.findByRole('button', { name: 'Setujui' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Tolak' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat menyetujui/)).toBeInTheDocument()
  })

  it('posts an approved disposal through a confirmation', async () => {
    const calls = boot(approver, { ...routes(disposal({ status: 'APPROVED' })), 'POST /app/accounting/asset-disposals/d-1/post': { data: postedDisposal() } })
    renderApp('/app/akuntansi/pelepasan-aset/d-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Posting' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Posting' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${AP}/asset-disposals/d-1/post`)).toBe(true))
    expect(await screen.findByText('Pelepasan diposting.')).toBeInTheDocument()
  })

  it('shows the frozen snapshot, the journal link and the reverse action of a posted disposal', async () => {
    const calls = boot(approver, {
      ...routes(postedDisposal()),
      'POST /app/accounting/asset-disposals/d-1/reverse': { data: postedDisposal({ status: 'REVERSED' }) },
    })
    renderApp('/app/akuntansi/pelepasan-aset/d-1')
    expect(await screen.findByRole('heading', { name: 'AD-FY2026-000001' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Lihat jurnal' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-30')
    expect(screen.getByText(/snapshot yang dibekukan/)).toBeInTheDocument()
    for (const label of ['Ubah', 'Posting', 'Batalkan', 'Ajukan']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Balik' }))
    const dialog = within(screen.getByRole('dialog', { name: 'Balik pelepasan' }))
    await userEvent.type(dialog.getByLabelText(/Alasan/), 'Pembeli batal')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))
    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/reverse'))?.data).toEqual({ reason: 'Pembeli batal', posting_date: '2026-10-08' }))
  })

  it('reopens a rejected disposal and shows the rejection reason', async () => {
    const calls = boot(approver, { ...routes(disposal({ status: 'REJECTED', reject_reason: 'Nilai jual terlalu rendah' })), 'POST /app/accounting/asset-disposals/d-1/reopen': { data: disposal() } })
    renderApp('/app/akuntansi/pelepasan-aset/d-1')
    expect(await screen.findByText('Ditolak: Nilai jual terlalu rendah')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Jadikan draf' }))
    await waitFor(() => expect(calls.some((c) => c.url.endsWith('/reopen'))).toBe(true))
  })

  it('offers no action to a viewer, or a user without the approve and post permissions, or in a read-only subscription', async () => {
    boot(view, routes(disposal({ status: 'APPROVED' })))
    const first = renderApp('/app/akuntansi/pelepasan-aset/d-1')
    await screen.findByRole('heading', { name: 'Draf pelepasan FA-FY2026-000001' })
    expect(buttons().filter((b) => ['Posting', 'Batalkan', 'Ajukan', 'Setujui', 'Balik'].includes(b ?? ''))).toEqual([])
    first.unmount()

    boot(dispose, routes(disposal({ status: 'SUBMITTED' })))
    const second = renderApp('/app/akuntansi/pelepasan-aset/d-1')
    await screen.findByRole('heading', { name: 'Draf pelepasan FA-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Setujui' })).not.toBeInTheDocument()
    second.unmount()

    boot(approver, routes(disposal()), { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/pelepasan-aset/d-1')
    await screen.findByRole('heading', { name: 'Draf pelepasan FA-FY2026-000001' })
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(buttons().filter((b) => ['Posting', 'Batalkan', 'Ajukan', 'Setujui', 'Balik'].includes(b ?? ''))).toEqual([])
    expect(screen.queryByRole('link', { name: 'Ubah' })).not.toBeInTheDocument()
  })
})
