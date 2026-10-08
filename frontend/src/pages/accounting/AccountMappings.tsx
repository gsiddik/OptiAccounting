import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Badge, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader } from '../../components/ui'
import { useAccountingAccess, type AccountMapping, type AccountRole } from '../../lib/accounting'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction, useResource } from '../../lib/hooks'
import { accountLabel, useAccounts, useDimensions } from './data'

type Overview = { roles: AccountRole[]; mappings: AccountMapping[] }
type Editing = { role: AccountRole; override: boolean } | null

export default function AccountMappings() {
  const toast = useToast()
  const { canChange } = useAccountingAccess()
  const manage = canChange('accounting.account_mapping.manage')
  const overview = useResource(async () => (await api.get<Overview>('/app/accounting/account-mappings')).data, [])
  const { accounts } = useAccounts()
  const { catalog } = useDimensions()
  const [editing, setEditing] = useState<Editing>(null)
  const [removing, setRemoving] = useState<AccountMapping | null>(null)

  if (overview.loading && !overview.data) return <Loading />
  if (overview.error || !overview.data) return <ErrorNotice error={overview.error} onRetry={overview.reload} />
  const { roles, mappings } = overview.data
  const active = mappings.filter((m) => m.status === 'ACTIVE')
  const defaults = new Map(active.filter((m) => !m.branch_id && !m.business_unit_id).map((m) => [m.account_role, m]))
  const overrides = active.filter((m) => m.branch_id || m.business_unit_id)
  const roleName = (code: string) => roles.find((r) => r.code === code)?.name ?? code
  const scopeName = (m: AccountMapping) => {
    const unit = catalog.business_units.find((u) => u.id === m.business_unit_id)
    const branch = catalog.branches.find((b) => b.id === m.branch_id)
    return unit ? `Unit bisnis ${unit.code}` : branch ? `Cabang ${branch.code}` : m.business_unit_id ? 'Unit bisnis' : 'Cabang'
  }

  return (
    <>
      <PageHeader title="Pemetaan akun" description="Menghubungkan peran akun pada aturan posting ke akun di bagan akun Anda. Jurnal yang sudah diposting menyimpan akun saat itu, jadi mengubah pemetaan tidak menulis ulang riwayat." />

      <Card title="Peran akun" flush>
        <DataTable
          caption="Peran akun"
          rows={roles}
          rowKey={(r) => r.code}
          columns={[
            { header: 'Peran', primary: true, cell: (r) => <><strong>{r.name}</strong><div className="muted">{r.description}</div></> },
            { header: 'Akun', cell: (r) => { const m = defaults.get(r.code); return m?.account ? accountLabel(m.account) : <Badge tone={r.used_by_published_rule ? 'bad' : 'neutral'}>Belum dipetakan</Badge> } },
            { header: 'Dipakai aturan', cell: (r) => (r.used_by_published_rule ? <Badge tone="info">Ya</Badge> : <span className="muted">Tidak</span>) },
            { header: 'Aksi', actions: true, cell: (r) => manage && (
              <div className="actions">
                <Button size="sm" variant={defaults.has(r.code) ? 'secondary' : 'primary'} onClick={() => setEditing({ role: r, override: false })}>{defaults.has(r.code) ? 'Ubah akun' : 'Petakan'}</Button>
                <Button size="sm" onClick={() => setEditing({ role: r, override: true })}>Tambah pengecualian</Button>
              </div>
            ) },
          ]}
        />
      </Card>

      <Card title="Pengecualian per cabang atau unit bisnis" flush>
        {overrides.length === 0 ? (
          <EmptyState title="Tidak ada pengecualian">Pemetaan umum berlaku untuk semua cabang dan unit bisnis.</EmptyState>
        ) : (
          <DataTable
            caption="Pengecualian pemetaan"
            rows={overrides}
            rowKey={(m) => m.id}
            columns={[
              { header: 'Peran', primary: true, cell: (m) => roleName(m.account_role) },
              { header: 'Cakupan', cell: scopeName },
              { header: 'Akun', cell: (m) => (m.account ? accountLabel(m.account) : m.account_id) },
              { header: 'Aksi', actions: true, cell: (m) => manage && <Button size="sm" variant="danger" onClick={() => setRemoving(m)}>Nonaktifkan</Button> },
            ]}
          />
        )}
      </Card>

      {editing && <MappingForm editing={editing} accounts={accounts} catalog={catalog} current={defaults.get(editing.role.code)?.account_id ?? ''} onClose={() => setEditing(null)} onDone={() => { setEditing(null); overview.reload(); toast.success('Pemetaan disimpan.') }} />}
      {removing && <RemoveDialog mapping={removing} onClose={() => setRemoving(null)} onDone={() => { setRemoving(null); overview.reload(); toast.success('Pengecualian dinonaktifkan.') }} />}
    </>
  )
}

function MappingForm({ editing, accounts, catalog, current, onClose, onDone }: { editing: NonNullable<Editing>; accounts: ReturnType<typeof useAccounts>['accounts']; catalog: ReturnType<typeof useDimensions>['catalog']; current: string; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ account_id: editing.override ? '' : current, branch_id: '', business_unit_id: '' })
  const options = accounts.filter((a) => a.status === 'ACTIVE' && a.is_postable)
  const units = catalog.business_units.filter((u) => !f.branch_id || u.branch_id === null || u.branch_id === f.branch_id)

  async function submit() {
    const r = await run(() => api.put('/app/accounting/account-mappings', { account_role: editing.role.code, account_id: f.account_id, branch_id: f.branch_id || null, business_unit_id: f.business_unit_id || null }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={`${editing.override ? 'Pengecualian' : 'Pemetaan'} ${editing.role.name}`} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Akun" error={fieldError(error, 'account_id')} full hint="Hanya akun posting yang aktif.">
          {(p) => (
            <select className="select" required value={f.account_id} onChange={(e) => setF((s) => ({ ...s, account_id: e.target.value }))} {...p}>
              <option value="">Pilih akun…</option>
              {options.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
            </select>
          )}
        </Field>
        {editing.override && (
          <>
            <Field label="Cabang" error={fieldError(error, 'branch_id')}>
              {(p) => (
                <select className="select" value={f.branch_id} onChange={(e) => setF((s) => ({ ...s, branch_id: e.target.value, business_unit_id: '' }))} {...p}>
                  <option value="">Semua cabang</option>
                  {catalog.branches.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
                </select>
              )}
            </Field>
            <Field label="Unit bisnis" error={fieldError(error, 'business_unit_id')} hint="Unit bisnis lebih spesifik daripada cabang.">
              {(p) => (
                <select className="select" value={f.business_unit_id} onChange={(e) => setF((s) => ({ ...s, business_unit_id: e.target.value }))} {...p}>
                  <option value="">Semua unit bisnis</option>
                  {units.map((u) => <option key={u.id} value={u.id}>{u.code} · {u.name}</option>)}
                </select>
              )}
            </Field>
          </>
        )}
      </div>
    </FormModal>
  )
}

function RemoveDialog({ mapping, onClose, onDone }: { mapping: AccountMapping; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  return (
    <ConfirmDialog
      title="Nonaktifkan pengecualian"
      confirmLabel="Nonaktifkan"
      danger
      busy={busy}
      error={error}
      message="Setelah dinonaktifkan, peran ini memakai pemetaan umum untuk cakupan tersebut. Jurnal yang sudah diposting tidak berubah."
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`/app/accounting/account-mappings/${mapping.id}/deactivate`))
        if (r.ok) onDone()
      }}
    />
  )
}
