import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatDate } from '../../lib/format'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import {
  apEditorRoutes, apPermissions, arEditorRoutes, arPermissions, BASE, bootOa4, currencyRoutes, fillApInvoice, fillArInvoice, MISSING_RATE, optionTexts, sent, taxOut, taxPreview, usdSnapshot,
} from './oa4.testkit'
import { invoice, invoiceRow, payment, vendor } from './payables/testkit'
import { arInvoice, cashAccount, receipt } from './receivables/testkit'

// OA4 multi-currency on the AP / AR screens: the currency controls exist only for a tenant and user that may use them, the request names a
// currency but never a rate or a functional amount, and the detail pages show what the server stored.

afterEach(() => vi.restoreAllMocks())

const lookups = (calls: ReturnType<typeof bootOa4>) => calls.filter((c) => c.url === `${BASE}/exchange-rates/lookup`)
const fx = ['accounting.currency.view', 'accounting.exchange_rate.view']
const RATE_LINK = 'Masukkan kurs di halaman Kurs'
const MISSING_RATE_TEXT = /Belum ada kurs yang berlaku untuk mata uang dan tanggal ini/

describe('vendor invoice editor and currencies', () => {
  const saved = invoice({ id: 'inv-9', status: 'DRAFT', document_number: null })
  const routes = { ...apEditorRoutes, [`POST ${BASE}/ap-invoices`]: { status: 201, data: saved }, [`GET ${BASE}/ap-invoices/inv-9`]: { data: saved } }
  /** The payload of a functional invoice, exactly as before OA4. */
  const plain = {
    vendor_id: 'v-1', vendor_invoice_number: 'FP-200', document_date: '2026-10-08', posting_date: '2026-10-08', due_date: null, payment_term_id: 'term-30', description: 'Jasa konsultasi', reference: null,
    branch_id: null, business_unit_id: null, cost_center_id: null, discount_amount: null, tax_amount: null, other_charges_amount: null,
    lines: [{ description: 'Konsultasi', amount: '1000000.0000', expense_category_id: null, account_role: null, account_id: null, cost_center_id: null }],
  }

  it.each([
    ['the tenant has no multi-currency module', apPermissions, {}, false],
    ['the module is entitled but no foreign currency is registered', [...apPermissions, 'accounting.currency.view'], { fx: 'FULL' as const }, true],
  ])('keeps the payload of a functional invoice unchanged when %s', async (_why, permissions, options, shown) => {
    const calls = bootOa4(permissions, { ...routes, [`GET ${BASE}/currencies`]: { data: page([]) } }, options)
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await fillApInvoice()

    if (shown) {
      await waitFor(() => expect(sent(calls, 'GET', `${BASE}/currencies`)).toBeDefined())
      expect(optionTexts(screen.getByLabelText('Mata uang'))).toEqual(['IDR · mata uang fungsional']) // nothing to choose but the functional currency
    } else {
      expect(screen.queryByLabelText('Mata uang')).not.toBeInTheDocument()
      expect(calls.some((c) => c.url.includes('/currencies') || c.url.includes('/exchange-rates'))).toBe(false)
    }
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/ap-invoices`)).toBeDefined())
    expect(sent(calls, 'POST', `${BASE}/ap-invoices`)!.data).toEqual(plain) // no currency, exchange_rate_type or any rate key
    expect(await screen.findByRole('heading', { name: 'Draf faktur vendor' })).toBeInTheDocument()
  })

  it('asks the server for the rate, shows it, and sends the chosen currency and rate type but never a rate or a functional amount', async () => {
    const calls = bootOa4([...apPermissions, ...fx], { ...routes, ...currencyRoutes }, { fx: 'FULL' })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await fillApInvoice('1500')
    await screen.findByRole('option', { name: 'USD · Dolar Amerika Serikat' })

    expect(optionTexts(screen.getByLabelText('Mata uang'))).toEqual(['IDR · mata uang fungsional', 'USD · Dolar Amerika Serikat'])
    expect(sent(calls, 'GET', `${BASE}/currencies`)?.params).toEqual({ status: 'ACTIVE', per_page: 200 })
    expect(screen.queryByLabelText('Jenis kurs')).not.toBeInTheDocument()
    expect(lookups(calls)).toHaveLength(0) // a functional document needs no rate

    await userEvent.selectOptions(screen.getByLabelText('Mata uang'), 'USD')
    expect(optionTexts(screen.getByLabelText('Jenis kurs'))).toEqual(['Otomatis', 'Spot', 'Harian', 'Akhir bulan', 'Manual'])
    expect(screen.getByText('Dalam USD, 2 desimal.')).toBeInTheDocument()
    expect(screen.getByText(/Jumlah dalam USD\. Total fungsional dihitung server/)).toBeInTheDocument()

    const info = (await screen.findByText(/Kurs yang akan dipakai server/)).closest('p') as HTMLElement
    expect(info).toHaveTextContent(`1 USD = 16.250,50 IDR, berlaku ${formatDate('2026-10-05')}, jenis Manual (Bank Indonesia)`)
    expect(lookups(calls).at(-1)?.params).toEqual({ currency: 'USD', date: '2026-10-08' })

    await userEvent.selectOptions(screen.getByLabelText('Jenis kurs'), 'SPOT')
    await waitFor(() => expect(lookups(calls).at(-1)?.params).toEqual({ currency: 'USD', date: '2026-10-08', rate_type: 'SPOT' }))
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/ap-invoices`)).toBeDefined())
    const body = sent(calls, 'POST', `${BASE}/ap-invoices`)!.data as Record<string, unknown>
    expect(body).toEqual({ ...plain, currency: 'USD', exchange_rate_type: 'SPOT', lines: [{ ...plain.lines[0], amount: '1500.0000' }] })
    expect(Object.keys(body).filter((k) => /functional|^exchange_rate$|^rate$/.test(k))).toEqual([]) // the server chooses the rate and computes the functional amount
    expect(await screen.findByRole('heading', { name: 'Draf faktur vendor' })).toBeInTheDocument()
  })

  it('lets a read-only multi-currency module keep functional invoices only', async () => {
    bootOa4([...apPermissions, ...fx], { ...routes, ...currencyRoutes }, { fx: 'READ_ONLY' })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })

    const select = await screen.findByLabelText('Mata uang')
    expect(select).toBeDisabled()
    expect(screen.getByText(/Modul multi mata uang dalam mode hanya baca: dokumen mata uang asing tidak dapat disimpan/)).toBeInTheDocument()
    expect(optionTexts(select)).toEqual(['IDR · mata uang fungsional'])
  })

  it.each([
    ['a user who may manage rates', true],
    ['a user who may not', false],
  ])('explains a missing rate in Indonesian and links to the rate page only for %s', async (_who, manage) => {
    bootOa4([...apPermissions, ...fx, ...(manage ? ['accounting.exchange_rate.manage'] : [])], { ...routes, ...currencyRoutes, [`GET ${BASE}/exchange-rates/lookup`]: MISSING_RATE }, { fx: 'FULL' })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('option', { name: 'USD · Dolar Amerika Serikat' })
    await userEvent.selectOptions(screen.getByLabelText('Mata uang'), 'USD')

    const banner = (await screen.findByText(MISSING_RATE_TEXT)).closest('.banner, [role="alert"], div') as HTMLElement
    expect(banner).toHaveTextContent('Masukkan kurs terlebih dulu.')
    if (manage) expect(within(banner).getByRole('link', { name: RATE_LINK })).toHaveAttribute('href', '/app/akuntansi/kurs')
    else expect(screen.queryByRole('link', { name: RATE_LINK })).not.toBeInTheDocument()
  })

  it('shows a refused save for a missing rate once, with one link to the rate page for a user who may manage rates', async () => {
    const calls = bootOa4([...apPermissions, 'accounting.currency.view', 'accounting.exchange_rate.manage'], { ...routes, ...currencyRoutes, [`POST ${BASE}/ap-invoices`]: MISSING_RATE }, { fx: 'FULL' })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await fillApInvoice('1500')
    await screen.findByRole('option', { name: 'USD · Dolar Amerika Serikat' })
    await userEvent.selectOptions(screen.getByLabelText('Mata uang'), 'USD')
    expect(lookups(calls)).toHaveLength(0) // without accounting.exchange_rate.view the server decides alone
    expect(screen.queryByText(/Kurs yang akan dipakai server/)).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    expect(await screen.findByText(MISSING_RATE_TEXT)).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: RATE_LINK })).toHaveLength(1)
    expect(screen.getByRole('link', { name: RATE_LINK })).toHaveAttribute('href', '/app/akuntansi/kurs')
  })

  it('shows the rate a foreign draft was saved with, and says so explicitly when the draft goes back to the functional currency', async () => {
    const draft = invoice({
      id: 'inv-5', status: 'DRAFT', document_number: null, ...usdSnapshot, total_amount: '1500.0000', functional_total_amount: '24375750.0000',
      lines: [{ id: 'l-1', line_number: 1, description: 'Jasa konsultasi', quantity: null, unit_price: null, amount: '1500.0000', expense_category_id: null, account_role: null, account_id: null, cost_center_id: null }],
    })
    const calls = bootOa4(['accounting.ap_invoice.view', 'accounting.ap_invoice.update', 'accounting.currency.view'], {
      ...routes, ...currencyRoutes, [`GET ${BASE}/ap-invoices/inv-5`]: { data: draft }, [`PATCH ${BASE}/ap-invoices/inv-5`]: { data: draft },
    }, { fx: 'FULL' })
    renderApp('/app/akuntansi/faktur-vendor/inv-5/ubah')
    await screen.findByRole('heading', { name: 'Ubah draf faktur FP-100' })
    await screen.findByRole('option', { name: 'USD · Dolar Amerika Serikat' })

    expect(screen.getByLabelText('Mata uang')).toHaveValue('USD')
    expect(screen.getByLabelText('Jenis kurs')).toHaveValue('MANUAL')
    const snapshot = screen.getByText(/Tersimpan pada draf/)
    expect(snapshot).toHaveTextContent(`1 USD = 16.250,50 IDR, tanggal kurs ${formatDate('2026-10-05')}, jenis Manual. Total fungsional 24.375.750,00. Server menghitung ulang kurs saat draf disimpan.`)
    expect(lookups(calls)).toHaveLength(0)

    await userEvent.selectOptions(screen.getByLabelText('Mata uang'), '')
    expect(screen.queryByText(/Tersimpan pada draf/)).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Jenis kurs')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(sent(calls, 'PATCH', `${BASE}/ap-invoices/inv-5`)).toBeDefined())
    // Leaving the currency out would keep USD on the draft.
    expect(sent(calls, 'PATCH', `${BASE}/ap-invoices/inv-5`)!.data).toMatchObject({ currency: 'IDR', exchange_rate_type: null })
  })
})

