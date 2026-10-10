import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { OperationalSummary } from '../../lib/operational'
import { renderApp } from '../../test/render'
import { boot, dashboard } from './bank/testing'

const SUMMARY = '/app/accounting/operational-summary'

const summary = (over: Partial<OperationalSummary> = {}): OperationalSummary => ({
  business_date: '2026-10-08',
  payables: {
    outstanding: { amount: '750000.0000', invoices: 3 }, overdue: { amount: '150000.0000', invoices: 1 }, due_soon: { days: 7, amount: '100000.0000', invoices: 1 },
    pending_approval: 1, awaiting_posting: 1,
  },
  payments: { pending_approval: 1, awaiting_posting: 0 },
  expenses: { pending_approval: 2, awaiting_posting: 1 },
  cash_bank: { book_balance: '3250000.0000', cash: '3000000.0000', bank: '250000.0000', accounts: 2, as_of: '2026-10-08' },
  complete: true,
  ...over,
})

async function openHome(routes: NonNullable<Parameters<typeof boot>[1]>) {
  const calls = boot({ permissions: ['accounting.journal.view'] }, routes)
  renderApp('/app/akuntansi')
  await screen.findByRole('heading', { name: 'Akuntansi' })
  return calls
}

const card = (label: string) => within(screen.getByText(label).closest('.card') as HTMLElement)
const summaryHeading = () => screen.queryByRole('heading', { name: 'Ringkasan operasional' })

