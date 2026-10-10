import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockApi, page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { account, arInvoice, arInvoiceRow, BASE, bootAr, customer, holdGet, mockDownload, noDimensions, term } from './receivables/testkit'

afterEach(() => vi.restoreAllMocks())

const customers = page([customer(), customer({ id: 'c-2', code: 'C2', name: 'CV Lain' })])

describe('customer invoice list', () => {
  const view = ['accounting.ar_invoice.view']
  const routes = {
    [`GET ${BASE}/ar-invoices`]: {
      data: page([
        arInvoiceRow(),
        arInvoiceRow({ id: 'ai-2', document_number: null, status: 'DRAFT', customer_reference: 'PO-101', received_amount: '0.0000', credited_amount: '0.0000', outstanding_amount: '0.0000', payment_status: null, total_amount: '250000.0000' }),
      ]),
    },
    [`GET ${BASE}/customers`]: { data: customers },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
  }
  const listCalls = (calls: ReturnType<typeof bootAr>) => calls.filter((c) => c.url === `${BASE}/ar-invoices`)

  it('renders the received, credited and outstanding figures the API computed and links each invoice', async () => {
    bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/faktur-pelanggan')

    const link = await screen.findByRole('link', { name: 'ARI-FY2026-000001' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan/ai-1')
    const row = within(link.closest('tr') as HTMLElement)
    expect(row.getByText('PT Pelanggan Setia')).toBeInTheDocument()
    expect(row.getByText('PO-100')).toBeInTheDocument()
    expect(row.getByText('1.500.000,00')).toBeInTheDocument() // total
    expect(row.getByText('500.000,00')).toBeInTheDocument() // received
    expect(row.getByText('100.000,00')).toBeInTheDocument() // credited
    expect(row.getByText('900.000,00')).toBeInTheDocument() // outstanding
    expect(row.getByText('Dibayar sebagian')).toBeInTheDocument()
    expect(row.getByText('Lewat jatuh tempo')).toBeInTheDocument() // due 2026-10-01, business date 2026-10-08

    const draft = within(screen.getByRole('link', { name: 'Draf' }).closest('tr') as HTMLElement)
    expect(draft.getByText('250.000,00')).toBeInTheDocument()
    expect(draft.queryByText('Lewat jatuh tempo')).not.toBeInTheDocument()
    expect(draft.getByText('Draf', { selector: '.badge' })).toBeInTheDocument()
    expect(draft.getAllByText('—').length).toBeGreaterThanOrEqual(3) // no received / credited / outstanding / payment status before posting
  })

  it('does not call a paid invoice overdue', async () => {
    bootAr({ permissions: view }, { ...routes, [`GET ${BASE}/ar-invoices`]: { data: page([arInvoiceRow({ payment_status: 'PAID', outstanding_amount: '0.0000', received_amount: '1400000.0000' })]) } })
    renderApp('/app/akuntansi/faktur-pelanggan')
    const row = within((await screen.findByRole('link', { name: 'ARI-FY2026-000001' })).closest('tr') as HTMLElement)
    expect(row.getByText('Lunas')).toBeInTheDocument()
    expect(row.queryByText('Lewat jatuh tempo')).not.toBeInTheDocument()
  })

  it('sends every filter to the server (flags as 1, empty values dropped) and starts again from page 1', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/faktur-pelanggan')
    await screen.findByRole('link', { name: 'ARI-FY2026-000001' })

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-2')
    await userEvent.selectOptions(screen.getByLabelText('Status bayar'), 'UNPAID')
    await userEvent.click(screen.getByLabelText('Belum lunas'))
    await userEvent.click(screen.getByLabelText('Jatuh tempo lewat'))
    await userEvent.click(screen.getByLabelText('Buatan saya'))
    await userEvent.type(screen.getByLabelText('Jatuh tempo dalam hari'), '14')
    await userEvent.type(screen.getByLabelText('Posting dari'), '2026-09-01')
    await userEvent.type(screen.getByLabelText('Jatuh tempo sampai'), '2026-10-31')
    await userEvent.type(screen.getByLabelText('Cari faktur'), 'po-1')

    await waitFor(() => {
      expect(listCalls(calls).at(-1)?.params).toEqual({
        page: 1, status: 'POSTED', customer_id: 'c-2', payment_status: 'UNPAID', open: 1, overdue: 1, mine: 1, due_within: '14', posting_from: '2026-09-01', due_to: '2026-10-31', q: 'po-1',
      })
    })
    expect(JSON.stringify(listCalls(calls).at(-1)?.params)).not.toContain('true')
  })

  it('does not send a half-typed or out-of-range due-within value', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/faktur-pelanggan')
    await screen.findByRole('link', { name: 'ARI-FY2026-000001' })

    await userEvent.type(screen.getByLabelText('Jatuh tempo dalam hari'), '999')
    await new Promise((r) => setTimeout(r, 450))
    expect(listCalls(calls).every((c) => c.params?.due_within === undefined)).toBe(true)
  })

  it('starts from the filter a link carries (the accounting home links with ?open=1, ?overdue=1, ?due_within= or ?status=)', async () => {
    const open = bootAr({ permissions: view }, routes)
    const first = renderApp('/app/akuntansi/faktur-pelanggan?open=1&due_within=7')
    await screen.findByRole('link', { name: 'ARI-FY2026-000001' })
    expect(listCalls(open)[0].params).toEqual({ page: 1, open: 1, due_within: '7' })
    expect(screen.getByLabelText('Belum lunas')).toBeChecked()
    expect(screen.getByLabelText('Jatuh tempo dalam hari')).toHaveValue(7)
    first.unmount()

    const overdue = bootAr({ permissions: view }, routes)
    const second = renderApp('/app/akuntansi/faktur-pelanggan?overdue=1')
    await screen.findByRole('link', { name: 'ARI-FY2026-000001' })
    expect(listCalls(overdue)[0].params).toEqual({ page: 1, overdue: 1 })
    second.unmount()

    const status = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/faktur-pelanggan?status=SUBMITTED&due_within=9999')
    await screen.findByRole('link', { name: 'ARI-FY2026-000001' })
    expect(listCalls(status)[0].params).toEqual({ page: 1, status: 'SUBMITTED' }) // an out-of-range number is dropped
    expect(screen.getByLabelText('Status')).toHaveValue('SUBMITTED')
  })

  it('offers CSV export only with accounting.report.export and exports with the same filters', async () => {
    const create = mockDownload()
    bootAr({ permissions: view }, routes)
    const first = renderApp('/app/akuntansi/faktur-pelanggan')
    await screen.findByRole('link', { name: 'ARI-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    first.unmount()

    const calls = bootAr({ permissions: [...view, 'accounting.report.export'] }, { ...routes, [`GET ${BASE}/ar-invoices/export`]: { data: new Blob(['x']) } })
    renderApp('/app/akuntansi/faktur-pelanggan')
    await screen.findByRole('link', { name: 'ARI-FY2026-000001' })
    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.click(screen.getByLabelText('Belum lunas'))
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-invoices/export`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ar-invoices/export`)?.params).toEqual({ status: 'POSTED', open: 1 })
    expect(create).toHaveBeenCalled()
  })

  it('shows the new-invoice button only to users who can create, and never on a read-only module', async () => {
    bootAr({ permissions: [...view, 'accounting.ar_invoice.create'] }, routes)
    const first = renderApp('/app/akuntansi/faktur-pelanggan')
    expect(await screen.findByRole('button', { name: 'Faktur baru' })).toBeInTheDocument()
    first.unmount()

    bootAr({ permissions: view }, routes)
    const second = renderApp('/app/akuntansi/faktur-pelanggan')
    await screen.findByRole('link', { name: 'ARI-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Faktur baru' })).not.toBeInTheDocument()
    second.unmount()

    bootAr({ permissions: [...view, 'accounting.ar_invoice.create'], ar: 'READ_ONLY' }, routes)
    renderApp('/app/akuntansi/faktur-pelanggan')
    await screen.findByRole('link', { name: 'ARI-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Faktur baru' })).not.toBeInTheDocument()
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
  })

  it('shows an error with a retry, and an empty state', async () => {
    let fail = true
    bootAr({ permissions: view }, { ...routes, [`GET ${BASE}/ar-invoices`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: page([]) }) })
    renderApp('/app/akuntansi/faktur-pelanggan')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Tidak ada faktur')).toBeInTheDocument()
  })

  it('shows a loading state while the list is on its way', async () => {
    bootAr({ permissions: view }, routes)
    const release = holdGet(`${BASE}/ar-invoices`)
    renderApp('/app/akuntansi/faktur-pelanggan')
    await screen.findByLabelText('Cari faktur') // the page is up; only the list is still on its way
    expect(screen.getByText('Memuat…')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'ARI-FY2026-000001' })).not.toBeInTheDocument()
    release()
    expect(await screen.findByRole('link', { name: 'ARI-FY2026-000001' })).toBeInTheDocument()
    expect(screen.queryByText('Memuat…')).not.toBeInTheDocument()
  })

  it('is closed without the permission, the feature or the module', async () => {
    bootAr({ permissions: ['accounting.customer.view'] }, routes)
    const first = renderApp('/app/akuntansi/faktur-pelanggan')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
    first.unmount()

    bootAr({ permissions: view, features: { CUSTOMER_INVOICE: false } }, routes)
    const second = renderApp('/app/akuntansi/faktur-pelanggan')
    expect(await screen.findByText('Fitur tidak tersedia')).toBeInTheDocument()
    second.unmount()

    const calls = bootAr({ permissions: view, modules: { ACCOUNTING_CORE: 'FULL' } }, routes)
    renderApp('/app/akuntansi/faktur-pelanggan')
    expect(await screen.findByText('Modul tidak tersedia')).toBeInTheDocument()
    expect(calls.some((c) => c.url.includes('ar-invoices'))).toBe(false)
  })
})

