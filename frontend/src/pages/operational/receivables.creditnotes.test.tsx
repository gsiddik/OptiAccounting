import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockApi, page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { account, arInvoice, arInvoiceRow, BASE, bootAr, creditNote, creditNoteRow, customer, holdGet, mockDownload, noDimensions } from './receivables/testkit'

afterEach(() => vi.restoreAllMocks())

const customers = page([customer(), customer({ id: 'c-2', code: 'C2', name: 'CV Lain' })])

describe('credit note list', () => {
  const view = ['accounting.ar_credit_note.view']
  const routes = {
    [`GET ${BASE}/ar-credit-notes`]: { data: page([creditNoteRow(), creditNoteRow({ id: 'cn-2', document_number: null, status: 'DRAFT', reason: 'Salah harga', total_amount: '250000.5000', ar_invoice_id: 'ai-2', invoice: null })]) },
    [`GET ${BASE}/customers`]: { data: customers },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
  }
  const listCalls = (calls: ReturnType<typeof bootAr>) => calls.filter((c) => c.url === `${BASE}/ar-credit-notes`)

  it('renders the credit notes the API returned and links each one and its invoice', async () => {
    bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/nota-kredit')

    const link = await screen.findByRole('link', { name: 'CN-FY2026-000001' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/nota-kredit/cn-1')
    expect(screen.getByRole('link', { name: 'Draf' })).toHaveAttribute('href', '/app/akuntansi/nota-kredit/cn-2')
    const row = within(link.closest('tr') as HTMLElement)
    expect(row.getByText('PT Pelanggan Setia')).toBeInTheDocument()
    expect(row.getByRole('link', { name: 'ARI-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan/ai-1')
    expect(row.getByText('Retur sebagian')).toBeInTheDocument()
    expect(row.getByText('100.000,00')).toBeInTheDocument()
    expect(row.getByText('Terposting')).toBeInTheDocument()
    const draft = within(screen.getByRole('link', { name: 'Draf' }).closest('tr') as HTMLElement)
    expect(draft.getByText('250.000,50')).toBeInTheDocument()
    expect(draft.getByText('Salah harga')).toBeInTheDocument()
    expect(draft.queryByRole('link', { name: /ARI-/ })).not.toBeInTheDocument() // the invoice was not returned
  })

  it('sends every filter to the server (flags as 1, empty values dropped) and starts again from page 1', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/nota-kredit')
    await screen.findByRole('link', { name: 'CN-FY2026-000001' })

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-2')
    await userEvent.click(screen.getByLabelText('Buatan saya'))
    await userEvent.type(screen.getByLabelText('Posting dari'), '2026-09-01')
    await userEvent.type(screen.getByLabelText('Dokumen sampai'), '2026-09-30')
    await userEvent.type(screen.getByLabelText('Cari nota kredit'), 'retur')

    await waitFor(() => {
      expect(listCalls(calls).at(-1)?.params).toEqual({ page: 1, status: 'POSTED', customer_id: 'c-2', mine: 1, posting_from: '2026-09-01', document_to: '2026-09-30', q: 'retur' })
    })
  })

  it('starts from the status a link carries (the accounting home links with ?status=SUBMITTED)', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/nota-kredit?status=SUBMITTED')
    await screen.findByRole('link', { name: 'CN-FY2026-000001' })
    expect(listCalls(calls)[0].params).toEqual({ page: 1, status: 'SUBMITTED' })
    expect(screen.getByLabelText('Status')).toHaveValue('SUBMITTED')
  })

  it('offers CSV export only with accounting.report.export and exports with the same filters', async () => {
    const create = mockDownload()
    bootAr({ permissions: view }, routes)
    const first = renderApp('/app/akuntansi/nota-kredit')
    await screen.findByRole('link', { name: 'CN-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    first.unmount()

    const calls = bootAr({ permissions: [...view, 'accounting.report.export'] }, { ...routes, [`GET ${BASE}/ar-credit-notes/export`]: { data: new Blob(['x']) } })
    renderApp('/app/akuntansi/nota-kredit')
    await screen.findByRole('link', { name: 'CN-FY2026-000001' })
    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-credit-notes/export`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ar-credit-notes/export`)?.params).toEqual({ status: 'POSTED' })
    expect(create).toHaveBeenCalled()
  })

  it('shows the new-note button only to users who can create, and never on a read-only module', async () => {
    bootAr({ permissions: [...view, 'accounting.ar_credit_note.create'] }, routes)
    const first = renderApp('/app/akuntansi/nota-kredit')
    expect(await screen.findByRole('button', { name: 'Nota kredit baru' })).toBeInTheDocument()
    first.unmount()

    bootAr({ permissions: view }, routes)
    const second = renderApp('/app/akuntansi/nota-kredit')
    await screen.findByRole('link', { name: 'CN-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Nota kredit baru' })).not.toBeInTheDocument()
    second.unmount()

    bootAr({ permissions: [...view, 'accounting.ar_credit_note.create'], ar: 'READ_ONLY' }, routes)
    renderApp('/app/akuntansi/nota-kredit')
    await screen.findByRole('link', { name: 'CN-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Nota kredit baru' })).not.toBeInTheDocument()
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
  })

  it('shows an error with a retry, and an empty state', async () => {
    let fail = true
    bootAr({ permissions: view }, { ...routes, [`GET ${BASE}/ar-credit-notes`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: page([]) }) })
    renderApp('/app/akuntansi/nota-kredit')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Tidak ada nota kredit')).toBeInTheDocument()
  })

  it('shows a loading state while the list is on its way', async () => {
    bootAr({ permissions: view }, routes)
    const release = holdGet(`${BASE}/ar-credit-notes`)
    renderApp('/app/akuntansi/nota-kredit')
    await screen.findByLabelText('Cari nota kredit') // the page is up; only the list is still on its way
    expect(screen.getByText('Memuat…')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'CN-FY2026-000001' })).not.toBeInTheDocument()
    release()
    expect(await screen.findByRole('link', { name: 'CN-FY2026-000001' })).toBeInTheDocument()
  })

  it('is closed without the permission, the feature or the module', async () => {
    bootAr({ permissions: ['accounting.ar_invoice.view'] }, routes)
    const first = renderApp('/app/akuntansi/nota-kredit')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
    first.unmount()

    bootAr({ permissions: view, features: { CREDIT_NOTE: false } }, routes)
    const second = renderApp('/app/akuntansi/nota-kredit')
    expect(await screen.findByText('Fitur tidak tersedia')).toBeInTheDocument()
    second.unmount()

    const calls = bootAr({ permissions: view, modules: { ACCOUNTING_CORE: 'FULL' } }, routes)
    renderApp('/app/akuntansi/nota-kredit')
    expect(await screen.findByText('Modul tidak tersedia')).toBeInTheDocument()
    expect(calls.some((c) => c.url.includes('ar-credit-notes'))).toBe(false)
  })
})

