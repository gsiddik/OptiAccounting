import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Badge, Banner, Button, EmptyState, ErrorNotice, Field, Loading, Meter, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, formatNumber, todayIn } from '../../lib/format'
import { fieldError } from '../../lib/forms'
import { useAction, useResource } from '../../lib/hooks'
import { capacityLabels, sourceLabels } from '../../lib/labels'
import type { Bundle, Feature, FeatureEntitlement, Member, Module, ModuleEntitlement, Paginated, Subscription, SubscriptionStatus, Tenant, TenantEntitlements } from '../../lib/types'

/* ------------------------------------------------------------------ members */

export function MemberTab({ tenantId }: { tenantId: string }) {
  const { can } = useCapabilities()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [target, setTarget] = useState<{ member: Member; status: 'ACTIVE' | 'SUSPENDED' | 'INACTIVE' } | null>(null)
  const act = useAction()
  const members = useResource(async () => (await api.get<Paginated<Member>>(`/platform/tenants/${tenantId}/members`, { params: { page } })).data, [tenantId, page])

  if (members.loading && !members.data) return <Loading />
  if (members.error || !members.data) return <ErrorNotice error={members.error} onRetry={members.reload} />
  if (members.data.data.length === 0) return <EmptyState title="Belum ada anggota">Tambahkan administrator awal saat membuat tenant atau melalui Portal Tenant.</EmptyState>

  const canManage = can('platform.membership.manage')
  return (
    <>
      <DataTable
        caption="Anggota tenant"
        rows={members.data.data}
        rowKey={(m) => m.id}
        columns={[
          { header: 'Pengguna', cell: (m) => <><strong>{m.user.name}</strong><div className="muted">{m.user.email}</div></> },
          { header: 'Peran', cell: (m) => <div className="chips">{m.roles.map((r) => <Badge key={r.id} tone="info">{r.name}</Badge>)}</div> },
          { header: 'Status', cell: (m) => <StatusBadge status={m.status} /> },
          { header: 'Aksi', actions: true, cell: (m) => canManage && (
            <div className="actions">
              {m.status === 'ACTIVE' ? (
                <Button size="sm" onClick={() => setTarget({ member: m, status: 'SUSPENDED' })}>Tangguhkan</Button>
              ) : (
                <Button size="sm" onClick={() => setTarget({ member: m, status: 'ACTIVE' })}>Aktifkan</Button>
              )}
            </div>
          ) },
        ]}
      />
      {members.data.last_page > 1 && (
        <div className="pagination">
          <span>Halaman {members.data.current_page} dari {members.data.last_page}</span>
          <div className="actions">
            <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Sebelumnya</Button>
            <Button size="sm" disabled={page >= members.data.last_page} onClick={() => setPage(page + 1)}>Berikutnya</Button>
          </div>
        </div>
      )}
      {target && (
        <ConfirmDialog
          title={target.status === 'ACTIVE' ? 'Aktifkan anggota' : 'Tangguhkan anggota'}
          message={target.status === 'ACTIVE' ? `Aktifkan kembali ${target.member.user.name}?` : `${target.member.user.name} akan dikeluarkan dari sesi yang sedang berjalan dan tidak dapat masuk ke tenant ini.`}
          confirmLabel={target.status === 'ACTIVE' ? 'Aktifkan' : 'Tangguhkan'}
          busy={act.busy}
          error={act.error}
          onClose={() => setTarget(null)}
          onConfirm={async () => {
            const r = await act.run(() => api.post(`/platform/tenants/${tenantId}/members/${target.member.id}/status`, { status: target.status }))
            if (r.ok) { setTarget(null); members.reload(); toast.success('Status anggota diperbarui.') }
          }}
        />
      )}
    </>
  )
}

/* ------------------------------------------------------------ subscriptions */

const NEXT: Record<SubscriptionStatus, SubscriptionStatus[]> = {
  PENDING: ['ACTIVE', 'CANCELLED'],
  ACTIVE: ['PAST_DUE', 'SUSPENDED', 'EXPIRED', 'CANCELLED'],
  PAST_DUE: ['ACTIVE', 'SUSPENDED', 'EXPIRED', 'CANCELLED'],
  SUSPENDED: ['ACTIVE', 'EXPIRED', 'CANCELLED'],
  EXPIRED: [],
  CANCELLED: [],
}
const NEXT_HINT: Partial<Record<SubscriptionStatus, string>> = {
  PAST_DUE: 'Modul menjadi hanya baca sampai pembayaran selesai.',
  SUSPENDED: 'Akses modul ditutup sementara.',
  EXPIRED: 'Periode hak akses dari paket ditutup. Tidak dapat dibatalkan.',
  CANCELLED: 'Periode hak akses dari paket ditutup. Tidak dapat dibatalkan.',
}

