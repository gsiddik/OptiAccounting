import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { boot, cashBankAccountRow, item, MANAGE, report, reportAccount, statement, summary, VIEW } from './bank/testing'
import type { BankStatement } from './bank/types'

const LIST = '/app/accounting/bank-statements'
const ACCOUNTS = '/app/accounting/cash-bank-accounts'
const DETAIL = (id = 'st-1') => `${LIST}/${id}`

/** A statement as the list returns it: counts instead of items and summary. */
const listRow = (over: Partial<BankStatement> = {}): BankStatement => statement({ items: undefined, summary: undefined, unmatched_items: 3, matched_items: 1, exception_items: 0, ...over })

const matchedItem = item({
  status: 'MATCHED', matched_journal_line_id: 'jl-1', matched_by: 'u-t', matched_at: '2026-10-08T03:00:00Z', matcher: { id: 'u-t', name: 'Budi Santoso' },
})

// ------------------------------------------------------------------------------------------------ list

describe('bank statements list', () => {
  it('lists statements with server-side filters and pagination, and offers only bank accounts in the filter', async () => {
    const calls = boot({ permissions: [...VIEW, 'accounting.cash_bank.view'] }, {
      [`GET ${LIST}`]: (req) => ({ data: { data: [listRow()], current_page: Number(req.params?.page ?? 1), last_page: 2, total: 30, per_page: 25 } }),
      [`GET ${ACCOUNTS}`]: { data: page([cashBankAccountRow(), cashBankAccountRow({ id: 'cb-2', code: 'KAS', name: 'Kas kecil', kind: 'CASH' })]) },
    })
    renderApp('/app/akuntansi/rekening-koran')

    const link = await screen.findByRole('link', { name: 'BCA-2026-03' })
    expect(link).toHaveAttribute('href', '/app/akuntansi/rekening-koran/st-1')
    const row = within(link.closest('tr')!)
    expect(row.getByText('BCA · Bank BCA')).toBeInTheDocument()
    expect(row.getByText('3.965.000,00')).toBeInTheDocument()
    expect(row.getByText(/1 cocok · 0 pengecualian/)).toBeInTheDocument()
    expect(row.getByText('3 belum dicocokkan')).toBeInTheDocument()
    expect(row.getByText('Terbuka')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Rekening koran baru' })).not.toBeInTheDocument() // view only

    await waitFor(() => expect(within(screen.getByLabelText('Akun bank')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Semua akun bank', 'BCA · Bank BCA']))

    await userEvent.click(screen.getByRole('button', { name: 'Berikutnya' }))
    await waitFor(() => expect(calls.filter((c) => c.url === LIST).at(-1)?.params).toMatchObject({ page: 2 }))

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'OPEN')
    await waitFor(() => expect(calls.filter((c) => c.url === LIST).at(-1)?.params).toEqual({ page: 1, status: 'OPEN' })) // a filter returns to page 1
    await userEvent.selectOptions(screen.getByLabelText('Akun bank'), 'cb-1')
    fireEvent.change(screen.getByLabelText(/Dari/), { target: { value: '2026-03-01' } })
    await waitFor(() => expect(calls.filter((c) => c.url === LIST).at(-1)?.params).toEqual({ page: 1, status: 'OPEN', cash_bank_account_id: 'cb-1', from: '2026-03-01' }))
  })

  it('does not request the account list for a user who may not read accounts', async () => {
    const calls = boot({ permissions: VIEW }, { [`GET ${LIST}`]: { data: page([listRow()]) } })
    renderApp('/app/akuntansi/rekening-koran')
    await screen.findByRole('link', { name: 'BCA-2026-03' })
    expect(screen.queryByLabelText('Akun bank')).not.toBeInTheDocument()
    expect(calls.some((c) => c.url === ACCOUNTS)).toBe(false)
  })

  it('shows an error with a retry, and the empty state', async () => {
    let mode: 'fail' | 'empty' = 'fail'
    boot({ permissions: MANAGE }, { [`GET ${LIST}`]: () => (mode === 'fail' ? { status: 500, data: { message: 'boom' } } : { data: page([]) }) })
    renderApp('/app/akuntansi/rekening-koran')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    mode = 'empty'
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Belum ada rekening koran')).toBeInTheDocument()
  })

  it('deletes only open statements without matches, after confirmation', async () => {
    let rows = [listRow({ matched_items: 0 }), listRow({ id: 'st-2', reference: 'BCA-2026-02', matched_items: 2 }), listRow({ id: 'st-3', reference: 'BCA-2026-01', status: 'COMPLETED', matched_items: 0 })]
    const calls = boot({ permissions: MANAGE }, {
      [`GET ${LIST}`]: () => ({ data: page(rows) }),
      [`DELETE ${DETAIL('st-1')}`]: () => { rows = rows.slice(1); return { status: 204 } },
    })
    renderApp('/app/akuntansi/rekening-koran')

    await screen.findByRole('link', { name: 'BCA-2026-03' })
    expect(screen.getAllByRole('button', { name: /^Hapus / }).map((b) => b.getAttribute('aria-label'))).toEqual(['Hapus BCA-2026-03'])

    await userEvent.click(screen.getByRole('button', { name: 'Hapus BCA-2026-03' }))
    const dialog = await screen.findByRole('dialog', { name: 'Hapus rekening koran' })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Hapus' }))

    await waitFor(() => expect(screen.queryByRole('link', { name: 'BCA-2026-03' })).not.toBeInTheDocument())
    expect(calls.some((c) => c.method === 'DELETE' && c.url === DETAIL('st-1'))).toBe(true)
    expect(await screen.findByText('Rekening koran dihapus.')).toBeInTheDocument()
  })
})

// ------------------------------------------------------------------------------------------------ create

describe('create a bank statement', () => {
  const permissions = [...MANAGE, 'accounting.cash_bank.view']

  async function openCreate(routes: Parameters<typeof boot>[1] = {}) {
    const calls = boot({ permissions }, {
      [`GET ${LIST}`]: { data: page([listRow()]) },
      [`GET ${ACCOUNTS}`]: { data: page([cashBankAccountRow(), cashBankAccountRow({ id: 'cb-2', code: 'KAS', name: 'Kas kecil', kind: 'CASH' })]) },
      ...routes,
    })
    renderApp('/app/akuntansi/rekening-koran')
    await userEvent.click(await screen.findByRole('button', { name: 'Rekening koran baru' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Rekening koran baru' }))
    await waitFor(() => expect(dialog.getByLabelText('Akun bank')).toHaveValue('cb-1')) // the only active bank account is chosen for the user
    return { calls, dialog }
  }

  async function fillHeader(dialog: ReturnType<typeof within>, over: { closing?: string; opening?: string } = {}) {
    await userEvent.type(dialog.getByLabelText('Referensi rekening koran'), 'BCA-2026-04')
    fireEvent.change(dialog.getByLabelText('Tanggal rekening koran'), { target: { value: '2026-03-31' } })
    if (over.opening) await userEvent.type(dialog.getByLabelText('Saldo awal (opsional)'), over.opening)
    await userEvent.type(dialog.getByLabelText('Saldo akhir'), over.closing ?? '3.965.000')
  }

  it('sends typed and pasted lines parsed exactly, as decimal strings, and opens the new statement', async () => {
    const { calls, dialog } = await openCreate({
      [`POST ${LIST}`]: { status: 201, data: statement({ id: 'st-9', reference: 'BCA-2026-04' }) },
      [`GET ${DETAIL('st-9')}`]: { data: statement({ id: 'st-9', reference: 'BCA-2026-04' }) },
    })
    // Only active BANK accounts are offered: the cash box is not.
    expect(within(dialog.getByLabelText('Akun bank')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Pilih akun bank…', 'BCA · Bank BCA (••••0123)'])

    await fillHeader(dialog, { closing: '-2.500.000,50', opening: '100' })
    await userEvent.click(dialog.getByRole('button', { name: 'Tambah baris' }))
    await userEvent.type(dialog.getByLabelText('Keterangan baris 1'), 'Pajak bunga')
    fireEvent.change(dialog.getByLabelText('Tanggal baris 1'), { target: { value: '2026-03-31' } })
    await userEvent.type(dialog.getByLabelText('Jumlah baris 1'), '-10.000,5')
    fireEvent.change(dialog.getByLabelText('Tempel dari spreadsheet'), {
      target: { value: '2026-03-01\tSetoran modal\t5.000.000,00\n\n2026-03-10;Transfer keluar vendor;-1.000.000\n2026-03-15\tBiaya admin\t-25000.1234' },
    })
    expect(await dialog.findByText('3 baris terbaca dari teks yang ditempel.')).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan rekening koran' }))

    expect(await screen.findByRole('heading', { name: 'BCA-2026-04' })).toBeInTheDocument()
    const post = calls.find((c) => c.method === 'POST' && c.url === LIST)!
    expect(post.data).toEqual({
      cash_bank_account_id: 'cb-1', reference: 'BCA-2026-04', statement_date: '2026-03-31', period_start: null, opening_balance: '100.0000', closing_balance: '-2500000.5000', notes: null,
      items: [
        { item_date: '2026-03-31', description: 'Pajak bunga', reference: null, amount: '-10000.5000' },
        { item_date: '2026-03-01', description: 'Setoran modal', reference: null, amount: '5000000.0000' },
        { item_date: '2026-03-10', description: 'Transfer keluar vendor', reference: null, amount: '-1000000.0000' },
        { item_date: '2026-03-15', description: 'Biaya admin', reference: null, amount: '-25000.1234' },
      ],
    })
    expect(JSON.stringify(post.data)).not.toMatch(/"(amount|closing_balance|opening_balance)":-?\d/) // never a JSON number
  })

  it('lists unreadable pasted lines with their line numbers and does not submit', async () => {
    const { calls, dialog } = await openCreate()
    await fillHeader(dialog)
    fireEvent.change(dialog.getByLabelText('Tempel dari spreadsheet'), {
      target: { value: '2026-03-01;Setoran;5.000.000\n2026-02-30;Tanggal salah;100\nhanya dua;kolom\n\n2026-03-06;Nol;0\n2026-03-07;Saldo;100;12.345' },
    })

    const alert = await dialog.findByText(/4 baris tempelan tidak dapat dibaca/)
    expect(alert).toBeInTheDocument()
    for (const line of [2, 3, 5, 6]) expect(dialog.getByText(new RegExp(`^Baris ${line}:`))).toBeInTheDocument()
    expect(dialog.queryByText(/^Baris 1:/)).not.toBeInTheDocument() // the readable line is not blamed
    expect(dialog.queryByText(/^Baris 4:/)).not.toBeInTheDocument() // neither is the blank line

    await userEvent.click(dialog.getByRole('button', { name: 'Simpan rekening koran' }))
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
    expect(screen.getByRole('dialog', { name: 'Rekening koran baru' })).toBeInTheDocument()
  })

  it('needs the required fields and a readable balance before anything is sent', async () => {
    const { calls, dialog } = await openCreate()
    await userEvent.type(dialog.getByLabelText('Saldo akhir'), '12abc')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan rekening koran' }))

    expect(dialog.getByText('Referensi wajib diisi.')).toBeInTheDocument()
    expect(dialog.getByText(/^Jumlah tidak valid/)).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Tambah baris' }))
    await userEvent.type(dialog.getByLabelText('Keterangan baris 1'), 'tanpa jumlah')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan rekening koran' }))
    expect(dialog.getByText('Tanggal harus berformat TTTT-BB-HH.')).toBeInTheDocument()
    expect(dialog.getByText('Jumlah wajib diisi.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('shows the API refusal and the server error on the line it belongs to', async () => {
    const { dialog } = await openCreate({
      [`POST ${LIST}`]: { status: 422, data: { code: 'BANK_STATEMENT_REFERENCE_TAKEN', message: 'taken', errors: { 'items.0.amount': ['Jumlah ditolak server.'] } } },
    })
    await fillHeader(dialog)
    await userEvent.click(dialog.getByRole('button', { name: 'Tambah baris' }))
    await userEvent.type(dialog.getByLabelText('Keterangan baris 1'), 'Biaya')
    fireEvent.change(dialog.getByLabelText('Tanggal baris 1'), { target: { value: '2026-03-15' } })
    await userEvent.type(dialog.getByLabelText('Jumlah baris 1'), '-5')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan rekening koran' }))

    expect(await dialog.findByText('Akun bank ini sudah memiliki rekening koran dengan referensi tersebut.')).toBeInTheDocument()
    expect(dialog.getByText('Jumlah ditolak server.')).toBeInTheDocument()
  })

  it('says so when no bank account exists, instead of offering a cash box', async () => {
    boot({ permissions }, { [`GET ${LIST}`]: { data: page([listRow()]) }, [`GET ${ACCOUNTS}`]: { data: page([cashBankAccountRow({ id: 'cb-2', code: 'KAS', name: 'Kas kecil', kind: 'CASH' })]) } })
    renderApp('/app/akuntansi/rekening-koran')
    await userEvent.click(await screen.findByRole('button', { name: 'Rekening koran baru' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Rekening koran baru' }))
    expect(await dialog.findByText(/Belum ada akun bank yang aktif/)).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan rekening koran' }))
    expect(dialog.getByText('Pilih akun bank.')).toBeInTheDocument()
  })
})

// ------------------------------------------------------------------------------------------------ detail

const DETAIL_PERMISSIONS = [...MANAGE, 'accounting.cash_bank.view', 'accounting.journal.view', 'accounting.report.export']

describe('bank statement detail', () => {
  it('shows the summary figures exactly as the API returns them, with the equation explained', async () => {
    const s = statement({
      opening_balance: '0.0000',
      summary: summary({
        statement_balance: '3965000.0000', book_balance: '3775000.0000', unmatched_book_net: '-200000.0000', unmatched_book_lines: 1, exception_statement_net: '-10000.0000',
        unexplained_difference: '0.0000', status: 'RECONCILED', book_minus_statement: '-190000.0000', items_net: '3955000.0000', statement_consistent: true,
        unmatched_items: 0, matched_items: 3, exception_items: 1,
      }),
      items: [
        matchedItem,
        item({ id: 'it-2', line_number: 2, description: 'Transfer keluar vendor', amount: '-1000000.0000', status: 'MATCHED', matched_journal_line_id: 'jl-2', matched_by: 'u-t', matched_at: '2026-10-08T03:00:00Z', matcher: { id: 'u-t', name: 'Budi Santoso' } }),
        item({ id: 'it-3', line_number: 3, description: 'Pajak bunga', amount: '-10000.0000', status: 'EXCEPTION', notes: 'Belum dicatat di buku', reference: 'TAX-1' }),
      ],
    })
    const calls = boot({ permissions: DETAIL_PERMISSIONS }, {
      [`GET ${DETAIL()}`]: { data: s },
      [`GET ${ACCOUNTS}/cb-1/transactions`]: { data: page([{ journal_line_id: 'jl-1', journal_entry_id: 'j-77', journal_number: 'CR-FY2026-000001', matched_item_id: 'it-1' }]) },
    })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    expect(await screen.findByRole('heading', { name: 'BCA-2026-03' })).toBeInTheDocument()
    const panel = within(screen.getByRole('heading', { name: 'Ringkasan rekonsiliasi' }).closest('section')!)
    const fact = (label: string) => within(panel.getByText(label).closest('div')!)
    expect(fact('Saldo rekening koran').getByText('3.965.000,00')).toBeInTheDocument()
    expect(fact('Saldo buku').getByText('3.775.000,00')).toBeInTheDocument()
    expect(fact('Buku dikurangi rekening koran').getByText('-190.000,00')).toBeInTheDocument()
    expect(fact('Mutasi buku belum dicocokkan').getByText('-200.000,00')).toBeInTheDocument()
    expect(fact('Mutasi buku belum dicocokkan').getByText(/1 baris buku tanpa pasangan/)).toBeInTheDocument()
    expect(fact('Pengecualian').getByText('-10.000,00')).toBeInTheDocument()
    expect(fact('Selisih tidak terjelaskan').getByText('0,00')).toBeInTheDocument()
    expect(fact('Selisih tidak terjelaskan').getByText(/Terekonsiliasi/)).toBeInTheDocument()
    expect(fact('Status rekonsiliasi').getByText('Terekonsiliasi')).toBeInTheDocument()
    expect(fact('Total baris rekening koran').getByText('3.955.000,00')).toBeInTheDocument()
    expect(fact('Konsistensi rekening koran').getByText('Konsisten')).toBeInTheDocument()
    expect(fact('Baris').getByText(/3 cocok · 1 pengecualian · 0 belum dicocokkan/)).toBeInTheDocument()
    expect(panel.getByText(/\(saldo rekening koran \+ mutasi buku yang belum dicocokkan\) − \(saldo buku \+ pengecualian\)/)).toBeInTheDocument()
    expect(screen.getByText(/Rekonsiliasi tidak pernah mengubah buku besar/)).toBeInTheDocument()

    // Items: unsigned figure + Masuk/Keluar, status, the reason of an exception, and the journal of a matched line.
    const deposit = within(screen.getByRole('row', { name: /Setoran modal/ }))
    expect(deposit.getByText('5.000.000,00')).toBeInTheDocument()
    expect(deposit.getByText('Masuk')).toBeInTheDocument()
    expect(deposit.getByText('Cocok')).toBeInTheDocument()
    expect(await deposit.findByRole('link', { name: 'CR-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/jurnal/j-77')
    expect(deposit.getByText(/oleh Budi Santoso/)).toBeInTheDocument()
    const withdrawal = within(screen.getByRole('row', { name: /Transfer keluar vendor/ }))
    expect(withdrawal.getByText('1.000.000,00')).toBeInTheDocument() // the sign is carried by "Keluar"
    expect(withdrawal.getByText('Keluar')).toBeInTheDocument()
    expect(withdrawal.getByText('Terhubung ke baris buku')).toBeInTheDocument() // its journal is not among the movements returned: no link, no guess
    const exception = within(screen.getByRole('row', { name: /Pajak bunga/ }))
    expect(exception.getByText('Alasan: Belum dicatat di buku')).toBeInTheDocument()
    expect(exception.getByText('Ref TAX-1')).toBeInTheDocument()

    expect(screen.getByRole('link', { name: 'Mutasi akun' })).toHaveAttribute('href', '/app/akuntansi/kas-bank/cb-1')
    expect(screen.getByRole('button', { name: 'Ekspor CSV' })).toBeInTheDocument()
    expect(calls.find((c) => c.url === `${ACCOUNTS}/cb-1/transactions`)?.params).toEqual({ matched: 1, per_page: 200 })
  })

  it('does not ask for ledger movements, and shows no journal link, without permission to read the account', async () => {
    const calls = boot({ permissions: MANAGE }, { [`GET ${DETAIL()}`]: { data: statement({ items: [matchedItem], summary: summary({ matched_items: 1, unmatched_items: 0 }) }) } })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    expect(await screen.findByText('Terhubung ke baris buku')).toBeInTheDocument()
    expect(calls.some((c) => c.url.includes('/transactions'))).toBe(false)
    expect(screen.queryByRole('link', { name: 'Mutasi akun' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
  })

  it('reports a statement that cannot be loaded and retries', async () => {
    let found = false
    boot({ permissions: VIEW }, { [`GET ${DETAIL()}`]: () => (found ? { data: statement() } : { status: 404, data: { message: 'x' } }) })
    renderApp('/app/akuntansi/rekening-koran/st-1')
    expect(await screen.findByText('Data tidak ditemukan.')).toBeInTheDocument()
    found = true
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('heading', { name: 'BCA-2026-03' })).toBeInTheDocument()
  })

  it('matches a line to the chosen book line: loads the candidates and posts the journal_line_id', async () => {
    const afterMatch = statement({ items: [matchedItem], summary: summary({ matched_items: 1, unmatched_items: 0, status: 'RECONCILED', unexplained_difference: '0.0000', book_balance: '3965000.0000' }) })
    const calls = boot({ permissions: MANAGE }, {
      [`GET ${DETAIL()}`]: { data: statement() },
      [`GET ${DETAIL()}/items/it-1/candidates`]: {
        data: {
          data: [
            { journal_line_id: 'jl-1', journal_number: 'CR-FY2026-000001', posting_date: '2026-03-01', description: 'Setoran modal pemilik', reference: null, amount: '5000000.0000', source_type: 'cash_transaction', source_id: 'ct-1', days_apart: 0 },
            { journal_line_id: 'jl-2', journal_number: 'CR-FY2026-000002', posting_date: '2026-03-04', description: 'Setoran lain', reference: 'R-2', amount: '5000000.0000', source_type: null, source_id: null, days_apart: 3 },
          ],
        },
      },
      [`POST ${DETAIL()}/items/it-1/match`]: { data: afterMatch },
    })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    await userEvent.click(await screen.findByRole('button', { name: 'Cocokkan baris 1' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Cocokkan baris 1' }))
    const second = await dialog.findByRole('radio', { name: /CR-FY2026-000002/ })
    expect(dialog.getByText(/3 hari dari tanggal baris/)).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: 'Cocokkan' })).toBeDisabled() // nothing chosen yet
    expect(calls.some((c) => c.method === 'POST')).toBe(false)

    await userEvent.click(dialog.getByRole('radio', { name: /CR-FY2026-000001/ }))
    expect(second).not.toBeChecked()
    await userEvent.click(dialog.getByRole('button', { name: 'Cocokkan' }))

    expect(await screen.findByText('Baris 1 dicocokkan.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')?.data).toEqual({ journal_line_id: 'jl-1' })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    // The page shows what the API answered: matched, with the new figures, and the line can only be released now.
    const row = within(screen.getByRole('row', { name: /Setoran modal/ }))
    expect(row.getByText('Cocok')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cocokkan baris 1' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Lepas baris 1' })).toBeInTheDocument()
    expect(within(screen.getByRole('heading', { name: 'Ringkasan rekonsiliasi' }).closest('section')!).getAllByText('Terekonsiliasi').length).toBeGreaterThan(0)
  })

  it('keeps the dialog open and explains an API refusal of a match', async () => {
    boot({ permissions: MANAGE }, {
      [`GET ${DETAIL()}`]: { data: statement() },
      [`GET ${DETAIL()}/items/it-1/candidates`]: { data: { data: [{ journal_line_id: 'jl-1', journal_number: 'CR-1', posting_date: '2026-03-01', description: null, reference: null, amount: '5000000.0000', source_type: null, source_id: null, days_apart: 0 }] } },
      [`POST ${DETAIL()}/items/it-1/match`]: { status: 409, data: { code: 'BANK_BOOK_LINE_ALREADY_MATCHED', message: 'x' } },
    })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    await userEvent.click(await screen.findByRole('button', { name: 'Cocokkan baris 1' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Cocokkan baris 1' }))
    await userEvent.click(await dialog.findByRole('radio', { name: /CR-1/ }))
    await userEvent.click(dialog.getByRole('button', { name: 'Cocokkan' }))
    expect(await dialog.findByText('Baris buku ini sudah dicocokkan dengan baris rekening koran lain.')).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Cocokkan baris 1' })).toBeInTheDocument()
  })

  it('says when there is no book line to match and when the candidates cannot load', async () => {
    let fail = true
    boot({ permissions: MANAGE }, {
      [`GET ${DETAIL()}`]: { data: statement() },
      [`GET ${DETAIL()}/items/it-1/candidates`]: () => (fail ? { status: 500, data: {} } : { data: { data: [] } }),
    })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    await userEvent.click(await screen.findByRole('button', { name: 'Cocokkan baris 1' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Cocokkan baris 1' }))
    expect(await dialog.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(dialog.getByRole('button', { name: 'Coba lagi' }))
    expect(await dialog.findByText('Tidak ada baris buku yang cocok')).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: 'Cocokkan' })).toBeDisabled()
  })

  it('requires a reason for an exception, then sends it', async () => {
    const flagged = statement({ items: [item({ status: 'EXCEPTION', notes: 'Biaya bank belum dicatat' })], summary: summary({ exception_items: 1, unmatched_items: 0 }) })
    const calls = boot({ permissions: MANAGE }, { [`GET ${DETAIL()}`]: { data: statement() }, [`POST ${DETAIL()}/items/it-1/exception`]: { data: flagged } })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    await userEvent.click(await screen.findByRole('button', { name: 'Pengecualian baris 1' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Pengecualian baris 1' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Tandai pengecualian' }))
    expect(dialog.getByText('Alasan wajib diisi.')).toBeInTheDocument()
    await userEvent.type(dialog.getByLabelText('Alasan pengecualian'), '   ')
    await userEvent.click(dialog.getByRole('button', { name: 'Tandai pengecualian' }))
    expect(calls.some((c) => c.method === 'POST')).toBe(false) // blanks are not a reason

    await userEvent.clear(dialog.getByLabelText('Alasan pengecualian'))
    await userEvent.type(dialog.getByLabelText('Alasan pengecualian'), '  Biaya bank belum dicatat ')
    await userEvent.click(dialog.getByRole('button', { name: 'Tandai pengecualian' }))

    expect(await screen.findByText('Baris 1 ditandai pengecualian.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')?.data).toEqual({ notes: 'Biaya bank belum dicatat' })
    expect(await screen.findByText('Alasan: Biaya bank belum dicatat')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Lepas baris 1' })).toBeInTheDocument() // an exception can be released again
  })

  it('releases a matched line', async () => {
    const calls = boot({ permissions: MANAGE }, {
      [`GET ${DETAIL()}`]: { data: statement({ items: [matchedItem], summary: summary({ matched_items: 1, unmatched_items: 0 }) }) },
      [`POST ${DETAIL()}/items/it-1/unmatch`]: { data: statement() },
    })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    const release = await screen.findByRole('button', { name: 'Lepas baris 1' })
    expect(screen.queryByRole('button', { name: 'Hapus' })).not.toBeInTheDocument() // a statement with matches cannot be deleted
    await userEvent.click(release)
    expect(await screen.findByText('Pencocokan dilepas.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST' && c.url === `${DETAIL()}/items/it-1/unmatch`)).toBe(true)
    expect(await screen.findByRole('button', { name: 'Cocokkan baris 1' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Lepas baris 1' })).not.toBeInTheDocument()
    // a matched statement cannot be deleted; once nothing is matched it can
    expect(screen.getByRole('button', { name: 'Hapus' })).toBeInTheDocument()
  })

  it('shows the API refusal to complete with the number of unmatched lines, then completes', async () => {
    let attempts = 0
    const done = statement({
      status: 'COMPLETED', completed_at: '2026-10-08T03:00:00Z', completer: { id: 'u-t', name: 'Budi Santoso' }, book_balance: '3775000.0000', unmatched_book_net: '0.0000', exception_statement_net: '0.0000', unexplained_difference: '190000.0000',
      summary: summary({ unmatched_book_lines: null, unmatched_items: 0, matched_items: 1 }), items: [matchedItem],
    })
    boot({ permissions: MANAGE }, {
      [`GET ${DETAIL()}`]: { data: statement() },
      [`POST ${DETAIL()}/complete`]: () =>
        attempts++ === 0 ? { status: 409, data: { code: 'BANK_STATEMENT_HAS_UNMATCHED_ITEMS', message: 'x', details: { unmatched_items: 2 } } } : { data: done },
    })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    await userEvent.click(await screen.findByRole('button', { name: 'Selesaikan rekonsiliasi' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Selesaikan rekonsiliasi' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Selesaikan' }))
    expect(await dialog.findByText(/Cocokkan setiap baris rekening koran atau tandai sebagai pengecualian sebelum menyelesaikan rekonsiliasi\. Masih ada 2 baris yang belum dicocokkan\./)).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Selesaikan rekonsiliasi' })).toBeInTheDocument()

    await userEvent.click(dialog.getByRole('button', { name: 'Selesaikan' }))
    expect(await screen.findByText('Rekonsiliasi diselesaikan.')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Selesaikan rekonsiliasi' })).not.toBeInTheDocument()
  })

  it('keeps a completed statement read-only and shows its frozen evidence', async () => {
    const calls = boot({ permissions: DETAIL_PERMISSIONS }, {
      [`GET ${DETAIL()}`]: {
        data: statement({
          status: 'COMPLETED', completed_at: '2026-04-01T02:00:00Z', completer: { id: 'u-2', name: 'Siti Akuntan' }, book_balance: '3775000.0000', unmatched_book_net: '-200000.0000',
          exception_statement_net: '-65000.0000', unexplained_difference: '-65000.0000',
          summary: summary({ status: 'DIFFERENCE', book_balance: '3775000.0000', unmatched_book_net: '-200000.0000', unmatched_book_lines: null, unexplained_difference: '-65000.0000', unmatched_items: 0, matched_items: 1, exception_items: 1 }),
          items: [matchedItem, item({ id: 'it-2', line_number: 2, status: 'EXCEPTION', notes: 'Belum dicatat', amount: '-65000.0000' })],
        }),
      },
      [`GET ${ACCOUNTS}/cb-1/transactions`]: { data: page([]) },
    })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    expect(await screen.findByText(/Rekonsiliasi selesai/)).toHaveTextContent('oleh Siti Akuntan')
    expect(screen.getByText(/bukti yang dibekukan saat penyelesaian/)).toBeInTheDocument()
    const panel = within(screen.getByRole('heading', { name: 'Ringkasan rekonsiliasi' }).closest('section')!)
    expect(panel.getByText('-65.000,00', { selector: '.money.strong' })).toBeInTheDocument()
    expect(panel.getAllByText('Ada selisih').length).toBeGreaterThan(0)
    expect(panel.queryByText(/baris buku tanpa pasangan/)).not.toBeInTheDocument() // the line count is not kept once completed
    expect(screen.getByRole('button', { name: 'Ekspor CSV' })).toBeInTheDocument() // evidence can still be exported

    for (const name of [/Cocokkan/, /Lepas/, /Pengecualian baris/, /^Ubah/, /^Hapus/, /Tambah baris/, /Selesaikan rekonsiliasi/]) expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    expect(calls.some((c) => c.method !== 'GET')).toBe(false)
  })

  it('offers no mutation to a user who may only view, or when the module is read-only', async () => {
    boot({ permissions: VIEW }, { [`GET ${DETAIL()}`]: { data: statement() } })
    const view = renderApp('/app/akuntansi/rekening-koran/st-1')
    await screen.findByRole('heading', { name: 'BCA-2026-03' })
    for (const name of [/Cocokkan/, /Pengecualian baris/, /^Ubah/, /^Hapus/, /Tambah baris/, /Selesaikan rekonsiliasi/]) expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    view.unmount()

    boot({ permissions: MANAGE, modules: { ACCOUNTING_CORE: 'FULL', ACCOUNTING_CASH_BANK: 'READ_ONLY' } }, { [`GET ${DETAIL()}`]: { data: statement() } })
    renderApp('/app/akuntansi/rekening-koran/st-1')
    expect(await screen.findByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    for (const name of [/Cocokkan/, /Pengecualian baris/, /^Ubah/, /^Hapus/, /Tambah baris/, /Selesaikan rekonsiliasi/]) expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
  })

  it('adds lines typed or pasted and shows the statement the API answers with', async () => {
    const after = statement({ items: [item(), item({ id: 'it-2', line_number: 2, description: 'Biaya admin', amount: '-25000.0000' })], summary: summary({ unmatched_items: 2 }) })
    const calls = boot({ permissions: MANAGE }, { [`GET ${DETAIL()}`]: { data: statement() }, [`POST ${DETAIL()}/items`]: { status: 201, data: after } })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    await userEvent.click(await screen.findByRole('button', { name: 'Tambah baris' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Tambah baris rekening koran' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan baris' }))
    expect(calls.some((c) => c.method === 'POST')).toBe(false) // the empty form is not sent
    expect(dialog.getByText('Isi sedikitnya satu baris atau tempel dari spreadsheet.')).toBeInTheDocument()

    fireEvent.change(dialog.getByLabelText('Tempel dari spreadsheet'), { target: { value: '2026-03-15\tBiaya admin\t-25.000' } })
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan baris' }))

    expect(await screen.findByText('Baris ditambahkan.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')?.data).toEqual({ items: [{ item_date: '2026-03-15', description: 'Biaya admin', reference: null, amount: '-25000.0000' }] })
    expect(await screen.findByRole('row', { name: /Biaya admin/ })).toBeInTheDocument()
  })

  it('edits and deletes an unmatched line', async () => {
    const edited = statement({ items: [item({ description: 'Setoran modal (koreksi)' })] })
    const calls = boot({ permissions: MANAGE }, {
      [`GET ${DETAIL()}`]: { data: statement() },
      [`PATCH ${DETAIL()}/items/it-1`]: { data: edited },
      [`DELETE ${DETAIL()}/items/it-1`]: { data: statement({ items: [], summary: summary({ unmatched_items: 0 }) }) },
    })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    await userEvent.click(await screen.findByRole('button', { name: 'Ubah baris 1' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Ubah baris 1' }))
    expect(dialog.getByLabelText('Jumlah')).toHaveValue('5000000') // shown as a plain figure, not "5000000.0000"
    await userEvent.type(dialog.getByLabelText('Keterangan'), ' (koreksi)')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await screen.findByText('Baris diperbarui.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'PATCH')?.data).toEqual({ item_date: '2026-03-01', description: 'Setoran modal (koreksi)', reference: null, amount: '5000000.0000' })
    expect(await screen.findByRole('row', { name: /koreksi/ })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Hapus baris 1' }))
    const confirm = within(await screen.findByRole('dialog', { name: 'Hapus baris 1' }))
    await userEvent.click(confirm.getByRole('button', { name: 'Hapus baris' }))
    expect(await screen.findByText('Belum ada baris')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'DELETE' && c.url === `${DETAIL()}/items/it-1`)).toBe(true)
  })

  it('edits the header with an exact signed balance and deletes an untouched statement', async () => {
    const calls = boot({ permissions: MANAGE }, {
      [`GET ${DETAIL()}`]: { data: statement() },
      [`PATCH ${DETAIL()}`]: { data: statement({ closing_balance: '-4000000.0000' }) },
      [`DELETE ${DETAIL()}`]: { status: 204 },
      [`GET ${LIST}`]: { data: page([]) },
    })
    renderApp('/app/akuntansi/rekening-koran/st-1')

    await userEvent.click(await screen.findByRole('button', { name: 'Ubah' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Ubah rekening koran BCA-2026-03' }))
    const closing = dialog.getByLabelText('Saldo akhir')
    await userEvent.clear(closing)
    await userEvent.type(closing, '-4.000.000')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await screen.findByText('Rekening koran diperbarui.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'PATCH')?.data).toMatchObject({ reference: 'BCA-2026-03', statement_date: '2026-03-31', closing_balance: '-4000000.0000', opening_balance: null })

    await userEvent.click(screen.getByRole('button', { name: 'Hapus' }))
    const confirm = within(await screen.findByRole('dialog', { name: 'Hapus rekening koran' }))
    await userEvent.click(confirm.getByRole('button', { name: 'Hapus' }))
    expect(await screen.findByText('Belum ada rekening koran')).toBeInTheDocument() // back on the list
    expect(calls.some((c) => c.method === 'DELETE' && c.url === DETAIL())).toBe(true)
  })
})

// ------------------------------------------------------------------------------------------------ cash/bank vs general ledger

describe('cash/bank vs general ledger report', () => {
  const permissions = ['accounting.reconciliation.cash_bank.view', 'accounting.bank_reconciliation.view', 'accounting.cash_bank.view', 'accounting.report.export']
  const REPORT = '/app/accounting/reconciliation/cash-bank'

  it('renders a mismatch, the partial-scope notice, other activity and the latest statement of each account', async () => {
    const mismatched = reportAccount({
      documents: { receipts: '5000000.0000', cash_payments: '225000.0000', vendor_payments: '900000.0000', paid_expenses: '0.0000', net: '3875000.0000', ledger_net: '3775000.0000', difference: '-100000.0000', status: 'MISMATCH' },
    })
    const cash = reportAccount({
      cash_bank_account_id: 'cb-2', code: 'KAS', name: 'Kas kecil', kind: 'CASH', book_balance: '250000.0000', other_activity: '0.0000', statement: null,
      documents: { receipts: '0.0000', cash_payments: '0.0000', vendor_payments: '0.0000', paid_expenses: '0.0000', net: '0.0000', ledger_net: '0.0000', difference: '0.0000', status: 'MATCHED' },
    })
    const calls = boot({ permissions }, {
      [`GET ${REPORT}`]: { data: report({ accounts: [mismatched, cash], mismatched_accounts: 1, status: 'MISMATCH', book_balance: '4325000.0000', complete: false }) },
      [`GET ${ACCOUNTS}`]: { data: page([cashBankAccountRow(), cashBankAccountRow({ id: 'cb-2', code: 'KAS', name: 'Kas kecil', kind: 'CASH' })]) },
    })
    renderApp('/app/akuntansi/rekonsiliasi/kas-bank')

    expect(await screen.findByText(/1 akun kas\/bank memiliki selisih antara dokumen dan buku besar/)).toBeInTheDocument()
    expect(screen.getByText(/Selisih ditampilkan apa adanya dan tidak disesuaikan otomatis/)).toBeInTheDocument()
    expect(screen.getByText(/Cakupan sebagian/)).toBeInTheDocument()
    expect(screen.getByText('4.325.000,00')).toBeInTheDocument() // overall book balance as the API sums it

    const bank = within(screen.getByRole('region', { name: 'Dokumen vs buku besar BCA' }))
    const fact = (label: string) => within(bank.getByText(label).closest('div')!)
    expect(fact('Selisih dokumen').getByText('-100.000,00')).toBeInTheDocument()
    expect(fact('Selisih dokumen').getByText('Selisih')).toBeInTheDocument()
    expect(fact('Pembayaran vendor').getByText('900.000,00')).toBeInTheDocument()
    expect(fact('Mutasi bersih dokumen').getByText('3.875.000,00')).toBeInTheDocument()
    expect(fact('Mutasi buku besar dari dokumen').getByText('3.775.000,00')).toBeInTheDocument()
    expect(fact('Aktivitas lain di buku besar').getByText('300.000,00')).toBeInTheDocument()
    expect(fact('Aktivitas lain di buku besar').getByText(/bukan kesalahan/)).toBeInTheDocument()
    expect(fact('Saldo buku').getByText('4.075.000,00')).toBeInTheDocument()

    const statement = within(screen.getByRole('region', { name: 'Rekening koran terakhir BCA' }))
    expect(statement.getByRole('link', { name: 'BCA-2026-03' })).toHaveAttribute('href', '/app/akuntansi/rekening-koran/st-1')
    expect(statement.getByText('4.000.000,00')).toBeInTheDocument()
    expect(statement.getByText('75.000,00')).toBeInTheDocument()
    expect(statement.getByText('Sedang berjalan')).toBeInTheDocument()
    expect(statement.getByText(/rekonsiliasi belum diselesaikan/)).toBeInTheDocument()

    expect(within(screen.getByRole('region', { name: 'Rekening koran terakhir KAS' })).getByText('Akun kas tidak memiliki rekening koran.')).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'Dokumen vs buku besar KAS' })).getByText('Cocok')).toBeInTheDocument()

    // The filters are sent to the API as the report's own parameters, and the export carries them.
    await screen.findByRole('option', { name: 'KAS · Kas kecil' })
    await userEvent.selectOptions(screen.getByLabelText('Akun kas/bank'), 'cb-2')
    fireEvent.change(screen.getByLabelText(/Per tanggal/), { target: { value: '2026-03-17' } })
    await waitFor(() => expect(calls.filter((c) => c.url === REPORT).at(-1)?.params).toEqual({ as_of: '2026-03-17', cash_bank_account_id: 'cb-2' }))
    expect(screen.getByRole('button', { name: 'Ekspor CSV' })).toBeInTheDocument()
  })

  it('confirms a clean report without a partial-scope notice, and shows a completed statement difference', async () => {
    const reconciled = reportAccount({
      statement: { id: 'st-1', reference: 'BCA-2026-03', statement_date: '2026-03-31', status: 'COMPLETED', statement_balance: '4000000.0000', book_minus_statement: '75000.0000', unexplained_difference: '-65000.0000', reconciliation: 'DIFFERENCE' },
    })
    boot({ permissions: ['accounting.reconciliation.cash_bank.view'] }, { [`GET ${REPORT}`]: { data: report({ accounts: [reconciled] }) } })
    renderApp('/app/akuntansi/rekonsiliasi/kas-bank')

    expect(await screen.findByText(/Dokumen sesuai dengan buku besar pada semua akun/)).toBeInTheDocument()
    expect(screen.queryByText(/Cakupan sebagian/)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument() // no export permission
    const statement = within(screen.getByRole('region', { name: 'Rekening koran terakhir BCA' }))
    expect(statement.getByText('-65.000,00')).toBeInTheDocument()
    expect(statement.getByText('Ada selisih')).toBeInTheDocument()
    expect(statement.queryByRole('link', { name: 'BCA-2026-03' })).not.toBeInTheDocument() // no permission to open statements: plain text
    expect(statement.getByText('BCA-2026-03')).toBeInTheDocument()
  })

  it('handles no accounts and a failing report', async () => {
    let fail = true
    boot({ permissions: ['accounting.reconciliation.cash_bank.view'] }, { [`GET ${REPORT}`]: () => (fail ? { status: 500, data: {} } : { data: report({ accounts: [], book_balance: '0.0000' }) }) })
    renderApp('/app/akuntansi/rekonsiliasi/kas-bank')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Tidak ada akun kas/bank')).toBeInTheDocument()
    expect(screen.queryByText(/Dokumen sesuai dengan buku besar/)).not.toBeInTheDocument() // nothing to compare is not "all matched"
  })
})
