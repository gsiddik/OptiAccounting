import { useState, type FormEvent } from 'react'
import { Banner, Button, Card, Field } from '../../components/ui'
import { formatAmount } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { formatDate, todayIn } from '../../lib/format'
import { useAction } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { API } from '../../lib/operational'
import { useCurrencyOptions } from './data'
import { rateTypeLabels } from './labels'
import { RATE_TYPES, type RateLookup as Lookup } from './types'

/** "Cari kurs": asks the server which rate a document of that currency would use on a date (same resolver as the documents) and shows its answer. */
export function RateLookup() {
  const { tenant } = useCapabilities()
  const options = useCurrencyOptions()
  const search = useAction()
  const [f, setF] = useState({ currency: '', date: tenant?.business_date ?? todayIn(), rate_type: '' })
  const [problem, setProblem] = useState<string | null>(null)
  const [result, setResult] = useState<Lookup | null>(null)
  const change = (patch: Partial<typeof f>) => {
    setF((s) => ({ ...s, ...patch }))
    setResult(null)
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    setProblem(null)
    setResult(null)
    if (!/^[A-Z]{3}$/.test(f.currency)) return setProblem('Pilih mata uang, atau isi kode ISO tiga huruf.')
    if (!f.date) return setProblem('Pilih tanggal dokumen.')
    const r = await search.run(async () => (await api.get<Lookup>(`${API}/exchange-rates/lookup`, { params: { currency: f.currency, date: f.date, ...(f.rate_type && { rate_type: f.rate_type }) } })).data)
    if (r.ok) setResult(r.value)
  }

  return (
    <Card title="Cari kurs">
      <form className="stack" onSubmit={(e) => void submit(e)} noValidate>
        <p className="muted">Menampilkan kurs yang akan dipakai server untuk dokumen bermata uang itu pada tanggal tertentu: kurs aktif terbaru pada atau sebelum tanggal tersebut, dan tidak terlalu lama.</p>
        <div className="form-grid">
          <Field label="Mata uang dokumen" error={fieldError(search.error, 'currency') ?? problem ?? undefined}>
            {(p) =>
              options.available ? (
                <select className="select" value={f.currency} onChange={(e) => change({ currency: e.target.value })} {...p}>
                  <option value="">Pilih mata uang…</option>
                  {options.currencies.map((c) => <option key={c.id} value={c.code}>{c.code} · {c.name}</option>)}
                </select>
              ) : (
                <input className="input mono" maxLength={3} value={f.currency} onChange={(e) => change({ currency: e.target.value.toUpperCase() })} {...p} />
              )
            }
          </Field>
          <Field label="Tanggal dokumen" error={fieldError(search.error, 'date')}>
            {(p) => <input className="input" type="date" value={f.date} onChange={(e) => change({ date: e.target.value })} {...p} />}
          </Field>
          <Field label="Jenis kurs (opsional)" hint="Biarkan otomatis agar server memilih menurut urutan bawaan.">
            {(p) => (
              <select className="select" value={f.rate_type} onChange={(e) => change({ rate_type: e.target.value })} {...p}>
                <option value="">Otomatis</option>
                {RATE_TYPES.map((t) => <option key={t} value={t}>{rateTypeLabels[t]}</option>)}
              </select>
            )}
          </Field>
        </div>
        <div className="actions"><Button type="submit" loading={search.busy}>Cari kurs</Button></div>
        {search.error != null && <Banner tone="bad">{describeError(search.error)}</Banner>}
      </form>

      {result && (
        <div className="stack" style={{ marginTop: 16 }} aria-live="polite">
          <h3>Kurs yang akan dipakai</h3>
          <dl className="facts">
            <div><dt>Kurs</dt><dd className="money">1 {result.currency} = {formatAmount(result.rate)} {result.functional_currency}</dd></div>
            <div><dt>Berlaku sejak</dt><dd>{result.effective_date ? formatDate(result.effective_date) : '—'}</dd></div>
            <div><dt>Jenis</dt><dd>{result.rate_type ? (rateTypeLabels[result.rate_type] ?? result.rate_type) : '—'}</dd></div>
            <div><dt>Sumber</dt><dd>{result.source ?? '—'}</dd></div>
          </dl>
          {result.rate_id === null && <p className="muted">Ini mata uang fungsional: tidak ada kurs di daftar, nilainya 1.</p>}
        </div>
      )}
    </Card>
  )
}
