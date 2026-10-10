import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import {
  apEditorRoutes, apPermissions, arEditorRoutes, arPermissions, BASE, bootOa4, expenseDraft, expenseEditorRoutes, expensePermissions, fillApInvoice, fillArInvoice,
  optionTexts, sent, taxIn, taxOut, taxPreview,
} from './oa4.testkit'
import { invoice } from './payables/testkit'
import { arInvoice } from './receivables/testkit'

// OA4 tax codes on the AP invoice, AR invoice and expense screens: the controls show only for a user and tenant that may use tax codes, the chosen
// id is what the request carries, and every tax figure comes from the server.

afterEach(() => vi.restoreAllMocks())

const taxList = (code: typeof taxIn) => ({ [`GET ${BASE}/tax-codes`]: { data: page([code]) } })
const previewOf = (id: string, over = {}) => ({ [`POST ${BASE}/tax-codes/${id}/preview`]: { data: taxPreview(over) } })
const previewCalls = (calls: ReturnType<typeof bootOa4>, id: string) => calls.filter((c) => c.method === 'POST' && c.url === `${BASE}/tax-codes/${id}/preview`)

describe('vendor invoice editor and tax codes', () => {
  const saved = invoice({ id: 'inv-9', status: 'DRAFT', document_number: null })
  const routes = { ...apEditorRoutes, [`POST ${BASE}/ap-invoices`]: { status: 201, data: saved }, [`GET ${BASE}/ap-invoices/inv-9`]: { data: saved } }
  const taxed = { ...routes, ...taxList(taxIn), ...previewOf('tc-in') }
  const header = { vendor_id: 'v-1', vendor_invoice_number: 'FP-200', document_date: '2026-10-08', posting_date: '2026-10-08', due_date: null, payment_term_id: 'term-30', description: 'Jasa konsultasi', reference: null, branch_id: null, business_unit_id: null, cost_center_id: null }
  const line = { account_role: null, account_id: null, cost_center_id: null, expense_category_id: null }

  it('offers an input tax code per line, sends the chosen id and leaves the tax to the server', async () => {
    const calls = bootOa4([...apPermissions, 'accounting.tax.view'], taxed, { tax: 'FULL' })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await fillApInvoice()
    await userEvent.click(screen.getByRole('button', { name: 'Tambah baris' }))
    await userEvent.type(screen.getByLabelText('Deskripsi baris 2'), 'Kertas A4')
    await userEvent.type(screen.getByLabelText('Jumlah baris 2'), '200000')

    expect(sent(calls, 'GET', `${BASE}/tax-codes`)?.params).toEqual({ status: 'ACTIVE', direction: 'INPUT', per_page: 200 })
    expect(await screen.findAllByRole('option', { name: 'PPN-MASUKAN · PPN Masukan' })).toHaveLength(2) // one select per line
    expect(optionTexts(screen.getByLabelText('Kode pajak baris 1'))).toEqual(['Tanpa kode pajak', 'PPN-MASUKAN · PPN Masukan'])
    expect(screen.getByLabelText('Kode pajak baris 2')).toBeInTheDocument()

    // Before a code is chosen the invoice is a plain one: a manual tax and the browser's own total preview.
    expect(screen.getByLabelText('Pajak')).toBeEnabled()
    expect(screen.getByText(/Total pratinjau/)).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('Kode pajak baris 1'), 'tc-in')
    expect(screen.getByLabelText('Pajak')).toBeDisabled()
    expect(screen.getByLabelText('Pajak')).toHaveValue('')
    expect(screen.getByText('Dihitung server dari kode pajak pada baris; tidak diisi manual.')).toBeInTheDocument()
    expect(screen.getByText(/Subtotal, pajak, dan total dihitung server dari kode pajak pada baris saat faktur disimpan/)).toBeInTheDocument()
    expect(screen.queryByText(/Total pratinjau/)).not.toBeInTheDocument() // no total is computed in the browser for a taxed invoice

    // The only tax figure on the page is the server's preview, labelled as one.
    const note = (await screen.findByText(/Pratinjau dari server/)).closest('p') as HTMLElement
    expect(note).toHaveTextContent(/PPN-MASUKAN 11%/)
    expect(note).toHaveTextContent(/dasar 1\.000\.000,00/)
    expect(note).toHaveTextContent(/pajak 110\.000,00/)
    expect(note).toHaveTextContent(/total 1\.110\.000,00/)
    expect(previewCalls(calls, 'tc-in').at(-1)?.data).toEqual({ amount: '1000000.0000', date: '2026-10-08' })

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/ap-invoices`)).toBeDefined())
    const body = sent(calls, 'POST', `${BASE}/ap-invoices`)!.data as { lines: Record<string, unknown>[] }
    expect(body).toEqual({
      ...header, discount_amount: null, tax_amount: null, other_charges_amount: null,
      lines: [
        { ...line, description: 'Konsultasi', amount: '1000000.0000', tax_code_id: 'tc-in' },
        { ...line, description: 'Kertas A4', amount: '200000.0000' },
      ],
    })
    expect(body.lines[1]).not.toHaveProperty('tax_code_id') // a line without a code carries no key at all
    expect(await screen.findByRole('heading', { name: 'Draf faktur vendor' })).toBeInTheDocument()
  })

  it('leaves the editor and its payload exactly as before for a tenant without the tax module', async () => {
    const calls = bootOa4([...apPermissions, 'accounting.tax.view'], taxed)
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await fillApInvoice()
    await userEvent.type(screen.getByLabelText('Pajak'), '110000')

    expect(screen.queryByLabelText('Kode pajak baris 1')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Pajak')).toBeEnabled()
    expect(screen.getByText('Jumlah pajak sesuai faktur vendor.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/ap-invoices`)).toBeDefined())
    expect(sent(calls, 'POST', `${BASE}/ap-invoices`)!.data).toEqual({
      ...header, discount_amount: null, tax_amount: '110000.0000', other_charges_amount: null,
      lines: [{ ...line, description: 'Konsultasi', amount: '1000000.0000' }],
    })
    expect(calls.some((c) => c.url.includes('/tax-codes'))).toBe(false)
    expect(await screen.findByRole('heading', { name: 'Draf faktur vendor' })).toBeInTheDocument()
  })

  it.each([
    ['the user may not read tax codes', apPermissions, 'FULL' as const, undefined],
    ['the tax module is read-only', [...apPermissions, 'accounting.tax.view'], 'READ_ONLY' as const, undefined],
    ['the server refuses the tax codes of this tenant', [...apPermissions, 'accounting.tax.view'], 'FULL' as const, { status: 403, data: { code: 'FEATURE_NOT_ENTITLED', message: 'x' } }],
  ])('shows no tax control when %s', async (_why, permissions, tax, refusal) => {
    const calls = bootOa4(permissions, { ...taxed, ...(refusal && { [`GET ${BASE}/tax-codes`]: refusal }) }, { tax })
    renderApp('/app/akuntansi/faktur-vendor/baru')
    await screen.findByRole('heading', { name: 'Faktur vendor baru' })

    if (refusal) await waitFor(() => expect(sent(calls, 'GET', `${BASE}/tax-codes`)).toBeDefined())
    else expect(calls.some((c) => c.url === `${BASE}/tax-codes`)).toBe(false) // nothing is requested when the select cannot show
    await waitFor(() => expect(screen.queryByLabelText('Kode pajak baris 1')).not.toBeInTheDocument())
    expect(screen.getByLabelText('Pajak')).toBeEnabled()
  })
})

