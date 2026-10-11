import { useState } from 'react'
import { Field } from '../../components/ui'
import { formatAmount } from '../../lib/accounting'
import { useDebounced } from '../../lib/hooks'
import { errorText } from '../operational/payables/messages'
import { useCanPickInvoiceLine, useInvoiceLines, usePostedInvoices } from './data'

/**
 * Chooses the vendor invoice line whose cost is already in the ledger (capitalization mode "catat saja"). A user who may open vendor invoices searches
 * a posted invoice and picks one of its lines; any other user types the line id. The API checks that the line is posted, names an asset account and
 * has room left for the cost, so nothing here decides whether the line is acceptable.
 */
export function ApLinePicker({ value, onChange, error }: { value: string; onChange: (lineId: string) => void; error?: string }) {
  const canPick = useCanPickInvoiceLine()
  const [search, setSearch] = useState('')
  const q = useDebounced(search)
  const [invoiceId, setInvoiceId] = useState('')
  const invoices = usePostedInvoices(q, canPick)
  const lines = useInvoiceLines(invoiceId)

  if (!canPick) {
    return (
      <Field label="ID baris faktur vendor" error={error} hint="Baris faktur vendor terposting yang biayanya sudah dijurnal. Anda tidak punya izin membuka faktur vendor, jadi isi ID barisnya." full>
        {(p) => <input className="input mono" autoComplete="off" value={value} onChange={(e) => onChange(e.target.value)} {...p} />}
      </Field>
    )
  }

  const saved = value !== '' && !lines.lines.some((l) => l.id === value)
  return (
    <>
      <Field label="Cari faktur vendor terposting" hint="Nomor faktur, nomor faktur vendor, atau nama vendor.">
        {(p) => <input className="input" type="search" autoComplete="off" value={search} onChange={(e) => setSearch(e.target.value)} {...p} />}
      </Field>
      <Field label="Faktur vendor" hint={invoices.error ? errorText(invoices.error) : undefined}>
        {(p) => (
          <select className="select" value={invoiceId} onChange={(e) => { setInvoiceId(e.target.value); onChange('') }} {...p}>
            <option value="">Pilih faktur…</option>
            {invoices.invoices.map((i) => (
              <option key={i.id} value={i.id}>{i.document_number ?? i.vendor_invoice_number} · {i.vendor?.name ?? 'Vendor'} · {formatAmount(i.total_amount)}</option>
            ))}
          </select>
        )}
      </Field>
      <Field
        label="Baris faktur"
        error={error}
        hint={invoiceId && !lines.loading && lines.lines.length === 0 ? 'Faktur ini tidak punya baris yang memakai akun.' : 'Hanya baris yang memakai akun aset yang dapat dicatat sebagai aset; server memeriksanya.'}
        full
      >
        {(p) => (
          <select className="select" value={lines.lines.some((l) => l.id === value) ? value : ''} disabled={!invoiceId} onChange={(e) => onChange(e.target.value)} {...p}>
            <option value="">Pilih baris…</option>
            {lines.lines.map((l) => (
              <option key={l.id} value={l.id}>#{l.line_number} · {l.description} · {formatAmount(l.amount)}{l.account ? ` · ${l.account.code}` : ''}</option>
            ))}
          </select>
        )}
      </Field>
      {saved && <p className="field full muted">Baris faktur yang sudah tersimpan pada draf ini tetap dipakai sampai Anda memilih baris lain.</p>}
    </>
  )
}
