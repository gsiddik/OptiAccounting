import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../../test/render'
import { RC, account, boot, detail, header, line, manage, published, unmapped, validation, view, type Calls } from './testkit'

afterEach(() => vi.restoreAllMocks())

const DETAIL = '/app/akuntansi/pemetaan-laporan/d-1'
const URL_ONE = `${RC}/report-definitions/d-1`
const URL_UNMAPPED = `${URL_ONE}/unmapped-accounts`
const URL_ACCOUNTS = `${RC}/accounts`

const accounts = [account('a-4100', '4100', 'Penjualan', { account_type: 'REVENUE' }), account('a-6200', '6200', 'Beban Sewa'), account('a-6300', '6300', 'Beban Listrik'), account('a-1110', '1110', 'Kas', { account_type: 'ASSET' })]
const routes = (over: Record<string, unknown> = {}) => ({
  [`GET ${URL_ONE}`]: { data: detail() },
  [`GET ${URL_UNMAPPED}`]: { data: { data: [unmapped(), unmapped({ id: 'a-6100', code: '6100', name: 'Beban Pemasaran', balance: '0.0000', has_activity: false })] } },
  [`GET ${URL_ACCOUNTS}`]: { data: { data: accounts } },
  ...over,
})
const sent = (calls: Calls, method: string, url: string) => calls.filter((c) => c.method === method && c.url === url)
const linesTable = async () => within(await screen.findByRole('table', { name: 'Baris laporan' }))

