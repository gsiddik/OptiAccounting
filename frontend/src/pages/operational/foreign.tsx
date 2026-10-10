import { Link } from 'react-router-dom'
import { Banner, Field } from '../../components/ui'
import { ApiError } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate } from '../../lib/format'
import { describeError } from '../../lib/labels'
import { rateTypeLabels } from '../../lib/oa4Labels'
import { EXCHANGE_RATES_PATH, formatRate, fxDifferenceLabel, isForeignDoc, useRateLookup, type CurrencyChoice, type ForeignSupport, type FxKind } from './foreignSupport'
import { fieldMessage, localizeError } from './payables/messages'
import type { ForeignFields } from './payables/types'
import { Money } from './shared'
import { formatAmount } from '../../lib/accounting'

// Foreign-currency controls and facts of the invoice, payment and receipt screens (OA4 multi-currency). Presentation only: the types, request
// building and hooks are in foreignSupport.ts, and the server chooses the rate and computes every functional amount and exchange difference.

// ------------------------------------------------------------------------------------------------ editor controls

/** "Masukkan kurs" for a user who may manage rates; shown beside a missing-rate refusal. */
export function ExchangeRateLink({ error }: { error: unknown }) {
  const { can } = useCapabilities()
  if (!(error instanceof ApiError) || error.code !== 'EXCHANGE_RATE_NOT_FOUND' || !can('accounting.exchange_rate.manage')) return null
  return <p style={{ margin: 0 }}><Link to={EXCHANGE_RATES_PATH}>Masukkan kurs di halaman Kurs</Link></p>
}

/** The rate the server will use for the chosen currency and posting date (or why there is none). */
function RateInfo({ choice, date }: { choice: CurrencyChoice; date: string }) {
  const { can } = useCapabilities()
  const rate = useRateLookup(choice.currency, date, choice.exchange_rate_type)
  if (!rate.allowed) return null
  if (rate.error) {
    const missing = rate.error instanceof ApiError && rate.error.code === 'EXCHANGE_RATE_NOT_FOUND'
    return (
      <Banner tone={missing ? 'warn' : 'bad'}>
        {describeError(localizeError(rate.error))}
        {missing && can('accounting.exchange_rate.manage') && <> <Link to={EXCHANGE_RATES_PATH}>Masukkan kurs di halaman Kurs</Link></>}
      </Banner>
    )
  }
  if (!rate.data) return rate.loading ? <p className="muted" style={{ margin: 0 }}>Mencari kurs…</p> : null
  const r = rate.data
  return (
    <p style={{ margin: 0 }} role="status">
      Kurs yang akan dipakai server: 1 {r.currency} = <span className="money">{formatRate(r.rate)}</span> {r.functional_currency}, berlaku {formatDate(r.effective_date)}, jenis {rateTypeLabels[r.rate_type] ?? r.rate_type}{r.source ? ` (${r.source})` : ''}.
      <span className="muted"> Kurs dipilih untuk tanggal posting dan dipastikan ulang oleh server saat disimpan.</span>
    </p>
  )
}

/** The snapshot a saved draft already carries (shown while its currency is unchanged). */
function SavedSnapshot({ doc, functional }: { doc: ForeignFields & { currency?: string }; functional: string }) {
  return (
    <p className="muted" style={{ margin: 0 }}>
      Tersimpan pada draf: 1 {doc.currency} = <span className="money">{formatRate(doc.exchange_rate)}</span> {functional}
      {doc.exchange_rate_date ? `, tanggal kurs ${formatDate(doc.exchange_rate_date)}` : ''}{doc.exchange_rate_type ? `, jenis ${rateTypeLabels[doc.exchange_rate_type] ?? doc.exchange_rate_type}` : ''}.
      {doc.functional_total_amount != null && <> Total fungsional <span className="money">{formatAmount(doc.functional_total_amount)}</span>.</>}
      {doc.functional_amount != null && <> Jumlah fungsional <span className="money">{formatAmount(doc.functional_amount)}</span>.</>}
      {' '}Server menghitung ulang kurs saat draf disimpan.
    </p>
  )
}

/**
 * "Mata uang" and "Jenis kurs" for a document form, plus the rate the server will use. Render it inside a `form-grid`; it renders nothing when the
 * controls do not show. `doc` is the saved draft being edited (its snapshot is shown while the currency stays the same).
 */
