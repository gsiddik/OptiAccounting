import { useMemo, useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Badge, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, StatusBadge, Tabs } from '../../components/ui'
import { downloadCsv, useAccountingAccess, type Account, type AccountType, type CostCenter } from '../../lib/accounting'
import { accountTypeLabels } from '../../lib/accountingLabels'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction, useDebounced, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { useDimensions } from './data'

type Tab = 'accounts' | 'cost-centers'
const TYPES = Object.keys(accountTypeLabels) as AccountType[]
const defaultSide = (t: AccountType): 'DEBIT' | 'CREDIT' => (t === 'ASSET' || t === 'EXPENSE' ? 'DEBIT' : 'CREDIT')

export default function ChartOfAccounts() {
  const [tab, setTab] = useState<Tab>('accounts')
  return (
    <>
      <PageHeader title="Bagan akun" description="Akun yang sudah dipakai jurnal dinonaktifkan, tidak dihapus. Akun kontrol hanya menerima posting dari sub-buku." />
      <Card flush>
        <Tabs tabs={[{ id: 'accounts', label: 'Akun' }, { id: 'cost-centers', label: 'Pusat biaya' }]} value={tab} onChange={setTab} />
        {tab === 'accounts' ? <Accounts /> : <CostCenters />}
      </Card>
    </>
  )
}

/** Depth-first order: a parent is followed by its children. A child whose parent is filtered out becomes a root. */
function arrange(accounts: Account[]): { account: Account; depth: number }[] {
  const ids = new Set(accounts.map((a) => a.id))
  const children = new Map<string | null, Account[]>()
  for (const a of accounts) {
    const key = a.parent_id && ids.has(a.parent_id) ? a.parent_id : null
    children.set(key, [...(children.get(key) ?? []), a])
  }
  const out: { account: Account; depth: number }[] = []
  const walk = (parent: string | null, depth: number) => {
    for (const a of children.get(parent) ?? []) {
      out.push({ account: a, depth })
      walk(a.id, depth + 1)
    }
  }
  walk(null, 0)
  return out
}

