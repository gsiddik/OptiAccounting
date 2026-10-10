import { Fragment, useId, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Banner, Button, Stat } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate, formatNumber } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, type OperationalSummary } from '../../lib/operational'
import { Money } from './shared'

const PATH = { invoices: '/app/akuntansi/faktur-vendor', payments: '/app/akuntansi/pembayaran-vendor', expenses: '/app/akuntansi/beban', cashBank: '/app/akuntansi/kas-bank' }

/** A stat card that opens the list behind the figure. */
function LinkedStat({ to, label, value, hint }: { to: string; label: string; value: ReactNode; hint?: ReactNode }) {
  return (
    <Link to={to} style={{ display: 'block', color: 'inherit', textDecoration: 'none' }}>
      <Stat label={label} value={value} hint={hint} />
    </Link>
  )
}

/** Counts that the API gives per document type: shown as one number (a sum of integers) with a link to each list. */
function CountStat({ label, status, sources }: { label: string; status: 'SUBMITTED' | 'APPROVED'; sources: { noun: string; count: number; path: string }[] }) {
  let total = 0
  for (const source of sources) total += source.count // integer COUNTS of documents, not money
  return (
    <Stat
      label={label}
      value={formatNumber(total)}
      hint={sources.map((source, i) => (
        <Fragment key={source.path}>
          {i > 0 && ' · '}
          <Link to={`${source.path}?status=${status}`}>{formatNumber(source.count)} {source.noun}</Link>
        </Fragment>
      ))}
    />
  )
}

/**
 * The OA2 counters on the accounting home: payables, approvals waiting, cash and bank balance. The API decides which sections the
 * user may see (a section is null otherwise) and computes every figure; this only lays them out. It never breaks the home: while
 * loading, or when no section is available (a tenant without the OA2 modules), it renders nothing, and a failure is a small notice.
 */
export function OperationalSummaryCard() {
  const heading = useId()
  const summary = useResource(async () => (await api.get<OperationalSummary>(`${API}/operational-summary`)).data, [])

  if (summary.loading && !summary.data) return null
  if (summary.error || !summary.data) {
    return (
      <Banner tone="warn">
        Ringkasan operasional tidak dapat dimuat sekarang. <Button size="sm" onClick={summary.reload}>Coba lagi</Button>
      </Banner>
    )
  }

  const s = summary.data
  const { payables, payments, expenses, cash_bank: cashBank } = s
  if (!payables && !payments && !expenses && !cashBank) return null

  const waiting = [
    payables && { noun: 'faktur', path: PATH.invoices, approval: payables.pending_approval, posting: payables.awaiting_posting },
    payments && { noun: 'pembayaran', path: PATH.payments, approval: payments.pending_approval, posting: payments.awaiting_posting },
    expenses && { noun: 'beban', path: PATH.expenses, approval: expenses.pending_approval, posting: expenses.awaiting_posting },
  ].filter((source): source is NonNullable<typeof source> => Boolean(source))

  return (
    <section className="stack" aria-labelledby={heading}>
      <div>
        <h2 id={heading}>Ringkasan operasional</h2>
        <p className="muted">Per tanggal bisnis {formatDate(s.business_date)}. Angka berasal dari dokumen yang sudah diposting dan buku besar.</p>
      </div>
      {!s.complete && <Banner tone="info">Angka hanya mencakup data dalam cakupan akses Anda (cabang atau unit bisnis tertentu), bukan seluruh organisasi.</Banner>}
      <div className="grid grid-3">
        {payables && (
          <>
            <LinkedStat to={`${PATH.invoices}?open=1`} label="Utang beredar" value={<Money value={payables.outstanding.amount} />} hint={`${formatNumber(payables.outstanding.invoices)} faktur`} />
            <LinkedStat to={`${PATH.invoices}?overdue=1`} label="Jatuh tempo lewat" value={<Money value={payables.overdue.amount} />} hint={`${formatNumber(payables.overdue.invoices)} faktur`} />
            <LinkedStat to={`${PATH.invoices}?due_within=${payables.due_soon.days}`} label={`Jatuh tempo ≤ ${payables.due_soon.days} hari`} value={<Money value={payables.due_soon.amount} />} hint={`${formatNumber(payables.due_soon.invoices)} faktur`} />
          </>
        )}
        {waiting.length > 0 && (
          <>
            <CountStat label="Menunggu persetujuan" status="SUBMITTED" sources={waiting.map((w) => ({ noun: w.noun, count: w.approval, path: w.path }))} />
            <CountStat label="Siap diposting" status="APPROVED" sources={waiting.map((w) => ({ noun: w.noun, count: w.posting, path: w.path }))} />
          </>
        )}
        {cashBank && (
          <LinkedStat
            to={PATH.cashBank}
            label="Saldo kas & bank"
            value={<Money value={cashBank.book_balance} />}
            hint={<>Kas <Money value={cashBank.cash} /> · Bank <Money value={cashBank.bank} /> · {formatNumber(cashBank.accounts)} akun per {formatDate(cashBank.as_of)}</>}
          />
        )}
      </div>
    </section>
  )
}
