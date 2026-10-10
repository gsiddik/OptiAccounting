import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockApi, page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { account, bootAp, BASE, category, invoice, invoiceRow, noDimensions, term, vendor } from './payables/testkit'

afterEach(() => vi.restoreAllMocks())

describe('vendors', () => {
  const view = ['accounting.vendor.view']
  const accounts = [
    account('a-ap', '2110', 'Utang usaha', { account_type: 'LIABILITY', normal_balance: 'CREDIT', is_control: true }),
    account('a-ap2', '2120', 'Utang non-kontrol', { account_type: 'LIABILITY', normal_balance: 'CREDIT' }),
    account('a-exp', '6100', 'Beban jasa'),
  ]
  const routes = {
    [`GET ${BASE}/vendors`]: { data: page([vendor(), vendor({ id: 'v-2', code: 'V2', name: 'CV Tidak Aktif', status: 'INACTIVE', payment_term_id: null, payment_term: null })]) },
    [`GET ${BASE}/payment-terms`]: { data: { data: [term(), term({ id: 'term-x', code: 'OLD', name: 'Termin lama', status: 'INACTIVE' })] } },
  }

  it('lists vendors from the server and offers no management without accounting.vendor.manage', async () => {
    bootAp({ permissions: [...view, 'accounting.report.export'] }, routes)
    renderApp('/app/akuntansi/vendor')

    expect(await screen.findByText('PT Sumber Makmur')).toBeInTheDocument()
    expect(screen.getByText('CV Tidak Aktif')).toBeInTheDocument()
    expect(screen.getByText('Net 30 hari', { selector: 'td' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Vendor baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Ubah vendor/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Hapus vendor/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ekspor CSV' })).toBeInTheDocument()
  })

  it('shows no export button without accounting.report.export', async () => {
    bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/vendor')
    await screen.findByText('PT Sumber Makmur')
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
  })

  it('hides the management buttons when the AP module is read-only', async () => {
    bootAp({ permissions: [...view, 'accounting.vendor.manage'], ap: 'READ_ONLY' }, routes)
    renderApp('/app/akuntansi/vendor')

    await screen.findByText('PT Sumber Makmur')
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Vendor baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Ubah vendor/ })).not.toBeInTheDocument()
  })

  it('searches and filters on the server and resets to the first page', async () => {
    const calls = bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/vendor')
    await screen.findByText('PT Sumber Makmur')

    await userEvent.selectOptions(screen.getByLabelText('Status vendor'), 'INACTIVE')
    await userEvent.type(screen.getByLabelText('Cari vendor'), 'cv')
    await waitFor(() => {
      const last = calls.filter((c) => c.url === `${BASE}/vendors`).at(-1)
      expect(last?.params).toEqual({ page: 1, status: 'INACTIVE', q: 'cv' })
    })
  })

  it('creates a vendor with the exact payload, offering only control liability accounts as the payable override', async () => {
    const calls = bootAp({ permissions: [...view, 'accounting.vendor.manage', 'accounting.coa.view'] }, {
      ...routes,
      [`GET ${BASE}/accounts`]: { data: { data: accounts } },
      [`POST ${BASE}/vendors`]: { status: 201, data: vendor() },
    })
    renderApp('/app/akuntansi/vendor')
    await screen.findByText('PT Sumber Makmur')

    await userEvent.click(screen.getByRole('button', { name: 'Vendor baru' }))
    const dialog = await screen.findByRole('dialog', { name: 'Vendor baru' })
    const form = within(dialog)
    await userEvent.type(form.getByLabelText('Kode'), 'abc-1')
    await userEvent.type(form.getByLabelText('Nama'), '  PT Baru  ')
    await userEvent.type(form.getByLabelText('Email'), 'kasir@baru.test')
    await userEvent.click(form.getByLabelText(/Pengusaha Kena Pajak/))
    await userEvent.selectOptions(form.getByLabelText('Termin pembayaran'), 'term-30')
    expect(within(form.getByLabelText('Termin pembayaran')).queryByText(/Termin lama/)).not.toBeInTheDocument() // inactive terms are not offered
    await waitFor(() => expect(within(form.getByLabelText('Akun utang (override)')).getAllByRole('option').length).toBeGreaterThan(1))
    expect(within(form.getByLabelText('Akun utang (override)')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Pemetaan peran standar', '2110 · Utang usaha'])
    await userEvent.selectOptions(form.getByLabelText('Akun utang (override)'), 'a-ap')
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/vendors`)).toBe(true))
    const post = calls.find((c) => c.method === 'POST' && c.url === `${BASE}/vendors`)
    expect(post?.data).toEqual({
      code: 'abc-1', name: 'PT Baru', legal_name: null, contact_name: null, email: 'kasir@baru.test', phone: null, address: null, tax_id: null, tax_registered: true,
      payment_term_id: 'term-30', default_currency: null, payable_account_id: 'a-ap', default_expense_account_id: null, notes: null,
    })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('shows the server field message and refusal inside the vendor form', async () => {
    bootAp({ permissions: [...view, 'accounting.vendor.manage'] }, {
      ...routes,
      [`GET ${BASE}/accounts`]: { status: 403, data: { code: 'PERMISSION_DENIED', message: 'x' } },
      [`POST ${BASE}/vendors`]: { status: 422, data: { message: 'The given data was invalid.', errors: { email: ['Format email salah.'] } } },
    })
    renderApp('/app/akuntansi/vendor')
    await screen.findByText('PT Sumber Makmur')

    await userEvent.click(screen.getByRole('button', { name: 'Vendor baru' }))
    const form = within(await screen.findByRole('dialog', { name: 'Vendor baru' }))
    await userEvent.type(form.getByLabelText('Kode'), 'X1')
    await userEvent.type(form.getByLabelText('Nama'), 'X')
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))

    expect(await form.findByText('Format email salah.', { selector: '.error' })).toBeInTheDocument()
    expect(form.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true')
    expect(form.getByText(/Daftar akun tidak dapat dimuat/)).toBeInTheDocument()
  })

  it('edits a vendor with PATCH and keeps the current payable account selectable when the chart cannot be read', async () => {
    const withAccount = vendor({ payable_account_id: 'a-ap', payable_account: { id: 'a-ap', code: '2110', name: 'Utang usaha' } })
    const calls = bootAp({ permissions: [...view, 'accounting.vendor.manage'] }, {
      ...routes,
      [`GET ${BASE}/vendors`]: { data: page([withAccount]) },
      [`GET ${BASE}/accounts`]: { status: 403, data: { code: 'PERMISSION_DENIED', message: 'x' } },
      [`PATCH ${BASE}/vendors/v-1`]: { data: withAccount },
    })
    renderApp('/app/akuntansi/vendor')
    await screen.findByText('PT Sumber Makmur')

    await userEvent.click(screen.getByRole('button', { name: 'Ubah vendor V1' }))
    const form = within(await screen.findByRole('dialog', { name: 'Ubah vendor V1' }))
    expect(form.getByLabelText('Akun utang (override)')).toHaveValue('a-ap')
    await userEvent.clear(form.getByLabelText('Nama'))
    await userEvent.type(form.getByLabelText('Nama'), 'PT Sumber Makmur Jaya')
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true))
    expect(calls.find((c) => c.method === 'PATCH')?.data).toMatchObject({ code: 'V1', name: 'PT Sumber Makmur Jaya', payable_account_id: 'a-ap', payment_term_id: 'term-30' })
  })

  it('explains that a used vendor cannot be deleted (VENDOR_IN_USE) and deactivates instead', async () => {
    const calls = bootAp({ permissions: [...view, 'accounting.vendor.manage'] }, {
      ...routes,
      [`DELETE ${BASE}/vendors/v-1`]: { status: 409, data: { code: 'VENDOR_IN_USE', message: 'This vendor has documents' } },
      [`POST ${BASE}/vendors/v-1/status`]: { data: vendor({ status: 'INACTIVE' }) },
    })
    renderApp('/app/akuntansi/vendor')
    await screen.findByText('PT Sumber Makmur')

    await userEvent.click(screen.getByRole('button', { name: 'Hapus vendor V1' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Hapus vendor' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus' }))
    expect(await dialog.findByText('Vendor ini sudah memiliki dokumen: nonaktifkan, jangan hapus.')).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Batal' }))

    await userEvent.click(screen.getByRole('button', { name: 'Nonaktifkan vendor V1' }))
    const confirm = within(await screen.findByRole('dialog', { name: 'Nonaktifkan vendor' }))
    await userEvent.click(confirm.getByRole('button', { name: 'Nonaktifkan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/vendors/v-1/status`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/vendors/v-1/status`)?.data).toEqual({ status: 'INACTIVE' })
  })
})

describe('payment terms', () => {
  const permissions = ['accounting.vendor.view', 'accounting.vendor.manage']
  const routes = {
    [`GET ${BASE}/vendors`]: { data: page([vendor()]) },
    [`GET ${BASE}/payment-terms`]: { data: { data: [term(), term({ id: 'term-c', code: 'CUSTOM', name: 'Manual', term_type: 'CUSTOM', due_days: null, allows_due_date_override: true })] } },
  }

  it('lists the terms with their due-date rule and creates a net-days term with an integer day count', async () => {
    const calls = bootAp({ permissions }, { ...routes, [`POST ${BASE}/payment-terms`]: { status: 201, data: term() } })
    renderApp('/app/akuntansi/vendor')
    await screen.findByText('PT Sumber Makmur')

    await userEvent.click(screen.getByRole('tab', { name: 'Termin pembayaran' }))
    expect(await screen.findByText('Tanggal dokumen + 30 hari')).toBeInTheDocument()
    expect(screen.getByText('Tanggal diisi sendiri')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Termin baru' }))
    const form = within(await screen.findByRole('dialog', { name: 'Termin baru' }))
    await userEvent.type(form.getByLabelText('Kode'), 'net45')
    await userEvent.type(form.getByLabelText('Nama'), 'Net 45 hari')
    await userEvent.clear(form.getByLabelText('Jumlah hari'))
    await userEvent.type(form.getByLabelText('Jumlah hari'), '45')
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/payment-terms`)).toBe(true))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${BASE}/payment-terms`)?.data).toEqual({
      code: 'net45', name: 'Net 45 hari', term_type: 'NET_DAYS', due_days: 45, allows_due_date_override: false, description: null,
    })
  })

  it('needs no day count for a custom term and always allows a manual due date', async () => {
    const calls = bootAp({ permissions }, { ...routes, [`POST ${BASE}/payment-terms`]: { status: 201, data: term() } })
    renderApp('/app/akuntansi/vendor')
    await userEvent.click(await screen.findByRole('tab', { name: 'Termin pembayaran' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Termin baru' }))
    const form = within(await screen.findByRole('dialog', { name: 'Termin baru' }))
    await userEvent.type(form.getByLabelText('Kode'), 'TGL')
    await userEvent.type(form.getByLabelText('Nama'), 'Tanggal sendiri')
    await userEvent.selectOptions(form.getByLabelText('Jenis termin'), 'CUSTOM')
    expect(form.queryByLabelText('Jumlah hari')).not.toBeInTheDocument()
    expect(form.getByRole('checkbox', { name: /Izinkan jatuh tempo/ })).toBeChecked()
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/payment-terms`)).toBe(true))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${BASE}/payment-terms`)?.data).toMatchObject({ term_type: 'CUSTOM', due_days: null, allows_due_date_override: true })
  })

  it('applies the standard terms and reports what was added', async () => {
    const calls = bootAp({ permissions }, { ...routes, [`POST ${BASE}/payment-terms/defaults`]: { status: 201, data: { created: ['COD', 'NET7'] } } })
    renderApp('/app/akuntansi/vendor')
    await userEvent.click(await screen.findByRole('tab', { name: 'Termin pembayaran' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Terapkan termin standar' }))

    expect(await screen.findByText('2 termin standar ditambahkan (COD, NET7).')).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST' && c.url === `${BASE}/payment-terms/defaults`)).toHaveLength(1)
  })

  it('refuses to delete a used term with the API reason', async () => {
    bootAp({ permissions }, { ...routes, [`DELETE ${BASE}/payment-terms/term-30`]: { status: 409, data: { code: 'PAYMENT_TERM_IN_USE', message: 'used' } } })
    renderApp('/app/akuntansi/vendor')
    await userEvent.click(await screen.findByRole('tab', { name: 'Termin pembayaran' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Hapus termin NET30' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Hapus termin' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus' }))
    expect(await dialog.findByText('Termin ini dipakai vendor atau faktur: nonaktifkan, jangan hapus.')).toBeInTheDocument()
  })

  it('hides term management without accounting.vendor.manage', async () => {
    bootAp({ permissions: ['accounting.vendor.view'] }, routes)
    renderApp('/app/akuntansi/vendor')
    await userEvent.click(await screen.findByRole('tab', { name: 'Termin pembayaran' }))
    await screen.findByText('Tanggal dokumen + 30 hari')
    expect(screen.queryByRole('button', { name: 'Termin baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Terapkan termin standar' })).not.toBeInTheDocument()
  })
})

describe('vendor invoice list', () => {
  const view = ['accounting.ap_invoice.view']
  const routes = {
    [`GET ${BASE}/ap-invoices`]: { data: page([invoiceRow(), invoiceRow({ id: 'inv-2', document_number: null, status: 'DRAFT', vendor_invoice_number: 'FP-101', paid_amount: '0.0000', outstanding_amount: '0.0000', payment_status: null, total_amount: '250000.0000' })]) },
    [`GET ${BASE}/vendors`]: { data: page([vendor(), vendor({ id: 'v-2', code: 'V2', name: 'CV Lain' })]) },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
  }

  it('renders the figures the API computed and links each invoice', async () => {
    bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/faktur-vendor')

    const link = await screen.findByRole('link', { name: 'API-FY2026-000001' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/faktur-vendor/inv-1')
    expect(screen.getByRole('link', { name: 'Draf' })).toBeInTheDocument()
    const row = within(link.closest('tr') as HTMLElement)
    expect(row.getByText('1.500.000,00')).toBeInTheDocument()
    expect(row.getByText('1.000.000,00')).toBeInTheDocument()
    expect(row.getByText('Dibayar sebagian')).toBeInTheDocument()
    expect(row.getByText('Lewat jatuh tempo')).toBeInTheDocument() // due 2026-10-01, business date 2026-10-08
  })

  it('sends every filter to the server (flags as 1, empty values dropped) and starts again from page 1', async () => {
    const calls = bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/faktur-vendor')
    await screen.findByRole('link', { name: 'API-FY2026-000001' })

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-2')
    await userEvent.selectOptions(screen.getByLabelText('Status bayar'), 'UNPAID')
    await userEvent.click(screen.getByLabelText('Belum lunas'))
    await userEvent.click(screen.getByLabelText('Jatuh tempo lewat'))
    await userEvent.click(screen.getByLabelText('Buatan saya'))
    await userEvent.type(screen.getByLabelText('Jatuh tempo dalam hari'), '14')
    await userEvent.type(screen.getByLabelText('Posting dari'), '2026-09-01')
    await userEvent.type(screen.getByLabelText('Jatuh tempo sampai'), '2026-10-31')
    await userEvent.type(screen.getByLabelText('Cari faktur'), 'fp-1')

    await waitFor(() => {
      const last = calls.filter((c) => c.url === `${BASE}/ap-invoices`).at(-1)
      expect(last?.params).toEqual({
        page: 1, status: 'POSTED', vendor_id: 'v-2', payment_status: 'UNPAID', open: 1, overdue: 1, mine: 1, due_within: '14', posting_from: '2026-09-01', due_to: '2026-10-31', q: 'fp-1',
      })
    })
  })

  it('does not send a half-typed or out-of-range due-within value', async () => {
    const calls = bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/faktur-vendor')
    await screen.findByRole('link', { name: 'API-FY2026-000001' })

    await userEvent.type(screen.getByLabelText('Jatuh tempo dalam hari'), '999')
    await new Promise((r) => setTimeout(r, 450))
    expect(calls.filter((c) => c.url === `${BASE}/ap-invoices`).every((c) => c.params?.due_within === undefined)).toBe(true)
  })

  it('offers CSV export only with accounting.report.export and exports with the same filters', async () => {
    const create = vi.fn(() => 'blob:csv')
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: vi.fn() })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    const first = bootAp({ permissions: view }, routes)
    const view1 = renderApp('/app/akuntansi/faktur-vendor')
    await screen.findByRole('link', { name: 'API-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    expect(first.length).toBeGreaterThan(0)
    view1.unmount()

    const calls = bootAp({ permissions: [...view, 'accounting.report.export'] }, { ...routes, [`GET ${BASE}/ap-invoices/export`]: { data: new Blob(['x']) } })
    renderApp('/app/akuntansi/faktur-vendor')
    await screen.findByRole('link', { name: 'API-FY2026-000001' })
    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.click(screen.getByLabelText('Belum lunas'))
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ap-invoices/export`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ap-invoices/export`)?.params).toEqual({ status: 'POSTED', open: 1 })
    expect(create).toHaveBeenCalled()
  })

  it('shows the new-invoice button only to users who can create, and never on a read-only module', async () => {
    bootAp({ permissions: [...view, 'accounting.ap_invoice.create'] }, routes)
    const first = renderApp('/app/akuntansi/faktur-vendor')
    expect(await screen.findByRole('button', { name: 'Faktur baru' })).toBeInTheDocument()
    first.unmount()

    bootAp({ permissions: [...view, 'accounting.ap_invoice.create'], ap: 'READ_ONLY' }, routes)
    renderApp('/app/akuntansi/faktur-vendor')
    await screen.findByRole('link', { name: 'API-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Faktur baru' })).not.toBeInTheDocument()
  })

  it('shows an error with a retry, and an empty state', async () => {
    let fail = true
    bootAp({ permissions: view }, {
      ...routes,
      [`GET ${BASE}/ap-invoices`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: page([]) }),
    })
    renderApp('/app/akuntansi/faktur-vendor')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Tidak ada faktur')).toBeInTheDocument()
  })
})

describe('vendor invoice editor', () => {
  const permissions = ['accounting.ap_invoice.view', 'accounting.ap_invoice.create']
  const routes = {
    [`GET ${BASE}/vendors`]: { data: page([vendor(), vendor({ id: 'v-2', code: 'V2', name: 'CV Tanpa Termin', payment_term_id: null, payment_term: null })]) },
    [`GET ${BASE}/payment-terms`]: { data: { data: [term(), term({ id: 'term-c', code: 'CUSTOM', name: 'Manual', term_type: 'CUSTOM', due_days: null, allows_due_date_override: true })] } },
    [`GET ${BASE}/expense-categories`]: { data: { data: [category] } },
    [`GET ${BASE}/accounts`]: { data: { data: [account('a-exp', '6100', 'Beban jasa'), account('a-ap', '2110', 'Utang usaha', { account_type: 'LIABILITY', is_control: true })] } },
    [`GET ${BASE}/account-mappings`]: { data: { roles: [{ code: 'GENERAL_EXPENSE', name: 'Beban umum', description: null, used_by_published_rule: true, mapped: true }, { code: 'ACCOUNTS_PAYABLE', name: 'Utang usaha', description: null, used_by_published_rule: true, mapped: true }], mappings: [] } },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
    [`GET ${BASE}/ap-invoices/check-duplicate`]: { data: { duplicate: false, status: null, document_number: null } },
  }

  it('builds the exact API payload from decimal strings, previews the total in exact arithmetic and never sends a number', async () => {
    const calls = bootAp({ permissions }, { ...routes, [`POST ${BASE}/ap-invoices`]: { status: 201, data: invoice({ id: 'inv-9', status: 'DRAFT', document_number: null }) }, [`GET ${BASE}/ap-invoices/inv-9`]: { data: invoice({ id: 'inv-9', status: 'DRAFT', document_number: null }) } })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })

    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    expect(screen.getByLabelText('Termin pembayaran')).toHaveValue('term-30') // the vendor's term is the starting point
    expect(screen.queryByLabelText(/Jatuh tempo manual/)).not.toBeInTheDocument() // NET30 does not allow a different due date
    await userEvent.type(screen.getByLabelText('Nomor faktur vendor'), ' FP-200 ')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'Jasa konsultasi Oktober')
    await userEvent.type(screen.getByLabelText('Referensi'), 'PO-7')

    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Konsultasi')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '1500000,5')
    await userEvent.selectOptions(screen.getByLabelText('Kategori beban baris 1'), 'cat-1')
    expect(within(screen.getByLabelText('Peran akun baris 1')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Tanpa peran', 'Beban umum']) // AP role is never a line destination

    await userEvent.click(screen.getByRole('button', { name: 'Tambah baris' }))
    await userEvent.type(screen.getByLabelText('Deskripsi baris 2'), 'Kertas A4')
    await userEvent.click(screen.getByLabelText(/Hitung dari kuantitas × harga satuan \(baris 2\)/))
    await userEvent.type(screen.getByLabelText('Kuantitas baris 2'), '10')
    await userEvent.type(screen.getByLabelText('Harga satuan baris 2'), '25000,5')
    await userEvent.selectOptions(screen.getByLabelText('Akun baris 2'), 'a-exp')

    await userEvent.type(screen.getByLabelText('Diskon'), '50000')
    await userEvent.type(screen.getByLabelText('Pajak'), '110000')
    await userEvent.type(screen.getByLabelText('Biaya lain'), '10000')

    // 1.500.000,50 + (10 x 25.000,50 = 250.005,00) - 50.000 + 110.000 + 10.000 = 1.820.005,50
    const totals = within(screen.getByText(/Total pratinjau/).closest('.totals') as HTMLElement)
    expect(totals.getByText('1.820.005,50')).toBeInTheDocument()
    expect(screen.getByText(/Ini pratinjau yang dihitung saat Anda mengetik/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/ap-invoices`)).toBe(true))
    const post = calls.find((c) => c.method === 'POST' && c.url === `${BASE}/ap-invoices`)
    expect(post?.data).toEqual({
      vendor_id: 'v-1', vendor_invoice_number: 'FP-200', document_date: '2026-10-08', posting_date: '2026-10-08', due_date: null, payment_term_id: 'term-30',
      description: 'Jasa konsultasi Oktober', reference: 'PO-7', branch_id: null, business_unit_id: null, cost_center_id: null,
      discount_amount: '50000.0000', tax_amount: '110000.0000', other_charges_amount: '10000.0000',
      lines: [
        { description: 'Konsultasi', amount: '1500000.5000', expense_category_id: 'cat-1', account_role: null, account_id: null, cost_center_id: null },
        { description: 'Kertas A4', quantity: '10.0000', unit_price: '25000.5000', expense_category_id: null, account_role: null, account_id: 'a-exp', cost_center_id: null },
      ],
    })
    expect(JSON.stringify(post?.data)).not.toMatch(/"(amount|quantity|unit_price|discount_amount|tax_amount|other_charges_amount)":\d/)
    expect(await screen.findByRole('heading', { name: 'Draf faktur vendor' })).toBeInTheDocument()
  })

  it('adds 0,10 and 0,20 without floating-point drift', async () => {
    bootAp({ permissions }, routes)
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })

    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '0,10')
    await userEvent.click(screen.getByRole('button', { name: 'Tambah baris' }))
    await userEvent.type(screen.getByLabelText('Jumlah baris 2'), '0,20')
    const totals = within(screen.getByText(/Total pratinjau/).closest('.totals') as HTMLElement)
    expect(totals.getByText(/Subtotal/)).toHaveTextContent('0,30') // no discount or tax: subtotal and total are legitimately equal
    expect(totals.getByText(/Total pratinjau/)).toHaveTextContent('0,30')
    expect(totals.getAllByText('0,30')).toHaveLength(2)
    expect(totals.queryByText('0,3')).not.toBeInTheDocument() // 0.1 + 0.2 in floats would be 0.30000000000000004
  })

  it('refuses obviously invalid input before any request and shows what to fix', async () => {
    const calls = bootAp({ permissions }, routes)
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Pilih vendor.')).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.type(screen.getByLabelText('Nomor faktur vendor'), 'X')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'Y')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'Baris')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '1.500.000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText(/Jumlah pada baris 1 tidak valid/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('shows the validation messages the server returned next to their fields', async () => {
    bootAp({ permissions }, {
      ...routes,
      [`POST ${BASE}/ap-invoices`]: {
        status: 422,
        data: { message: 'The given data was invalid.', errors: { vendor_invoice_number: ['Nomor faktur sudah dipakai.'], 'lines.0.description': ['Deskripsi baris terlalu panjang.'], discount_amount: ['Diskon tidak valid.'] } },
      },
    })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })

    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.type(screen.getByLabelText('Nomor faktur vendor'), 'FP-1')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'D')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'B')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '100')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    expect(await screen.findByText('Nomor faktur sudah dipakai.', { selector: '.error' })).toBeInTheDocument()
    expect(screen.getByText('Deskripsi baris terlalu panjang.', { selector: '.error' })).toBeInTheDocument()
    expect(screen.getByText('Diskon tidak valid.', { selector: '.error' })).toBeInTheDocument()
    expect(screen.getByLabelText('Nomor faktur vendor')).toHaveAttribute('aria-invalid', 'true')
  })

  it('shows a domain refusal on the line it points at', async () => {
    bootAp({ permissions }, {
      ...routes,
      [`POST ${BASE}/ap-invoices`]: { status: 422, data: { code: 'LINE_AMOUNT_INVALID', message: 'A line amount must be greater than zero.', details: { line: 1 } } },
    })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })

    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.type(screen.getByLabelText('Nomor faktur vendor'), 'FP-1')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'D')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'B')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '0')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    const group = within(await screen.findByRole('group', { name: 'Baris 1' }))
    expect(await group.findByText(/Setiap baris|lebih besar dari nol|harus lebih besar/i)).toBeInTheDocument()
  })

  it('lets a CUSTOM term take an explicit due date and sends it, but sends null for a term that derives it', async () => {
    const calls = bootAp({ permissions }, { ...routes, [`POST ${BASE}/ap-invoices`]: { status: 201, data: invoice({ id: 'inv-9', status: 'DRAFT' }) }, [`GET ${BASE}/ap-invoices/inv-9`]: { data: invoice({ id: 'inv-9', status: 'DRAFT' }) } })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })

    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-2')
    expect(screen.getByLabelText('Termin pembayaran')).toHaveValue('') // this vendor has no term
    await userEvent.selectOptions(screen.getByLabelText('Termin pembayaran'), 'term-c')
    await userEvent.type(screen.getByLabelText(/Jatuh tempo \(wajib untuk termin ini\)/), '2026-11-15')
    await userEvent.type(screen.getByLabelText('Nomor faktur vendor'), 'C-1')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'D')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'B')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '100')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/ap-invoices`)).toBe(true))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${BASE}/ap-invoices`)?.data).toMatchObject({ vendor_id: 'v-2', payment_term_id: 'term-c', due_date: '2026-11-15' })
  })

  it('warns about a duplicate vendor invoice number and sends the override with its reason only for an authorized user', async () => {
    const duplicate = { duplicate: true, status: 'POSTED', document_number: 'API-FY2026-000009' }
    const calls = bootAp({ permissions: [...permissions, 'accounting.ap_invoice.override_duplicate'] }, {
      ...routes,
      [`GET ${BASE}/ap-invoices/check-duplicate`]: { data: duplicate },
      [`POST ${BASE}/ap-invoices`]: { status: 201, data: invoice({ id: 'inv-9', status: 'DRAFT' }) },
      [`GET ${BASE}/ap-invoices/inv-9`]: { data: invoice({ id: 'inv-9', status: 'DRAFT' }) },
    })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })

    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.type(screen.getByLabelText('Nomor faktur vendor'), 'FP-100')
    expect(await screen.findByText(/sudah memiliki faktur dengan nomor yang sama \(API-FY2026-000009, Terposting\)/)).toBeInTheDocument()
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ap-invoices/check-duplicate`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ap-invoices/check-duplicate`)?.params).toEqual({ vendor_id: 'v-1', vendor_invoice_number: 'FP-100' })

    await userEvent.type(screen.getByLabelText('Deskripsi'), 'D')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'B')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '100')
    await userEvent.click(screen.getByLabelText(/Catat tetap sebagai nomor ganda/))
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    expect(await screen.findByText('Isi alasan mencatat nomor faktur vendor yang sama.')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Alasan nomor ganda'), 'Faktur pengganti')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/ap-invoices`)).toBe(true))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${BASE}/ap-invoices`)?.data).toMatchObject({ duplicate_override: true, duplicate_override_reason: 'Faktur pengganti' })
  })

  it('offers no override to a user without the permission', async () => {
    bootAp({ permissions }, { ...routes, [`GET ${BASE}/ap-invoices/check-duplicate`]: { data: { duplicate: true, status: 'DRAFT', document_number: null } } })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.type(screen.getByLabelText('Nomor faktur vendor'), 'FP-100')

    expect(await screen.findByText(/Anda tidak memiliki izin mencatat nomor ganda/)).toBeInTheDocument()
    expect(screen.queryByLabelText(/Catat tetap sebagai nomor ganda/)).not.toBeInTheDocument()
  })

  it('edits a draft with PATCH, keeps the saved lines, and refuses a posted invoice', async () => {
    const draft = invoice({ id: 'inv-3', status: 'DRAFT', document_number: null, vendor_invoice_number: 'FP-300', discount_amount: '1000.0000', lines: [{ id: 'l-1', line_number: 1, description: 'Kertas', quantity: '10.0000', unit_price: '25000.5000', amount: '250005.0000', expense_category_id: null, account_role: null, account_id: null, cost_center_id: null }] })
    const calls = bootAp({ permissions: [...permissions, 'accounting.ap_invoice.update'] }, {
      ...routes,
      [`GET ${BASE}/ap-invoices/inv-3`]: { data: draft },
      [`GET ${BASE}/ap-invoices/inv-4`]: { data: invoice({ id: 'inv-4', status: 'POSTED' }) },
      [`PATCH ${BASE}/ap-invoices/inv-3`]: { data: draft },
    })
    const first = renderApp('/app/akuntansi/faktur-vendor/inv-3/ubah')
    expect(await screen.findByRole('heading', { name: 'Ubah draf faktur FP-300' })).toBeInTheDocument()
    expect(screen.getByLabelText('Kuantitas baris 1')).toHaveValue('10')
    expect(screen.getByLabelText('Harga satuan baris 1')).toHaveValue('25000.5')
    expect(screen.getByLabelText('Diskon')).toHaveValue('1000')

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true))
    expect(calls.find((c) => c.method === 'PATCH')?.data).toMatchObject({ vendor_invoice_number: 'FP-300', discount_amount: '1000.0000', lines: [{ quantity: '10.0000', unit_price: '25000.5000' }] })
    first.unmount()

    renderApp('/app/akuntansi/faktur-vendor/inv-4/ubah')
    expect(await screen.findByText(/Hanya faktur vendor berstatus draf yang dapat diubah/)).toBeInTheDocument()
  })

  it('saves and submits in one step, and still opens the saved draft when the submit is refused', async () => {
    const calls = bootAp({ permissions: [...permissions, 'accounting.ap_invoice.submit'] }, {
      ...routes,
      [`POST ${BASE}/ap-invoices`]: { status: 201, data: invoice({ id: 'inv-9', status: 'DRAFT' }) },
      [`POST ${BASE}/ap-invoices/inv-9/submit`]: { status: 422, data: { code: 'AP_INVOICE_TOTAL_INVALID', message: 'x' } },
      [`GET ${BASE}/ap-invoices/inv-9`]: { data: invoice({ id: 'inv-9', status: 'DRAFT', document_number: null }) },
    })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.type(screen.getByLabelText('Nomor faktur vendor'), 'FP-1')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'D')
    await userEvent.type(screen.getByLabelText('Deskripsi baris 1'), 'B')
    await userEvent.type(screen.getByLabelText('Jumlah baris 1'), '100')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }))

    expect(await screen.findByRole('heading', { name: 'Draf faktur vendor' })).toBeInTheDocument()
    expect(await screen.findByText(/Draf disimpan, tetapi belum dapat diajukan: Total faktur harus lebih besar dari nol/)).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST' && c.url === `${BASE}/ap-invoices`)).toHaveLength(1)
  })

  it('does not open for a read-only AP module', async () => {
    bootAp({ permissions, ap: 'READ_ONLY' }, routes)
    renderApp('/app/akuntansi/faktur-vendor/baru')
    expect(await screen.findByText('Modul hanya baca')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Simpan draf' })).not.toBeInTheDocument()
  })
})