export function SubscriptionTab({ tenant }: { tenant: Tenant }) {
  const { can } = useCapabilities()
  const toast = useToast()
  const subs = useResource(async () => (await api.get<{ data: Subscription[] }>(`/platform/tenants/${tenant.id}/subscriptions`)).data.data, [tenant.id])
  const [creating, setCreating] = useState(false)
  const [moving, setMoving] = useState<Subscription | null>(null)
  const [rescheduling, setRescheduling] = useState<Subscription | null>(null)
  const canManage = can('platform.subscription.manage')

  if (subs.loading && !subs.data) return <Loading />
  if (subs.error || !subs.data) return <ErrorNotice error={subs.error} onRetry={subs.reload} />
  const live = subs.data.some((s) => ['PENDING', 'ACTIVE', 'PAST_DUE', 'SUSPENDED'].includes(s.status))

  return (
    <>
      <div className="card-head">
        <span className="muted">Satu tenant hanya boleh memiliki satu langganan yang berjalan. Riwayat tidak dihapus.</span>
        {canManage && <Button variant="primary" size="sm" disabled={live} onClick={() => setCreating(true)}>Langganan baru</Button>}
      </div>
      {subs.data.length === 0 ? (
        <EmptyState title="Belum ada langganan">Tanpa langganan, tenant tidak memiliki akses modul akuntansi.</EmptyState>
      ) : (
        <DataTable
          caption="Riwayat langganan"
          rows={subs.data}
          rowKey={(s) => s.id}
          columns={[
            { header: 'Paket', cell: (s) => <strong>{s.bundle?.name ?? 'Tanpa paket'}</strong> },
            { header: 'Status', cell: (s) => <StatusBadge status={s.status} /> },
            { header: 'Mulai', cell: (s) => formatDate(s.starts_on) },
            { header: 'Berakhir', cell: (s) => formatDate(s.ends_on) },
            { header: 'Aksi', actions: true, cell: (s) => canManage && (
              <div className="actions">
                {NEXT[s.status].length > 0 && <Button size="sm" onClick={() => setMoving(s)}>Ubah status</Button>}
                {NEXT[s.status].length > 0 && <Button size="sm" onClick={() => setRescheduling(s)}>Jadwal ulang</Button>}
              </div>
            ) },
          ]}
        />
      )}
      {creating && <NewSubscription tenant={tenant} onClose={() => setCreating(false)} onDone={() => { setCreating(false); subs.reload(); toast.success('Langganan dibuat.') }} />}
      {moving && <MoveSubscription tenant={tenant} sub={moving} onClose={() => setMoving(null)} onDone={() => { setMoving(null); subs.reload(); toast.success('Status langganan diperbarui.') }} />}
      {rescheduling && <Reschedule tenant={tenant} sub={rescheduling} onClose={() => setRescheduling(null)} onDone={() => { setRescheduling(null); subs.reload(); toast.success('Jadwal langganan diperbarui.') }} />}
    </>
  )
}

