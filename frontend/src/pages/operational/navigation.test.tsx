import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Mode } from '../../lib/types'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { boot, dashboard, FEATURES_ALL, MODULES_ALL, statement, summary, MANAGE } from './bank/testing'
import { arInvoice, arInvoiceRow, BASE, cashAccount, creditNote, creditNoteRow, customer, noDimensions, receipt, receiptRow } from './receivables/testkit'

// The OA2 menu and page guards: an item shows only when the user's permission AND the module AND the feature are all there. All of it
// is cosmetic (the API enforces), but it must not offer what the server would refuse.

const nav = () => screen.getByRole('navigation', { name: /Menu/ })
const groups = () => [...nav().querySelectorAll('.nav-group')].map((g) => g.textContent)
const links = () => within(nav()).getAllByRole('link').map((l) => l.textContent)

const NO_SECTIONS = { business_date: '2026-10-08', payables: null, payments: null, expenses: null, cash_bank: null, receivables: null, receipts: null, credit_notes: null, complete: true }
const homeRoutes = { 'GET /app/accounting/dashboard': { data: dashboard() }, 'GET /app/accounting/operational-summary': { data: NO_SECTIONS } }

const OA2_VIEW = [
  'accounting.journal.view', 'accounting.vendor.view', 'accounting.ap_invoice.view', 'accounting.ap_payment.view', 'accounting.ap_aging.view', 'accounting.expense.view',
  'accounting.cash_bank.view', 'accounting.cash_transaction.view', 'accounting.bank_reconciliation.view', 'accounting.reconciliation.ap.view', 'accounting.reconciliation.cash_bank.view',
]

async function openHome(options: Parameters<typeof boot>[0]) {
  boot(options, homeRoutes)
  renderApp('/app/akuntansi')
  await screen.findByRole('heading', { name: 'Akuntansi' })
}

describe('OA2 menu', () => {
  it('shows every OA2 group and item to a user who holds everything', async () => {
    await openHome({ permissions: OA2_VIEW })

    expect(groups()).toEqual(['Akuntansi', 'Utang usaha', 'Beban', 'Kas & bank', 'Rekonsiliasi'])
    expect(links()).toEqual([
      'Dashboard', 'Ringkasan', 'Jurnal', // the core items this user holds (Dashboard is the tenant home)
      'Vendor', 'Faktur vendor', 'Pembayaran vendor', 'Umur utang',
      'Beban', 'Kategori beban',
      'Akun kas & bank', 'Pembayaran kas', 'Penerimaan kas', 'Rekening koran',
      'Utang vs buku besar', 'Kas/bank vs buku besar',
    ])
  })

  it('shows only the items whose permission, module and feature are all present', async () => {
    await openHome({
      permissions: ['accounting.journal.view', 'accounting.ap_invoice.view', 'accounting.expense.view', 'accounting.cash_bank.view', 'accounting.bank_reconciliation.view', 'accounting.reconciliation.cash_bank.view', 'accounting.reconciliation.ap.view'],
      // BANK_RECONCILIATION and AP_AGING are not in the subscription; VENDOR is, but the user holds no vendor permission.
      features: { ...FEATURES_ALL, BANK_RECONCILIATION: false, AP_AGING: false },
    })

    expect(links()).toEqual(['Dashboard', 'Ringkasan', 'Jurnal', 'Faktur vendor', 'Beban', 'Kategori beban', 'Akun kas & bank'])
    expect(groups()).toEqual(['Akuntansi', 'Utang usaha', 'Beban', 'Kas & bank']) // "Rekonsiliasi" has nothing left to show
    expect(within(nav()).queryByRole('link', { name: 'Vendor' })).not.toBeInTheDocument() // feature present, permission missing
    expect(within(nav()).queryByRole('link', { name: 'Rekening koran' })).not.toBeInTheDocument() // permission present, feature missing
    expect(within(nav()).queryByRole('link', { name: 'Kas/bank vs buku besar' })).not.toBeInTheDocument()
  })

  it('hides a group whose module is not in the subscription, and refuses its URL', async () => {
    const modules: Record<string, Mode> = { ACCOUNTING_CORE: 'FULL', ACCOUNTING_AP: 'FULL', ACCOUNTING_EXPENSE: 'FULL' } // no ACCOUNTING_CASH_BANK
    await openHome({ permissions: OA2_VIEW, modules })

    expect(groups()).toEqual(['Akuntansi', 'Utang usaha', 'Beban', 'Rekonsiliasi'])
    expect(links()).not.toContain('Rekening koran')
    expect(links()).not.toContain('Akun kas & bank')
    expect(links()).not.toContain('Kas/bank vs buku besar')
    expect(links()).toContain('Utang vs buku besar') // the AP reconciliation belongs to the AP module and stays

    for (const path of ['/app/akuntansi/rekening-koran', '/app/akuntansi/rekening-koran/st-1', '/app/akuntansi/rekonsiliasi/kas-bank']) {
      const view = renderApp(path)
      expect(await screen.findByText('Modul tidak tersedia')).toBeInTheDocument()
      view.unmount()
    }
  })

  it('keeps every item visible when a module is read-only', async () => {
    await openHome({ permissions: OA2_VIEW, modules: { ...MODULES_ALL, ACCOUNTING_CASH_BANK: 'READ_ONLY', ACCOUNTING_AP: 'READ_ONLY' } })
    expect(groups()).toEqual(['Akuntansi', 'Utang usaha', 'Beban', 'Kas & bank', 'Rekonsiliasi'])
    expect(links()).toContain('Rekening koran')
    expect(links()).toContain('Kas/bank vs buku besar')
  })
})

