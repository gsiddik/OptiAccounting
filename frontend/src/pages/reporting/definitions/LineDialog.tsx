import { useState } from 'react'
import { FormModal } from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { Button, Field } from '../../../components/ui'
import { api } from '../../../lib/api'
import { fieldError } from '../../../lib/forms'
import { useAction } from '../../../lib/hooks'
import { RefusalFindings } from './components'
import { ENDPOINTS, descendantCodes, isContainer, isMapped, lineBodyFromForm, lineForm, type LineForm } from './data'
import { categoryLabels, definitionError, lineTypeHints, lineTypeLabels, refusalFindings, sideLabels, systemKeyLabels, systemKeysFor } from './labels'
import { CASH_FLOW_CATEGORIES, LINE_TYPES, type BalanceSide, type DefinitionDetail, type LineType, type ReportLine } from './types'

const CODE_PATTERN = /^[A-Za-z0-9_.-]+$/

/**
 * Add a line to a draft or change one. Only declarative settings exist here: a balance side, a closed list of system figures and plus/minus
 * operands over other lines. The API validates the whole shape (and the validator the whole tree), so this dialog only shapes the request.
 */
export function LineDialog({ definition, line, parentCode, onClose, onSaved }: { definition: DefinitionDetail; line: ReportLine | null; parentCode?: string; onClose: () => void; onSaved: (detail: DefinitionDetail) => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [f, setF] = useState<LineForm>(() => lineForm(line, parentCode))
  const [local, setLocal] = useState<Record<string, string>>({})
  const creating = line === null
  const type = definition.statement_type
  const mapped = isMapped(f.line_type)
  const blocked = line ? descendantCodes(definition.lines, line.code) : new Set<string>()
  const parents = definition.lines.filter((l) => isContainer(l.line_type) && !blocked.has(l.code))
  const operandChoices = definition.lines.filter((l) => l.line_type !== 'HEADER' && l.code !== line?.code)
  const set = <K extends keyof LineForm>(key: K, value: LineForm[K]) => setF((s) => ({ ...s, [key]: value }))
  const { errors, warnings } = refusalFindings(error)

  function validate(): boolean {
    const found: Record<string, string> = {}
    if (creating && !CODE_PATTERN.test(f.code.trim())) found.code = 'Kode wajib diisi: huruf, angka, titik, garis bawah, atau strip.'
    if (!f.label.trim()) found.label = 'Uraian wajib diisi.'
    if (mapped && !f.balance_side) found.balance_side = 'Pilih sisi saldo.'
    if (f.line_type === 'SYSTEM' && !f.system_key) found.system_key = 'Pilih angka sistem.'
    if (f.line_type === 'CALCULATED_TOTAL' && !f.operands.some((o) => o.line)) found.operands = 'Tambahkan minimal satu operand.'
    setLocal(found)
    return Object.keys(found).length === 0
  }

  async function submit() {
    if (!validate()) return
    const body = lineBodyFromForm(f, type, creating)
    const r = await run(async () => (line ? await api.patch<DefinitionDetail>(ENDPOINTS.line(definition.id, line.id), body) : await api.post<DefinitionDetail>(ENDPOINTS.lines(definition.id), body)).data)
    if (r.ok) {
      toast.success(creating ? 'Baris ditambahkan.' : 'Baris disimpan.')
      onSaved(r.value)
    }
  }

  const setOperand = (i: number, patch: Partial<{ line: string; sign: '+' | '-' }>) => set('operands', f.operands.map((o, at) => (at === i ? { ...o, ...patch } : o)))
  const msg = (name: string) => local[name] ?? fieldError(error, name)

  return (
    <FormModal title={creating ? 'Tambah baris' : `Ubah baris ${line.code}`} busy={busy} error={definitionError(error)} onSubmit={() => void submit()} onClose={onClose} wide>
      <RefusalFindings errors={errors} warnings={warnings} />
      <div className="form-grid">
        <Field label="Kode" error={msg('code')} hint={creating ? 'Huruf, angka, titik, garis bawah, atau strip. Tidak dapat diubah setelah dibuat.' : 'Kode adalah identitas baris dan tidak dapat diubah.'}>
          {(p) => <input className="input mono" required maxLength={40} disabled={!creating} value={f.code} onChange={(e) => set('code', e.target.value)} {...p} />}
        </Field>
        <Field label="Uraian" error={msg('label')}>
          {(p) => <input className="input" required maxLength={200} value={f.label} onChange={(e) => set('label', e.target.value)} {...p} />}
        </Field>
        <Field label="Jenis baris" error={msg('line_type')} hint={lineTypeHints[f.line_type]}>
          {(p) => (
            <select className="select" value={f.line_type} onChange={(e) => set('line_type', e.target.value as LineType)} {...p}>
              {LINE_TYPES.map((t) => <option key={t} value={t}>{lineTypeLabels[t]}</option>)}
            </select>
          )}
        </Field>
        <Field label="Berada di bawah" error={msg('parent_code')} hint="Hanya judul atau subtotal yang dapat memuat baris lain.">
          {(p) => (
            <select className="select" value={f.parent_code} onChange={(e) => set('parent_code', e.target.value)} {...p}>
              <option value="">Tingkat teratas</option>
              {parents.map((l) => <option key={l.code} value={l.code}>{l.code} · {l.label}</option>)}
            </select>
          )}
        </Field>
        {mapped && (
          <Field label="Sisi saldo" error={msg('balance_side')} hint="Debit - kredit untuk akun bersaldo normal debit; kredit - debit untuk bersaldo normal kredit.">
            {(p) => (
              <select className="select" value={f.balance_side} onChange={(e) => set('balance_side', e.target.value as '' | BalanceSide)} {...p}>
                <option value="">Pilih sisi saldo…</option>
                {(['DEBIT', 'CREDIT'] as const).map((s) => <option key={s} value={s}>{sideLabels[s]}</option>)}
              </select>
            )}
          </Field>
        )}
        {mapped && type === 'CASH_FLOW' && (
          <Field label="Kategori arus kas" error={msg('cash_flow_category')} hint="Baris akun pada laporan arus kas wajib berkategori agar definisi valid.">
            {(p) => (
              <select className="select" value={f.cash_flow_category} onChange={(e) => set('cash_flow_category', e.target.value)} {...p}>
                <option value="">Belum dipilih</option>
                {CASH_FLOW_CATEGORIES.map((c) => <option key={c} value={c}>{categoryLabels[c]}</option>)}
              </select>
            )}
          </Field>
        )}
        {f.line_type === 'SYSTEM' && (
          <Field label="Angka sistem" error={msg('system_key')} hint="Daftar tertutup; hanya angka yang berlaku untuk jenis laporan ini.">
            {(p) => (
              <select className="select" value={f.system_key} onChange={(e) => set('system_key', e.target.value)} {...p}>
                <option value="">Pilih angka sistem…</option>
                {systemKeysFor(type).map((k) => <option key={k} value={k}>{systemKeyLabels[k] ?? k}</option>)}
              </select>
            )}
          </Field>
        )}
        {f.line_type === 'CALCULATED_TOTAL' && (
          <div className="field full">
            <span id="operands-label">Operand</span>
            <div className="stack" role="group" aria-labelledby="operands-label">
              {f.operands.map((o, i) => (
                <div key={i} className="toolbar">
                  <select className="select" aria-label={`Tanda operand ${i + 1}`} value={o.sign} onChange={(e) => setOperand(i, { sign: e.target.value as '+' | '-' })}>
                    <option value="+">+ tambah</option>
                    <option value="-">− kurang</option>
                  </select>
                  <select className="select" aria-label={`Baris operand ${i + 1}`} value={o.line} onChange={(e) => setOperand(i, { line: e.target.value })}>
                    <option value="">Pilih baris…</option>
                    {operandChoices.map((l) => <option key={l.code} value={l.code}>{l.code} · {l.label}</option>)}
                  </select>
                  <Button size="sm" aria-label={`Hapus operand ${i + 1}`} onClick={() => set('operands', f.operands.filter((_, at) => at !== i))}>Hapus</Button>
                </div>
              ))}
              <div><Button size="sm" onClick={() => set('operands', [...f.operands, { line: '', sign: '+' }])}>Tambah operand</Button></div>
            </div>
            <span className="hint">Total hitung menggabungkan baris lain dengan tanda tambah atau kurang. Tidak ada rumus bebas.</span>
            {msg('operands') && <span className="error">{msg('operands')}</span>}
          </div>
        )}
        <Field label="Urutan tampil" error={msg('display_order')} hint="Angka kecil tampil lebih dulu di antara baris sejajar. Kosongkan untuk urutan otomatis.">
          {(p) => <input className="input" type="number" min={0} max={1000000} value={f.display_order} onChange={(e) => set('display_order', e.target.value)} {...p} />}
        </Field>
        <Field label="Catatan" error={msg('notes')} full>
          {(p) => <textarea className="textarea" maxLength={500} value={f.notes} onChange={(e) => set('notes', e.target.value)} {...p} />}
        </Field>
        <label className="check"><input type="checkbox" checked={f.show_zero} onChange={(e) => set('show_zero', e.target.checked)} /> Tampilkan walau nol</label>
        <label className="check"><input type="checkbox" checked={f.emphasis} onChange={(e) => set('emphasis', e.target.checked)} /> Cetak tebal</label>
      </div>
    </FormModal>
  )
}