describe('customer invoice editor and tax codes', () => {
  const created = arInvoice({ id: 'ai-9', status: 'DRAFT', document_number: null, payment_status: null, allocations: [], credit_notes: [] })

  it('offers output tax codes, sends the chosen id and leaves the tax to the server', async () => {
    const calls = bootOa4([...arPermissions, 'accounting.tax.view'], {
      ...arEditorRoutes, ...taxList(taxOut), ...previewOf('tc-out', { tax_code: 'PPN-KELUARAN', tax_type: 'OUTPUT_TAX' }),
      [`POST ${BASE}/ar-invoices`]: { status: 201, data: created }, [`GET ${BASE}/ar-invoices/ai-9`]: { data: created },
    }, { tax: 'FULL' })
    renderApp('/app/akuntansi/faktur-pelanggan/baru')
    await fillArInvoice()

    expect(sent(calls, 'GET', `${BASE}/tax-codes`)?.params).toEqual({ status: 'ACTIVE', direction: 'OUTPUT', per_page: 200 })
    await screen.findByRole('option', { name: 'PPN-KELUARAN · PPN Keluaran' })
    expect(screen.getByLabelText('Pajak')).toBeEnabled()
    await userEvent.selectOptions(screen.getByLabelText('Kode pajak baris 1'), 'tc-out')

    expect(screen.getByLabelText('Pajak')).toBeDisabled()
    expect(screen.getByText('Dihitung server dari kode pajak pada baris; tidak diisi manual.')).toBeInTheDocument()
    expect(screen.getByText(/Subtotal, pajak, dan total dihitung server dari kode pajak pada baris/)).toBeInTheDocument()
    expect(await screen.findByText(/Pratinjau dari server/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/ar-invoices`)).toBeDefined())
    expect(sent(calls, 'POST', `${BASE}/ar-invoices`)!.data).toEqual({
      customer_id: 'c-1', customer_reference: null, document_date: '2026-10-08', posting_date: '2026-10-08', due_date: null, payment_term_id: 'term-30', description: 'Penjualan barang', reference: null,
      branch_id: null, business_unit_id: null, cost_center_id: null, discount_amount: null, tax_amount: null, other_charges_amount: null,
      lines: [{ description: 'Barang', amount: '1000000.0000', account_role: null, account_id: null, cost_center_id: null, tax_code_id: 'tc-out' }],
    })
    expect(await screen.findByRole('heading', { name: 'Draf faktur pelanggan' })).toBeInTheDocument()
  })

  it('shows no tax control and sends no tax code without accounting.tax.view', async () => {
    const calls = bootOa4(arPermissions, {
      ...arEditorRoutes, ...taxList(taxOut), [`POST ${BASE}/ar-invoices`]: { status: 201, data: created }, [`GET ${BASE}/ar-invoices/ai-9`]: { data: created },
    }, { tax: 'FULL' })
    renderApp('/app/akuntansi/faktur-pelanggan/baru')
    await fillArInvoice()
    await userEvent.type(screen.getByLabelText('Pajak'), '110000')

    expect(screen.queryByLabelText('Kode pajak baris 1')).not.toBeInTheDocument()
    expect(screen.getByText('Jumlah pajak keluaran sesuai faktur.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/ar-invoices`)).toBeDefined())
    const body = sent(calls, 'POST', `${BASE}/ar-invoices`)!.data as { tax_amount: string | null; lines: Record<string, unknown>[] }
    expect(body.tax_amount).toBe('110000.0000')
    expect(body.lines[0]).not.toHaveProperty('tax_code_id')
    expect(calls.some((c) => c.url.includes('/tax-codes'))).toBe(false)
    expect(await screen.findByRole('heading', { name: 'Draf faktur pelanggan' })).toBeInTheDocument()
  })
})

