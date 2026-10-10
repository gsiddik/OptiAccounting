import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { dashboard } from '../pages/operational/bank/testing'
import { arInvoice, arInvoiceRow, BASE, bootAr, customer, noDimensions } from '../pages/operational/receivables/testkit'
import { page } from '../test/fakeApi'
import { renderApp } from '../test/render'

// The shell around every portal page: logo container, collapsible menu, breadcrumb and Back.

const PERMISSIONS = ['accounting.journal.view', 'accounting.ar_invoice.view', 'accounting.customer.view']
const routes = {
  [`GET ${BASE}/ar-invoices`]: { data: page([arInvoiceRow()]) },
  [`GET ${BASE}/ar-invoices/ai-1`]: { data: arInvoice() },
  [`GET ${BASE}/customers`]: { data: page([customer()]) },
  [`GET ${BASE}/dimensions`]: { data: noDimensions },
  'GET /app/accounting/dashboard': { data: dashboard() },
  'GET /app/accounting/operational-summary': { data: { business_date: '2026-10-08', payables: null, payments: null, expenses: null, cash_bank: null, receivables: null, receipts: null, credit_notes: null, complete: true } },
}

const LIST = '/app/akuntansi/faktur-pelanggan'
const menu = () => screen.getByRole('navigation', { name: /Menu/ })
const trail = () => within(screen.getByRole('navigation', { name: 'Breadcrumb' }))
const group = (name: string) => within(menu()).getByRole('button', { name })

async function openList() {
  bootAr({ permissions: PERMISSIONS }, routes)
  const view = renderApp(LIST)
  await screen.findByRole('link', { name: 'ARI-FY2026-000001' })
  return view
}

describe('logo container', () => {
  it('shows the OptiEntry logo in the sidebar as a link home, with every raster size to choose from', async () => {
    await openList()

    const sidebar = screen.getByRole('complementary', { name: 'Navigasi utama' })
    const link = within(sidebar).getByRole('link', { name: 'OptiEntry, ke beranda' })
    expect(link).toHaveAttribute('href', '/app')
    expect(link).toHaveClass('brand-logo')
    const image = link.querySelector('img')
    expect(image).toHaveAttribute('src', '/brand/optientry-logo-800.png')
    expect(image?.getAttribute('srcset')).toBe('/brand/optientry-logo-400.png 400w, /brand/optientry-logo-800.png 800w, /brand/optientry-logo-1200.png 1200w, /brand/optientry-logo-1878.png 1878w')
    expect(image).toHaveAttribute('width', '1878')
    expect(image).toHaveAttribute('height', '437')
  })

  it('shows the logo (not the old name) on the sign-in page', async () => {
    renderApp('/login')
    expect(await screen.findByRole('img', { name: 'OptiEntry' })).toHaveAttribute('srcset')
    expect(screen.queryByText('OptiAccounting')).not.toBeInTheDocument()
  })
})

describe('collapsible menu', () => {
  it('expands and collapses a group, and tells assistive technology which', async () => {
    await openList()
    const piutang = group('Piutang')
    expect(piutang).toHaveAttribute('aria-expanded', 'true')
    const panel = document.getElementById(piutang.getAttribute('aria-controls') ?? '') as HTMLElement
    expect(within(panel).getByRole('link', { name: 'Faktur pelanggan' })).toBeInTheDocument()

    await userEvent.click(piutang)
    expect(piutang).toHaveAttribute('aria-expanded', 'false')
    expect(panel).toBeInTheDocument()
    expect(panel).not.toBeVisible()
    expect(within(menu()).queryByRole('link', { name: 'Faktur pelanggan' })).not.toBeInTheDocument()
    expect(within(menu()).getByRole('link', { name: 'Jurnal' })).toBeInTheDocument() // the other groups are untouched

    await userEvent.click(piutang)
    expect(piutang).toHaveAttribute('aria-expanded', 'true')
    expect(within(menu()).getByRole('link', { name: 'Faktur pelanggan' })).toBeInTheDocument()
  })

  it('remembers a collapsed group on the next visit', async () => {
    const first = await openList()
    await userEvent.click(group('Akuntansi'))
    expect(JSON.parse(window.localStorage.getItem('optientry.sidebar.collapsed.tenant') ?? '[]')).toEqual(['Akuntansi'])
    first.unmount()

    await openList()
    expect(group('Akuntansi')).toHaveAttribute('aria-expanded', 'false')
    expect(group('Piutang')).toHaveAttribute('aria-expanded', 'true')
  })

  it('opens the group of the page you arrive on, even if it was collapsed', async () => {
    window.localStorage.setItem('optientry.sidebar.collapsed.tenant', JSON.stringify(['Piutang', 'Akuntansi']))
    await openList()

    expect(group('Piutang')).toHaveAttribute('aria-expanded', 'true') // the group of this page
    expect(group('Akuntansi')).toHaveAttribute('aria-expanded', 'false') // the stored choice for the others
    expect(within(menu()).getByRole('link', { name: 'Faktur pelanggan' })).toHaveClass('active')
  })

  it('reopens a collapsed group when navigation brings you back into it', async () => {
    await openList()
    await userEvent.click(group('Piutang')) // collapse the group of the current page
    expect(group('Piutang')).toHaveAttribute('aria-expanded', 'false')
    expect(group('Piutang')).toHaveClass('has-active') // the collapsed header still shows where you are

    await userEvent.click(within(menu()).getByRole('link', { name: 'Ringkasan' }))
    await screen.findByRole('heading', { name: 'Akuntansi' })
    await userEvent.click(screen.getByRole('button', { name: 'Kembali ke halaman sebelumnya' }))

    await screen.findByRole('link', { name: 'ARI-FY2026-000001' })
    expect(group('Piutang')).toHaveAttribute('aria-expanded', 'true')
  })

  it('collapses and expands all groups at once', async () => {
    await openList()
    await userEvent.click(within(menu()).getByRole('button', { name: 'Ciutkan semua' }))
    for (const name of ['Akuntansi', 'Piutang']) expect(group(name)).toHaveAttribute('aria-expanded', 'false')

    await userEvent.click(within(menu()).getByRole('button', { name: 'Bentangkan semua' }))
    for (const name of ['Akuntansi', 'Piutang']) expect(group(name)).toHaveAttribute('aria-expanded', 'true')
  })

  it('keeps working when the browser refuses to store the choice', async () => {
    const original = Storage.prototype.setItem
    Storage.prototype.setItem = () => {
      throw new Error('blocked')
    }
    try {
      await openList()
      await userEvent.click(group('Piutang'))
      expect(group('Piutang')).toHaveAttribute('aria-expanded', 'false')
    } finally {
      Storage.prototype.setItem = original
    }
  })
})

