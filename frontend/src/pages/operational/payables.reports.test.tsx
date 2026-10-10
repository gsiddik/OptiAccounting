import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { page } from '../../test/fakeApi'
import { formatDate } from '../../lib/format'
import { renderApp } from '../../test/render'
import { BASE, bootAp, noDimensions, vendor } from './payables/testkit'
import type { AgingReport, ApReconciliation } from './payables/types'

afterEach(() => vi.restoreAllMocks())

const stat = (label: string) => within(screen.getByText(label, { selector: '.label' }).closest('.stat') as HTMLElement)
const mockDownload = () => {
  const create = vi.fn(() => 'blob:csv')
  Object.assign(URL, { createObjectURL: create, revokeObjectURL: vi.fn() })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
  return create
}

describe('payables aging', () => {
  const view = ['accounting.ap_aging.view']
  const report = (over: Partial<AgingReport> = {}): AgingReport => ({
    as_of: '2026-10-08',
    buckets: [
      { key: 'current', label: 'Belum jatuh tempo', from: null, to: 0 },
      { key: 'd1_30', label: '1-30 hari', from: 1, to: 30 },
      { key: 'd31_60', label: '31-60 hari', from: 31, to: 60 },
      { key: 'd61_90', label: '61-90 hari', from: 61, to: 90 },
      { key: 'over_90', label: 'Lebih dari 90 hari', from: 91, to: null },
    ],
    data: [
      { vendor_id: 'v-1', vendor_code: 'V1', vendor_name: 'PT Sumber Makmur', invoice_count: 2, buckets: { current: '100000.0000', d1_30: '250000.5000', d31_60: '0.0000', d61_90: '0.0000', over_90: '75000.0000' }, total: '425000.5000' },
      { vendor_id: 'v-2', vendor_code: 'V2', vendor_name: 'CV Lain', invoice_count: 1, buckets: { current: '0.0000', d1_30: '0.0000', d31_60: '1234567.8900', d61_90: '0.0000', over_90: '0.0000' }, total: '1234567.8900' },
    ],
    totals: { current: '100000.0000', d1_30: '250000.5000', d31_60: '1234567.8900', d61_90: '0.0000', over_90: '75000.0000', total: '1659568.3900' },
    invoice_count: 3,
    complete: true,
    ...over,
  })
  const routes = {
    [`GET ${BASE}/ap-aging`]: { data: report() },
    [`GET ${BASE}/vendors`]: { data: page([vendor(), vendor({ id: 'v-2', code: 'V2', name: 'CV Lain' })]) },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
  }
  const agingCalls = (calls: ReturnType<typeof bootAp>) => calls.filter((c) => c.url === `${BASE}/ap-aging`)

  it('renders the figures the API computed: bucket totals, the vendor table and the grand total', async () => {
    const calls = bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/umur-utang')

    expect(await screen.findByText(`Posisi per ${formatDate('2026-10-08')} · 3 faktur terbuka`)).toBeInTheDocument()
    expect(stat('1-30 hari').getByText('250.000,50')).toBeInTheDocument()
    expect(stat('31-60 hari').getByText('1.234.567,89')).toBeInTheDocument()
    expect(stat('61-90 hari').getByText('0,00')).toBeInTheDocument()
    expect(stat('Lebih dari 90 hari').getByText('75.000,00')).toBeInTheDocument()
    expect(stat('Total utang').getByText('1.659.568,39')).toBeInTheDocument()

    const table = within(screen.getByRole('table', { name: 'Umur utang per vendor' }))
    expect(table.getByRole('columnheader', { name: '31-60 hari' })).toBeInTheDocument()
    const first = within(table.getByText('PT Sumber Makmur').closest('tr') as HTMLElement)
    expect(first.getByText('250.000,50')).toBeInTheDocument()
    expect(first.getByText('425.000,50')).toBeInTheDocument()
    const second = within(table.getByText('CV Lain').closest('tr') as HTMLElement)
    expect(second.getAllByText('1.234.567,89')).toHaveLength(2) // the 31-60 bucket and the vendor total
    const total = within(table.getByText('Total', { selector: 'strong' }).closest('tr') as HTMLElement)
    expect(total.getAllByText('1.659.568,39').length).toBeGreaterThan(0)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    expect(agingCalls(calls)[0].params).toEqual({ as_of: '2026-10-08', buckets: '30,60,90' })
  })

  it('asks the server for the buckets the user typed and renders the columns the server answers with', async () => {
    const calls = bootAp({ permissions: view }, {
      ...routes,
      [`GET ${BASE}/ap-aging`]: (request) => ({
        data: request.params?.buckets === '15,45'
          ? report({
            buckets: [{ key: 'current', label: 'Belum jatuh tempo', from: null, to: 0 }, { key: 'd1_15', label: '1-15 hari', from: 1, to: 15 }, { key: 'd16_45', label: '16-45 hari', from: 16, to: 45 }, { key: 'over_45', label: 'Lebih dari 45 hari', from: 46, to: null }],
            data: [{ vendor_id: 'v-1', vendor_code: 'V1', vendor_name: 'PT Sumber Makmur', invoice_count: 1, buckets: { current: '0.0000', d1_15: '10.5000', d16_45: '20.2500', over_45: '0.0000' }, total: '30.7500' }],
            totals: { current: '0.0000', d1_15: '10.5000', d16_45: '20.2500', over_45: '0.0000', total: '30.7500' },
            invoice_count: 1,
          })
          : report(),
      }),
    })
    renderApp('/app/akuntansi/umur-utang')
    await screen.findByText(/3 faktur terbuka/)

    const field = screen.getByLabelText('Kelompok umur (hari)')
    await userEvent.clear(field)
    await userEvent.type(field, '15,45')

    expect(await screen.findByRole('columnheader', { name: '16-45 hari' })).toBeInTheDocument()
    expect(agingCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-10-08', buckets: '15,45' })
    expect(screen.queryByRole('columnheader', { name: '61-90 hari' })).not.toBeInTheDocument()
    expect(stat('Total utang').getByText('30,75')).toBeInTheDocument()
  })

  it('never sends an invalid bucket list and says what is expected', async () => {
    const calls = bootAp({ permissions: [...view, 'accounting.report.export'] }, routes)
    renderApp('/app/akuntansi/umur-utang')
    await screen.findByText(/3 faktur terbuka/)
    const before = agingCalls(calls).length
    expect(screen.getByRole('button', { name: 'Ekspor CSV' })).toBeInTheDocument()

    const field = screen.getByLabelText('Kelompok umur (hari)')
    await userEvent.clear(field)
    await userEvent.type(field, '30,,x')
    expect(await screen.findByRole('alert')).toHaveTextContent('Isi 1 sampai 8 batas hari yang menaik dipisah koma')
    await new Promise((r) => setTimeout(r, 450))

    expect(agingCalls(calls)).toHaveLength(before)
    expect(agingCalls(calls).every((c) => c.params?.buckets === '30,60,90')).toBe(true)
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument() // nothing valid to export
  })

  it('shows the invoice detail only when asked for, and links each invoice', async () => {
    const calls = bootAp({ permissions: view }, {
      ...routes,
      [`GET ${BASE}/ap-aging`]: (request) => ({
        data: report(request.params?.detail === 1
          ? { invoices: [{ id: 'inv-1', document_number: 'API-FY2026-000001', vendor_invoice_number: 'FP-100', vendor_id: 'v-1', vendor_code: 'V1', vendor_name: 'PT Sumber Makmur', posting_date: '2026-08-01', due_date: '2026-09-01', days_overdue: 37, bucket: 'd31_60', total_amount: '1500000.0000', paid_amount: '500000.0000', outstanding_amount: '1000000.0000' }] }
          : {}),
      }),
    })
    renderApp('/app/akuntansi/umur-utang')
    await screen.findByText(/3 faktur terbuka/)
    expect(screen.queryByRole('table', { name: 'Rincian umur utang per faktur' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByLabelText('Tampilkan rincian faktur'))
    const detail = within(await screen.findByRole('table', { name: 'Rincian umur utang per faktur' }))
    expect(agingCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-10-08', buckets: '30,60,90', detail: 1 })
    expect(detail.getByRole('link', { name: 'API-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/faktur-vendor/inv-1')
    const row = within(detail.getByRole('link', { name: 'API-FY2026-000001' }).closest('tr') as HTMLElement)
    expect(row.getByText('37')).toBeInTheDocument()
    expect(row.getByText('31-60 hari')).toBeInTheDocument()
    expect(row.getByText('1.500.000,00')).toBeInTheDocument()
    expect(row.getByText('500.000,00')).toBeInTheDocument()
    expect(row.getByText('1.000.000,00')).toBeInTheDocument()
  })

  it('filters by vendor and date on the server', async () => {
    const calls = bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/umur-utang')
    await screen.findByText(/3 faktur terbuka/)

    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-2')
    await waitFor(() => expect(agingCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-10-08', buckets: '30,60,90', vendor_id: 'v-2' }))
    const date = screen.getByLabelText('Per tanggal')
    await userEvent.clear(date)
    await userEvent.type(date, '2026-09-30')
    await waitFor(() => expect(agingCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-09-30', buckets: '30,60,90', vendor_id: 'v-2' }))
  })

  it('warns that a partial scope is not the whole payable', async () => {
    bootAp({ permissions: view }, { ...routes, [`GET ${BASE}/ap-aging`]: { data: report({ complete: false }) } })
    renderApp('/app/akuntansi/umur-utang')
    expect(await screen.findByRole('alert')).toHaveTextContent(/hanya mencakup faktur dalam cakupan data Anda/)
  })

  it('offers CSV export only with accounting.report.export, with the same filters and without the detail flag', async () => {
    const create = mockDownload()
    bootAp({ permissions: view }, routes)
    const first = renderApp('/app/akuntansi/umur-utang')
    await screen.findByText(/3 faktur terbuka/)
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    first.unmount()

    const calls = bootAp({ permissions: [...view, 'accounting.report.export'] }, { ...routes, [`GET ${BASE}/ap-aging/export`]: { data: new Blob(['x']) } })
    renderApp('/app/akuntansi/umur-utang')
    await screen.findByText(/3 faktur terbuka/)
    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-1')
    await userEvent.click(screen.getByLabelText('Tampilkan rincian faktur'))
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ap-aging/export`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ap-aging/export`)?.params).toEqual({ as_of: '2026-10-08', buckets: '30,60,90', vendor_id: 'v-1' })
    expect(create).toHaveBeenCalled()
  })

  it('shows an empty state, and an error with a retry', async () => {
    let fail = true
    bootAp({ permissions: view }, {
      ...routes,
      [`GET ${BASE}/ap-aging`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: report({ data: [], invoice_count: 0, totals: { current: '0.0000', d1_30: '0.0000', d31_60: '0.0000', d61_90: '0.0000', over_90: '0.0000', total: '0.0000' } }) }),
    })
    renderApp('/app/akuntansi/umur-utang')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Tidak ada utang terbuka')).toBeInTheDocument()
  })

  it('is closed to a user without accounting.ap_aging.view', async () => {
    bootAp({ permissions: ['accounting.ap_invoice.view'] }, routes)
    renderApp('/app/akuntansi/umur-utang')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
    expect(screen.queryByText(/faktur terbuka/)).not.toBeInTheDocument()
  })
})

describe('payables vs general ledger reconciliation', () => {
  const view = ['accounting.reconciliation.ap.view']
  const matched: ApReconciliation = {
    as_of: '2026-10-08',
    vendor_id: null,
    control_accounts: [{ id: 'a-ap', code: '2110', name: 'Utang usaha', gl_balance: '2159568.3900' }],
    gl_balance: '2159568.3900',
    opening_balance_component: '500000.0000',
    gl_transactional_balance: '1659568.3900',
    subledger_balance: '1659568.3900',
    difference: '0.0000',
    status: 'MATCHED',
    vendors: [
      { vendor_id: 'v-1', vendor_code: 'V1', vendor_name: 'PT Sumber Makmur', gl_balance: '425000.5000', subledger_balance: '425000.5000', difference: '0.0000', status: 'MATCHED' },
      { vendor_id: 'v-2', vendor_code: 'V2', vendor_name: 'CV Lain', gl_balance: '1234567.8900', subledger_balance: '1234567.8900', difference: '0.0000', status: 'MATCHED' },
    ],
    complete: true,
  }
  const mismatch: ApReconciliation = {
    ...matched,
    control_accounts: [{ id: 'a-ap', code: '2110', name: 'Utang usaha', gl_balance: '2200000.0000' }],
    gl_balance: '2200000.0000',
    gl_transactional_balance: '1700000.0000',
    difference: '40431.6100',
    status: 'MISMATCH',
    vendors: [
      { vendor_id: 'v-1', vendor_code: 'V1', vendor_name: 'PT Sumber Makmur', gl_balance: '425000.5000', subledger_balance: '425000.5000', difference: '0.0000', status: 'MATCHED' },
      { vendor_id: 'v-2', vendor_code: 'V2', vendor_name: 'CV Lain', gl_balance: '1274999.5000', subledger_balance: '1234567.8900', difference: '40431.6100', status: 'MISMATCH' },
    ],
  }
  const routes = {
    [`GET ${BASE}/reconciliation/ap`]: { data: matched },
    [`GET ${BASE}/vendors`]: { data: page([vendor(), vendor({ id: 'v-2', code: 'V2', name: 'CV Lain' })]) },
  }
  const reconCalls = (calls: ReturnType<typeof bootAp>) => calls.filter((c) => c.url === `${BASE}/reconciliation/ap`)

  it('renders the matched figures of the API and says so', async () => {
    const calls = bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/rekonsiliasi/utang')

    const banner = (await screen.findByText('Cocok.', { selector: 'strong' })).closest('.banner') as HTMLElement
    expect(banner).toHaveTextContent(`Per ${formatDate('2026-10-08')}`)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(stat('Saldo buku besar (akun kontrol)').getByText('2.159.568,39')).toBeInTheDocument()
    expect(stat('Komponen saldo awal').getByText('500.000,00')).toBeInTheDocument()
    expect(stat('Saldo buku besar (transaksional)').getByText('1.659.568,39')).toBeInTheDocument()
    expect(stat('Saldo sub-buku utang').getByText('1.659.568,39')).toBeInTheDocument()

    const accounts = within(screen.getByRole('table', { name: 'Akun kontrol utang' }))
    expect(accounts.getByText('2110', { exact: false })).toBeInTheDocument()
    expect(accounts.getByText('2.159.568,39')).toBeInTheDocument()
    const vendors = within(screen.getByRole('table', { name: 'Rekonsiliasi utang per vendor' }))
    const row = within(vendors.getByText('PT Sumber Makmur').closest('tr') as HTMLElement)
    expect(row.getAllByText('425.000,50', { selector: '.money' }).length).toBeGreaterThan(0) // ledger and sub-ledger, equal
    expect(row.getByText('Cocok')).toBeInTheDocument()
    expect(reconCalls(calls)[0].params).toEqual({ as_of: '2026-10-08' })
  })

  it('shows a MISMATCH as a difference to investigate, with the figures as the API gave them and no adjustment offered', async () => {
    bootAp({ permissions: view }, { ...routes, [`GET ${BASE}/reconciliation/ap`]: { data: mismatch } })
    renderApp('/app/akuntansi/rekonsiliasi/utang')

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Selisih.')
    expect(alert).toHaveTextContent('40.431,61')
    expect(alert).toHaveTextContent('Tidak ada penyesuaian otomatis')
    expect(screen.queryByText('Cocok.', { selector: 'strong' })).not.toBeInTheDocument()
    expect(stat('Saldo buku besar (transaksional)').getByText('1.700.000,00')).toBeInTheDocument()
    expect(stat('Saldo sub-buku utang').getByText('1.659.568,39')).toBeInTheDocument()

    const totals = within(screen.getByText(/Selisih \(buku besar transaksional/).closest('.totals') as HTMLElement)
    expect(totals.getByText('40.431,61')).toBeInTheDocument()
    expect(totals.getByText('Selisih', { selector: '.badge' })).toBeInTheDocument()

    const vendors = within(screen.getByRole('table', { name: 'Rekonsiliasi utang per vendor' }))
    const bad = within(vendors.getByText('CV Lain').closest('tr') as HTMLElement)
    expect(bad.getByText('1.274.999,50')).toBeInTheDocument()
    expect(bad.getByText('1.234.567,89')).toBeInTheDocument()
    expect(bad.getByText('40.431,61')).toBeInTheDocument()
    expect(bad.getByText('Selisih', { selector: '.badge' })).toBeInTheDocument()
    expect(within(vendors.getByText('PT Sumber Makmur').closest('tr') as HTMLElement).getByText('Cocok')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /sesuaikan|jurnal penyesuaian|perbaiki/i })).not.toBeInTheDocument()
  })

  it('warns that a partial scope proves nothing about the whole company', async () => {
    bootAp({ permissions: view }, { ...routes, [`GET ${BASE}/reconciliation/ap`]: { data: { ...matched, complete: false } } })
    renderApp('/app/akuntansi/rekonsiliasi/utang')
    expect(await screen.findByText(/Tampilan ini sebagian/)).toBeInTheDocument()
  })

  it('filters by vendor and date on the server', async () => {
    const calls = bootAp({ permissions: view }, routes)
    renderApp('/app/akuntansi/rekonsiliasi/utang')
    await screen.findByText('Cocok.', { selector: 'strong' })

    await userEvent.selectOptions(screen.getByLabelText('Vendor'), 'v-2')
    await waitFor(() => expect(reconCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-10-08', vendor_id: 'v-2' }))
    const date = screen.getByLabelText('Per tanggal')
    await userEvent.clear(date)
    await userEvent.type(date, '2026-09-30')
    await waitFor(() => expect(reconCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-09-30', vendor_id: 'v-2' }))
  })

  it('offers CSV export only with accounting.report.export', async () => {
    const create = mockDownload()
    bootAp({ permissions: view }, routes)
    const first = renderApp('/app/akuntansi/rekonsiliasi/utang')
    await screen.findByText('Cocok.', { selector: 'strong' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    first.unmount()

    const calls = bootAp({ permissions: [...view, 'accounting.report.export'] }, { ...routes, [`GET ${BASE}/reconciliation/ap/export`]: { data: new Blob(['x']) } })
    renderApp('/app/akuntansi/rekonsiliasi/utang')
    await screen.findByText('Cocok.', { selector: 'strong' })
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/reconciliation/ap/export`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/reconciliation/ap/export`)?.params).toEqual({ as_of: '2026-10-08' })
    expect(create).toHaveBeenCalled()
  })

  it('shows empty states for a ledger without control accounts and vendors, and an error with a retry', async () => {
    let fail = true
    bootAp({ permissions: view }, {
      ...routes,
      [`GET ${BASE}/reconciliation/ap`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: { ...matched, control_accounts: [], vendors: [] } }),
    })
    renderApp('/app/akuntansi/rekonsiliasi/utang')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Belum ada akun kontrol utang')).toBeInTheDocument()
    expect(screen.getByText('Tidak ada saldo vendor')).toBeInTheDocument()
  })
})
