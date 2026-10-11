import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderApp } from '../../test/render'
import { AP, boot, reconRow, reconciliation } from './testkit'

afterEach(() => vi.restoreAllMocks())

const recon = ['accounting.asset.reconciliation.view']
const view = [...recon, 'accounting.asset.view']
const PATH = '/app/akuntansi/rekonsiliasi/aset-tetap'

const events = (ready: boolean[]) => ['ASSET_CAPITALIZED', 'DEPRECIATION_RECOGNIZED', 'ASSET_DISPOSED'].map((event_type, i) => ({
  event_type, name: event_type, ready: ready[i], rule_code: ready[i] ? `ASSET-${i + 1}` : null, effective_from: ready[i] ? '2026-01-01' : null,
}))
const rules = (ready: boolean[], unmapped: string[] = []) => ({ data: { events: events(ready), unmapped_roles: unmapped } })

const routes = (over: Record<string, unknown> = {}) => ({
  'GET /app/accounting/asset-reconciliation': { data: reconciliation() },
  'GET /app/accounting/asset-rules': rules([true, true, true]),
  ...over,
})

const mismatch = () => reconciliation({
  reconciled: false,
  cost: [reconRow({ ledger: '350000000.0000', difference: '-10000000.0000', matched: false })],
  totals: {
    register_cost: '360000000.0000', ledger_cost: '350000000.0000', register_accumulated: '90000000.0000', ledger_accumulated: '90000000.0000',
    register_net_book_value: '270000000.0000', ledger_net_book_value: '260000000.0000', difference: '-10000000.0000',
  },
})

describe('asset reconciliation', () => {
  it('shows the match the server computed, with the figures per account', async () => {
    const calls = boot(view, routes())
    renderApp(PATH)

    expect(await screen.findByRole('heading', { name: 'Aset tetap vs buku besar' })).toBeInTheDocument()
    expect(await screen.findByText('Cocok.')).toBeInTheDocument()
    expect(calls.find((c) => c.url === `${AP}/asset-reconciliation`)!.params).toEqual({ as_of: '2026-10-08' })

    const cost = within(screen.getByRole('table', { name: 'Harga perolehan per akun' }))
    expect(cost.getByText('Kendaraan', { exact: false })).toBeInTheDocument()
    expect(cost.getAllByText('360.000.000,00')).toHaveLength(2)
    expect(cost.getByText('Cocok')).toBeInTheDocument()
    const accumulated = within(screen.getByRole('table', { name: 'Akumulasi penyusutan per akun' }))
    expect(accumulated.getAllByText('90.000.000,00')).toHaveLength(2)
    expect(screen.getByText('Nilai buku register')).toBeInTheDocument()
    expect(screen.queryByText('Selisih.')).not.toBeInTheDocument()
    expect(screen.queryByText(/Tampilan ini sebagian/)).not.toBeInTheDocument()
  })

  it('shows a mismatch as the server reports it and flags the account that differs', async () => {
    boot(view, routes({ 'GET /app/accounting/asset-reconciliation': { data: mismatch() } }))
    renderApp(PATH)

    expect(await screen.findByText('Selisih.')).toBeInTheDocument()
    expect(screen.getByText(/Tidak ada penyesuaian otomatis/)).toBeInTheDocument()
    const cost = within(screen.getByRole('table', { name: 'Harga perolehan per akun' }))
    expect(cost.getByText('Selisih', { selector: '.badge' })).toBeInTheDocument()
    expect(cost.getByText('350.000.000,00')).toBeInTheDocument()
    expect(cost.getAllByText(/10\.000\.000,00/).length).toBeGreaterThan(0)
    expect(screen.queryByText('Cocok.')).not.toBeInTheDocument()
  })

  it('says so when the view covers only the assets within the data scope', async () => {
    boot(view, routes({ 'GET /app/accounting/asset-reconciliation': { data: reconciliation({ complete: false }) } }))
    renderApp(PATH)
    expect(await screen.findByText(/Tampilan ini sebagian/)).toBeInTheDocument()
  })

  it('shows an empty state per section when no account is registered yet', async () => {
    boot(view, routes({ 'GET /app/accounting/asset-reconciliation': { data: reconciliation({ cost: [], accumulated_depreciation: [] }) } }))
    renderApp(PATH)
    expect(await screen.findByText(/Belum ada akun aset tetap/)).toBeInTheDocument()
    expect(screen.getByText(/Belum ada akun akumulasi penyusutan/)).toBeInTheDocument()
  })

  it('asks the server again for the date the user picks', async () => {
    const calls = boot(view, routes())
    renderApp(PATH)
    await screen.findByText('Cocok.')

    fireEvent.change(screen.getByLabelText('Per tanggal'), { target: { value: '2026-09-30' } })
    await waitFor(() => expect(calls.filter((c) => c.url === `${AP}/asset-reconciliation`).at(-1)?.params).toEqual({ as_of: '2026-09-30' }))
  })

  it('explains a refusal in Indonesian', async () => {
    boot(view, routes({ 'GET /app/accounting/asset-reconciliation': { status: 403, data: { message: 'no', code: 'MODULE_NOT_ENTITLED' } } }))
    renderApp(PATH)
    expect(await screen.findByText('Modul ini tidak termasuk dalam langganan.')).toBeInTheDocument()
    expect(screen.queryByText('Cocok.')).not.toBeInTheDocument()
  })

  it('offers the export only to a user who may export, for the chosen date', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const without = boot(view, routes())
    const first = renderApp(PATH)
    await screen.findByText('Cocok.')
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    expect(without.some((c) => c.url.endsWith('/export'))).toBe(false)
    first.unmount()

    const calls = boot([...view, 'accounting.report.export'], routes({ 'GET /app/accounting/asset-reconciliation/export': { data: 'csv' } }))
    renderApp(PATH)
    await screen.findByText('Cocok.')
    fireEvent.change(screen.getByLabelText('Per tanggal'), { target: { value: '2026-09-30' } })
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.find((c) => c.url === `${AP}/asset-reconciliation/export`)).toBeDefined())
    expect(calls.find((c) => c.url === `${AP}/asset-reconciliation/export`)!.params).toEqual({ as_of: '2026-09-30' })
  })
})

