import { screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { OperationalSummary } from '../../lib/operational'
import { renderApp } from '../../test/render'
import { boot, dashboard, FEATURES_ALL, MODULES_ALL } from './bank/testing'

// The OA3 sections of the operational summary on the accounting home (the OA2 sections are covered by summary.test.tsx). The API decides
// which sections a user may see and computes every figure; the home only lays them out.

const SUMMARY = '/app/accounting/operational-summary'
const modules = { ...MODULES_ALL, ACCOUNTING_AR: 'FULL' as const }
const features = { ...FEATURES_ALL, CUSTOMER: true, CUSTOMER_INVOICE: true, AR_RECEIPT: true, CREDIT_NOTE: true, AR_AGING: true }

const summary = (over: Partial<OperationalSummary> = {}): OperationalSummary => ({
  business_date: '2026-10-08',
  payables: null, payments: null, expenses: null, cash_bank: null,
  receivables: {
    outstanding: { amount: '1200000.5000', invoices: 4 }, overdue: { amount: '300000.0000', invoices: 2 }, due_soon: { days: 7, amount: '450000.2500', invoices: 1 },
    pending_approval: 2, awaiting_posting: 1,
  },
  receipts: { pending_approval: 3, awaiting_posting: 2 },
  credit_notes: { pending_approval: 1, awaiting_posting: 0 },
  complete: true,
  ...over,
})

async function openHome(routes: NonNullable<Parameters<typeof boot>[1]>) {
  const calls = boot({ permissions: ['accounting.journal.view'], modules, features }, routes)
  renderApp('/app/akuntansi')
  await screen.findByRole('heading', { name: 'Akuntansi' })
  return calls
}

const card = (label: string) => within(screen.getByText(label).closest('.card') as HTMLElement)
const withSummary = (data: OperationalSummary) => ({ 'GET /app/accounting/dashboard': { data: dashboard() }, [`GET ${SUMMARY}`]: { data } })

describe('receivables on the operational summary', () => {
  it('shows the receivable balances the API computed, each linking to the filtered invoice list', async () => {
    await openHome(withSummary(summary()))

    expect(await screen.findByRole('heading', { name: 'Ringkasan operasional' })).toBeInTheDocument()
    const outstanding = screen.getByRole('link', { name: /Piutang beredar/ })
    expect(outstanding).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan?open=1')
    expect(outstanding).toHaveTextContent('1.200.000,50')
    expect(outstanding).toHaveTextContent('4 faktur')

    const overdue = screen.getByRole('link', { name: /Piutang lewat jatuh tempo/ })
    expect(overdue).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan?overdue=1')
    expect(overdue).toHaveTextContent('300.000,00')
    expect(overdue).toHaveTextContent('2 faktur')

    const soon = screen.getByRole('link', { name: /Piutang jatuh tempo ≤ 7 hari/ })
    expect(soon).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan?due_within=7')
    expect(soon).toHaveTextContent('450.000,25')
    expect(soon).toHaveTextContent('1 faktur')

    // The payable cards are absent: that section is null.
    for (const label of ['Utang beredar', 'Jatuh tempo lewat', 'Saldo kas & bank']) expect(screen.queryByText(label)).not.toBeInTheDocument()
  })

  it('sums the waiting counts of invoices, receipts and credit notes, and links each list with its status', async () => {
    await openHome(withSummary(summary()))

    const approval = await screen.findByText('Menunggu persetujuan')
    const approvalCard = within(approval.closest('.card') as HTMLElement)
    expect(approvalCard.getByText('6')).toBeInTheDocument() // 2 invoices + 3 receipts + 1 credit note
    expect(approvalCard.getByRole('link', { name: '2 faktur pelanggan' })).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan?status=SUBMITTED')
    expect(approvalCard.getByRole('link', { name: '3 penerimaan' })).toHaveAttribute('href', '/app/akuntansi/penerimaan-pelanggan?status=SUBMITTED')
    expect(approvalCard.getByRole('link', { name: '1 nota kredit' })).toHaveAttribute('href', '/app/akuntansi/nota-kredit?status=SUBMITTED')

    const posting = card('Siap diposting')
    expect(posting.getByText('3')).toBeInTheDocument() // 1 + 2 + 0
    expect(posting.getByRole('link', { name: '1 faktur pelanggan' })).toHaveAttribute('href', '/app/akuntansi/faktur-pelanggan?status=APPROVED')
    expect(posting.getByRole('link', { name: '2 penerimaan' })).toHaveAttribute('href', '/app/akuntansi/penerimaan-pelanggan?status=APPROVED')
    expect(posting.getByRole('link', { name: '0 nota kredit' })).toHaveAttribute('href', '/app/akuntansi/nota-kredit?status=APPROVED')
  })

  it('adds the OA3 counts to the OA2 counts on one card when the user may see both', async () => {
    await openHome(withSummary(summary({
      payables: { outstanding: { amount: '750000.0000', invoices: 3 }, overdue: { amount: '150000.0000', invoices: 1 }, due_soon: { days: 7, amount: '100000.0000', invoices: 1 }, pending_approval: 1, awaiting_posting: 1 },
      payments: { pending_approval: 1, awaiting_posting: 0 },
      expenses: { pending_approval: 2, awaiting_posting: 1 },
    })))

    const approval = within((await screen.findByText('Menunggu persetujuan')).closest('.card') as HTMLElement)
    expect(approval.getByText('10')).toBeInTheDocument() // 1 + 1 + 2 on the payable side, 2 + 3 + 1 on the receivable side
    expect(approval.getAllByRole('link').map((l) => l.textContent)).toEqual(['1 faktur', '1 pembayaran', '2 beban', '2 faktur pelanggan', '3 penerimaan', '1 nota kredit'])
    expect(screen.getByRole('link', { name: /Utang beredar/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Piutang beredar/ })).toBeInTheDocument()
  })

  it('renders only the sections that are not null', async () => {
    await openHome(withSummary(summary({ receivables: null, credit_notes: null }))) // only receipts

    const approval = await screen.findByText('Menunggu persetujuan')
    const approvalCard = within(approval.closest('.card') as HTMLElement)
    expect(approvalCard.getByText('3')).toBeInTheDocument()
    expect(approvalCard.getAllByRole('link').map((l) => l.textContent)).toEqual(['3 penerimaan'])
    expect(card('Siap diposting').getAllByRole('link').map((l) => l.textContent)).toEqual(['2 penerimaan'])
    for (const label of ['Piutang beredar', 'Piutang lewat jatuh tempo']) expect(screen.queryByText(label)).not.toBeInTheDocument()
    expect(screen.queryByText(/Piutang jatuh tempo ≤/)).not.toBeInTheDocument()
  })

  it('shows credit notes alone without the receivable balances', async () => {
    await openHome(withSummary(summary({ receivables: null, receipts: null })))
    const approval = await screen.findByText('Menunggu persetujuan')
    expect(within(approval.closest('.card') as HTMLElement).getAllByRole('link').map((l) => l.textContent)).toEqual(['1 nota kredit'])
    expect(screen.queryByText('Piutang beredar')).not.toBeInTheDocument()
  })

  it('shows the receivable balances without any approval card when no document count is available', async () => {
    await openHome(withSummary(summary({ receipts: null, credit_notes: null })))
    expect(await screen.findByRole('link', { name: /Piutang beredar/ })).toHaveTextContent('1.200.000,50')
    expect(card('Menunggu persetujuan').getAllByRole('link').map((l) => l.textContent)).toEqual(['2 faktur pelanggan'])
  })

  it('shows nothing at all when every OA3 section (and every OA2 section) is null', async () => {
    await openHome(withSummary(summary({ receivables: null, receipts: null, credit_notes: null })))
    expect(await screen.findByText('Jurnal terposting terbaru')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Ringkasan operasional' })).not.toBeInTheDocument()
    expect(screen.queryByText(/Ringkasan operasional tidak dapat dimuat/)).not.toBeInTheDocument()
  })

  it('tolerates an API that does not know the OA3 sections yet (the keys are absent)', async () => {
    const old = { business_date: '2026-10-08', payables: null, payments: null, expenses: null, cash_bank: null, complete: true } as unknown as OperationalSummary
    await openHome(withSummary(old))
    expect(await screen.findByText('Jurnal terposting terbaru')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Ringkasan operasional' })).not.toBeInTheDocument()
  })

  it('says when the figures cover only part of the organisation', async () => {
    await openHome(withSummary(summary({ complete: false })))
    expect(await screen.findByText(/hanya mencakup data dalam cakupan akses Anda/)).toBeInTheDocument()
  })
})