describe('OA2 page guards', () => {
  it('refuses a typed URL when the permission is missing (module and feature present)', async () => {
    const calls = boot({ permissions: ['accounting.journal.view', 'accounting.bank_reconciliation.view'] })

    renderApp('/app/akuntansi/rekonsiliasi/kas-bank')
    expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()

    const none = boot({ permissions: ['accounting.journal.view'] })
    for (const path of ['/app/akuntansi/rekening-koran', '/app/akuntansi/rekening-koran/st-1']) {
      const view = renderApp(path)
      expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
      view.unmount()
    }
    expect([...calls, ...none].some((c) => c.url.includes('bank-statements') || c.url.includes('reconciliation'))).toBe(false) // nothing was even requested
  })

  it('refuses a typed URL when the feature is not in the subscription', async () => {
    boot({ permissions: [...MANAGE, 'accounting.reconciliation.cash_bank.view'], features: { ...FEATURES_ALL, BANK_RECONCILIATION: false } })
    for (const path of ['/app/akuntansi/rekening-koran', '/app/akuntansi/rekening-koran/st-1', '/app/akuntansi/rekonsiliasi/kas-bank']) {
      const view = renderApp(path)
      expect(await screen.findByText('Fitur tidak tersedia')).toBeInTheDocument()
      view.unmount()
    }
  })
})

