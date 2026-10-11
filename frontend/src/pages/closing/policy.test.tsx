import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../test/render'
import { CL, boot, policy, policyManager, posts, template, view } from './testkit'

afterEach(() => vi.restoreAllMocks())

const PATH = '/app/akuntansi/penutupan/kebijakan'
const POLICY = `${CL}/closing/policy`
const TEMPLATES = `${CL}/closing/checklist-templates`

const routes = (over: Record<string, unknown> = {}) => ({
  [`GET ${POLICY}`]: { data: policy() },
  [`GET ${TEMPLATES}`]: { data: { data: [template(), template({ id: 't-2', code: 'SIGN_PACK', title: 'Tanda tangan paket laporan', required: false, sort_order: 2, status: 'INACTIVE', description: null })] } },
  ...over,
})
const checksTable = async () => within(await screen.findByRole('table', { name: 'Perilaku setiap pemeriksaan penutupan' }))
const apSelect = () => screen.getByLabelText('Perilaku Rekonsiliasi utang usaha dengan buku besar')
const save = () => screen.getByRole('button', { name: 'Simpan kebijakan' })

describe('closing policy', () => {
  it('shows the locked checks as locked and the rest as editable, with the defaults and the module each depends on', async () => {
    boot(policyManager, routes())
    renderApp(PATH)

    expect(await screen.findByRole('heading', { name: 'Kebijakan penutupan' })).toBeInTheDocument()
    const table = await checksTable()
    expect(table.getByText('Terkunci', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText(/selalu menghalangi \(aturan akuntansi\)/)).toBeInTheDocument()
    expect(table.queryByLabelText('Perilaku Neraca saldo seimbang')).not.toBeInTheDocument()
    expect(apSelect()).toHaveValue('BLOCK')
    expect(screen.getByLabelText('Perilaku Jurnal draf pada periode')).toHaveValue('WARN')
    expect(table.getByText('Hanya bila modul Utang usaha berlangganan')).toBeInTheDocument()
    expect(table.getAllByText('Selalu berlaku').length).toBe(2)
    expect(screen.getByLabelText('Toleransi rekonsiliasi')).toHaveValue('0.0000')
    expect(screen.getByLabelText('Batas mundur buka kembali (periode)')).toHaveValue('')
    expect(screen.getByRole('checkbox', { name: /periode yang lebih baru tidak tertutup/ })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: /Pemisahan tugas/ })).not.toBeChecked()
    expect(save()).toBeDisabled()
    expect(screen.getByText('Belum ada perubahan.')).toBeInTheDocument()
  })

  it('is read-only for a user without the manage permission', async () => {
    boot(view, routes())
    renderApp(PATH)
    const table = await checksTable()
    expect(table.queryAllByRole('combobox')).toHaveLength(0)
    expect(screen.queryByRole('button', { name: 'Simpan kebijakan' })).not.toBeInTheDocument()
    expect(screen.getByText(/Tampilan hanya baca/)).toBeInTheDocument()
    expect(screen.getByText('Tanpa batas')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tambah butir' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ubah REVIEW_ACCRUALS' })).not.toBeInTheDocument()
  })

  it('is read-only in a read-only subscription even for a manager', async () => {
    boot(policyManager, routes(), { mode: 'READ_ONLY' })
    renderApp(PATH)
    const table = await checksTable()
    expect(table.queryAllByRole('combobox')).toHaveLength(0)
    expect(screen.queryByRole('button', { name: 'Simpan kebijakan' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Tambah butir' })).not.toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
  })

  it('sends only what changed and shows the saved policy', async () => {
    const saved = policy()
    saved.checks[2].severity = 'WARN'
    saved.settings.reconciliation_tolerance = '100.0000'
    saved.settings.separate_closer_and_reopener = true
    saved.settings.reopen_max_periods_back = 2
    const calls = boot(policyManager, routes({ [`PUT ${POLICY}`]: { data: saved } }))
    renderApp(PATH)
    await checksTable()

    await userEvent.selectOptions(apSelect(), 'WARN')
    const tolerance = screen.getByLabelText('Toleransi rekonsiliasi')
    await userEvent.clear(tolerance)
    await userEvent.type(tolerance, '100')
    await userEvent.type(screen.getByLabelText('Batas mundur buka kembali (periode)'), '2')
    await userEvent.click(screen.getByRole('checkbox', { name: /Pemisahan tugas/ }))
    expect(save()).toBeEnabled()
    await userEvent.click(save())

    expect(await screen.findByText('Kebijakan penutupan disimpan.')).toBeInTheDocument()
    const put = calls.find((c) => c.method === 'PUT')!
    expect(put.url).toBe(POLICY)
    expect(put.data).toEqual({
      rules: [{ check_code: 'AP_RECONCILIATION', severity: 'WARN' }],
      settings: { reconciliation_tolerance: '100', reopen_max_periods_back: 2, separate_closer_and_reopener: true },
    })
    await waitFor(() => expect(screen.getByLabelText('Toleransi rekonsiliasi')).toHaveValue('100.0000'))
    expect(apSelect()).toHaveValue('WARN')
    expect(save()).toBeDisabled()
  })

  it('clears the reopen limit by sending null', async () => {
    const withLimit = policy()
    withLimit.settings.reopen_max_periods_back = 3
    const calls = boot(policyManager, routes({ [`GET ${POLICY}`]: { data: withLimit }, [`PUT ${POLICY}`]: { data: policy() } }))
    renderApp(PATH)
    await checksTable()
    await userEvent.clear(screen.getByLabelText('Batas mundur buka kembali (periode)'))
    await userEvent.click(save())
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')).toBeDefined())
    expect(calls.find((c) => c.method === 'PUT')!.data).toEqual({ settings: { reopen_max_periods_back: null } })
  })

  it('does not send a tolerance or a limit that is not a number', async () => {
    const calls = boot(policyManager, routes())
    renderApp(PATH)
    await checksTable()
    const tolerance = screen.getByLabelText('Toleransi rekonsiliasi')
    await userEvent.clear(tolerance)
    await userEvent.type(tolerance, 'abc')
    await userEvent.click(save())
    expect(await screen.findByText(/Toleransi harus angka desimal/)).toBeInTheDocument()
    await userEvent.clear(tolerance)
    await userEvent.type(tolerance, '5')
    await userEvent.type(screen.getByLabelText('Batas mundur buka kembali (periode)'), 'x')
    await userEvent.click(save())
    expect(await screen.findByText(/Isi bilangan bulat 0 sampai 1000/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'PUT')).toBe(false)
  })

  it('shows the refusal of the server and keeps the edits', async () => {
    boot(policyManager, routes({ [`PUT ${POLICY}`]: { status: 422, data: { message: 'x', code: 'CLOSING_CHECK_LOCKED' } } }))
    renderApp(PATH)
    await checksTable()
    await userEvent.selectOptions(apSelect(), 'OFF')
    await userEvent.click(save())
    expect(await screen.findByText(/aturan akuntansi yang tetap dan selalu menghalangi penutupan/)).toBeInTheDocument()
    expect(apSelect()).toHaveValue('OFF')
  })

  it('shows an error with retry when the policy cannot be read', async () => {
    let attempt = 0
    boot(policyManager, routes({ [`GET ${POLICY}`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: policy() }) }))
    renderApp(PATH)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await checksTable()).toBeDefined()
  })
})

