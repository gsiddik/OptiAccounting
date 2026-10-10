import { Field } from '../../components/ui'
import { describeError } from '../../lib/labels'
import { localizeError } from './payables/messages'
import { Money } from './shared'
import { formatPercent, useTaxPreview, type TaxCodeOption, type TaxFact } from './taxSupport'

// Tax-code controls and facts of the document screens (OA4 tax): the select, the server preview line and the detail-page cells. Nothing here
// calculates tax; the types and hooks are in taxSupport.ts.

/** "Kode pajak" select. The saved code stays selectable even if it was deactivated since. */
export function TaxCodeField({ label, value, codes, onChange, error, hint }: { label: string; value: string; codes: TaxCodeOption[]; onChange: (id: string) => void; error?: string; hint?: string }) {
  return (
    <Field label={label} error={error} hint={hint ?? 'Opsional. Server menghitung pajak dengan tarif pada tanggal dokumen.'}>
      {(p) => (
        <select className="select" value={value} onChange={(e) => onChange(e.target.value)} {...p}>
          <option value="">Tanpa kode pajak</option>
          {value && !codes.some((c) => c.id === value) && <option value={value}>Kode pajak tersimpan</option>}
          {codes.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}{c.calculation_method === 'INCLUSIVE' ? ' (inklusif)' : ''}</option>)}
        </select>
      )}
    </Field>
  )
}

/** Under a taxed line or expense: the server's base, tax and total for the amount typed, labelled as a preview. Renders nothing without a tax code. */
export function TaxPreviewNote({ codeId, amount, date, foreign }: { codeId: string; amount: string; date: string; foreign?: boolean }) {
  const preview = useTaxPreview(codeId, amount, date, !foreign)
  if (!codeId) return null
  if (foreign) return <p className="muted full" style={{ margin: 0 }}>Pajak dihitung server saat disimpan, dengan tarif pada tanggal dokumen dan pembulatan sesuai mata uang dokumen.</p>
  if (preview.error) return <p className="muted full" style={{ margin: 0 }} role="status">Pratinjau pajak belum tersedia: {describeError(localizeError(preview.error))}</p>
  if (!preview.data) return <p className="muted full" style={{ margin: 0 }}>{amount === '' ? 'Isi jumlah untuk melihat pratinjau pajak dari server.' : 'Menghitung pajak…'}</p>
  const p = preview.data
  return (
    <p className="full" style={{ margin: 0 }} role="status">
      <strong>Pratinjau dari server</strong> (bukan angka tersimpan) · {p.tax_code} {formatPercent(p.rate)}% · dasar <Money value={p.base_amount} /> · pajak <Money value={p.tax_amount} /> · total <Money value={p.total_amount} strong />
    </p>
  )
}

/** "PPN11 · 11%" for a line's tax code; "Berpajak" when the code could not be read. */
export function TaxCodeCell({ taxCodeId, fact }: { taxCodeId: string | null | undefined; fact: TaxFact | undefined }) {
  if (!taxCodeId) return <span className="muted">—</span>
  if (!fact) return <span className="muted">Berkode pajak</span>
  return <>{fact.code}{fact.rate !== null && <span className="muted"> · {formatPercent(fact.rate)}%</span>}</>
}

/** The tax amount of a line, as the server calculated it; "—" when there is no tax code or no figure. */
export function TaxAmountCell({ taxCodeId, fact }: { taxCodeId: string | null | undefined; fact: TaxFact | undefined }) {
  if (!taxCodeId || !fact || fact.tax_amount === null) return <span className="muted">—</span>
  return <Money value={fact.tax_amount} />
}
