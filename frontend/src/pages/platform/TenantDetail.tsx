import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { AuditTable } from '../../components/AuditTable'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Field, Loading, PageHeader, StatusBadge, Tabs } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDateTime, TIMEZONES } from '../../lib/format'
import { fieldError } from '../../lib/forms'
import { useAction, useResource } from '../../lib/hooks'
import type { Tenant, TenantStatus } from '../../lib/types'
import { CapacityTab, FeatureTab, MemberTab, ModuleTab, SubscriptionTab } from './tenant-tabs'

const TRANSITIONS: Record<TenantStatus, { to: TenantStatus; label: string; danger?: boolean; hint: string }[]> = {
  DRAFT: [{ to: 'ACTIVE', label: 'Aktifkan', hint: 'Tenant dapat digunakan oleh anggotanya.' }],
  ACTIVE: [
    { to: 'SUSPENDED', label: 'Tangguhkan', hint: 'Seluruh sesi tenant diakhiri dan anggotanya tidak dapat masuk.' },
    { to: 'INACTIVE', label: 'Nonaktifkan', hint: 'Seluruh sesi tenant diakhiri. Data tetap tersimpan.' },
  ],
  SUSPENDED: [
    { to: 'ACTIVE', label: 'Aktifkan kembali', hint: 'Anggota dapat masuk lagi.' },
    { to: 'INACTIVE', label: 'Nonaktifkan', hint: 'Data tetap tersimpan.' },
  ],
  INACTIVE: [
    { to: 'ACTIVE', label: 'Aktifkan kembali', hint: 'Anggota dapat masuk lagi.' },
    { to: 'TERMINATED', label: 'Hentikan permanen', danger: true, hint: 'Tidak dapat dibatalkan. Data tetap tersimpan tetapi tenant tidak dapat diaktifkan lagi.' },
  ],
  TERMINATED: [],
}

type Tab = 'summary' | 'members' | 'subscription' | 'modules' | 'features' | 'capacity' | 'audit'

export default function TenantDetail() {
  const { id = '' } = useParams()
  const { can } = useCapabilities()
  const [tab, setTab] = useState<Tab>('summary')
  const tenant = useResource(async () => (await api.get<Tenant>(`/platform/tenants/${id}`)).data, [id])

  if (tenant.loading && !tenant.data) return <Loading />
  if (tenant.error || !tenant.data) return <ErrorNotice error={tenant.error} onRetry={tenant.reload} />
  const t = tenant.data

  const tabs: { id: Tab; label: string; show: boolean }[] = [
    { id: 'summary', label: 'Ringkasan', show: true },
    { id: 'members', label: 'Anggota', show: can('platform.membership.view') },
    { id: 'subscription', label: 'Langganan', show: can('platform.subscription.view') },
    { id: 'modules', label: 'Modul', show: can('platform.entitlement.view') },
    { id: 'features', label: 'Fitur', show: can('platform.entitlement.view') },
    { id: 'capacity', label: 'Kapasitas', show: can('platform.entitlement.view') },
    { id: 'audit', label: 'Audit', show: can('platform.audit.view') },
  ]

  return (
    <>
      <PageHeader
        title={t.name}
        description={<><Link to="/platform/tenants">← Semua tenant</Link> · <span className="mono">{t.code}</span> · <StatusBadge status={t.status} /></>}
      />
      <Tabs tabs={tabs.filter((x) => x.show)} value={tab} onChange={setTab} />
      <Card flush>
        {tab === 'summary' && <Summary tenant={t} onChanged={tenant.reload} />}
        {tab === 'members' && <MemberTab tenantId={t.id} />}
        {tab === 'subscription' && <SubscriptionTab tenant={t} />}
        {tab === 'modules' && <ModuleTab tenant={t} />}
        {tab === 'features' && <FeatureTab tenant={t} />}
        {tab === 'capacity' && <CapacityTab tenant={t} />}
        {tab === 'audit' && <AuditTable endpoint="/platform/audit-logs" params={{ tenant_id: t.id }} />}
      </Card>
    </>
  )
}

