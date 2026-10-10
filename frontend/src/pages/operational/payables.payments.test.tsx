import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockApi, page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { BASE, bootAp, noDimensions, openInvoice, payment, paymentRow, vendor } from './payables/testkit'

afterEach(() => vi.restoreAllMocks())

const cashAccount = {
  id: 'cb-1', code: 'BCA', name: 'BCA Operasional', kind: 'BANK', status: 'ACTIVE', currency: 'IDR', account_id: 'a-bank', gl_account: null,
  bank_name: 'BCA', account_holder: null, account_number_masked: null, branch_id: null, business_unit_id: null,
}

describe('vendor payment list', () => {
  const view = ['accounting.ap_payment.view']
  const routes = {
    [`GET ${BASE}/vendor-payments`]: { data: page([paymentRow(), paymentRow({ id: 'pay-2', document_number: null, status: 'DRAFT', reference: null, allocated_amount: '0.0000', amount: '250000.0000', payment_method: 'CASH' })]) },
    [`GET ${BASE}/vendors`]: { data: page([vendor(), vendor({ id: 'v-2', code: 'V2', name: 'CV Lain' })]) },
    [`GET ${BASE}/cash-bank-accounts`]: { data: page([cashAccount]) },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
  }

  it('renders the payments the API returned and links each one', async () => {
    bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/pembayaran-vendor')

    const link = await screen.findByRole('link', { name: 'PAY-FY2026-000001' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/pembayaran-vendor/pay-1')
    expect(screen.getByRole('link', { name: 'Draf' })).toHaveAttribute('href', '/app/akuntansi/pembayaran-vendor/pay-2')
    const row = within(link.closest('tr') as HTMLElement)
    expect(row.getByText('PT Sumber Makmur')).toBeInTheDocument()
    expect(row.getByText('BCA Operasional')).toBeInTheDocument()
    expect(row.getByText('Transfer bank')).toBeInTheDocument()
    expect(row.getAllByText('500.000,00')).toHaveLength(2) // amount and allocated amount, both from the API
    expect(row.getByText('Terposting')).toBeInTheDocument()
  })

  it('sends every filter to the server (flags as 1, empty values dropped) and starts again from page 1', async () => {
    const calls = bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/pembayaran-vendor')
    await screen.findByRole('link', { name: 'PAY-FY2026-000001' })

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-2')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.click(screen.getByLabelText('Buatan saya'))
    await userEvent.type(screen.getByLabelText('Bayar dari'), '2026-09-01')
    await userEvent.type(screen.getByLabelText('Posting sampai'), '2026-09-30')
    await userEvent.type(screen.getByLabelText('Cari pembayaran'), 'trf')

    await waitFor(() => {
      const last = calls.filter((c) => c.url === `${BASE}/vendor-payments`).at(-1)
      expect(last?.params).toEqual({ page: 1, status: 'POSTED', vendor_id: 'v-2', cash_bank_account_id: 'cb-1', mine: 1, payment_from: '2026-09-01', posting_to: '2026-09-30', q: 'trf' })
    })
  })

  it('offers CSV export only with accounting.report.export and exports with the same filters', async () => {
    const create = vi.fn(() => 'blob:csv')
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: vi.fn() })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    bootAp({ permissions: view }, routes)
    const first = renderApp('/app/akuntansi/pembayaran-vendor')
    await screen.findByRole('link', { name: 'PAY-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    first.unmount()

    const calls = bootAp({ permissions: [...view, 'accounting.report.export'] }, { ...routes, [`GET ${BASE}/vendor-payments/export`]: { data: new Blob(['x']) } })
    renderApp('/app/akuntansi/pembayaran-vendor')
    await screen.findByRole('link', { name: 'PAY-FY2026-000001' })
    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/vendor-payments/export`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/vendor-payments/export`)?.params).toEqual({ status: 'POSTED' })
    expect(create).toHaveBeenCalled()
  })

  it('shows the new-payment button only to users who can create, and never on a read-only module', async () => {
    bootAp({ permissions: [...view, 'accounting.ap_payment.create'] }, routes)
    const first = renderApp('/app/akuntansi/pembayaran-vendor')
    expect(await screen.findByRole('button', { name: 'Pembayaran baru' })).toBeInTheDocument()
    first.unmount()

    bootAp({ permissions: view }, routes)
    const second = renderApp('/app/akuntansi/pembayaran-vendor')
    await screen.findByRole('link', { name: 'PAY-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Pembayaran baru' })).not.toBeInTheDocument()
    second.unmount()

    bootAp({ permissions: [...view, 'accounting.ap_payment.create'], ap: 'READ_ONLY' }, routes)
    renderApp('/app/akuntansi/pembayaran-vendor')
    await screen.findByRole('link', { name: 'PAY-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Pembayaran baru' })).not.toBeInTheDocument()
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
  })

  it('shows an error with a retry, and an empty state', async () => {
    let fail = true
    bootAp({ permissions: view }, { ...routes, [`GET ${BASE}/vendor-payments`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: page([]) }) })
    renderApp('/app/akuntansi/pembayaran-vendor')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Tidak ada pembayaran')).toBeInTheDocument()
  })
})