describe('customer invoice editor and currencies', () => {
  const created = arInvoice({ id: 'ai-9', status: 'DRAFT', document_number: null, payment_status: null, allocations: [], credit_notes: [] })
  const routes = { ...arEditorRoutes, [`POST ${BASE}/ar-invoices`]: { status: 201, data: created }, [`GET ${BASE}/ar-invoices/ai-9`]: { data: created } }

  it('shows no currency control and sends no currency key to a user without accounting.currency.view', async () => {
    const calls = bootOa4(arPermissions, { ...routes, ...currencyRoutes }, { fx: 'FULL' })
    renderApp('/app/akuntansi/faktur-pelanggan/baru')
    await fillArInvoice()

    expect(screen.queryByLabelText('Mata uang')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/ar-invoices`)).toBeDefined())
    const body = sent(calls, 'POST', `${BASE}/ar-invoices`)!.data as Record<string, unknown>
    expect(body).not.toHaveProperty('currency')
    expect(body).not.toHaveProperty('exchange_rate_type')
    expect(calls.some((c) => c.url.includes('/currencies') || c.url.includes('/exchange-rates'))).toBe(false)
    expect(await screen.findByRole('heading', { name: 'Draf faktur pelanggan' })).toBeInTheDocument()
  })

  it('sends the currency with a tax code and leaves the tax of a foreign invoice to the server', async () => {
    const calls = bootOa4([...arPermissions, ...fx, 'accounting.tax.view'], {
      ...routes, ...currencyRoutes, [`GET ${BASE}/tax-codes`]: { data: page([taxOut]) }, [`POST ${BASE}/tax-codes/tc-out/preview`]: { data: taxPreview() },
    }, { fx: 'FULL', tax: 'FULL' })
    renderApp('/app/akuntansi/faktur-pelanggan/baru')
    await fillArInvoice('1500')
    await screen.findByRole('option', { name: 'USD · Dolar Amerika Serikat' })
    await screen.findByRole('option', { name: 'PPN-KELUARAN · PPN Keluaran' })

    await userEvent.selectOptions(screen.getByLabelText('Mata uang'), 'USD')
    await userEvent.selectOptions(screen.getByLabelText('Kode pajak baris 1'), 'tc-out')
    // The server's preview rounds at the functional scale, so a foreign invoice gets an explanation instead of a preview.
    expect(screen.getByText(/Pajak dihitung server saat disimpan, dengan tarif pada tanggal dokumen dan pembulatan sesuai mata uang dokumen/)).toBeInTheDocument()
    expect(screen.queryByText(/Pratinjau dari server/)).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/ar-invoices`)).toBeDefined())
    const body = sent(calls, 'POST', `${BASE}/ar-invoices`)!.data as { lines: Record<string, unknown>[] }
    expect(body).toMatchObject({ currency: 'USD', exchange_rate_type: null, tax_amount: null })
    expect(body.lines).toEqual([{ description: 'Barang', amount: '1500.0000', account_role: null, account_id: null, cost_center_id: null, tax_code_id: 'tc-out' }])
    expect(calls.some((c) => c.url.endsWith('/preview'))).toBe(false)
    expect(await screen.findByRole('heading', { name: 'Draf faktur pelanggan' })).toBeInTheDocument()
  })
})