function Summary({ tenant, onChanged }: { tenant: Tenant; onChanged: () => void }) {
  const { can } = useCapabilities()
  const toast = useToast()
  const [editing, setEditing] = useState(false)
  const [moving, setMoving] = useState<(typeof TRANSITIONS)[TenantStatus][number] | null>(null)
  const status = useAction()

  return (
    <div className="card-body stack">
      {tenant.status === 'DRAFT' && <Banner tone="info">Tenant masih berstatus draf. Atur langganan, lalu aktifkan agar anggotanya dapat masuk.</Banner>}
      <dl className="facts">
        <div><dt>Nama hukum</dt><dd>{tenant.legal_name ?? '—'}</dd></div>
        <div><dt>Zona waktu</dt><dd>{tenant.timezone}</dd></div>
        <div><dt>Bahasa</dt><dd>{tenant.default_locale}</dd></div>
        <div><dt>Mata uang</dt><dd>{tenant.default_currency}</dd></div>
        <div><dt>Kontak</dt><dd>{tenant.contact_name ?? '—'}</dd></div>
        <div><dt>E-mail kontak</dt><dd>{tenant.contact_email ?? '—'}</dd></div>
        <div><dt>Telepon</dt><dd>{tenant.contact_phone ?? '—'}</dd></div>
        <div><dt>Dibuat</dt><dd>{formatDateTime(tenant.created_at)}</dd></div>
      </dl>
      <div className="actions">
        {can('platform.tenant.update') && <Button onClick={() => setEditing(true)}>Ubah data</Button>}
        {can('platform.tenant.status.manage') &&
          TRANSITIONS[tenant.status].map((m) => (
            <Button key={m.to} variant={m.danger ? 'danger' : 'secondary'} onClick={() => setMoving(m)}>{m.label}</Button>
          ))}
      </div>
      {editing && <EditTenant tenant={tenant} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); onChanged(); toast.success('Data tenant disimpan.') }} />}
      {moving && (
        <ConfirmDialog
          title={`${moving.label}: ${tenant.name}`}
          message={moving.hint}
          confirmLabel={moving.label}
          danger={moving.danger}
          reasonRequired
          busy={status.busy}
          error={status.error}
          onClose={() => setMoving(null)}
          onConfirm={async (reason) => {
            const r = await status.run(() => api.post(`/platform/tenants/${tenant.id}/status`, { status: moving.to, reason }))
            if (r.ok) { setMoving(null); onChanged(); toast.success('Status tenant diperbarui.') }
          }}
        />
      )}
    </div>
  )
}

function EditTenant({ tenant, onClose, onSaved }: { tenant: Tenant; onClose: () => void; onSaved: () => void }) {
  const { busy, error, run } = useAction()
  const [f, setF] = useState({
    name: tenant.name, legal_name: tenant.legal_name ?? '', timezone: tenant.timezone, default_currency: tenant.default_currency,
    contact_name: tenant.contact_name ?? '', contact_email: tenant.contact_email ?? '', contact_phone: tenant.contact_phone ?? '',
  })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const nullable = (v: string) => (v.trim() === '' ? null : v.trim())

  async function save() {
    const r = await run(() => api.patch(`/platform/tenants/${tenant.id}`, {
      name: f.name.trim(), legal_name: nullable(f.legal_name), timezone: f.timezone, default_currency: f.default_currency.trim().toUpperCase(),
      contact_name: nullable(f.contact_name), contact_email: nullable(f.contact_email), contact_phone: nullable(f.contact_phone),
    }))
    if (r.ok) onSaved()
  }

  return (
    <FormModal title="Ubah data tenant" wide busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Nama hukum" error={fieldError(error, 'legal_name')}>{(p) => <input className="input" value={f.legal_name} onChange={set('legal_name')} {...p} />}</Field>
        <Field label="Zona waktu" error={fieldError(error, 'timezone')}>
          {(p) => <select className="select" value={f.timezone} onChange={set('timezone')} {...p}>{[...new Set([f.timezone, ...TIMEZONES])].map((z) => <option key={z}>{z}</option>)}</select>}
        </Field>
        <Field label="Mata uang" error={fieldError(error, 'default_currency')}>{(p) => <input className="input mono" maxLength={3} value={f.default_currency} onChange={set('default_currency')} {...p} />}</Field>
        <Field label="Nama kontak" error={fieldError(error, 'contact_name')}>{(p) => <input className="input" value={f.contact_name} onChange={set('contact_name')} {...p} />}</Field>
        <Field label="E-mail kontak" error={fieldError(error, 'contact_email')}>{(p) => <input className="input" type="email" value={f.contact_email} onChange={set('contact_email')} {...p} />}</Field>
        <Field label="Telepon" error={fieldError(error, 'contact_phone')}>{(p) => <input className="input" value={f.contact_phone} onChange={set('contact_phone')} {...p} />}</Field>
      </div>
    </FormModal>
  )
}
