import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { account, boot, taxCode, taxCodeDetail, taxTransaction } from './testkit'

const AP = '/app/accounting'
const view = ['accounting.tax.view']
const manage = [...view, 'accounting.tax.manage']

afterEach(() => vi.restoreAllMocks())

describe('tax codes list', () => {
  const rows = [
    taxCode(),
    taxCode({ id: 'tc-2', code: 'PPN-MASUKAN', name: 'PPN Masukan', tax_type: 'INPUT_TAX', account_role: null, account_id: 'a-1150', account: { id: 'a-1150', code: '1150', name: 'Pajak Dibayar di Muka' } }),
    taxCode({ id: 'tc-3', code: 'PPN-BIAYA', name: 'PPN tidak dikreditkan', tax_type: 'INPUT_TAX', is_recoverable: false, account_role: null, status: 'INACTIVE' }),
  ]
  const list = { 'GET /app/accounting/tax-codes': { data: page(rows) } }

  it('lists codes with their destination and offers no management control without the permission', async () => {
    boot(view, list)
    renderApp('/app/akuntansi/kode-pajak')

    expect(await screen.findByRole('link', { name: 'PPN-KELUARAN' })).toHaveAttribute('href', '/app/akuntansi/kode-pajak/tc-1')
    expect(screen.getByText('Pajak Dibayar di Muka', { exact: false })).toBeInTheDocument()
    expect(screen.getByText('Menjadi biaya (tanpa akun pajak)')).toBeInTheDocument()
    expect(screen.getByText('TAX_PAYABLE')).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Buka' })).toHaveLength(3)
    for (const label of ['Kode pajak baru', 'Ubah', 'Nonaktifkan', 'Aktifkan']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
  })

  it('offers no management control in a read-only subscription even for a user who may manage', async () => {
    boot(manage, list, { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/kode-pajak')

    expect(await screen.findByRole('link', { name: 'PPN-KELUARAN' })).toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Kode pajak baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ubah' })).not.toBeInTheDocument()
  })

  it('searches and filters on the server', async () => {
    const calls = boot(view, list)
    renderApp('/app/akuntansi/kode-pajak')
    await screen.findByRole('link', { name: 'PPN-KELUARAN' })

    await userEvent.selectOptions(screen.getByLabelText('Jenis pajak'), 'INPUT_TAX')
    await userEvent.selectOptions(screen.getByLabelText('Status kode pajak'), 'INACTIVE')
    await userEvent.type(screen.getByLabelText('Cari kode pajak'), 'ppn')
    await waitFor(() => expect(calls.some((c) => c.url === `${AP}/tax-codes` && c.params?.q === 'ppn' && c.params?.tax_type === 'INPUT_TAX' && c.params?.status === 'INACTIVE')).toBe(true))
  })

  it('creates a code with its first rate and sends the destination as neither account nor role (the type default)', async () => {
    const calls = boot(manage, {
      'GET /app/accounting/tax-codes': { data: page([]) },
      'GET /app/accounting/accounts': { data: { data: [] } },
      'POST /app/accounting/tax-codes': { status: 201, data: taxCodeDetail({ id: 'tc-9' }) },
      'GET /app/accounting/tax-codes/tc-9': { data: taxCodeDetail({ id: 'tc-9' }) },
    })
    renderApp('/app/akuntansi/kode-pajak')
    await userEvent.click(await screen.findByRole('button', { name: 'Kode pajak baru' }))

    const dialog = within(screen.getByRole('dialog', { name: 'Kode pajak baru' }))
    await userEvent.type(dialog.getByLabelText('Kode'), 'ppn-keluaran')
    await userEvent.type(dialog.getByLabelText('Nama'), 'PPN Keluaran')
    await userEvent.type(dialog.getByLabelText('Tarif (%)'), '11')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({
      name: 'PPN Keluaran', description: null, account_id: null, account_role: null, code: 'PPN-KELUARAN', tax_type: 'OUTPUT_TAX', calculation_method: 'EXCLUSIVE',
      treatment: 'STANDARD', is_recoverable: true, rate: '11', effective_from: '2026-10-08',
    })
    expect(await screen.findByText('Kode pajak dibuat.')).toBeInTheDocument()
    expect(await screen.findByRole('heading', { level: 1, name: 'PPN-KELUARAN' })).toBeInTheDocument()
  })

  it('sends a chosen account (only fitting accounts are offered) and no role, and takes a decimal comma in the rate', async () => {
    const calls = boot(manage, {
      'GET /app/accounting/tax-codes': { data: page([]) },
      'GET /app/accounting/accounts': { data: { data: [account('a-2120', '2120', 'Utang Pajak', { account_type: 'LIABILITY' }), account('a-1150', '1150', 'Pajak Dibayar di Muka'), account('a-2110', '2110', 'Utang Usaha', { account_type: 'LIABILITY', is_control: true })] } },
      'POST /app/accounting/tax-codes': { status: 201, data: taxCodeDetail({ id: 'tc-9' }) },
      'GET /app/accounting/tax-codes/tc-9': { data: taxCodeDetail({ id: 'tc-9' }) },
    })
    renderApp('/app/akuntansi/kode-pajak')
    await userEvent.click(await screen.findByRole('button', { name: 'Kode pajak baru' }))

    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'PPH23')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Pajak khusus')
    await userEvent.type(dialog.getByLabelText('Tarif (%)'), '2,5')
    await userEvent.click(dialog.getByRole('radio', { name: 'Akun tertentu' }))
    // An output tax posts to a liability account; control and asset accounts are not offered.
    await waitFor(() => expect(dialog.getAllByRole('option').map((o) => o.textContent)).toContain('2120 · Utang Pajak'))
    const select = dialog.getByLabelText('Akun pajak')
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual(['Pilih akun…', '2120 · Utang Pajak'])
    await userEvent.selectOptions(select, 'a-2120')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toMatchObject({ code: 'PPH23', rate: '2.5', account_id: 'a-2120', account_role: null })
  })

  it('a non-recoverable input tax names no account at all and a zero-rated code is sent with rate 0', async () => {
    const calls = boot(manage, {
      'GET /app/accounting/tax-codes': { data: page([]) },
      'GET /app/accounting/accounts': { data: { data: [] } },
      'POST /app/accounting/tax-codes': { status: 201, data: taxCodeDetail({ id: 'tc-9' }) },
      'GET /app/accounting/tax-codes/tc-9': { data: taxCodeDetail({ id: 'tc-9' }) },
    })
    renderApp('/app/akuntansi/kode-pajak')
    await userEvent.click(await screen.findByRole('button', { name: 'Kode pajak baru' }))

    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'PM-BIAYA')
    await userEvent.type(dialog.getByLabelText('Nama'), 'PPN masukan menjadi biaya')
    await userEvent.selectOptions(dialog.getByLabelText('Jenis pajak'), 'INPUT_TAX')
    await userEvent.selectOptions(dialog.getByLabelText('Perlakuan'), 'ZERO_RATED')
    expect(dialog.getByLabelText('Tarif (%)')).toBeDisabled()
    await userEvent.click(dialog.getByLabelText('Dapat dikreditkan'))
    expect(dialog.queryByRole('radio', { name: 'Akun tertentu' })).not.toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toMatchObject({ tax_type: 'INPUT_TAX', treatment: 'ZERO_RATED', is_recoverable: false, rate: '0', account_id: null, account_role: null })
  })

  it('shows an Indonesian message when the API refuses the new code', async () => {
    boot(manage, {
      'GET /app/accounting/tax-codes': { data: page([]) },
      'GET /app/accounting/accounts': { data: { data: [] } },
      'POST /app/accounting/tax-codes': { status: 409, data: { message: 'A tax code with this code already exists.', code: 'TAX_CODE_TAKEN', details: { field: 'code' } } },
    })
    renderApp('/app/akuntansi/kode-pajak')
    await userEvent.click(await screen.findByRole('button', { name: 'Kode pajak baru' }))

    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'PPN')
    await userEvent.type(dialog.getByLabelText('Nama'), 'PPN')
    await userEvent.type(dialog.getByLabelText('Tarif (%)'), '11')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    expect((await dialog.findAllByText('Kode pajak sudah dipakai.')).length).toBeGreaterThan(0)
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('asks for the missing fields before calling the server', async () => {
    const calls = boot(manage, { 'GET /app/accounting/tax-codes': { data: page([]) }, 'GET /app/accounting/accounts': { data: { data: [] } } })
    renderApp('/app/akuntansi/kode-pajak')
    await userEvent.click(await screen.findByRole('button', { name: 'Kode pajak baru' }))
    const dialog = within(screen.getByRole('dialog'))

    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await dialog.findByText('Kode wajib diisi.')).toBeInTheDocument()
    await userEvent.type(dialog.getByLabelText('Kode'), 'PPN')
    await userEvent.type(dialog.getByLabelText('Nama'), 'PPN')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await dialog.findByText('Tarif wajib diisi.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('deactivates a code after confirmation', async () => {
    const calls = boot(manage, { ...list, 'POST /app/accounting/tax-codes/tc-1/deactivate': { data: taxCode({ status: 'INACTIVE' }) } })
    renderApp('/app/akuntansi/kode-pajak')
    await screen.findByRole('link', { name: 'PPN-KELUARAN' })

    await userEvent.click(screen.getAllByRole('button', { name: 'Nonaktifkan' })[0])
    const dialog = within(screen.getByRole('dialog', { name: 'Nonaktifkan kode pajak' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Nonaktifkan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/tax-codes/tc-1/deactivate`)).toBeDefined())
    expect(await screen.findByText('Status kode pajak diperbarui.')).toBeInTheDocument()
  })
})

describe('tax code detail', () => {
  const detail = `${AP}/tax-codes/tc-1`
  const loaded = (over = {}) => ({ [`GET ${detail}`]: { data: taxCodeDetail(over) } })

  it('shows the facts, the effective-dated rate history and the rate in force', async () => {
    boot(view, loaded())
    renderApp('/app/akuntansi/kode-pajak/tc-1')

    expect(await screen.findByRole('heading', { level: 1, name: 'PPN-KELUARAN' })).toBeInTheDocument()
    expect(screen.getByText('Eksklusif (pajak ditambahkan)')).toBeInTheDocument()
    expect(screen.getByText('Belum dipakai')).toBeInTheDocument()
    // current_rate comes from the server; the history lists every rate with its window.
    expect(screen.getAllByText('11,00%').length).toBeGreaterThanOrEqual(2)
    expect(screen.getByText('12,00%')).toBeInTheDocument()
    expect(screen.getByText('Berlaku')).toBeInTheDocument()
    expect(screen.getByText('Terjadwal')).toBeInTheDocument()
    for (const label of ['Ubah', 'Tarif baru', 'Nonaktifkan', 'Hapus']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
  })

  it('offers Hapus only while no document used the code', async () => {
    boot(manage, loaded({ in_use: true }))
    renderApp('/app/akuntansi/kode-pajak/tc-1')
    expect(await screen.findByRole('button', { name: 'Tarif baru' })).toBeInTheDocument()
    expect(screen.getByText('Sudah dipakai dokumen')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Hapus' })).not.toBeInTheDocument()
  })

  it('adds a rate with the decimal comma turned into a point and shows the retroactive-change refusal', async () => {
    const calls = boot(manage, {
      ...loaded(),
      [`POST ${detail}/rates`]: { status: 409, data: { message: 'Posted transactions already used this code', code: 'TAX_RATE_RETROACTIVE_CONFLICT', details: { effective_from: '2026-09-01' } } },
    })
    renderApp('/app/akuntansi/kode-pajak/tc-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Tarif baru' }))

    const dialog = within(screen.getByRole('dialog', { name: 'Tarif baru untuk PPN-KELUARAN' }))
    await userEvent.type(dialog.getByLabelText('Tarif (%)'), '12,5')
    fireEvent.change(dialog.getByLabelText('Berlaku mulai'), { target: { value: '2026-09-01' } })
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan tarif' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ rate: '12.5', effective_from: '2026-09-01' })
    expect(await dialog.findByText(/Tarif tidak dapat diubah surut/)).toBeInTheDocument()
  })

  it('tells when the new rate must start by naming the start of the latest rate', async () => {
    boot(manage, {
      ...loaded(),
      [`POST ${detail}/rates`]: { status: 409, data: { message: 'A new rate must start after the latest rate', code: 'TAX_RATE_OVERLAP', details: { latest_effective_from: '2026-11-01' } } },
    })
    renderApp('/app/akuntansi/kode-pajak/tc-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Tarif baru' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Tarif (%)'), '10')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan tarif' }))

    expect(await dialog.findByText(/setelah tarif terakhir kode ini/)).toBeInTheDocument()
  })

  it('previews with what the server returns and sends the amount as a normalised string', async () => {
    const calls = boot(view, {
      ...loaded(),
      // Deliberately not what 11% of the typed amount would be: the page must show the server's figures, not compute its own.
      [`POST ${detail}/preview`]: {
        data: {
          tax_code: 'PPN-KELUARAN', tax_type: 'OUTPUT_TAX', calculation_method: 'EXCLUSIVE', treatment: 'STANDARD', is_recoverable: true, date: '2026-10-08', rate: '11.000000',
          entered_amount: '1000000.0000', base_amount: '900000.0000', tax_amount: '99000.0000', total_amount: '999000.0000',
        },
      },
    })
    renderApp('/app/akuntansi/kode-pajak/tc-1')
    await userEvent.type(await screen.findByLabelText('Jumlah'), '1000000')
    await userEvent.click(screen.getByRole('button', { name: 'Hitung' }))

    expect(await screen.findByText('Hasil dari server')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ amount: '1000000.0000', date: '2026-10-08' })
    expect(screen.getByText('900.000,00')).toBeInTheDocument()
    expect(screen.getByText('99.000,00')).toBeInTheDocument()
    expect(screen.getByText('999.000,00')).toBeInTheDocument()
  })

  it('does not call the server for an amount that is not a number and explains a refused preview', async () => {
    const calls = boot(view, { ...loaded(), [`POST ${detail}/preview`]: { status: 422, data: { message: 'no rate', code: 'TAX_RATE_NOT_FOUND', details: {} } } })
    renderApp('/app/akuntansi/kode-pajak/tc-1')
    const amount = await screen.findByLabelText('Jumlah')

    await userEvent.type(amount, 'abc')
    await userEvent.click(screen.getByRole('button', { name: 'Hitung' }))
    expect(await screen.findByText(/Masukkan jumlah lebih besar dari nol/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)

    await userEvent.clear(amount)
    await userEvent.type(amount, '500000')
    await userEvent.click(screen.getByRole('button', { name: 'Hitung' }))
    expect(await screen.findByText('Kode pajak ini belum memiliki tarif yang berlaku pada tanggal tersebut.')).toBeInTheDocument()
  })

  it('edits a used code without sending the settings that are fixed once used', async () => {
    const calls = boot(manage, { ...loaded({ in_use: true }), 'GET /app/accounting/accounts': { data: { data: [] } }, [`PATCH ${detail}`]: { data: taxCodeDetail({ in_use: true, name: 'PPN Keluaran 2026' }) } })
    renderApp('/app/akuntansi/kode-pajak/tc-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Ubah' }))

    const dialog = within(screen.getByRole('dialog', { name: 'Ubah kode pajak PPN-KELUARAN' }))
    expect(dialog.getByLabelText('Jenis pajak')).toBeDisabled()
    expect(dialog.getByLabelText('Metode perhitungan')).toBeDisabled()
    expect(dialog.getByLabelText('Kode')).toBeDisabled()
    await userEvent.clear(dialog.getByLabelText('Nama'))
    await userEvent.type(dialog.getByLabelText('Nama'), 'PPN Keluaran 2026')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')).toBeDefined())
    expect(calls.find((c) => c.method === 'PATCH')!.data).toEqual({ name: 'PPN Keluaran 2026', description: null, account_id: null, account_role: 'TAX_PAYABLE' })
    expect(await screen.findByText('Kode pajak disimpan.')).toBeInTheDocument()
  })

  it('deletes an unused code and returns to the list', async () => {
    const calls = boot(manage, { ...loaded(), [`DELETE ${detail}`]: { status: 204 }, 'GET /app/accounting/tax-codes': { data: page([]) } })
    renderApp('/app/akuntansi/kode-pajak/tc-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Hapus' }))
    await userEvent.click(within(screen.getByRole('dialog', { name: 'Hapus kode pajak' })).getByRole('button', { name: 'Hapus' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'DELETE')).toBeDefined())
    expect(await screen.findByText('Belum ada kode pajak')).toBeInTheDocument()
  })
})

describe('tax transactions', () => {
  const report = ['accounting.tax.report.view']
  const rows = [
    taxTransaction(),
    taxTransaction({ id: 'tx-2', source_type: 'expense', source_id: 'ex-1', line_number: 0, document_number: 'EXP-FY2026-000009', direction: 'INPUT', tax_code: 'PPN-MASUKAN', tax_name: 'PPN Masukan', counterparty_name: null, counterparty_tax_id: null }),
    taxTransaction({
      id: 'tx-3', source_type: 'ap_invoice', source_id: 'ap-1', document_number: 'AP-FY2026-000003', direction: 'INPUT', currency: 'USD', exchange_rate: '16250.5000000000',
      base_amount: '100.0000', tax_amount: '11.0000', functional_base_amount: '1625050.0000', functional_tax_amount: '178755.5000',
    }),
  ]
  const list = { 'GET /app/accounting/tax-transactions': { data: page(rows) } }

  it('lists the frozen tax lines as returned and links only the source documents the user may open', async () => {
    const calls = boot([...report, 'accounting.ar_invoice.view', 'accounting.ap_invoice.view'], list)
    renderApp('/app/akuntansi/transaksi-pajak')

    expect(await screen.findByRole('link', { name: 'AR-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan/ar-1')
    expect(screen.getByRole('link', { name: 'AP-FY2026-000003' })).toHaveAttribute('href', '/app/akuntansi/faktur-vendor/ap-1')
    // the user may not open expenses: the number is shown without a link
    expect(screen.getByText('EXP-FY2026-000009')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'EXP-FY2026-000009' })).not.toBeInTheDocument()
    expect(screen.getAllByText('110.000,00')).toHaveLength(2)
    expect(screen.getByText('USD · setara 1.625.050,00')).toBeInTheDocument()
    expect(calls.find((c) => c.url === `${AP}/tax-transactions`)!.params).toEqual({ page: 1, basis: 'posting_date', status: 'POSTED' })
  })

  it('links an expense to its own page', async () => {
    boot([...report, 'accounting.expense.view'], list)
    renderApp('/app/akuntansi/transaksi-pajak')
    expect(await screen.findByRole('link', { name: 'EXP-FY2026-000009' })).toHaveAttribute('href', '/app/akuntansi/beban/ex-1')
  })

  it('filters on the server and refuses a reversed date range without asking', async () => {
    const calls = boot([...report, 'accounting.tax.view'], { ...list, 'GET /app/accounting/tax-codes': { data: page([taxCode()]) } })
    renderApp('/app/akuntansi/transaksi-pajak')
    await screen.findByText('AR-FY2026-000001')

    await userEvent.selectOptions(screen.getByLabelText('Arah pajak'), 'OUTPUT')
    await userEvent.selectOptions(screen.getByLabelText('Sumber dokumen'), 'ar_invoice')
    await userEvent.selectOptions(await screen.findByLabelText('Kode pajak'), 'tc-1')
    await userEvent.selectOptions(screen.getByLabelText('Status transaksi pajak'), 'REVERSED')
    await userEvent.selectOptions(screen.getByLabelText('Dasar tanggal'), 'tax_date')
    await waitFor(() => expect(calls.some((c) => c.url === `${AP}/tax-transactions` && c.params?.direction === 'OUTPUT' && c.params?.source_type === 'ar_invoice' && c.params?.tax_code_id === 'tc-1' && c.params?.status === 'REVERSED' && c.params?.basis === 'tax_date')).toBe(true))

    fireEvent.change(screen.getByLabelText('Dari tanggal'), { target: { value: '2026-10-20' } })
    fireEvent.change(screen.getByLabelText('Sampai tanggal'), { target: { value: '2026-10-01' } })
    expect(await screen.findByText('Tanggal akhir tidak boleh sebelum tanggal awal.')).toBeInTheDocument()
    const listCalls = calls.filter((c) => c.url === `${AP}/tax-transactions`)
    expect(listCalls.every((c) => !(c.params?.date_from === '2026-10-20' && c.params?.date_to === '2026-10-01'))).toBe(true)
  })

  it('hides the code selector for a user who may not read tax codes', async () => {
    const calls = boot(report, list)
    renderApp('/app/akuntansi/transaksi-pajak')
    await screen.findByText('AR-FY2026-000001')
    expect(screen.queryByLabelText('Kode pajak')).not.toBeInTheDocument()
    expect(calls.some((c) => c.url === `${AP}/tax-codes`)).toBe(false)
  })
})

describe('tax report', () => {
  const allow = ['accounting.tax.report.view']
  const summary = (over: Record<string, unknown> = {}) => ({
    basis: 'posting_date',
    filters: { date_from: '2026-10-01', date_to: '2026-10-08' },
    rows: [
      { tax_code_id: 'tc-1', tax_code: 'PPN-KELUARAN', tax_name: 'PPN Keluaran', tax_type: 'OUTPUT_TAX', direction: 'OUTPUT', treatment: 'STANDARD', is_recoverable: true, rate: '11.000000', base_amount: '5000000.0000', tax_amount: '550000.0000', transactions: 3 },
      { tax_code_id: 'tc-2', tax_code: 'PPN-MASUKAN', tax_name: 'PPN Masukan', tax_type: 'INPUT_TAX', direction: 'INPUT', treatment: 'STANDARD', is_recoverable: true, rate: '11.000000', base_amount: '2000000.0000', tax_amount: '220000.0000', transactions: 2 },
      { tax_code_id: 'tc-3', tax_code: 'PPN-BIAYA', tax_name: 'PPN menjadi biaya', tax_type: 'INPUT_TAX', direction: 'INPUT', treatment: 'STANDARD', is_recoverable: false, rate: '11.000000', base_amount: '100000.0000', tax_amount: '11000.0000', transactions: 1 },
    ],
    totals: { output_base: '5000000.0000', output_tax: '550000.0000', input_base: '2100000.0000', input_tax_recoverable: '220000.0000', input_tax_non_recoverable: '11000.0000', net_payable: '330000.0000' },
    credit_note_tax: { informational: true, ar_credit_note_tax: '33000.0000', ar_credit_notes: 2 },
    complete: true,
    ...over,
  })
  const route = (over: Record<string, unknown> = {}) => ({ 'GET /app/accounting/tax-report': { data: summary(over) } })

  it('shows the summary per direction and the totals exactly as the server returned them', async () => {
    const calls = boot(allow, route())
    renderApp('/app/akuntansi/laporan-pajak')

    expect(await screen.findByText('Pajak kurang (lebih) bayar')).toBeInTheDocument()
    expect(calls.find((c) => c.url === `${AP}/tax-report`)!.params).toEqual({ date_from: '2026-10-01', date_to: '2026-10-08', basis: 'posting_date' })
    expect(screen.getByRole('table', { name: 'Pajak keluaran per kode' })).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Pajak masukan per kode' })).toBeInTheDocument()
    expect(screen.getAllByText('550.000,00').length).toBeGreaterThanOrEqual(2)
    expect(screen.getAllByText('330.000,00').length).toBeGreaterThanOrEqual(1)
    expect(screen.getByText('Kurang bayar')).toBeInTheDocument()
    expect(screen.getByText('PPN-BIAYA')).toBeInTheDocument()
    // credit-note tax is shown as information next to the report, outside the net figure
    expect(screen.getByText(/nota kredit pelanggan/)).toBeInTheDocument()
    expect(screen.queryByText(/bukan total seluruh organisasi/)).not.toBeInTheDocument()
  })

  it('warns when data scope hid part of the organisation', async () => {
    boot(allow, route({ complete: false }))
    renderApp('/app/akuntansi/laporan-pajak')
    expect(await screen.findByText(/bukan total seluruh organisasi/)).toBeInTheDocument()
  })

  it('reports a refund position as the server signs it', async () => {
    boot(allow, route({ totals: { ...summary().totals, net_payable: '-120000.0000' } }))
    renderApp('/app/akuntansi/laporan-pajak')
    expect(await screen.findByText('Lebih bayar')).toBeInTheDocument()
    expect(screen.getByText('-120.000,00')).toBeInTheDocument()
  })

  it('reloads for another basis and refuses a reversed period without asking', async () => {
    const calls = boot(allow, route())
    renderApp('/app/akuntansi/laporan-pajak')
    await screen.findByText('Pajak kurang (lebih) bayar')

    await userEvent.selectOptions(screen.getByLabelText('Dasar laporan'), 'tax_date')
    await waitFor(() => expect(calls.some((c) => c.url === `${AP}/tax-report` && c.params?.basis === 'tax_date')).toBe(true))

    const before = calls.filter((c) => c.url === `${AP}/tax-report`).length
    fireEvent.change(screen.getByLabelText('Dari tanggal'), { target: { value: '2026-10-20' } })
    expect(await screen.findByText('Tanggal akhir tidak boleh sebelum tanggal awal.')).toBeInTheDocument()
    expect(calls.filter((c) => c.url === `${AP}/tax-report`).length).toBe(before)
  })

  it('exports with the same filters, and only for users who may export', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const calls = boot([...allow, 'accounting.report.export'], { ...route(), 'GET /app/accounting/tax-report/export': { data: 'csv' } })
    renderApp('/app/akuntansi/laporan-pajak')
    await userEvent.selectOptions(await screen.findByLabelText('Arah pajak'), 'OUTPUT')
    await userEvent.click(await screen.findByRole('button', { name: 'Ekspor CSV' }))

    await waitFor(() => expect(calls.find((c) => c.url === `${AP}/tax-report/export`)).toBeDefined())
    expect(calls.find((c) => c.url === `${AP}/tax-report/export`)!.params).toEqual({ date_from: '2026-10-01', date_to: '2026-10-08', basis: 'posting_date', direction: 'OUTPUT' })
  })

  it('offers no export without the permission', async () => {
    boot(allow, route())
    renderApp('/app/akuntansi/laporan-pajak')
    await screen.findByText('Pajak kurang (lebih) bayar')
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
  })

  it('shows an empty state when nothing was posted', async () => {
    boot(allow, route({ rows: [], totals: { output_base: '0.0000', output_tax: '0.0000', input_base: '0.0000', input_tax_recoverable: '0.0000', input_tax_non_recoverable: '0.0000', net_payable: '0.0000' }, credit_note_tax: { informational: true, ar_credit_note_tax: '0.0000', ar_credit_notes: 0 } }))
    renderApp('/app/akuntansi/laporan-pajak')
    expect(await screen.findByText('Tidak ada pajak terposting')).toBeInTheDocument()
  })
})
