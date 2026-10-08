import { useState } from 'react'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Badge, Banner, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatNumber } from '../../lib/format'
import { fieldError } from '../../lib/forms'
import { useAction, useResource } from '../../lib/hooks'
import { capacityLabels } from '../../lib/labels'
import type { Bundle, Module } from '../../lib/types'

const CAPACITIES = Object.keys(capacityLabels)

export default function Bundles() {
  const { can } = useCapabilities()
  const toast = useToast()
  const bundles = useResource(async () => (await api.get<{ data: Bundle[] }>('/platform/bundles')).data.data, [])
  const modules = useResource(async () => (await api.get<{ data: Module[] }>('/platform/modules')).data.data, [])
  const [editing, setEditing] = useState<Bundle | 'new' | null>(null)
  const canManage = can('platform.bundle.manage')

  return (
    <>
      <PageHeader
        title="Paket"
        description="Paket adalah kumpulan modul dan batas kapasitas yang dapat dipakai berulang untuk langganan. Perubahan paket tidak mengubah langganan yang sudah berjalan."
        actions={canManage && <Button variant="primary" onClick={() => setEditing('new')}>Paket baru</Button>}
      />
      {bundles.loading && !bundles.data ? <Loading /> : bundles.error || !bundles.data ? <ErrorNotice error={bundles.error} onRetry={bundles.reload} /> : bundles.data.length === 0 ? (
        <Card><EmptyState title="Belum ada paket" action={canManage ? <Button variant="primary" onClick={() => setEditing('new')}>Buat paket pertama</Button> : undefined}>Paket dipakai saat membuat langganan tenant.</EmptyState></Card>
      ) : (
        <div className="grid grid-3">
          {bundles.data.map((b) => (
            <section key={b.id} className="card card-body stack" aria-label={`Paket ${b.name}`}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'flex-start' }}>
                <div><h2>{b.name}</h2><span className="mono muted">{b.code}</span></div>
                <StatusBadge status={b.status} />
              </div>
              {b.description && <p className="muted">{b.description}</p>}
              <div><h3>Modul ({b.modules.length})</h3><div className="chips" style={{ marginTop: 6 }}>{b.modules.map((m) => <Badge key={m.id} tone="info">{m.code.replace('ACCOUNTING_', '')}</Badge>)}</div></div>
              <div>
                <h3>Kapasitas</h3>
                <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
                  {CAPACITIES.map((c) => {
                    const row = b.capacities.find((x) => x.limit_code === c)
                    return <li key={c}>{capacityLabels[c]}: {row && row.limit_value !== null ? formatNumber(row.limit_value) : 'tanpa batas'}</li>
                  })}
                </ul>
              </div>
              {canManage && <div><Button size="sm" onClick={() => setEditing(b)}>Ubah</Button></div>}
            </section>
          ))}
        </div>
      )}
      {editing && modules.data && (
        <BundleForm bundle={editing === 'new' ? null : editing} modules={modules.data} onClose={() => setEditing(null)} onDone={() => { setEditing(null); bundles.reload(); toast.success('Paket disimpan.') }} />
      )}
    </>
  )
}

/** The set of modules a selection implies: itself plus everything it requires, directly or not. */
function withRequirements(codes: Set<string>, modules: Module[]): Set<string> {
  const byCode = new Map(modules.map((m) => [m.code, m]))
  const result = new Set<string>()
  const visit = (code: string) => {
    if (result.has(code)) return
    result.add(code)
    byCode.get(code)?.requires.forEach((r) => visit(r.code))
  }
  codes.forEach(visit)
  return result
}

function BundleForm({ bundle, modules, onClose, onDone }: { bundle: Bundle | null; modules: Module[]; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [code, setCode] = useState(bundle?.code ?? '')
  const [name, setName] = useState(bundle?.name ?? '')
  const [description, setDescription] = useState(bundle?.description ?? '')
  const [status, setStatus] = useState(bundle?.status ?? 'ACTIVE')
  const [selected, setSelected] = useState(new Set(bundle?.modules.map((m) => m.code) ?? []))
  const [limits, setLimits] = useState<Record<string, string>>(
    Object.fromEntries(CAPACITIES.map((c) => [c, String(bundle?.capacities.find((x) => x.limit_code === c)?.limit_value ?? '')])),
  )
  const available = modules.filter((m) => m.status === 'ACTIVE')

  function toggle(moduleCode: string, on: boolean) {
    if (on) {
      setSelected(withRequirements(new Set([...selected, moduleCode]), modules))
      return
    }
    // Removing a module also removes the ones that need it, so the bundle never breaks the dependency rule.
    const next = new Set(selected)
    next.delete(moduleCode)
    let changed = true
    while (changed) {
      changed = false
      for (const m of modules) {
        if (next.has(m.code) && m.requires.some((r) => !next.has(r.code))) { next.delete(m.code); changed = true }
      }
    }
    setSelected(next)
  }

  async function save() {
    const capacities = Object.fromEntries(CAPACITIES.map((c) => [c, limits[c] === '' ? null : Number(limits[c])]))
    const body = { name: name.trim(), description: description.trim() || null, modules: [...selected], capacities }
    const r = await run(() => (bundle ? api.patch(`/platform/bundles/${bundle.id}`, { ...body, status }) : api.post('/platform/bundles', { code: code.trim(), ...body })))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={bundle ? `Ubah paket: ${bundle.code}` : 'Paket baru'} wide busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <div className="form-grid">
        {!bundle && <Field label="Kode" hint="Huruf besar, angka, dan garis bawah." error={fieldError(error, 'code')}>{(p) => <input className="input mono" required value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} {...p} />}</Field>}
        <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required value={name} onChange={(e) => setName(e.target.value)} {...p} />}</Field>
        {bundle && <Field label="Status">{(p) => <select className="select" value={status} onChange={(e) => setStatus(e.target.value as Bundle['status'])} {...p}><option value="ACTIVE">Aktif</option><option value="INACTIVE">Nonaktif</option></select>}</Field>}
        <Field label="Deskripsi" full>{(p) => <input className="input" value={description} onChange={(e) => setDescription(e.target.value)} {...p} />}</Field>
      </div>
      <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
        <legend className="label" style={{ fontWeight: 600, marginBottom: 6 }}>Modul</legend>
        <Banner tone="info">Memilih modul otomatis menyertakan modul yang dibutuhkannya.</Banner>
        <div className="perm-list" style={{ padding: '10px 0' }}>
          {available.map((m) => (
            <label key={m.id} className="check">
              <input type="checkbox" checked={selected.has(m.code)} onChange={(e) => toggle(m.code, e.target.checked)} />
              <span>{m.name} <span className="mono muted">{m.code}</span>{m.requires.length > 0 && <small>Membutuhkan: {m.requires.map((r) => r.code).join(', ')}</small>}</span>
            </label>
          ))}
        </div>
        {fieldError(error, 'modules') && <span className="error" style={{ color: 'var(--bad-text)' }}>{fieldError(error, 'modules')}</span>}
      </fieldset>
      <div className="form-grid">
        {CAPACITIES.map((c) => (
          <Field key={c} label={`Batas ${capacityLabels[c].toLowerCase()}`} hint="Kosong = tanpa batas">
            {(p) => <input className="input" type="number" min={0} value={limits[c]} onChange={(e) => setLimits((s) => ({ ...s, [c]: e.target.value }))} {...p} />}
          </Field>
        ))}
      </div>
    </FormModal>
  )
}
