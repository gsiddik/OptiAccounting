import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockApi, page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { BASE, bootAr, cashAccount, customer, mockDownload, noDimensions, openArInvoice, receipt, receiptRow } from './receivables/testkit'

afterEach(() => vi.restoreAllMocks())

const customers = page([customer(), customer({ id: 'c-2', code: 'C2', name: 'CV Lain' })])

describe('customer receipt list', () => {
  const view = ['accounting.ar_receipt.view']
  const routes = {
    [`GET ${BASE}/customer-receipts`]: { data: page([receiptRow(), receiptRow({ id: 'rc-2', document_number: null, status: 'DRAFT', reference: null, allocated_amount: '0.0000', amount: '250000.0000', receipt_method: 'CASH' })]) },
    [`GET ${BASE}/customers`]: { data: customers },
    [`GET ${BASE}/cash-bank-accounts`]: { data: page([cashAccount]) },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
  }
  const listCalls = (calls: ReturnType<typeof bootAr>) => calls.filter((c) => c.url === `${BASE}/customer-receipts`)

  it('renders the receipts the API returned with the allocated amount, and links each one', async () => {
    bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/penerimaan-pelanggan')

    const link = await screen.findByRole('link', { name: 'RCP-FY2026-000001' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/penerimaan-pelanggan/rc-1')
    expect(screen.getByRole('link', { name: 'Draf' })).toHaveAttribute('href', '/app/akuntansi/penerimaan-pelanggan/rc-2')
    const row = within(link.closest('tr') as HTMLElement)
    expect(row.getByText('PT Pelanggan Setia')).toBeInTheDocument()
    expect(row.getByText('BCA Operasional')).toBeInTheDocument()
    expect(row.getByText('Transfer bank')).toBeInTheDocument()
    expect(row.getAllByText('500.000,00')).toHaveLength(2) // amount and allocated amount, both from the API
    expect(row.getByText('Terposting')).toBeInTheDocument()
    const draft = within(screen.getByRole('link', { name: 'Draf' }).closest('tr') as HTMLElement)
    expect(draft.getByText('Tunai')).toBeInTheDocument()
    expect(draft.getByText('250.000,00')).toBeInTheDocument()
    expect(draft.getByText('0,00')).toBeInTheDocument()
  })

  it('sends every filter to the server (flags as 1, empty values dropped) and starts again from page 1', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/penerimaan-pelanggan')
    await screen.findByRole('link', { name: 'RCP-FY2026-000001' })

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-2')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.click(screen.getByLabelText('Buatan saya'))
    await userEvent.type(screen.getByLabelText('Terima dari'), '2026-09-01')
    await userEvent.type(screen.getByLabelText('Posting sampai'), '2026-09-30')
    await userEvent.type(screen.getByLabelText('Cari penerimaan'), 'trf')

    await waitFor(() => {
      expect(listCalls(calls).at(-1)?.params).toEqual({ page: 1, status: 'POSTED', customer_id: 'c-2', cash_bank_account_id: 'cb-1', mine: 1, receipt_from: '2026-09-01', posting_to: '2026-09-30', q: 'trf' })
    })
  })

  it('starts from the status a link carries (the accounting home links with ?status=APPROVED)', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/penerimaan-pelanggan?status=APPROVED')
    await screen.findByRole('link', { name: 'RCP-FY2026-000001' })
    expect(listCalls(calls)[0].params).toEqual({ page: 1, status: 'APPROVED' })
    expect(screen.getByLabelText('Status')).toHaveValue('APPROVED')
  })

  it('offers CSV export only with accounting.report.export and exports with the same filters', async () => {
    const create = mockDownload()
    bootAr({ permissions: view }, routes)
    const first = renderApp('/app/akuntansi/penerimaan-pelanggan')
    await screen.findByRole('link', { name: 'RCP-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    first.unmount()

    const calls = bootAr({ permissions: [...view, 'accounting.report.export'] }, { ...routes, [`GET ${BASE}/customer-receipts/export`]: { data: new Blob(['x']) } })
    renderApp('/app/akuntansi/penerimaan-pelanggan')
    await screen.findByRole('link', { name: 'RCP-FY2026-000001' })
    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/customer-receipts/export`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/customer-receipts/export`)?.params).toEqual({ status: 'POSTED' })
    expect(create).toHaveBeenCalled()
  })

  it('shows the new-receipt button only to users who can create, and never on a read-only module', async () => {
    bootAr({ permissions: [...view, 'accounting.ar_receipt.create'] }, routes)
    const first = renderApp('/app/akuntansi/penerimaan-pelanggan')
    expect(await screen.findByRole('button', { name: 'Penerimaan baru' })).toBeInTheDocument()
    first.unmount()

    bootAr({ permissions: view }, routes)
    const second = renderApp('/app/akuntansi/penerimaan-pelanggan')
    await screen.findByRole('link', { name: 'RCP-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Penerimaan baru' })).not.toBeInTheDocument()
    second.unmount()

    bootAr({ permissions: [...view, 'accounting.ar_receipt.create'], ar: 'READ_ONLY' }, routes)
    renderApp('/app/akuntansi/penerimaan-pelanggan')
    await screen.findByRole('link', { name: 'RCP-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Penerimaan baru' })).not.toBeInTheDocument()
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
  })

  it('shows an error with a retry, and an empty state', async () => {
    let fail = true
    bootAr({ permissions: view }, { ...routes, [`GET ${BASE}/customer-receipts`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: page([]) }) })
    renderApp('/app/akuntansi/penerimaan-pelanggan')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Tidak ada penerimaan')).toBeInTheDocument()
  })

  it('is closed without the permission or the feature', async () => {
    bootAr({ permissions: ['accounting.ar_invoice.view'] }, routes)
    const first = renderApp('/app/akuntansi/penerimaan-pelanggan')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
    first.unmount()

    bootAr({ permissions: view, features: { AR_RECEIPT: false } }, routes)
    renderApp('/app/akuntansi/penerimaan-pelanggan')
    expect(await screen.findByText('Fitur tidak tersedia')).toBeInTheDocument()
  })
})

