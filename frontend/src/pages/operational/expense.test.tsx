import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { account, boot, noDimensions } from './expense/testkit'

const AP = '/app/accounting'

const category = (over: Record<string, unknown> = {}) => ({
  id: 'c-1', code: 'UTIL', name: 'Listrik dan air', description: null, account_role: null, account_id: 'a-6300', account: { id: 'a-6300', code: '6300', name: 'Beban Utilitas' }, status: 'ACTIVE', ...over,
})
const vendor = { id: 'v-1', code: 'PLN', name: 'PT PLN', status: 'ACTIVE', payment_term_id: 't-30' }
const terms = [
  { id: 't-30', code: 'NET30', name: 'Neto 30 hari', term_type: 'NET_DAYS', due_days: 30, allows_due_date_override: false, description: null, status: 'ACTIVE' },
  { id: 't-custom', code: 'CUSTOM', name: 'Tanggal sendiri', term_type: 'CUSTOM', due_days: null, allows_due_date_override: true, description: null, status: 'ACTIVE' },
]
const bank = { id: 'b-1', code: 'BCA', name: 'BCA Operasional', kind: 'BANK', status: 'ACTIVE', currency: 'IDR', account_id: 'a-1120', bank_name: 'BCA', account_holder: null, account_number_masked: '******7890', branch_id: null, business_unit_id: null, notes: null }

const expense = (over: Record<string, unknown> = {}) => ({
  id: 'e-1', document_number: null, settlement: 'PAYABLE', status: 'DRAFT', vendor_id: 'v-1', payee_name: null, expense_category_id: 'c-1', account_id: null,
  expense_date: '2026-10-08', posting_date: '2026-10-08', due_date: '2026-11-07', payment_term_id: 't-30', due_date_overridden: false, currency: 'IDR',
  description: 'Biaya listrik Oktober', reference: null, payment_method: null, supporting_document: 'KW-001', cash_bank_account_id: null,
  branch_id: null, business_unit_id: null, cost_center_id: null, net_amount: '1000000.0000', tax_amount: '110000.0000', total_amount: '1110000.0000',
  created_by: 'u-t', posted_at: null, journal_entry_id: null, reversal_journal_id: null, reversal_reason: null, reversal_posting_date: null, reject_reason: null, cancel_reason: null,
  creator: { id: 'u-t', name: 'Budi Santoso' }, vendor, category: { id: 'c-1', code: 'UTIL', name: 'Listrik dan air', status: 'ACTIVE' },
  payment_term: { id: 't-30', code: 'NET30', name: 'Neto 30 hari' }, cash_bank_account: null,
  transitions: [{ id: 'tr-1', from_status: null, to_status: 'DRAFT', actor_user_id: 'u-t', actor: { id: 'u-t', name: 'Budi Santoso' }, reason: null, occurred_at: '2026-10-08T03:00:00Z' }],
  sod: { approve: true, post: true, approval_required: true }, ...over,
})
const posted = (over: Record<string, unknown> = {}) => expense({
  status: 'POSTED', document_number: 'EXP-FY2026-000001', journal_entry_id: 'j-1', posted_at: '2026-10-08T04:00:00Z',
  payable: { id: 'p-1', document_number: 'EXP-FY2026-000001', status: 'POSTED', due_date: '2026-11-07', total_amount: '1110000.0000', paid_amount: '400000.0000', outstanding_amount: '710000.0000', payment_status: 'PARTIALLY_PAID' },
  ...over,
})
const paid = (over: Record<string, unknown> = {}) => expense({
  settlement: 'DIRECT_PAID', vendor_id: null, vendor: null, payment_term_id: null, payment_term: null, due_date: null, cash_bank_account_id: 'b-1',
  cash_bank_account: { id: 'b-1', code: 'BCA', name: 'BCA Operasional', kind: 'BANK', bank_name: 'BCA', account_number_masked: '******7890', status: 'ACTIVE' }, payee_name: 'Operator parkir', payment_method: 'TRANSFER', ...over,
})

afterEach(() => vi.restoreAllMocks())

