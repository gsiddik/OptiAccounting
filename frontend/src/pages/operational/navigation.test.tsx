import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { Mode } from '../../lib/types'
import { page } from '../../test/fakeApi'
import { renderApp } from '../../test/render'
import { boot, dashboard, FEATURES_ALL, MODULES_ALL, statement, summary, MANAGE } from './bank/testing'

// The OA2 menu and page guards: an item shows only when the user's permission AND the module AND the feature are all there. All of it
// is cosmetic (the API enforces), but it must not offer what the server would refuse.

const nav = () => screen.getByRole('navigation', { name: /Menu/ })
const groups = () => [...nav().querySelectorAll('.nav-group')].map((g) => g.textContent)
const links = () => within(nav()).getAllByRole('link').map((l) => l.textContent)

const NO_SECTIONS = { business_date: '2026-10-08', payables: null, payments: null, expenses: null, cash_bank: null, complete: true }
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