describe('credit note editor', () => {
  const permissions = ['accounting.ar_credit_note.view', 'accounting.ar_credit_note.create']
  const open1 = arInvoiceRow({ id: 'ai-1', document_number: 'ARI-FY2026-000001', customer_reference: 'PO-100', outstanding_amount: '900000.0000' })
  const open2 = arInvoiceRow({ id: 'ai-2', document_number: 'ARI-FY2026-000002', customer_reference: null, outstanding_amount: '250000.5000' })
  const routes = {
    [`GET ${BASE}/customers`]: { data: customers },
    [`GET ${BASE}/ar-invoices`]: (request: { params?: Record<string, unknown> }) => ({ data: page(request.params?.customer_id === 'c-1' ? [open1, open2] : []) }),
    [`GET ${BASE}/accounts`]: { data: { data: [account('a-rev', '4100', 'Pendapatan penjualan', { account_type: 'REVENUE', normal_balance: 'CREDIT' }), account('a-exp', '6100', 'Beban jasa')] } },
    [`GET ${BASE}/account-mappings`]: { data: { roles: [{ code: 'SALES_REVENUE', name: 'Pendapatan penjualan', description: null, used_by_published_rule: true, mapped: true }, { code: 'ACCOUNTS_RECEIVABLE', name: 'Piutang usaha', description: null, used_by_published_rule: true, mapped: true }], mappings: [] } },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
  }
  const saved = creditNote({ id: 'cn-9', status: 'DRAFT', document_number: null })
  const create = { ...routes, [`POST ${BASE}/ar-credit-notes`]: { status: 201, data: saved }, [`GET ${BASE}/ar-credit-notes/cn-9`]: { data: saved } }

  async function open(extra: Parameters<typeof mockApi>[0] = {}, perms = permissions, path = '/app/akuntansi/nota-kredit/baru') {
    const calls = bootAr({ permissions: perms }, { ...create, ...extra })
    renderApp(path)
    await screen.findByRole('heading', { name: 'Nota kredit baru' })
    return calls
  }
  async function fillMinimum() {
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(await screen.findByRole('combobox', { name: 'Faktur yang dikreditkan' }), 'ai-1')
    await userEvent.type(screen.getByLabelText('Alasan'), 'Retur barang')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Retur')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '100')
  }
  const invoiceSelect = () => screen.getByRole('combobox', { name: 'Faktur yang dikreditkan' })

  it('reads only active customers, and offers the posted invoices with a balance of the chosen customer', async () => {
    const calls = await open()
    expect(calls.find((c) => c.url === `${BASE}/customers`)?.params).toEqual({ per_page: 200, status: 'ACTIVE' })
    expect(calls.some((c) => c.url === `${BASE}/ar-invoices`)).toBe(false) // nothing to ask before a customer is chosen
    expect(invoiceSelect()).toBeDisabled()
    expect(within(invoiceSelect()).getByRole('option', { name: 'Pilih pelanggan terlebih dahulu' })).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await waitFor(() => expect(within(invoiceSelect()).getAllByRole('option').map((o) => o.textContent)).toEqual(['Pilih faktur…', 'ARI-FY2026-000001 · PO-100', 'ARI-FY2026-000002']))
    expect(calls.find((c) => c.url === `${BASE}/ar-invoices`)?.params).toEqual({ status: 'POSTED', open: 1, customer_id: 'c-1', per_page: 100 })
  })

  it('shows the outstanding balance of the invoice exactly as the server reported it', async () => {
    await open()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(await screen.findByRole('combobox', { name: 'Faktur yang dikreditkan' }), 'ai-2')
    const panel = screen.getByText('Saldo piutang faktur', { selector: '.label' }).closest('.field') as HTMLElement
    expect(within(panel).getByText('250.000,50')).toBeInTheDocument()
    expect(within(panel).getByText(/Dihitung server dari penerimaan dan nota kredit yang sudah diposting/)).toBeInTheDocument()
  })

  it('says so when the customer has no invoice with a balance, and drops the chosen invoice when the customer changes', async () => {
    await open()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(await screen.findByRole('combobox', { name: 'Faktur yang dikreditkan' }), 'ai-1')
    expect(invoiceSelect()).toHaveValue('ai-1')

    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-2')
    expect(invoiceSelect()).toHaveValue('')
    expect(await screen.findByText('Pelanggan ini tidak memiliki faktur terbuka.')).toBeInTheDocument()
  })

  it('builds the exact API payload from decimal strings, previews the total in exact arithmetic and never sends a number', async () => {
    const calls = await open()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(await screen.findByRole('combobox', { name: 'Faktur yang dikreditkan' }), 'ai-1')
    await userEvent.type(screen.getByLabelText('Alasan'), '  Retur sebagian  ')
    await userEvent.type(screen.getByLabelText('Referensi'), 'RTR-7')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Retur kertas')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '100000,10')
    await userEvent.selectOptions(screen.getByLabelText('Akun pendapatan baris 1'), 'a-rev')
    expect(within(screen.getByLabelText('Akun pendapatan baris 1')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Ikuti peran / default pelanggan', '4100 · Pendapatan penjualan'])

    await userEvent.click(screen.getByRole('button', { name: 'Tambah baris' }))
    await userEvent.type(screen.getByLabelText('Deskripsi baris 2'), 'Retur map')
    await userEvent.click(screen.getByLabelText(/Hitung dari kuantitas × harga satuan \(baris 2\)/))
    await userEvent.type(screen.getByLabelText('Kuantitas baris 2'), '3')
    await userEvent.type(screen.getByLabelText('Harga satuan baris 2'), '1000,5')
    await userEvent.type(screen.getByLabelText('Pajak'), '11000')

    // 100.000,10 + (3 x 1.000,50 = 3.001,50) = 103.001,60 ; plus tax 11.000 = 114.001,60
    const totals = within(screen.getByText(/Total pratinjau/).closest('.totals') as HTMLElement)
    expect(totals.getByText('103.001,60')).toBeInTheDocument()
    expect(totals.getByText('114.001,60')).toBeInTheDocument()
    expect(screen.getByText(/Server menghitung total nota kredit dan menolaknya bila melebihi saldo piutang faktur/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/ar-credit-notes`)).toBe(true))
    const post = calls.find((c) => c.method === 'POST' && c.url === `${BASE}/ar-credit-notes`)
    expect(post?.data).toEqual({
      ar_invoice_id: 'ai-1', reason: 'Retur sebagian', reference: 'RTR-7', document_date: '2026-10-08', posting_date: '2026-10-08',
      branch_id: null, business_unit_id: null, cost_center_id: null, tax_amount: '11000.0000',
      lines: [
        { description: 'Retur kertas', amount: '100000.1000', account_role: null, account_id: 'a-rev', cost_center_id: null },
        { description: 'Retur map', quantity: '3.0000', unit_price: '1000.5000', account_role: null, account_id: null, cost_center_id: null },
      ],
    })
    expect(JSON.stringify(post?.data)).not.toMatch(/"(amount|quantity|unit_price|tax_amount)":\d/)
    expect(await screen.findByRole('heading', { name: 'Draf nota kredit' })).toBeInTheDocument()
    expect(await screen.findByText('Draf nota kredit disimpan.')).toBeInTheDocument()
  })

  it('adds money exactly, never in floating point', async () => {
    await open()
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '0,10')
    await userEvent.click(screen.getByRole('button', { name: 'Tambah baris' }))
    await userEvent.type(screen.getByLabelText('Jumlah baris 2'), '0,20')
    const totals = within(screen.getByText(/Total pratinjau/).closest('.totals') as HTMLElement)
    expect(totals.getAllByText('0,30')).toHaveLength(2)
    expect(totals.queryByText('0,3')).not.toBeInTheDocument()
  })

  it('refuses obviously invalid input before any request and shows what to fix', async () => {
    const calls = await open()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Pilih faktur yang dikreditkan.')).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(await screen.findByRole('combobox', { name: 'Faktur yang dikreditkan' }), 'ai-1')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Isi alasan nota kredit.')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Alasan'), 'Retur')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Isi deskripsi baris 1.')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Baris')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '1.500.000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(/Jumlah pada baris 1 tidak valid/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('shows the validation messages of the server next to the fields they point at', async () => {
    await open({
      [`POST ${BASE}/ar-credit-notes`]: { status: 422, data: { message: 'The given data was invalid.', errors: { reason: ['Alasan terlalu panjang.'], 'lines.0.amount': ['Jumlah baris pertama tidak valid.'] } } },
    })
    await fillMinimum()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Alasan terlalu panjang.', { selector: '.error' })).toBeInTheDocument()
    expect(screen.getByText('Jumlah baris pertama tidak valid.', { selector: '.error' })).toBeInTheDocument()
    expect(screen.getByLabelText('Alasan')).toHaveAttribute('aria-invalid', 'true')
  })

  it.each([
    ['AR_CREDIT_NOTE_EXCEEDS_OUTSTANDING', { document_number: 'ARI-FY2026-000001', outstanding: '900000.0000' }, 'Nota kredit melebihi saldo piutang faktur. (faktur ARI-FY2026-000001, saldo piutang 900.000,00)'],
    ['AR_CREDIT_NOTE_EXCEEDS_INVOICE', {}, 'Nota kredit tidak boleh mengurangi pendapatan atau pajak lebih dari yang diakui faktur.'],
    ['AR_CREDIT_NOTE_CUSTOMER_MISMATCH', {}, 'Nota kredit harus milik pelanggan dari faktur yang dikreditkan.'],
    ['AR_CREDIT_NOTE_TOTAL_INVALID', {}, 'Total nota kredit harus lebih besar dari nol.'],
  ])('shows the refusal %s of the server in words, with the figures it reported', async (code, details, text) => {
    await open({ [`POST ${BASE}/ar-credit-notes`]: { status: 422, data: { code, message: 'x', details } } })
    await fillMinimum()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(text)).toBeInTheDocument()
  })

  it('starts from the invoice a link carries: the customer and the invoice are chosen and the customer cannot be changed', async () => {
    const calls = bootAr({ permissions }, { ...create, [`GET ${BASE}/ar-invoices/ai-1`]: { data: arInvoice() } })
    renderApp('/app/akuntansi/nota-kredit/baru?faktur=ai-1')
    await screen.findByRole('heading', { name: 'Nota kredit baru' })

    expect(screen.getByLabelText('Pelanggan')).toHaveValue('c-1')
    expect(screen.getByLabelText('Pelanggan')).toBeDisabled()
    await waitFor(() => expect(invoiceSelect()).toHaveValue('ai-1'))
    const panel = screen.getByText('Saldo piutang faktur', { selector: '.label' }).closest('.field') as HTMLElement
    expect(within(panel).getByText('900.000,00')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Alasan'), 'Retur')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Retur')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '100')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/ar-credit-notes`)).toBe(true))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${BASE}/ar-credit-notes`)?.data).toMatchObject({ ar_invoice_id: 'ai-1' })
  })

  it('shows an error with retry when the invoice it starts from cannot be loaded', async () => {
    bootAr({ permissions }, { ...create, [`GET ${BASE}/ar-invoices/ai-1`]: { status: 404, data: { message: 'x' } } })
    renderApp('/app/akuntansi/nota-kredit/baru?faktur=ai-1')
    expect(await screen.findByText('Data tidak ditemukan.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })

  it('offers no "Simpan & ajukan" without the submit permission', async () => {
    await open()
    expect(screen.queryByRole('button', { name: 'Simpan & ajukan' })).not.toBeInTheDocument()
  })

  it('saves and submits in one step with the submit permission', async () => {
    const calls = await open({ [`POST ${BASE}/ar-credit-notes/cn-9/submit`]: { data: creditNote({ status: 'SUBMITTED' }) } }, [...permissions, 'accounting.ar_credit_note.submit'])
    await fillMinimum()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-credit-notes/cn-9/submit`)).toBe(true))
    expect(await screen.findByText('Nota kredit disimpan dan diajukan.')).toBeInTheDocument()
  })

  it('keeps the saved draft when it cannot be submitted, and says why', async () => {
    const calls = await open(
      { [`POST ${BASE}/ar-credit-notes/cn-9/submit`]: { status: 422, data: { code: 'AR_CREDIT_NOTE_EXCEEDS_OUTSTANDING', message: 'x', details: { document_number: 'ARI-FY2026-000001', outstanding: '50000.0000' } } } },
      [...permissions, 'accounting.ar_credit_note.submit'],
    )
    await fillMinimum()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }))
    expect(await screen.findByText(/Draf disimpan, tetapi belum dapat diajukan: Nota kredit melebihi saldo piutang faktur\. \(faktur ARI-FY2026-000001, saldo piutang 50\.000,00\)/)).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST' && c.url === `${BASE}/ar-credit-notes`)).toHaveLength(1) // not created twice
    expect(await screen.findByRole('heading', { name: 'Draf nota kredit' })).toBeInTheDocument()
  })

  it('edits a draft with PATCH, and locks the customer and the invoice of a saved note', async () => {
    const draft = creditNote({ id: 'cn-3', status: 'DRAFT', document_number: null })
    const calls = bootAr({ permissions }, { ...routes, [`GET ${BASE}/ar-credit-notes/cn-3`]: { data: draft }, [`PATCH ${BASE}/ar-credit-notes/cn-3`]: { data: draft } })
    renderApp('/app/akuntansi/nota-kredit/cn-3/ubah')
    expect(await screen.findByRole('heading', { name: 'Ubah draf nota kredit' })).toBeInTheDocument()

    expect(screen.getByLabelText('Pelanggan')).toBeDisabled()
    expect(invoiceSelect()).toBeDisabled()
    expect(invoiceSelect()).toHaveValue('ai-1')
    expect(screen.getByText('Faktur nota kredit yang sudah disimpan tidak dapat diganti; buat nota baru.')).toBeInTheDocument()
    expect(screen.getByLabelText('Alasan')).toHaveValue('Retur sebagian')
    expect(screen.getByLabelText('Jumlah baris 1')).toHaveValue('100000')

    await userEvent.clear(screen.getByLabelText('Alasan'))
    await userEvent.type(screen.getByLabelText('Alasan'), 'Retur lengkap')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH' && c.url === `${BASE}/ar-credit-notes/cn-3`)).toBe(true))
    expect(calls.find((c) => c.method === 'PATCH')?.data).toMatchObject({ ar_invoice_id: 'ai-1', reason: 'Retur lengkap' })
    expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/ar-credit-notes`)).toBe(false)
  })

  it('refuses to edit a note that is no longer a draft', async () => {
    bootAr({ permissions }, { ...routes, [`GET ${BASE}/ar-credit-notes/cn-1`]: { data: creditNote() } })
    renderApp('/app/akuntansi/nota-kredit/cn-1/ubah')
    expect(await screen.findByText('Hanya nota kredit berstatus draf yang dapat diubah. Nota kredit yang sudah diposting dikoreksi dengan pembalikan.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Simpan draf' })).not.toBeInTheDocument()
  })

  it('shows a read-only module as a notice instead of the form', async () => {
    bootAr({ permissions, ar: 'READ_ONLY' }, create)
    renderApp('/app/akuntansi/nota-kredit/baru')
    expect(await screen.findByText('Modul hanya baca')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Simpan draf' })).not.toBeInTheDocument()
  })

  it('is closed to a user without the create permission', async () => {
    bootAr({ permissions: ['accounting.ar_credit_note.view'] }, create)
    renderApp('/app/akuntansi/nota-kredit/baru')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
  })
})

describe('credit note detail', () => {
  const base = ['accounting.ar_credit_note.view']
  const all = [...base, 'accounting.ar_credit_note.create', 'accounting.ar_credit_note.submit', 'accounting.ar_credit_note.approve', 'accounting.ar_credit_note.post', 'accounting.ar_credit_note.reverse']
  const open = (permissions: string[], note: ReturnType<typeof creditNote>, options: { ar?: 'FULL' | 'READ_ONLY'; subscription?: 'FULL' | 'READ_ONLY'; features?: Record<string, boolean> } = {}, extra: Parameters<typeof mockApi>[0] = {}) => {
    const calls = bootAr({ permissions, ...options }, { [`GET ${BASE}/ar-credit-notes/${note.id}`]: { data: note }, ...extra })
    renderApp(`/app/akuntansi/nota-kredit/${note.id}`)
    return calls
  }
  const buttons = () => ['Ubah', 'Ajukan', 'Setujui', 'Tolak', 'Posting', 'Jadikan draf', 'Batalkan', 'Balik'].filter((n) => screen.queryByRole('button', { name: n }) ?? screen.queryByRole('link', { name: n }))
  const fact = (label: string) => within(within(document.body).getByText(label, { selector: 'dt' }).closest('div') as HTMLElement)

  it('shows the facts, the lines, the totals and the invoice outstanding reported by the server', async () => {
    open(base, creditNote())

    expect(await screen.findByRole('heading', { name: 'CN-FY2026-000001' })).toBeInTheDocument()
    expect(fact('Pelanggan').getByText('C1 · PT Pelanggan Setia')).toBeInTheDocument()
    expect(fact('Faktur dikreditkan').getByRole('link', { name: 'ARI-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan/ai-1')
    expect(fact('Saldo piutang faktur').getByText('900.000,00')).toBeInTheDocument() // from the API, never subtracted here
    expect(fact('Alasan').getByText('Retur sebagian')).toBeInTheDocument()
    expect(fact('Referensi').getByText('—')).toBeInTheDocument()
    expect(fact('Dibuat oleh').getByText('Budi Santoso')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Lihat jurnal' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-20')
    expect(screen.getByText('Retur barang')).toBeInTheDocument()
    expect(screen.getByText('Akun 4100')).toBeInTheDocument()
    const total = within(screen.getByText(/^Total/, { selector: 'span' }).closest('.table-total') as HTMLElement)
    expect(total.getByText('100.000,00', { selector: '.money.strong' })).toBeInTheDocument()
    expect(buttons()).toEqual([])
  })

  it('shows quantity and unit price of a line that has them', async () => {
    open(base, creditNote({ lines: [{ id: 'cl-2', line_number: 1, description: 'Retur kertas', quantity: '3.0000', unit_price: '1000.5000', amount: '3001.5000', account_role: 'SALES_REVENUE', account_id: null, cost_center_id: null, account: null }] }))
    expect(await screen.findByText('Retur kertas')).toBeInTheDocument()
    expect(screen.getByText('Peran SALES_REVENUE')).toBeInTheDocument()
    expect(document.querySelector('[data-label="Kuantitas × harga"]')).toHaveTextContent('3 × 1.000,50')
  })

  it('offers edit, submit and cancel on a draft, and no posting while approval is required', async () => {
    open(all, creditNote({ status: 'DRAFT', document_number: null }))
    await screen.findByRole('heading', { name: 'Draf nota kredit' })
    expect(screen.getByRole('link', { name: 'Ubah' })).toHaveAttribute('href', '/app/akuntansi/nota-kredit/cn-1/ubah')
    expect(buttons()).toEqual(['Ubah', 'Ajukan', 'Batalkan'])
  })

  it('treats the create permission as the right to edit, cancel and reopen (as the API routes do)', async () => {
    open([...base, 'accounting.ar_credit_note.create'], creditNote({ status: 'DRAFT', document_number: null }))
    await screen.findByRole('heading', { name: 'Draf nota kredit' })
    expect(buttons()).toEqual(['Ubah', 'Batalkan']) // no submit permission, so no "Ajukan"
  })

  it('posts a draft directly when the accounting policy needs no approval', async () => {
    open(all, creditNote({ status: 'DRAFT', document_number: null, sod: { approve: true, post: true, approval_required: false } }))
    await screen.findByRole('heading', { name: 'Draf nota kredit' })
    expect(screen.getByRole('button', { name: 'Posting' })).toBeEnabled()
    expect(screen.queryByRole('button', { name: 'Ajukan' })).not.toBeInTheDocument()
  })

  it('submits a draft with POST /submit and reloads', async () => {
    const calls = open(all, creditNote({ status: 'DRAFT', document_number: null }), {}, { [`POST ${BASE}/ar-credit-notes/cn-1/submit`]: { data: creditNote({ status: 'SUBMITTED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Ajukan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-credit-notes/cn-1/submit`)).toBe(true))
    expect(await screen.findByText('Nota kredit diajukan.')).toBeInTheDocument()
    await waitFor(() => expect(calls.filter((c) => c.method === 'GET' && c.url === `${BASE}/ar-credit-notes/cn-1`).length).toBeGreaterThan(1))
  })

  it('shows the segregation-of-duties flags of the server: approve and reject are disabled and explained', async () => {
    open(all, creditNote({ status: 'SUBMITTED', document_number: null, sod: { approve: false, post: false, approval_required: true } }))
    expect(await screen.findByRole('button', { name: 'Setujui' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Tolak' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat menyetujui nota kredit/)).toBeInTheDocument()
  })

  it('approves a submitted note with POST /approve', async () => {
    const calls = open(all, creditNote({ status: 'SUBMITTED', document_number: null }), {}, { [`POST ${BASE}/ar-credit-notes/cn-1/approve`]: { data: creditNote({ status: 'APPROVED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Setujui' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-credit-notes/cn-1/approve`)).toBe(true))
    expect(await screen.findByText('Nota kredit disetujui.')).toBeInTheDocument()
  })

  it('rejects with a reason of at least three characters', async () => {
    const calls = open(all, creditNote({ status: 'SUBMITTED', document_number: null }), {}, { [`POST ${BASE}/ar-credit-notes/cn-1/reject`]: { data: creditNote({ status: 'REJECTED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Tolak' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Tolak nota kredit' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'ab')
    await userEvent.click(dialog.getByRole('button', { name: 'Tolak' }))
    expect(calls.some((c) => c.url === `${BASE}/ar-credit-notes/cn-1/reject`)).toBe(false)

    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'c - nilai salah')
    await userEvent.click(dialog.getByRole('button', { name: 'Tolak' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-credit-notes/cn-1/reject`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ar-credit-notes/cn-1/reject`)?.data).toEqual({ reason: 'abc - nilai salah' })
  })

  it('cancels a draft with a reason', async () => {
    const calls = open(all, creditNote({ status: 'DRAFT', document_number: null }), {}, { [`POST ${BASE}/ar-credit-notes/cn-1/cancel`]: { data: creditNote({ status: 'CANCELLED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Batalkan' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Batalkan nota kredit' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Retur dibatalkan')
    await userEvent.click(dialog.getByRole('button', { name: 'Batalkan nota kredit' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-credit-notes/cn-1/cancel`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ar-credit-notes/cn-1/cancel`)?.data).toEqual({ reason: 'Retur dibatalkan' })
  })

  it('posts an approved note after a confirmation', async () => {
    const calls = open(all, creditNote({ status: 'APPROVED', document_number: null }), {}, { [`POST ${BASE}/ar-credit-notes/cn-1/post`]: { data: creditNote() } })
    await screen.findByRole('button', { name: 'Posting' })
    expect(buttons()).not.toContain('Balik')
    await userEvent.click(screen.getByRole('button', { name: 'Posting' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Posting nota kredit' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Posting' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-credit-notes/cn-1/post`)).toBe(true))
    expect(await screen.findByText('Nota kredit diposting.')).toBeInTheDocument()
  })

  it('shows the figures the server reports when posting is refused because the note exceeds the invoice balance', async () => {
    open(all, creditNote({ status: 'APPROVED', document_number: null }), {}, {
      [`POST ${BASE}/ar-credit-notes/cn-1/post`]: { status: 422, data: { code: 'AR_CREDIT_NOTE_EXCEEDS_OUTSTANDING', message: 'x', details: { document_number: 'ARI-FY2026-000001', outstanding: '40000.0000' } } },
    })
    await userEvent.click(await screen.findByRole('button', { name: 'Posting' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Posting nota kredit' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Posting' }))
    expect(await dialog.findByText('Nota kredit melebihi saldo piutang faktur. (faktur ARI-FY2026-000001, saldo piutang 40.000,00)')).toBeInTheDocument()
  })

  it('shows the date refusal of the server inside the posting dialog', async () => {
    open(all, creditNote({ status: 'APPROVED', document_number: null }), {}, {
      [`POST ${BASE}/ar-credit-notes/cn-1/post`]: { status: 422, data: { code: 'AR_CREDIT_NOTE_BEFORE_INVOICE', message: 'x' } },
    })
    await userEvent.click(await screen.findByRole('button', { name: 'Posting' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Posting nota kredit' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Posting' }))
    expect(await dialog.findByText('Nota kredit tidak dapat diposting sebelum tanggal faktur yang dikreditkan.')).toBeInTheDocument()
  })

  it('explains the posting refusal of segregation of duties', async () => {
    open(all, creditNote({ status: 'APPROVED', document_number: null, sod: { approve: true, post: false, approval_required: true } }))
    expect(await screen.findByRole('button', { name: 'Posting' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat memposting nota kredit/)).toBeInTheDocument()
  })

  it('reverses a posted note with a reason and the posting date, only for a user who may reverse', async () => {
    const calls = open(all, creditNote(), {}, { [`POST ${BASE}/ar-credit-notes/cn-1/reverse`]: { data: creditNote({ status: 'REVERSED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Balik' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Balik nota kredit' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Retur dibatalkan')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-credit-notes/cn-1/reverse`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ar-credit-notes/cn-1/reverse`)?.data).toEqual({ reason: 'Retur dibatalkan', posting_date: '2026-10-08' })
  })

  it('shows a reversed note with its reversal journal, that the invoice balance is back, and no action', async () => {
    open(all, creditNote({ status: 'REVERSED', reversal_journal_id: 'j-21', reversal_reason: 'Retur dibatalkan' }))
    expect(await screen.findByText(/Nota kredit ini sudah dibalik: Retur dibatalkan/)).toBeInTheDocument()
    expect(screen.getByText(/Saldo piutang fakturnya kembali seperti sebelum dikreditkan/)).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Lihat jurnal pembalik' }).every((l) => l.getAttribute('href') === '/app/akuntansi/jurnal/j-21')).toBe(true)
    expect(buttons()).toEqual([])
  })

  it('shows why a rejected note ended, and reopens it as a draft', async () => {
    const calls = open(all, creditNote({ status: 'REJECTED', document_number: null, reject_reason: 'Alasan tidak jelas' }), {}, { [`POST ${BASE}/ar-credit-notes/cn-1/reopen`]: { data: creditNote({ status: 'DRAFT' }) } })
    expect(await screen.findByText('Ditolak: Alasan tidak jelas')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Jadikan draf' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-credit-notes/cn-1/reopen`)).toBe(true))
  })

  it('shows the reason of a cancelled note and no action', async () => {
    open(all, creditNote({ status: 'CANCELLED', document_number: null, cancel_reason: 'Dobel input' }))
    expect(await screen.findByText('Dibatalkan: Dobel input')).toBeInTheDocument()
    expect(buttons()).toEqual([])
  })

  it('offers no mutation to a user who only holds the view permission', async () => {
    open(base, creditNote({ status: 'APPROVED', document_number: null }))
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
  })

  it('offers no mutation on a read-only AR module', async () => {
    open(all, creditNote({ status: 'APPROVED', document_number: null }), { ar: 'READ_ONLY' })
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
  })

  it('offers no mutation on a read-only subscription', async () => {
    open(all, creditNote({ status: 'DRAFT', document_number: null }), { subscription: 'READ_ONLY' })
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
  })

  it('shows an error with retry when the note cannot be loaded', async () => {
    bootAr({ permissions: base }, { [`GET ${BASE}/ar-credit-notes/cn-1`]: { status: 404, data: { message: 'x' } } })
    renderApp('/app/akuntansi/nota-kredit/cn-1')
    expect(await screen.findByText('Data tidak ditemukan.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })
})
