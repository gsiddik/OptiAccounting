import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { account, boot, noDimensions } from './expense/testkit'

const AP = '/app/accounting'

const bank = (over: Record<string, unknown> = {}) => ({
  id: 'b-1', code: 'BCA', name: 'BCA Operasional', kind: 'BANK', status: 'ACTIVE', currency: 'IDR', account_id: 'a-1120',
  gl_account: { id: 'a-1120', code: '1120', name: 'Bank', account_type: 'ASSET', status: 'ACTIVE' }, bank_name: 'BCA', account_holder: 'PT Maju Jaya', account_number_masked: '******7890',
  branch_id: null, business_unit_id: null, branch: null, business_unit: null, notes: null, book_balance: '1500000.0000', ...over,
})
const kas = (over: Record<string, unknown> = {}) => bank({
  id: 'b-2', code: 'KAS', name: 'Kas Kecil', kind: 'CASH', account_id: 'a-1110', gl_account: { id: 'a-1110', code: '1110', name: 'Kas', account_type: 'ASSET', status: 'ACTIVE' },
  bank_name: null, account_holder: null, account_number_masked: null, book_balance: '-222000.0000', ...over,
})

const gl = [
  account('a-1110', '1110', 'Kas'),
  account('a-1120', '1120', 'Bank'),
  account('a-1130', '1130', 'Piutang Usaha', { is_control: true }),
  account('a-1140', '1140', 'Persediaan'),
  account('a-1000', '1000', 'Aset (induk)', { is_postable: false }),
  account('a-1199', '1199', 'Kas lama', { status: 'INACTIVE' }),
  account('a-6900', '6900', 'Beban Umum', { account_type: 'EXPENSE' }),
  account('a-4100', '4100', 'Penjualan', { account_type: 'REVENUE', normal_balance: 'CREDIT' }),
]

const transaction = (kind: 'PAYMENT' | 'RECEIPT', over: Record<string, unknown> = {}) => ({
  id: 't-1', document_number: null, kind, status: 'DRAFT', cash_bank_account_id: 'b-1', counter_account_id: 'a-6900', transaction_date: '2026-10-08', posting_date: '2026-10-08',
  currency: 'IDR', amount: '250000.0000', purpose: 'Bayar parkir', description: 'Parkir kantor', reference: null, counterparty_name: 'Operator parkir', branch_id: null, business_unit_id: null, cost_center_id: null,
  created_by: 'u-t', posted_at: null, journal_entry_id: null, reversal_journal_id: null, reversal_reason: null, reversal_posting_date: null, cancel_reason: null,
  creator: { id: 'u-t', name: 'Budi Santoso' },
  cash_bank_account: { id: 'b-1', code: 'BCA', name: 'BCA Operasional', kind: 'BANK', bank_name: 'BCA', account_number_masked: '******7890', status: 'ACTIVE' },
  counter_account: { id: 'a-6900', code: '6900', name: 'Beban Umum', account_type: 'EXPENSE' },
  transitions: [{ id: 'tr-1', from_status: null, to_status: 'DRAFT', actor_user_id: 'u-t', actor: { id: 'u-t', name: 'Budi Santoso' }, reason: null, occurred_at: '2026-10-08T03:00:00Z' }],
  sod: { approve: false, post: true }, ...over,
})

afterEach(() => vi.restoreAllMocks())