describe('breadcrumb and Back', () => {
  it('shows where a list page sits, with the current page as the last step', async () => {
    await openList()

    const items = within(trail().getByRole('list')).getAllByRole('listitem')
    expect(items.map((i) => i.textContent)).toEqual(['Beranda', 'Piutang', 'Faktur pelanggan'])
    expect(trail().getByRole('link', { name: 'Beranda' })).toHaveAttribute('href', '/app')
    expect(trail().queryByRole('link', { name: 'Piutang' })).not.toBeInTheDocument() // a group is only a label
    expect(trail().getByText('Faktur pelanggan')).toHaveAttribute('aria-current', 'page')
  })

  it('reads a detail page as its document number, and the earlier steps lead back', async () => {
    await openList()
    await userEvent.click(screen.getByRole('link', { name: 'ARI-FY2026-000001' }))

    await screen.findByRole('heading', { name: 'ARI-FY2026-000001' })
    await waitFor(() => expect(trail().getByText('ARI-FY2026-000001')).toHaveAttribute('aria-current', 'page'))
    expect(trail().getByRole('link', { name: 'Faktur pelanggan' })).toHaveAttribute('href', LIST)
  })

  it('goes back to the page you came from', async () => {
    await openList()
    await userEvent.click(screen.getByRole('link', { name: 'ARI-FY2026-000001' }))
    await screen.findByRole('heading', { name: 'ARI-FY2026-000001' })

    await userEvent.click(screen.getByRole('button', { name: 'Kembali ke halaman sebelumnya' }))

    expect(await screen.findByRole('heading', { name: 'Faktur pelanggan' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'ARI-FY2026-000001' })).toBeInTheDocument()
  })

  it('goes to the parent page when the page was opened directly and there is nothing to go back to', async () => {
    bootAr({ permissions: PERMISSIONS }, routes)
    renderApp(`${LIST}/ai-1`)
    await screen.findByRole('heading', { name: 'ARI-FY2026-000001' })

    await userEvent.click(screen.getByRole('button', { name: 'Kembali ke halaman sebelumnya' }))

    expect(await screen.findByRole('heading', { name: 'Faktur pelanggan' })).toBeInTheDocument()
  })

  it('offers no Back on the portal home when there is nowhere to go, and marks the home as the current page', async () => {
    bootAr({ permissions: PERMISSIONS }, routes)
    renderApp('/app')
    await screen.findByRole('heading', { name: 'PT Maju Jaya' })

    expect(screen.queryByRole('button', { name: 'Kembali ke halaman sebelumnya' })).not.toBeInTheDocument()
    expect(trail().getByText('Beranda')).toHaveAttribute('aria-current', 'page')
  })

  it('has a breadcrumb on a page that does not exist inside the portal, and a way out', async () => {
    bootAr({ permissions: PERMISSIONS }, routes)
    renderApp('/app/tidak-ada')

    expect(await screen.findByText('Halaman tidak ditemukan', { selector: 'strong' })).toBeInTheDocument()
    expect(trail().getByRole('link', { name: 'Beranda' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Kembali ke halaman sebelumnya' })).toBeInTheDocument()
  })
})