describe('checklist templates', () => {
  it('lists the templates with their kind and status', async () => {
    boot(view, routes())
    renderApp(PATH)
    const table = within(await screen.findByRole('table', { name: 'Butir daftar periksa penutupan' }))
    expect(table.getByText('Tinjau akrual')).toBeInTheDocument()
    expect(table.getByText('Periksa akrual akhir bulan')).toBeInTheDocument()
    expect(table.getByText('Wajib', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Opsional', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Aktif', { selector: '.badge' })).toBeInTheDocument()
    expect(table.getByText('Nonaktif', { selector: '.badge' })).toBeInTheDocument()
  })

  it('says so when there is none', async () => {
    boot(policyManager, routes({ [`GET ${TEMPLATES}`]: { data: { data: [] } } }))
    renderApp(PATH)
    expect(await screen.findByText('Belum ada butir')).toBeInTheDocument()
  })

  it('adds a template with the payload of the form', async () => {
    const calls = boot(policyManager, routes({ [`POST ${TEMPLATES}`]: { status: 201, data: template({ id: 't-3', code: 'CLOSE_BANK', title: 'Cocokkan rekening koran' }) } }))
    renderApp(PATH)
    await userEvent.click(await screen.findByRole('button', { name: 'Tambah butir' }))
    const dialog = within(await screen.findByRole('dialog'))
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await dialog.findByText(/Kode hanya huruf, angka dan garis bawah/)).toBeInTheDocument()
    expect(dialog.getByText('Judul wajib diisi.')).toBeInTheDocument()
    expect(posts(calls)).toHaveLength(0)

    await userEvent.type(dialog.getByLabelText('Kode'), 'CLOSE_BANK')
    await userEvent.type(dialog.getByLabelText('Judul'), 'Cocokkan rekening koran')
    await userEvent.type(dialog.getByLabelText('Deskripsi'), 'Semua bank')
    await userEvent.clear(dialog.getByLabelText('Urutan'))
    await userEvent.type(dialog.getByLabelText('Urutan'), '5')
    await userEvent.click(dialog.getByRole('checkbox'))
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    expect(await screen.findByText('Butir daftar periksa ditambahkan.')).toBeInTheDocument()
    expect(posts(calls)[0].url).toBe(TEMPLATES)
    expect(posts(calls)[0].data).toEqual({ code: 'CLOSE_BANK', title: 'Cocokkan rekening koran', description: 'Semua bank', required: false, sort_order: 5, status: 'ACTIVE' })
    await waitFor(() => expect(calls.filter((c) => c.method === 'GET' && c.url === TEMPLATES).length).toBeGreaterThan(1))
  })

  it('shows a taken or reserved code as the server refuses it', async () => {
    boot(policyManager, routes({ [`POST ${TEMPLATES}`]: { status: 422, data: { message: 'x', code: 'CHECKLIST_CODE_RESERVED' } } }))
    renderApp(PATH)
    await userEvent.click(await screen.findByRole('button', { name: 'Tambah butir' }))
    const dialog = within(await screen.findByRole('dialog'))
    await userEvent.type(dialog.getByLabelText('Kode'), 'TRIAL_BALANCE')
    await userEvent.type(dialog.getByLabelText('Judul'), 'Neraca')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))
    expect(await dialog.findByText(/Kode ini milik pemeriksaan sistem/)).toBeInTheDocument()
  })

  it('edits a template without being able to change its code', async () => {
    const calls = boot(policyManager, routes({ [`PATCH ${TEMPLATES}/t-1`]: { data: template({ title: 'Tinjau akrual & beban dibayar di muka' }) } }))
    renderApp(PATH)
    await userEvent.click(await screen.findByRole('button', { name: 'Ubah REVIEW_ACCRUALS' }))
    const dialog = within(await screen.findByRole('dialog'))
    expect(dialog.getByLabelText('Kode')).toBeDisabled()
    const title = dialog.getByLabelText('Judul')
    await userEvent.clear(title)
    await userEvent.type(title, 'Tinjau akrual & beban dibayar di muka')
    await userEvent.selectOptions(dialog.getByLabelText('Status'), 'INACTIVE')
    await userEvent.click(dialog.getByRole('button', { name: 'Simpan' }))

    expect(await screen.findByText('Butir daftar periksa diperbarui.')).toBeInTheDocument()
    const patch = calls.find((c) => c.method === 'PATCH')!
    expect(patch.url).toBe(`${TEMPLATES}/t-1`)
    expect(patch.data).toEqual({ title: 'Tinjau akrual & beban dibayar di muka', description: 'Periksa akrual akhir bulan', required: true, sort_order: 1, status: 'INACTIVE' })
  })

  it('deletes a template after a confirmation', async () => {
    const calls = boot(policyManager, routes({ [`DELETE ${TEMPLATES}/t-2`]: { status: 204 } }))
    renderApp(PATH)
    await userEvent.click(await screen.findByRole('button', { name: 'Hapus SIGN_PACK' }))
    const dialog = within(await screen.findByRole('dialog'))
    expect(dialog.getByText(/yang sudah dipakai harus dinonaktifkan/)).toBeInTheDocument()
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus' }))
    expect(await screen.findByText('Butir SIGN_PACK dihapus.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'DELETE')!.url).toBe(`${TEMPLATES}/t-2`)
  })

  it('tells to deactivate instead when the template was already used', async () => {
    boot(policyManager, routes({ [`DELETE ${TEMPLATES}/t-1`]: { status: 409, data: { message: 'x', code: 'CHECKLIST_TEMPLATE_IN_USE' } } }))
    renderApp(PATH)
    await userEvent.click(await screen.findByRole('button', { name: 'Hapus REVIEW_ACCRUALS' }))
    const dialog = within(await screen.findByRole('dialog'))
    await userEvent.click(dialog.getByRole('button', { name: 'Hapus' }))
    expect(await dialog.findByText(/sudah dipakai pada daftar periksa periode, jadi tidak dapat dihapus. Nonaktifkan saja/)).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('shows an error with retry for the templates while the policy stays', async () => {
    let attempt = 0
    boot(policyManager, routes({ [`GET ${TEMPLATES}`]: () => (++attempt === 1 ? { status: 500, data: { message: 'boom' } } : { data: { data: [template()] } }) }))
    renderApp(PATH)
    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    expect(await checksTable()).toBeDefined()
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('table', { name: 'Butir daftar periksa penutupan' })).toBeInTheDocument()
  })
})
