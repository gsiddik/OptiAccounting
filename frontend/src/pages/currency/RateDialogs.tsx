import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, todayIn } from '../../lib/format'
import { useAction } from '../../lib/hooks'
import { API } from '../../lib/operational'
import { useCurrencyOptions, useFunctionalCurrency } from './data'
import { fieldMessage } from './errors'
import { decimalText, rateTypeLabels } from './labels'
import { RATE_TYPES, type ExchangeRate, type RateType } from './types'

/**
 * Enter a rate. A rate is a fact: once saved its currency, date, type and value never change (a wrong one is withdrawn and another entered).
 * The server accepts one active rate per currency, type and date.
 */
export function RateForm({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const { tenant } = useCapabilities()
  const options = useCurrencyOptions('ACTIVE')
  const functional = useFunctionalCurrency()
  const [problem, setProblem] = useState<string | null>(null)
  const [f, setF] = useState({ from_currency: '', rate_type: 'MANUAL' as RateType, effective_date: tenant?.business_date ?? todayIn(), rate: '', source: '', notes: '' })
  const set = (k: 'rate' | 'effective_date' | 'source' | 'notes') => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const target = functional.profile?.functional_currency

  async function submit() {
    setProblem(null)
    if (!/^[A-Z]{3}$/.test(f.from_currency)) return setProblem('Pilih mata uang asing, atau isi kode ISO tiga huruf.')
    if (!f.effective_date) return setProblem('Tanggal berlaku wajib diisi.')
    if (!decimalText(f.rate)) return setProblem('Kurs wajib diisi.')
    const body = {
      from_currency: f.from_currency,
      rate: decimalText(f.rate),
      effective_date: f.effective_date,
      rate_type: f.rate_type,
      source: f.source.trim() || null,
      notes: f.notes.trim() || null,
    }
    const r = await run(() => api.post(`${API}/exchange-rates`, body))
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Kurs baru" busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field
          label="Mata uang"
          error={fieldMessage(error, 'from_currency')}
          hint={options.available && options.currencies.length === 0 ? 'Belum ada mata uang asing aktif. Tambahkan di halaman Mata uang.' : 'Mata uang asing yang dikurskan ke mata uang fungsional.'}
        >
          {(p) =>
            options.available ? (
              <select className="select" required value={f.from_currency} onChange={(e) => setF((s) => ({ ...s, from_currency: e.target.value }))} {...p}>
                <option value="">Pilih mata uang…</option>
                {options.currencies.map((c) => <option key={c.id} value={c.code}>{c.code} · {c.name}</option>)}
              </select>
            ) : (
              <input className="input mono" required maxLength={3} value={f.from_currency} onChange={(e) => setF((s) => ({ ...s, from_currency: e.target.value.toUpperCase() }))} {...p} />
            )
          }
        </Field>
        <Field label="Jenis kurs" error={fieldMessage(error, 'rate_type')} hint="Jenis menentukan kurs mana yang dipilih bila satu tanggal punya beberapa kurs.">
          {(p) => (
            <select className="select" value={f.rate_type} onChange={(e) => setF((s) => ({ ...s, rate_type: e.target.value as RateType }))} {...p}>
              {RATE_TYPES.map((t) => <option key={t} value={t}>{rateTypeLabels[t]}</option>)}
            </select>
          )}
        </Field>
        <Field label="Berlaku mulai" error={fieldMessage(error, 'effective_date')} hint="Kurs dipakai dokumen pada tanggal ini dan sesudahnya, sampai ada kurs yang lebih baru.">
          {(p) => <input className="input" type="date" required value={f.effective_date} onChange={set('effective_date')} {...p} />}
        </Field>
        <Field
          label="Kurs"
          error={fieldMessage(error, 'rate')}
          hint={`${f.from_currency ? `1 ${f.from_currency}` : 'Satu satuan mata uang asing'} = berapa ${target ?? 'mata uang fungsional'}. Bilangan positif, paling banyak 10 desimal. Contoh: 16250,5`}
        >
          {(p) => <input className="input amount" inputMode="decimal" required value={f.rate} onChange={set('rate')} {...p} />}
        </Field>
        <Field label="Sumber" error={fieldMessage(error, 'source')} hint="Opsional, misalnya Bank Indonesia atau bank Anda.">
          {(p) => <input className="input" maxLength={100} value={f.source} onChange={set('source')} {...p} />}
        </Field>
        <Field label="Catatan" error={fieldMessage(error, 'notes')}>
          {(p) => <input className="input" maxLength={255} value={f.notes} onChange={set('notes')} {...p} />}
        </Field>
        {problem && <p className="field full"><span className="error" role="alert">{problem}</span></p>}
        {options.available && options.currencies.length === 0 && <p className="field full muted"><Link to="/app/akuntansi/mata-uang">Buka halaman Mata uang</Link></p>}
      </div>
    </FormModal>
  )
}

const rateName = (r: ExchangeRate) => `${r.from_currency} ${rateTypeLabels[r.rate_type] ?? r.rate_type} ${formatDate(r.effective_date)}`

/** Withdraw (deactivate) or re-activate a rate. A withdrawn rate is no longer chosen for new documents; documents that cited it keep it. */
export function RateStatusDialog({ rate, onClose, onDone }: { rate: ExchangeRate; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const activate = rate.status !== 'ACTIVE'
  return (
    <ConfirmDialog
      title={activate ? 'Aktifkan kurs' : 'Tarik kurs'}
      confirmLabel={activate ? 'Aktifkan' : 'Tarik kurs'}
      danger={!activate}
      busy={busy}
      error={error}
      message={activate ? `Aktifkan kembali kurs ${rateName(rate)}?` : `Kurs ${rateName(rate)} ditarik: tidak dipilih lagi untuk dokumen baru. Dokumen yang sudah memakainya tidak berubah. Kurs tidak diubah; masukkan kurs yang benar sebagai kurs baru.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`${API}/exchange-rates/${rate.id}/${activate ? 'activate' : 'deactivate'}`))
        if (r.ok) onDone()
      }}
    />
  )
}

export function RateDeleteDialog({ rate, onClose, onDone }: { rate: ExchangeRate; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  return (
    <ConfirmDialog
      title="Hapus kurs"
      confirmLabel="Hapus"
      danger
      busy={busy}
      error={error}
      message={`Hapus kurs ${rateName(rate)}? Hanya kurs yang belum dikutip dokumen mana pun yang dapat dihapus; yang sudah dipakai harus ditarik.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`${API}/exchange-rates/${rate.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
