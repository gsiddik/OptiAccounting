import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { account, BASE, bootAr, customer, term } from './receivables/testkit'

afterEach(() => vi.restoreAllMocks())

const view = ['accounting.customer.view']
const manage = [...view, 'accounting.customer.manage']
const accounts = [
  account('a-ar', '1210', 'Piutang usaha', { account_type: 'ASSET', is_control: true }),
  account('a-ar2', '1220', 'Piutang non-kontrol', { account_type: 'ASSET' }),
  account('a-rev', '4100', 'Pendapatan penjualan', { account_type: 'REVENUE', normal_balance: 'CREDIT' }),
  account('a-rev-old', '4900', 'Pendapatan lama', { account_type: 'REVENUE', normal_balance: 'CREDIT', status: 'INACTIVE' }),
  account('a-exp', '6100', 'Beban jasa'),
]
const routes = {
  [`GET ${BASE}/customers`]: { data: page([customer({ credit_limit: '5000000.0000' }), customer({ id: 'c-2', code: 'C2', name: 'CV Tidak Aktif', status: 'INACTIVE', payment_term_id: null, payment_term: null })]) },
  [`GET ${BASE}/ar-payment-terms`]: { data: { data: [term(), term({ id: 'term-x', code: 'OLD', name: 'Termin lama', status: 'INACTIVE' })] } },
}