describe('expense categories', () => {
  const view = ['accounting.expense.view']
  const manage = [...view, 'accounting.expense_category.manage']
  const accounts = { data: [account('a-6200', '6200', 'Beban Perjalanan', { account_type: 'EXPENSE' }), account('a-6300', '6300', 'Beban Utilitas', { account_type: 'EXPENSE' }), account('a-2110', '2110', 'Utang Usaha', { account_type: 'LIABILITY', is_control: true }), account('a-6000', '6000', 'Beban (induk)', { account_type: 'EXPENSE', is_postable: false })] }
  const roles = { roles: [{ code: 'EXPENSE', name: 'Beban (umum)', binding: 'MAPPED' }, { code: 'CASH_BANK_ACCOUNT', name: 'Akun kas/bank pada dokumen', binding: 'DOCUMENT' }], mappings: [] }

  it('lists categories with their destination and hides every management control without the permission', async () => {
    boot(view, { 'GET /app/accounting/expense-categories': { data: { data: [category(), category({ id: 'c-2', code: 'OTHER', name: 'Lain-lain', account_id: null, account: null, account_role: 'EXPENSE' }), category({ id: 'c-3', code: 'FREE', name: 'Bebas', account_id: null, account: null })] } } })
    renderApp('/app/akuntansi/kategori-beban')

    expect(await screen.findByText('Listrik dan air')).toBeInTheDocument()
    expect(screen.getByText('Beban Utilitas', { exact: false })).toBeInTheDocument()
    expect(screen.getByText('Mengikuti aturan posting')).toBeInTheDocument()
    for (const label of ['Kategori baru', 'Terapkan kategori standar', 'Ubah', 'Nonaktifkan', 'Hapus']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
  })

  it('offers no management control in a read-only subscription even for a user who may manage', async () => {
    boot(manage, { 'GET /app/accounting/expense-categories': { data: { data: [category()] } } }, { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/kategori-beban')

    expect(await screen.findByText('Listrik dan air')).toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Kategori baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ubah' })).not.toBeInTheDocument()
  })

  it('searches and filters on the server', async () => {
    const calls = boot(view, { 'GET /app/accounting/expense-categories': { data: { data: [category()] } } })
    renderApp('/app/akuntansi/kategori-beban')
    await screen.findByText('Listrik dan air')

    await userEvent.selectOptions(screen.getByLabelText('Status kategori'), 'INACTIVE')
    await userEvent.type(screen.getByLabelText('Cari kategori'), 'lis')
    await waitFor(() => expect(calls.some((c) => c.url === `${AP}/expense-categories` && c.params?.q === 'lis' && c.params?.status === 'INACTIVE')).toBe(true))
  })

  it('creates a category that names an account and sends no role', async () => {
    const calls = boot(manage, {
      'GET /app/accounting/expense-categories': { data: { data: [] } },
      'GET /app/accounting/accounts': { data: accounts },
      'POST /app/accounting/expense-categories': { status: 201, data: category({ id: 'c-9', code: 'TRAVEL' }) },
    })
    renderApp('/app/akuntansi/kategori-beban')
    await userEvent.click(await screen.findByRole('button', { name: 'Kategori baru' }))

    const dialog = within(screen.getByRole('dialog', { name: 'Kategori beban baru' }))
    await userEvent.type(dialog.getByLabelText('Kode'), 'TRAVEL')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Perjalanan dinas')
    await userEvent.click(dialog.getByRole('radio', { name: 'Akun tertentu' }))
    // Only active, postable, non-control expense or asset accounts can be chosen.
    await waitFor(() => expect(dialog.getAllByRole('option').map((o) => o.textContent)).toEqual(['Pilih akun…', '6200 · Beban Perjalanan', '6300 · Beban Utilitas']))
    await userEvent.selectOptions(dialog.getByLabelText('Akun tujuan'), 'a-6200')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/expense-categories`)).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ code: 'TRAVEL', name: 'Perjalanan dinas', description: null, account_id: 'a-6200', account_role: null })
    expect(await screen.findByText('Kategori disimpan.')).toBeInTheDocument()
  })

  it('creates a category that names an account role from the API role catalog and sends no account', async () => {
    const calls = boot([...manage, 'accounting.account_mapping.view'], {
      'GET /app/accounting/expense-categories': { data: { data: [] } },
      'GET /app/accounting/accounts': { data: accounts },
      'GET /app/accounting/account-mappings': { data: roles },
      'POST /app/accounting/expense-categories': { status: 201, data: category({ id: 'c-9', code: 'GEN', account_id: null, account: null, account_role: 'EXPENSE' }) },
    })
    renderApp('/app/akuntansi/kategori-beban')
    await userEvent.click(await screen.findByRole('button', { name: 'Kategori baru' }))

    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'GEN')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Umum')
    const radio = dialog.getByRole('radio', { name: 'Peran akun (dipetakan tenant)' })
    await waitFor(() => expect(radio).toBeEnabled())
    await userEvent.click(radio)
    // A role bound to the source document has no tenant mapping, so it is not offered.
    expect(dialog.getAllByRole('option').map((o) => o.textContent)).toEqual(['Pilih peran…', 'Beban (umum)'])
    await userEvent.selectOptions(dialog.getByLabelText('Peran akun'), 'EXPENSE')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ code: 'GEN', name: 'Umum', description: null, account_id: null, account_role: 'EXPENSE' })
  })

  it('will not send an empty destination and switching away from an account drops it', async () => {
    const calls = boot(manage, { 'GET /app/accounting/expense-categories': { data: { data: [category()] } }, 'GET /app/accounting/accounts': { data: accounts }, 'PATCH /app/accounting/expense-categories/c-1': { data: category({ account_id: null, account: null }) } })
    renderApp('/app/akuntansi/kategori-beban')
    await userEvent.click(await screen.findByRole('button', { name: 'Ubah' }))
    const dialog = within(screen.getByRole('dialog'))

    expect(dialog.getByLabelText('Kode')).toBeDisabled()
    expect(dialog.getByRole('radio', { name: 'Akun tertentu' })).toBeChecked()
    await userEvent.selectOptions(dialog.getByLabelText('Akun tujuan'), '')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await dialog.findByText('Pilih akun tujuan.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'PATCH')).toBe(false)

    await userEvent.click(dialog.getByRole('radio', { name: 'Ikuti aturan posting (tanpa tujuan khusus)' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')).toBeDefined())
    expect(calls.find((c) => c.method === 'PATCH')!.data).toEqual({ name: 'Listrik dan air', description: null, account_id: null, account_role: null })
  })

  it('surfaces the refusal to delete a category that documents use and offers deactivation', async () => {
    const calls = boot(manage, {
      'GET /app/accounting/expense-categories': { data: { data: [category()] } },
      'DELETE /app/accounting/expense-categories/c-1': { status: 409, data: { message: 'used', code: 'EXPENSE_CATEGORY_IN_USE', details: {} } },
      'POST /app/accounting/expense-categories/c-1/status': { data: category({ status: 'INACTIVE' }) },
    })
    renderApp('/app/akuntansi/kategori-beban')
    await userEvent.click(await screen.findByRole('button', { name: 'Hapus' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Hapus' }))
    expect(await screen.findByText('Kategori ini dipakai beban atau baris faktur: nonaktifkan, jangan hapus.')).toBeInTheDocument()

    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Batal' }))
    await userEvent.click(screen.getByRole('button', { name: 'Nonaktifkan' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Nonaktifkan' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${AP}/expense-categories/c-1/status`)?.data).toEqual({ status: 'INACTIVE' }))
  })

  it('applies the standard categories and says what was added', async () => {
    const calls = boot(manage, { 'GET /app/accounting/expense-categories': { data: { data: [] } }, 'POST /app/accounting/expense-categories/defaults': { status: 201, data: { created: ['TRANSPORT', 'OFFICE'] } } })
    renderApp('/app/akuntansi/kategori-beban')
    await userEvent.click(await screen.findByRole('button', { name: 'Terapkan kategori standar' }))
    expect(await screen.findByText('2 kategori standar ditambahkan.')).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'GET' && c.url === `${AP}/expense-categories`).length).toBeGreaterThan(1) // reloaded
  })
})

