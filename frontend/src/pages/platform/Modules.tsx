import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Badge, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, StatusBadge, Tabs } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { useAction, useResource } from '../../lib/hooks'
import type { Feature, Module } from '../../lib/types'

type Tab = 'modules' | 'dependencies' | 'features'

/** The module catalog is data: modules, their dependencies and features are edited here, not in code. */
export default function Modules() {
  const { can } = useCapabilities()
  const [tab, setTab] = useState<Tab>('modules')
  const modules = useResource(async () => (await api.get<{ data: Module[] }>('/platform/modules')).data.data, [])
  const canManage = can('platform.module.manage')

  return (
    <>
      <PageHeader title="Modul, dependensi, dan fitur" description="Katalog modul yang dapat dijual. Perubahan di sini tidak mengubah data akuntansi tenant." />
      <Tabs<Tab>
        tabs={[{ id: 'modules', label: 'Modul' }, { id: 'dependencies', label: 'Dependensi' }, { id: 'features', label: 'Fitur' }]}
        value={tab}
        onChange={setTab}
      />
      <Card flush>
        {modules.loading && !modules.data ? <Loading /> : modules.error || !modules.data ? <ErrorNotice error={modules.error} onRetry={modules.reload} /> : (
          <>
            {tab === 'modules' && <ModuleList modules={modules.data} canManage={canManage} onChanged={modules.reload} />}
            {tab === 'dependencies' && <Dependencies modules={modules.data} canManage={canManage} onChanged={modules.reload} />}
            {tab === 'features' && <Features modules={modules.data} canManage={canManage} onChanged={modules.reload} />}
          </>
        )}
      </Card>
    </>
  )
}

type Props = { modules: Module[]; canManage: boolean; onChanged: () => void }