describe('customer receipt editor', () => {
  const permissions = ['accounting.ar_receipt.view', 'accounting.ar_receipt.create']
  // Three open invoices of c-1. The server suggestion below deliberately skips the oldest one and splits 800.000 in an uneven way,
  // which nothing in the browser could have computed from the outstanding amounts.
  const invoices = [
    openArInvoice({ id: 'i-1', document_number: 'ARI-FY2026-000001', due_date: '2026-09-30', outstanding_amount: '300000.0000' }),
    openArInvoice({ id: 'i-2', document_number: 'ARI-FY2026-000002', customer_reference: 'PO-200', due_date: '2026-10-15', outstanding_amount: '700000.5000' }),
    openArInvoice({ id: 'i-3', document_number: 'ARI-FY2026-000003', customer_reference: 'PO-300', due_date: '2026-11-01', outstanding_amount: '450000.0000' }),
  ]
  const routes = {
    [`GET ${BASE}/customers`]: { data: customers },
    [`GET ${BASE}/cash-bank-accounts`]: { data: page([cashAccount]) },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
    [`GET ${BASE}/customers/c-1/open-invoices`]: { data: { data: invoices } },
    [`GET ${BASE}/customers/c-1/allocation-suggestion`]: { data: { data: [{ ar_invoice_id: 'i-2', amount: '500000.2500' }, { ar_invoice_id: 'i-3', amount: '299999.7500' }] } },
  }
  const saved = receipt({ id: 'rc-9', status: 'DRAFT', document_number: null })
  const create = { ...routes, [`POST ${BASE}/customer-receipts`]: { status: 201, data: saved }, [`GET ${BASE}/customer-receipts/rc-9`]: { data: saved } }
  const input = (document_number: string) => screen.getByLabelText(`Alokasi ${document_number}`)

  async function open(extra: Parameters<typeof mockApi>[0] = {}, perms = permissions) {
    const calls = bootAr({ permissions: perms }, { ...create, ...extra })
    renderApp('/app/akuntansi/penerimaan-pelanggan/baru')
    await screen.findByRole('heading', { name: 'Penerimaan pelanggan baru' })
    return calls
  }

  it('loads the open invoices of the chosen customer, and only then', async () => {
    const calls = await open()
    expect(calls.find((c) => c.url === `${BASE}/customers`)?.params).toEqual({ per_page: 200, status: 'ACTIVE' })
    expect(screen.getByText('Pilih pelanggan', { selector: 'strong' })).toBeInTheDocument()
    expect(calls.some((c) => c.url.endsWith('/open-invoices'))).toBe(false)
    expect(screen.getByRole('button', { name: 'Alokasikan otomatis' })).toBeDisabled()

    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    expect(await screen.findByLabelText('Alokasi ARI-FY2026-000001')).toHaveValue('')
    expect(input('ARI-FY2026-000002')).toBeInTheDocument()
    expect(input('ARI-FY2026-000003')).toBeInTheDocument()
    expect(calls.filter((c) => c.url === `${BASE}/customers/c-1/open-invoices`)).toHaveLength(1)
    expect(screen.getByRole('columnheader', { name: 'Referensi pelanggan' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Saldo piutang' })).toBeInTheDocument()
    const row = within(input('ARI-FY2026-000002').closest('tr') as HTMLElement)
    expect(row.getByText('700.000,50')).toBeInTheDocument() // outstanding as the server reported it
    expect(row.getByText('PO-200')).toBeInTheDocument()
  })

  it('fills the allocations from the server suggestion verbatim, never from its own arithmetic', async () => {
    const calls = await open()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await screen.findByLabelText('Alokasi ARI-FY2026-000001')

    // Without an amount there is nothing to propose: the user is told, and no request is made.
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    expect(await screen.findByText('Pilih pelanggan dan isi jumlah penerimaan terlebih dahulu.')).toBeInTheDocument()
    expect(calls.some((c) => c.url.endsWith('/allocation-suggestion'))).toBe(false)

    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '800000')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))

    await waitFor(() => expect(input('ARI-FY2026-000002')).toHaveValue('500000.2500'))
    expect(input('ARI-FY2026-000003')).toHaveValue('299999.7500')
    expect(input('ARI-FY2026-000001')).toHaveValue('') // the server did not propose the oldest invoice, so neither does the page
    const suggestion = calls.filter((c) => c.url === `${BASE}/customers/c-1/allocation-suggestion`)
    expect(suggestion).toHaveLength(1)
    expect(suggestion[0].params).toEqual({ amount: '800000.0000', posting_date: '2026-10-08' })
    const summary = screen.getByText('Teralokasi penuh.').closest('[role="status"]') as HTMLElement
    const figure = (label: string) => within(within(summary).getByText(label, { selector: 'span' })).getByText(/\d/, { selector: '.money' })
    expect(figure('Jumlah penerimaan')).toHaveTextContent('800.000,00')
    expect(figure('Teralokasi')).toHaveTextContent('800.000,00') // 500.000,25 + 299.999,75, the sum of the server's two rows
    expect(figure('Sisa')).toHaveTextContent('0,00')
  })

  it('keeps a receipt that the open invoices cannot absorb unallocated, and says how much is left', async () => {
    await open({ [`GET ${BASE}/customers/c-1/allocation-suggestion`]: { data: { data: [{ ar_invoice_id: 'i-1', amount: '300000.0000' }] } } }) // the server could place only 300.000
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await screen.findByLabelText('Alokasi ARI-FY2026-000001')
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '500000')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))

    await waitFor(() => expect(input('ARI-FY2026-000001')).toHaveValue('300000.0000'))
    expect(screen.getByText(/Belum dialokasikan 200\.000,00\. Draf tetap dapat disimpan, tetapi penerimaan harus teralokasi penuh sebelum diajukan atau diposting/)).toBeInTheDocument()
  })

  it('previews an under-allocation and an over-allocation in exact arithmetic while the user types', async () => {
    await open()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await screen.findByLabelText('Alokasi ARI-FY2026-000001')
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '800000')
    expect(screen.getByText(/Belum dialokasikan 800\.000,00/)).toBeInTheDocument()

    await userEvent.type(input('ARI-FY2026-000002'), '500000,25')
    expect(screen.getByText(/Belum dialokasikan 299\.999,75/)).toBeInTheDocument()

    await userEvent.type(input('ARI-FY2026-000003'), '300000,5')
    expect(screen.getByText('Alokasi melebihi jumlah penerimaan sebesar 0,75.')).toBeInTheDocument()

    await userEvent.clear(input('ARI-FY2026-000003'))
    await userEvent.type(input('ARI-FY2026-000003'), '299999,75')
    expect(screen.getByText('Teralokasi penuh.')).toBeInTheDocument()
  })

  it('warns when an allocation is larger than the invoice balance, without sending anything', async () => {
    const calls = await open()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await screen.findByLabelText('Alokasi ARI-FY2026-000001')
    await userEvent.type(input('ARI-FY2026-000001'), '300000,01')
    expect(within(input('ARI-FY2026-000001').closest('tr') as HTMLElement).getByText('Melebihi saldo faktur')).toBeInTheDocument()
    expect(input('ARI-FY2026-000001')).toHaveAttribute('aria-invalid', 'true')
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('sends the exact payload with the allocations of the suggestion as decimal strings', async () => {
    const calls = await open()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), ' 800000 ')
    await userEvent.type(screen.getByLabelText('Referensi'), 'TRF-9')
    await screen.findByLabelText('Alokasi ARI-FY2026-000001')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    await waitFor(() => expect(input('ARI-FY2026-000003')).toHaveValue('299999.7500'))

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/customer-receipts`)).toBe(true))
    const post = calls.find((c) => c.method === 'POST' && c.url === `${BASE}/customer-receipts`)
    expect(post?.data).toEqual({
      customer_id: 'c-1', cash_bank_account_id: 'cb-1', amount: '800000.0000', receipt_date: '2026-10-08', posting_date: '2026-10-08', receipt_method: 'TRANSFER',
      reference: 'TRF-9', description: null, branch_id: null, business_unit_id: null, cost_center_id: null,
      allocations: [{ ar_invoice_id: 'i-2', amount: '500000.2500' }, { ar_invoice_id: 'i-3', amount: '299999.7500' }],
    })
    expect(JSON.stringify(post?.data)).not.toMatch(/"(amount)":\d/)
    expect(await screen.findByRole('heading', { name: 'Draf penerimaan pelanggan' })).toBeInTheDocument()
  })

  it('sends a hand-typed allocation, drops empty and zero rows, and lets the server judge a short allocation', async () => {
    const calls = await open()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '500000')
    await screen.findByLabelText('Alokasi ARI-FY2026-000001')
    await userEvent.type(input('ARI-FY2026-000001'), '300000')
    await userEvent.type(input('ARI-FY2026-000002'), '0')
    await userEvent.type(input('ARI-FY2026-000003'), '100000,5')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/customer-receipts`)).toBe(true))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${BASE}/customer-receipts`)?.data).toMatchObject({
      amount: '500000.0000',
      allocations: [{ ar_invoice_id: 'i-1', amount: '300000.0000' }, { ar_invoice_id: 'i-3', amount: '100000.5000' }], // short by 99.999,50: a draft may be saved, the server blocks submit and post
    })
  })

  it('refuses obviously invalid input before any request', async () => {
    const calls = await open()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Pilih pelanggan.')).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Pilih akun kas/bank.')).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Isi jumlah penerimaan.')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '1.500.000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(/Jumlah penerimaan tidak valid/)).toBeInTheDocument()

    await userEvent.clear(screen.getByLabelText('Jumlah penerimaan'))
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '1000')
    await userEvent.type(await screen.findByLabelText('Alokasi ARI-FY2026-000001'), '1.000.000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(/Ada alokasi yang bukan angka yang valid/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('shows the validation messages and the allocation refusal of the server next to what they point at', async () => {
    await open({
      [`POST ${BASE}/customer-receipts`]: { status: 422, data: { message: 'The given data was invalid.', errors: { reference: ['Referensi terlalu panjang.'], 'allocations.0.amount': ['Alokasi pertama tidak valid.'] } } },
    })
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '800000')
    await screen.findByLabelText('Alokasi ARI-FY2026-000001')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    await waitFor(() => expect(input('ARI-FY2026-000002')).toHaveValue('500000.2500'))
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    expect(await screen.findByText('Referensi terlalu panjang.', { selector: '.error' })).toBeInTheDocument()
    expect(screen.getByLabelText('Referensi')).toHaveAttribute('aria-invalid', 'true')
    expect(within(input('ARI-FY2026-000002').closest('tr') as HTMLElement).getByText('Alokasi pertama tidak valid.')).toBeInTheDocument()
  })

  it('shows a domain refusal on the allocation row it points at, with the balance the server reported', async () => {
    await open({
      [`POST ${BASE}/customer-receipts`]: { status: 422, data: { code: 'AR_ALLOCATION_EXCEEDS_OUTSTANDING', message: 'x', details: { allocation: 2, document_number: 'ARI-FY2026-000003', outstanding: '299999.5000' } } },
    })
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '800000')
    await screen.findByLabelText('Alokasi ARI-FY2026-000001')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    await waitFor(() => expect(input('ARI-FY2026-000003')).toHaveValue('299999.7500'))
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    const second = within(input('ARI-FY2026-000003').closest('tr') as HTMLElement)
    expect(await second.findByText('Alokasi melebihi saldo piutang faktur. (faktur ARI-FY2026-000003, saldo piutang 299.999,50)')).toBeInTheDocument()
    expect(within(input('ARI-FY2026-000002').closest('tr') as HTMLElement).queryByText(/melebihi saldo piutang/)).not.toBeInTheDocument()
  })

  it.each([
    ['AR_ALLOCATION_DUPLICATE', 'Satu faktur hanya dapat dialokasikan sekali per penerimaan.'],
    ['AR_ALLOCATION_CUSTOMER_MISMATCH', 'Penerimaan hanya dapat melunasi faktur milik pelanggan yang sama.'],
    ['AR_RECEIPT_BEFORE_INVOICE', 'Penerimaan tidak dapat diposting sebelum tanggal faktur yang dilunasinya.'],
    ['AR_INVOICE_NOT_PAYABLE', 'Hanya faktur yang sudah diposting yang dapat dilunasi.'],
    ['AR_ALLOCATION_EXCEEDS_RECEIPT', 'Jumlah alokasi melebihi jumlah penerimaan.'],
    ['RECEIPT_AMOUNT_INVALID', 'Jumlah penerimaan harus lebih besar dari nol.'],
    ['CUSTOMER_INACTIVE', 'Pelanggan ini tidak aktif.'],
  ])('maps the refusal %s to its Indonesian message', async (code, message) => {
    await open({ [`POST ${BASE}/customer-receipts`]: { status: 422, data: { code, message: 'x' } } })
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '300000')
    await userEvent.type(await screen.findByLabelText('Alokasi ARI-FY2026-000001'), '300000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(message)).toBeInTheDocument()
  })

  it('shows a refusal of the suggestion without losing what the user typed', async () => {
    await open({ [`GET ${BASE}/customers/c-1/allocation-suggestion`]: { status: 422, data: { code: 'AR_ALLOCATION_INVALID', message: 'x' } } })
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '800000')
    await screen.findByLabelText('Alokasi ARI-FY2026-000001')
    await userEvent.type(input('ARI-FY2026-000001'), '100')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    expect(await screen.findByText('Setiap alokasi harus lebih besar dari nol.')).toBeInTheDocument()
    expect(input('ARI-FY2026-000001')).toHaveValue('100')
  })

  it('clears the allocations when the customer changes, since they belong to the previous customer', async () => {
    await open({ [`GET ${BASE}/customers/c-2/open-invoices`]: { data: { data: [] } } })
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.type(await screen.findByLabelText('Alokasi ARI-FY2026-000001'), '100')
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-2')
    expect(await screen.findByText('Tidak ada faktur terbuka')).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    expect(await screen.findByLabelText('Alokasi ARI-FY2026-000001')).toHaveValue('')
  })

  it('shows an error with a retry when the open invoices cannot be loaded', async () => {
    let fail = true
    await open({ [`GET ${BASE}/customers/c-1/open-invoices`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: { data: invoices } }) })
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByLabelText('Alokasi ARI-FY2026-000001')).toBeInTheDocument()
  })

  it('offers save-and-submit only with the submit permission and a writable cash and bank module', async () => {
    await open()
    expect(screen.queryByRole('button', { name: 'Simpan & ajukan' })).not.toBeInTheDocument()
  })

  it('saves and submits in one step and opens the saved receipt', async () => {
    const calls = await open({ [`POST ${BASE}/customer-receipts/rc-9/submit`]: { data: receipt({ id: 'rc-9', status: 'SUBMITTED' }) } }, [...permissions, 'accounting.ar_receipt.submit'])
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '300000')
    await userEvent.type(await screen.findByLabelText('Alokasi ARI-FY2026-000001'), '300000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }))

    expect(await screen.findByText('Penerimaan disimpan dan diajukan.')).toBeInTheDocument()
    expect(calls.some((c) => c.url === `${BASE}/customer-receipts/rc-9/submit`)).toBe(true)
  })

  it('still opens the saved draft when the submit is refused, and tells how far the receipt is from fully allocated', async () => {
    const calls = await open(
      { [`POST ${BASE}/customer-receipts/rc-9/submit`]: { status: 422, data: { code: 'RECEIPT_NOT_FULLY_ALLOCATED', message: 'x', details: { amount: '500000.0000', allocated: '300000.0000' } } } },
      [...permissions, 'accounting.ar_receipt.submit'],
    )
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah penerimaan'), '500000')
    await userEvent.type(await screen.findByLabelText('Alokasi ARI-FY2026-000001'), '300000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }))

    expect(await screen.findByRole('heading', { name: 'Draf penerimaan pelanggan' })).toBeInTheDocument()
    expect(await screen.findByText(/Draf disimpan, tetapi belum dapat diajukan: Penerimaan harus dialokasikan penuh ke faktur pelanggan sebelum disetujui atau diposting\. \(jumlah penerimaan 500\.000,00, teralokasi 300\.000,00\)/)).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST' && c.url === `${BASE}/customer-receipts`)).toHaveLength(1)
  })

  it('offers no save-and-submit while the cash and bank module is read-only (the API would refuse the submit), but still saves a draft', async () => {
    bootAr({ permissions: [...permissions, 'accounting.ar_receipt.submit'], cashBank: 'READ_ONLY' }, create)
    renderApp('/app/akuntansi/penerimaan-pelanggan/baru')
    await screen.findByRole('heading', { name: 'Penerimaan pelanggan baru' })
    expect(screen.getByRole('button', { name: 'Simpan draf' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Simpan & ajukan' })).not.toBeInTheDocument()
  })

  it('edits a draft with PATCH, keeping its allocations, and refuses a posted receipt', async () => {
    const draft = receipt({
      id: 'rc-3', status: 'DRAFT', document_number: null, reference: 'TRF-3', amount: '300000.0000', allocated_amount: '300000.0000', unallocated_amount: '0.0000',
      allocations: [{ id: 'al-3', customer_receipt_id: 'rc-3', ar_invoice_id: 'i-1', amount: '300000.0000', is_effective: false, effective_at: null, released_at: null, invoice: { id: 'i-1', document_number: 'ARI-FY2026-000001', customer_reference: 'PO-100', due_date: '2026-09-30', total_amount: '300000.0000', status: 'POSTED' } }],
    })
    const calls = bootAr({ permissions }, {
      ...routes,
      [`GET ${BASE}/customer-receipts/rc-3`]: { data: draft },
      [`GET ${BASE}/customer-receipts/rc-4`]: { data: receipt({ id: 'rc-4' }) },
      [`PATCH ${BASE}/customer-receipts/rc-3`]: { data: draft },
    })
    const first = renderApp('/app/akuntansi/penerimaan-pelanggan/rc-3/ubah')
    expect(await screen.findByRole('heading', { name: 'Ubah draf penerimaan TRF-3' })).toBeInTheDocument()
    expect(await screen.findByLabelText('Alokasi ARI-FY2026-000001')).toHaveValue('300000')
    expect(screen.getByLabelText('Jumlah penerimaan')).toHaveValue('300000')
    expect(screen.getByText('Teralokasi penuh.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true))
    expect(calls.find((c) => c.method === 'PATCH')?.data).toMatchObject({
      customer_id: 'c-1', cash_bank_account_id: 'cb-1', amount: '300000.0000', reference: 'TRF-3', allocations: [{ ar_invoice_id: 'i-1', amount: '300000.0000' }],
    })
    first.unmount()

    renderApp('/app/akuntansi/penerimaan-pelanggan/rc-4/ubah')
    expect(await screen.findByText(/Hanya penerimaan berstatus draf yang dapat diubah/)).toBeInTheDocument()
  })

  it('keeps an invoice the draft names but that is no longer open, so the user can clear it', async () => {
    const draft = receipt({
      id: 'rc-5', status: 'DRAFT', document_number: null, amount: '300000.0000', allocated_amount: '300000.0000',
      allocations: [{ id: 'al-5', customer_receipt_id: 'rc-5', ar_invoice_id: 'i-9', amount: '300000.0000', is_effective: false, effective_at: null, released_at: null, invoice: { id: 'i-9', document_number: 'ARI-FY2026-000009', customer_reference: 'PO-9', due_date: '2026-09-01', total_amount: '300000.0000', status: 'POSTED' } }],
    })
    bootAr({ permissions }, { ...routes, [`GET ${BASE}/customer-receipts/rc-5`]: { data: draft } })
    renderApp('/app/akuntansi/penerimaan-pelanggan/rc-5/ubah')
    const stale = await screen.findByLabelText('Alokasi ARI-FY2026-000009')
    const row = within(stale.closest('tr') as HTMLElement)
    expect(row.getByText('Tidak lagi terbuka')).toBeInTheDocument()
    expect(row.getByText('Faktur ini tidak lagi dapat dilunasi')).toBeInTheDocument()
    await userEvent.clear(stale)
    expect(row.queryByText('Faktur ini tidak lagi dapat dilunasi')).not.toBeInTheDocument()
  })

  it('does not open for a read-only AR module, and is closed without the create permission', async () => {
    bootAr({ permissions, ar: 'READ_ONLY' }, create)
    const first = renderApp('/app/akuntansi/penerimaan-pelanggan/baru')
    expect(await screen.findByText('Modul hanya baca')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Simpan draf' })).not.toBeInTheDocument()
    first.unmount()

    bootAr({ permissions: ['accounting.ar_receipt.view'] }, create)
    renderApp('/app/akuntansi/penerimaan-pelanggan/baru')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
  })
})

describe('customer receipt detail', () => {
  const base = ['accounting.ar_receipt.view']
  const all = [...base, 'accounting.ar_receipt.create', 'accounting.ar_receipt.submit', 'accounting.ar_receipt.approve', 'accounting.ar_receipt.post', 'accounting.ar_receipt.reverse']
  const open = (permissions: string[], rec: ReturnType<typeof receipt>, options: { ar?: 'FULL' | 'READ_ONLY'; cashBank?: 'FULL' | 'READ_ONLY'; subscription?: 'FULL' | 'READ_ONLY' } = {}, extra: Parameters<typeof mockApi>[0] = {}) => {
    const calls = bootAr({ permissions, ...options }, { [`GET ${BASE}/customer-receipts/${rec.id}`]: { data: rec }, ...extra })
    renderApp(`/app/akuntansi/penerimaan-pelanggan/${rec.id}`)
    return calls
  }
  const buttons = () => ['Ubah', 'Ajukan', 'Setujui', 'Tolak', 'Posting', 'Jadikan draf', 'Batalkan', 'Balik'].filter((n) => screen.queryByRole('button', { name: n }) ?? screen.queryByRole('link', { name: n }))
  const fact = (label: string) => within(screen.getByText(label, { selector: 'dt' }).closest('div') as HTMLElement)

  it('shows the facts, the allocations and the journal links of a posted receipt', async () => {
    open(base, receipt())

    expect(await screen.findByRole('heading', { name: 'RCP-FY2026-000001' })).toBeInTheDocument()
    expect(fact('Pelanggan').getByText('C1 · PT Pelanggan Setia')).toBeInTheDocument()
    expect(fact('Akun kas/bank').getByText('BCA · BCA Operasional')).toBeInTheDocument()
    expect(fact('Jumlah').getByText('500.000,00')).toBeInTheDocument()
    expect(fact('Teralokasi').getByText('500.000,00')).toBeInTheDocument()
    expect(fact('Belum dialokasikan').getByText('0,00')).toBeInTheDocument()
    expect(fact('Metode').getByText('Transfer bank')).toBeInTheDocument()
    expect(fact('Akun buku besar').getByText('1120 · Bank')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Lihat jurnal' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-9')
    const link = screen.getByRole('link', { name: 'ARI-FY2026-000001' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan/ai-1')
    const row = within(link.closest('tr') as HTMLElement)
    expect(row.getByText('PO-100')).toBeInTheDocument()
    expect(row.getByText('1.500.000,00')).toBeInTheDocument() // invoice total
    expect(row.getByText('500.000,00')).toBeInTheDocument() // allocated
    expect(row.getByText('Berlaku')).toBeInTheDocument()
    expect(screen.getByText('Riwayat')).toBeInTheDocument()
    expect(screen.queryByText(/belum teralokasi penuh/)).not.toBeInTheDocument()
  })

  it('explains that reversing releases the allocations, only to a user who can reverse', async () => {
    const first = open(all, receipt())
    expect(await screen.findByText(/melepas alokasinya, sehingga saldo piutang faktur yang dilunasinya kembali terbuka/)).toBeInTheDocument()
    expect(first.length).toBeGreaterThan(0)
    expect(buttons()).toEqual(['Balik'])
  })

  it('offers no reversal note and no button to a user who only holds the view permission', async () => {
    open(base, receipt())
    await screen.findByText('Riwayat')
    expect(screen.queryByText(/melepas alokasinya, sehingga/)).not.toBeInTheDocument()
    expect(buttons()).toEqual([])
  })

  it('shows the amount a draft still lacks to be fully allocated, as the server computed it', async () => {
    open(all, receipt({ status: 'DRAFT', document_number: null, allocated_amount: '300000.0000', unallocated_amount: '200000.0000', allocations: [] }))
    const banner = (await screen.findByText(/Penerimaan ini belum teralokasi penuh/)).closest('.banner') as HTMLElement
    expect(banner).toHaveTextContent('200.000,00')
    expect(fact('Belum dialokasikan').getByText('200.000,00')).toBeInTheDocument()
    expect(screen.getByText('Belum ada alokasi')).toBeInTheDocument()
  })

  it('offers edit, submit and cancel on a draft for a user who holds those permissions, and no posting while approval is required', async () => {
    open(all, receipt({ status: 'DRAFT', document_number: null }))
    await screen.findByRole('heading', { name: 'Draf penerimaan pelanggan' })

    expect(screen.getByRole('link', { name: 'Ubah' })).toHaveAttribute('href', '/app/akuntansi/penerimaan-pelanggan/rc-1/ubah')
    expect(screen.getByRole('button', { name: 'Ajukan' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Batalkan' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Posting' })).not.toBeInTheDocument()
  })

  it('needs the create permission to edit or cancel a draft, but only submit to submit it', async () => {
    open([...base, 'accounting.ar_receipt.submit'], receipt({ status: 'DRAFT', document_number: null }))
    await screen.findByRole('heading', { name: 'Draf penerimaan pelanggan' })
    expect(buttons()).toEqual(['Ajukan'])
  })

  it('submits a draft with POST /submit', async () => {
    const calls = open(all, receipt({ status: 'DRAFT', document_number: null }), {}, { [`POST ${BASE}/customer-receipts/rc-1/submit`]: { data: receipt({ status: 'SUBMITTED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Ajukan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/customer-receipts/rc-1/submit`)).toBe(true))
    expect(await screen.findByText('Penerimaan diajukan.')).toBeInTheDocument()
  })

  it('shows the amounts the server reports when a submit is refused because the receipt is not allocated in full', async () => {
    open(all, receipt({ status: 'DRAFT', document_number: null }), {}, {
      [`POST ${BASE}/customer-receipts/rc-1/submit`]: { status: 422, data: { code: 'RECEIPT_NOT_FULLY_ALLOCATED', message: 'x', details: { amount: '500000.0000', allocated: '300000.0000' } } },
    })
    await userEvent.click(await screen.findByRole('button', { name: 'Ajukan' }))
    expect(await screen.findByText('Penerimaan harus dialokasikan penuh ke faktur pelanggan sebelum disetujui atau diposting. (jumlah penerimaan 500.000,00, teralokasi 300.000,00)')).toBeInTheDocument()
  })

  it('shows the segregation-of-duties flags of the server: approve is disabled and explained', async () => {
    open(all, receipt({ status: 'SUBMITTED', document_number: null, sod: { approve: false, post: false, approval_required: true } }))
    expect(await screen.findByRole('button', { name: 'Setujui' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Tolak' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat menyetujui penerimaan/)).toBeInTheDocument()
  })

  it('approves a submitted receipt, and rejects one with a reason', async () => {
    const calls = open(all, receipt({ status: 'SUBMITTED', document_number: null }), {}, {
      [`POST ${BASE}/customer-receipts/rc-1/approve`]: { data: receipt({ status: 'APPROVED' }) },
      [`POST ${BASE}/customer-receipts/rc-1/reject`]: { data: receipt({ status: 'REJECTED' }) },
    })
    await userEvent.click(await screen.findByRole('button', { name: 'Setujui' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/customer-receipts/rc-1/approve`)).toBe(true))

    await userEvent.click(await screen.findByRole('button', { name: 'Tolak' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Tolak penerimaan' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Rekening salah')
    await userEvent.click(dialog.getByRole('button', { name: 'Tolak' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/customer-receipts/rc-1/reject`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/customer-receipts/rc-1/reject`)?.data).toEqual({ reason: 'Rekening salah' })
  })

  it('offers posting on an approved receipt, disabled with a note when segregation of duties forbids it', async () => {
    const first = open(all, receipt({ status: 'APPROVED', document_number: null }))
    expect(await screen.findByRole('button', { name: 'Posting' })).toBeEnabled()
    expect(first.length).toBeGreaterThan(0)
    expect(buttons()).not.toContain('Balik')
  })

  it('explains the posting refusal of segregation of duties', async () => {
    open(all, receipt({ status: 'APPROVED', document_number: null, sod: { approve: true, post: false, approval_required: true } }))
    expect(await screen.findByRole('button', { name: 'Posting' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat memposting penerimaan/)).toBeInTheDocument()
  })

  it('posts after a confirmation, and shows the API refusal inside the dialog with the invoice balance', async () => {
    const calls = open(all, receipt({ status: 'APPROVED', document_number: null }), {}, {
      [`POST ${BASE}/customer-receipts/rc-1/post`]: { status: 409, data: { code: 'AR_ALLOCATION_EXCEEDS_OUTSTANDING', message: 'x', details: { document_number: 'ARI-FY2026-000001', outstanding: '100000.0000', allocation: '500000.0000' } } },
    })
    await userEvent.click(await screen.findByRole('button', { name: 'Posting' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Posting penerimaan' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Posting' }))
    expect(await dialog.findByText('Alokasi melebihi saldo piutang faktur. (faktur ARI-FY2026-000001, saldo piutang 100.000,00)')).toBeInTheDocument()
    expect(calls.filter((c) => c.url === `${BASE}/customer-receipts/rc-1/post`)).toHaveLength(1)
  })

  it('reverses a posted receipt with a reason and the posting date', async () => {
    const calls = open(all, receipt(), {}, { [`POST ${BASE}/customer-receipts/rc-1/reverse`]: { data: receipt({ status: 'REVERSED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Balik' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Balik penerimaan' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Salah rekening')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/customer-receipts/rc-1/reverse`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/customer-receipts/rc-1/reverse`)?.data).toEqual({ reason: 'Salah rekening', posting_date: '2026-10-08' })
  })

  it('shows a reversed receipt with its released allocations and the reversal journal', async () => {
    open(all, receipt({
      status: 'REVERSED', reversal_journal_id: 'j-10', reversal_reason: 'Salah rekening', unallocated_amount: '500000.0000', allocated_amount: '0.0000',
      allocations: [{ id: 'al-1', customer_receipt_id: 'rc-1', ar_invoice_id: 'ai-1', amount: '500000.0000', is_effective: false, effective_at: '2026-09-20T03:00:00Z', released_at: '2026-10-01T03:00:00Z', invoice: { id: 'ai-1', document_number: 'ARI-FY2026-000001', customer_reference: 'PO-100', due_date: '2026-10-01', total_amount: '1500000.0000', status: 'POSTED' } }],
    }))
    expect(await screen.findByText(/Penerimaan ini sudah dibalik: Salah rekening/)).toBeInTheDocument()
    expect(screen.getByText('Dilepas')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Lihat jurnal pembalik' }).every((l) => l.getAttribute('href') === '/app/akuntansi/jurnal/j-10')).toBe(true)
    expect(buttons()).toEqual([])
  })

  it('shows why a rejected or cancelled receipt ended, and reopens a rejected one', async () => {
    const calls = open(all, receipt({ status: 'REJECTED', document_number: null, reject_reason: 'Rekening salah' }), {}, { [`POST ${BASE}/customer-receipts/rc-1/reopen`]: { data: receipt({ status: 'DRAFT' }) } })
    expect(await screen.findByText('Ditolak: Rekening salah')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Jadikan draf' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/customer-receipts/rc-1/reopen`)).toBe(true))
  })

  it('cancels a draft with a reason', async () => {
    const calls = open(all, receipt({ status: 'DRAFT', document_number: null }), {}, { [`POST ${BASE}/customer-receipts/rc-1/cancel`]: { data: receipt({ status: 'CANCELLED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Batalkan' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Batalkan penerimaan' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Dobel input')
    await userEvent.click(dialog.getByRole('button', { name: 'Batalkan penerimaan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/customer-receipts/rc-1/cancel`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/customer-receipts/rc-1/cancel`)?.data).toEqual({ reason: 'Dobel input' })
  })

  describe('while the cash and bank module is read-only', () => {
    it('keeps what the API still allows (edit, cancel) on a draft, but not the submit that draws on the cash account', async () => {
      open(all, receipt({ status: 'DRAFT', document_number: null }), { cashBank: 'READ_ONLY' })
      await screen.findByRole('heading', { name: 'Draf penerimaan pelanggan' })
      expect(buttons()).toEqual(['Ubah', 'Batalkan'])
    })

    it('lets a submitted receipt be rejected or cancelled but not approved', async () => {
      open(all, receipt({ status: 'SUBMITTED', document_number: null }), { cashBank: 'READ_ONLY' })
      await screen.findByText('Riwayat')
      expect(buttons()).toEqual(['Tolak', 'Batalkan'])
    })

    it('offers no posting of an approved receipt, but still its cancellation', async () => {
      open(all, receipt({ status: 'APPROVED', document_number: null }), { cashBank: 'READ_ONLY' })
      await screen.findByText('Riwayat')
      expect(buttons()).toEqual(['Batalkan'])
    })

    it('still lets a posted receipt be reversed (reversal needs the ledger, not the cash account)', async () => {
      open(all, receipt(), { cashBank: 'READ_ONLY' })
      await screen.findByText('Riwayat')
      expect(buttons()).toEqual(['Balik'])
    })
  })

  it('shows the module refusal of the API in words', async () => {
    open(all, receipt({ status: 'DRAFT', document_number: null }), {}, {
      [`POST ${BASE}/customer-receipts/rc-1/submit`]: { status: 403, data: { code: 'MODULE_NOT_AVAILABLE', message: 'x', details: { module: 'ACCOUNTING_CASH_BANK' } } },
    })
    await userEvent.click(await screen.findByRole('button', { name: 'Ajukan' }))
    expect(await screen.findByText('Dokumen ini membutuhkan modul Kas & bank yang aktif dan tidak dalam mode hanya baca.')).toBeInTheDocument()
  })

  it('offers no mutation on a read-only AR module or a read-only subscription', async () => {
    const first = open(all, receipt({ status: 'APPROVED', document_number: null }), { ar: 'READ_ONLY' })
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
    expect(first.length).toBeGreaterThan(0)
  })

  it('offers no mutation on a read-only subscription', async () => {
    open(all, receipt({ status: 'DRAFT', document_number: null }), { subscription: 'READ_ONLY' })
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
  })

  it('shows an error with a retry when the receipt cannot be loaded', async () => {
    bootAr({ permissions: base }, { [`GET ${BASE}/customer-receipts/rc-1`]: { status: 404, data: { message: 'x' } } })
    renderApp('/app/akuntansi/penerimaan-pelanggan/rc-1')
    expect(await screen.findByText('Data tidak ditemukan.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })
})