describe('asset posting rules panel', () => {
  const manage = [...view, 'accounting.posting_rule.manage']

  it('reports that every asset event has a rule, without offering to apply any', async () => {
    boot(manage, routes())
    renderApp(PATH)

    expect(await screen.findByText(/Aturan posting untuk kapitalisasi, penyusutan, dan pelepasan aset sudah terbit/)).toBeInTheDocument()
    const table = within(screen.getByRole('table', { name: 'Aturan posting aset' }))
    expect(table.getByText('Kapitalisasi aset')).toBeInTheDocument()
    expect(table.getByText('Penyusutan')).toBeInTheDocument()
    expect(table.getByText('Pelepasan aset')).toBeInTheDocument()
    expect(table.getAllByText('Siap')).toHaveLength(3)
    expect(table.getByText('ASSET-2')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Terapkan aturan standar' })).not.toBeInTheDocument()
  })

  it('applies the standard rules and reports what was added and what was skipped', async () => {
    const calls = boot(manage, routes({
      'GET /app/accounting/asset-rules': rules([true, false, false], ['FIXED_ASSET', 'ASSET_DISPOSAL_GAIN_LOSS']),
      'POST /app/accounting/asset-rules/defaults': { status: 201, data: { created: ['DEPRECIATION_RECOGNIZED'], skipped: [{ event_type: 'ASSET_DISPOSED', reason: 'CODE_TAKEN' }] } },
    }))
    renderApp(PATH)

    expect(await screen.findByText(/Ada peristiwa aset yang belum punya aturan posting/)).toBeInTheDocument()
    expect(screen.getAllByText('Belum ada aturan', { selector: '.badge' })).toHaveLength(2)
    // The roles the mapping still lacks are named in Indonesian, not by their code.
    expect(screen.getByText(/Peran akun yang belum dipetakan: Aset tetap, Laba\/rugi pelepasan aset/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Terapkan aturan standar' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Terapkan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    const post = calls.find((c) => c.method === 'POST')!
    expect(post.url).toBe(`${AP}/asset-rules/defaults`)
    expect(post.data).toEqual({})
    expect(await screen.findByText(/1 aturan ditambahkan\. Dilewati: Pelepasan aset \(kode aturan standar sudah dipakai\)/)).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    // The status is read again after the rules were created.
    await waitFor(() => expect(calls.filter((c) => c.url === `${AP}/asset-rules` && c.method === 'GET').length).toBeGreaterThan(1))
  })

  it('sends the date the rules should take effect from when one is given', async () => {
    const calls = boot(manage, routes({
      'GET /app/accounting/asset-rules': rules([false, false, false]),
      'POST /app/accounting/asset-rules/defaults': { status: 201, data: { created: ['ASSET_CAPITALIZED', 'DEPRECIATION_RECOGNIZED', 'ASSET_DISPOSED'], skipped: [] } },
    }))
    renderApp(PATH)
    await userEvent.click(await screen.findByRole('button', { name: 'Terapkan aturan standar' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Berlaku mulai'), { target: { value: '2026-10-01' } })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Terapkan' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'POST')).toBeDefined())
    expect(calls.find((c) => c.method === 'POST')!.data).toEqual({ effective_from: '2026-10-01' })
    expect(await screen.findByText(/3 aturan ditambahkan/)).toBeInTheDocument()
  })

  it('shows the refusal of the server in Indonesian and keeps the dialog open', async () => {
    boot(manage, routes({
      'GET /app/accounting/asset-rules': rules([false, false, false]),
      'POST /app/accounting/asset-rules/defaults': { status: 422, data: { message: 'bad', code: 'VALIDATION_FAILED', details: { field: 'effective_from', errors: { effective_from: ['Tanggal tidak valid.'] } } } },
    }))
    renderApp(PATH)
    await userEvent.click(await screen.findByRole('button', { name: 'Terapkan aturan standar' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Terapkan' }))
    await waitFor(() => expect(within(screen.getByRole('dialog')).getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('does not offer to apply rules without the permission, and tells who to ask', async () => {
    boot(view, routes({ 'GET /app/accounting/asset-rules': rules([false, true, true], ['ACCUMULATED_DEPRECIATION']) }))
    renderApp(PATH)

    expect(await screen.findByText(/Minta pengguna dengan izin mengelola aturan posting/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Terapkan aturan standar' })).not.toBeInTheDocument()
    expect(screen.getByText(/Peran akun yang belum dipetakan: Akumulasi penyusutan/)).toBeInTheDocument()
    // Without the right to see account mappings the hint names the missing permission instead of linking to a page the user cannot open.
    expect(screen.getByText(/butuh izin melihat pemetaan akun/)).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Pemetaan akun' })).not.toBeInTheDocument()
  })

  it('links to the account mapping for a user who may see it', async () => {
    boot([...view, 'accounting.account_mapping.view'], routes({ 'GET /app/accounting/asset-rules': rules([true, true, true], ['FIXED_ASSET']) }))
    renderApp(PATH)
    expect(await screen.findByRole('link', { name: 'Pemetaan akun' })).toHaveAttribute('href', '/app/akuntansi/pemetaan-akun')
  })

  it('does not offer to apply rules in a read-only subscription', async () => {
    boot(manage, routes({ 'GET /app/accounting/asset-rules': rules([false, false, false]) }), { mode: 'READ_ONLY' })
    renderApp(PATH)
    expect(await screen.findByText(/Ada peristiwa aset yang belum punya aturan posting/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Terapkan aturan standar' })).not.toBeInTheDocument()
  })

  it('is absent, and never asks the server, for a user who may not see the asset register', async () => {
    const calls = boot(recon, routes())
    renderApp(PATH)
    await screen.findByRole('heading', { name: 'Aset tetap vs buku besar' })
    expect(screen.queryByText('Aturan posting aset')).not.toBeInTheDocument()
    expect(calls.some((c) => c.url === `${AP}/asset-rules`)).toBe(false)
  })
})