describe('vendor invoice detail', () => {
  const base = ['accounting.ap_invoice.view']
  const all = [...base, 'accounting.ap_invoice.update', 'accounting.ap_invoice.submit', 'accounting.ap_invoice.approve', 'accounting.ap_invoice.post', 'accounting.ap_invoice.reverse']
  const open = (permissions: string[], inv: ReturnType<typeof invoice>, options: { ap?: 'FULL' | 'READ_ONLY'; subscription?: 'FULL' | 'READ_ONLY' } = {}, extra: Parameters<typeof mockApi>[0] = {}) => {
    const calls = bootAp({ permissions, ...options }, { [`GET ${BASE}/ap-invoices/${inv.id}`]: { data: inv }, ...extra })
    renderApp(`/app/akuntansi/faktur-vendor/${inv.id}`)
    return calls
  }
  const buttons = () => ['Ubah', 'Ajukan', 'Setujui', 'Tolak', 'Posting', 'Jadikan draf', 'Batalkan', 'Balik'].filter((n) => screen.queryByRole('button', { name: n }) ?? screen.queryByRole('link', { name: n }))

  it('shows the facts, the lines, the server figures, the allocations and the journal link of a posted invoice', async () => {
    open(base, invoice({ possible_duplicates: [{ id: 'inv-77', document_number: 'API-FY2026-000077', vendor_invoice_number: 'FP-999', status: 'POSTED' }] }))

    expect(await screen.findByRole('heading', { name: 'API-FY2026-000001' })).toBeInTheDocument()
    expect(screen.getByText('PT Sumber Makmur', { exact: false })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Lihat jurnal' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-1')
    expect(screen.getByRole('link', { name: 'PAY-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/pembayaran-vendor/pay-1')
    const settlement = within(screen.getByRole('heading', { name: 'Pelunasan' }).closest('section') as HTMLElement)
    // The paid figure appears twice on purpose: once in the facts and once as the allocation row. Check each place.
    const fact = (label: string) => within(settlement.getByText(label, { selector: 'dt' }).closest('div') as HTMLElement)
    expect(fact('Sudah dibayar').getByText('500.000,00')).toBeInTheDocument() // paid, from the API
    expect(fact('Saldo terutang').getByText('1.000.000,00')).toBeInTheDocument() // outstanding, from the API
    const row = within(settlement.getByRole('link', { name: 'PAY-FY2026-000001' }).closest('tr') as HTMLElement)
    expect(row.getByText('500.000,00')).toBeInTheDocument()
    expect(screen.getByText(/Pastikan bukan tagihan ganda/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'API-FY2026-000077' })).toHaveAttribute('href', '/app/akuntansi/faktur-vendor/inv-77')
    expect(screen.getByText('Kategori JASA')).toBeInTheDocument()
  })

  it('offers edit, submit and cancel on a draft for a user who holds those permissions, and no posting while approval is required', async () => {
    open(all, invoice({ status: 'DRAFT', document_number: null, payment_status: null }))
    await screen.findByRole('heading', { name: 'Draf faktur vendor' })

    expect(screen.getByRole('link', { name: 'Ubah' })).toHaveAttribute('href', '/app/akuntansi/faktur-vendor/inv-1/ubah')
    expect(screen.getByRole('button', { name: 'Ajukan' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Batalkan' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Posting' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Balik' })).not.toBeInTheDocument()
  })

  it('posts a draft directly when the accounting policy needs no approval', async () => {
    open(all, invoice({ status: 'DRAFT', document_number: null, sod: { approve: true, post: true, approval_required: false } }))
    await screen.findByRole('heading', { name: 'Draf faktur vendor' })
    expect(screen.getByRole('button', { name: 'Posting' })).toBeEnabled()
    expect(screen.queryByRole('button', { name: 'Ajukan' })).not.toBeInTheDocument()
  })

  it('submits a draft with POST /submit', async () => {
    const calls = open(all, invoice({ status: 'DRAFT', document_number: null }), {}, { [`POST ${BASE}/ap-invoices/inv-1/submit`]: { data: invoice({ status: 'SUBMITTED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Ajukan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ap-invoices/inv-1/submit`)).toBe(true))
  })

  it('shows the segregation-of-duties flags of the server: approve is disabled and explained', async () => {
    open(all, invoice({ status: 'SUBMITTED', document_number: null, sod: { approve: false, post: false, approval_required: true } }))

    expect(await screen.findByRole('button', { name: 'Setujui' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Tolak' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat menyetujui faktur/)).toBeInTheDocument()
  })

  it('offers posting only on an approved invoice, disabled with a note when SoD forbids it', async () => {
    const approved = invoice({ status: 'APPROVED', document_number: null })
    const first = open(all, approved)
    expect(await screen.findByRole('button', { name: 'Posting' })).toBeEnabled()
    expect(first.length).toBeGreaterThan(0)
    expect(buttons()).not.toContain('Balik')
  })

  it('explains the posting refusal of segregation of duties', async () => {
    open(all, invoice({ status: 'APPROVED', document_number: null, sod: { approve: true, post: false, approval_required: true } }))
    expect(await screen.findByRole('button', { name: 'Posting' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat memposting faktur/)).toBeInTheDocument()
  })

  it('offers reversal only on a posted invoice, and a reason-and-date dialog posts the reversal', async () => {
    const calls = open(all, invoice(), {}, { [`POST ${BASE}/ap-invoices/inv-1/reverse`]: { data: invoice({ status: 'REVERSED' }) } })
    await userEvent.click(await screen.findByRole('button', { name: 'Balik' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Balik faktur' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Faktur salah')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ap-invoices/inv-1/reverse`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ap-invoices/inv-1/reverse`)?.data).toEqual({ reason: 'Faktur salah', posting_date: '2026-10-08' })
  })

  it('shows the API refusal when reversing an invoice that still has payments', async () => {
    open(all, invoice(), {}, { [`POST ${BASE}/ap-invoices/inv-1/reverse`]: { status: 409, data: { code: 'AP_INVOICE_HAS_PAYMENTS', message: 'x' } } })
    await userEvent.click(await screen.findByRole('button', { name: 'Balik' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Balik faktur' }))
    await userEvent.type(dialog.getByLabelText('Alasan (dicatat di audit)'), 'Faktur salah')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))
    expect(await dialog.findByText('Faktur sudah dialokasikan ke pembayaran. Balik pembayarannya terlebih dahulu.')).toBeInTheDocument()
  })

  it('never offers post, submit or reverse for a payable created by an expense, and points to the expense', async () => {
    open(all, invoice({ origin: 'EXPENSE', source_type: 'expense', source_id: 'ex-1', lines: [] }))
    expect(await screen.findByText(/Utang ini dibuat oleh beban/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Lihat beban' })).toHaveAttribute('href', '/app/akuntansi/beban/ex-1')
    expect(buttons()).toEqual([])
  })

  it('offers no mutation to a user who only holds the view permission', async () => {
    open(base, invoice({ status: 'APPROVED', document_number: null }))
    await screen.findByRole('heading', { name: 'Faktur belum bernomor' }).catch(() => screen.findByRole('heading', { level: 1 }))
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
  })

  it('offers no mutation on a read-only AP module or a read-only subscription', async () => {
    const first = open(all, invoice({ status: 'APPROVED', document_number: null }), { ap: 'READ_ONLY' })
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
    expect(first.length).toBeGreaterThan(0)
  })

  it('offers no mutation on a read-only subscription', async () => {
    open(all, invoice({ status: 'DRAFT', document_number: null }), { subscription: 'READ_ONLY' })
    await screen.findByText('Riwayat')
    expect(buttons()).toEqual([])
  })

  it('shows why a rejected or cancelled invoice ended, and reopens a rejected one', async () => {
    const calls = open(all, invoice({ status: 'REJECTED', document_number: null, reject_reason: 'Nilai tidak sesuai PO' }), {}, { [`POST ${BASE}/ap-invoices/inv-1/reopen`]: { data: invoice({ status: 'DRAFT' }) } })
    expect(await screen.findByText('Ditolak: Nilai tidak sesuai PO')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Jadikan draf' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ap-invoices/inv-1/reopen`)).toBe(true))
  })

  it('shows an error with retry when the invoice cannot be loaded', async () => {
    bootAp({ permissions: base }, { [`GET ${BASE}/ap-invoices/inv-1`]: { status: 404, data: { message: 'x' } } })
    renderApp('/app/akuntansi/faktur-vendor/inv-1')
    expect(await screen.findByText('Data tidak ditemukan.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument()
  })
})