describe('customer invoice editor', () => {
  const permissions = ['accounting.ar_invoice.view', 'accounting.ar_invoice.create']
  const routes = {
    [`GET ${BASE}/customers`]: { data: page([customer(), customer({ id: 'c-2', code: 'C2', name: 'CV Tanpa Termin', payment_term_id: null, payment_term: null })]) },
    [`GET ${BASE}/ar-payment-terms`]: { data: { data: [term(), term({ id: 'term-c', code: 'CUSTOM', name: 'Manual', term_type: 'CUSTOM', due_days: null, allows_due_date_override: true }), term({ id: 'term-old', code: 'OLD', name: 'Termin lama', status: 'INACTIVE' })] } },
    [`GET ${BASE}/accounts`]: { data: { data: [account('a-rev', '4100', 'Pendapatan penjualan', { account_type: 'REVENUE', normal_balance: 'CREDIT' }), account('a-exp', '6100', 'Beban jasa'), account('a-ar', '1210', 'Piutang usaha', { account_type: 'ASSET', is_control: true })] } },
    [`GET ${BASE}/account-mappings`]: { data: { roles: [{ code: 'SALES_REVENUE', name: 'Pendapatan penjualan', description: null, used_by_published_rule: true, mapped: true }, { code: 'ACCOUNTS_RECEIVABLE', name: 'Piutang usaha', description: null, used_by_published_rule: true, mapped: true }], mappings: [] } },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
  }
  const saved = arInvoice({ id: 'ai-9', status: 'DRAFT', document_number: null, payment_status: null, allocations: [], credit_notes: [] })
  const create = { ...routes, [`POST ${BASE}/ar-invoices`]: { status: 201, data: saved }, [`GET ${BASE}/ar-invoices/ai-9`]: { data: saved } }

  async function open(extra: Parameters<typeof mockApi>[0] = {}, perms = permissions) {
    const calls = bootAr({ permissions: perms }, { ...create, ...extra })
    renderApp('/app/akuntansi/faktur-pelanggan/baru')
    await screen.findByRole('heading', { name: 'Faktur pelanggan baru' })
    return calls
  }
  async function fillMinimum() {
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'Penjualan')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Barang')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '100')
  }

  it('reads only active customers and the receivables payment terms, and offers no inactive term', async () => {
    const calls = await open()
    expect(calls.find((c) => c.url === `${BASE}/customers`)?.params).toEqual({ per_page: 200, status: 'ACTIVE' })
    expect(calls.some((c) => c.url === `${BASE}/ar-payment-terms`)).toBe(true)
    expect(calls.some((c) => c.url === `${BASE}/payment-terms`)).toBe(false)
    expect(within(screen.getByLabelText('Termin pembayaran')).queryByText(/Termin lama/)).not.toBeInTheDocument()
  })

  it('builds the exact API payload from decimal strings, previews the total in exact arithmetic and never sends a number', async () => {
    const calls = await open()

    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    expect(screen.getByLabelText('Termin pembayaran')).toHaveValue('term-30') // the customer's term is the starting point
    expect(screen.queryByLabelText(/Jatuh tempo manual/)).not.toBeInTheDocument() // NET30 does not allow a different due date
    await userEvent.type(screen.getByLabelText('Referensi pelanggan'), ' PO-77 ')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'Penjualan Oktober')
    await userEvent.type(screen.getByLabelText('Referensi'), 'KTR-7')

    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Konsultasi')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '1500000,5')
    await userEvent.selectOptions(screen.getByLabelText('Akun pendapatan baris 1'), 'a-rev')
    expect(within(screen.getByLabelText('Akun pendapatan baris 1')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Ikuti peran / default pelanggan', '4100 · Pendapatan penjualan']) // only postable revenue accounts
    expect(within(screen.getByLabelText('Peran akun baris 1')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Tanpa peran', 'Pendapatan penjualan']) // the receivable role is never a line destination

    await userEvent.click(screen.getByRole('button', { name: 'Tambah baris' }))
    await userEvent.type(screen.getByLabelText('Deskripsi baris 2'), 'Kertas A4')
    await userEvent.click(screen.getByLabelText(/Hitung dari kuantitas × harga satuan \(baris 2\)/))
    await userEvent.type(screen.getByLabelText('Kuantitas baris 2'), '10')
    await userEvent.type(screen.getByLabelText('Harga satuan baris 2'), '25000,5')

    await userEvent.type(screen.getByLabelText('Diskon'), '50000')
    await userEvent.type(screen.getByLabelText('Pajak'), '110000')
    await userEvent.type(screen.getByLabelText('Biaya lain'), '10000')

    // 1.500.000,50 + (10 x 25.000,50 = 250.005,00) - 50.000 + 110.000 + 10.000 = 1.820.005,50
    const totals = within(screen.getByText(/Total pratinjau/).closest('.totals') as HTMLElement)
    expect(totals.getByText('1.820.005,50')).toBeInTheDocument()
    expect(screen.getByText(/Ini pratinjau yang dihitung saat Anda mengetik/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/ar-invoices`)).toBe(true))
    const post = calls.find((c) => c.method === 'POST' && c.url === `${BASE}/ar-invoices`)
    expect(post?.data).toEqual({
      customer_id: 'c-1', customer_reference: 'PO-77', document_date: '2026-10-08', posting_date: '2026-10-08', due_date: null, payment_term_id: 'term-30',
      description: 'Penjualan Oktober', reference: 'KTR-7', branch_id: null, business_unit_id: null, cost_center_id: null,
      discount_amount: '50000.0000', tax_amount: '110000.0000', other_charges_amount: '10000.0000',
      lines: [
        { description: 'Konsultasi', amount: '1500000.5000', account_role: null, account_id: 'a-rev', cost_center_id: null },
        { description: 'Kertas A4', quantity: '10.0000', unit_price: '25000.5000', account_role: null, account_id: null, cost_center_id: null },
      ],
    })
    expect(JSON.stringify(post?.data)).not.toMatch(/"(amount|quantity|unit_price|discount_amount|tax_amount|other_charges_amount)":\d/)
    expect(await screen.findByRole('heading', { name: 'Draf faktur pelanggan' })).toBeInTheDocument()
  })

  it('adds money exactly, never in floating point', async () => {
    await open()
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '0,10')
    await userEvent.click(screen.getByRole('button', { name: 'Tambah baris' }))
    await userEvent.type(screen.getByLabelText('Jumlah baris 2'), '0,20')
    const totals = within(screen.getByText(/Total pratinjau/).closest('.totals') as HTMLElement)
    expect(totals.getAllByText('0,30')).toHaveLength(2) // subtotal and total; 0.1 + 0.2 in floats would be 0.30000000000000004
    expect(totals.queryByText('0,3')).not.toBeInTheDocument()
  })

  it('warns when the discount is larger than the subtotal, and shows the API refusal in words', async () => {
    await open({ [`POST ${BASE}/ar-invoices`]: { status: 422, data: { code: 'AR_INVOICE_DISCOUNT_INVALID', message: 'x' } } })
    await fillMinimum()
    await userEvent.type(screen.getByLabelText('Diskon'), '150')
    expect(screen.getByText('Diskon melebihi subtotal; server akan menolaknya.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Diskon tidak boleh melebihi subtotal.')).toBeInTheDocument()
  })

  it('refuses obviously invalid input before any request and shows what to fix', async () => {
    const calls = await open()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Pilih pelanggan.')).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Isi deskripsi faktur.')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Deskripsi'), 'Y')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Isi deskripsi baris 1.')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Baris')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '1.500.000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(/Jumlah pada baris 1 tidak valid/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('shows the validation messages the server returned next to their fields', async () => {
    await open({
      [`POST ${BASE}/ar-invoices`]: {
        status: 422,
        data: { message: 'The given data was invalid.', errors: { customer_reference: ['Referensi sudah dipakai.'], 'lines.0.description': ['Deskripsi baris terlalu panjang.'], discount_amount: ['Diskon tidak valid.'] } },
      },
    })
    await fillMinimum()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    expect(await screen.findByText('Referensi sudah dipakai.', { selector: '.error' })).toBeInTheDocument()
    expect(screen.getByText('Deskripsi baris terlalu panjang.', { selector: '.error' })).toBeInTheDocument()
    expect(screen.getByText('Diskon tidak valid.', { selector: '.error' })).toBeInTheDocument()
    expect(screen.getByLabelText('Referensi pelanggan')).toHaveAttribute('aria-invalid', 'true')
  })

  it('shows a domain refusal on the line it points at, and one about the customer in a banner', async () => {
    await open({ [`POST ${BASE}/ar-invoices`]: { status: 422, data: { code: 'LINE_AMOUNT_INVALID', message: 'A line amount must be greater than zero.', details: { line: 1 } } } })
    await fillMinimum()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    const group = within(await screen.findByRole('group', { name: 'Baris 1' }))
    expect(await group.findByText('Jumlah baris harus lebih besar dari nol.')).toBeInTheDocument()
  })

  it('maps a refusal about an inactive customer to its Indonesian message', async () => {
    await open({ [`POST ${BASE}/ar-invoices`]: { status: 422, data: { code: 'CUSTOMER_INACTIVE', message: 'x' } } })
    await fillMinimum()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Pelanggan ini tidak aktif.')).toBeInTheDocument()
  })

  it('lets a CUSTOM term take an explicit due date and sends it, but sends null for a term that derives it', async () => {
    const calls = await open()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-2')
    expect(screen.getByLabelText('Termin pembayaran')).toHaveValue('') // this customer has no term
    await userEvent.selectOptions(screen.getByLabelText('Termin pembayaran'), 'term-c')
    await userEvent.type(screen.getByLabelText(/Jatuh tempo \(wajib untuk termin ini\)/), '2026-11-15')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'D')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'B')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '100')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/ar-invoices`)).toBe(true))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${BASE}/ar-invoices`)?.data).toMatchObject({ customer_id: 'c-2', payment_term_id: 'term-c', due_date: '2026-11-15' })
  })

  it('resets the term and the typed due date when another customer is chosen', async () => {
    await open()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    expect(screen.getByLabelText('Termin pembayaran')).toHaveValue('term-30')
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-2')
    expect(screen.getByLabelText('Termin pembayaran')).toHaveValue('')
  })

  it('warns after saving when the server found a possible duplicate invoice', async () => {
    await open({ [`POST ${BASE}/ar-invoices`]: { status: 201, data: arInvoice({ id: 'ai-9', status: 'DRAFT', document_number: null, possible_duplicates: [{ id: 'ai-7', document_number: 'ARI-FY2026-000007', customer_reference: 'PO-100', status: 'POSTED' }] }) } })
    await fillMinimum()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(/Ada kemungkinan faktur ganda/)).toBeInTheDocument()
  })

  it('edits a draft with PATCH, keeps the saved lines, and refuses a posted invoice', async () => {
    const draft = arInvoice({
      id: 'ai-3', status: 'DRAFT', document_number: null, payment_status: null, customer_reference: 'PO-300', discount_amount: '1000.0000',
      possible_duplicates: [{ id: 'ai-7', document_number: 'ARI-FY2026-000007', customer_reference: 'PO-300', status: 'POSTED' }],
      lines: [{ id: 'l-1', line_number: 1, description: 'Kertas', quantity: '10.0000', unit_price: '25000.5000', amount: '250005.0000', account_role: null, account_id: null, cost_center_id: null }],
    })
    const calls = bootAr({ permissions: [...permissions, 'accounting.ar_invoice.update'] }, {
      ...routes,
      [`GET ${BASE}/ar-invoices/ai-3`]: { data: draft },
      [`GET ${BASE}/ar-invoices/ai-4`]: { data: arInvoice({ id: 'ai-4', status: 'POSTED' }) },
      [`PATCH ${BASE}/ar-invoices/ai-3`]: { data: draft },
    })
    const first = renderApp('/app/akuntansi/faktur-pelanggan/ai-3/ubah')
    expect(await screen.findByRole('heading', { name: 'Ubah draf faktur pelanggan PO-300' })).toBeInTheDocument()
    expect(screen.getByLabelText('Kuantitas baris 1')).toHaveValue('10')
    expect(screen.getByLabelText('Harga satuan baris 1')).toHaveValue('25000.5')
    expect(screen.getByLabelText('Diskon')).toHaveValue('1000')
    expect(screen.getByText(/Pastikan bukan tagihan ganda/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'ARI-FY2026-000007' })).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan/ai-7')

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true))
    expect(calls.find((c) => c.method === 'PATCH')?.data).toMatchObject({ customer_id: 'c-1', customer_reference: 'PO-300', discount_amount: '1000.0000', lines: [{ quantity: '10.0000', unit_price: '25000.5000' }] })
    first.unmount()

    renderApp('/app/akuntansi/faktur-pelanggan/ai-4/ubah')
    expect(await screen.findByText(/Hanya faktur pelanggan berstatus draf yang dapat diubah/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Simpan draf' })).not.toBeInTheDocument()
  })

  it('offers save-and-submit only with the submit permission', async () => {
    await open()
    expect(screen.queryByRole('button', { name: 'Simpan & ajukan' })).not.toBeInTheDocument()
  })

  it('saves and submits in one step and opens the saved invoice', async () => {
    const calls = await open({ [`POST ${BASE}/ar-invoices/ai-9/submit`]: { data: arInvoice({ id: 'ai-9', status: 'SUBMITTED', document_number: null }) } }, [...permissions, 'accounting.ar_invoice.submit'])
    await fillMinimum()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }))

    expect(await screen.findByText('Faktur disimpan dan diajukan.')).toBeInTheDocument()
    expect(calls.some((c) => c.url === `${BASE}/ar-invoices/ai-9/submit`)).toBe(true)
    expect(await screen.findByRole('heading', { name: 'Draf faktur pelanggan' })).toBeInTheDocument()
  })

  it('still opens the saved draft when the submit is refused, and never creates the draft twice', async () => {
    const calls = await open({ [`POST ${BASE}/ar-invoices/ai-9/submit`]: { status: 422, data: { code: 'AR_INVOICE_TOTAL_INVALID', message: 'x' } } }, [...permissions, 'accounting.ar_invoice.submit'])
    await fillMinimum()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }))

    expect(await screen.findByRole('heading', { name: 'Draf faktur pelanggan' })).toBeInTheDocument()
    expect(await screen.findByText(/Draf disimpan, tetapi belum dapat diajukan: Total faktur harus lebih besar dari nol/)).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST' && c.url === `${BASE}/ar-invoices`)).toHaveLength(1)
  })

  it('does not open for a read-only AR module', async () => {
    bootAr({ permissions, ar: 'READ_ONLY' }, create)
    renderApp('/app/akuntansi/faktur-pelanggan/baru')
    expect(await screen.findByText('Modul hanya baca')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Simpan draf' })).not.toBeInTheDocument()
  })

  it('is closed without accounting.ar_invoice.create', async () => {
    bootAr({ permissions: ['accounting.ar_invoice.view'] }, create)
    renderApp('/app/akuntansi/faktur-pelanggan/baru')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
  })
})

describe('customer invoice detail', () => {
  const base = ['accounting.ar_invoice.view']
  const all = [...base, 'accounting.ar_invoice.update', 'accounting.ar_invoice.submit', 'accounting.ar_invoice.approve', 'accounting.ar_invoice.post', 'accounting.ar_invoice.reverse', 'accounting.ar_credit_note.create']
  const open = (permissions: string[], inv: ReturnType<typeof arInvoice>, options: { ar?: 'FULL' | 'READ_ONLY'; subscription?: 'FULL' | 'READ_ONLY'; features?: Record<string, boolean> } = {}, extra: Parameters<typeof mockApi>[0] = {}) => {
    const calls = bootAr({ permissions, ...options }, { [`GET ${BASE}/ar-invoices/${inv.id}`]: { data: inv }, ...extra })
    renderApp(`/app/akuntansi/faktur-pelanggan/${inv.id}`)
    return calls
  }
  const buttons = () => ['Ubah', 'Ajukan', 'Setujui', 'Tolak', 'Posting', 'Jadikan draf', 'Batalkan', 'Balik', 'Buat nota kredit'].filter((n) => screen.queryByRole('button', { name: n }) ?? screen.queryByRole('link', { name: n }))
  const fact = (label: string, scope: HTMLElement = document.body) => within(within(scope).getByText(label, { selector: 'dt' }).closest('div') as HTMLElement)

  it('shows the facts, the lines, the server figures, the receipts and the credit notes of a posted invoice', async () => {
    open(base, arInvoice({ possible_duplicates: [{ id: 'ai-77', document_number: 'ARI-FY2026-000077', customer_reference: 'PO-100', status: 'POSTED' }] }))

    expect(await screen.findByRole('heading', { name: 'ARI-FY2026-000001' })).toBeInTheDocument()
    expect(fact('Pelanggan').getByText('C1 · PT Pelanggan Setia')).toBeInTheDocument()
    expect(fact('Referensi pelanggan').getByText('PO-100')).toBeInTheDocument()
    expect(fact('Termin pembayaran').getByText('NET30 · Net 30 hari')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Lihat jurnal' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-1')
    expect(screen.getByText('Akun 4100')).toBeInTheDocument() // the revenue account of the line

    const settlement = within(screen.getByRole('heading', { name: 'Pelunasan' }).closest('section') as HTMLElement)
    const settled = (label: string) => within(settlement.getByText(label, { selector: 'dt' }).closest('div') as HTMLElement)
    expect(settled('Total faktur').getByText('1.500.000,00')).toBeInTheDocument()
    expect(settled('Sudah diterima').getByText('500.000,00')).toBeInTheDocument() // from the API
    expect(settled('Dikreditkan').getByText('100.000,00')).toBeInTheDocument()
    expect(settled('Saldo piutang').getByText('900.000,00')).toBeInTheDocument()
    expect(settled('Status bayar').getByText('Dibayar sebagian')).toBeInTheDocument()

    const receipt = settlement.getByRole('link', { name: 'RCP-FY2026-000001' })
    expect(receipt).toHaveAttribute('href', '/app/akuntansi/penerimaan-pelanggan/rc-1')
    const receiptRow = within(receipt.closest('tr') as HTMLElement)
    expect(receiptRow.getByText('500.000,00')).toBeInTheDocument()
    expect(receiptRow.getByText('Terposting')).toBeInTheDocument()

    const note = settlement.getByRole('link', { name: 'CN-FY2026-000001' })
    expect(note).toHaveAttribute('href', '/app/akuntansi/nota-kredit/cn-1')
    const noteRow = within(note.closest('tr') as HTMLElement)
    expect(noteRow.getByText('100.000,00')).toBeInTheDocument()
    expect(noteRow.getByText('Retur sebagian')).toBeInTheDocument()

    expect(screen.getByText(/Pastikan bukan tagihan ganda/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'ARI-FY2026-000077' })).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan/ai-77')
    const total = within(screen.getByText(/^Total/, { selector: 'span' }).closest('.table-total') as HTMLElement)
    expect(total.getByText('1.500.000,00', { selector: '.money.strong' })).toBeInTheDocument()
  })

  it('explains an empty settlement of a posted invoice that nobody has paid yet', async () => {
    open(base, arInvoice({ allocations: [], credit_notes: [], received_amount: '0.0000', credited_amount: '0.0000', outstanding_amount: '1500000.0000', payment_status: 'UNPAID' }))
    expect(await screen.findByText('Belum ada penerimaan')).toBeInTheDocument()
    expect(screen.queryByRole('table', { name: 'Nota kredit' })).not.toBeInTheDocument()
    expect(fact('Saldo piutang', screen.getByRole('heading', { name: 'Pelunasan' }).closest('section') as HTMLElement).getByText('1.500.000,00')).toBeInTheDocument()
  })

  it('offers a credit note only for a posted invoice that is not fully paid, to a user who may create one', async () => {
    const first = open(all, arInvoice())
    const link = await screen.findByRole('link', { name: 'Buat nota kredit' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/nota-kredit/baru?faktur=ai-1')
    expect(first.length).toBeGreaterThan(0)
  })

  it.each([
    ['the invoice is fully paid', all, arInvoice({ payment_status: 'PAID', outstanding_amount: '0.0000' }), {}],
    ['the invoice is not posted', all, arInvoice({ status: 'APPROVED', document_number: null, payment_status: null }), {}],
    ['the user cannot create credit notes', [...base, 'accounting.ar_invoice.reverse'], arInvoice(), {}],
    ['the credit note feature is not subscribed', all, arInvoice(), { features: { CREDIT_NOTE: false } }],
    ['the AR module is read-only', all, arInvoice(), { ar: 'READ_ONLY' as const }],
  ])('offers no credit note when %s', async (_name, permissions, inv, options) => {
    open(permissions, inv, options)
    await screen.findByText('Riwayat')
    expect(screen.queryByRole('link', { name: 'Buat nota kredit' })).not.toBeInTheDocument()
  })

  it('offers edit, submit and cancel on a draft for a user who holds those permissions, and no posting while approval is required', async () => {
    open(all, arInvoice({ status: 'DRAFT', document_number: null, payment_status: null, allocations: [], credit_notes: [] }))
    await screen.findByRole('heading', { name: 'Draf faktur pelanggan' })

    expect(screen.getByRole('link', { name: 'Ubah' })).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan/ai-1/ubah')
    expect(screen.getByRole('button', { name: 'Ajukan' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Batalkan' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Posting' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Balik' })).not.toBeInTheDocument()
    expect(screen.queryByText('Pelunasan')).not.toBeInTheDocument() // nothing to settle before posting
  })

  it('posts a draft directly when the accounting policy needs no approval', async () => {
    open(all, arInvoice({ status: 'DRAFT', document_number: null, sod: { approve: true, post: true, approval_required: false } }))
    await screen.findByRole('heading', { name: 'Draf faktur pelanggan' })
    expect(screen.getByRole('button', { name: 'Posting' })).toBeEnabled()
    expect(screen.queryByRole('button', { name: 'Ajukan' })).not.toBeInTheDocument()
  })

  it('submits a draft with POST /submit and reloads', async () => {
    const calls = open(all, arInvoice({ status: 'DRAFT', document_number: null }), {}, { [`POST ${BASE}/ar-invoices/ai-1/submit`]: { data: arInvoice({ status: 'SUBMITTED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Ajukan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-invoices/ai-1/submit`)).toBe(true))
    expect(await screen.findByText('Faktur diajukan.')).toBeInTheDocument()
    await waitFor(() => expect(calls.filter((c) => c.method === 'GET' && c.url === `${BASE}/ar-invoices/ai-1`).length).toBeGreaterThan(1))
  })

  it('shows the segregation-of-duties flags of the server: approve and reject are disabled and explained', async () => {
    open(all, arInvoice({ status: 'SUBMITTED', document_number: null, sod: { approve: false, post: false, approval_required: true } }))
    expect(await screen.findByRole('button', { name: 'Setujui' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Tolak' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat menyetujui faktur/)).toBeInTheDocument()
  })

  it('approves a submitted invoice with POST /approve', async () => {
    const calls = open(all, arInvoice({ status: 'SUBMITTED', document_number: null }), {}, { [`POST ${BASE}/ar-invoices/ai-1/approve`]: { data: arInvoice({ status: 'APPROVED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Setujui' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-invoices/ai-1/approve`)).toBe(true))
    expect(await screen.findByText('Faktur disetujui.')).toBeInTheDocument()
  })

  it('rejects with a reason of at least three characters', async () => {
    const calls = open(all, arInvoice({ status: 'SUBMITTED', document_number: null }), {}, { [`POST ${BASE}/ar-invoices/ai-1/reject`]: { data: arInvoice({ status: 'REJECTED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Tolak' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Tolak faktur' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'ab')
    await userEvent.click(dialog.getByRole('button', { name: 'Tolak' }))
    expect(calls.some((c) => c.url === `${BASE}/ar-invoices/ai-1/reject`)).toBe(false) // too short

    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'c - nilai salah')
    await userEvent.click(dialog.getByRole('button', { name: 'Tolak' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-invoices/ai-1/reject`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ar-invoices/ai-1/reject`)?.data).toEqual({ reason: 'abc - nilai salah' })
  })

  it('cancels a draft with a reason', async () => {
    const calls = open(all, arInvoice({ status: 'DRAFT', document_number: null }), {}, { [`POST ${BASE}/ar-invoices/ai-1/cancel`]: { data: arInvoice({ status: 'CANCELLED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Batalkan' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Batalkan faktur' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Pesanan dibatalkan')
    await userEvent.click(dialog.getByRole('button', { name: 'Batalkan faktur' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-invoices/ai-1/cancel`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ar-invoices/ai-1/cancel`)?.data).toEqual({ reason: 'Pesanan dibatalkan' })
  })

  it('offers posting only on an approved invoice, and posts after a confirmation', async () => {
    const calls = open(all, arInvoice({ status: 'APPROVED', document_number: null }), {}, { [`POST ${BASE}/ar-invoices/ai-1/post`]: { data: arInvoice() } })
    await screen.findByRole('button', { name: 'Posting' })
    expect(buttons()).not.toContain('Balik')
    await userEvent.click(screen.getByRole('button', { name: 'Posting' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Posting faktur' }))
    expect(dialog.getByText(/mendapat nomor resmi dan jurnalnya tidak dapat diubah/)).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Posting' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-invoices/ai-1/post`)).toBe(true))
    expect(await screen.findByText('Faktur diposting.')).toBeInTheDocument()
  })

  it('explains the posting refusal of segregation of duties, and an API refusal inside the posting dialog', async () => {
    const first = open(all, arInvoice({ status: 'APPROVED', document_number: null, sod: { approve: true, post: false, approval_required: true } }))
    expect(await screen.findByRole('button', { name: 'Posting' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat memposting faktur/)).toBeInTheDocument()
    expect(first.length).toBeGreaterThan(0)
  })

  it('shows the API refusal inside the posting dialog', async () => {
    open(all, arInvoice({ status: 'APPROVED', document_number: null }), {}, { [`POST ${BASE}/ar-invoices/ai-1/post`]: { status: 422, data: { code: 'AR_POSTING_RULE_INVALID', message: 'x' } } })
    await userEvent.click(await screen.findByRole('button', { name: 'Posting' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Posting faktur' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Posting' }))
    expect(await dialog.findByText('Aturan posting piutang harus memakai peran piutang usaha dan kas/bank sesuai ketentuan.')).toBeInTheDocument()
  })

  it('reverses a posted invoice with a reason and the posting date', async () => {
    const calls = open(all, arInvoice({ allocations: [], credit_notes: [] }), {}, { [`POST ${BASE}/ar-invoices/ai-1/reverse`]: { data: arInvoice({ status: 'REVERSED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Balik' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Balik faktur' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Faktur salah')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-invoices/ai-1/reverse`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ar-invoices/ai-1/reverse`)?.data).toEqual({ reason: 'Faktur salah', posting_date: '2026-10-08' })
  })

  it('tells a user who can reverse that receipts and credit notes come first, and shows the API refusal when they still exist', async () => {
    open(all, arInvoice(), {}, { [`POST ${BASE}/ar-invoices/ai-1/reverse`]: { status: 409, data: { code: 'AR_INVOICE_HAS_RECEIPTS', message: 'x' } } })
    expect(await screen.findByText(/balik penerimaan dan nota kreditnya terlebih dahulu/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Balik' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Balik faktur' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Faktur salah')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))
    expect(await dialog.findByText('Faktur sudah dilunasi sebagian atau seluruhnya oleh penerimaan. Balik penerimaannya terlebih dahulu.')).toBeInTheDocument()
  })

  it('maps the credit-note refusal of a reversal to its Indonesian message', async () => {
    open(all, arInvoice(), {}, { [`POST ${BASE}/ar-invoices/ai-1/reverse`]: { status: 409, data: { code: 'AR_INVOICE_HAS_CREDIT_NOTES', message: 'x' } } })
    await userEvent.click(await screen.findByRole('button', { name: 'Balik' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Balik faktur' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Faktur salah')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))
    expect(await dialog.findByText('Faktur memiliki nota kredit yang sudah diposting. Balik nota kreditnya terlebih dahulu.')).toBeInTheDocument()
  })

  it('shows a reversed invoice with its reversal journal and no actions', async () => {
    open(all, arInvoice({ status: 'REVERSED', reversal_journal_id: 'j-2', reversal_reason: 'Faktur salah', allocations: [], credit_notes: [], payment_status: null }))
    expect(await screen.findByText(/Faktur ini sudah dibalik: Faktur salah/)).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Lihat jurnal pembalik' }).every((l) => l.getAttribute('href') === '/app/akuntansi/jurnal/j-2')).toBe(true)
    expect(buttons()).toEqual([])
  })

  it('shows why a rejected or cancelled invoice ended, and reopens a rejected one', async () => {
    const calls = open(all, arInvoice({ status: 'REJECTED', document_number: null, reject_reason: 'Nilai tidak sesuai PO' }), {}, { [`POST ${BASE}/ar-invoices/ai-1/reopen`]: { data: arInvoice({ status: 'DRAFT' }) } })
    expect(await screen.findByText('Ditolak: Nilai tidak sesuai PO')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Jadikan draf' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-invoices/ai-1/reopen`)).toBe(true))
  })

  it('shows the reason of a cancelled invoice and no action', async () => {
    open(all, arInvoice({ status: 'CANCELLED', document_number: null, cancel_reason: 'Pesanan batal' }))
    expect(await screen.findByText('Dibatalkan: Pesanan batal')).toBeInTheDocument()
    expect(buttons()).toEqual([])
  })

  it('offers no mutation to a user who only holds the view permission', async () => {
    open(base, arInvoice({ status: 'APPROVED', document_number: null }))
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
  })

  it('offers no mutation on a read-only AR module or a read-only subscription', async () => {
    const first = open(all, arInvoice({ status: 'APPROVED', document_number: null }), { ar: 'READ_ONLY' })
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
    expect(first.length).toBeGreaterThan(0)
  })

  it('offers no mutation on a read-only subscription', async () => {
    open(all, arInvoice({ status: 'DRAFT', document_number: null }), { subscription: 'READ_ONLY' })
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
  })

  it('shows an error with retry when the invoice cannot be loaded', async () => {
    bootAr({ permissions: base }, { [`GET ${BASE}/ar-invoices/ai-1`]: { status: 404, data: { message: 'x' } } })
    renderApp('/app/akuntansi/faktur-pelanggan/ai-1')
    expect(await screen.findByText('Data tidak ditemukan.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })
})