describe('operational summary on the accounting home', () => {
  it('shows every section the API returned, with the API figures and links to the lists behind them', async () => {
    const calls = await openHome({ 'GET /app/accounting/dashboard': { data: dashboard() }, [`GET ${SUMMARY}`]: { data: summary() } })

    expect(await screen.findByRole('heading', { name: 'Ringkasan operasional' })).toBeInTheDocument()
    expect(calls.find((c) => c.url === SUMMARY)?.params).toBeUndefined()

    const outstanding = screen.getByRole('link', { name: /Utang beredar/ })
    expect(outstanding).toHaveAttribute('href', '/app/akuntansi/faktur-vendor?open=1')
    expect(outstanding).toHaveTextContent('750.000,00')
    expect(outstanding).toHaveTextContent('3 faktur')

    const overdue = screen.getByRole('link', { name: /Jatuh tempo lewat/ })
    expect(overdue).toHaveAttribute('href', '/app/akuntansi/faktur-vendor?overdue=1')
    expect(overdue).toHaveTextContent('150.000,00')
    expect(overdue).toHaveTextContent('1 faktur')

    const soon = screen.getByRole('link', { name: /Jatuh tempo ≤ 7 hari/ })
    expect(soon).toHaveAttribute('href', '/app/akuntansi/faktur-vendor?due_within=7')
    expect(soon).toHaveTextContent('100.000,00')

    const cash = screen.getByRole('link', { name: /Saldo kas & bank/ })
    expect(cash).toHaveAttribute('href', '/app/akuntansi/kas-bank')
    expect(cash).toHaveTextContent('3.250.000,00')
    expect(cash).toHaveTextContent('Kas 3.000.000,00 · Bank 250.000,00 · 2 akun')

    // Waiting counts are integers the API gives per document type; the card shows their sum and links to each list.
    const approval = card('Menunggu persetujuan')
    expect(approval.getByText('4')).toBeInTheDocument() // 1 invoice + 1 payment + 2 expenses
    expect(approval.getByRole('link', { name: '1 faktur' })).toHaveAttribute('href', '/app/akuntansi/faktur-vendor?status=SUBMITTED')
    expect(approval.getByRole('link', { name: '1 pembayaran' })).toHaveAttribute('href', '/app/akuntansi/pembayaran-vendor?status=SUBMITTED')
    expect(approval.getByRole('link', { name: '2 beban' })).toHaveAttribute('href', '/app/akuntansi/beban?status=SUBMITTED')
    const posting = card('Siap diposting')
    expect(posting.getByText('2')).toBeInTheDocument() // 1 + 0 + 1
    expect(posting.getByRole('link', { name: '0 pembayaran' })).toHaveAttribute('href', '/app/akuntansi/pembayaran-vendor?status=APPROVED')
    expect(screen.queryByText(/cakupan akses Anda/)).not.toBeInTheDocument() // complete: no partial-scope notice

    // The accounting home itself is untouched.
    expect(screen.getByText('Tahun fiskal')).toBeInTheDocument()
    expect(screen.getByText('Jurnal terposting terbaru')).toBeInTheDocument()
  })

  it('renders only the sections that are not null, and sums only the counts it was given', async () => {
    await openHome({
      'GET /app/accounting/dashboard': { data: dashboard() },
      [`GET ${SUMMARY}`]: { data: summary({ payables: null, payments: null, cash_bank: null }) }, // only expenses
    })

    const approval = await screen.findByText('Menunggu persetujuan')
    expect(within(approval.closest('.card') as HTMLElement).getByText('2')).toBeInTheDocument()
    expect(within(approval.closest('.card') as HTMLElement).getAllByRole('link').map((l) => l.textContent)).toEqual(['2 beban'])
    expect(card('Siap diposting').getAllByRole('link').map((l) => l.textContent)).toEqual(['1 beban'])
    for (const label of ['Utang beredar', 'Jatuh tempo lewat', 'Saldo kas & bank']) expect(screen.queryByText(label)).not.toBeInTheDocument()
    expect(screen.queryByText(/Jatuh tempo ≤/)).not.toBeInTheDocument()
  })

  it('shows payables alone, and the cash and bank balance alone', async () => {
    const calls = await openHome({
      'GET /app/accounting/dashboard': { data: dashboard() },
      [`GET ${SUMMARY}`]: { data: summary({ payments: null, expenses: null, cash_bank: null }) },
    })
    expect(await screen.findByRole('link', { name: /Utang beredar/ })).toBeInTheDocument()
    expect(card('Menunggu persetujuan').getAllByRole('link').map((l) => l.textContent)).toEqual(['1 faktur'])
    expect(screen.queryByText('Saldo kas & bank')).not.toBeInTheDocument()
    expect(calls.filter((c) => c.url === SUMMARY)).toHaveLength(1)
  })

  it('shows the cash and bank balance without any approval card when that is all the user may see', async () => {
    await openHome({
      'GET /app/accounting/dashboard': { data: dashboard() },
      [`GET ${SUMMARY}`]: { data: summary({ payables: null, payments: null, expenses: null }) },
    })
    expect(await screen.findByRole('link', { name: /Saldo kas & bank/ })).toHaveTextContent('3.250.000,00')
    expect(screen.queryByText('Menunggu persetujuan')).not.toBeInTheDocument()
    expect(screen.queryByText('Siap diposting')).not.toBeInTheDocument()
  })

  it('says when the figures cover only part of the organisation', async () => {
    await openHome({ 'GET /app/accounting/dashboard': { data: dashboard() }, [`GET ${SUMMARY}`]: { data: summary({ complete: false }) } })
    expect(await screen.findByText(/hanya mencakup data dalam cakupan akses Anda/)).toBeInTheDocument()
  })

  it('shows nothing at all for a tenant without the OA2 modules (every section null)', async () => {
    await openHome({
      'GET /app/accounting/dashboard': { data: dashboard() },
      [`GET ${SUMMARY}`]: { data: summary({ payables: null, payments: null, expenses: null, cash_bank: null }) },
    })
    expect(await screen.findByText('Jurnal terposting terbaru')).toBeInTheDocument()
    expect(summaryHeading()).not.toBeInTheDocument()
    expect(screen.queryByText(/Ringkasan operasional tidak dapat dimuat/)).not.toBeInTheDocument()
  })

  it('survives a failing summary endpoint: the home still works and a retry recovers', async () => {
    let fail = true
    await openHome({ 'GET /app/accounting/dashboard': { data: dashboard() }, [`GET ${SUMMARY}`]: () => (fail ? { status: 500, data: { message: 'boom' } } : { data: summary() }) })

    expect(await screen.findByText(/Ringkasan operasional tidak dapat dimuat/)).toBeInTheDocument()
    expect(screen.getByText('Tahun fiskal')).toBeInTheDocument()
    expect(screen.getByText('Jurnal terposting terbaru')).toBeInTheDocument()
    expect(screen.queryByText(/boom/)).not.toBeInTheDocument() // no raw server text

    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Coba lagi' }))
    expect(await screen.findByRole('link', { name: /Utang beredar/ })).toBeInTheDocument()
    expect(screen.queryByText(/Ringkasan operasional tidak dapat dimuat/)).not.toBeInTheDocument()
  })

  it('tolerates a summary request that nothing answers (a failed call of any kind), like the home tests that mock only the dashboard', async () => {
    await openHome({ 'GET /app/accounting/dashboard': { data: dashboard() } }) // GET /operational-summary is not mocked: the transport throws
    expect(await screen.findByText(/Ringkasan operasional tidak dapat dimuat/)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Akuntansi' })).toBeInTheDocument()
    expect(screen.getByText('Jurnal terposting terbaru')).toBeInTheDocument()
  })
})