describe('cash and bank accounts', () => {
  const view = ['accounting.cash_bank.view']
  const manage = [...view, 'accounting.cash_bank.manage']
  const masters = { 'GET /app/accounting/accounts': { data: { data: gl } }, 'GET /app/accounting/dimensions': { data: noDimensions } }

  it('shows the book balance the API derived from the ledger, only the masked number, and links to the detail page', async () => {
    boot(view, { 'GET /app/accounting/cash-bank-accounts': { data: page([bank(), kas()]) } })
    renderApp('/app/akuntansi/kas-bank')

    expect(await screen.findByRole('link', { name: 'BCA' })).toHaveAttribute('href', '/app/akuntansi/kas-bank/b-1')
    const table = within(screen.getByRole('table'))
    expect(table.getByText('1.500.000,00')).toBeInTheDocument()
    expect(table.getByText('-222.000,00')).toBeInTheDocument()
    expect(table.getByText('******7890')).toBeInTheDocument()
    expect(table.getByText('1120', { exact: false })).toBeInTheDocument()
    // The balance is display only: nothing in the list can edit it.
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument()
    for (const label of ['Akun kas/bank baru', 'Ubah', 'Nonaktifkan', 'Hapus']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
  })

  it('filters by kind, status and search on the server and returns to page 1', async () => {
    const calls = boot(view, { 'GET /app/accounting/cash-bank-accounts': (req) => ({ data: { data: [bank()], current_page: Number(req.params?.page ?? 1), last_page: 2, total: 80, per_page: 50 } }) })
    renderApp('/app/akuntansi/kas-bank')
    await screen.findByRole('link', { name: 'BCA' })
    const listCalls = () => calls.filter((c) => c.method === 'GET' && c.url === `${AP}/cash-bank-accounts`)

    await userEvent.click(screen.getByRole('button', { name: 'Berikutnya' }))
    await waitFor(() => expect(listCalls().at(-1)?.params?.page).toBe(2))
    await userEvent.selectOptions(screen.getByLabelText('Jenis akun'), 'BANK')
    await waitFor(() => expect(listCalls().at(-1)?.params).toEqual({ page: 1, kind: 'BANK' }))
    await userEvent.selectOptions(screen.getByLabelText('Status akun'), 'INACTIVE')
    await userEvent.type(screen.getByLabelText('Cari akun kas/bank'), 'bca')
    await waitFor(() => expect(listCalls().at(-1)?.params).toEqual({ page: 1, kind: 'BANK', status: 'INACTIVE', q: 'bca' }))
  })

  it('creates a bank account: sends the account number once, then shows only the masked value', async () => {
    let rows = [kas()]
    const calls = boot(manage, {
      ...masters,
      'GET /app/accounting/cash-bank-accounts': () => ({ data: page(rows) }),
      'POST /app/accounting/cash-bank-accounts': () => {
        rows = [bank({ id: 'b-9', code: 'MANDIRI', name: 'Mandiri Gaji', account_number_masked: '******7890' }), ...rows]
        return { status: 201, data: rows[0] }
      },
    })
    renderApp('/app/akuntansi/kas-bank')
    await userEvent.click(await screen.findByRole('button', { name: 'Akun kas/bank baru' }))
    const dialog = within(screen.getByRole('dialog', { name: 'Akun kas/bank baru' }))

    await userEvent.type(dialog.getByLabelText('Kode'), 'MANDIRI')
    await userEvent.selectOptions(dialog.getByLabelText('Jenis'), 'BANK')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Mandiri Gaji')
    // Postable, active, non-control asset accounts only.
    await waitFor(() => expect(dialog.getAllByRole('option', { name: /·/ }).map((o) => o.textContent)).toEqual(['1110 · Kas', '1120 · Bank', '1140 · Persediaan']))
    await userEvent.selectOptions(dialog.getByLabelText('Akun buku besar'), 'a-1120')
    await userEvent.type(dialog.getByLabelText('Nama bank'), 'Mandiri')
    await userEvent.type(dialog.getByLabelText('Pemilik rekening'), 'PT Maju Jaya')
    await userEvent.type(dialog.getByLabelText('Nomor rekening'), '1234567890')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({
      code: 'MANDIRI', kind: 'BANK', name: 'Mandiri Gaji', account_id: 'a-1120', bank_name: 'Mandiri', account_holder: 'PT Maju Jaya', account_number: '1234567890',
      notes: null, branch_id: null, business_unit_id: null,
    })
    expect(await screen.findByRole('link', { name: 'MANDIRI' })).toBeInTheDocument()
    expect(screen.getAllByText('******7890').length).toBeGreaterThan(0)
    expect(document.body.textContent).not.toContain('1234567890')
  })

  it('creates a cash account without any bank field', async () => {
    const calls = boot(manage, { ...masters, 'GET /app/accounting/cash-bank-accounts': { data: page([]) }, 'POST /app/accounting/cash-bank-accounts': { status: 201, data: kas() } })
    renderApp('/app/akuntansi/kas-bank')
    await userEvent.click(await screen.findByRole('button', { name: 'Akun kas/bank baru' }))
    const dialog = within(screen.getByRole('dialog'))

    expect(dialog.queryByLabelText('Nomor rekening')).not.toBeInTheDocument() // a cash box has no bank details
    await userEvent.type(dialog.getByLabelText('Kode'), 'KAS')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Kas Kecil')
    await waitFor(() => expect(dialog.getByRole('option', { name: '1110 · Kas' })).toBeInTheDocument())
    await userEvent.selectOptions(dialog.getByLabelText('Akun buku besar'), 'a-1110')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ code: 'KAS', kind: 'CASH', name: 'Kas Kecil', account_id: 'a-1110', notes: null, branch_id: null, business_unit_id: null })
  })

  it('asks for a GL account before calling the API', async () => {
    const calls = boot(manage, { ...masters, 'GET /app/accounting/cash-bank-accounts': { data: page([]) } })
    renderApp('/app/akuntansi/kas-bank')
    await userEvent.click(await screen.findByRole('button', { name: 'Akun kas/bank baru' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'KAS')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await dialog.findByText('Pilih akun buku besar.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('shows the API refusal in Indonesian when the GL account already belongs to another active account', async () => {
    boot(manage, {
      ...masters,
      'GET /app/accounting/cash-bank-accounts': { data: page([]) },
      'POST /app/accounting/cash-bank-accounts': { status: 422, data: { message: 'Another active cash or bank account already uses this GL account.', code: 'CASH_BANK_GL_ACCOUNT_TAKEN', details: {} } },
    })
    renderApp('/app/akuntansi/kas-bank')
    await userEvent.click(await screen.findByRole('button', { name: 'Akun kas/bank baru' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'KAS2')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Kas lain')
    await waitFor(() => expect(dialog.getByRole('option', { name: '1110 · Kas' })).toBeInTheDocument())
    await userEvent.selectOptions(dialog.getByLabelText('Akun buku besar'), 'a-1110')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await dialog.findByText('Akun buku besar ini sudah dipakai akun kas/bank aktif lain.')).toBeInTheDocument()
  })

  it('edits without the fixed code and kind, never prefills the number and sends only what changed', async () => {
    const calls = boot(manage, { ...masters, 'GET /app/accounting/cash-bank-accounts': { data: page([bank()]) }, 'PATCH /app/accounting/cash-bank-accounts/b-1': { data: bank({ name: 'BCA Utama' }) } })
    renderApp('/app/akuntansi/kas-bank')
    await userEvent.click(await screen.findByRole('button', { name: 'Ubah' }))
    const dialog = within(screen.getByRole('dialog', { name: 'Ubah akun BCA' }))

    expect(dialog.getByLabelText('Kode')).toBeDisabled()
    expect(dialog.getByLabelText('Jenis')).toBeDisabled()
    expect(dialog.getByLabelText('Nomor rekening')).toHaveValue('')
    expect(dialog.getByText(/Tersimpan: \*{6}7890/)).toBeInTheDocument()
    await waitFor(() => expect(dialog.getByRole('option', { name: '1120 · Bank' })).toBeInTheDocument())
    expect(dialog.getByLabelText('Akun buku besar')).toHaveValue('a-1120')
    await userEvent.clear(dialog.getByLabelText('Nama'))
    await userEvent.type(dialog.getByLabelText('Nama'), 'BCA Utama')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')).toBeDefined())
    const body = calls.find((c) => c.method === 'PATCH')!.data as Record<string, unknown>
    expect(body).toEqual({ name: 'BCA Utama', notes: null, branch_id: null, business_unit_id: null, bank_name: 'BCA', account_holder: 'PT Maju Jaya' })
    for (const forbidden of ['code', 'kind', 'account_number', 'account_id']) expect(body).not.toHaveProperty(forbidden)
  })

  it('sends a replacement account number only when one is typed', async () => {
    const calls = boot(manage, { ...masters, 'GET /app/accounting/cash-bank-accounts': { data: page([bank()]) }, 'PATCH /app/accounting/cash-bank-accounts/b-1': { data: bank() } })
    renderApp('/app/akuntansi/kas-bank')
    await userEvent.click(await screen.findByRole('button', { name: 'Ubah' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Nomor rekening'), '9998887776')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')).toBeDefined())
    expect(calls.find((c) => c.method === 'PATCH')!.data).toMatchObject({ account_number: '9998887776' })
  })

  it('deactivates an account and shows why a used account cannot be deleted', async () => {
    const calls = boot(manage, {
      'GET /app/accounting/cash-bank-accounts': { data: page([bank()]) },
      'POST /app/accounting/cash-bank-accounts/b-1/status': { data: bank({ status: 'INACTIVE' }) },
      'DELETE /app/accounting/cash-bank-accounts/b-1': { status: 409, data: { message: 'in use', code: 'CASH_BANK_ACCOUNT_IN_USE', details: {} } },
    })
    renderApp('/app/akuntansi/kas-bank')
    await userEvent.click(await screen.findByRole('button', { name: 'Nonaktifkan' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Nonaktifkan' }))
    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/status'))?.data).toEqual({ status: 'INACTIVE' }))

    await userEvent.click(await screen.findByRole('button', { name: 'Hapus' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Hapus' }))
    expect(await screen.findByText(/Akun ini sudah memiliki dokumen: nonaktifkan, jangan hapus/)).toBeInTheDocument()
  })

  it('offers no management control in a read-only subscription', async () => {
    boot(manage, { 'GET /app/accounting/cash-bank-accounts': { data: page([bank()]) } }, { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/kas-bank')
    expect(await screen.findByRole('link', { name: 'BCA' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Akun kas/bank baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ubah' })).not.toBeInTheDocument()
  })

  it('shows an empty state', async () => {
    boot(view, { 'GET /app/accounting/cash-bank-accounts': { data: page([]) } })
    renderApp('/app/akuntansi/kas-bank')
    expect(await screen.findByText('Tidak ada akun kas/bank')).toBeInTheDocument()
  })
})

describe('cash and bank account detail', () => {
  const view = ['accounting.cash_bank.view']
  const movement = (over: Record<string, unknown> = {}) => ({
    journal_line_id: 'l-1', journal_entry_id: 'j-1', journal_number: 'SJ-FY2026-000001', journal_type: 'SYSTEM', source_type: 'expense', source_id: 'e-1', posting_date: '2026-10-05',
    description: 'Beban listrik', reference: null, debit: '0.0000', credit: '400000.0000', amount: '400000.0000', direction: 'OUT', running_balance: '1100000.0000', document_number: 'EXP-FY2026-000001',
    matched_item_id: null, matched_statement: null, matched_statement_id: null, ...over,
  })
  const rows = [
    movement({ journal_line_id: 'l-0', journal_entry_id: 'j-0', journal_number: 'SJ-FY2026-000000', posting_date: '2026-10-01', description: 'Setoran modal', debit: '1500000.0000', credit: '0.0000', amount: '1500000.0000', direction: 'IN', running_balance: '1500000.0000', source_type: null, document_number: null, matched_item_id: 'i-1', matched_statement: 'STM-OKT', matched_statement_id: 's-1' }),
    movement(),
  ]
  const routes = (account = bank()) => ({
    [`GET /app/accounting/cash-bank-accounts/${account.id}`]: { data: account },
    [`GET /app/accounting/cash-bank-accounts/${account.id}/transactions`]: (req: { params?: Record<string, unknown> }) => ({ data: { data: rows, current_page: Number(req.params?.page ?? 1), last_page: 2, total: 80, per_page: 50 } }),
  })

  it('shows the account facts with the masked number and the movements with the running balance the API computed', async () => {
    boot(view, routes())
    renderApp('/app/akuntansi/kas-bank/b-1')

    expect(await screen.findByRole('heading', { name: 'BCA · BCA Operasional' })).toBeInTheDocument()
    const facts = within(screen.getByRole('heading', { name: 'Akun' }).closest('section')!)
    expect(facts.getByText('1.500.000,00')).toBeInTheDocument()
    expect(facts.getByText('******7890')).toBeInTheDocument()
    expect(facts.getByText('1120 · Bank')).toBeInTheDocument()

    const table = within(await screen.findByRole('table', { name: 'Mutasi akun kas/bank' }))
    expect(table.getByText('1.100.000,00')).toBeInTheDocument() // running balance after the outflow
    expect(table.getByText('400.000,00')).toBeInTheDocument()
    expect(table.getByRole('link', { name: 'SJ-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-1')
    expect(table.getByText('EXP-FY2026-000001')).toBeInTheDocument()
    // Match state is spelled out, not only coloured.
    expect(table.getByText('Cocok')).toBeInTheDocument()
    expect(table.getByRole('link', { name: 'STM-OKT' })).toHaveAttribute('href', '/app/akuntansi/rekening-koran/s-1')
    expect(table.getByText('Belum dicocokkan')).toBeInTheDocument()
  })

  it('filters the movements on the server, including the explicit "not matched" choice', async () => {
    const calls = boot(view, routes())
    renderApp('/app/akuntansi/kas-bank/b-1')
    await screen.findByRole('table', { name: 'Mutasi akun kas/bank' })
    const moves = () => calls.filter((c) => c.method === 'GET' && c.url === `${AP}/cash-bank-accounts/b-1/transactions`)

    await userEvent.click(screen.getByRole('button', { name: 'Berikutnya' }))
    await waitFor(() => expect(moves().at(-1)?.params?.page).toBe(2))

    await userEvent.selectOptions(screen.getByLabelText('Arah mutasi'), 'OUT')
    await waitFor(() => expect(moves().at(-1)?.params).toEqual({ page: 1, direction: 'OUT' }))
    await userEvent.selectOptions(screen.getByLabelText('Status pencocokan'), 'no')
    await waitFor(() => expect(moves().at(-1)?.params).toEqual({ page: 1, direction: 'OUT', matched: 0 }))
    await userEvent.selectOptions(screen.getByLabelText('Status pencocokan'), 'yes')
    await waitFor(() => expect(moves().at(-1)?.params?.matched).toBe(1))
    fireEvent.change(screen.getByLabelText('Mutasi dari tanggal'), { target: { value: '2026-10-01' } })
    fireEvent.change(screen.getByLabelText('Mutasi sampai tanggal'), { target: { value: '2026-10-31' } })
    await userEvent.type(screen.getByLabelText('Cari mutasi'), 'listrik')
    await waitFor(() => expect(moves().at(-1)?.params).toEqual({ page: 1, direction: 'OUT', matched: 1, from: '2026-10-01', to: '2026-10-31', q: 'listrik' }))
  })

  it('exports the same filtered movements for users who may export, and links bank accounts to the statements', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const calls = boot([...view, 'accounting.report.export', 'accounting.bank_reconciliation.view'], { ...routes(), 'GET /app/accounting/cash-bank-accounts/b-1/transactions/export': { data: 'csv' } })
    renderApp('/app/akuntansi/kas-bank/b-1')
    await screen.findByRole('table', { name: 'Mutasi akun kas/bank' })
    expect(within(screen.getByRole('main')).getByRole('link', { name: 'Rekening koran' })).toHaveAttribute('href', '/app/akuntansi/rekening-koran')

    await userEvent.selectOptions(screen.getByLabelText('Arah mutasi'), 'IN')
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url.endsWith('/transactions/export'))).toBeDefined())
    expect(calls.find((c) => c.url.endsWith('/transactions/export'))!.params).toEqual({ direction: 'IN' })
  })

  it('has neither export nor statement link without the permissions', async () => {
    boot(view, routes())
    renderApp('/app/akuntansi/kas-bank/b-1')
    await screen.findByRole('table', { name: 'Mutasi akun kas/bank' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    expect(within(screen.getByRole('main')).queryByRole('link', { name: 'Rekening koran' })).not.toBeInTheDocument()
  })

  it('leaves out the bank-only parts for a cash box', async () => {
    boot([...view, 'accounting.bank_reconciliation.view'], routes(kas()))
    renderApp('/app/akuntansi/kas-bank/b-2')
    await screen.findByRole('table', { name: 'Mutasi akun kas/bank' })
    expect(screen.queryByLabelText('Status pencocokan')).not.toBeInTheDocument()
    expect(screen.queryByText('Pencocokan')).not.toBeInTheDocument()
    expect(screen.queryByText('Nomor rekening')).not.toBeInTheDocument()
    expect(within(screen.getByRole('main')).queryByRole('link', { name: 'Rekening koran' })).not.toBeInTheDocument()
  })

  it('says so when there are no movements', async () => {
    boot(view, { 'GET /app/accounting/cash-bank-accounts/b-1': { data: bank() }, 'GET /app/accounting/cash-bank-accounts/b-1/transactions': { data: page([]) } })
    renderApp('/app/akuntansi/kas-bank/b-1')
    expect(await screen.findByText('Belum ada mutasi')).toBeInTheDocument()
  })
})

describe.each([
  { kind: 'PAYMENT' as const, route: 'pembayaran-kas', path: 'cash-payments', title: 'Pembayaran kas', create: 'Pembayaran baru', posting: /Debit akun lawan, Kredit akun kas\/bank/, who: 'Dibayarkan kepada' },
  { kind: 'RECEIPT' as const, route: 'penerimaan-kas', path: 'cash-receipts', title: 'Penerimaan kas', create: 'Penerimaan baru', posting: /Debit akun kas\/bank, Kredit akun lawan/, who: 'Diterima dari' },
])('cash $kind', ({ kind, route, path, title, create, posting, who }) => {
  const view = ['accounting.cash_transaction.view']
  const base = `${AP}/${path}`
  const ui = `/app/akuntansi/${route}`

  describe('list', () => {
    const lists = (rows: unknown[]) => ({
      [`GET ${base}`]: (req: { params?: Record<string, unknown> }) => ({ data: { data: rows, current_page: Number(req.params?.page ?? 1), last_page: 2, total: 40, per_page: 25 } }),
      'GET /app/accounting/cash-bank-accounts': { data: page([bank(), kas()]) },
      'GET /app/accounting/accounts': { data: { data: gl } },
    })

    it('lists transactions of its own kind from its own endpoint', async () => {
      const calls = boot(view, lists([transaction(kind, { document_number: 'CP-1', status: 'POSTED' })]))
      renderApp(ui)

      expect(await screen.findByRole('heading', { name: title })).toBeInTheDocument()
      expect(await screen.findByRole('link', { name: 'CP-1' })).toHaveAttribute('href', `${ui}/t-1`)
      expect(within(screen.getByRole('table')).getByText('250.000,00')).toBeInTheDocument()
      expect(screen.queryByRole('button', { name: create })).not.toBeInTheDocument()
      expect(calls.filter((c) => c.url.includes('/cash-') && c.method === 'GET').every((c) => c.url.startsWith(base) || c.url.endsWith('cash-bank-accounts'))).toBe(true)
    })

    it('sends every filter to the server and resets to page 1', async () => {
      const calls = boot(view, lists([transaction(kind)]))
      renderApp(ui)
      await screen.findByRole('link', { name: 'Draf' })
      const rows = () => calls.filter((c) => c.method === 'GET' && c.url === base)

      await userEvent.click(screen.getByRole('button', { name: 'Berikutnya' }))
      await waitFor(() => expect(rows().at(-1)?.params?.page).toBe(2))
      await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
      await waitFor(() => expect(rows().at(-1)?.params).toEqual({ page: 1, status: 'POSTED' }))
      await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'b-1')
      await userEvent.selectOptions(screen.getByLabelText('Akun lawan'), 'a-6900')
      fireEvent.change(screen.getByLabelText('Tanggal transaksi dari'), { target: { value: '2026-10-01' } })
      fireEvent.change(screen.getByLabelText('Tanggal transaksi sampai'), { target: { value: '2026-10-31' } })
      fireEvent.change(screen.getByLabelText('Tanggal posting dari'), { target: { value: '2026-10-02' } })
      fireEvent.change(screen.getByLabelText('Tanggal posting sampai'), { target: { value: '2026-10-30' } })
      await userEvent.click(screen.getByRole('checkbox', { name: 'Buatan saya' }))
      await userEvent.type(screen.getByLabelText(new RegExp(`^Cari ${title}`, 'i')), 'parkir')
      await waitFor(() => expect(rows().at(-1)?.params).toEqual({
        page: 1, status: 'POSTED', cash_bank_account_id: 'b-1', counter_account_id: 'a-6900', transaction_from: '2026-10-01', transaction_to: '2026-10-31',
        posting_from: '2026-10-02', posting_to: '2026-10-30', mine: 1, q: 'parkir',
      }))
    })

    it('exports from its own endpoint with the filters, only for users who may export', async () => {
      URL.createObjectURL = vi.fn(() => 'blob:x')
      URL.revokeObjectURL = vi.fn()
      const calls = boot([...view, 'accounting.report.export', 'accounting.cash_transaction.create'], { ...lists([transaction(kind)]), [`GET ${base}/export`]: { data: 'csv' } })
      renderApp(ui)
      await screen.findByRole('link', { name: 'Draf' })
      expect(screen.getByRole('button', { name: create })).toBeInTheDocument()
      await userEvent.selectOptions(screen.getByLabelText('Status'), 'POSTED')
      await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
      await waitFor(() => expect(calls.find((c) => c.url === `${base}/export`)).toBeDefined())
      expect(calls.find((c) => c.url === `${base}/export`)!.params).toEqual({ status: 'POSTED' })
    })

    it('shows an empty state', async () => {
      boot(view, { ...lists([]), [`GET ${base}`]: { data: page([]) } })
      renderApp(ui)
      expect(await screen.findByText(`Tidak ada ${title.toLowerCase()}`)).toBeInTheDocument()
    })
  })

  describe('editor', () => {
    const create_ = ['accounting.cash_transaction.view', 'accounting.cash_transaction.create']
    const masters = {
      'GET /app/accounting/cash-bank-accounts': { data: page([bank(), kas({ status: 'INACTIVE' })]) },
      'GET /app/accounting/accounts': { data: { data: gl } },
      'GET /app/accounting/dimensions': { data: noDimensions },
    }

    it('explains the direction and posting of this kind', async () => {
      boot(create_, masters)
      renderApp(`${ui}/baru`)
      expect(await screen.findByRole('heading', { name: create })).toBeInTheDocument()
      expect(screen.getByText(posting)).toBeInTheDocument()
      expect(screen.getByLabelText(who)).toBeInTheDocument()
    })

    it('offers only active cash accounts and only counter accounts the API will accept', async () => {
      boot(create_, masters)
      renderApp(`${ui}/baru`)
      await screen.findByRole('option', { name: 'BCA · BCA Operasional' })

      expect(within(screen.getByLabelText('Akun kas/bank')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Pilih akun…', 'BCA · BCA Operasional'])
      // Not the cash/bank GL accounts (1110, 1120), not a control account (1130), not a header (1000), not an inactive one (1199).
      expect(within(screen.getByLabelText(/Akun (tujuan|sumber) \(lawan\)/)).getAllByRole('option').map((o) => o.textContent)).toEqual(['Pilih akun…', '1140 · Persediaan', '6900 · Beban Umum', '4100 · Penjualan'])
    })

    it('posts the draft to the endpoint of its kind with the amount as a decimal string', async () => {
      const created = transaction(kind)
      const calls = boot(create_, { ...masters, [`POST ${base}`]: { status: 201, data: created }, [`GET ${base}/t-1`]: { data: created } })
      renderApp(`${ui}/baru`)
      await screen.findByRole('option', { name: 'BCA · BCA Operasional' })
      await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'b-1')
      await userEvent.selectOptions(screen.getByLabelText(/Akun (tujuan|sumber) \(lawan\)/), 'a-6900')
      await userEvent.type(screen.getByLabelText('Jumlah'), '250000,5')
      expect(screen.getByText('250.000,50')).toBeInTheDocument()
      await userEvent.type(screen.getByLabelText('Tujuan'), 'Bayar parkir')
      await userEvent.type(screen.getByLabelText('Deskripsi'), 'Parkir kantor')
      await userEvent.type(screen.getByLabelText(who), 'Operator parkir')
      await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

      await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === base)).toBeDefined())
      const sent = calls.find((c) => c.method === 'POST')!
      expect(sent.data).toEqual({
        cash_bank_account_id: 'b-1', counter_account_id: 'a-6900', amount: '250000.5000', transaction_date: '2026-10-08', posting_date: '2026-10-08',
        purpose: 'Bayar parkir', description: 'Parkir kantor', counterparty_name: 'Operator parkir', reference: null, branch_id: null, business_unit_id: null, cost_center_id: null,
      })
      expect(JSON.stringify(sent.data)).not.toMatch(/"amount":\d/)
      expect(calls.some((c) => c.method === 'POST' && c.url !== base)).toBe(false) // the other kind's endpoint is never touched
      expect(await screen.findByRole('heading', { name: `Draf ${title.toLowerCase()}` })).toBeInTheDocument()
    })

    it('takes the placement of the cash account like the API does', async () => {
      const withBranch = bank({ branch_id: 'br-1', business_unit_id: 'bu-1' })
      const calls = boot(create_, { ...masters, 'GET /app/accounting/cash-bank-accounts': { data: page([withBranch]) }, [`POST ${base}`]: { status: 201, data: transaction(kind) }, [`GET ${base}/t-1`]: { data: transaction(kind) } })
      renderApp(`${ui}/baru`)
      await screen.findByRole('option', { name: 'BCA · BCA Operasional' })
      await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'b-1')
      await userEvent.selectOptions(screen.getByLabelText(/Akun (tujuan|sumber) \(lawan\)/), 'a-6900')
      await userEvent.type(screen.getByLabelText('Jumlah'), '1000')
      await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
      await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
      expect(calls.find((c) => c.method === 'POST')!.data).toMatchObject({ branch_id: 'br-1', business_unit_id: 'bu-1' })
    })

    it('shows validation messages under their fields and a business-rule refusal on its field', async () => {
      boot(create_, { ...masters, [`POST ${base}`]: { status: 422, data: { message: 'The counter account cannot be a cash or bank account.', code: 'CASH_TRANSACTION_COUNTER_IS_CASH_BANK', details: { field: 'counter_account_id' } } } })
      renderApp(`${ui}/baru`)
      await screen.findByRole('option', { name: 'BCA · BCA Operasional' })
      await userEvent.type(screen.getByLabelText('Jumlah'), '1000')
      await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))

      const messages = await screen.findAllByText('Akun lawan tidak boleh akun kas/bank; perpindahan antar kas/bank belum didukung.')
      expect(messages.length).toBe(2)
      expect(screen.getByLabelText(/Akun (tujuan|sumber) \(lawan\)/)).toHaveAttribute('aria-invalid', 'true')
    })

    it('shows the API validation message of a field', async () => {
      boot(create_, { ...masters, [`POST ${base}`]: { status: 422, data: { message: 'The given data was invalid.', errors: { purpose: ['Tujuan wajib diisi.'] } } } })
      renderApp(`${ui}/baru`)
      await screen.findByRole('option', { name: 'BCA · BCA Operasional' })
      await userEvent.type(screen.getByLabelText('Jumlah'), '1000')
      await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
      expect((await screen.findAllByText('Tujuan wajib diisi.')).some((m) => m.classList.contains('error'))).toBe(true)
    })

    it('refuses an amount that is not a plain decimal before calling the API', async () => {
      const calls = boot(create_, masters)
      renderApp(`${ui}/baru`)
      await screen.findByRole('option', { name: 'BCA · BCA Operasional' })
      await userEvent.type(screen.getByLabelText('Jumlah'), '1.5jt')
      await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
      expect(await screen.findByText(/Jumlah harus berupa angka tanpa pemisah ribuan/)).toBeInTheDocument()
      expect(calls.some((c) => c.method === 'POST')).toBe(false)
    })

    it('edits a draft through the endpoint of its kind and refuses to edit a posted transaction', async () => {
      const calls = boot([...create_], { ...masters, [`GET ${base}/t-1`]: { data: transaction(kind) }, [`GET ${base}/t-2`]: { data: transaction(kind, { id: 't-2', status: 'POSTED', document_number: 'X-1' }) }, [`PATCH ${base}/t-1`]: { data: transaction(kind) } })
      const view_ = renderApp(`${ui}/t-1/ubah`)
      await screen.findByRole('heading', { name: `Ubah draf ${title.toLowerCase()}` })
      expect(screen.getByLabelText('Jumlah')).toHaveValue('250000')
      expect(screen.getByLabelText('Tujuan')).toHaveValue('Bayar parkir')
      await userEvent.click(screen.getByRole('button', { name: 'Simpan draf' }))
      await waitFor(() => expect(calls.some((c) => c.method === 'PATCH' && c.url === `${base}/t-1`)).toBe(true))
      expect(calls.find((c) => c.method === 'PATCH')!.data).toMatchObject({ amount: '250000.0000', cash_bank_account_id: 'b-1', counter_account_id: 'a-6900' })
      view_.unmount()

      renderApp(`${ui}/t-2/ubah`)
      expect(await screen.findByText('Transaksi tidak dapat diubah')).toBeInTheDocument()
    })
  })

  describe('detail', () => {
    const all = ['accounting.cash_transaction.view', 'accounting.cash_transaction.create', 'accounting.cash_transaction.post', 'accounting.cash_transaction.reverse']
    const get = (data: unknown) => ({ [`GET ${base}/t-1`]: { data } })

    it('has no approval buttons at all and offers edit, direct posting and cancel on a draft', async () => {
      // Even a user holding the approval permissions of other documents sees none here: cash transactions have no submit, approve, reject or reopen.
      boot([...all, 'accounting.expense.submit', 'accounting.expense.approve', 'accounting.journal.submit'], get(transaction(kind)))
      renderApp(`${ui}/t-1`)

      expect(await screen.findByRole('heading', { name: `Draf ${title.toLowerCase()}` })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Ubah' })).toHaveAttribute('href', `${ui}/t-1/ubah`)
      expect(screen.getByRole('button', { name: 'Posting' })).toBeEnabled()
      expect(screen.getByRole('button', { name: 'Batalkan' })).toBeInTheDocument()
      for (const label of ['Ajukan', 'Setujui', 'Tolak', 'Jadikan draf', 'Balik']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
      expect(screen.getByText('250.000,00')).toBeInTheDocument()
      expect(screen.getByText(posting)).toBeInTheDocument()
    })

    it('posts a draft after a confirmation, on its own endpoint', async () => {
      const calls = boot(all, { ...get(transaction(kind)), [`POST ${base}/t-1/post`]: { data: transaction(kind, { status: 'POSTED', document_number: 'CP-1', journal_entry_id: 'j-1' }) } })
      renderApp(`${ui}/t-1`)
      await userEvent.click(await screen.findByRole('button', { name: 'Posting' }))
      await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Posting' }))
      await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${base}/t-1/post`)).toBe(true))
      expect(await screen.findByText('Transaksi kas diposting.')).toBeInTheDocument()
    })

    it('needs a reason to cancel a draft', async () => {
      const calls = boot(all, { ...get(transaction(kind)), [`POST ${base}/t-1/cancel`]: { data: transaction(kind, { status: 'CANCELLED' }) } })
      renderApp(`${ui}/t-1`)
      await userEvent.click(await screen.findByRole('button', { name: 'Batalkan' }))
      const dialog = within(screen.getByRole('dialog'))
      await userEvent.click(dialog.getByRole('button', { name: 'Batalkan transaksi kas' }))
      expect(calls.some((c) => c.url.endsWith('/cancel'))).toBe(false)
      await userEvent.type(dialog.getByLabelText(/Alasan/), 'Salah input')
      await userEvent.click(dialog.getByRole('button', { name: 'Batalkan transaksi kas' }))
      await waitFor(() => expect(calls.find((c) => c.url.endsWith('/cancel'))?.data).toEqual({ reason: 'Salah input' }))
    })

    it('disables posting with the reason when segregation of duties forbids it', async () => {
      boot(all, get(transaction(kind, { sod: { approve: false, post: false } })))
      renderApp(`${ui}/t-1`)
      expect(await screen.findByRole('button', { name: 'Posting' })).toBeDisabled()
      expect(screen.getByText(/Pemisahan tugas: Anda tidak dapat memposting/)).toBeInTheDocument()
    })

    it('does not offer posting or cancelling without the permission', async () => {
      boot(['accounting.cash_transaction.view'], get(transaction(kind)))
      renderApp(`${ui}/t-1`)
      await screen.findByRole('heading', { name: `Draf ${title.toLowerCase()}` })
      for (const label of ['Posting', 'Batalkan']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: 'Ubah' })).not.toBeInTheDocument()
    })

    it('reverses a posted transaction with a reason and a posting date, linking both journals', async () => {
      const postedTx = transaction(kind, { status: 'POSTED', document_number: 'CP-1', journal_entry_id: 'j-1', posted_at: '2026-10-08T04:00:00Z' })
      const calls = boot(all, { ...get(postedTx), [`POST ${base}/t-1/reverse`]: { data: transaction(kind, { status: 'REVERSED', document_number: 'CP-1', journal_entry_id: 'j-1', reversal_journal_id: 'j-2', reversal_reason: 'Salah catat', reversal_posting_date: '2026-10-08' }) } })
      renderApp(`${ui}/t-1`)

      expect(await screen.findByRole('heading', { name: 'CP-1' })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Lihat jurnal' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-1')
      for (const label of ['Posting', 'Batalkan', 'Ajukan']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
      await userEvent.click(screen.getByRole('button', { name: 'Balik' }))
      const dialog = within(screen.getByRole('dialog', { name: 'Balik transaksi kas' }))
      await userEvent.type(dialog.getByLabelText(/Alasan/), 'Salah catat')
      await userEvent.click(dialog.getByRole('button', { name: 'Balik' }))

      await waitFor(() => expect(calls.find((c) => c.url === `${base}/t-1/reverse`)).toBeDefined())
      expect(calls.find((c) => c.url === `${base}/t-1/reverse`)!.data).toEqual({ reason: 'Salah catat', posting_date: '2026-10-08' })
    })

    it('shows the reversal journal of a reversed transaction and offers no action', async () => {
      boot(all, get(transaction(kind, { status: 'REVERSED', document_number: 'CP-1', journal_entry_id: 'j-1', reversal_journal_id: 'j-2', reversal_reason: 'Salah catat', reversal_posting_date: '2026-10-09' })))
      renderApp(`${ui}/t-1`)
      expect(await screen.findByRole('link', { name: 'Lihat jurnal pembalik' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-2')
      expect(screen.getByText('Salah catat')).toBeInTheDocument()
      expect(screen.queryAllByRole('button').filter((b) => ['Posting', 'Batalkan', 'Balik'].includes(b.textContent ?? ''))).toEqual([])
    })

    it('keeps the document viewable but offers no action in a read-only subscription', async () => {
      boot(all, get(transaction(kind)), { mode: 'READ_ONLY' })
      renderApp(`${ui}/t-1`)
      expect(await screen.findByRole('heading', { name: `Draf ${title.toLowerCase()}` })).toBeInTheDocument()
      expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
      for (const label of ['Posting', 'Batalkan']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: 'Ubah' })).not.toBeInTheDocument()
    })

    it('shows the masked account number only and the status history', async () => {
      boot(all, get(transaction(kind)))
      renderApp(`${ui}/t-1`)
      expect(await screen.findByText('******7890')).toBeInTheDocument()
      const history = within(screen.getByRole('heading', { name: 'Riwayat' }).closest('section')!)
      expect(history.getByText(/Budi Santoso/)).toBeInTheDocument()
    })
  })
})
