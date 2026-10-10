import { DataTable } from '../../../components/DataTable'
import { amountToApi, formatAmount } from '../../../lib/accounting'
import { formatDate } from '../../../lib/format'
import { Money } from '../shared'
import { exceedsOutstanding, type AllocationInputs, type AllocationPreview } from './paymentForm'

/** One invoice the payment may settle. `outstanding_amount` is null for an invoice the draft still names but that is no longer open. */
export type AllocationRow = { id: string; document_number: string | null; vendor_invoice_number: string; due_date: string; outstanding_amount: string | null }

/** The open invoices of the chosen vendor with an allocation input each. The amounts typed here are sent as they are; the server validates them. */
export function AllocationTable({ rows, values, onChange, errorFor }: { rows: AllocationRow[]; values: AllocationInputs; onChange: (invoiceId: string, text: string) => void; errorFor?: (invoiceId: string) => string | undefined }) {
  return (
    <DataTable
      caption="Faktur terbuka dan alokasi pembayaran"
      rows={rows}
      rowKey={(r) => r.id}
      scroll
      columns={[
        { header: 'Faktur', primary: true, cell: (r) => <span className="mono">{r.document_number ?? 'Faktur'}</span> },
        { header: 'No. faktur vendor', cell: (r) => <span className="mono">{r.vendor_invoice_number}</span> },
        { header: 'Jatuh tempo', cell: (r) => formatDate(r.due_date) },
        { header: 'Saldo terutang', align: 'right', cell: (r) => (r.outstanding_amount === null ? <span className="muted">Tidak lagi terbuka</span> : <Money value={r.outstanding_amount} />) },
        {
          header: 'Alokasi',
          align: 'right',
          cell: (r) => {
            const text = values[r.id] ?? ''
            const over = exceedsOutstanding(text, r.outstanding_amount)
            const message = errorFor?.(r.id) ?? (over ? 'Melebihi saldo faktur' : r.outstanding_amount === null && text.trim() !== '' ? 'Faktur ini tidak lagi dapat dilunasi' : undefined)
            return (
              <div>
                <input
                  className="input amount"
                  inputMode="decimal"
                  autoComplete="off"
                  placeholder="0"
                  aria-label={`Alokasi ${r.document_number ?? r.vendor_invoice_number}`}
                  aria-invalid={message ? true : undefined}
                  style={{ minWidth: 140 }}
                  value={text}
                  onChange={(e) => onChange(r.id, e.target.value)}
                />
                {message && <div className="error" style={{ fontSize: '0.82rem', color: 'var(--bad-text)' }}>{message}</div>}
              </div>
            )
          },
        },
      ]}
    />
  )
}

/** Payment amount, allocated and remaining, in exact arithmetic, with a plain-words verdict. A preview: the server judges the allocation. */
export function AllocationSummary({ preview }: { preview: AllocationPreview }) {
  const show = (units: bigint) => formatAmount(amountToApi(units < 0n ? -units : units))
  const verdict = preview.amount === null
    ? { cls: 'balance-bad', text: 'Jumlah pembayaran bukan angka yang valid.' }
    : preview.over
      ? { cls: 'balance-bad', text: `Alokasi melebihi jumlah pembayaran sebesar ${show(preview.remaining)}.` }
      : preview.under
        ? { cls: 'balance-bad', text: `Belum dialokasikan ${show(preview.remaining)}. Draf tetap dapat disimpan, tetapi pembayaran harus teralokasi penuh sebelum diajukan atau diposting.` }
        : preview.balanced
          ? { cls: 'balance-ok', text: 'Teralokasi penuh.' }
          : { cls: 'muted', text: 'Belum ada jumlah.' }
  return (
    <div role="status" aria-live="polite" className="stack" style={{ gap: 6 }}>
      <div className="totals">
        <span>Jumlah pembayaran <strong className="money">{preview.amount === null ? '—' : formatAmount(amountToApi(preview.amount))}</strong></span>
        <span>Teralokasi <strong className="money">{formatAmount(amountToApi(preview.allocated))}</strong></span>
        <span>Sisa <strong className="money">{preview.amount === null ? '—' : `${preview.remaining < 0n ? '-' : ''}${show(preview.remaining)}`}</strong></span>
      </div>
      <span className={verdict.cls}>{verdict.text}</span>
    </div>
  )
}