export function CurrencyFields({ support, value, onChange, date, doc, error }: {
  support: ForeignSupport
  value: CurrencyChoice
  onChange: (patch: Partial<CurrencyChoice>) => void
  /** The posting date: the date the server resolves the rate for. */
  date: string
  doc?: (ForeignFields & { currency?: string }) | null
  error?: unknown
}) {
  if (!support.visible) return null
  const foreign = value.currency !== ''
  const known = support.currencies.some((c) => c.code === value.currency)
  const savedForeign = doc && isForeignDoc(doc) && doc.currency === value.currency ? doc : null
  return (
    <>
      <Field
        label="Mata uang"
        error={fieldMessage(error, 'currency')}
        hint={support.writable ? 'Mata uang fungsional dipakai bila tidak diubah.' : 'Modul multi mata uang dalam mode hanya baca: dokumen mata uang asing tidak dapat disimpan.'}
      >
        {(p) => (
          <select className="select" value={value.currency} disabled={!support.writable && !foreign} onChange={(e) => onChange({ currency: e.target.value, exchange_rate_type: '' })} {...p}>
            <option value="">{support.functional} · mata uang fungsional</option>
            {foreign && !known && <option value={value.currency}>{value.currency}</option>}
            {(support.writable ? support.currencies : support.currencies.filter((c) => c.code === value.currency)).map((c) => <option key={c.id} value={c.code}>{c.code} · {c.name}</option>)}
          </select>
        )}
      </Field>
      {foreign && (
        <Field label="Jenis kurs" error={fieldMessage(error, 'exchange_rate_type')} hint="Otomatis: server memilih kurs terbaru sampai tanggal posting (manual, spot, harian, lalu akhir bulan).">
          {(p) => (
            <select className="select" value={value.exchange_rate_type} onChange={(e) => onChange({ exchange_rate_type: e.target.value })} {...p}>
              <option value="">Otomatis</option>
              {Object.entries(rateTypeLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          )}
        </Field>
      )}
      {foreign && (
        <div className="full stack" style={{ gap: 6 }}>
          <RateInfo choice={value} date={date} />
          {savedForeign && <SavedSnapshot doc={savedForeign} functional={support.functional} />}
          <ExchangeRateLink error={error} />
        </div>
      )}
    </>
  )
}

// ------------------------------------------------------------------------------------------------ lists and details

/** An amount with its currency next to it when the document is foreign; a functional document keeps the plain amount. */
export function DocAmount({ value, doc, strong, functional }: { value: string | null | undefined; doc: ForeignFields & { currency?: string }; strong?: boolean; functional?: string | null }) {
  if (!isForeignDoc(doc)) return <Money value={value} strong={strong} />
  return (
    <>
      <Money value={value} strong={strong} /> <span className="muted mono">{doc.currency}</span>
      {functional != null && <div className="muted">Fungsional <Money value={functional} /></div>}
    </>
  )
}

/** The facts of a foreign document for a `<dl className="facts">`: rate, rate date and type, the amount in its currency (unless `skipTotal`, when the page already shows it) and the functional amount. Nothing for a functional document. */
export function ForeignFacts({ doc, total, kind, skipTotal }: { doc: ForeignFields & { currency: string }; total: { label: string; value: string }; kind?: FxKind; skipTotal?: boolean }) {
  if (!isForeignDoc(doc)) return null
  const functional = doc.functional_total_amount ?? doc.functional_amount
  const diff = doc.fx_difference
  return (
    <>
      <div><dt>Kurs</dt><dd><span className="money">{formatRate(doc.exchange_rate)}</span> <span className="muted">per 1 {doc.currency}</span></dd></div>
      <div><dt>Tanggal dan jenis kurs</dt><dd>{doc.exchange_rate_date ? formatDate(doc.exchange_rate_date) : '—'}{doc.exchange_rate_type ? ` · ${rateTypeLabels[doc.exchange_rate_type] ?? doc.exchange_rate_type}` : ''}</dd></div>
      {!skipTotal && <div><dt>{total.label} ({doc.currency})</dt><dd><Money value={total.value} /></dd></div>}
      <div><dt>{total.label} fungsional</dt><dd><Money value={functional} strong /></dd></div>
      {kind && diff != null && <div><dt>{fxDifferenceLabel(diff, kind)}</dt><dd><Money value={diff.replace(/^-/, '')} strong /></dd></div>}
    </>
  )
}
