import { useMemo, useState } from 'react'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { normalizeAmountInput } from '../../lib/accounting'
import { API } from '../../lib/operational'
import { accountLabel, useAccounts, useDimensions } from '../accounting/data'
import { fieldMessage, useAct } from '../operational/payables/messages'
import { DimensionFields } from '../operational/shared'
import type { BudgetLine, BudgetVersion, LinePayload, PeriodRef } from './types'

const toPayload = (l: BudgetLine): LinePayload => ({
  account_id: l.account_id, accounting_period_id: l.accounting_period_id, amount: l.amount,
  branch_id: l.branch_id, business_unit_id: l.business_unit_id, cost_center_id: l.cost_center_id, description: l.description,
})

/**
 * Add budget for one account over one or several periods: an amount per period (empty = no line for that period). One filled period
 * is a single new line; several replace the lines of the version in one step so either all of them are saved or none (the API refuses
 * the replace when the user's data scope does not cover the whole version).
 */
export function LineAddDialog({ version, periods, onClose, onDone }: { version: BudgetVersion; periods: Pick<PeriodRef, 'id' | 'code' | 'name'>[]; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const { accounts } = useAccounts()
  const { catalog } = useDimensions()
  const [account, setAccount] = useState('')
  const [dims, setDims] = useState({ branch_id: '', business_unit_id: '', cost_center_id: '' })
  const [description, setDescription] = useState('')
  const [amounts, setAmounts] = useState<Record<string, string>>({})
  const [fill, setFill] = useState('')
  const options = useMemo(() => accounts.filter((a) => a.status === 'ACTIVE'), [accounts])

  // Amounts are kept as typed and normalised only when sent (the server parses and validates them again).
  const filled = periods.filter((p) => normalizeAmountInput(amounts[p.id] ?? '') !== '')

  async function submit() {
    const fresh: LinePayload[] = filled.map((p) => ({
      account_id: account, accounting_period_id: p.id, amount: normalizeAmountInput(amounts[p.id]), description: description.trim() || null,
      branch_id: dims.branch_id || null, business_unit_id: dims.business_unit_id || null, cost_center_id: dims.cost_center_id || null,
    }))
    if (fresh.length === 0) return
    const r = await run(async () => {
      if (fresh.length === 1) return (await api.post(`${API}/budget-versions/${version.id}/lines`, fresh[0])).data
      return (await api.put(`${API}/budget-versions/${version.id}/lines`, { lines: [...(version.lines ?? []).map(toPayload), ...fresh] })).data
    })
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Tambah baris anggaran" submitLabel={filled.length > 1 ? `Simpan ${filled.length} baris` : 'Simpan baris'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Akun" error={fieldMessage(error, 'account_id')} hint="Akun induk mencakup seluruh akun di bawahnya.">
          {(p) => (
            <select className="select" required value={account} onChange={(e) => setAccount(e.target.value)} {...p}>
              <option value="">Pilih akun…</option>
              {options.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
            </select>
          )}
        </Field>
        <DimensionFields catalog={catalog} value={dims} onChange={(patch) => setDims((s) => ({ ...s, ...patch }))} error={error} />
        <Field label="Keterangan" error={fieldMessage(error, 'description')} full>
          {(p) => <input className="input" maxLength={255} value={description} onChange={(e) => setDescription(e.target.value)} {...p} />}
        </Field>
        <Field label="Isi semua periode" hint="Mengisi setiap periode dengan jumlah yang sama; ubah per periode di bawah." full>
          {(p) => (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <input className="input money" inputMode="decimal" placeholder="0" value={fill} onChange={(e) => setFill(e.target.value)} {...p} />
              <button type="button" className="btn btn-sm" onClick={() => setAmounts(Object.fromEntries(periods.map((q) => [q.id, fill])))} disabled={normalizeAmountInput(fill) === ''}>Terapkan ke semua periode</button>
            </div>
          )}
        </Field>
      </div>
      <div className="table-wrap" style={{ marginTop: 12 }}>
        <table className="table" aria-label="Jumlah per periode">
          <thead><tr><th scope="col">Periode</th><th scope="col" className="right">Jumlah</th></tr></thead>
          <tbody>
            {periods.map((p, i) => (
              <tr key={p.id}>
                <td>{p.code} · {p.name}</td>
                <td className="right">
                  <input className="input money" aria-label={`Jumlah periode ${p.code}`} inputMode="decimal" placeholder="—" value={amounts[p.id] ?? ''}
                    onChange={(e) => setAmounts((s) => ({ ...s, [p.id]: e.target.value }))} />
                  {fieldMessage(error, 'amount') && i === 0 && <span className="error">{fieldMessage(error, 'amount')}</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </FormModal>
  )
}

/** Change the amount or the note of a line (the account, period and dimensions identify the line: to change those, remove it and add another). */
export function LineEditDialog({ version, line, onClose, onDone }: { version: BudgetVersion; line: BudgetLine; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const [amount, setAmount] = useState(line.amount)
  const [description, setDescription] = useState(line.description ?? '')

  async function submit() {
    const r = await run(async () => (await api.patch(`${API}/budget-versions/${version.id}/lines/${line.id}`, { amount: normalizeAmountInput(amount) || amount.trim(), description: description.trim() || null })).data)
    if (r.ok) onDone()
  }

  return (
    <FormModal title={`Ubah baris ${line.account?.code ?? ''} · ${line.period?.code ?? ''}`} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Jumlah" error={fieldMessage(error, 'amount')}>
          {(p) => <input className="input money" required inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} {...p} />}
        </Field>
        <Field label="Keterangan" error={fieldMessage(error, 'description')}>
          {(p) => <input className="input" maxLength={255} value={description} onChange={(e) => setDescription(e.target.value)} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}

export function LineRemoveDialog({ version, line, onClose, onDone }: { version: BudgetVersion; line: BudgetLine; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  return (
    <ConfirmDialog
      title="Hapus baris anggaran"
      confirmLabel="Hapus baris"
      danger
      busy={busy}
      error={error}
      message={`Baris ${line.account?.code ?? ''} · ${line.period?.code ?? ''} dihapus dari versi draf ini. Versi yang sudah diajukan tidak dapat diubah barisnya.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`${API}/budget-versions/${version.id}/lines/${line.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