describe('expense list', () => {
  const view = ['accounting.expense.view']
  const row = (over: Record<string, unknown> = {}) => posted(over)
  const lists = (rows: unknown[]) => ({
    'GET /app/accounting/expenses': (req: { params?: Record<string, unknown> }) => ({ data: { data: rows, current_page: Number(req.params?.page ?? 1), last_page: 3, total: 60, per_page: 25 } }),
    'GET /app/accounting/vendors': { data: page([vendor]) },
    'GET /app/accounting/expense-categories': { data: { data: [category()] } },
    'GET /app/accounting/cash-bank-accounts': { data: page([bank]) },
  })

  it('shows the amounts the server computed and links to the detail page', async () => {
    boot(view, lists([row(), paid({ id: 'e-2', status: 'DRAFT', total_amount: '200000.0000' })]))
    renderApp('/app/akuntansi/beban')

    expect(await screen.findByRole('link', { name: 'EXP-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/beban/e-1')
    expect(screen.getByText('1.110.000,00')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Draf' })).toHaveAttribute('href', '/app/akuntansi/beban/e-2')
    expect(within(screen.getByRole('table')).getByText('Dibayar langsung')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Beban baru' })).not.toBeInTheDocument()
  })

  it('sends every filter to the server and returns to page 1 when one changes', async () => {
    const calls = boot(view, lists([row()]))
    renderApp('/app/akuntansi/beban')
    await screen.findByRole('link', { name: 'EXP-FY2026-000001' })
    const expenseCalls = () => calls.filter((c) => c.method === 'GET' && c.url === `${AP}/expenses`)

    await userEvent.click(screen.getByRole('button', { name: 'Berikutnya' }))
    await waitFor(() => expect(expenseCalls().at(-1)?.params?.page).toBe(2))

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await waitFor(() => expect(expenseCalls().at(-1)?.params).toEqual({ page: 1, status: 'POSTED' }))

    await userEvent.selectOptions(screen.getByLabelText('Penyelesaian'), 'DIRECT_PAID')
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.selectOptions(screen.getByLabelText('Kategori'), 'c-1')
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'b-1')
    fireEvent.change(screen.getByLabelText('Tanggal beban dari'), { target: { value: '2026-10-01' } })
    fireEvent.change(screen.getByLabelText('Tanggal beban sampai'), { target: { value: '2026-10-31' } })
    fireEvent.change(screen.getByLabelText('Tanggal posting dari'), { target: { value: '2026-10-02' } })
    fireEvent.change(screen.getByLabelText('Tanggal posting sampai'), { target: { value: '2026-10-30' } })
    await userEvent.click(screen.getByRole('checkbox', { name: 'Buatan saya' }))
    await userEvent.type(screen.getByLabelText('Cari beban'), 'listrik')

    await waitFor(() => expect(expenseCalls().at(-1)?.params).toEqual({
      page: 1, status: 'POSTED', settlement: 'DIRECT_PAID', vendor_id: 'v-1', expense_category_id: 'c-1', cash_bank_account_id: 'b-1',
      expense_from: '2026-10-01', expense_to: '2026-10-31', posting_from: '2026-10-02', posting_to: '2026-10-30', mine: 1, q: 'listrik',
    }))
  })

  it('debounces the search box instead of querying on every keystroke', async () => {
    const calls = boot(view, lists([row()]))
    renderApp('/app/akuntansi/beban')
    await screen.findByRole('link', { name: 'EXP-FY2026-000001' })
    const before = calls.filter((c) => c.url === `${AP}/expenses`).length

    await userEvent.type(screen.getByLabelText('Cari beban'), 'abcdef')
    await waitFor(() => expect(calls.filter((c) => c.url === `${AP}/expenses`).at(-1)?.params?.q).toBe('abcdef'))
    expect(calls.filter((c) => c.url === `${AP}/expenses`).length - before).toBeLessThanOrEqual(2)
  })

  it('offers the export only to users who may export, with the same filters and no page', async () => {
    boot(view, lists([row()]))
    renderApp('/app/akuntansi/beban')
    await screen.findByRole('link', { name: 'EXP-FY2026-000001' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
  })

  it('exports with the active filters', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const calls = boot([...view, 'accounting.report.export'], { ...lists([row()]), 'GET /app/accounting/expenses/export': { data: 'csv' } })
    renderApp('/app/akuntansi/beban')
    await screen.findByRole('link', { name: 'EXP-FY2026-000001' })
    await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))

    await waitFor(() => expect(calls.find((c) => c.url === `${AP}/expenses/export`)).toBeDefined())
    expect(calls.find((c) => c.url === `${AP}/expenses/export`)!.params).toEqual({ status: 'POSTED' })
  })

  it('shows an empty state and an error with a retry', async () => {
    boot(view, { ...lists([]), 'GET /app/accounting/expenses': { data: page([]) } })
    renderApp('/app/akuntansi/beban')
    expect(await screen.findByText('Tidak ada beban')).toBeInTheDocument()
  })

  it('reports a failing list and lets the user retry', async () => {
    let fail = true
    boot(view, { ...lists([]), 'GET /app/accounting/expenses': () => (fail ? { status: 500, data: { message: 'x' } } : { data: page([row()]) }) })
    renderApp('/app/akuntansi/beban')
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('link', { name: 'EXP-FY2026-000001' })).toBeInTheDocument()
  })
})

describe('expense editor', () => {
  const permissions = ['accounting.expense.view', 'accounting.expense.create']
  const masters = {
    'GET /app/accounting/vendors': { data: page([vendor]) },
    'GET /app/accounting/payment-terms': { data: { data: terms } },
    'GET /app/accounting/expense-categories': { data: { data: [category()] } },
    'GET /app/accounting/cash-bank-accounts': { data: page([bank]) },
    'GET /app/accounting/dimensions': { data: noDimensions },
  }

  async function fillCommon() {
    await screen.findByRole('option', { name: 'UTIL · Listrik dan air' })
    await userEvent.selectOptions(screen.getByLabelText('Kategori'), 'c-1')
    await userEvent.type(screen.getByLabelText('Deskripsi'), 'Biaya listrik Oktober')
    await userEvent.type(screen.getByLabelText('Dokumen pendukung'), 'KW-9')
  }

  it('sends a payable expense with decimal strings and previews the total in exact arithmetic', async () => {
    const calls = boot(permissions, { ...masters, 'POST /app/accounting/expenses': { status: 201, data: expense() }, 'GET /app/accounting/expenses/e-1': { data: expense() } })
    renderApp('/app/akuntansi/beban/baru')
    await screen.findByRole('heading', { name: 'Beban baru' })
    await fillCommon()
    await screen.findByRole('option', { name: 'PLN · PT PLN' })
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.selectOptions(screen.getByLabelText('Termin pembayaran'), 't-custom')
    fireEvent.change(screen.getByLabelText('Jatuh tempo'), { target: { value: '2026-11-30' } })
    await userEvent.type(screen.getByLabelText('Jumlah neto'), '1000000,10')
    await userEvent.type(screen.getByLabelText('Pajak'), '0.2')

    expect(screen.getByText('1.000.000,30')).toBeInTheDocument() // 0.1 + 0.2 is exactly 0.3 here, floating point would drift
    expect(screen.getByText(/total yang tersimpan dihitung server/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/expenses`)).toBeDefined())
    const sent = calls.find((c) => c.method === 'POST' && c.url === `${AP}/expenses`)!
    expect(sent.data).toEqual({
      settlement: 'PAYABLE', expense_category_id: 'c-1', expense_date: '2026-10-08', posting_date: '2026-10-08', net_amount: '1000000.1000', tax_amount: '0.2000',
      supporting_document: 'KW-9', description: 'Biaya listrik Oktober', reference: null, branch_id: null, business_unit_id: null, cost_center_id: null,
      vendor_id: 'v-1', payment_term_id: 't-custom', due_date: '2026-11-30',
    })
    expect(JSON.stringify(sent.data)).not.toMatch(/"(net_amount|tax_amount)":\d/) // never a JSON number
    expect(await screen.findByRole('heading', { name: 'Draf beban' })).toBeInTheDocument()
  })

  it('uses the vendor term when none is chosen and locks the due date of a term that fixes it', async () => {
    const calls = boot(permissions, { ...masters, 'POST /app/accounting/expenses': { status: 201, data: expense() }, 'GET /app/accounting/expenses/e-1': { data: expense() } })
    renderApp('/app/akuntansi/beban/baru')
    await screen.findByRole('heading', { name: 'Beban baru' })
    await fillCommon()
    await screen.findByRole('option', { name: 'PLN · PT PLN' })
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    expect(screen.getByLabelText('Jatuh tempo')).toBeDisabled() // the vendor's NET30 term does not allow another date
    await userEvent.selectOptions(screen.getByLabelText('Termin pembayaran'), 't-custom')
    expect(screen.getByLabelText('Jatuh tempo')).toBeEnabled()
    await userEvent.selectOptions(screen.getByLabelText('Termin pembayaran'), '')
    await userEvent.type(screen.getByLabelText('Jumlah neto'), '500000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/expenses`)).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toMatchObject({ vendor_id: 'v-1', payment_term_id: 't-30', due_date: null, net_amount: '500000.0000', tax_amount: '0.0000' })
  })

  it('sends a directly paid expense with its cash account, payment method and payee and no vendor or term', async () => {
    const calls = boot(permissions, { ...masters, 'POST /app/accounting/expenses': { status: 201, data: paid() }, 'GET /app/accounting/expenses/e-1': { data: paid() } })
    renderApp('/app/akuntansi/beban/baru')
    await screen.findByRole('heading', { name: 'Beban baru' })
    await fillCommon()
    await userEvent.click(screen.getByRole('radio', { name: 'Dibayar langsung' }))
    expect(screen.queryByLabelText('Vendor')).not.toBeInTheDocument()
    await screen.findByRole('option', { name: 'BCA · BCA Operasional' })
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'b-1')
    await userEvent.selectOptions(screen.getByLabelText('Metode pembayaran'), 'TRANSFER')
    await userEvent.type(screen.getByLabelText('Nama penerima'), 'Operator parkir')
    await userEvent.type(screen.getByLabelText('Jumlah neto'), '200000')
    await userEvent.type(screen.getByLabelText('Pajak'), '22000')
    expect(screen.getByText('222.000,00')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/expenses`)).toBeDefined())
    const body = calls.find((c) => c.method === 'POST')!.data as Record<string, unknown>
    expect(body).toEqual({
      settlement: 'DIRECT_PAID', expense_category_id: 'c-1', expense_date: '2026-10-08', posting_date: '2026-10-08', net_amount: '200000.0000', tax_amount: '22000.0000',
      supporting_document: 'KW-9', description: 'Biaya listrik Oktober', reference: null, branch_id: null, business_unit_id: null, cost_center_id: null,
      vendor_id: null, cash_bank_account_id: 'b-1', payment_method: 'TRANSFER', payee_name: 'Operator parkir',
    })
    expect(body).not.toHaveProperty('due_date')
    expect(body).not.toHaveProperty('payment_term_id')
  })

  it('saves and submits in one step for a user who may submit', async () => {
    const calls = boot([...permissions, 'accounting.expense.submit'], { ...masters, 'POST /app/accounting/expenses': { status: 201, data: expense() }, 'POST /app/accounting/expenses/e-1/submit': { data: expense({ status: 'SUBMITTED' }) }, 'GET /app/accounting/expenses/e-1': { data: expense() } })
    renderApp('/app/akuntansi/beban/baru')
    await screen.findByRole('heading', { name: 'Beban baru' })
    await fillCommon()
    await userEvent.type(screen.getByLabelText('Jumlah neto'), '1000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${AP}/expenses/e-1/submit`)).toBe(true))
  })

  it('shows the API validation message under the field it belongs to', async () => {
    boot(permissions, { ...masters, 'POST /app/accounting/expenses': { status: 422, data: { message: 'The given data was invalid.', errors: { net_amount: ['Jumlah neto tidak valid.'], description: ['Deskripsi wajib diisi.'] } } } })
    renderApp('/app/akuntansi/beban/baru')
    await screen.findByRole('heading', { name: 'Beban baru' })
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    // The banner repeats the first message; each field carries its own.
    expect((await screen.findAllByText('Jumlah neto tidak valid.')).some((m) => m.classList.contains('error'))).toBe(true)
    expect(screen.getByLabelText('Jumlah neto')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByText('Deskripsi wajib diisi.')).toHaveClass('error')
  })

  it('attaches a business-rule refusal to the field named in its details and translates it', async () => {
    boot(permissions, { ...masters, 'POST /app/accounting/expenses': { status: 422, data: { message: 'A payable expense needs its vendor.', code: 'VENDOR_REQUIRED', details: { field: 'vendor_id' } } } })
    renderApp('/app/akuntansi/beban/baru')
    await screen.findByRole('heading', { name: 'Beban baru' })
    await userEvent.type(screen.getByLabelText('Jumlah neto'), '1000')
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    const messages = await screen.findAllByText('Vendor wajib diisi.')
    expect(messages.length).toBe(2) // the banner and the field
    expect(screen.getByLabelText('Vendor')).toHaveAttribute('aria-invalid', 'true')
  })

  it('refuses an amount that is not a plain decimal before calling the API', async () => {
    const calls = boot(permissions, masters)
    renderApp('/app/akuntansi/beban/baru')
    await screen.findByRole('heading', { name: 'Beban baru' })
    await userEvent.type(screen.getByLabelText('Jumlah neto'), '1.000.000')
    expect(screen.getByText(/Jumlah belum valid/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

    expect(await screen.findByText(/harus berupa angka tanpa pemisah ribuan/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('edits a draft with its saved values and refuses to edit a posted expense', async () => {
    const calls = boot([...permissions, 'accounting.expense.update'], { ...masters, 'GET /app/accounting/expenses/e-1': { data: expense() }, 'GET /app/accounting/expenses/e-2': { data: posted({ id: 'e-2' }) }, 'PATCH /app/accounting/expenses/e-1': { data: expense() } })
    const view = renderApp('/app/akuntansi/beban/e-1/ubah')
    await screen.findByRole('heading', { name: 'Ubah draf beban' })
    expect(screen.getByLabelText('Deskripsi')).toHaveValue('Biaya listrik Oktober')
    expect(screen.getByLabelText('Jumlah neto')).toHaveValue('1000000')
    expect(screen.getByLabelText('Pajak')).toHaveValue('110000')
    await screen.findByRole('option', { name: 'PLN · PT PLN' })
    await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH')).toBe(true))
    expect(calls.find((c) => c.method === 'PATCH')!.data).toMatchObject({ net_amount: '1000000.0000', tax_amount: '110000.0000', vendor_id: 'v-1', payment_term_id: 't-30', due_date: null })
    view.unmount()

    renderApp('/app/akuntansi/beban/e-2/ubah')
    expect(await screen.findByText('Beban tidak dapat diubah')).toBeInTheDocument()
  })
})

describe('expense detail', () => {
  const all = ['accounting.expense.view', 'accounting.expense.update', 'accounting.expense.submit', 'accounting.expense.approve', 'accounting.expense.post', 'accounting.expense.reverse']
  const buttons = () => screen.queryAllByRole('button').map((b) => b.textContent)

  it('offers edit, submit and cancel on a draft that needs approval, and neither post nor approve', async () => {
    boot(all, { 'GET /app/accounting/expenses/e-1': { data: expense() } })
    renderApp('/app/akuntansi/beban/e-1')

    expect(await screen.findByRole('heading', { name: 'Draf beban' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ubah' })).toHaveAttribute('href', '/app/akuntansi/beban/e-1/ubah')
    expect(screen.getByRole('button', { name: 'Ajukan' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Batalkan' })).toBeInTheDocument()
    for (const label of ['Posting', 'Setujui', 'Tolak', 'Balik']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
    expect(screen.getByText('1.110.000,00')).toBeInTheDocument() // total from the API
  })

  it('posts a draft directly when the policy needs no approval, unless segregation of duties forbids it', async () => {
    boot(all, { 'GET /app/accounting/expenses/e-1': { data: expense({ sod: { approve: false, post: false, approval_required: false } }) } })
    renderApp('/app/akuntansi/beban/e-1')

    expect(await screen.findByRole('button', { name: 'Posting' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Ajukan' })).not.toBeInTheDocument()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat memposting/)).toBeInTheDocument()
  })

  it('shows approve and reject disabled with the reason when the server says the user may not approve', async () => {
    boot(all, { 'GET /app/accounting/expenses/e-1': { data: expense({ status: 'SUBMITTED', sod: { approve: false, post: false, approval_required: true } }) } })
    renderApp('/app/akuntansi/beban/e-1')

    expect(await screen.findByRole('button', { name: 'Setujui' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Tolak' })).toBeDisabled()
    expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat menyetujui/)).toBeInTheDocument()
  })

  it('approves, then posts an approved expense through a confirmation', async () => {
    const calls = boot(all, {
      'GET /app/accounting/expenses/e-1': { data: expense({ status: 'APPROVED' }) },
      'POST /app/accounting/expenses/e-1/post': { data: posted() },
    })
    renderApp('/app/akuntansi/beban/e-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Posting' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Posting' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${AP}/expenses/e-1/post`)).toBe(true))
    expect(await screen.findByText('Beban diposting.')).toBeInTheDocument()
  })

  it('requires a reason to reject and sends it', async () => {
    const calls = boot(all, { 'GET /app/accounting/expenses/e-1': { data: expense({ status: 'SUBMITTED' }) }, 'POST /app/accounting/expenses/e-1/reject': { data: expense({ status: 'REJECTED' }) } })
    renderApp('/app/akuntansi/beban/e-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Tolak' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.click(dialog.getByRole('button', { name: 'Tolak' }))
    expect(calls.some((c) => c.url.endsWith('/reject'))).toBe(false)
    await userEvent.type(dialog.getByLabelText(/Alasan/), 'Kuitansi tidak jelas')
    await userEvent.click(dialog.getByRole('button', { name: 'Tolak' }))
    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/reject'))?.data).toEqual({ reason: 'Kuitansi tidak jelas' }))
  })

  it('links a posted payable expense to its payable and shows what the API says is paid and outstanding', async () => {
    boot(all, { 'GET /app/accounting/expenses/e-1': { data: posted() } })
    renderApp('/app/akuntansi/beban/e-1')

    expect(await screen.findByRole('heading', { name: 'EXP-FY2026-000001' })).toBeInTheDocument()
    const link = screen.getByRole('link', { name: 'EXP-FY2026-000001' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/faktur-vendor/p-1')
    const payable = within(screen.getByRole('heading', { name: 'Utang usaha dari beban ini' }).closest('section')!)
    expect(payable.getByText('Dibayar sebagian')).toBeInTheDocument()
    expect(payable.getByText('400.000,00')).toBeInTheDocument()
    expect(payable.getByText('710.000,00')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Lihat jurnal' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-1')
    expect(screen.getByRole('button', { name: 'Balik' })).toBeInTheDocument()
    for (const label of ['Posting', 'Batalkan', 'Ajukan']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
  })

  it('has no payable card for a directly paid expense', async () => {
    boot(all, { 'GET /app/accounting/expenses/e-1': { data: paid({ status: 'POSTED', document_number: 'EXP-FY2026-000002', journal_entry_id: 'j-2' }) } })
    renderApp('/app/akuntansi/beban/e-1')

    expect(await screen.findByRole('heading', { name: 'EXP-FY2026-000002' })).toBeInTheDocument()
    expect(screen.getByText(/BCA · BCA Operasional/)).toBeInTheDocument()
    expect(screen.getByText('******7890')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Utang usaha dari beban ini' })).not.toBeInTheDocument()
  })

  it('reverses with a reason and posting date, and shows the refusal when payments are allocated', async () => {
    const calls = boot(all, {
      'GET /app/accounting/expenses/e-1': { data: posted() },
      'POST /app/accounting/expenses/e-1/reverse': { status: 409, data: { message: 'Payments are allocated.', code: 'EXPENSE_HAS_PAYMENTS', details: {} } },
    })
    renderApp('/app/akuntansi/beban/e-1')
    await userEvent.click(await screen.findByRole('button', { name: 'Balik' }))
    const dialog = within(screen.getByRole('dialog', { name: 'Balik beban' }))
    await userEvent.type(dialog.getByLabelText(/Alasan/), 'Salah catat')
    await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))

    await waitFor(() => expect(calls.find((c) => c.url === `${AP}/expenses/e-1/reverse`)).toBeDefined())
    expect(calls.find((c) => c.url === `${AP}/expenses/e-1/reverse`)!.data).toEqual({ reason: 'Salah catat', posting_date: '2026-10-08' })
    expect(await dialog.findByText('Utang dari beban ini sudah dilunasi sebagian atau seluruhnya. Balik pembayarannya terlebih dahulu.')).toBeInTheDocument()
  })

  it('keeps the document viewable but offers no action in a read-only subscription', async () => {
    boot(all, { 'GET /app/accounting/expenses/e-1': { data: expense({ status: 'APPROVED' }) } }, { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/beban/e-1')

    expect(await screen.findByRole('heading', { name: 'Beban belum bernomor' })).toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Ubah' })).not.toBeInTheDocument()
    expect(buttons().filter((b) => ['Posting', 'Batalkan', 'Ajukan', 'Setujui', 'Balik'].includes(b ?? ''))).toEqual([])
  })

  it('hides the actions when the module of the settlement path is not writable', async () => {
    boot(all, { 'GET /app/accounting/expenses/e-1': { data: posted() } }, { modes: { ACCOUNTING_AP: 'READ_ONLY' } })
    renderApp('/app/akuntansi/beban/e-1')

    expect(await screen.findByRole('heading', { name: 'EXP-FY2026-000001' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Balik' })).not.toBeInTheDocument()
  })

  it('shows the status history', async () => {
    boot(all, { 'GET /app/accounting/expenses/e-1': { data: expense() } })
    renderApp('/app/akuntansi/beban/e-1')
    const history = within((await screen.findByRole('heading', { name: 'Riwayat' })).closest('section')!)
    expect(history.getByText('Draf')).toBeInTheDocument()
    expect(history.getByText(/Budi Santoso/)).toBeInTheDocument()
  })
})
