import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { AP, boot, category, chart } from './testkit'

afterEach(() => vi.restoreAllMocks())

const view = ['accounting.asset.view']
const manage = [...view, 'accounting.asset_category.manage']
const land = category({ id: 'c-2', code: 'TNH', name: 'Tanah', default_method: 'NONE', default_useful_life_months: null, default_residual_type: 'NONE', default_residual_value: '0.0000', asset_account_id: 'a-1500', asset_account: { id: 'a-1500', code: '1500', name: 'Kendaraan' } })
const list = (rows: unknown[]) => ({ 'GET /app/accounting/asset-categories': { data: page(rows) } })

describe('asset categories', () => {
  it('lists the defaults and accounts of each category and hides every management control without the permission', async () => {
    boot(view, list([category(), land]))
    renderApp('/app/akuntansi/kategori-aset')

    expect(await screen.findByText('Garis lurus · 48 bulan · mulai bulan kapitalisasi')).toBeInTheDocument()
    expect(screen.getByText('Persentase dari biaya: 10,00%')).toBeInTheDocument()
    expect(screen.getByText('Tidak disusutkan · mulai bulan kapitalisasi')).toBeInTheDocument()
    expect(screen.getByText('Mengikuti pemetaan peran akun')).toBeInTheDocument() // KEND names no account
    expect(screen.getByText('Aset:')).toBeInTheDocument() // TNH names an asset account
    for (const label of ['Kategori baru', 'Ubah', 'Nonaktifkan', 'Hapus']) expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
  })

  it('offers no management control in a read-only subscription even for a user who may manage', async () => {
    boot(manage, list([category()]), { mode: 'READ_ONLY' })
    renderApp('/app/akuntansi/kategori-aset')

    expect(await screen.findByText('Kendaraan')).toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Kategori baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ubah' })).not.toBeInTheDocument()
  })

  it('searches and filters on the server', async () => {
    const calls = boot(view, list([category()]))
    renderApp('/app/akuntansi/kategori-aset')
    await screen.findByText('Kendaraan')

    await userEvent.selectOptions(screen.getByLabelText('Status kategori'), 'INACTIVE')
    await userEvent.type(screen.getByLabelText('Cari kategori'), 'ken')
    await waitFor(() => expect(calls.some((c) => c.url === `${AP}/asset-categories` && c.params?.q === 'ken' && c.params?.status === 'INACTIVE')).toBe(true))
  })

  it('creates a category with its defaults, offering each account field only the account types the API accepts', async () => {
    const calls = boot(manage, { ...list([]), 'GET /app/accounting/accounts': { data: { data: chart } }, 'POST /app/accounting/asset-categories': { status: 201, data: category() } })
    renderApp('/app/akuntansi/kategori-aset')
    await userEvent.click(await screen.findByRole('button', { name: 'Kategori baru' }))

    const dialog = within(screen.getByRole('dialog', { name: 'Kategori aset baru' }))
    await userEvent.type(dialog.getByLabelText('Kode'), 'KEND')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Kendaraan')
    await userEvent.type(dialog.getByLabelText('Umur manfaat default (bulan)'), '48')
    await userEvent.selectOptions(dialog.getByLabelText('Kebijakan nilai sisa'), 'PERCENT')
    await userEvent.type(dialog.getByLabelText('Nilai sisa default (persen)'), '10')
    // Asset accounts: active, postable, not a control account. Header, liability and control accounts are not offered.
    await waitFor(() => expect(within(dialog.getByLabelText('Akun aset tetap')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Ikuti pemetaan peran akun', '1120 · Bank BCA', '1500 · Kendaraan', '1590 · Akumulasi Penyusutan Kendaraan']))
    expect(within(dialog.getByLabelText('Akun beban penyusutan')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Ikuti pemetaan peran akun', '6500 · Beban Penyusutan'])
    expect(within(dialog.getByLabelText('Akun laba/rugi pelepasan')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Ikuti pemetaan peran akun', '4250 · Laba Rugi Pelepasan Aset', '6500 · Beban Penyusutan'])
    await userEvent.selectOptions(dialog.getByLabelText('Akun aset tetap'), 'a-1500')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST' && c.url === `${AP}/asset-categories`)).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({
      code: 'KEND', name: 'Kendaraan', description: null, default_method: 'STRAIGHT_LINE', default_useful_life_months: 48, default_residual_type: 'PERCENT', default_residual_value: '10.0000',
      default_start_policy: 'CAPITALIZATION_MONTH', asset_account_id: 'a-1500', accumulated_account_id: null, expense_account_id: null, gain_loss_account_id: null,
    })
    expect(await screen.findByText('Kategori disimpan.')).toBeInTheDocument()
  })

  it('sends no useful life for a method that never depreciates and no residual value without a residual policy', async () => {
    const calls = boot(manage, { ...list([]), 'GET /app/accounting/accounts': { data: { data: chart } }, 'POST /app/accounting/asset-categories': { status: 201, data: land } })
    renderApp('/app/akuntansi/kategori-aset')
    await userEvent.click(await screen.findByRole('button', { name: 'Kategori baru' }))

    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'TNH')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Tanah')
    await userEvent.type(dialog.getByLabelText('Umur manfaat default (bulan)'), '60')
    await userEvent.selectOptions(dialog.getByLabelText('Metode penyusutan default'), 'NONE')
    expect(dialog.getByLabelText('Umur manfaat default (bulan)')).toBeDisabled()
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    const body = calls.find((c) => c.method === 'POST')!.data as Record<string, unknown>
    expect(body).toMatchObject({ default_method: 'NONE', default_useful_life_months: null, default_residual_type: 'NONE' })
    expect(body).not.toHaveProperty('default_residual_value')
  })

  it('refuses an invalid useful life before calling the API', async () => {
    const calls = boot(manage, { ...list([]), 'GET /app/accounting/accounts': { data: { data: chart } } })
    renderApp('/app/akuntansi/kategori-aset')
    await userEvent.click(await screen.findByRole('button', { name: 'Kategori baru' }))

    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'X')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Contoh')
    await userEvent.type(dialog.getByLabelText('Umur manfaat default (bulan)'), '1500')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    expect(await dialog.findByText('Umur manfaat harus bilangan bulat antara 1 dan 1200 bulan.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('edits a category without sending its fixed code and clears an account override with null', async () => {
    const calls = boot(manage, { ...list([land]), 'GET /app/accounting/accounts': { data: { data: chart } }, 'PATCH /app/accounting/asset-categories/c-2': { data: land } })
    renderApp('/app/akuntansi/kategori-aset')
    await userEvent.click(await screen.findByRole('button', { name: 'Ubah' }))

    const dialog = within(screen.getByRole('dialog', { name: 'Ubah kategori TNH' }))
    expect(dialog.getByLabelText('Kode')).toBeDisabled()
    expect(dialog.getByLabelText('Akun aset tetap')).toHaveValue('a-1500')
    await userEvent.selectOptions(dialog.getByLabelText('Akun aset tetap'), '')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'PATCH')).toBeDefined())
    const body = calls.find((c) => c.method === 'PATCH')!.data as Record<string, unknown>
    expect(body).toMatchObject({ name: 'Tanah', asset_account_id: null, default_method: 'NONE' })
    expect(body).not.toHaveProperty('code')
  })

  it('shows the server refusal to delete a used category in Indonesian and deactivates it instead', async () => {
    const calls = boot(manage, {
      ...list([category()]),
      'DELETE /app/accounting/asset-categories/c-1': { status: 409, data: { message: 'used', code: 'ASSET_CATEGORY_IN_USE', details: {} } },
      'POST /app/accounting/asset-categories/c-1/deactivate': { data: category({ status: 'INACTIVE' }) },
    })
    renderApp('/app/akuntansi/kategori-aset')
    await userEvent.click(await screen.findByRole('button', { name: 'Hapus' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Hapus' }))
    expect(await screen.findByText('Kategori ini sudah dipakai aset: nonaktifkan, jangan hapus.')).toBeInTheDocument()

    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Batal' }))
    await userEvent.click(screen.getByRole('button', { name: 'Nonaktifkan' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Nonaktifkan' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === `${AP}/asset-categories/c-1/deactivate`)).toBe(true))
    expect(await screen.findByText('Status kategori diperbarui.')).toBeInTheDocument()
  })

  it('activates an inactive category', async () => {
    const calls = boot(manage, { ...list([category({ status: 'INACTIVE' })]), 'POST /app/accounting/asset-categories/c-1/activate': { data: category() } })
    renderApp('/app/akuntansi/kategori-aset')
    await userEvent.click(await screen.findByRole('button', { name: 'Aktifkan' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Aktifkan' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${AP}/asset-categories/c-1/activate`)).toBe(true))
  })

  it('attaches a business-rule refusal to the field named in its details', async () => {
    boot(manage, { ...list([]), 'GET /app/accounting/accounts': { data: { data: chart } }, 'POST /app/accounting/asset-categories': { status: 422, data: { message: 'taken', code: 'ASSET_CATEGORY_CODE_INVALID', details: { field: 'code' } } } })
    renderApp('/app/akuntansi/kategori-aset')
    await userEvent.click(await screen.findByRole('button', { name: 'Kategori baru' }))
    const dialog = within(screen.getByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'A B')
    await userEvent.type(dialog.getByLabelText('Nama'), 'Contoh')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    const messages = await dialog.findAllByText('Kode kategori hanya boleh berisi huruf, angka, titik, garis bawah, atau strip.')
    expect(messages.length).toBe(2) // the banner and the field
    expect(dialog.getByLabelText('Kode')).toHaveAttribute('aria-invalid', 'true')
  })

  it('shows an empty state', async () => {
    boot(manage, list([]))
    renderApp('/app/akuntansi/kategori-aset')
    expect(await screen.findByText('Belum ada kategori aset')).toBeInTheDocument()
  })
})
