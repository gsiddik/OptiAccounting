import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { useAction } from '../../lib/hooks'
import { API } from '../../lib/operational'
import { fieldMessage } from './errors'
import type { Currency } from './types'

const PLACES = [0, 1, 2, 3, 4]

/**
 * Add or edit a foreign currency. The ISO code is its identity and is fixed once saved; the precision is fixed once a rate or document uses
 * the currency (`in_use`, known from the list). The functional currency is never added here.
 */
export function CurrencyForm({ currency, onClose, onDone }: { currency: Currency | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ code: currency?.code ?? '', name: currency?.name ?? '', symbol: currency?.symbol ?? '', decimal_places: String(currency?.decimal_places ?? 2) })
  const [problem, setProblem] = useState<string | null>(null)
  const locked = !!currency && currency.in_use === true

  async function submit() {
    setProblem(null)
    if (!currency && !/^[A-Z]{3}$/.test(f.code)) return setProblem('Kode mata uang adalah tiga huruf ISO 4217, misalnya USD.')
    if (!f.name.trim()) return setProblem('Nama wajib diisi.')
    const body = { name: f.name.trim(), symbol: f.symbol.trim() || null, decimal_places: Number(f.decimal_places) }
    const r = await run(() => (currency ? api.patch(`${API}/currencies/${currency.id}`, body) : api.post(`${API}/currencies`, { ...body, code: f.code })))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={currency ? `Ubah mata uang ${currency.code}` : 'Mata uang asing baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Kode ISO" error={fieldMessage(error, 'code')} hint={currency ? 'Kode tidak dapat diubah.' : 'Tiga huruf ISO 4217, misalnya USD atau EUR.'}>
          {(p) => <input className="input mono" required maxLength={3} disabled={!!currency} value={f.code} onChange={(e) => setF((s) => ({ ...s, code: e.target.value.toUpperCase() }))} {...p} />}
        </Field>
        <Field label="Nama" error={fieldMessage(error, 'name')}>
          {(p) => <input className="input" required maxLength={100} value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} {...p} />}
        </Field>
        <Field label="Simbol" error={fieldMessage(error, 'symbol')} hint="Hanya tampilan, misalnya $. Mata uang dikenali dari kodenya.">
          {(p) => <input className="input" maxLength={10} value={f.symbol} onChange={(e) => setF((s) => ({ ...s, symbol: e.target.value }))} {...p} />}
        </Field>
        <Field label="Jumlah desimal" error={fieldMessage(error, 'decimal_places')} hint={locked ? 'Tidak dapat diubah karena mata uang sudah dipakai.' : 'Presisi jumlah dokumen dalam mata uang ini, 0 sampai 4.'}>
          {(p) => (
            <select className="select" disabled={locked} value={f.decimal_places} onChange={(e) => setF((s) => ({ ...s, decimal_places: e.target.value }))} {...p}>
              {PLACES.map((n) => <option key={n} value={n}>{n}</option>)}
            </select>
          )}
        </Field>
        {problem && <p className="field full"><span className="error" role="alert">{problem}</span></p>}
      </div>
    </FormModal>
  )
}

export function CurrencyStatusDialog({ currency, onClose, onDone }: { currency: Currency; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const activate = currency.status !== 'ACTIVE'
  return (
    <ConfirmDialog
      title={activate ? 'Aktifkan mata uang' : 'Nonaktifkan mata uang'}
      confirmLabel={activate ? 'Aktifkan' : 'Nonaktifkan'}
      danger={!activate}
      busy={busy}
      error={error}
      message={activate ? `Aktifkan kembali ${currency.code} · ${currency.name}?` : `${currency.code} · ${currency.name} tidak dapat dipakai pada dokumen baru dan tidak dapat diberi kurs baru. Dokumen dan kurs yang sudah ada tetap utuh.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`${API}/currencies/${currency.id}/${activate ? 'activate' : 'deactivate'}`))
        if (r.ok) onDone()
      }}
    />
  )
}

export function CurrencyDeleteDialog({ currency, onClose, onDone }: { currency: Currency; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  return (
    <ConfirmDialog
      title="Hapus mata uang"
      confirmLabel="Hapus"
      danger
      busy={busy}
      error={error}
      message={`Hapus ${currency.code} · ${currency.name}? Hanya mata uang yang belum pernah dipakai kurs atau dokumen yang dapat dihapus; yang sudah dipakai harus dinonaktifkan.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`${API}/currencies/${currency.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