describe('customers', () => {
  it('lists customers from the server with the credit limit as information, and offers no management without accounting.customer.manage', async () => {
    bootAr({ permissions: [...view, 'accounting.report.export'] }, routes)
    renderApp('/app/akuntansi/pelanggan')

    expect(await screen.findByText('PT Pelanggan Setia')).toBeInTheDocument()
    expect(screen.getByText('CV Tidak Aktif')).toBeInTheDocument()
    expect(screen.getByText('Net 30 hari', { selector: 'td' })).toBeInTheDocument()
    const row = within(screen.getByText('PT Pelanggan Setia').closest('tr') as HTMLElement)
    expect(row.getByText('5.000.000,00')).toBeInTheDocument() // credit limit, as the server stored it
    expect(screen.getByRole('columnheader', { name: 'Batas kredit (info)' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Pelanggan baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Ubah pelanggan/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Hapus pelanggan/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ekspor CSV' })).toBeInTheDocument()
  })

  it('shows no export button without accounting.report.export', async () => {
    bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/pelanggan')
    await screen.findByText('PT Pelanggan Setia')
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
  })

  it('hides the management buttons when the AR module is read-only', async () => {
    bootAr({ permissions: manage, ar: 'READ_ONLY' }, routes)
    renderApp('/app/akuntansi/pelanggan')

    await screen.findByText('PT Pelanggan Setia')
    expect(screen.getByText(/mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Pelanggan baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Ubah pelanggan/ })).not.toBeInTheDocument()
  })

  it('searches and filters on the server (the term filter reads ar-payment-terms) and resets to the first page', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/pelanggan')
    await screen.findByText('PT Pelanggan Setia')

    await userEvent.selectOptions(screen.getByLabelText('Status pelanggan'), 'INACTIVE')
    await userEvent.selectOptions(await screen.findByRole('option', { name: /NET30/ }).then(() => screen.getByLabelText('Termin pembayaran')), 'term-30')
    await userEvent.type(screen.getByLabelText('Cari pelanggan'), 'cv')
    await waitFor(() => {
      const last = calls.filter((c) => c.url === `${BASE}/customers`).at(-1)
      expect(last?.params).toEqual({ page: 1, status: 'INACTIVE', payment_term_id: 'term-30', q: 'cv' })
    })
    expect(calls.some((c) => c.url === `${BASE}/ar-payment-terms`)).toBe(true)
    expect(calls.some((c) => c.url === `${BASE}/payment-terms`)).toBe(false)
  })

  it('creates a customer with the exact payload and only offers fitting accounts', async () => {
    const calls = bootAr({ permissions: [...manage, 'accounting.coa.view'] }, {
      ...routes,
      [`GET ${BASE}/accounts`]: { data: { data: accounts } },
      [`POST ${BASE}/customers`]: { status: 201, data: customer() },
    })
    renderApp('/app/akuntansi/pelanggan')
    await screen.findByText('PT Pelanggan Setia')

    await userEvent.click(screen.getByRole('button', { name: 'Pelanggan baru' }))
    const form = within(await screen.findByRole('dialog', { name: 'Pelanggan baru' }))
    await userEvent.type(form.getByLabelText('Kode'), 'abc-1')
    await userEvent.type(form.getByLabelText('Nama'), '  PT Baru  ')
    await userEvent.type(form.getByLabelText('Email'), 'kasir@baru.test')
    await userEvent.click(form.getByLabelText(/Pengusaha Kena Pajak/))
    await userEvent.selectOptions(form.getByLabelText('Termin pembayaran'), 'term-30')
    expect(within(form.getByLabelText('Termin pembayaran')).queryByText(/Termin lama/)).not.toBeInTheDocument() // inactive terms are not offered
    await waitFor(() => expect(within(form.getByLabelText('Akun piutang (override)')).getAllByRole('option').length).toBeGreaterThan(1))
    expect(within(form.getByLabelText('Akun piutang (override)')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Pemetaan peran standar', '1210 · Piutang usaha'])
    expect(within(form.getByLabelText('Akun pendapatan default')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Tanpa akun default', '4100 · Pendapatan penjualan'])
    await userEvent.selectOptions(form.getByLabelText('Akun piutang (override)'), 'a-ar')
    await userEvent.selectOptions(form.getByLabelText('Akun pendapatan default'), 'a-rev')
    await userEvent.type(form.getByLabelText('Batas kredit'), '5000000')
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/customers`)).toBe(true))
    const post = calls.find((c) => c.method === 'POST' && c.url === `${BASE}/customers`)
    expect(post?.data).toEqual({
      code: 'abc-1', name: 'PT Baru', legal_name: null, contact_name: null, email: 'kasir@baru.test', phone: null, address: null, tax_id: null, tax_registered: true,
      payment_term_id: 'term-30', default_currency: null, receivable_account_id: 'a-ar', default_revenue_account_id: 'a-rev', credit_limit: '5000000.0000', notes: null,
    })
    expect(post?.data).toMatchObject({ credit_limit: expect.any(String) }) // money is a decimal string, never a number
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('sends a null credit limit when it is left empty, and refuses a credit limit that is not a number before any request', async () => {
    const calls = bootAr({ permissions: manage }, { ...routes, [`GET ${BASE}/accounts`]: { data: { data: accounts } }, [`POST ${BASE}/customers`]: { status: 201, data: customer() } })
    renderApp('/app/akuntansi/pelanggan')
    await screen.findByText('PT Pelanggan Setia')

    await userEvent.click(screen.getByRole('button', { name: 'Pelanggan baru' }))
    const form = within(await screen.findByRole('dialog', { name: 'Pelanggan baru' }))
    await userEvent.type(form.getByLabelText('Kode'), 'X1')
    await userEvent.type(form.getByLabelText('Nama'), 'X')
    await userEvent.type(form.getByLabelText('Batas kredit'), '5.000.000')
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))
    expect(await form.findByText(/Batas kredit tidak valid/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)

    await userEvent.clear(form.getByLabelText('Batas kredit'))
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/customers`)).toBe(true))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${BASE}/customers`)?.data).toMatchObject({ code: 'X1', credit_limit: null })
  })

  it('shows the server field message and refusal inside the customer form', async () => {
    bootAr({ permissions: manage }, {
      ...routes,
      [`GET ${BASE}/accounts`]: { status: 403, data: { code: 'PERMISSION_DENIED', message: 'x' } },
      [`POST ${BASE}/customers`]: { status: 422, data: { message: 'The given data was invalid.', errors: { email: ['Format email salah.'] } } },
    })
    renderApp('/app/akuntansi/pelanggan')
    await screen.findByText('PT Pelanggan Setia')

    await userEvent.click(screen.getByRole('button', { name: 'Pelanggan baru' }))
    const form = within(await screen.findByRole('dialog', { name: 'Pelanggan baru' }))
    await userEvent.type(form.getByLabelText('Kode'), 'X1')
    await userEvent.type(form.getByLabelText('Nama'), 'X')
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))

    expect(await form.findByText('Format email salah.', { selector: '.error' })).toBeInTheDocument()
    expect(form.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true')
    expect(form.getByText(/Daftar akun tidak dapat dimuat/)).toBeInTheDocument()
  })

  it('maps a duplicate customer code to its Indonesian message', async () => {
    bootAr({ permissions: manage }, {
      ...routes,
      [`GET ${BASE}/accounts`]: { data: { data: accounts } },
      [`POST ${BASE}/customers`]: { status: 422, data: { code: 'CUSTOMER_CODE_TAKEN', message: 'taken' } },
    })
    renderApp('/app/akuntansi/pelanggan')
    await screen.findByText('PT Pelanggan Setia')
    await userEvent.click(screen.getByRole('button', { name: 'Pelanggan baru' }))
    const form = within(await screen.findByRole('dialog', { name: 'Pelanggan baru' }))
    await userEvent.type(form.getByLabelText('Kode'), 'C1')
    await userEvent.type(form.getByLabelText('Nama'), 'X')
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))
    expect(await form.findByText('Pelanggan dengan kode ini sudah ada.')).toBeInTheDocument()
  })

  it('edits a customer with PATCH and keeps the current receivable account selectable when the chart cannot be read', async () => {
    const withAccount = customer({ receivable_account_id: 'a-ar', receivable_account: { id: 'a-ar', code: '1210', name: 'Piutang usaha' }, credit_limit: '2500000.5000' })
    const calls = bootAr({ permissions: manage }, {
      ...routes,
      [`GET ${BASE}/customers`]: { data: page([withAccount]) },
      [`GET ${BASE}/accounts`]: { status: 403, data: { code: 'PERMISSION_DENIED', message: 'x' } },
      [`PATCH ${BASE}/customers/c-1`]: { data: withAccount },
    })
    renderApp('/app/akuntansi/pelanggan')
    await screen.findByText('PT Pelanggan Setia')

    await userEvent.click(screen.getByRole('button', { name: 'Ubah pelanggan C1' }))
    const form = within(await screen.findByRole('dialog', { name: 'Ubah pelanggan C1' }))
    expect(form.getByLabelText('Akun piutang (override)')).toHaveValue('a-ar')
    expect(form.getByLabelText('Batas kredit')).toHaveValue('2500000.5')
    await userEvent.clear(form.getByLabelText('Nama'))
    await userEvent.type(form.getByLabelText('Nama'), 'PT Pelanggan Setia Jaya')
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true))
    expect(calls.find((c) => c.method === 'PATCH')?.data).toMatchObject({ code: 'C1', name: 'PT Pelanggan Setia Jaya', receivable_account_id: 'a-ar', payment_term_id: 'term-30', credit_limit: '2500000.5000' })
  })

  it('explains that a used customer cannot be deleted (CUSTOMER_IN_USE) and deactivates instead', async () => {
    const calls = bootAr({ permissions: manage }, {
      ...routes,
      [`DELETE ${BASE}/customers/c-1`]: { status: 409, data: { code: 'CUSTOMER_IN_USE', message: 'This customer has documents' } },
      [`POST ${BASE}/customers/c-1/status`]: { data: customer({ status: 'INACTIVE' }) },
    })
    renderApp('/app/akuntansi/pelanggan')
    await screen.findByText('PT Pelanggan Setia')

    await userEvent.click(screen.getByRole('button', { name: 'Hapus pelanggan C1' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Hapus pelanggan' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus' }))
    expect(await dialog.findByText('Pelanggan ini sudah memiliki dokumen: nonaktifkan, jangan hapus.')).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Batal' }))

    await userEvent.click(screen.getByRole('button', { name: 'Nonaktifkan pelanggan C1' }))
    const confirm = within(await screen.findByRole('dialog', { name: 'Nonaktifkan pelanggan' }))
    await userEvent.click(confirm.getByRole('button', { name: 'Nonaktifkan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/customers/c-1/status`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/customers/c-1/status`)?.data).toEqual({ status: 'INACTIVE' })
  })

  it('activates an inactive customer', async () => {
    const calls = bootAr({ permissions: manage }, { ...routes, [`POST ${BASE}/customers/c-2/status`]: { data: customer({ id: 'c-2' }) } })
    renderApp('/app/akuntansi/pelanggan')
    await userEvent.click(await screen.findByRole('button', { name: 'Aktifkan pelanggan C2' }))
    const confirm = within(await screen.findByRole('dialog', { name: 'Aktifkan pelanggan' }))
    await userEvent.click(confirm.getByRole('button', { name: 'Aktifkan' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${BASE}/customers/c-2/status`)?.data).toEqual({ status: 'ACTIVE' }))
  })

  it('shows an error with a retry, and an empty state', async () => {
    let fail = true
    bootAr({ permissions: view }, { ...routes, [`GET ${BASE}/customers`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: page([]) }) })
    renderApp('/app/akuntansi/pelanggan')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Tidak ada pelanggan')).toBeInTheDocument()
  })

  it('refuses a direct visit without the permission, the module or the feature', async () => {
    bootAr({ permissions: [] }, routes)
    const first = renderApp('/app/akuntansi/pelanggan')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
    first.unmount()

    bootAr({ permissions: view, features: { CUSTOMER: false } }, routes)
    renderApp('/app/akuntansi/pelanggan')
    expect(await screen.findByText('Fitur tidak tersedia')).toBeInTheDocument()
  })
})