function Accounts() {
  const { canChange, can } = useAccountingAccess()
  const toast = useToast()
  const manage = canChange('accounting.coa.manage')
  const [filters, setFilters] = useState({ q: '', type: '', status: '' })
  const q = useDebounced(filters.q)
  const accounts = useResource(async () => {
    const params: Record<string, string> = {}
    if (q) params.q = q
    if (filters.type) params.type = filters.type
    if (filters.status) params.status = filters.status
    return (await api.get<{ data: Account[] }>('/app/accounting/accounts', { params })).data.data
  }, [q, filters.type, filters.status])
  const all = useResource(async () => (await api.get<{ data: Account[] }>('/app/accounting/accounts')).data.data, [])
  const [editing, setEditing] = useState<Account | 'new' | null>(null)
  const [toggling, setToggling] = useState<Account | null>(null)
  const [deleting, setDeleting] = useState<Account | null>(null)
  const [exportError, setExportError] = useState<unknown>(null)
  const rows = useMemo(() => arrange(accounts.data ?? []), [accounts.data])
  const refresh = () => { accounts.reload(); all.reload() }

  if (accounts.loading && !accounts.data) return <Loading />
  if (accounts.error || !accounts.data) return <ErrorNotice error={accounts.error} onRetry={accounts.reload} />
  const empty = (all.data?.length ?? 1) === 0

  return (
    <>
      <div className="card-body">
        <div className="filters">
          <input className="input" type="search" aria-label="Cari akun" placeholder="Cari kode atau nama" value={filters.q} onChange={(e) => setFilters((s) => ({ ...s, q: e.target.value }))} />
          <select className="select" aria-label="Tipe akun" value={filters.type} onChange={(e) => setFilters((s) => ({ ...s, type: e.target.value }))}>
            <option value="">Semua tipe</option>
            {TYPES.map((t) => <option key={t} value={t}>{accountTypeLabels[t]}</option>)}
          </select>
          <select className="select" aria-label="Status akun" value={filters.status} onChange={(e) => setFilters((s) => ({ ...s, status: e.target.value }))}>
            <option value="">Semua status</option>
            <option value="ACTIVE">Aktif</option>
            <option value="INACTIVE">Nonaktif</option>
          </select>
          <span className="spacer" />
          {can('accounting.report.export') && <Button size="sm" onClick={() => void downloadCsv('/app/accounting/accounts-export', {}, 'bagan-akun.csv').then(() => setExportError(null), setExportError)}>Ekspor CSV</Button>}
          {manage && <Button size="sm" variant="primary" onClick={() => setEditing('new')}>Akun baru</Button>}
        </div>
        {exportError != null && <p className="field"><span className="error">{describeError(exportError)}</span></p>}
      </div>

      {empty && manage && <TemplatePicker onApplied={refresh} />}
      {rows.length === 0 ? (
        <EmptyState title={empty ? 'Bagan akun masih kosong' : 'Tidak ada akun yang cocok'}>{empty ? 'Terapkan templat atau buat akun pertama.' : 'Ubah filter pencarian.'}</EmptyState>
      ) : (
        <DataTable
          caption="Bagan akun"
          rows={rows}
          rowKey={(r) => r.account.id}
          columns={[
            { header: 'Kode', primary: true, cell: (r) => <span className="mono" style={{ paddingLeft: r.depth * 18 }}>{r.account.code}</span> },
            { header: 'Nama', cell: (r) => <span style={{ fontWeight: r.account.is_postable ? 400 : 700 }}>{r.account.name}</span> },
            { header: 'Tipe', cell: (r) => accountTypeLabels[r.account.account_type] },
            { header: 'Saldo normal', cell: (r) => (r.account.normal_balance === 'DEBIT' ? 'Debit' : 'Kredit') },
            { header: 'Sifat', cell: (r) => <span className="chips">{!r.account.is_postable && <Badge tone="info">Induk</Badge>}{r.account.is_control && <Badge tone="warn">Kontrol</Badge>}</span> },
            { header: 'Status', cell: (r) => <StatusBadge status={r.account.status} /> },
            {
              header: 'Aksi',
              actions: true,
              cell: (r) => manage && (
                <div className="actions">
                  <Button size="sm" onClick={() => setEditing(r.account)}>Ubah</Button>
                  <Button size="sm" onClick={() => setToggling(r.account)}>{r.account.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
                  <Button size="sm" variant="danger" onClick={() => setDeleting(r.account)}>Hapus</Button>
                </div>
              ),
            },
          ]}
        />
      )}

      {editing && <AccountForm account={editing === 'new' ? null : editing} accounts={all.data ?? []} onClose={() => setEditing(null)} onDone={() => { setEditing(null); refresh(); toast.success('Akun disimpan.') }} />}
      {toggling && <StatusDialog account={toggling} onClose={() => setToggling(null)} onDone={() => { setToggling(null); refresh(); toast.success('Status akun diperbarui.') }} />}
      {deleting && <DeleteDialog account={deleting} onClose={() => setDeleting(null)} onDone={() => { setDeleting(null); refresh(); toast.success('Akun dihapus.') }} />}
    </>
  )
}

function TemplatePicker({ onApplied }: { onApplied: () => void }) {
  const toast = useToast()
  const templates = useResource(async () => (await api.get<{ data: { code: string; name: string; description: string | null }[] }>('/app/accounting/coa-templates')).data.data, [])
  const { busy, error, run } = useAction()
  const [code, setCode] = useState('')
  const options = templates.data ?? []
  const chosen = code || options[0]?.code || ''

  if (options.length === 0) return null
  async function apply() {
    const r = await run(() => api.post('/app/accounting/coa-templates/apply', { template: chosen }))
    if (r.ok) {
      toast.success('Templat bagan akun diterapkan.')
      onApplied()
    }
  }
  return (
    <div className="card-body template-picker">
      <strong>Mulai dari templat</strong>
      <span className="muted">{options.find((t) => t.code === chosen)?.description}</span>
      <div className="filters">
        <select className="select" aria-label="Templat bagan akun" value={chosen} onChange={(e) => setCode(e.target.value)}>
          {options.map((t) => <option key={t.code} value={t.code}>{t.name}</option>)}
        </select>
        <Button variant="primary" size="sm" loading={busy} onClick={() => void apply()}>Terapkan templat</Button>
      </div>
      {error != null && <ErrorNotice error={error} />}
    </div>
  )
}

function AccountForm({ account, accounts, onClose, onDone }: { account: Account | null; accounts: Account[]; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [f, setF] = useState({
    code: account?.code ?? '',
    name: account?.name ?? '',
    description: account?.description ?? '',
    account_type: (account?.account_type ?? 'ASSET') as AccountType,
    normal_balance: account?.normal_balance ?? 'DEBIT',
    parent_id: account?.parent_id ?? '',
    is_postable: account?.is_postable ?? true,
    is_control: account?.is_control ?? false,
  })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const parents = accounts.filter((a) => !a.is_postable && a.account_type === f.account_type && a.status === 'ACTIVE' && a.id !== account?.id)

  async function submit() {
    const body = {
      code: f.code.trim(), name: f.name.trim(), description: f.description.trim() || null, account_type: f.account_type, normal_balance: f.normal_balance,
      parent_id: f.parent_id || null, is_postable: f.is_postable, is_control: f.is_postable && f.is_control,
    }
    const r = await run(() => (account ? api.patch(`/app/accounting/accounts/${account.id}`, body) : api.post('/app/accounting/accounts', body)))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={account ? `Ubah akun ${account.code}` : 'Akun baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Kode" error={fieldError(error, 'code')} hint="Huruf, angka, titik, atau strip.">{(p) => <input className="input mono" required maxLength={30} value={f.code} onChange={set('code')} {...p} />}</Field>
        <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required maxLength={255} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Tipe" error={fieldError(error, 'account_type')}>
          {(p) => (
            <select className="select" value={f.account_type} onChange={(e) => setF((s) => ({ ...s, account_type: e.target.value as AccountType, normal_balance: defaultSide(e.target.value as AccountType), parent_id: '' }))} {...p}>
              {TYPES.map((t) => <option key={t} value={t}>{accountTypeLabels[t]}</option>)}
            </select>
          )}
        </Field>
        <Field label="Saldo normal" error={fieldError(error, 'normal_balance')}>
          {(p) => (
            <select className="select" value={f.normal_balance} onChange={set('normal_balance')} {...p}>
              <option value="DEBIT">Debit</option>
              <option value="CREDIT">Kredit</option>
            </select>
          )}
        </Field>
        <Field label="Akun induk" error={fieldError(error, 'parent_id')} full>
          {(p) => (
            <select className="select" value={f.parent_id} onChange={set('parent_id')} {...p}>
              <option value="">Tanpa induk</option>
              {parents.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
            </select>
          )}
        </Field>
        <Field label="Keterangan" error={fieldError(error, 'description')} full>{(p) => <input className="input" maxLength={500} value={f.description} onChange={set('description')} {...p} />}</Field>
        <label className="check"><input type="checkbox" checked={f.is_postable} onChange={(e) => setF((s) => ({ ...s, is_postable: e.target.checked }))} /> Akun posting (dapat dipakai pada baris jurnal)</label>
        <label className="check"><input type="checkbox" checked={f.is_postable && f.is_control} disabled={!f.is_postable} onChange={(e) => setF((s) => ({ ...s, is_control: e.target.checked }))} /> Akun kontrol (hanya dari sub-buku)</label>
      </div>
    </FormModal>
  )
}

function StatusDialog({ account, onClose, onDone }: { account: Account; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const next = account.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  return (
    <ConfirmDialog
      title={next === 'ACTIVE' ? 'Aktifkan akun' : 'Nonaktifkan akun'}
      confirmLabel={next === 'ACTIVE' ? 'Aktifkan' : 'Nonaktifkan'}
      danger={next === 'INACTIVE'}
      busy={busy}
      error={error}
      message={next === 'ACTIVE' ? `Aktifkan kembali ${account.code} · ${account.name}?` : `${account.code} · ${account.name} tidak dapat dipakai untuk jurnal baru. Riwayat jurnal tetap utuh.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`/app/accounting/accounts/${account.id}/status`, { status: next }))
        if (r.ok) onDone()
      }}
    />
  )
}

function DeleteDialog({ account, onClose, onDone }: { account: Account; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  return (
    <ConfirmDialog
      title="Hapus akun"
      confirmLabel="Hapus"
      danger
      busy={busy}
      error={error}
      message={`Hapus ${account.code} · ${account.name}? Hanya akun yang belum pernah dipakai yang dapat dihapus; akun yang sudah dipakai harus dinonaktifkan.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`/app/accounting/accounts/${account.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}

function CostCenters() {
  const { canChange } = useAccountingAccess()
  const toast = useToast()
  const manage = canChange('accounting.dimension.manage')
  const centers = useResource(async () => (await api.get<{ data: CostCenter[] }>('/app/accounting/cost-centers')).data.data, [])
  const { catalog } = useDimensions()
  const [editing, setEditing] = useState<CostCenter | 'new' | null>(null)
  const [toggling, setToggling] = useState<CostCenter | null>(null)

  if (centers.loading && !centers.data) return <Loading />
  if (centers.error || !centers.data) return <ErrorNotice error={centers.error} onRetry={centers.reload} />
  const name = (list: { id: string; code: string }[], id: string | null) => list.find((x) => x.id === id)?.code

  return (
    <>
      <div className="card-head">
        <span className="muted">Pusat biaya melengkapi cabang dan unit bisnis sebagai dimensi baris jurnal.</span>
        {manage && <Button size="sm" variant="primary" onClick={() => setEditing('new')}>Pusat biaya baru</Button>}
      </div>
      {centers.data.length === 0 ? (
        <EmptyState title="Belum ada pusat biaya" />
      ) : (
        <DataTable
          caption="Pusat biaya"
          rows={centers.data}
          rowKey={(c) => c.id}
          columns={[
            { header: 'Kode', primary: true, cell: (c) => <span className="mono">{c.code}</span> },
            { header: 'Nama', cell: (c) => <>{c.name}{c.description && <div className="muted">{c.description}</div>}</> },
            { header: 'Cabang', cell: (c) => name(catalog.branches, c.branch_id) ?? <span className="muted">—</span> },
            { header: 'Unit bisnis', cell: (c) => name(catalog.business_units, c.business_unit_id) ?? <span className="muted">—</span> },
            { header: 'Status', cell: (c) => <StatusBadge status={c.status} /> },
            { header: 'Aksi', actions: true, cell: (c) => manage && (
              <div className="actions">
                <Button size="sm" onClick={() => setEditing(c)}>Ubah</Button>
                <Button size="sm" onClick={() => setToggling(c)}>{c.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
              </div>
            ) },
          ]}
        />
      )}
      {editing && <CostCenterForm center={editing === 'new' ? null : editing} catalog={catalog} onClose={() => setEditing(null)} onDone={() => { setEditing(null); centers.reload(); toast.success('Pusat biaya disimpan.') }} />}
      {toggling && <CostCenterStatus center={toggling} onClose={() => setToggling(null)} onDone={() => { setToggling(null); centers.reload(); toast.success('Status pusat biaya diperbarui.') }} />}
    </>
  )
}

function CostCenterForm({ center, catalog, onClose, onDone }: { center: CostCenter | null; catalog: ReturnType<typeof useDimensions>['catalog']; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ code: center?.code ?? '', name: center?.name ?? '', description: center?.description ?? '', branch_id: center?.branch_id ?? '', business_unit_id: center?.business_unit_id ?? '' })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  async function submit() {
    const body = { name: f.name.trim(), description: f.description.trim() || null, branch_id: f.branch_id || null, business_unit_id: f.business_unit_id || null }
    const r = await run(() => (center ? api.patch(`/app/accounting/cost-centers/${center.id}`, body) : api.post('/app/accounting/cost-centers', { ...body, code: f.code.trim() })))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={center ? `Ubah pusat biaya ${center.code}` : 'Pusat biaya baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Kode" error={fieldError(error, 'code')} hint={center ? 'Kode tidak dapat diubah.' : undefined}>{(p) => <input className="input mono" required maxLength={30} disabled={!!center} value={f.code} onChange={set('code')} {...p} />}</Field>
        <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required maxLength={255} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Cabang" error={fieldError(error, 'branch_id')}>
          {(p) => (
            <select className="select" value={f.branch_id} onChange={set('branch_id')} {...p}>
              <option value="">Tanpa cabang</option>
              {catalog.branches.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
            </select>
          )}
        </Field>
        <Field label="Unit bisnis" error={fieldError(error, 'business_unit_id')}>
          {(p) => (
            <select className="select" value={f.business_unit_id} onChange={set('business_unit_id')} {...p}>
              <option value="">Tanpa unit bisnis</option>
              {catalog.business_units.map((u) => <option key={u.id} value={u.id}>{u.code} · {u.name}</option>)}
            </select>
          )}
        </Field>
        <Field label="Keterangan" error={fieldError(error, 'description')} full>{(p) => <input className="input" maxLength={500} value={f.description} onChange={set('description')} {...p} />}</Field>
      </div>
    </FormModal>
  )
}

function CostCenterStatus({ center, onClose, onDone }: { center: CostCenter; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const next = center.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  return (
    <ConfirmDialog
      title={next === 'ACTIVE' ? 'Aktifkan pusat biaya' : 'Nonaktifkan pusat biaya'}
      confirmLabel={next === 'ACTIVE' ? 'Aktifkan' : 'Nonaktifkan'}
      danger={next === 'INACTIVE'}
      busy={busy}
      error={error}
      message={`${center.code} · ${center.name}`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`/app/accounting/cost-centers/${center.id}/status`, { status: next }))
        if (r.ok) onDone()
      }}
    />
  )
}
