import type { FiscalYear, Period } from '../../lib/accounting'
import { journalTypeLabels } from '../../lib/accountingLabels'
import { useForeignSupport } from '../operational/foreignSupport'
import type { Range } from './data'
import { ACCOUNT_TYPE_OPTIONS, SOURCE_TYPE_SUGGESTIONS } from './ledger-labels'
import type { AccountFilter } from './ledger-params'
import { RangeFilter } from './shared'

// Filter controls added by OA5 to the trial balance, the general ledger and the account balance report. They only collect input; the API validates and applies it.

/** Fiscal year, period, or plain dates. A period and a fiscal year exclude each other, so picking one clears the other. */
export function LedgerWindow({ range, year, years, periods, defaultRange, onChange }: {
  range: Range
  year: string
  years: FiscalYear[]
  periods: Period[]
  defaultRange: Range
  onChange: (next: { range: Range; year: string }) => void
}) {
  return (
    <>
      {years.length > 0 && (
        <select className="select" aria-label="Tahun fiskal" value={year} onChange={(e) => onChange(e.target.value ? { year: e.target.value, range: { period_id: '', from: '', to: '' } } : { year: '', range: defaultRange })}>
          <option value="">Tanpa tahun fiskal</option>
          {years.map((y) => <option key={y.id} value={y.id}>{y.code} · {y.name}</option>)}
        </select>
      )}
      <RangeFilter value={range} periods={periods} onChange={(r) => onChange({ range: r, year: r.period_id ? '' : year })} />
    </>
  )
}

/** The transaction currency of the lines; it exists only for an organisation with the multi-currency module and the right to read currencies. */
function CurrencyFilter({ value, onChange }: { value: string; onChange: (currency: string) => void }) {
  const support = useForeignSupport()
  if (!support.visible) return null
  return (
    <select className="select" aria-label="Mata uang transaksi" value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">Semua mata uang</option>
      <option value={support.functional}>{support.functional} · mata uang fungsional</option>
      {support.currencies.filter((c) => c.code !== support.functional).map((c) => <option key={c.id} value={c.code}>{c.code} · {c.name}</option>)}
    </select>
  )
}

/** Account type, a code range and the transaction currency. Together they make the report a partial view, which the API says with `complete: false`. */
export function AccountFilters({ value, onChange }: { value: AccountFilter; onChange: (patch: Partial<AccountFilter>) => void }) {
  return (
    <>
      <select className="select" aria-label="Tipe akun" value={value.account_type} onChange={(e) => onChange({ account_type: e.target.value })}>
        <option value="">Semua tipe akun</option>
        {ACCOUNT_TYPE_OPTIONS.map(([code, label]) => <option key={code} value={code}>{label}</option>)}
      </select>
      <label className="inline-field">Kode akun dari <input className="input mono" aria-label="Kode akun dari" maxLength={30} value={value.account_code_from} onChange={(e) => onChange({ account_code_from: e.target.value })} /></label>
      <label className="inline-field">sampai <input className="input mono" aria-label="Kode akun sampai" maxLength={30} value={value.account_code_to} onChange={(e) => onChange({ account_code_to: e.target.value })} /></label>
      <CurrencyFilter value={value.currency} onChange={(currency) => onChange({ currency })} />
    </>
  )
}

/** General ledger only: where the journal came from (any source text) and its type. */
export function SourceFilters({ source, journalType, onChange }: { source: string; journalType: string; onChange: (patch: { source_type?: string; journal_type?: string }) => void }) {
  return (
    <>
      <input className="input mono" list="ledger-source-types" aria-label="Sumber jurnal" placeholder="Sumber, mis. AP_INVOICE" maxLength={40} value={source} onChange={(e) => onChange({ source_type: e.target.value })} />
      <datalist id="ledger-source-types">{SOURCE_TYPE_SUGGESTIONS.map((s) => <option key={s} value={s} />)}</datalist>
      <select className="select" aria-label="Jenis jurnal" value={journalType} onChange={(e) => onChange({ journal_type: e.target.value })}>
        <option value="">Semua jenis jurnal</option>
        {Object.entries(journalTypeLabels).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
      </select>
    </>
  )
}