describe('receivables payment terms', () => {
  const permissions = [...manage]

  it('lists the terms from ar-payment-terms and creates a net-days term there', async () => {
    const calls = bootAr({ permissions }, { ...routes, [`POST ${BASE}/ar-payment-terms`]: { status: 201, data: term() } })
    renderApp('/app/akuntansi/pelanggan')
    await screen.findByText('PT Pelanggan Setia')

    await userEvent.click(screen.getByRole('tab', { name: 'Termin pembayaran' }))
    expect(await screen.findAllByText('Tanggal dokumen + 30 hari')).toHaveLength(2) // NET30 and the inactive OLD term

    await userEvent.click(screen.getByRole('button', { name: 'Termin baru' }))
    const form = within(await screen.findByRole('dialog', { name: 'Termin baru' }))
    await userEvent.type(form.getByLabelText('Kode'), 'net45')
    await userEvent.type(form.getByLabelText('Nama'), 'Net 45 hari')
    await userEvent.clear(form.getByLabelText('Jumlah hari'))
    await userEvent.type(form.getByLabelText('Jumlah hari'), '45')
    await userEvent.click(form.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${BASE}/ar-payment-terms`)).toBe(true))
    expect(calls.find((c) => c.method === 'POST' && c.url === `${BASE}/ar-payment-terms`)?.data).toEqual({
      code: 'net45', name: 'Net 45 hari', term_type: 'NET_DAYS', due_days: 45, allows_due_date_override: false, description: null,
    })
    expect(calls.some((c) => c.url === `${BASE}/payment-terms`)).toBe(false)
  })

  it('applies the standard terms through ar-payment-terms/defaults', async () => {
    const calls = bootAr({ permissions }, { ...routes, [`POST ${BASE}/ar-payment-terms/defaults`]: { status: 201, data: { created: ['COD', 'NET7'] } } })
    renderApp('/app/akuntansi/pelanggan')
    await userEvent.click(await screen.findByRole('tab', { name: 'Termin pembayaran' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Terapkan termin standar' }))
    expect(await screen.findByText('2 termin standar ditambahkan (COD, NET7).')).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST' && c.url === `${BASE}/ar-payment-terms/defaults`)).toHaveLength(1)
  })

  it('refuses to delete a used term with the API reason, and names who may use it', async () => {
    bootAr({ permissions }, { ...routes, [`DELETE ${BASE}/ar-payment-terms/term-30`]: { status: 409, data: { code: 'PAYMENT_TERM_IN_USE', message: 'used' } } })
    renderApp('/app/akuntansi/pelanggan')
    await userEvent.click(await screen.findByRole('tab', { name: 'Termin pembayaran' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Hapus termin NET30' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Hapus termin' }))
    expect(dialog.getByText(/Termin yang sudah dipakai pelanggan atau faktur tidak dapat dihapus/)).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus' }))
    expect(await dialog.findByText(/nonaktifkan, jangan hapus/)).toBeInTheDocument()
  })

  it('hides term management without accounting.customer.manage', async () => {
    bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/pelanggan')
    await userEvent.click(await screen.findByRole('tab', { name: 'Termin pembayaran' }))
    await screen.findAllByText('Tanggal dokumen + 30 hari')
    expect(screen.queryByRole('button', { name: 'Termin baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Terapkan termin standar' })).not.toBeInTheDocument()
  })
})
