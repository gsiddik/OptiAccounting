import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Badge, Banner, Button, Field } from '../../components/ui'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { operationalModuleLabels } from '../../lib/operationalLabels'
import { Facts, SeverityBadge } from './components'
import { ENDPOINTS } from './data'
import { closingError, severityText } from './labels'
import type { Policy, PolicyCheck, Severity } from './types'

const SEVERITIES: Severity[] = ['BLOCK', 'WARN', 'OFF']

type Draft = { severities: Record<string, Severity>; tolerance: string; maxBack: string; laterOpen: boolean; separate: boolean; softFirst: boolean }

const toDraft = (p: Policy): Draft => ({
  severities: Object.fromEntries(p.checks.map((c) => [c.check_code, c.severity])),
  tolerance: p.settings.reconciliation_tolerance,
  maxBack: p.settings.reopen_max_periods_back === null ? '' : String(p.settings.reopen_max_periods_back),
  laterOpen: p.settings.reopen_requires_later_open,
  separate: p.settings.separate_closer_and_reopener,
  softFirst: p.settings.require_soft_close_first,
})

/** Only what differs from the saved policy is sent: the changed check severities and the changed settings. */
function changes(p: Policy, d: Draft) {
  const rules = p.checks.filter((c) => !c.locked && d.severities[c.check_code] !== c.severity).map((c) => ({ check_code: c.check_code, severity: d.severities[c.check_code] }))
  const s = p.settings
  const settings: Record<string, unknown> = {}
  if (d.tolerance.trim() !== s.reconciliation_tolerance) settings.reconciliation_tolerance = d.tolerance.trim()
  if (d.maxBack !== (s.reopen_max_periods_back === null ? '' : String(s.reopen_max_periods_back))) settings.reopen_max_periods_back = d.maxBack === '' ? null : Number(d.maxBack)
  if (d.laterOpen !== s.reopen_requires_later_open) settings.reopen_requires_later_open = d.laterOpen
  if (d.separate !== s.separate_closer_and_reopener) settings.separate_closer_and_reopener = d.separate
  if (d.softFirst !== s.require_soft_close_first) settings.require_soft_close_first = d.softFirst
  return { rules, settings, any: rules.length > 0 || Object.keys(settings).length > 0 }
}

const moduleNote = (c: PolicyCheck) => (c.module ? `Hanya bila modul ${operationalModuleLabels[c.module] ?? c.module} berlangganan` : 'Selalu berlaku')

export function PolicyEditor({ policy, editable, onSaved }: { policy: Policy; editable: boolean; onSaved: (saved: Policy) => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [draft, setDraft] = useState(() => toDraft(policy))
  const [localError, setLocalError] = useState<string | undefined>()
  const patch = (over: Partial<Draft>) => setDraft((d) => ({ ...d, ...over }))
  const diff = changes(policy, draft)

  async function save() {
    if (draft.maxBack !== '' && !/^\d{1,4}$/.test(draft.maxBack)) return setLocalError('Isi bilangan bulat 0 sampai 1000, atau kosongkan untuk tanpa batas.')
    if (!/^\d+(\.\d{1,4})?$/.test(draft.tolerance.trim())) return setLocalError('Toleransi harus angka desimal tidak negatif dengan paling banyak 4 desimal.')
    setLocalError(undefined)
    const result = await run(async () => (await api.put<Policy>(ENDPOINTS.policy, { ...(diff.rules.length > 0 && { rules: diff.rules }), ...(Object.keys(diff.settings).length > 0 && { settings: diff.settings }) })).data)
    if (result.ok) {
      toast.success('Kebijakan penutupan disimpan.')
      onSaved(result.value)
    }
  }

  return (
    <>
      <DataTable
        caption="Perilaku setiap pemeriksaan penutupan"
        rows={policy.checks}
        rowKey={(c) => c.check_code}
        columns={[
          { header: 'Pemeriksaan', primary: true, cell: (c) => <><strong>{c.title}</strong> <span className="muted">({c.check_code})</span><div className="muted preview-note">{moduleNote(c)}</div></> },
          { header: 'Bawaan', cell: (c) => <SeverityBadge severity={c.default_severity} /> },
          {
            header: 'Perilaku',
            cell: (c) => c.locked
              ? <><Badge tone="info">Terkunci</Badge> <span className="muted">selalu menghalangi (aturan akuntansi)</span></>
              : editable
                ? (
                  <select className="select" aria-label={`Perilaku ${c.title}`} value={draft.severities[c.check_code]} onChange={(e) => patch({ severities: { ...draft.severities, [c.check_code]: e.target.value as Severity } })}>
                    {SEVERITIES.map((s) => <option key={s} value={s}>{severityText(s)[0]}</option>)}
                  </select>
                )
                : <SeverityBadge severity={c.severity} />,
          },
        ]}
      />
      <div className="card-body stack">
        <h3>Pengaturan</h3>
        {editable ? (
          <>
            {(localError || error != null) && <Banner tone="bad">{localError ?? describeError(closingError(error))}</Banner>}
            <div className="form-grid">
              <Field label="Toleransi rekonsiliasi" hint="Selisih sampai batas ini dianggap cocok. Bawaan 0." error={fieldError(error, 'settings.reconciliation_tolerance')}>
                {(p) => <input className="input" inputMode="decimal" value={draft.tolerance} onChange={(e) => patch({ tolerance: e.target.value })} {...p} />}
              </Field>
              <Field label="Batas mundur buka kembali (periode)" hint="Kosong berarti tanpa batas." error={fieldError(error, 'settings.reopen_max_periods_back')}>
                {(p) => <input className="input" inputMode="numeric" value={draft.maxBack} onChange={(e) => patch({ maxBack: e.target.value })} {...p} />}
              </Field>
              <label className="check full"><input type="checkbox" checked={draft.laterOpen} onChange={(e) => patch({ laterOpen: e.target.checked })} /> Periode hanya boleh dibuka kembali bila periode yang lebih baru tidak tertutup</label>
              <label className="check full"><input type="checkbox" checked={draft.separate} onChange={(e) => patch({ separate: e.target.checked })} /> Pemisahan tugas: orang yang menutup periode tidak boleh membukanya kembali</label>
              <label className="check full"><input type="checkbox" checked={draft.softFirst} onChange={(e) => patch({ softFirst: e.target.checked })} /> Wajib tutup sementara sebelum tutup periode</label>
            </div>
            <div className="toolbar">
              <Button variant="primary" loading={busy} disabled={!diff.any} onClick={() => void save()}>Simpan kebijakan</Button>
              {!diff.any && <span className="muted">Belum ada perubahan.</span>}
            </div>
          </>
        ) : (
          <Facts rows={[
            ['Toleransi rekonsiliasi', policy.settings.reconciliation_tolerance],
            ['Batas mundur buka kembali', policy.settings.reopen_max_periods_back === null ? 'Tanpa batas' : `${policy.settings.reopen_max_periods_back} periode`],
            ['Periode yang lebih baru harus tidak tertutup', policy.settings.reopen_requires_later_open ? 'Ya' : 'Tidak'],
            ['Penutup tidak boleh membuka kembali', policy.settings.separate_closer_and_reopener ? 'Ya' : 'Tidak'],
            ['Wajib tutup sementara dulu', policy.settings.require_soft_close_first ? 'Ya' : 'Tidak'],
          ]} />
        )}
      </div>
    </>
  )
}