describe('read-only entitlement on the bank pages', () => {
  const permissions = [...MANAGE, 'accounting.cash_bank.view', 'accounting.reconciliation.cash_bank.view', 'accounting.report.export']
  const routes = {
    'GET /app/accounting/bank-statements': { data: page([statement({ items: undefined, summary: undefined, unmatched_items: 1, matched_items: 0, exception_items: 0 })]) },
    'GET /app/accounting/bank-statements/st-1': { data: statement({ summary: summary() }) },
    'GET /app/accounting/cash-bank-accounts': { data: page([]) },
  }

  it('lets a user with the same permissions change data while the module is writable', async () => {
    boot({ permissions }, routes)
    renderApp('/app/akuntansi/rekening-koran')
    expect(await screen.findByRole('button', { name: 'Rekening koran baru' })).toBeInTheDocument()
    expect(screen.queryByText(/mode hanya baca/)).not.toBeInTheDocument()
  })

  it.each([
    ['the cash and bank module is read-only', { ...MODULES_ALL, ACCOUNTING_CASH_BANK: 'READ_ONLY' as Mode }],
    ['the whole subscription is read-only', { ACCOUNTING_CORE: 'READ_ONLY' as Mode, ACCOUNTING_AP: 'READ_ONLY' as Mode, ACCOUNTING_EXPENSE: 'READ_ONLY' as Mode, ACCOUNTING_CASH_BANK: 'READ_ONLY' as Mode }],
    ['the accounting core is read-only', { ...MODULES_ALL, ACCOUNTING_CORE: 'READ_ONLY' as Mode }],
  ])('offers data but no mutating button when %s', async (_name, modules) => {
    boot({ permissions, modules, subscriptionMode: (modules as Record<string, Mode>).ACCOUNTING_CORE === 'READ_ONLY' ? 'READ_ONLY' : 'FULL' }, routes)

    // The list: visible and filterable, no way to create or delete.
    const list = renderApp('/app/akuntansi/rekening-koran')
    expect(await screen.findByRole('link', { name: 'BCA-2026-03' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Rekening koran baru' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Hapus/ })).not.toBeInTheDocument()
    await waitFor(() => expect(within(nav()).getByRole('link', { name: 'Rekening koran' })).toBeInTheDocument()) // the menu item stays
    list.unmount()

    // The statement: figures, items and export remain; every action is gone.
    const detail = renderApp('/app/akuntansi/rekening-koran/st-1')
    expect(await screen.findByRole('heading', { name: 'BCA-2026-03' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ekspor CSV' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Ringkasan rekonsiliasi' })).toBeInTheDocument()
    for (const name of [/Cocokkan/, /Pengecualian baris/, /Lepas/, /^Ubah/, /^Hapus/, /Tambah baris/, /Selesaikan rekonsiliasi/]) expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    detail.unmount()

    // The report is read-only by nature and still opens.
    boot({ permissions, modules }, { 'GET /app/accounting/reconciliation/cash-bank': { data: { as_of: '2026-10-08', accounts: [], mismatched_accounts: 0, status: 'MATCHED', book_balance: '0.0000', complete: true } }, 'GET /app/accounting/cash-bank-accounts': { data: page([]) } })
    renderApp('/app/akuntansi/rekonsiliasi/kas-bank')
    expect(await screen.findByText('Tidak ada akun kas/bank')).toBeInTheDocument()
  })

  it('shows the read-only notice on the pages', async () => {
    boot({ permissions, modules: { ...MODULES_ALL, ACCOUNTING_CASH_BANK: 'READ_ONLY' } }, routes)
    renderApp('/app/akuntansi/rekening-koran')
    expect(await screen.findByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument() // the notice shows while the list is still loading
    await userEvent.click(await screen.findByRole('link', { name: 'BCA-2026-03' })) // so wait for the row before following it
    expect(await screen.findByRole('heading', { name: 'Ringkasan rekonsiliasi' })).toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
  })
})

// ---------------------------------------------------------------------------------------------------------------------------------------
// OA3: the receivables menu and guards. ACCOUNTING_AR depends only on ACCOUNTING_CORE (not on the payables or the cash and bank module).

const OA3_VIEW = [
  'accounting.journal.view', 'accounting.customer.view', 'accounting.ar_invoice.view', 'accounting.ar_receipt.view', 'accounting.ar_credit_note.view', 'accounting.ar_aging.view',
  'accounting.reconciliation.ar.view',
]
const AR_FEATURES: Record<string, boolean> = { CUSTOMER: true, CUSTOMER_INVOICE: true, AR_RECEIPT: true, CREDIT_NOTE: true, AR_AGING: true }
const OA3_MODULES: Record<string, Mode> = { ...MODULES_ALL, ACCOUNTING_AR: 'FULL' }
const OA3_FEATURES: Record<string, boolean> = { ...FEATURES_ALL, ...AR_FEATURES }
const OA3_LINKS = ['Pelanggan', 'Faktur pelanggan', 'Penerimaan pelanggan', 'Nota kredit', 'Umur piutang', 'Piutang vs buku besar']
const AR_PATHS = [
  '/app/akuntansi/pelanggan', '/app/akuntansi/faktur-pelanggan', '/app/akuntansi/faktur-pelanggan/ai-1', '/app/akuntansi/penerimaan-pelanggan', '/app/akuntansi/penerimaan-pelanggan/rc-1',
  '/app/akuntansi/nota-kredit', '/app/akuntansi/nota-kredit/cn-1', '/app/akuntansi/umur-piutang', '/app/akuntansi/rekonsiliasi/piutang',
]

describe('OA3 menu', () => {
  it('shows the Piutang group and its items to a user who holds everything', async () => {
    await openHome({ permissions: OA3_VIEW, modules: OA3_MODULES, features: OA3_FEATURES })

    expect(groups()).toEqual(['Akuntansi', 'Piutang', 'Rekonsiliasi'])
    expect(links()).toEqual(['Dashboard', 'Ringkasan', 'Jurnal', ...OA3_LINKS])
  })

  it('places the Piutang group after the payables group when a user holds both', async () => {
    await openHome({ permissions: [...OA2_VIEW, ...OA3_VIEW], modules: OA3_MODULES, features: OA3_FEATURES })

    expect(groups()).toEqual(['Akuntansi', 'Utang usaha', 'Piutang', 'Beban', 'Kas & bank', 'Rekonsiliasi'])
    const all = links()
    expect(all.indexOf('Umur utang')).toBeLessThan(all.indexOf('Pelanggan'))
    expect(all.indexOf('Umur piutang')).toBeLessThan(all.indexOf('Beban'))
    expect(all.indexOf('Utang vs buku besar')).toBeLessThan(all.indexOf('Piutang vs buku besar'))
  })

  it('shows only the items whose permission, module and feature are all present', async () => {
    await openHome({
      // No credit-note permission and no customer-invoice permission; AR_AGING is not subscribed (the aging and its reconciliation both need it).
      permissions: ['accounting.journal.view', 'accounting.customer.view', 'accounting.ar_receipt.view', 'accounting.ar_credit_note.view', 'accounting.ar_aging.view', 'accounting.reconciliation.ar.view'],
      modules: OA3_MODULES,
      features: { ...OA3_FEATURES, AR_AGING: false, CREDIT_NOTE: true },
    })

    expect(links()).toEqual(['Dashboard', 'Ringkasan', 'Jurnal', 'Pelanggan', 'Penerimaan pelanggan', 'Nota kredit'])
    expect(groups()).toEqual(['Akuntansi', 'Piutang']) // "Rekonsiliasi" has nothing left to show
    expect(within(nav()).queryByRole('link', { name: 'Faktur pelanggan' })).not.toBeInTheDocument() // feature present, permission missing
    expect(within(nav()).queryByRole('link', { name: 'Umur piutang' })).not.toBeInTheDocument() // permission present, feature missing
    expect(within(nav()).queryByRole('link', { name: 'Piutang vs buku besar' })).not.toBeInTheDocument()
  })

  it('hides a feature item whose feature is not subscribed even when the user holds every permission', async () => {
    await openHome({ permissions: OA3_VIEW, modules: OA3_MODULES, features: { ...OA3_FEATURES, CREDIT_NOTE: false, AR_RECEIPT: false } })
    expect(links()).toEqual(['Dashboard', 'Ringkasan', 'Jurnal', 'Pelanggan', 'Faktur pelanggan', 'Umur piutang', 'Piutang vs buku besar'])
  })

  it('hides the Piutang group and the receivables reconciliation when the module is not subscribed, and refuses every URL', async () => {
    const modules: Record<string, Mode> = { ...MODULES_ALL } // no ACCOUNTING_AR
    await openHome({ permissions: [...OA2_VIEW, ...OA3_VIEW], modules, features: OA3_FEATURES })

    expect(groups()).toEqual(['Akuntansi', 'Utang usaha', 'Beban', 'Kas & bank', 'Rekonsiliasi'])
    for (const label of OA3_LINKS) expect(links()).not.toContain(label)
    expect(links()).toContain('Utang vs buku besar') // the AP reconciliation belongs to the AP module and stays
    expect(links()).toContain('Kas/bank vs buku besar')

    for (const path of AR_PATHS) {
      const view = renderApp(path)
      expect(await screen.findByText('Modul tidak tersedia')).toBeInTheDocument()
      view.unmount()
    }
  })

  it('keeps the receivables group for a tenant that has no payables, expense or cash and bank module', async () => {
    await openHome({ permissions: [...OA2_VIEW, ...OA3_VIEW], modules: { ACCOUNTING_CORE: 'FULL', ACCOUNTING_AR: 'FULL' }, features: OA3_FEATURES })

    expect(groups()).toEqual(['Akuntansi', 'Piutang', 'Rekonsiliasi'])
    expect(links()).toEqual(['Dashboard', 'Ringkasan', 'Jurnal', ...OA3_LINKS])
  })

  it('keeps every receivables item visible when the module is read-only', async () => {
    await openHome({ permissions: OA3_VIEW, modules: { ...OA3_MODULES, ACCOUNTING_AR: 'READ_ONLY' }, features: OA3_FEATURES })
    expect(groups()).toEqual(['Akuntansi', 'Piutang', 'Rekonsiliasi'])
    expect(links()).toEqual(['Dashboard', 'Ringkasan', 'Jurnal', ...OA3_LINKS])
  })

  it('keeps every receivables item visible on a read-only subscription', async () => {
    const readOnly: Record<string, Mode> = { ACCOUNTING_CORE: 'READ_ONLY', ACCOUNTING_AR: 'READ_ONLY' }
    await openHome({ permissions: OA3_VIEW, modules: readOnly, features: OA3_FEATURES, subscriptionMode: 'READ_ONLY' })
    expect(links()).toEqual(['Dashboard', 'Ringkasan', 'Jurnal', ...OA3_LINKS])
  })
})

describe('OA3 page guards', () => {
  it('refuses a typed URL when the permission is missing (module and feature present), without asking the API for anything', async () => {
    const calls = boot({ permissions: ['accounting.journal.view'], modules: OA3_MODULES, features: OA3_FEATURES })
    for (const path of AR_PATHS) {
      const view = renderApp(path)
      expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
      view.unmount()
    }
    expect(calls.some((c) => /customers|ar-invoices|customer-receipts|ar-credit-notes|ar-aging|reconciliation\/ar/.test(c.url))).toBe(false)
  })

  it('refuses a creation URL to a user who may only view', async () => {
    boot({ permissions: OA3_VIEW, modules: OA3_MODULES, features: OA3_FEATURES })
    for (const path of ['/app/akuntansi/faktur-pelanggan/baru', '/app/akuntansi/faktur-pelanggan/ai-1/ubah', '/app/akuntansi/penerimaan-pelanggan/baru', '/app/akuntansi/penerimaan-pelanggan/rc-1/ubah', '/app/akuntansi/nota-kredit/baru', '/app/akuntansi/nota-kredit/cn-1/ubah']) {
      const view = renderApp(path)
      expect(await screen.findByText('Akses ditolak')).toBeInTheDocument()
      view.unmount()
    }
  })

  it.each([
    ['/app/akuntansi/pelanggan', 'CUSTOMER'],
    ['/app/akuntansi/faktur-pelanggan', 'CUSTOMER_INVOICE'],
    ['/app/akuntansi/faktur-pelanggan/baru', 'CUSTOMER_INVOICE'],
    ['/app/akuntansi/faktur-pelanggan/ai-1', 'CUSTOMER_INVOICE'],
    ['/app/akuntansi/penerimaan-pelanggan', 'AR_RECEIPT'],
    ['/app/akuntansi/penerimaan-pelanggan/baru', 'AR_RECEIPT'],
    ['/app/akuntansi/penerimaan-pelanggan/rc-1', 'AR_RECEIPT'],
    ['/app/akuntansi/nota-kredit', 'CREDIT_NOTE'],
    ['/app/akuntansi/nota-kredit/baru', 'CREDIT_NOTE'],
    ['/app/akuntansi/nota-kredit/cn-1', 'CREDIT_NOTE'],
    ['/app/akuntansi/umur-piutang', 'AR_AGING'],
    ['/app/akuntansi/rekonsiliasi/piutang', 'AR_AGING'],
  ])('refuses %s when the %s feature is not in the subscription', async (path, feature) => {
    const everything = [...OA3_VIEW, 'accounting.customer.manage', 'accounting.ar_invoice.create', 'accounting.ar_receipt.create', 'accounting.ar_credit_note.create']
    const calls = boot({ permissions: everything, modules: OA3_MODULES, features: { ...OA3_FEATURES, [feature]: false } })
    renderApp(path)
    expect(await screen.findByText('Fitur tidak tersedia')).toBeInTheDocument()
    expect(calls.some((c) => /ar-invoices|customer-receipts|ar-credit-notes|ar-aging|reconciliation\/ar/.test(c.url))).toBe(false)
  })
})

describe('read-only entitlement on the receivables pages', () => {
  const mutate = [
    ...OA3_VIEW, 'accounting.report.export', 'accounting.customer.manage',
    'accounting.ar_invoice.create', 'accounting.ar_invoice.update', 'accounting.ar_invoice.submit', 'accounting.ar_invoice.approve', 'accounting.ar_invoice.post', 'accounting.ar_invoice.reverse',
    'accounting.ar_receipt.create', 'accounting.ar_receipt.update', 'accounting.ar_receipt.submit', 'accounting.ar_receipt.approve', 'accounting.ar_receipt.post', 'accounting.ar_receipt.reverse',
    'accounting.ar_credit_note.create', 'accounting.ar_credit_note.submit', 'accounting.ar_credit_note.approve', 'accounting.ar_credit_note.post', 'accounting.ar_credit_note.reverse',
  ]
  const routes = {
    [`GET ${BASE}/customers`]: { data: page([customer()]) },
    [`GET ${BASE}/ar-invoices`]: { data: page([arInvoiceRow()]) },
    [`GET ${BASE}/ar-invoices/ai-1`]: { data: arInvoice({ status: 'APPROVED', document_number: null, payment_status: null, allocations: [], credit_notes: [] }) },
    [`GET ${BASE}/customer-receipts`]: { data: page([receiptRow()]) },
    [`GET ${BASE}/customer-receipts/rc-1`]: { data: receipt({ status: 'APPROVED', document_number: null }) },
    [`GET ${BASE}/ar-credit-notes`]: { data: page([creditNoteRow()]) },
    [`GET ${BASE}/ar-credit-notes/cn-1`]: { data: creditNote({ status: 'DRAFT', document_number: null }) },
    [`GET ${BASE}/cash-bank-accounts`]: { data: page([cashAccount]) },
    [`GET ${BASE}/payment-terms`]: { data: { data: [] } },
    [`GET ${BASE}/ar-payment-terms`]: { data: { data: [] } },
    [`GET ${BASE}/dimensions`]: { data: noDimensions },
    [`GET ${BASE}/reconciliation/ar`]: { data: { as_of: '2026-10-08', customer_id: null, control_accounts: [], gl_balance: '0.0000', opening_balance_component: '0.0000', gl_transactional_balance: '0.0000', subledger_balance: '0.0000', difference: '0.0000', status: 'MATCHED', customers: [], complete: true } },
  }
  const LISTS = [
    ['/app/akuntansi/faktur-pelanggan', 'ARI-FY2026-000001', 'Faktur baru'],
    ['/app/akuntansi/penerimaan-pelanggan', 'RCP-FY2026-000001', 'Penerimaan baru'],
    ['/app/akuntansi/nota-kredit', 'CN-FY2026-000001', 'Nota kredit baru'],
  ]
  const ACTIONS = /^(Ubah|Ajukan|Setujui|Tolak|Posting|Jadikan draf|Batalkan|Balik|Buat nota kredit)$/

  it.each(LISTS)('lets a user with the same permissions create on %s while the module is writable', async (path, link, create) => {
    boot({ permissions: mutate, modules: OA3_MODULES, features: OA3_FEATURES }, routes)
    renderApp(path)
    expect(await screen.findByRole('link', { name: link })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: create })).toBeInTheDocument()
    expect(screen.queryByText(/mode hanya baca/)).not.toBeInTheDocument()
  })

  it.each([
    ['the receivables module is read-only', { ...OA3_MODULES, ACCOUNTING_AR: 'READ_ONLY' as Mode }, 'FULL' as const],
    ['the whole subscription is read-only', { ACCOUNTING_CORE: 'READ_ONLY' as Mode, ACCOUNTING_AR: 'READ_ONLY' as Mode, ACCOUNTING_CASH_BANK: 'READ_ONLY' as Mode }, 'READ_ONLY' as const],
    ['the accounting core is read-only', { ...OA3_MODULES, ACCOUNTING_CORE: 'READ_ONLY' as Mode }, 'READ_ONLY' as const],
  ])('offers data but no mutating button when %s', async (_name, modules, subscriptionMode) => {
    boot({ permissions: mutate, modules, features: OA3_FEATURES, subscriptionMode }, routes)

    // The lists: visible and filterable, exportable, no way to create.
    for (const [path, link, create] of LISTS) {
      const list = renderApp(path)
      expect(await screen.findByRole('link', { name: link })).toBeInTheDocument()
      expect(screen.queryByRole('button', { name: create })).not.toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Ekspor CSV' })).toBeInTheDocument()
      list.unmount()
    }

    // The documents: facts and history remain; every lifecycle action is gone.
    for (const path of ['/app/akuntansi/faktur-pelanggan/ai-1', '/app/akuntansi/penerimaan-pelanggan/rc-1', '/app/akuntansi/nota-kredit/cn-1']) {
      const detail = renderApp(path)
      await screen.findByText('Riwayat')
      expect(screen.queryAllByRole('button', { name: ACTIONS })).toEqual([])
      expect(screen.queryAllByRole('link', { name: ACTIONS })).toEqual([])
      detail.unmount()
    }

    // The customer master: readable, not manageable.
    const customers = renderApp('/app/akuntansi/pelanggan')
    expect(await screen.findByText('PT Pelanggan Setia')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Pelanggan baru' })).not.toBeInTheDocument()
    customers.unmount()

    // The reports are read-only by nature and still open.
    renderApp('/app/akuntansi/rekonsiliasi/piutang')
    expect(await screen.findByText('Cocok.', { selector: 'strong' })).toBeInTheDocument()
  })

  it('shows the read-only notice on the pages of a read-only module', async () => {
    boot({ permissions: mutate, modules: { ...OA3_MODULES, ACCOUNTING_AR: 'READ_ONLY' }, features: OA3_FEATURES }, routes)
    renderApp('/app/akuntansi/faktur-pelanggan')
    expect(await screen.findByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
    await userEvent.click(await screen.findByRole('link', { name: 'ARI-FY2026-000001' }))
    expect(await screen.findByText('Riwayat')).toBeInTheDocument()
    expect(screen.getByText(/Modul ini dalam mode hanya baca/)).toBeInTheDocument()
  })
})
