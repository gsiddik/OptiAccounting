import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatDate } from '../../lib/format'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import type { ArAgingReport, ArReconciliationReport } from './receivables/types'
import { BASE, bootAr, customer, holdGet, mockDownload, noDimensions } from './receivables/testkit'

afterEach(() => vi.restoreAllMocks())

const stat = (label: string) => within(screen.getByText(label, { selector: '.label' }).closest('.stat') as HTMLElement)
const customers = page([customer(), customer({ id: 'c-2', code: 'C2', name: 'CV Lain' })])

describe('receivables aging', () => {
  const view = ['accounting.ar_aging.view']
  const report = (over: Partial<ArAgingReport> = {}): ArAgingReport => ({
    as_of: '2026-10-08',
    buckets: [
      { key: 'current', label: 'Belum jatuh tempo', from: null, to: 0 },
      { key: 'd1_30', label: '1-30 hari', from: 1, to: 30 },
      { key: 'd31_60', label: '31-60 hari', from: 31, to: 60 },
      { key: 'd61_90', label: '61-90 hari', from: 61, to: 90 },
      { key: 'over_90', label: 'Lebih dari 90 hari', from: 91, to: null },
    ],
    data: [
      { customer_id: 'c-1', customer_code: 'C1', customer_name: 'PT Pelanggan Setia', invoice_count: 2, buckets: { current: '100000.0000', d1_30: '250000.5000', d31_60: '0.0000', d61_90: '0.0000', over_90: '75000.0000' }, total: '425000.5000' },
      { customer_id: 'c-2', customer_code: 'C2', customer_name: 'CV Lain', invoice_count: 1, buckets: { current: '0.0000', d1_30: '0.0000', d31_60: '1234567.8900', d61_90: '0.0000', over_90: '0.0000' }, total: '1234567.8900' },
    ],
    totals: { current: '100000.0000', d1_30: '250000.5000', d31_60: '1234567.8900', d61_90: '0.0000', over_90: '75000.0000', total: '1659568.3900' },
    invoice_count: 3,
    complete: true,
    ...over,
  })
  const routes = {
    [`GET ${BASE}/ar-aging`]: { data: report() },
    [`GET ${BASE}/customers`]: { data: customers },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
  }
  const agingCalls = (calls: ReturnType<typeof bootAr>) => calls.filter((c) => c.url === `${BASE}/ar-aging`)

  it('renders the figures the API computed: bucket totals, the customer table and the grand total', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/umur-piutang')

    expect(await screen.findByText(`Posisi per ${formatDate('2026-10-08')} · 3 faktur terbuka`)).toBeInTheDocument()
    expect(stat('Belum jatuh tempo').getByText('100.000,00')).toBeInTheDocument()
    expect(stat('1-30 hari').getByText('250.000,50')).toBeInTheDocument()
    expect(stat('31-60 hari').getByText('1.234.567,89')).toBeInTheDocument()
    expect(stat('61-90 hari').getByText('0,00')).toBeInTheDocument()
    expect(stat('Lebih dari 90 hari').getByText('75.000,00')).toBeInTheDocument()
    expect(stat('Total piutang').getByText('1.659.568,39')).toBeInTheDocument()

    const table = within(screen.getByRole('table', { name: 'Umur piutang per pelanggan' }))
    expect(table.getByRole('columnheader', { name: '31-60 hari' })).toBeInTheDocument()
    const first = within(table.getByText('PT Pelanggan Setia').closest('tr') as HTMLElement)
    expect(first.getByText('250.000,50')).toBeInTheDocument()
    expect(first.getByText('425.000,50')).toBeInTheDocument()
    expect(first.getByText('2')).toBeInTheDocument() // invoice count
    const second = within(table.getByText('CV Lain').closest('tr') as HTMLElement)
    expect(second.getAllByText('1.234.567,89')).toHaveLength(2) // the 31-60 bucket and the customer total
    const total = within(table.getByText('Total', { selector: 'strong' }).closest('tr') as HTMLElement)
    expect(total.getAllByText('1.659.568,39').length).toBeGreaterThan(0)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    expect(agingCalls(calls)[0].params).toEqual({ as_of: '2026-10-08', buckets: '30,60,90' })
  })

  it('asks the server for the buckets the user typed and renders the columns the server answers with', async () => {
    const calls = bootAr({ permissions: view }, {
      ...routes,
      [`GET ${BASE}/ar-aging`]: (request) => ({
        data: request.params?.buckets === '15,45'
          ? report({
            buckets: [{ key: 'current', label: 'Belum jatuh tempo', from: null, to: 0 }, { key: 'd1_15', label: '1-15 hari', from: 1, to: 15 }, { key: 'd16_45', label: '16-45 hari', from: 16, to: 45 }, { key: 'over_45', label: 'Lebih dari 45 hari', from: 46, to: null }],
            data: [{ customer_id: 'c-1', customer_code: 'C1', customer_name: 'PT Pelanggan Setia', invoice_count: 1, buckets: { current: '0.0000', d1_15: '10.5000', d16_45: '20.2500', over_45: '0.0000' }, total: '30.7500' }],
            totals: { current: '0.0000', d1_15: '10.5000', d16_45: '20.2500', over_45: '0.0000', total: '30.7500' },
            invoice_count: 1,
          })
          : report(),
      }),
    })
    renderApp('/app/akuntansi/umur-piutang')
    await screen.findByText(/3 faktur terbuka/)

    const field = screen.getByLabelText('Kelompok umur (hari)')
    await userEvent.clear(field)
    await userEvent.type(field, '15,45')

    expect(await screen.findByRole('columnheader', { name: '16-45 hari' })).toBeInTheDocument()
    expect(agingCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-10-08', buckets: '15,45' })
    expect(screen.queryByRole('columnheader', { name: '61-90 hari' })).not.toBeInTheDocument()
    expect(stat('Total piutang').getByText('30,75')).toBeInTheDocument()
  })

  it('never sends an invalid bucket list and says what is expected', async () => {
    const calls = bootAr({ permissions: [...view, 'accounting.report.export'] }, routes)
    renderApp('/app/akuntansi/umur-piutang')
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

  it('drills down to the open invoices only when asked for, and links each invoice', async () => {
    const calls = bootAr({ permissions: view }, {
      ...routes,
      [`GET ${BASE}/ar-aging`]: (request) => ({
        data: report(request.params?.detail === 1
          ? { invoices: [{ id: 'ai-1', document_number: 'ARI-FY2026-000001', customer_reference: 'PO-100', customer_id: 'c-1', customer_code: 'C1', customer_name: 'PT Pelanggan Setia', posting_date: '2026-08-01', due_date: '2026-09-01', days_overdue: 37, bucket: 'd31_60', total_amount: '1500000.0000', received_amount: '400000.0000', credited_amount: '100000.0000', outstanding_amount: '1000000.0000' }] }
          : {}),
      }),
    })
    renderApp('/app/akuntansi/umur-piutang')
    await screen.findByText(/3 faktur terbuka/)
    expect(screen.queryByRole('table', { name: 'Rincian umur piutang per faktur' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByLabelText('Tampilkan rincian faktur'))
    const detail = within(await screen.findByRole('table', { name: 'Rincian umur piutang per faktur' }))
    expect(agingCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-10-08', buckets: '30,60,90', detail: 1 })
    expect(detail.getByRole('link', { name: 'ARI-FY2026-000001' })).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan/ai-1')
    const row = within(detail.getByRole('link', { name: 'ARI-FY2026-000001' }).closest('tr') as HTMLElement)
    expect(row.getByText('PO-100')).toBeInTheDocument()
    expect(row.getByText('37')).toBeInTheDocument()
    expect(row.getByText('31-60 hari')).toBeInTheDocument()
    expect(row.getByText('1.500.000,00')).toBeInTheDocument()
    expect(row.getByText('400.000,00')).toBeInTheDocument()
    expect(row.getByText('100.000,00')).toBeInTheDocument()
    expect(row.getByText('1.000.000,00')).toBeInTheDocument()
  })

  it('filters by customer and as-of date on the server', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/umur-piutang')
    await screen.findByText(/3 faktur terbuka/)

    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-2')
    await waitFor(() => expect(agingCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-10-08', buckets: '30,60,90', customer_id: 'c-2' }))
    const date = screen.getByLabelText('Per tanggal')
    await userEvent.clear(date)
    await userEvent.type(date, '2026-09-30')
    await waitFor(() => expect(agingCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-09-30', buckets: '30,60,90', customer_id: 'c-2' }))
  })

  it('warns that a partial scope is not the whole receivable', async () => {
    bootAr({ permissions: view }, { ...routes, [`GET ${BASE}/ar-aging`]: { data: report({ complete: false }) } })
    renderApp('/app/akuntansi/umur-piutang')
    expect(await screen.findByRole('alert')).toHaveTextContent(/hanya mencakup faktur dalam cakupan data Anda/)
  })

  it('offers CSV export only with accounting.report.export, with the same filters and without the detail flag', async () => {
    const create = mockDownload()
    bootAr({ permissions: view }, routes)
    const first = renderApp('/app/akuntansi/umur-piutang')
    await screen.findByText(/3 faktur terbuka/)
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    first.unmount()

    const calls = bootAr({ permissions: [...view, 'accounting.report.export'] }, { ...routes, [`GET ${BASE}/ar-aging/export`]: { data: new Blob(['x']) } })
    renderApp('/app/akuntansi/umur-piutang')
    await screen.findByText(/3 faktur terbuka/)
    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-1')
    await userEvent.click(screen.getByLabelText('Tampilkan rincian faktur'))
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))

    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/ar-aging/export`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/ar-aging/export`)?.params).toEqual({ as_of: '2026-10-08', buckets: '30,60,90', customer_id: 'c-1' })
    expect(create).toHaveBeenCalled()
  })

  it('shows an empty state, and an error with a retry', async () => {
    let fail = true
    bootAr({ permissions: view }, {
      ...routes,
      [`GET ${BASE}/ar-aging`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: report({ data: [], invoice_count: 0, totals: { current: '0.0000', d1_30: '0.0000', d31_60: '0.0000', d61_90: '0.0000', over_90: '0.0000', total: '0.0000' } }) }),
    })
    renderApp('/app/akuntansi/umur-piutang')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Tidak ada piutang terbuka')).toBeInTheDocument()
  })

  it('shows a loading state while the report is on its way', async () => {
    bootAr({ permissions: view }, routes)
    const release = holdGet(`${BASE}/ar-aging`)
    renderApp('/app/akuntansi/umur-piutang')
    await screen.findByLabelText('Kelompok umur (hari)') // the page is up; only the report is still on its way
    expect(screen.getByText('Memuat…')).toBeInTheDocument()
    release()
    expect(await screen.findByText(/3 faktur terbuka/)).toBeInTheDocument()
  })

  it('stays readable on a read-only AR module (reports are reads)', async () => {
    bootAr({ permissions: view, ar: 'READ_ONLY' }, routes)
    renderApp('/app/akuntansi/umur-piutang')
    expect(await screen.findByText(/3 faktur terbuka/)).toBeInTheDocument()
  })

  it('is closed without the permission, the feature or the module', async () => {
    bootAr({ permissions: ['accounting.ar_invoice.view'] }, routes)
    const first = renderApp('/app/akuntansi/umur-piutang')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
    expect(screen.queryByText(/faktur terbuka/)).not.toBeInTheDocument()
    first.unmount()

    bootAr({ permissions: view, features: { AR_AGING: false } }, routes)
    const second = renderApp('/app/akuntansi/umur-piutang')
    expect(await screen.findByText('Fitur tidak tersedia')).toBeInTheDocument()
    second.unmount()

    const calls = bootAr({ permissions: view, modules: { ACCOUNTING_CORE: 'FULL' } }, routes)
    renderApp('/app/akuntansi/umur-piutang')
    expect(await screen.findByText('Modul tidak tersedia')).toBeInTheDocument()
    expect(agingCalls(calls)).toHaveLength(0)
  })
})

describe('receivables vs general ledger reconciliation', () => {
  const view = ['accounting.reconciliation.ar.view']
  const matched: ArReconciliationReport = {
    as_of: '2026-10-08',
    customer_id: null,
    control_accounts: [{ id: 'a-ar', code: '1210', name: 'Piutang usaha', gl_balance: '2159568.3900' }],
    gl_balance: '2159568.3900',
    opening_balance_component: '500000.0000',
    gl_transactional_balance: '1659568.3900',
    subledger_balance: '1659568.3900',
    difference: '0.0000',
    status: 'MATCHED',
    customers: [
      { customer_id: 'c-1', customer_code: 'C1', customer_name: 'PT Pelanggan Setia', gl_balance: '425000.5000', subledger_balance: '425000.5000', difference: '0.0000', status: 'MATCHED' },
      { customer_id: 'c-2', customer_code: 'C2', customer_name: 'CV Lain', gl_balance: '1234567.8900', subledger_balance: '1234567.8900', difference: '0.0000', status: 'MATCHED' },
    ],
    complete: true,
  }
  const mismatch: ArReconciliationReport = {
    ...matched,
    control_accounts: [{ id: 'a-ar', code: '1210', name: 'Piutang usaha', gl_balance: '2200000.0000' }],
    gl_balance: '2200000.0000',
    gl_transactional_balance: '1700000.0000',
    difference: '40431.6100',
    status: 'MISMATCH',
    customers: [
      { customer_id: 'c-1', customer_code: 'C1', customer_name: 'PT Pelanggan Setia', gl_balance: '425000.5000', subledger_balance: '425000.5000', difference: '0.0000', status: 'MATCHED' },
      { customer_id: 'c-2', customer_code: 'C2', customer_name: 'CV Lain', gl_balance: '1274999.5000', subledger_balance: '1234567.8900', difference: '40431.6100', status: 'MISMATCH' },
    ],
  }
  const routes = {
    [`GET ${BASE}/reconciliation/ar`]: { data: matched },
    [`GET ${BASE}/customers`]: { data: customers },
  }
  const reconCalls = (calls: ReturnType<typeof bootAr>) => calls.filter((c) => c.url === `${BASE}/reconciliation/ar`)

  it('renders the matched figures of the API and says so', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/rekonsiliasi/piutang')

    const banner = (await screen.findByText('Cocok.', { selector: 'strong' })).closest('.banner') as HTMLElement
    expect(banner).toHaveTextContent(`Per ${formatDate('2026-10-08')}`)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(stat('Saldo buku besar (akun kontrol)').getByText('2.159.568,39')).toBeInTheDocument()
    expect(stat('Komponen saldo awal').getByText('500.000,00')).toBeInTheDocument()
    expect(stat('Saldo buku besar (transaksional)').getByText('1.659.568,39')).toBeInTheDocument()
    expect(stat('Saldo sub-buku piutang').getByText('1.659.568,39')).toBeInTheDocument()

    const accounts = within(screen.getByRole('table', { name: 'Akun kontrol piutang' }))
    expect(accounts.getByText('1210', { exact: false })).toBeInTheDocument()
    expect(accounts.getByText('2.159.568,39')).toBeInTheDocument()
    const rows = within(screen.getByRole('table', { name: 'Rekonsiliasi piutang per pelanggan' }))
    const row = within(rows.getByText('PT Pelanggan Setia').closest('tr') as HTMLElement)
    expect(row.getAllByText('425.000,50', { selector: '.money' }).length).toBeGreaterThan(0) // ledger and sub-ledger, equal
    expect(row.getByText('Cocok')).toBeInTheDocument()
    expect(reconCalls(calls)[0].params).toEqual({ as_of: '2026-10-08' })
  })

  it('shows a MISMATCH as a difference to investigate, with the figures as the API gave them and no adjustment offered', async () => {
    bootAr({ permissions: view }, { ...routes, [`GET ${BASE}/reconciliation/ar`]: { data: mismatch } })
    renderApp('/app/akuntansi/rekonsiliasi/piutang')

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Selisih.')
    expect(alert).toHaveTextContent('40.431,61')
    expect(alert).toHaveTextContent('Tidak ada penyesuaian otomatis')
    expect(screen.queryByText('Cocok.', { selector: 'strong' })).not.toBeInTheDocument()
    expect(stat('Saldo buku besar (transaksional)').getByText('1.700.000,00')).toBeInTheDocument()
    expect(stat('Saldo sub-buku piutang').getByText('1.659.568,39')).toBeInTheDocument()

    const totals = within(screen.getByText(/Selisih \(buku besar transaksional/).closest('.totals') as HTMLElement)
    expect(totals.getByText('40.431,61')).toBeInTheDocument()
    expect(totals.getByText('Selisih', { selector: '.badge' })).toBeInTheDocument()

    const rows = within(screen.getByRole('table', { name: 'Rekonsiliasi piutang per pelanggan' }))
    const bad = within(rows.getByText('CV Lain').closest('tr') as HTMLElement)
    expect(bad.getByText('1.274.999,50')).toBeInTheDocument()
    expect(bad.getByText('1.234.567,89')).toBeInTheDocument()
    expect(bad.getByText('40.431,61')).toBeInTheDocument()
    expect(bad.getByText('Selisih', { selector: '.badge' })).toBeInTheDocument()
    expect(within(rows.getByText('PT Pelanggan Setia').closest('tr') as HTMLElement).getByText('Cocok')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /sesuaikan|jurnal penyesuaian|perbaiki/i })).not.toBeInTheDocument()
  })

  it('warns that a partial scope proves nothing about the whole company', async () => {
    bootAr({ permissions: view }, { ...routes, [`GET ${BASE}/reconciliation/ar`]: { data: { ...matched, complete: false } } })
    renderApp('/app/akuntansi/rekonsiliasi/piutang')
    expect(await screen.findByText(/Tampilan ini sebagian/)).toBeInTheDocument()
  })

  it('filters by customer and date on the server', async () => {
    const calls = bootAr({ permissions: view }, routes)
    renderApp('/app/akuntansi/rekonsiliasi/piutang')
    await screen.findByText('Cocok.', { selector: 'strong' })

    await userEvent.selectOptions(screen.getByLabelText('Pelanggan'), 'c-2')
    await waitFor(() => expect(reconCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-10-08', customer_id: 'c-2' }))
    const date = screen.getByLabelText('Per tanggal')
    await userEvent.clear(date)
    await userEvent.type(date, '2026-09-30')
    await waitFor(() => expect(reconCalls(calls).at(-1)?.params).toEqual({ as_of: '2026-09-30', customer_id: 'c-2' }))
  })

  it('offers CSV export only with accounting.report.export', async () => {
    const create = mockDownload()
    bootAr({ permissions: view }, routes)
    const first = renderApp('/app/akuntansi/rekonsiliasi/piutang')
    await screen.findByText('Cocok.', { selector: 'strong' })
    expect(screen.queryByRole('button', { name: 'Ekspor CSV' })).not.toBeInTheDocument()
    first.unmount()

    const calls = bootAr({ permissions: [...view, 'accounting.report.export'] }, { ...routes, [`GET ${BASE}/reconciliation/ar/export`]: { data: new Blob(['x']) } })
    renderApp('/app/akuntansi/rekonsiliasi/piutang')
    await screen.findByText('Cocok.', { selector: 'strong' })
    await userEvent.click(screen.getByRole('button', { name: 'Ekspor CSV' }))
    await waitFor(() => expect(calls.some((c) => c.url === `${BASE}/reconciliation/ar/export`)).toBe(true))
    expect(calls.find((c) => c.url === `${BASE}/reconciliation/ar/export`)?.params).toEqual({ as_of: '2026-10-08' })
    expect(create).toHaveBeenCalled()
  })

  it('shows empty states for a ledger without control accounts and customers, and an error with a retry', async () => {
    let fail = true
    bootAr({ permissions: view }, {
      ...routes,
      [`GET ${BASE}/reconciliation/ar`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: { ...matched, control_accounts: [], customers: [] } }),
    })
    renderApp('/app/akuntansi/rekonsiliasi/piutang')

    expect(await screen.findByText('Terjadi kesalahan pada server. Coba lagi nanti.')).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByText('Belum ada akun kontrol piutang')).toBeInTheDocument()
    expect(screen.getByText('Tidak ada saldo pelanggan')).toBeInTheDocument()
  })

  it('shows a loading state while the report is on its way', async () => {
    bootAr({ permissions: view }, routes)
    const release = holdGet(`${BASE}/reconciliation/ar`)
    renderApp('/app/akuntansi/rekonsiliasi/piutang')
    await screen.findByLabelText('Per tanggal')
    expect(screen.getByText('Memuat…')).toBeInTheDocument()
    release()
    expect(await screen.findByText('Cocok.', { selector: 'strong' })).toBeInTheDocument()
  })

  it('stays readable on a read-only AR module (reports are reads)', async () => {
    bootAr({ permissions: view, ar: 'READ_ONLY' }, routes)
    renderApp('/app/akuntansi/rekonsiliasi/piutang')
    expect(await screen.findByText('Cocok.', { selector: 'strong' })).toBeInTheDocument()
  })

  it('is closed without the permission, the feature or the module', async () => {
    bootAr({ permissions: ['accounting.ar_aging.view'] }, routes)
    const first = renderApp('/app/akuntansi/rekonsiliasi/piutang')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
    first.unmount()

    bootAr({ permissions: view, features: { AR_AGING: false } }, routes)
    const second = renderApp('/app/akuntansi/rekonsiliasi/piutang')
    expect(await screen.findByText('Fitur tidak tersedia')).toBeInTheDocument()
    second.unmount()

    const calls = bootAr({ permissions: view, modules: { ACCOUNTING_CORE: 'FULL' } }, routes)
    renderApp('/app/akuntansi/rekonsiliasi/piutang')
    expect(await screen.findByText('Modul tidak tersedia')).toBeInTheDocument()
    expect(reconCalls(calls)).toHaveLength(0)
  })
})