function NewSubscription({ tenant, onClose, onDone }: { tenant: Tenant; onClose: () => void; onDone: () => void }) {
  const bundles = useResource(async () => (await api.get<{ data: Bundle[] }>('/platform/bundles')).data.data.filter((b) => b.status === 'ACTIVE'), [])
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ bundle_code: '', starts_on: todayIn(tenant.timezone), ends_on: '', status: 'ACTIVE', notes: '' })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  async function save() {
    const r = await run(() => api.post(`/platform/tenants/${tenant.id}/subscriptions`, {
      bundle_code: f.bundle_code || null, starts_on: f.starts_on, ends_on: f.ends_on || null, status: f.status, notes: f.notes.trim() || null,
    }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Langganan baru" wide busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Paket" hint="Hak akses modul, fitur, dan batas kapasitas diterapkan dari paket." error={fieldError(error, 'bundle_code')} full>
          {(p) => (
            <select className="select" value={f.bundle_code} onChange={set('bundle_code')} {...p}>
              <option value="">Tanpa paket (atur hak akses manual)</option>
              {(bundles.data ?? []).map((b) => <option key={b.id} value={b.code}>{b.name} — {b.modules.length} modul</option>)}
            </select>
          )}
        </Field>
        <Field label="Mulai" error={fieldError(error, 'starts_on')}>{(p) => <input className="input" type="date" required value={f.starts_on} onChange={set('starts_on')} {...p} />}</Field>
        <Field label="Berakhir (opsional)" error={fieldError(error, 'ends_on')}>{(p) => <input className="input" type="date" value={f.ends_on} onChange={set('ends_on')} {...p} />}</Field>
        <Field label="Status awal">
          {(p) => <select className="select" value={f.status} onChange={set('status')} {...p}><option value="ACTIVE">Aktif</option><option value="PENDING">Menunggu</option></select>}
        </Field>
        <Field label="Catatan" error={fieldError(error, 'notes')}>{(p) => <input className="input" maxLength={500} value={f.notes} onChange={set('notes')} {...p} />}</Field>
      </div>
    </FormModal>
  )
}

function MoveSubscription({ tenant, sub, onClose, onDone }: { tenant: Tenant; sub: Subscription; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const options = NEXT[sub.status]
  const [to, setTo] = useState<SubscriptionStatus>(options[0])
  const [reason, setReason] = useState('')

  async function save() {
    const r = await run(() => api.post(`/platform/tenants/${tenant.id}/subscriptions/${sub.id}/status`, { status: to, reason: reason.trim() }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Ubah status langganan" busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <Field label="Status baru">
        {(p) => <select className="select" value={to} onChange={(e) => setTo(e.target.value as SubscriptionStatus)} {...p}>{options.map((o) => <option key={o} value={o}>{o}</option>)}</select>}
      </Field>
      {NEXT_HINT[to] && <Banner tone="warn">{NEXT_HINT[to]}</Banner>}
      <Field label="Alasan (dicatat di audit)" hint="Minimal 3 karakter." error={fieldError(error, 'reason')}>
        {(p) => <textarea className="textarea" value={reason} onChange={(e) => setReason(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}

function Reschedule({ tenant, sub, onClose, onDone }: { tenant: Tenant; sub: Subscription; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [starts, setStarts] = useState(sub.starts_on.slice(0, 10))
  const [ends, setEnds] = useState(sub.ends_on?.slice(0, 10) ?? '')

  async function save() {
    const r = await run(() => api.patch(`/platform/tenants/${tenant.id}/subscriptions/${sub.id}`, { starts_on: starts, ends_on: ends || null }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Jadwal ulang langganan" busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <p className="muted">Periode hak akses yang berasal dari paket ikut berubah.</p>
      <div className="form-grid">
        <Field label="Mulai">{(p) => <input className="input" type="date" required value={starts} onChange={(e) => setStarts(e.target.value)} {...p} />}</Field>
        <Field label="Berakhir">{(p) => <input className="input" type="date" value={ends} onChange={(e) => setEnds(e.target.value)} {...p} />}</Field>
      </div>
    </FormModal>
  )
}

/* ------------------------------------------------------------------ modules */

function useEntitlements(tenantId: string) {
  return useResource(async () => (await api.get<TenantEntitlements>(`/platform/tenants/${tenantId}/entitlements`)).data, [tenantId])
}

export function ModuleTab({ tenant }: { tenant: Tenant }) {
  const { can } = useCapabilities()
  const toast = useToast()
  const ent = useEntitlements(tenant.id)
  const [granting, setGranting] = useState(false)
  const [editing, setEditing] = useState<ModuleEntitlement | null>(null)
  const canManage = can('platform.entitlement.manage')

  if (ent.loading && !ent.data) return <Loading />
  if (ent.error || !ent.data) return <ErrorNotice error={ent.error} onRetry={ent.reload} />
  const data = ent.data

  return (
    <>
      <div className="card-head">
        <span className="muted">Keadaan efektif per {formatDate(data.effective.date)} · langganan: <StatusBadge status={data.effective.subscription.mode} /></span>
        {canManage && <Button variant="primary" size="sm" onClick={() => setGranting(true)}>Beri modul</Button>}
      </div>
      {data.modules.length === 0 ? (
        <EmptyState title="Belum ada hak akses modul">Berlangganan paket atau beri modul secara manual.</EmptyState>
      ) : (
        <DataTable
          caption="Hak akses modul"
          rows={data.modules}
          rowKey={(m) => m.id}
          columns={[
            { header: 'Modul', cell: (m) => <><strong>{m.module.name}</strong><div className="mono muted">{m.module.code}</div></> },
            { header: 'Status', cell: (m) => <StatusBadge status={m.state} /> },
            { header: 'Sumber', cell: (m) => sourceLabels[m.source] ?? m.source },
            { header: 'Periode', cell: (m) => <span className="nowrap">{formatDate(m.effective_from)} – {m.effective_until ? formatDate(m.effective_until) : 'tanpa batas'}</span> },
            { header: 'Aksi', actions: true, cell: (m) => canManage && <Button size="sm" onClick={() => setEditing(m)}>Ubah</Button> },
          ]}
        />
      )}
      {granting && <GrantModule tenant={tenant} onClose={() => setGranting(false)} onDone={() => { setGranting(false); ent.reload(); toast.success('Hak akses modul diberikan.') }} />}
      {editing && <EditModule tenant={tenant} row={editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); ent.reload(); toast.success('Hak akses modul diperbarui.') }} />}
    </>
  )
}

function GrantModule({ tenant, onClose, onDone }: { tenant: Tenant; onClose: () => void; onDone: () => void }) {
  const modules = useResource(async () => (await api.get<{ data: Module[] }>('/platform/modules')).data.data, [])
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ module_code: '', state: 'ACTIVE', source: 'MANUAL_OVERRIDE', effective_from: todayIn(tenant.timezone), effective_until: '' })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const picked = modules.data?.find((m) => m.code === f.module_code)

  async function save() {
    const r = await run(() => api.post(`/platform/tenants/${tenant.id}/entitlements/modules`, {
      module_code: f.module_code, state: f.state, source: f.source, effective_from: f.effective_from, effective_until: f.effective_until || null,
    }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Beri modul" wide busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Modul" error={fieldError(error, 'module_code')} full>
          {(p) => (
            <select className="select" required value={f.module_code} onChange={set('module_code')} {...p}>
              <option value="">Pilih modul…</option>
              {(modules.data ?? []).filter((m) => m.status === 'ACTIVE').map((m) => <option key={m.id} value={m.code}>{m.name} ({m.code})</option>)}
            </select>
          )}
        </Field>
        {picked && picked.requires.length > 0 && <div className="full"><Banner tone="info">Membutuhkan modul aktif: {picked.requires.map((r) => r.code).join(', ')}.</Banner></div>}
        <Field label="Status">
          {(p) => <select className="select" value={f.state} onChange={set('state')} {...p}>{['ACTIVE', 'READ_ONLY', 'SUSPENDED', 'DISABLED'].map((s) => <option key={s}>{s}</option>)}</select>}
        </Field>
        <Field label="Sumber">
          {(p) => <select className="select" value={f.source} onChange={set('source')} {...p}>{Object.entries(sourceLabels).filter(([k]) => k !== 'BUNDLE').map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>}
        </Field>
        <Field label="Berlaku mulai" error={fieldError(error, 'effective_from')}>{(p) => <input className="input" type="date" value={f.effective_from} onChange={set('effective_from')} {...p} />}</Field>
        <Field label="Berlaku sampai (opsional)" error={fieldError(error, 'effective_until')}>{(p) => <input className="input" type="date" value={f.effective_until} onChange={set('effective_until')} {...p} />}</Field>
      </div>
    </FormModal>
  )
}

function EditModule({ tenant, row, onClose, onDone }: { tenant: Tenant; row: ModuleEntitlement; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [state, setState] = useState(row.state)
  const [until, setUntil] = useState(row.effective_until?.slice(0, 10) ?? '')

  async function save() {
    const r = await run(() => api.patch(`/platform/tenants/${tenant.id}/entitlements/modules/${row.id}`, { state, effective_until: until || null }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={`Ubah modul: ${row.module.name}`} busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <Field label="Status">
        {(p) => <select className="select" value={state} onChange={(e) => setState(e.target.value as ModuleEntitlement['state'])} {...p}>{['ACTIVE', 'READ_ONLY', 'SUSPENDED', 'DISABLED'].map((s) => <option key={s}>{s}</option>)}</select>}
      </Field>
      <Field label="Berlaku sampai (kosong = tanpa batas)">{(p) => <input className="input" type="date" value={until} onChange={(e) => setUntil(e.target.value)} {...p} />}</Field>
    </FormModal>
  )
}

/* ----------------------------------------------------------------- features */

export function FeatureTab({ tenant }: { tenant: Tenant }) {
  const { can } = useCapabilities()
  const toast = useToast()
  const ent = useEntitlements(tenant.id)
  const [granting, setGranting] = useState(false)
  const [editing, setEditing] = useState<FeatureEntitlement | null>(null)
  const canManage = can('platform.entitlement.manage')

  if (ent.loading && !ent.data) return <Loading />
  if (ent.error || !ent.data) return <ErrorNotice error={ent.error} onRetry={ent.reload} />

  return (
    <>
      <div className="card-head">
        <span className="muted">Fitur mengikuti modul induknya: fitur aktif tidak dapat dipakai bila modulnya tidak tersedia.</span>
        {canManage && <Button variant="primary" size="sm" onClick={() => setGranting(true)}>Beri fitur</Button>}
      </div>
      {ent.data.features.length === 0 ? (
        <EmptyState title="Belum ada hak akses fitur" />
      ) : (
        <DataTable
          caption="Hak akses fitur"
          rows={ent.data.features}
          rowKey={(f) => f.id}
          columns={[
            { header: 'Fitur', cell: (f) => <><strong>{f.feature.name}</strong><div className="mono muted">{f.feature.code}</div></> },
            { header: 'Status', cell: (f) => <StatusBadge status={f.state} /> },
            { header: 'Sumber', cell: (f) => sourceLabels[f.source] ?? f.source },
            { header: 'Periode', cell: (f) => <span className="nowrap">{formatDate(f.effective_from)} – {f.effective_until ? formatDate(f.effective_until) : 'tanpa batas'}</span> },
            { header: 'Aksi', actions: true, cell: (f) => canManage && <Button size="sm" onClick={() => setEditing(f)}>Ubah</Button> },
          ]}
        />
      )}
      {granting && <GrantFeature tenant={tenant} onClose={() => setGranting(false)} onDone={() => { setGranting(false); ent.reload(); toast.success('Hak akses fitur diberikan.') }} />}
      {editing && <EditFeature tenant={tenant} row={editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); ent.reload(); toast.success('Hak akses fitur diperbarui.') }} />}
    </>
  )
}

function GrantFeature({ tenant, onClose, onDone }: { tenant: Tenant; onClose: () => void; onDone: () => void }) {
  const modules = useResource(async () => (await api.get<{ data: Module[] }>('/platform/modules')).data.data, [])
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ feature_code: '', state: 'ACTIVE', source: 'MANUAL_OVERRIDE', effective_from: todayIn(tenant.timezone), effective_until: '' })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const features: (Feature & { module: string })[] = (modules.data ?? []).flatMap((m) => m.features.filter((x) => x.status === 'ACTIVE').map((x) => ({ ...x, module: m.name })))

  async function save() {
    const r = await run(() => api.post(`/platform/tenants/${tenant.id}/entitlements/features`, {
      feature_code: f.feature_code, state: f.state, source: f.source, effective_from: f.effective_from, effective_until: f.effective_until || null,
    }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Beri fitur" wide busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Fitur" full error={fieldError(error, 'feature_code')}>
          {(p) => (
            <select className="select" required value={f.feature_code} onChange={set('feature_code')} {...p}>
              <option value="">Pilih fitur…</option>
              {features.map((x) => <option key={x.id} value={x.code}>{x.module} · {x.name} ({x.code})</option>)}
            </select>
          )}
        </Field>
        <Field label="Status">{(p) => <select className="select" value={f.state} onChange={set('state')} {...p}><option>ACTIVE</option><option>DISABLED</option></select>}</Field>
        <Field label="Sumber">{(p) => <select className="select" value={f.source} onChange={set('source')} {...p}>{Object.entries(sourceLabels).filter(([k]) => k !== 'BUNDLE').map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>}</Field>
        <Field label="Berlaku mulai">{(p) => <input className="input" type="date" value={f.effective_from} onChange={set('effective_from')} {...p} />}</Field>
        <Field label="Berlaku sampai (opsional)">{(p) => <input className="input" type="date" value={f.effective_until} onChange={set('effective_until')} {...p} />}</Field>
      </div>
    </FormModal>
  )
}

function EditFeature({ tenant, row, onClose, onDone }: { tenant: Tenant; row: FeatureEntitlement; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [state, setState] = useState(row.state)
  const [until, setUntil] = useState(row.effective_until?.slice(0, 10) ?? '')

  async function save() {
    const r = await run(() => api.patch(`/platform/tenants/${tenant.id}/entitlements/features/${row.id}`, { state, effective_until: until || null }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={`Ubah fitur: ${row.feature.name}`} busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <Field label="Status">{(p) => <select className="select" value={state} onChange={(e) => setState(e.target.value as FeatureEntitlement['state'])} {...p}><option>ACTIVE</option><option>DISABLED</option></select>}</Field>
      <Field label="Berlaku sampai (kosong = tanpa batas)">{(p) => <input className="input" type="date" value={until} onChange={(e) => setUntil(e.target.value)} {...p} />}</Field>
    </FormModal>
  )
}

/* ----------------------------------------------------------------- capacity */

export function CapacityTab({ tenant }: { tenant: Tenant }) {
  const { can } = useCapabilities()
  const toast = useToast()
  const ent = useEntitlements(tenant.id)
  const [editing, setEditing] = useState<{ code: string; limit: number | null } | null>(null)
  const canManage = can('platform.entitlement.manage')

  if (ent.loading && !ent.data) return <Loading />
  if (ent.error || !ent.data) return <ErrorNotice error={ent.error} onRetry={ent.reload} />

  return (
    <>
      <div className="card-head"><span className="muted">Batas diperiksa saat membuat pengguna, cabang, atau unit bisnis. Menurunkan batas tidak menghapus data yang sudah ada.</span></div>
      <DataTable
        caption="Kapasitas"
        rows={ent.data.capacity}
        rowKey={(c) => c.code}
        columns={[
          { header: 'Batas', cell: (c) => <strong>{capacityLabels[c.code] ?? c.code}</strong> },
          { header: 'Terpakai', align: 'right', cell: (c) => formatNumber(c.used) },
          { header: 'Batas maksimum', align: 'right', cell: (c) => (c.limit === null ? 'Tanpa batas' : formatNumber(c.limit)) },
          { header: 'Pemakaian', cell: (c) => <div style={{ minWidth: 120 }}><Meter used={c.used} limit={c.limit} /></div> },
          { header: 'Aksi', actions: true, cell: (c) => canManage && <Button size="sm" onClick={() => setEditing({ code: c.code, limit: c.limit })}>Atur</Button> },
        ]}
      />
      {editing && <SetCapacity tenant={tenant} code={editing.code} limit={editing.limit} onClose={() => setEditing(null)} onDone={() => { setEditing(null); ent.reload(); toast.success('Batas kapasitas disimpan.') }} />}
    </>
  )
}

function SetCapacity({ tenant, code, limit, onClose, onDone }: { tenant: Tenant; code: string; limit: number | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [value, setValue] = useState(limit === null ? '' : String(limit))
  const [source, setSource] = useState('MANUAL_OVERRIDE')

  async function save() {
    const r = await run(() => api.put(`/platform/tenants/${tenant.id}/capacity/${code}`, { limit_value: value === '' ? null : Number(value), source }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={`Atur batas: ${capacityLabels[code] ?? code}`} busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <Field label="Batas maksimum" hint="Kosongkan untuk tanpa batas. 0 menutup seluruh penambahan." error={fieldError(error, 'limit_value')}>
        {(p) => <input className="input" type="number" min={0} step={1} value={value} onChange={(e) => setValue(e.target.value)} {...p} />}
      </Field>
      <Field label="Sumber">{(p) => <select className="select" value={source} onChange={(e) => setSource(e.target.value)} {...p}>{Object.entries(sourceLabels).filter(([k]) => k !== 'BUNDLE').map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>}</Field>
    </FormModal>
  )
}