describe('expense editor and tax codes', () => {
  const draft = expenseDraft()
  const routes = { ...expenseEditorRoutes, [`POST ${BASE}/expenses`]: { status: 201, data: draft }, [`GET ${BASE}/expenses/e-1`]: { data: draft } }

  async function fillExpense() {
    await screen.findByRole('heading', { name: 'Beban baru' })
    await screen.findByRole('option', { name: 'JASA · Jasa profesional' })
    await screen.findByRole('option', { name: 'V1 · PT Sumber Makmur' })
    await userEvent.selectOptions(screen.getByLabelText('Kategori'), 'cat-1')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'Biaya listrik Oktober')
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.type(screen.getByLabelText('Jumlah neto'), '1000000')
  }

  it('offers an input tax code, sends its id and leaves the tax to the server', async () => {
    const calls = bootOa4([...expensePermissions, 'accounting.tax.view'], { ...routes, ...taxList(taxIn), ...previewOf('tc-in') }, { tax: 'FULL' })
    renderApp('/app/akuntansi/beban/baru')
    await fillExpense()

    expect(sent(calls, 'GET', `${BASE}/tax-codes`)?.params).toEqual({ status: 'ACTIVE', direction: 'INPUT', per_page: 200 })
    await screen.findByRole('option', { name: 'PPN-MASUKAN · PPN Masukan' })
    expect(screen.getByLabelText('Pajak')).toBeEnabled()
    await userEvent.selectOptions(screen.getByLabelText('Kode pajak'), 'tc-in')

    expect(screen.getByLabelText('Pajak')).toBeDisabled()
    expect(screen.getByText('Dihitung server dari kode pajak; tidak diisi manual.')).toBeInTheDocument()
    expect(screen.queryByText(/Pratinjau total/)).not.toBeInTheDocument() // the browser's net + tax preview gives way to the server's
    expect(await screen.findByText(/Pratinjau dari server/)).toBeInTheDocument()
    expect(previewCalls(calls, 'tc-in').at(-1)?.data).toEqual({ amount: '1000000.0000', date: '2026-10-08' })

    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/expenses`)).toBeDefined())
    expect(sent(calls, 'POST', `${BASE}/expenses`)!.data).toEqual({
      settlement: 'PAYABLE', expense_category_id: 'cat-1', expense_date: '2026-10-08', posting_date: '2026-10-08', net_amount: '1000000.0000', tax_amount: null, tax_code_id: 'tc-in',
      supporting_document: null, description: 'Biaya listrik Oktober', reference: null, branch_id: null, business_unit_id: null, cost_center_id: null,
      vendor_id: 'v-1', payment_term_id: 'term-30', due_date: null,
    })
    expect(await screen.findByRole('heading', { name: 'Draf beban' })).toBeInTheDocument()
  })

  it('sends the same payload as before to a tenant without the tax module', async () => {
    const calls = bootOa4([...expensePermissions, 'accounting.tax.view'], { ...routes, ...taxList(taxIn) })
    renderApp('/app/akuntansi/beban/baru')
    await fillExpense()
    await userEvent.type(screen.getByLabelText('Pajak'), '110000')

    expect(screen.queryByLabelText('Kode pajak')).not.toBeInTheDocument()
    expect(screen.getByText(/Pratinjau total/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(sent(calls, 'POST', `${BASE}/expenses`)).toBeDefined())
    const body = sent(calls, 'POST', `${BASE}/expenses`)!.data
    expect(body).toMatchObject({ net_amount: '1000000.0000', tax_amount: '110000.0000' })
    expect(body).not.toHaveProperty('tax_code_id')
    expect(calls.some((c) => c.url.includes('/tax-codes'))).toBe(false)
    expect(await screen.findByRole('heading', { name: 'Draf beban' })).toBeInTheDocument()
  })

  it('shows the amount that was entered for a taxed draft and says so explicitly when the code is removed', async () => {
    const taxedDraft = expenseDraft({ tax_code_id: 'tc-in', entered_amount: '1110000.0000', net_amount: '1000000.0000', tax_amount: '110000.0000', total_amount: '1110000.0000' })
    const calls = bootOa4([...expensePermissions, 'accounting.expense.update', 'accounting.tax.view'], {
      ...expenseEditorRoutes, ...taxList(taxIn), ...previewOf('tc-in'),
      [`GET ${BASE}/expenses/e-1`]: { data: taxedDraft }, [`PATCH ${BASE}/expenses/e-1`]: { data: expenseDraft() },
    }, { tax: 'FULL' })
    renderApp('/app/akuntansi/beban/e-1/ubah')
    await screen.findByRole('heading', { name: 'Ubah draf beban' })
    await screen.findByRole('option', { name: 'PPN-MASUKAN · PPN Masukan' })

    expect(screen.getByLabelText('Kode pajak')).toHaveValue('tc-in')
    expect(screen.getByLabelText('Jumlah neto')).toHaveValue('1110000') // what was typed, not the server's base
    expect(screen.getByLabelText('Pajak')).toBeDisabled()
    expect(screen.getByLabelText('Pajak')).toHaveValue('') // the stored tax is the server's calculation: never sent back

    await userEvent.selectOptions(screen.getByLabelText('Kode pajak'), '')
    expect(screen.getByLabelText('Pajak')).toBeEnabled()
    await screen.findByRole('option', { name: 'V1 · PT Sumber Makmur' })
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(sent(calls, 'PATCH', `${BASE}/expenses/e-1`)).toBeDefined())
    const body = sent(calls, 'PATCH', `${BASE}/expenses/e-1`)!.data
    expect(body).toHaveProperty('tax_code_id', null) // leaving the key out would keep the old code
    expect(body).toMatchObject({ net_amount: '1110000.0000', tax_amount: '0.0000' })
  })
})

describe('vendor invoice detail and tax codes', () => {
  const taxedInvoice = (over = {}) => invoice({
    id: 'inv-1', tax_amount: '110000.0000', total_amount: '1110000.0000',
    lines: [{ id: 'l-1', line_number: 1, description: 'Jasa konsultasi', quantity: null, unit_price: null, amount: '1000000.0000', entered_amount: '1000000.0000', tax_code_id: 'tc-in', expense_category_id: null, account_role: null, account_id: null, cost_center_id: null }],
    ...over,
  })

  it('adds the tax code, rate and tax columns from the server when a line is taxed', async () => {
    const calls = bootOa4(['accounting.ap_invoice.view', 'accounting.tax.view'], { [`GET ${BASE}/ap-invoices/inv-1`]: { data: taxedInvoice() }, ...previewOf('tc-in') }, { tax: 'FULL' })
    renderApp('/app/akuntansi/faktur-vendor/inv-1')

    const table = within(await screen.findByRole('table', { name: 'Baris faktur' }))
    for (const name of ['Kode pajak', 'Pajak', 'Dasar (jumlah)']) expect(table.getByRole('columnheader', { name })).toBeInTheDocument()
    const row = within((await table.findByText('PPN-MASUKAN', { exact: false })).closest('tr') as HTMLElement)
    expect(row.getByText(/PPN-MASUKAN/)).toHaveTextContent('PPN-MASUKAN · 11%')
    expect(row.getByText('110.000,00')).toBeInTheDocument() // the server's tax for the line
    expect(row.getByText('1.000.000,00')).toBeInTheDocument()
    expect(screen.getByText(/Kode pajak, tarif dan pajak per baris adalah hitungan server/)).toBeInTheDocument()
    expect(previewCalls(calls, 'tc-in')[0].data).toEqual({ amount: '1000000.0000', date: '2026-09-01' })
  })

  it('shows no tax columns for an untaxed invoice or to a user who may not read tax codes', async () => {
    bootOa4(['accounting.ap_invoice.view', 'accounting.tax.view'], { [`GET ${BASE}/ap-invoices/inv-1`]: { data: invoice() } }, { tax: 'FULL' })
    const first = renderApp('/app/akuntansi/faktur-vendor/inv-1')
    const table = within(await screen.findByRole('table', { name: 'Baris faktur' }))
    expect(table.getByRole('columnheader', { name: 'Jumlah' })).toBeInTheDocument()
    expect(table.queryByRole('columnheader', { name: 'Kode pajak' })).not.toBeInTheDocument()
    first.unmount()

    // A taxed line the user cannot look up still says it carries a code, without inventing a rate or an amount.
    const calls = bootOa4(['accounting.ap_invoice.view'], { [`GET ${BASE}/ap-invoices/inv-1`]: { data: taxedInvoice() } }, { tax: 'FULL' })
    renderApp('/app/akuntansi/faktur-vendor/inv-1')
    const taxed = within(await screen.findByRole('table', { name: 'Baris faktur' }))
    expect(taxed.getByText('Berkode pajak')).toBeInTheDocument()
    expect(calls.some((c) => c.url.includes('/tax-codes'))).toBe(false)
  })
})