describe('definition detail: what is shown', () => {
  it('shows the line tree with each line type, its balance side and the accounts mapped to it', async () => {
    boot(manage, routes())
    renderApp(DETAIL)

    expect(await screen.findByRole('heading', { name: 'Laporan Laba Rugi' })).toBeInTheDocument()
    const table = await linesTable()
    expect(table.getByText('REV_SALES')).toBeInTheDocument()
    expect(table.getByText('Kredit - debit')).toBeInTheDocument()
    expect(table.getByText('Debit - kredit')).toBeInTheDocument()
    expect(table.getByText('4100')).toBeInTheDocument()
    expect(table.getByText(/· Beban Sewa/)).toBeInTheDocument()
    expect(table.getByText('Total hitung')).toBeInTheDocument()
    expect(table.getByText('+ REV − EXP')).toBeInTheDocument()
    expect(screen.getByText('Draf', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText('Belum berlaku')).toBeInTheDocument()
    expect(await screen.findByRole('table', { name: 'Akun belum dipetakan' })).toBeInTheDocument()
  })

  it('offers every authoring action on a draft to a user who may manage definitions', async () => {
    boot(manage, routes())
    renderApp(DETAIL)
    await linesTable()

    for (const name of ['Ubah nama', 'Terbitkan', 'Hapus draf', 'Tambah baris', 'Petakan akun ke REV_SALES', 'Ubah baris REV', 'Hapus baris REV', 'Tambah sub-baris di REV', 'Lepas akun 4100 dari REV_SALES']) {
      expect(screen.getByRole('button', { name })).toBeInTheDocument()
    }
    expect(await screen.findByRole('button', { name: 'Petakan akun 5100' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Versi baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Arsipkan' })).not.toBeInTheDocument()
  })

  it('shows a published definition read-only and explains that a new version is the only way to revise it', async () => {
    boot(manage, routes({ [`GET ${URL_ONE}`]: { data: published() } }))
    renderApp(DETAIL)
    await linesTable()

    expect(screen.getByText(/bersifat final: baris dan pemetaannya tidak dapat diubah/)).toBeInTheDocument()
    expect(screen.getByText('Terbit', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText('01 Jan 2026 – sekarang')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Versi baru' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Arsipkan' })).toBeInTheDocument()
    for (const name of ['Terbitkan', 'Hapus draf', 'Tambah baris', 'Ubah nama', 'Petakan akun ke REV_SALES', 'Ubah baris REV', 'Lepas akun 4100 dari REV_SALES', 'Naikkan REV']) {
      expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    }
    await screen.findByRole('table', { name: 'Akun belum dipetakan' })
    expect(screen.queryByRole('button', { name: 'Petakan akun 5100' })).not.toBeInTheDocument()
  })

  it('offers a new version but no archive for an archived definition', async () => {
    boot(manage, routes({ [`GET ${URL_ONE}`]: { data: detail({ status: 'ARCHIVED', effective_from: '2025-01-01', effective_to: '2025-12-31' }) } }))
    renderApp(DETAIL)
    await linesTable()
    expect(screen.getByRole('button', { name: 'Versi baru' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Arsipkan' })).not.toBeInTheDocument()
    expect(screen.getByText('01 Jan 2025 – 31 Des 2025')).toBeInTheDocument()
    expect(screen.getByText(/sudah diarsipkan dan bersifat final/)).toBeInTheDocument()
  })

  it('gives a user without the manage permission a read-only view of a draft', async () => {
    boot(view, routes())
    renderApp(DETAIL)
    await linesTable()
    expect(screen.getByText(/Draf ini hanya dapat dilihat/)).toBeInTheDocument()
    for (const name of ['Terbitkan', 'Ubah nama', 'Hapus draf', 'Tambah baris', 'Petakan akun ke REV_SALES', 'Hapus baris REV']) {
      expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    }
    expect(screen.getByRole('button', { name: 'Validasi sekarang' })).toBeInTheDocument()
    await screen.findByRole('table', { name: 'Akun belum dipetakan' })
    expect(screen.queryByRole('button', { name: 'Petakan akun 5100' })).not.toBeInTheDocument()
  })

  it('drops every action on a read-only subscription even for a manager', async () => {
    boot(manage, routes(), { mode: 'READ_ONLY' })
    renderApp(DETAIL)
    await linesTable()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Terbitkan' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tambah baris' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Petakan akun ke REV_SALES' })).not.toBeInTheDocument()
  })

  it('shows a refusal and loads again on retry', async () => {
    let attempt = 0
    boot(view, routes({ [`GET ${URL_ONE}`]: () => (++attempt === 1 ? { status: 404, data: { message: 'x' } } : { data: detail() }) }))
    renderApp(DETAIL)
    expect(await screen.findByText('Data tidak ditemukan.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('heading', { name: 'Laporan Laba Rugi' })).toBeInTheDocument()
  })
})

describe('definition detail: validation', () => {
  it('posts the validation and lists errors and warnings in Indonesian with the line they are about', async () => {
    const calls = boot(manage, routes({
      [`POST ${URL_ONE}/validate`]: {
        data: validation({
          valid: false,
          errors: [{ code: 'OPERAND_LINE_MISSING', message: 'x', line: 'RESULT', details: { operand: 'GONE' } }, { code: 'SUBTOTAL_EMPTY', message: 'y', line: 'EXP', details: {} }],
          warnings: [{ code: 'UNMAPPED_ACCOUNT_WITH_BALANCE', message: 'z', line: null, details: { count: 2 } }],
        }),
      },
    }))
    renderApp(DETAIL)
    await linesTable()
    expect(screen.getByText(/Belum diperiksa pada sesi ini/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Validasi sekarang' }))
    expect(await screen.findByText('Definisi belum valid.')).toBeInTheDocument()
    expect(screen.getByText('Operand GONE pada RESULT tidak ada dalam definisi ini.')).toBeInTheDocument()
    expect(screen.getByText('Subtotal EXP membutuhkan minimal satu baris bernilai di bawahnya.')).toBeInTheDocument()
    expect(screen.getByText(/2 akun bersaldo atau beraktivitas belum tercakup/)).toBeInTheDocument()
    expect(sent(calls, 'POST', `${URL_ONE}/validate`)).toHaveLength(1)
  })

  it('says a valid definition is valid, with its warnings counted', async () => {
    boot(manage, routes({ [`POST ${URL_ONE}/validate`]: { data: validation({ warnings: [{ code: 'LINE_WITHOUT_MAPPING', message: 'w', line: 'EXP_RENT', details: {} }] }) } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Validasi sekarang' }))
    expect(await screen.findByText('Definisi valid.')).toBeInTheDocument()
    expect(screen.getByText(/Ada 1 peringatan/)).toBeInTheDocument()
    expect(screen.getByText('Baris EXP_RENT belum memiliki akun, sehingga nilainya selalu nol.')).toBeInTheDocument()
  })
})

describe('account mapping', () => {
  it('maps an account to a line by sending the whole mapping with the new entry, then shows the saved mapping and its validation', async () => {
    const saved = detail({ lines: [...detail().lines.slice(0, 3), line({ id: 'l-4', code: 'EXP_RENT', label: 'Beban sewa', line_type: 'ACCOUNT', parent_code: 'EXP', depth: 1, balance_side: 'DEBIT', mappings: [
      { account_id: 'a-6200', code: '6200', name: 'Beban Sewa', account_type: 'EXPENSE', is_postable: true, status: 'ACTIVE', include_descendants: false },
      { account_id: 'a-6300', code: '6300', name: 'Beban Listrik', account_type: 'EXPENSE', is_postable: true, status: 'ACTIVE', include_descendants: false },
    ] })], validation: validation({ warnings: [{ code: 'LINE_WITHOUT_MAPPING', message: 'w', line: 'EXP', details: {} }] }) })
    const calls = boot(manage, routes({ [`PUT ${URL_ONE}/mappings`]: { data: saved } }))
    renderApp(DETAIL)
    await linesTable()

    await userEvent.click(screen.getByRole('button', { name: 'Petakan akun ke EXP_RENT' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Petakan akun ke baris' }))
    expect(dialog.getByLabelText('Baris')).toBeDisabled()
    await userEvent.selectOptions(dialog.getByLabelText('Akun'), await dialog.findByRole('option', { name: '6300 · Beban Listrik' }))
    expect(dialog.queryByRole('option', { name: '1110 · Kas' })).not.toBeInTheDocument() // a profit and loss statement uses no asset accounts
    await userEvent.click(dialog.getByRole('button', { name: 'Petakan' }))

    await waitFor(() => expect(sent(calls, 'PUT', `${URL_ONE}/mappings`)).toHaveLength(1))
    expect(sent(calls, 'PUT', `${URL_ONE}/mappings`)[0].data).toEqual({
      mappings: [
        { line_code: 'REV_SALES', account_id: 'a-4100', include_descendants: false },
        { line_code: 'EXP_RENT', account_id: 'a-6200', include_descendants: false },
        { line_code: 'EXP_RENT', account_id: 'a-6300', include_descendants: false },
      ],
    })
    expect(await screen.findByText('Pemetaan disimpan.')).toBeInTheDocument()
    expect((await linesTable()).getByText(/· Beban Listrik/)).toBeInTheDocument()
    expect(screen.getByText('Baris EXP belum memiliki akun, sehingga nilainya selalu nol.')).toBeInTheDocument()
    await waitFor(() => expect(sent(calls, 'GET', URL_UNMAPPED).length).toBeGreaterThanOrEqual(2)) // the unmapped list is read again
  })

  it('maps a header account with its descendants', async () => {
    const calls = boot(manage, routes({
      [`GET ${URL_ACCOUNTS}`]: { data: { data: [account('a-6000', '6000', 'Beban Usaha', { is_postable: false })] } },
      [`PUT ${URL_ONE}/mappings`]: { data: detail() },
    }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Petakan akun ke EXP_RENT' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Petakan akun ke baris' }))
    await userEvent.selectOptions(dialog.getByLabelText('Akun'), await dialog.findByRole('option', { name: '6000 · Beban Usaha (induk)' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Petakan' }))
    await waitFor(() => expect(sent(calls, 'PUT', `${URL_ONE}/mappings`)).toHaveLength(1))
    expect((sent(calls, 'PUT', `${URL_ONE}/mappings`)[0].data as { mappings: unknown[] }).mappings.at(-1)).toEqual({ line_code: 'EXP_RENT', account_id: 'a-6000', include_descendants: true })
  })

  it('shows the findings of the API when the proposed mapping is invalid (an account maps once per definition)', async () => {
    boot(manage, routes({
      [`PUT ${URL_ONE}/mappings`]: {
        status: 422,
        data: {
          code: 'REPORT_MAPPING_INVALID', message: 'The mapping has errors and was not saved.',
          details: { errors: [{ code: 'MAPPING_DUPLICATE_ACCOUNT', message: 'dup', line: 'EXP_RENT', details: { account_id: 'a-6200' } }], warnings: [] },
        },
      },
    }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Petakan akun ke EXP_RENT' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Petakan akun ke baris' }))
    await userEvent.selectOptions(dialog.getByLabelText('Akun'), await dialog.findByRole('option', { name: '6200 · Beban Sewa' }))
    expect(dialog.getByText(/Akun ini sudah dipetakan ke baris/)).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Petakan' }))

    expect(await dialog.findByText('Pemetaan memiliki kesalahan dan tidak disimpan. Perbaiki temuan berikut.')).toBeInTheDocument()
    expect(dialog.getByText('Akun 6200 · Beban Sewa dipetakan lebih dari satu kali pada baris EXP_RENT.')).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Petakan akun ke baris' })).toBeInTheDocument() // stays open for a correction
  })

  it('refuses to send a mapping without a line or an account', async () => {
    const calls = boot(manage, routes())
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Petakan akun ke REV_SALES' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Petakan akun ke baris' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Petakan' }))
    expect(await dialog.findByText('Pilih akun.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'PUT')).toBe(false)
  })

  it('releases an account from a line by sending the mapping without it', async () => {
    const calls = boot(manage, routes({ [`PUT ${URL_ONE}/mappings`]: { data: detail() } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Lepas akun 4100 dari REV_SALES' }))
    await waitFor(() => expect(sent(calls, 'PUT', `${URL_ONE}/mappings`)).toHaveLength(1))
    expect(sent(calls, 'PUT', `${URL_ONE}/mappings`)[0].data).toEqual({ mappings: [{ line_code: 'EXP_RENT', account_id: 'a-6200', include_descendants: false }] })
    expect(await screen.findByText('Akun dilepas dari baris.')).toBeInTheDocument()
  })
})

describe('unmapped accounts', () => {
  it('lists the accounts the server found with their balances and reads again for another date or with zero accounts', async () => {
    const calls = boot(manage, routes())
    renderApp(DETAIL)
    const table = within(await screen.findByRole('table', { name: 'Akun belum dipetakan' }))
    expect(table.getByText('5100')).toBeInTheDocument()
    expect(table.getByText(/· Beban Gaji/)).toBeInTheDocument()
    expect(table.getByText('2.500.000,00')).toBeInTheDocument()
    expect(table.getByText('Ada')).toBeInTheDocument()
    expect(sent(calls, 'GET', URL_UNMAPPED)[0].params).toEqual({})

    await userEvent.click(screen.getByRole('checkbox', { name: 'Hanya yang bersaldo atau beraktivitas' }))
    await waitFor(() => expect(sent(calls, 'GET', URL_UNMAPPED).at(-1)?.params).toEqual({ only_with_balance: 0 }))
    const date = screen.getByLabelText('Per tanggal') as HTMLInputElement
    await userEvent.type(date, '2026-09-30')
    await waitFor(() => expect(sent(calls, 'GET', URL_UNMAPPED).at(-1)?.params).toEqual({ as_of: '2026-09-30', only_with_balance: 0 }))
  })

  it('quick-maps an unmapped account to a chosen line', async () => {
    const calls = boot(manage, routes({ [`PUT ${URL_ONE}/mappings`]: { data: detail() } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(await screen.findByRole('button', { name: 'Petakan akun 5100' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Petakan akun ke baris' }))
    expect(dialog.getByDisplayValue('5100 · Beban Gaji')).toHaveAttribute('readonly')
    await userEvent.selectOptions(dialog.getByLabelText('Baris'), 'EXP_RENT')
    await userEvent.click(dialog.getByRole('button', { name: 'Petakan' }))

    await waitFor(() => expect(sent(calls, 'PUT', `${URL_ONE}/mappings`)).toHaveLength(1))
    expect((sent(calls, 'PUT', `${URL_ONE}/mappings`)[0].data as { mappings: unknown[] }).mappings.at(-1)).toEqual({ line_code: 'EXP_RENT', account_id: 'a-5100', include_descendants: false })
  })

  it('says when every relevant account is covered', async () => {
    boot(manage, routes({ [`GET ${URL_UNMAPPED}`]: { data: { data: [] } } }))
    renderApp(DETAIL)
    expect(await screen.findByText('Semua akun sudah tercakup')).toBeInTheDocument()
  })

  it('shows a refusal of the unmapped list with a retry', async () => {
    let attempt = 0
    boot(manage, routes({ [`GET ${URL_UNMAPPED}`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: { data: [] } }) }))
    renderApp(DETAIL)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Semua akun sudah tercakup')).toBeInTheDocument()
  })
})

describe('lines', () => {
  it('adds an account line: the body carries the type-specific fields and null for the rest', async () => {
    const calls = boot(manage, routes({ [`POST ${URL_ONE}/lines`]: { status: 201, data: detail() } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Tambah baris' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Tambah baris' }))

    await userEvent.type(dialog.getByLabelText('Kode'), 'EXP_SALARY')
    await userEvent.type(dialog.getByLabelText('Uraian'), 'Beban gaji')
    await userEvent.selectOptions(dialog.getByLabelText('Berada di bawah'), 'EXP')
    await userEvent.selectOptions(dialog.getByLabelText('Sisi saldo'), 'DEBIT')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(sent(calls, 'POST', `${URL_ONE}/lines`)).toHaveLength(1))
    expect(sent(calls, 'POST', `${URL_ONE}/lines`)[0].data).toEqual({
      code: 'EXP_SALARY', label: 'Beban gaji', line_type: 'ACCOUNT', parent_code: 'EXP', balance_side: 'DEBIT', cash_flow_category: null, system_key: null, operands: null, show_zero: false, emphasis: false, notes: null,
    })
    expect(await screen.findByText('Baris ditambahkan.')).toBeInTheDocument()
  })

  it('adds a calculated total from operands only: plus or minus over other lines, never a free formula', async () => {
    const calls = boot(manage, routes({ [`POST ${URL_ONE}/lines`]: { status: 201, data: detail() } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Tambah baris' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Tambah baris' }))
    await userEvent.type(dialog.getByLabelText('Kode'), 'MARGIN')
    await userEvent.type(dialog.getByLabelText('Uraian'), 'Margin')
    await userEvent.selectOptions(dialog.getByLabelText('Jenis baris'), 'CALCULATED_TOTAL')
    expect(dialog.queryByLabelText('Sisi saldo')).not.toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await dialog.findByText('Tambahkan minimal satu operand.')).toBeInTheDocument()

    await userEvent.click(dialog.getByRole('button', { name: 'Tambah operand' }))
    await userEvent.selectOptions(dialog.getByLabelText('Baris operand 1'), 'REV')
    await userEvent.click(dialog.getByRole('button', { name: 'Tambah operand' }))
    await userEvent.selectOptions(dialog.getByLabelText('Tanda operand 2'), '-')
    await userEvent.selectOptions(dialog.getByLabelText('Baris operand 2'), 'EXP')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(sent(calls, 'POST', `${URL_ONE}/lines`)).toHaveLength(1))
    expect(sent(calls, 'POST', `${URL_ONE}/lines`)[0].data).toMatchObject({
      code: 'MARGIN', line_type: 'CALCULATED_TOTAL', balance_side: null, system_key: null, operands: [{ line: 'REV', sign: '+' }, { line: 'EXP', sign: '-' }],
    })
  })

  it('offers only the system figures of the statement type and a cash flow category only on a cash flow account line', async () => {
    boot(manage, routes({ [`GET ${URL_ONE}`]: { data: detail({ statement_type: 'CASH_FLOW' }) } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Tambah baris' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Tambah baris' }))
    expect(dialog.getByLabelText('Kategori arus kas')).toBeInTheDocument()
    await userEvent.selectOptions(dialog.getByLabelText('Jenis baris'), 'SYSTEM')
    expect(dialog.queryByLabelText('Kategori arus kas')).not.toBeInTheDocument()
    expect(dialog.getByRole('option', { name: 'Kas akhir' })).toBeInTheDocument()
    expect(dialog.queryByRole('option', { name: 'Laba (rugi) tahun berjalan' })).not.toBeInTheDocument()
  })

  it('changes a line without its code and clears what the old type used', async () => {
    const calls = boot(manage, routes({ [`PATCH ${URL_ONE}/lines/l-2`]: { data: detail() } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Ubah baris REV_SALES' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Ubah baris REV_SALES' }))
    expect(dialog.getByLabelText('Kode')).toBeDisabled()
    await userEvent.clear(dialog.getByLabelText('Uraian'))
    await userEvent.type(dialog.getByLabelText('Uraian'), 'Penjualan bersih')
    await userEvent.selectOptions(dialog.getByLabelText('Jenis baris'), 'SUBTOTAL')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(sent(calls, 'PATCH', `${URL_ONE}/lines/l-2`)).toHaveLength(1))
    const body = sent(calls, 'PATCH', `${URL_ONE}/lines/l-2`)[0].data as Record<string, unknown>
    expect(body).not.toHaveProperty('code')
    expect(body).toMatchObject({ label: 'Penjualan bersih', line_type: 'SUBTOTAL', parent_code: 'REV', display_order: 10, balance_side: null, system_key: null, operands: null })
  })

  it('deletes a line after confirmation and shows the API refusal when it still has sub-lines', async () => {
    const calls = boot(manage, routes({
      [`DELETE ${URL_ONE}/lines/l-1`]: { status: 409, data: { code: 'REPORT_LINE_HAS_CHILDREN', message: 'Remove or move the lines below this one first.', details: { code: 'REV' } } },
      [`DELETE ${URL_ONE}/lines/l-2`]: { data: detail({ lines: detail().lines.filter((l) => l.code !== 'REV_SALES') }) },
    }))
    renderApp(DETAIL)
    await linesTable()

    await userEvent.click(screen.getByRole('button', { name: 'Hapus baris REV' }))
    let dialog = within(await screen.findByRole('dialog', { name: 'Hapus baris REV' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus baris' }))
    expect(await dialog.findByText('Pindahkan atau hapus dulu baris yang berada di bawah baris ini.')).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Batal' }))

    await userEvent.click(screen.getByRole('button', { name: 'Hapus baris REV_SALES' }))
    dialog = within(await screen.findByRole('dialog', { name: 'Hapus baris REV_SALES' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus baris' }))
    expect(await screen.findByText('Baris dihapus.')).toBeInTheDocument()
    expect(sent(calls, 'DELETE', `${URL_ONE}/lines/l-2`)).toHaveLength(1)
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Hapus baris REV_SALES' })).not.toBeInTheDocument())
  })

  it('moves a line down among its siblings by renumbering them through the replace-lines endpoint', async () => {
    const calls = boot(manage, routes({ [`PUT ${URL_ONE}/lines`]: { data: detail() } }))
    renderApp(DETAIL)
    await linesTable()
    expect(screen.getByRole('button', { name: 'Naikkan REV' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Turunkan RESULT' })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: 'Turunkan REV' }))
    await waitFor(() => expect(sent(calls, 'PUT', `${URL_ONE}/lines`)).toHaveLength(1))
    const lines = (sent(calls, 'PUT', `${URL_ONE}/lines`)[0].data as { lines: { code: string; display_order: number; parent_code: string | null }[] }).lines
    expect(lines).toHaveLength(5)
    const order = Object.fromEntries(lines.map((l) => [l.code, l.display_order]))
    expect(order).toEqual({ EXP: 10, REV: 20, RESULT: 30, REV_SALES: 10, EXP_RENT: 10 })
    expect(lines.find((l) => l.code === 'EXP_RENT')!.parent_code).toBe('EXP')
    expect(await screen.findByText('Urutan baris disimpan.')).toBeInTheDocument()
  })

  it('shows the refusal of an action done from a row button above the table', async () => {
    boot(manage, routes({ [`PUT ${URL_ONE}/mappings`]: { status: 409, data: { code: 'REPORT_DEFINITION_NOT_DRAFT', message: 'A published report definition cannot change; create a new version.' } } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Lepas akun 4100 dari REV_SALES' }))
    expect(await screen.findByText('Definisi yang sudah terbit tidak dapat diubah. Buat versi baru untuk merevisinya.')).toBeInTheDocument()
  })
})

describe('publishing, versions and the draft itself', () => {
  it('publishes after a confirmation that says it is final, with the effective date, and then shows it read-only with the warnings', async () => {
    const calls = boot(manage, routes({
      [`POST ${URL_ONE}/publish`]: { data: { ...published({ effective_from: '2026-11-01' }), warnings: [{ code: 'LINE_WITHOUT_MAPPING', message: 'w', line: 'EXP', details: {} }] } },
    }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Terbitkan' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Terbitkan versi 1' }))
    expect(dialog.getByText(/tidak dapat diubah lagi/)).toBeInTheDocument()
    await userEvent.type(dialog.getByLabelText('Berlaku mulai'), '2026-11-01')
    await userEvent.click(dialog.getByRole('button', { name: 'Terbitkan' }))

    await waitFor(() => expect(sent(calls, 'POST', `${URL_ONE}/publish`)).toHaveLength(1))
    expect(sent(calls, 'POST', `${URL_ONE}/publish`)[0].data).toEqual({ effective_from: '2026-11-01' })
    expect(await screen.findByText('Definisi diterbitkan.')).toBeInTheDocument()
    expect(await screen.findByText(/bersifat final: baris dan pemetaannya tidak dapat diubah/)).toBeInTheDocument()
    expect(screen.getByText('Baris EXP belum memiliki akun, sehingga nilainya selalu nol.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tambah baris' })).not.toBeInTheDocument()
  })

  it('publishes without a date when none is typed', async () => {
    const calls = boot(manage, routes({ [`POST ${URL_ONE}/publish`]: { data: published() } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Terbitkan' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Terbitkan versi 1' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Terbitkan' }))
    await waitFor(() => expect(sent(calls, 'POST', `${URL_ONE}/publish`)).toHaveLength(1))
    expect(sent(calls, 'POST', `${URL_ONE}/publish`)[0].data).toEqual({})
  })

  it('keeps the draft and shows every finding when the server refuses to publish an invalid definition', async () => {
    boot(manage, routes({
      [`POST ${URL_ONE}/publish`]: {
        status: 422,
        data: {
          code: 'REPORT_DEFINITION_INVALID', message: 'The report definition has configuration errors and cannot be published.',
          details: { errors: [{ code: 'CALCULATION_CYCLE', message: 'c', line: 'RESULT', details: {} }], warnings: [{ code: 'LINE_WITHOUT_MAPPING', message: 'w', line: 'EXP', details: {} }] },
        },
      },
    }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Terbitkan' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Terbitkan versi 1' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Terbitkan' }))

    expect(await dialog.findByText('Definisi memiliki kesalahan konfigurasi sehingga tidak dapat dipakai atau diterbitkan. Perbaiki temuan berikut.')).toBeInTheDocument()
    expect(dialog.getByText('Baris RESULT bergantung pada dirinya sendiri, langsung atau lewat total lain.')).toBeInTheDocument()
    expect(dialog.getByText('Baris EXP belum memiliki akun, sehingga nilainya selalu nol.')).toBeInTheDocument()
  })

  it('shows the window overlap refusal of a publish in Indonesian', async () => {
    boot(manage, routes({ [`POST ${URL_ONE}/publish`]: { status: 409, data: { code: 'REPORT_WINDOW_OVERLAP', message: 'Another version' } } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Terbitkan' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Terbitkan versi 1' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Terbitkan' }))
    expect(await dialog.findByText('Versi lain dari laporan ini sudah mencakup tanggal tersebut. Pilih tanggal berlaku yang tidak bertabrakan.')).toBeInTheDocument()
  })

  it('creates a new version of a published definition after confirmation and opens the new draft', async () => {
    const calls = boot(manage, routes({
      [`GET ${URL_ONE}`]: { data: published() },
      [`POST ${URL_ONE}/new-version`]: { status: 201, data: detail({ id: 'd-2', version: 2, based_on_id: 'd-1' }) },
      [`GET ${RC}/report-definitions/d-2`]: { data: detail({ id: 'd-2', version: 2, based_on_id: 'd-1' }) },
      [`GET ${RC}/report-definitions/d-2/unmapped-accounts`]: { data: { data: [] } },
    }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Versi baru' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Buat versi baru dari versi 1' }))
    expect(dialog.getByText(/Versi ini tidak berubah/)).toBeInTheDocument()
    expect(sent(calls, 'POST', `${URL_ONE}/new-version`)).toHaveLength(0)
    await userEvent.click(dialog.getByRole('button', { name: 'Buat versi baru' }))

    await waitFor(() => expect(sent(calls, 'POST', `${URL_ONE}/new-version`)).toHaveLength(1))
    expect(await screen.findByText('Draf', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'berdasarkan versi sebelumnya' })).toHaveAttribute('href', '/app/akuntansi/pemetaan-laporan/d-1')
    expect(screen.getByRole('button', { name: 'Terbitkan' })).toBeInTheDocument()
  })

  it('shows that only one draft per statement may exist when a new version is refused', async () => {
    boot(manage, routes({
      [`GET ${URL_ONE}`]: { data: published() },
      [`POST ${URL_ONE}/new-version`]: { status: 409, data: { code: 'REPORT_DRAFT_EXISTS', message: 'There is already a draft', details: { definition_id: 'd-9' } } },
    }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Versi baru' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Buat versi baru dari versi 1' }))
    await userEvent.click(dialog.getByRole('button', { name: 'Buat versi baru' }))
    expect(await dialog.findByText('Sudah ada draf untuk jenis laporan ini. Terbitkan atau hapus draf itu lebih dulu.')).toBeInTheDocument()
  })

  it('archives a published definition with an optional end date', async () => {
    const calls = boot(manage, routes({
      [`GET ${URL_ONE}`]: { data: published() },
      [`POST ${URL_ONE}/archive`]: { data: detail({ status: 'ARCHIVED', effective_from: '2026-01-01', effective_to: '2026-12-31' }) },
    }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Arsipkan' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Arsipkan versi 1' }))
    await userEvent.type(dialog.getByLabelText('Berlaku sampai'), '2026-12-31')
    await userEvent.click(dialog.getByRole('button', { name: 'Arsipkan' }))

    await waitFor(() => expect(sent(calls, 'POST', `${URL_ONE}/archive`)).toHaveLength(1))
    expect(sent(calls, 'POST', `${URL_ONE}/archive`)[0].data).toEqual({ effective_to: '2026-12-31' })
    expect(await screen.findByText('Diarsipkan', { selector: '.badge' })).toBeInTheDocument()
    expect(screen.getByText('01 Jan 2026 – 31 Des 2026')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Arsipkan' })).not.toBeInTheDocument()
  })

  it('renames a draft', async () => {
    const calls = boot(manage, routes({ [`PATCH ${URL_ONE}`]: { data: detail({ name: 'Laba rugi 2027' }) } }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Ubah nama' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Ubah nama dan keterangan' }))
    await userEvent.clear(dialog.getByLabelText('Nama'))
    await userEvent.type(dialog.getByLabelText('Nama'), 'Laba rugi 2027')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    await waitFor(() => expect(sent(calls, 'PATCH', URL_ONE)).toHaveLength(1))
    expect(sent(calls, 'PATCH', URL_ONE)[0].data).toEqual({ name: 'Laba rugi 2027', description: null })
    expect(await screen.findByRole('heading', { name: 'Laba rugi 2027' })).toBeInTheDocument()
  })

  it('deletes a draft after confirmation and returns to the list', async () => {
    const calls = boot(manage, routes({
      [`DELETE ${URL_ONE}`]: { status: 204, data: null },
      [`GET ${RC}/report-definitions`]: { data: { data: [header({ id: 'd-0', name: 'Neraca', statement_type: 'BALANCE_SHEET', status: 'ARCHIVED' })] } },
    }))
    renderApp(DETAIL)
    await linesTable()
    await userEvent.click(screen.getByRole('button', { name: 'Hapus draf' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Hapus draf' }))
    expect(sent(calls, 'DELETE', URL_ONE)).toHaveLength(0)
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus draf' }))
    await waitFor(() => expect(sent(calls, 'DELETE', URL_ONE)).toHaveLength(1))
    expect(await screen.findByRole('table', { name: 'Daftar definisi laporan' })).toBeInTheDocument()
  })
})
