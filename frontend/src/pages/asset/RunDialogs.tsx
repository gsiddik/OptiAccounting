import { useState } from 'react'
import { FormModal } from '../../components/Modal'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { API } from '../../lib/operational'
import { useCalendar } from '../accounting/data'
import { fieldMessage, useAct } from '../operational/payables/messages'
import type { DepreciationRun } from './types'

/**
 * Calculate a depreciation run for one period. The server picks the eligible assets (active, with scheduled months up to the end of the period, inside the
 * user's data scope), reserves those months in a draft and returns the amounts. Only an open or soft-closed period can be calculated.
 */
export function CalculateRunDialog({ onClose, onDone }: { onClose: () => void; onDone: (run: DepreciationRun) => void }) {
  const { busy, error, run } = useAct()
  const calendar = useCalendar()
  const [f, setF] = useState({ accounting_period_id: '', posting_date: '', description: '', reference: '' })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const periods = calendar.periods.filter((p) => p.status === 'OPEN' || p.status === 'SOFT_CLOSED')
  const chosen = periods.find((p) => p.id === f.accounting_period_id)

  async function submit() {
    const body = { accounting_period_id: f.accounting_period_id, posting_date: f.posting_date || null, description: f.description.trim() || null, reference: f.reference.trim() || null }
    const r = await run(async () => (await api.post<DepreciationRun>(`${API}/depreciation-runs`, body)).data)
    if (r.ok) onDone(r.value)
  }

  return (
    <FormModal title="Hitung penyusutan" submitLabel="Hitung penyusutan" busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <p>
        Server menghitung penyusutan semua aset aktif yang bulannya sudah jatuh tempo sampai akhir periode, termasuk bulan yang terlewat sebelumnya. Hasilnya berupa draf untuk diperiksa;
        bulan-bulannya dipesan sehingga tidak bisa dihitung dua kali, dan baru masuk buku besar saat draf diposting.
      </p>
      <div className="form-grid">
        <Field label="Periode" error={fieldMessage(error, 'accounting_period_id')} hint={calendar.error ? 'Daftar periode tidak dapat dimuat (butuh izin melihat periode akuntansi).' : 'Periode yang terbuka atau ditutup sementara.'}>
          {(p) => (
            <select className="select" required value={f.accounting_period_id} onChange={set('accounting_period_id')} {...p}>
              <option value="">Pilih periode…</option>
              {periods.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
            </select>
          )}
        </Field>
        <Field label="Tanggal posting" error={fieldMessage(error, 'posting_date')} hint={chosen ? `Kosong berarti akhir periode (${chosen.end_date}). Harus berada dalam periode.` : 'Kosong berarti akhir periode. Harus berada dalam periode.'}>
          {(p) => <input className="input" type="date" min={chosen?.start_date} max={chosen?.end_date} value={f.posting_date} onChange={set('posting_date')} {...p} />}
        </Field>
        <Field label="Deskripsi" error={fieldMessage(error, 'description')} full>{(p) => <input className="input" maxLength={500} value={f.description} onChange={set('description')} {...p} />}</Field>
        <Field label="Referensi" error={fieldMessage(error, 'reference')} hint="Opsional.">{(p) => <input className="input" maxLength={100} value={f.reference} onChange={set('reference')} {...p} />}</Field>
      </div>
    </FormModal>
  )
}