describe('vendor payment editor', () => {
  const permissions = ['accounting.ap_payment.view', 'accounting.ap_payment.create']
  // Three open invoices of v-1. The server suggestion below deliberately skips the oldest one and splits 800.000 in an uneven way,
  // which nothing in the browser could have computed from the outstanding amounts.
  const invoices = [
    openInvoice({ id: 'i-1', document_number: 'API-FY2026-000001', due_date: '2026-09-30', outstanding_amount: '300000.0000' }),
    openInvoice({ id: 'i-2', document_number: 'API-FY2026-000002', vendor_invoice_number: 'FP-200', due_date: '2026-10-15', outstanding_amount: '700000.5000' }),
    openInvoice({ id: 'i-3', document_number: 'API-FY2026-000003', vendor_invoice_number: 'FP-300', due_date: '2026-11-01', outstanding_amount: '450000.0000' }),
  ]
  const routes = {
    [`GET ${BASE}/vendors`]: { data: page([vendor(), vendor({ id: 'v-2', code: 'V2', name: 'CV Lain' })]) },
    [`GET ${BASE}/cash-bank-accounts`]: { data: page([cashAccount]) },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
    [`GET ${BASE}/vendors/v-1/open-invoices`]: { data: { data: invoices } },
    [`GET ${BASE}/vendors/v-1/allocation-suggestion`]: { data: { data: [{ ap_invoice_id: 'i-2', amount: '500000.2500' }, { ap_invoice_id: 'i-3', amount: '299999.7500' }] } },
  }
  const saved = payment({ id: 'pay-9', status: 'DRAFT', document_number: null })
  const create = { ...routes, [`POST ${BASE}/vendor-payments`]: { status: 201, data: saved }, [`GET ${BASE}/vendor-payments/pay-9`]: { data: saved } }
  const input = (document_number: string) => screen.getByLabelText(`Alokasi ${document_number}`)

  async function open(extra: Parameters<typeof mockApi>[0] = {}, perms = permissions) {
    const calls = bootAp({ permissions: perms }, { ...create, ...extra })
    renderApp('/app/akuntansi/pembayaran-vendor/baru')
    await screen.findByRole('heading', { name: 'Pembayaran vendor baru' })
    return calls
  }

  it('loads the open invoices of the chosen vendor, and only then', async () => {
    const calls = await open()
    expect(screen.getByText('Pilih vendor', { selector: 'h3, h2, strong, p, div' })).toBeInTheDocument()
    expect(calls.some((c) => c.url.endsWith('/open-invoices'))).toBe(false)
    expect(screen.getByRole('button', { name: 'Alokasikan otomatis' })).toBeDisabled()

    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    expect(await screen.findByLabelText('Alokasi API-FY2026-000001')).toHaveValue('')
    expect(input('API-FY2026-000002')).toBeInTheDocument()
    expect(input('API-FY2026-000003')).toBeInTheDocument()
    expect(calls.filter((c) => c.url === `${BASE}/vendors/v-1/open-invoices`)).toHaveLength(1)
    const row = within(input('API-FY2026-000002').closest('tr') as HTMLElement)
    expect(row.getByText('700.000,50')).toBeInTheDocument() // outstanding as the server reported it
    expect(row.getByText('FP-200')).toBeInTheDocument()
  })

  it('fills the allocations from the server suggestion verbatim, never from its own arithmetic', async () => {
    const calls = await open()
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await screen.findByLabelText('Alokasi API-FY2026-000001')

    // Without a vendor amount there is nothing to propose: the user is told, and no request is made.
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    expect(await screen.findByText('Pilih vendor dan isi jumlah pembayaran terlebih dahulu.')).toBeInTheDocument()
    expect(calls.some((c) => c.url.endsWith('/allocation-suggestion'))).toBe(false)

    await userEvent.type(screen.getByLabelText('Jumlah pembayaran'), '800000')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))

    await waitFor(() => expect(input('API-FY2026-000002')).toHaveValue('500000.2500'))
    expect(input('API-FY2026-000003')).toHaveValue('299999.7500')
    expect(input('API-FY2026-000001')).toHaveValue('') // the server did not propose the oldest invoice, so neither does the page
    const suggestion = calls.filter((c) => c.url === `${BASE}/vendors/v-1/allocation-suggestion`)
    expect(suggestion).toHaveLength(1)
    expect(suggestion[0].params).toEqual({ amount: '800000.0000', posting_date: '2026-10-08' })
    const summary = screen.getByText('Teralokasi penuh.').closest('[role="status"]') as HTMLElement
    const figure = (label: string) => within(within(summary).getByText(label, { selector: 'span' })).getByText(/\d/, { selector: '.money' })
    expect(figure('Jumlah pembayaran')).toHaveTextContent('800.000,00')
    expect(figure('Teralokasi')).toHaveTextContent('800.000,00') // 500.000,25 + 299.999,75, the sum of the server's two rows
    expect(figure('Sisa')).toHaveTextContent('0,00')
  })

  it('previews an under-allocation and an over-allocation in exact arithmetic while the user types', async () => {
    await open()
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await screen.findByLabelText('Alokasi API-FY2026-000001')
    await userEvent.type(screen.getByLabelText('Jumlah pembayaran'), '800000')
    expect(screen.getByText(/Belum dialokasikan 800\.000,00/)).toBeInTheDocument()

    await userEvent.type(input('API-FY2026-000002'), '500000,25')
    expect(screen.getByText(/Belum dialokasikan 299\.999,75/)).toBeInTheDocument()

    await userEvent.type(input('API-FY2026-000003'), '300000,5')
    expect(screen.getByText('Alokasi melebihi jumlah pembayaran sebesar 0,75.')).toBeInTheDocument()

    await userEvent.clear(input('API-FY2026-000003'))
    await userEvent.type(input('API-FY2026-000003'), '299999,75')
    expect(screen.getByText('Teralokasi penuh.')).toBeInTheDocument()
  })

  it('warns when an allocation is larger than the invoice balance, without sending anything', async () => {
    const calls = await open()
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await screen.findByLabelText('Alokasi API-FY2026-000001')
    await userEvent.type(input('API-FY2026-000001'), '300000,01')
    expect(within(input('API-FY2026-000001').closest('tr') as HTMLElement).getByText('Melebihi saldo faktur')).toBeInTheDocument()
    expect(input('API-FY2026-000001')).toHaveAttribute('aria-invalid', 'true')
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('sends the exact payload with the allocations of the suggestion as decimal strings', async () => {
    const calls = await open()
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah pembayaran'), ' 800000 ')
    await userEvent.type(screen.getByLabelText('Referensi'), 'TRF-9')
    await screen.findByLabelText('Alokasi API-FY2026-000001')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    await waitFor(() => expect(input('API-FY2026-000003')).toHaveValue('299999.7500'))

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/vendor-payments`)).toBe(true))
    const post = calls.find((c) => c.method === 'POST' && c.url === `${BASE}/vendor-payments`)
    expect(post?.data).toEqual({
      vendor_id: 'v-1', cash_bank_account_id: 'cb-1', amount: '800000.0000', payment_date: '2026-10-08', posting_date: '2026-10-08', payment_method: 'TRANSFER',
      reference: 'TRF-9', description: null, branch_id: null, business_unit_id: null, cost_center_id: null,
      allocations: [{ ap_invoice_id: 'i-2', amount: '500000.2500' }, { ap_invoice_id: 'i-3', amount: '299999.7500' }],
    })
    expect(JSON.stringify(post?.data)).not.toMatch(/"(amount)":\d/)
    expect(await screen.findByRole('heading', { name: 'Draf pembayaran vendor' })).toBeInTheDocument()
  })

  it('sends a hand-typed allocation, drops empty and zero rows, and lets the server judge a short allocation', async () => {
    const calls = await open()
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah pembayaran'), '500000')
    await screen.findByLabelText('Alokasi API-FY2026-000001')
    await userEvent.type(input('API-FY2026-000001'), '300000')
    await userEvent.type(input('API-FY2026-000002'), '0')
    await userEvent.type(input('API-FY2026-000003'), '100000,5')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/vendor-payments`)).toBe(true))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${BASE}/vendor-payments`)?.data).toMatchObject({
      amount: '500000.0000',
      allocations: [{ ap_invoice_id: 'i-1', amount: '300000.0000' }, { ap_invoice_id: 'i-3', amount: '100000.5000' }], // short by 99.999,50: a draft may be saved, the server blocks submit and post
    })
  })

  it('refuses obviously invalid input before any request', async () => {
    const calls = await open()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Pilih vendor.')).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Pilih akun kas/bank.')).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah pembayaran'), '1.500.000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(/Jumlah pembayaran tidak valid/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('shows the validation messages and the allocation refusal of the server next to what they point at', async () => {
    await open({
      [`POST ${BASE}/vendor-payments`]: { status: 422, data: { message: 'The given data was invalid.', errors: { reference: ['Referensi terlalu panjang.'], 'allocations.0.amount': ['Alokasi pertama tidak valid.'] } } },
    })
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah pembayaran'), '800000')
    await screen.findByLabelText('Alokasi API-FY2026-000001')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    await waitFor(() => expect(input('API-FY2026-000002')).toHaveValue('500000.2500'))
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    expect(await screen.findByText('Referensi terlalu panjang.', { selector: '.error' })).toBeInTheDocument()
    expect(screen.getByLabelText('Referensi')).toHaveAttribute('aria-invalid', 'true')
    expect(within(input('API-FY2026-000002').closest('tr') as HTMLElement).getByText('Alokasi pertama tidak valid.')).toBeInTheDocument()
  })

  it('shows a domain refusal on the allocation row it points at', async () => {
    await open({
      [`POST ${BASE}/vendor-payments`]: { status: 422, data: { code: 'AP_ALLOCATION_EXCEEDS_OUTSTANDING', message: 'x', details: { allocation: 2 } } },
    })
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah pembayaran'), '800000')
    await screen.findByLabelText('Alokasi API-FY2026-000001')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    await waitFor(() => expect(input('API-FY2026-000003')).toHaveValue('299999.7500'))
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    const second = within(input('API-FY2026-000003').closest('tr') as HTMLElement)
    expect(await second.findByText('Alokasi melebihi saldo faktur yang masih terutang.')).toBeInTheDocument()
    expect(within(input('API-FY2026-000002').closest('tr') as HTMLElement).queryByText(/melebihi saldo/)).not.toBeInTheDocument()
  })

  it('shows a refusal of the suggestion without losing what the user typed', async () => {
    await open({ [`GET ${BASE}/vendors/v-1/allocation-suggestion`]: { status: 422, data: { code: 'AP_ALLOCATION_INVALID', message: 'x' } } })
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.type(screen.getByLabelText('Jumlah pembayaran'), '800000')
    await screen.findByLabelText('Alokasi API-FY2026-000001')
    await userEvent.type(input('API-FY2026-000001'), '100')
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    expect(await screen.findByText('Setiap alokasi harus lebih besar dari nol.')).toBeInTheDocument()
    expect(input('API-FY2026-000001')).toHaveValue('100')
  })

  it('clears the allocations when the vendor changes, since they belong to the previous vendor', async () => {
    await open({ [`GET ${BASE}/vendors/v-2/open-invoices`]: { data: { data: [] } } })
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.type(await screen.findByLabelText('Alokasi API-FY2026-000001'), '100')
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-2')
    expect(await screen.findByText('Tidak ada faktur terbuka')).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    expect(await screen.findByLabelText('Alokasi API-FY2026-000001')).toHaveValue('')
  })

  it('offers save-and-submit only with the submit permission', async () => {
    await open()
    expect(screen.queryByRole('button', { name: 'Simpan & ajukan' })).not.toBeInTheDocument()
  })

  it('saves and submits in one step, and still opens the saved draft when the submit is refused', async () => {
    const calls = await open({ [`POST ${BASE}/vendor-payments/pay-9/submit`]: { status: 422, data: { code: 'AP_ALLOCATION_EXCEEDS_PAYMENT', message: 'x' } } }, [...permissions, 'accounting.ap_payment.submit'])
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await userEvent.type(screen.getByLabelText('Jumlah pembayaran'), '300000')
    await userEvent.type(await screen.findByLabelText('Alokasi API-FY2026-000001'), '300000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }))

    expect(await screen.findByRole('heading', { name: 'Draf pembayaran vendor' })).toBeInTheDocument()
    expect(await screen.findByText(/Draf disimpan, tetapi belum dapat diajukan: Jumlah alokasi melebihi jumlah pembayaran/)).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST' && c.url === `${BASE}/vendor-payments`)).toHaveLength(1)
    expect(calls.some((c) => c.url === `${BASE}/vendor-payments/pay-9/submit`)).toBe(true)
  })

  it('edits a draft with PATCH, keeping its allocations, and refuses a posted payment', async () => {
    const draft = payment({
      id: 'pay-3', status: 'DRAFT', document_number: null, reference: 'TRF-3', amount: '300000.0000', allocated_amount: '300000.0000',
      allocations: [{ id: 'al-3', vendor_payment_id: 'pay-3', ap_invoice_id: 'i-1', amount: '300000.0000', is_effective: false, effective_at: null, released_at: null, invoice: { id: 'i-1', document_number: 'API-FY2026-000001', vendor_invoice_number: 'FP-100', due_date: '2026-09-30', total_amount: '300000.0000', status: 'POSTED' } }],
    })
    const calls = bootAp({ permissions: [...permissions, 'accounting.ap_payment.create'] }, {
      ...routes,
      [`GET ${BASE}/vendor-payments/pay-3`]: { data: draft },
      [`GET ${BASE}/vendor-payments/pay-4`]: { data: payment({ id: 'pay-4' }) },
      [`PATCH ${BASE}/vendor-payments/pay-3`]: { data: draft },
    })
    const first = renderApp('/app/akuntansi/pembayaran-vendor/pay-3/ubah')
    expect(await screen.findByRole('heading', { name: 'Ubah draf pembayaran TRF-3' })).toBeInTheDocument()
    expect(await screen.findByLabelText('Alokasi API-FY2026-000001')).toHaveValue('300000')
    expect(screen.getByLabelText('Jumlah pembayaran')).toHaveValue('300000')

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true))
    expect(calls.find((c) => c.method === 'PATCH')?.data).toMatchObject({
      vendor_id: 'v-1', cash_bank_account_id: 'cb-1', amount: '300000.0000', reference: 'TRF-3', allocations: [{ ap_invoice_id: 'i-1', amount: '300000.0000' }],
    })
    first.unmount()

    renderApp('/app/akuntansi/pembayaran-vendor/pay-4/ubah')
    expect(await screen.findByText(/Hanya pembayaran berstatus draf yang dapat diubah/)).toBeInTheDocument()
  })

  it('does not open for a read-only AP module', async () => {
    bootAp({ permissions, ap: 'READ_ONLY' }, create)
    renderApp('/app/akuntansi/pembayaran-vendor/baru')
    expect(await screen.findByText('Modul hanya baca')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Simpan draf' })).not.toBeInTheDocument()
  })

  it('shows an error with a retry when the open invoices cannot be loaded', async () => {
    let fail = true
    await open({ [`GET ${BASE}/vendors/v-1/open-invoices`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: { data: invoices } }) })
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByLabelText('Alokasi API-FY2026-000001')).toBeInTheDocument()
  })
})

describe('vendor payment detail', () => {
  const base = ['accounting.ap_payment.view']
  const all = [...base, 'accounting.ap_payment.create', 'accounting.ap_payment.submit', 'accounting.ap_payment.approve', 'accounting.ap_payment.post', 'accounting.ap_payment.reverse']
  const open = (permissions: string[], pay: ReturnType<typeof payment>, options: { ap?: 'FULL' | 'READ_ONLY'; subscription?: 'FULL' | 'READ_ONLY' } = {}, extra: Parameters<typeof mockApi>[0] = {}) => {
    const calls = bootAp({ permissions, ...options }, { [`GET ${BASE}/vendor-payments/${pay.id}`]: { data: pay }, ...extra })
    renderApp(`/app/akuntansi/pembayaran-vendor/${pay.id}`)
    return calls
  }
  const buttons = () => ['Ubah', 'Ajukan', 'Setujui', 'Tolak', 'Posting', 'Jadikan draf', 'Batalkan', 'Balik'].filter((n) => screen.queryByRole('button', { name: n }) ?? screen.queryByRole('link', { name: n }))
  const fact = (label: string) => within(screen.getByText(label, { selector: 'dt' }).closest('div') as HTMLElement)

  it('shows the facts, the allocations and the journal links of a posted payment', async () => {
    open(base, payment())

    expect(await screen.findByRole('heading', { name: 'PAY-FY2026-000001' })).toBeInTheDocument()
    expect(fact('Vendor').getByText('V1 · PT Sumber Makmur')).toBeInTheDocument()
    expect(fact('Akun kas/bank').getByText('BCA · BCA Operasional')).toBeInTheDocument()
    expect(fact('Jumlah').getByText('500.000,00')).toBeInTheDocument()
    expect(fact('Teralokasi').getByText('500.000,00')).toBeInTheDocument()
    expect(fact('Belum dialokasikan').getByText('0,00')).toBeInTheDocument()
    expect(fact('Metode').getByText('Transfer bank')).toBeInTheDocument()
    expect(fact('Akun buku besar').getByText('1120 · Bank')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Lihat jurnal' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-9')
    const link = screen.getByRole('link', { name: 'API-FY2026-000001' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/faktur-vendor/inv-1')
    const row = within(link.closest('tr') as HTMLElement)
    expect(row.getByText('1.500.000,00')).toBeInTheDocument() // invoice total
    expect(row.getByText('500.000,00')).toBeInTheDocument() // allocated
    expect(row.getByText('Berlaku')).toBeInTheDocument()
    expect(screen.getByText('Riwayat')).toBeInTheDocument()
  })

  it('explains that reversing releases the allocations, only to a user who can reverse', async () => {
    const first = open(all, payment())
    expect(await screen.findByText(/melepas alokasinya, sehingga saldo faktur yang dilunasinya kembali terutang/)).toBeInTheDocument()
    expect(first.length).toBeGreaterThan(0)
    expect(buttons()).toEqual(['Balik'])
  })

  it('offers no reversal note and no button to a user who only holds the view permission', async () => {
    open(base, payment())
    await screen.findByText('Riwayat')
    expect(screen.queryByText(/melepas alokasinya, sehingga/)).not.toBeInTheDocument()
    expect(buttons()).toEqual([])
  })

  it('offers edit, submit and cancel on a draft for a user who holds those permissions, and no posting while approval is required', async () => {
    open(all, payment({ status: 'DRAFT', document_number: null, allocations: [] }))
    await screen.findByRole('heading', { name: 'Draf pembayaran vendor' })

    expect(screen.getByRole('link', { name: 'Ubah' })).toHaveAttribute('href', '/app/akuntansi/pembayaran-vendor/pay-1/ubah')
    expect(screen.getByRole('button', { name: 'Ajukan' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Batalkan' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Posting' })).not.toBeInTheDocument()
    expect(screen.getByText('Belum ada alokasi')).toBeInTheDocument()
  })

  it('needs the create permission to edit or cancel a draft, but only submit to submit it', async () => {
    open([...base, 'accounting.ap_payment.submit'], payment({ status: 'DRAFT', document_number: null }))
    await screen.findByRole('heading', { name: 'Draf pembayaran vendor' })
    expect(buttons()).toEqual(['Ajukan'])
  })

  it('submits a draft with POST /submit', async () => {
    const calls = open(all, payment({ status: 'DRAFT', document_number: null }), {}, { [`POST ${BASE}/vendor-payments/pay-1/submit`]: { data: payment({ status: 'SUBMITTED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Ajukan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/vendor-payments/pay-1/submit`)).toBe(true))
  })

  it('shows the segregation-of-duties flags of the server: approve is disabled and explained', async () => {
    open(all, payment({ status: 'SUBMITTED', document_number: null, sod: { approve: false, post: false, approval_required: true } }))
    expect(await screen.findByRole('button', { name: 'Setujui' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Tolak' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat menyetujui pembayaran/)).toBeInTheDocument()
  })

  it('offers posting on an approved payment, disabled with a note when segregation of duties forbids it', async () => {
    const first = open(all, payment({ status: 'APPROVED', document_number: null }))
    expect(await screen.findByRole('button', { name: 'Posting' })).toBeEnabled()
    expect(first.length).toBeGreaterThan(0)
    expect(buttons()).not.toContain('Balik')
  })

  it('explains the posting refusal of segregation of duties', async () => {
    open(all, payment({ status: 'APPROVED', document_number: null, sod: { approve: true, post: false, approval_required: true } }))
    expect(await screen.findByRole('button', { name: 'Posting' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat memposting pembayaran/)).toBeInTheDocument()
  })

  it('reverses a posted payment with a reason and the posting date', async () => {
    const calls = open(all, payment(), {}, { [`POST ${BASE}/vendor-payments/pay-1/reverse`]: { data: payment({ status: 'REVERSED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Balik' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Balik pembayaran' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Salah rekening')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/vendor-payments/pay-1/reverse`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/vendor-payments/pay-1/reverse`)?.data).toEqual({ reason: 'Salah rekening', posting_date: '2026-10-08' })
  })

  it('shows a reversed payment with its released allocations and the reversal journal', async () => {
    open(all, payment({
      status: 'REVERSED', reversal_journal_id: 'j-10', reversal_reason: 'Salah rekening', unallocated_amount: '500000.0000', allocated_amount: '0.0000',
      allocations: [{ id: 'al-1', vendor_payment_id: 'pay-1', ap_invoice_id: 'inv-1', amount: '500000.0000', is_effective: false, effective_at: '2026-09-20T03:00:00Z', released_at: '2026-10-01T03:00:00Z', invoice: { id: 'inv-1', document_number: 'API-FY2026-000001', vendor_invoice_number: 'FP-100', due_date: '2026-10-01', total_amount: '1500000.0000', status: 'POSTED' } }],
    }))
    expect(await screen.findByText(/Pembayaran ini sudah dibalik: Salah rekening/)).toBeInTheDocument()
    expect(screen.getByText('Dilepas')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Lihat jurnal pembalik' }).every((l) => l.getAttribute('href') === '/app/akuntansi/jurnal/j-10')).toBe(true)
    expect(buttons()).toEqual([])
  })

  it('shows why a rejected or cancelled payment ended, and reopens a rejected one', async () => {
    const calls = open(all, payment({ status: 'REJECTED', document_number: null, reject_reason: 'Rekening salah' }), {}, { [`POST ${BASE}/vendor-payments/pay-1/reopen`]: { data: payment({ status: 'DRAFT' }) } })
    expect(await screen.findByText('Ditolak: Rekening salah')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Jadikan draf' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/vendor-payments/pay-1/reopen`)).toBe(true))
  })

  it('offers no mutation on a read-only AP module or a read-only subscription', async () => {
    const first = open(all, payment({ status: 'APPROVED', document_number: null }), { ap: 'READ_ONLY' })
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
    expect(first.length).toBeGreaterThan(0)
  })

  it('offers no mutation on a read-only subscription', async () => {
    open(all, payment({ status: 'DRAFT', document_number: null }), { subscription: 'READ_ONLY' })
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
  })

  it('shows an error with a retry when the payment cannot be loaded', async () => {
    bootAp({ permissions: base }, { [`GET ${BASE}/vendor-payments/pay-1`]: { status: 404, data: { message: 'x' } } })
    renderApp('/app/akuntansi/pembayaran-vendor/pay-1')
    expect(await screen.findByText('Data tidak ditemukan.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })
})