describe('vendor payment editor and currencies', () => {
  const usdOpen = {
    id: 'i-usd', document_number: 'API-FY2026-000007', vendor_invoice_number: 'FP-USD', posting_date: '2026-09-01', due_date: '2026-10-15', currency: 'USD', exchange_rate: '16250.5000000000',
    total_amount: '1500.0000', paid_amount: '0.0000', outstanding_amount: '1500.0000', outstanding_functional: '24375750.0000', payment_status: 'UNPAID',
  }
  const saved = payment({ id: 'pay-9', status: 'DRAFT', document_number: null })

  it('asks for the open invoices and the allocation proposal in the payment currency and sends only the currency', async () => {
    const calls = bootOa4(['accounting.ap_payment.view', 'accounting.ap_payment.create', ...fx], {
      ...currencyRoutes,
      [`GET ${BASE}/vendors`]: { data: page([vendor()]) },
      [`GET ${BASE}/cash-bank-accounts`]: { data: page([cashAccount]) },
      [`GET ${BASE}/dimensions`]: { data: { branches: [], business_units: [], cost_centers: [] } },
      [`GET ${BASE}/vendors/v-1/open-invoices`]: (request) => ({ data: { data: request.params?.currency === 'USD' ? [usdOpen] : [] } }),
      [`GET ${BASE}/vendors/v-1/allocation-suggestion`]: { data: { data: [{ ap_invoice_id: 'i-usd', amount: '500.0000' }] } },
      [`POST ${BASE}/vendor-payments`]: { status: 201, data: saved }, [`GET ${BASE}/vendor-payments/pay-9`]: { data: saved },
    }, { fx: 'FULL' })
    renderApp('/app/akuntansi/pembayaran-vendor/baru')
    await screen.findByRole('heading', { name: 'Pembayaran vendor baru' })
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-1')
    await screen.findByText('Tidak ada faktur terbuka') // a functional payment settles functional invoices only
    expect(calls.filter((c) => c.url === `${BASE}/vendors/v-1/open-invoices`).at(-1)?.params).toBeUndefined()

    await screen.findByRole('option', { name: 'USD · Dolar Amerika Serikat' })
    await userEvent.selectOptions(screen.getByLabelText('Mata uang'), 'USD')
    const table = within(await screen.findByRole('table', { name: /Faktur/ }))
    expect(calls.filter((c) => c.url === `${BASE}/vendors/v-1/open-invoices`).at(-1)?.params).toEqual({ currency: 'USD' })
    for (const name of ['Mata uang', 'Kurs faktur', 'Saldo fungsional']) expect(table.getByRole('columnheader', { name })).toBeInTheDocument()
    const row = within(table.getByText('API-FY2026-000007').closest('tr') as HTMLElement)
    expect(row.getByText('USD')).toBeInTheDocument()
    expect(row.getByText('16.250,50')).toBeInTheDocument()
    expect(row.getByText('24.375.750,00')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Jumlah pembayaran'), '500')
    expect(screen.getByText('Dalam USD, 2 desimal.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Alokasikan otomatis' }))
    await waitFor(() => expect(screen.getByLabelText('Alokasi API-FY2026-000007')).toHaveValue('500.0000'))
    expect(sent(calls, 'GET', `${BASE}/vendors/v-1/allocation-suggestion`)?.params).toEqual({ amount: '500.0000', posting_date: '2026-10-08', currency: 'USD' })

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/vendor-payments`)).toBeDefined())
    const body = sent(calls, 'POST', `${BASE}/vendor-payments`)!.data as Record<string, unknown>
    expect(body).toMatchObject({ vendor_id: 'v-1', amount: '500.0000', currency: 'USD', exchange_rate_type: null, allocations: [{ ap_invoice_id: 'i-usd', amount: '500.0000' }] })
    expect(Object.keys(body).filter((k) => /functional|^exchange_rate$|fx_difference/.test(k))).toEqual([])
    expect(await screen.findByRole('heading', { name: 'Draf pembayaran vendor' })).toBeInTheDocument()
  })
})

describe('foreign invoices on the detail pages', () => {
  const foreignFields = { ...usdSnapshot, total_amount: '1500.0000', functional_total_amount: '24375750.0000', outstanding_functional: '24375750.0000' }
  const fact = (label: string) => within(screen.getByText(label, { selector: 'dt' }).closest('div') as HTMLElement)

  it('shows the currency, the rate the server used and the functional total of a foreign vendor invoice', async () => {
    bootOa4(['accounting.ap_invoice.view'], { [`GET ${BASE}/ap-invoices/inv-1`]: { data: invoice({ ...foreignFields }) } }, { fx: 'FULL' })
    renderApp('/app/akuntansi/faktur-vendor/inv-1')
    await screen.findByRole('heading', { name: 'API-FY2026-000001' })

    expect(fact('Mata uang').getByText('USD')).toBeInTheDocument()
    expect(fact('Kurs').getByText('16.250,50')).toBeInTheDocument()
    expect(fact('Kurs').getByText('per 1 USD')).toBeInTheDocument()
    expect(fact('Tanggal dan jenis kurs').getByText(`${formatDate('2026-10-05')} · Manual`)).toBeInTheDocument()
    expect(fact('Total (USD)').getByText('1.500,00')).toBeInTheDocument()
    expect(fact('Total fungsional').getByText('24.375.750,00')).toBeInTheDocument()
    expect(screen.getByText('Total USD', { selector: 'span' })).toHaveTextContent('1.500,00')
    expect(screen.getByText('Total fungsional', { selector: 'span' })).toHaveTextContent('24.375.750,00')
  })

  it('shows none of the rate rows for a functional vendor invoice', async () => {
    bootOa4(['accounting.ap_invoice.view'], { [`GET ${BASE}/ap-invoices/inv-1`]: { data: invoice() } }, { fx: 'FULL' })
    renderApp('/app/akuntansi/faktur-vendor/inv-1')
    await screen.findByRole('heading', { name: 'API-FY2026-000001' })

    expect(fact('Mata uang').getByText('IDR')).toBeInTheDocument()
    expect(screen.queryByText('Kurs', { selector: 'dt' })).not.toBeInTheDocument()
    expect(screen.queryByText('Tanggal dan jenis kurs', { selector: 'dt' })).not.toBeInTheDocument()
    expect(screen.queryByText(/fungsional/i)).not.toBeInTheDocument()
    expect(screen.queryByText('Kode pajak', { selector: 'th' })).not.toBeInTheDocument() // and no tax columns without a taxed line
  })

  it('shows the currency, the rate and the functional total of a foreign customer invoice, and no credit-note action', async () => {
    bootOa4(['accounting.ar_invoice.view'], { [`GET ${BASE}/ar-invoices/ai-1`]: { data: arInvoice({ ...foreignFields }) } }, { fx: 'FULL' })
    renderApp('/app/akuntansi/faktur-pelanggan/ai-1')
    await screen.findByRole('heading', { name: 'ARI-FY2026-000001' })

    expect(fact('Mata uang').getByText('USD')).toBeInTheDocument()
    expect(fact('Kurs').getByText('16.250,50')).toBeInTheDocument()
    expect(fact('Tanggal dan jenis kurs').getByText(`${formatDate('2026-10-05')} · Manual`)).toBeInTheDocument()
    expect(fact('Total (USD)').getByText('1.500,00')).toBeInTheDocument()
    expect(fact('Total fungsional').getByText('24.375.750,00')).toBeInTheDocument()
    expect(screen.getByText('Total fungsional', { selector: 'span' })).toHaveTextContent('24.375.750,00')
  })
})

describe('realised exchange difference on payment and receipt details', () => {
  const fact = (label: string) => within(screen.getByText(label, { selector: 'dt' }).closest('div') as HTMLElement)
  const settled = { carrying_amount: '8000250.0000', settlement_amount: '8125250.0000' }

  it.each([
    ['a payment above the carrying value is a loss', '125000.0000', 'Rugi selisih kurs'],
    ['a payment below the carrying value is a gain, shown without a sign', '-125000.0000', 'Laba selisih kurs'],
  ])('vendor payment: %s', async (_name, diff, label) => {
    const pay = payment({
      ...usdSnapshot, amount: '500.0000', functional_amount: '8125250.0000', fx_difference: diff,
      allocations: [{ ...payment().allocations![0], amount: '500.0000', ...settled }],
    })
    bootOa4(['accounting.ap_payment.view'], { [`GET ${BASE}/vendor-payments/pay-1`]: { data: pay } }, { fx: 'FULL' })
    renderApp('/app/akuntansi/pembayaran-vendor/pay-1')
    await screen.findByRole('heading', { name: 'PAY-FY2026-000001' })

    expect(fact(label).getByText('125.000,00')).toBeInTheDocument()
    expect(screen.queryByText(diff.startsWith('-') ? 'Rugi selisih kurs' : 'Laba selisih kurs', { selector: 'dt' })).not.toBeInTheDocument()
    expect(fact('Jumlah').getByText('USD')).toBeInTheDocument()
    expect(fact('Kurs').getByText('16.250,50')).toBeInTheDocument()
    expect(fact('Jumlah fungsional').getByText('8.125.250,00')).toBeInTheDocument()
    const table = within(screen.getByRole('table', { name: 'Alokasi pembayaran ke faktur' }))
    expect(table.getByRole('columnheader', { name: 'Nilai tercatat fungsional' })).toBeInTheDocument()
    expect(table.getByText('8.000.250,00')).toBeInTheDocument()
  })

  it.each([
    ['a receipt above the carrying value is a gain', '125000.0000', 'Laba selisih kurs'],
    ['a receipt below the carrying value is a loss, shown without a sign', '-125000.0000', 'Rugi selisih kurs'],
  ])('customer receipt: %s', async (_name, diff, label) => {
    const rec = receipt({ ...usdSnapshot, amount: '500.0000', functional_amount: '8125250.0000', fx_difference: diff })
    bootOa4(['accounting.ar_receipt.view'], { [`GET ${BASE}/customer-receipts/rc-1`]: { data: rec } }, { fx: 'FULL' })
    renderApp('/app/akuntansi/penerimaan-pelanggan/rc-1')
    await screen.findByRole('heading', { name: 'RCP-FY2026-000001' })

    expect(fact(label).getByText('125.000,00')).toBeInTheDocument()
    expect(fact('Jumlah fungsional').getByText('8.125.250,00')).toBeInTheDocument()
  })

  it('shows no exchange rows for a functional payment', async () => {
    bootOa4(['accounting.ap_payment.view'], { [`GET ${BASE}/vendor-payments/pay-1`]: { data: payment() } }, { fx: 'FULL' })
    renderApp('/app/akuntansi/pembayaran-vendor/pay-1')
    await screen.findByRole('heading', { name: 'PAY-FY2026-000001' })

    expect(fact('Mata uang').getByText('IDR')).toBeInTheDocument()
    expect(screen.queryByText(/selisih kurs/i)).not.toBeInTheDocument()
    expect(screen.queryByText('Kurs', { selector: 'dt' })).not.toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Nilai tercatat fungsional' })).not.toBeInTheDocument()
  })
})

describe('currency in the lists and the aging reports', () => {
  const buckets = [
    { key: 'current', label: 'Belum jatuh tempo', from: null, to: 0 },
    { key: 'd1_30', label: '1-30 hari', from: 1, to: 30 },
  ]
  const totals = { current: '0.0000', d1_30: '8125250.0000', total: '8125250.0000' }

  it('marks a foreign invoice in the list with its currency and functional total, and leaves a functional one plain', async () => {
    bootOa4(['accounting.ap_invoice.view'], {
      [`GET ${BASE}/ap-invoices`]: {
        data: page([
          invoiceRow({ ...usdSnapshot, currency: 'USD', total_amount: '1500.0000', outstanding_amount: '1000.0000', paid_amount: '500.0000', payment_status: 'PARTIALLY_PAID', functional_total_amount: '24375750.0000' }),
          invoiceRow({ id: 'inv-2', document_number: 'API-FY2026-000002', vendor_invoice_number: 'FP-101' }),
        ]),
      },
      [`GET ${BASE}/vendors`]: { data: page([vendor()]) },
      [`GET ${BASE}/dimensions`]: { data: { branches: [], business_units: [], cost_centers: [] } },
    }, { fx: 'FULL' })
    renderApp('/app/akuntansi/faktur-vendor')

    const foreign = within((await screen.findByRole('link', { name: 'API-FY2026-000001' })).closest('tr') as HTMLElement)
    expect(foreign.getAllByText('USD')).toHaveLength(2) // total and balance
    expect(foreign.getByText('1.500,00')).toBeInTheDocument()
    expect(foreign.getByText('1.000,00')).toBeInTheDocument()
    expect(foreign.getByText(/Fungsional/)).toHaveTextContent('24.375.750,00')
    const plain = within(screen.getByRole('link', { name: 'API-FY2026-000002' }).closest('tr') as HTMLElement)
    expect(plain.queryByText('USD')).not.toBeInTheDocument()
    expect(plain.queryByText(/Fungsional/)).not.toBeInTheDocument()
  })

  it('adds currency, rate and functional balance columns to the vendor aging detail only when an invoice is foreign', async () => {
    const row = { vendor_id: 'v-1', vendor_code: 'V1', vendor_name: 'PT Sumber Makmur', posting_date: '2026-08-01', due_date: '2026-09-20', days_overdue: 18, bucket: 'd1_30', paid_amount: '0.0000' }
    const report = (invoices: object[]) => ({
      as_of: '2026-10-08', buckets, complete: true, invoice_count: invoices.length, totals,
      data: [{ vendor_id: 'v-1', vendor_code: 'V1', vendor_name: 'PT Sumber Makmur', invoice_count: invoices.length, buckets: totals, total: totals.total }],
      invoices,
    })
    const usdRow = { ...row, id: 'inv-usd', document_number: 'API-USD-1', vendor_invoice_number: 'FP-USD', currency: 'USD', exchange_rate: '16250.5000000000', total_amount: '500.0000', outstanding_amount: '500.0000', outstanding_functional: '8125250.0000' }
    const idrRow = { ...row, id: 'inv-idr', document_number: 'API-IDR-1', vendor_invoice_number: 'FP-IDR', currency: 'IDR', exchange_rate: '1.0000000000', total_amount: '300000.0000', outstanding_amount: '300000.0000', outstanding_functional: '300000.0000' }
    const routes = { [`GET ${BASE}/vendors`]: { data: page([vendor()]) }, [`GET ${BASE}/dimensions`]: { data: { branches: [], business_units: [], cost_centers: [] } } }

    bootOa4(['accounting.ap_aging.view'], { ...routes, [`GET ${BASE}/ap-aging`]: { data: report([usdRow, idrRow]) } }, { fx: 'FULL' })
    const first = renderApp('/app/akuntansi/umur-utang')
    const table = within(await screen.findByRole('table', { name: 'Rincian umur utang per faktur' }))
    for (const name of ['Mata uang', 'Kurs', 'Saldo fungsional']) expect(table.getByRole('columnheader', { name })).toBeInTheDocument()
    expect(screen.getByText(/Nilai faktur, dibayar dan saldo memakai mata uang masing-masing faktur/)).toBeInTheDocument()
    const foreign = within(table.getByRole('link', { name: 'API-USD-1' }).closest('tr') as HTMLElement)
    expect(foreign.getByText('USD')).toBeInTheDocument()
    expect(foreign.getByText('16.250,50')).toBeInTheDocument()
    expect(foreign.getByText('8.125.250,00')).toBeInTheDocument()
    const functional = within(table.getByRole('link', { name: 'API-IDR-1' }).closest('tr') as HTMLElement)
    expect(functional.getByText('IDR')).toBeInTheDocument()
    expect(functional.queryByText('1,00')).not.toBeInTheDocument() // a functional invoice has no rate to show
    first.unmount()

    bootOa4(['accounting.ap_aging.view'], { ...routes, [`GET ${BASE}/ap-aging`]: { data: report([idrRow]) } })
    renderApp('/app/akuntansi/umur-utang')
    const plain = within(await screen.findByRole('table', { name: 'Rincian umur utang per faktur' }))
    for (const name of ['Mata uang', 'Kurs', 'Saldo fungsional']) expect(plain.queryByRole('columnheader', { name })).not.toBeInTheDocument()
    expect(screen.queryByText(/mata uang masing-masing faktur/)).not.toBeInTheDocument()
  })

  it('does the same for the customer aging detail', async () => {
    const arRow = { id: 'ai-usd', document_number: 'ARI-USD-1', customer_reference: 'PO-1', customer_id: 'c-1', customer_code: 'C1', customer_name: 'PT Pelanggan Setia', posting_date: '2026-08-01', due_date: '2026-09-20', days_overdue: 18, bucket: 'd1_30', received_amount: '0.0000', credited_amount: '0.0000' }
    const usdRow = { ...arRow, currency: 'USD', exchange_rate: '16250.5000000000', total_amount: '500.0000', outstanding_amount: '500.0000', outstanding_functional: '8125250.0000' }
    bootOa4(['accounting.ar_aging.view'], {
      [`GET ${BASE}/customers`]: { data: page([]) }, [`GET ${BASE}/dimensions`]: { data: { branches: [], business_units: [], cost_centers: [] } },
      [`GET ${BASE}/ar-aging`]: { data: {
        as_of: '2026-10-08', buckets, complete: true, invoice_count: 1, totals,
        data: [{ customer_id: 'c-1', customer_code: 'C1', customer_name: 'PT Pelanggan Setia', invoice_count: 1, buckets: totals, total: totals.total }], invoices: [usdRow],
      } },
    }, { fx: 'FULL' })
    renderApp('/app/akuntansi/umur-piutang')

    const table = within(await screen.findByRole('table', { name: /Rincian umur piutang/ }))
    for (const name of ['Mata uang', 'Kurs', 'Saldo fungsional']) expect(table.getByRole('columnheader', { name })).toBeInTheDocument()
    const row = within(table.getByRole('link', { name: 'ARI-USD-1' }).closest('tr') as HTMLElement)
    expect(row.getByText('USD')).toBeInTheDocument()
    expect(row.getByText('8.125.250,00')).toBeInTheDocument()
  })
})