function ModuleList({ modules, canManage, onChanged }: Props) {
  const toast = useToast()
  const [editing, setEditing] = useState<Module | 'new' | null>(null)
  const [toggling, setToggling] = useState<Module | null>(null)
  const act = useAction()

  return (
    <>
      <div className="card-head">
        <span className="muted">{modules.length} modul</span>
        {canManage && <Button variant="primary" size="sm" onClick={() => setEditing('new')}>Modul baru</Button>}
      </div>
      <DataTable
        caption="Modul"
        rows={modules}
        rowKey={(m) => m.id}
        columns={[
          { header: 'Modul', primary: true, cell: (m) => <><strong>{m.name}</strong><div className="mono muted">{m.code}</div></> },
          { header: 'Status', cell: (m) => <StatusBadge status={m.status} /> },
          { header: 'Dijual', cell: (m) => (m.commercially_available ? <Badge tone="ok">Tersedia</Badge> : <Badge>Belum dijual</Badge>) },
          { header: 'Fitur', align: 'right', cell: (m) => m.features.length },
          { header: 'Aksi', actions: true, cell: (m) => canManage && (
            <div className="actions">
              <Button size="sm" onClick={() => setEditing(m)}>Ubah</Button>
              <Button size="sm" onClick={() => setToggling(m)}>{m.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
            </div>
          ) },
        ]}
      />
      {editing && <ModuleForm module={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); onChanged(); toast.success('Modul disimpan.') }} />}
      {toggling && (
        <ConfirmDialog
          title={toggling.status === 'ACTIVE' ? 'Nonaktifkan modul' : 'Aktifkan modul'}
          message={toggling.status === 'ACTIVE' ? `${toggling.name} tidak dapat dinonaktifkan selama masih dipakai tenant.` : `Aktifkan kembali ${toggling.name} di katalog?`}
          confirmLabel={toggling.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}
          busy={act.busy}
          error={act.error}
          onClose={() => setToggling(null)}
          onConfirm={async () => {
            const r = await act.run(() => api.post(`/platform/modules/${toggling.id}/status`, { status: toggling.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' }))
            if (r.ok) { setToggling(null); onChanged(); toast.success('Status modul diperbarui.') }
          }}
        />
      )}
    </>
  )
}

function ModuleForm({ module, onClose, onDone }: { module: Module | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [code, setCode] = useState(module?.code ?? '')
  const [name, setName] = useState(module?.name ?? '')
  const [description, setDescription] = useState(module?.description ?? '')
  const [sellable, setSellable] = useState(module?.commercially_available ?? true)

  async function save() {
    const body = { name: name.trim(), description: description.trim() || null, commercially_available: sellable }
    const r = await run(() => (module ? api.patch(`/platform/modules/${module.id}`, body) : api.post('/platform/modules', { code: code.trim(), ...body })))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={module ? `Ubah modul: ${module.code}` : 'Modul baru'} busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      {!module && <Field label="Kode" hint="Huruf besar, angka, dan garis bawah; tidak dapat diubah." error={fieldError(error, 'code')}>{(p) => <input className="input mono" required value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} {...p} />}</Field>}
      <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required value={name} onChange={(e) => setName(e.target.value)} {...p} />}</Field>
      <Field label="Deskripsi" error={fieldError(error, 'description')}>{(p) => <textarea className="textarea" value={description} onChange={(e) => setDescription(e.target.value)} {...p} />}</Field>
      <label className="check"><input type="checkbox" checked={sellable} onChange={(e) => setSellable(e.target.checked)} /> Dapat dijual (tersedia untuk paket dan hak akses)</label>
    </FormModal>
  )
}

function Dependencies({ modules, canManage, onChanged }: Props) {
  const toast = useToast()
  const [adding, setAdding] = useState<Module | null>(null)
  const [removing, setRemoving] = useState<{ module: Module; requires: { id: string; code: string; name: string } } | null>(null)
  const act = useAction()

  return (
    <>
      <div className="card-head"><span className="muted">Modul hanya dapat diberikan bila seluruh modul yang dibutuhkan (termasuk secara tidak langsung) juga aktif. Dependensi melingkar ditolak.</span></div>
      <DataTable
        caption="Dependensi modul"
        rows={modules}
        rowKey={(m) => m.id}
        columns={[
          { header: 'Modul', primary: true, cell: (m) => <><strong>{m.name}</strong><div className="mono muted">{m.code}</div></> },
          { header: 'Membutuhkan', cell: (m) => m.requires.length === 0 ? <span className="muted">Tidak ada</span> : (
            <div className="chips">
              {m.requires.map((r) => (
                <span key={r.id} className="badge badge-info">
                  {r.code}
                  {canManage && <button type="button" className="btn-ghost" style={{ border: 0, background: 'none', cursor: 'pointer', color: 'inherit', padding: '0 0 0 6px' }} aria-label={`Hapus dependensi ${r.code} dari ${m.code}`} onClick={() => setRemoving({ module: m, requires: r })}>✕</button>}
                </span>
              ))}
            </div>
          ) },
          { header: 'Aksi', actions: true, cell: (m) => canManage && <Button size="sm" onClick={() => setAdding(m)}>Tambah dependensi</Button> },
        ]}
      />
      {adding && <AddDependency module={adding} modules={modules} onClose={() => setAdding(null)} onDone={() => { setAdding(null); onChanged(); toast.success('Dependensi ditambahkan.') }} />}
      {removing && (
        <ConfirmDialog
          title="Hapus dependensi"
          message={<><strong>{removing.module.code}</strong> tidak lagi membutuhkan <strong>{removing.requires.code}</strong>.</>}
          confirmLabel="Hapus"
          danger
          busy={act.busy}
          error={act.error}
          onClose={() => setRemoving(null)}
          onConfirm={async () => {
            const r = await act.run(() => api.delete(`/platform/modules/${removing.module.id}/dependencies/${removing.requires.id}`))
            if (r.ok) { setRemoving(null); onChanged(); toast.success('Dependensi dihapus.') }
          }}
        />
      )}
    </>
  )
}

function AddDependency({ module, modules, onClose, onDone }: { module: Module; modules: Module[]; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const options = modules.filter((m) => m.id !== module.id && !module.requires.some((r) => r.id === m.id))
  const [requires, setRequires] = useState(options[0]?.code ?? '')

  async function save() {
    const r = await run(() => api.post(`/platform/modules/${module.id}/dependencies`, { requires }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={`Dependensi baru untuk ${module.code}`} busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <Field label="Membutuhkan modul" error={fieldError(error, 'requires')}>
        {(p) => <select className="select" value={requires} onChange={(e) => setRequires(e.target.value)} {...p}>{options.map((m) => <option key={m.id} value={m.code}>{m.name} ({m.code})</option>)}</select>}
      </Field>
    </FormModal>
  )
}

function Features({ modules, canManage, onChanged }: Props) {
  const toast = useToast()
  const [editing, setEditing] = useState<{ module: Module; feature: Feature | null } | null>(null)
  const rows = modules.flatMap((m) => m.features.map((f) => ({ module: m, feature: f })))

  return (
    <>
      <div className="card-head">
        <span className="muted">{rows.length} fitur</span>
        {canManage && <Button variant="primary" size="sm" onClick={() => setEditing({ module: modules[0], feature: null })} disabled={modules.length === 0}>Fitur baru</Button>}
      </div>
      {rows.length === 0 ? <EmptyState title="Belum ada fitur" /> : (
        <DataTable
          caption="Fitur"
          rows={rows}
          rowKey={(r) => r.feature.id}
          columns={[
            { header: 'Fitur', primary: true, cell: (r) => <><strong>{r.feature.name}</strong><div className="mono muted">{r.feature.code}</div></> },
            { header: 'Modul', cell: (r) => r.module.name },
            { header: 'Status', cell: (r) => <StatusBadge status={r.feature.status} /> },
            { header: 'Aksi', actions: true, cell: (r) => canManage && <Button size="sm" onClick={() => setEditing(r)}>Ubah</Button> },
          ]}
        />
      )}
      {editing && <FeatureForm modules={modules} initial={editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); onChanged(); toast.success('Fitur disimpan.') }} />}
    </>
  )
}

function FeatureForm({ modules, initial, onClose, onDone }: { modules: Module[]; initial: { module: Module; feature: Feature | null }; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const feature = initial.feature
  const [moduleId, setModuleId] = useState(initial.module.id)
  const [code, setCode] = useState(feature?.code ?? '')
  const [name, setName] = useState(feature?.name ?? '')
  const [status, setStatus] = useState(feature?.status ?? 'ACTIVE')

  async function save() {
    const r = await run(() => (feature
      ? api.patch(`/platform/features/${feature.id}`, { name: name.trim(), status })
      : api.post(`/platform/modules/${moduleId}/features`, { code: code.trim(), name: name.trim() })))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={feature ? `Ubah fitur: ${feature.code}` : 'Fitur baru'} busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      {!feature && (
        <>
          <Field label="Modul">{(p) => <select className="select" value={moduleId} onChange={(e) => setModuleId(e.target.value)} {...p}>{modules.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</select>}</Field>
          <Field label="Kode" hint="Huruf besar, angka, dan garis bawah; unik di seluruh katalog." error={fieldError(error, 'code')}>{(p) => <input className="input mono" required value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} {...p} />}</Field>
        </>
      )}
      <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required value={name} onChange={(e) => setName(e.target.value)} {...p} />}</Field>
      {feature && <Field label="Status">{(p) => <select className="select" value={status} onChange={(e) => setStatus(e.target.value as Feature['status'])} {...p}><option value="ACTIVE">Aktif</option><option value="INACTIVE">Nonaktif</option></select>}</Field>}
    </FormModal>
  )
}
