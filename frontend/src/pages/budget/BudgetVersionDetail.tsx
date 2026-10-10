import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { fieldMessage, useAct } from '../operational/payables/messages'
import { Money, ReadOnlyNotice, Timeline } from '../operational/shared'
import { LineAddDialog, LineEditDialog, LineRemoveDialog } from './BudgetLineDialogs'
import { useFiscalPeriods, windowText } from './data'
import type { BudgetLine, BudgetVersion } from './types'

const PAGE = 100

export default function BudgetVersionDetail() {
  const { id, versionId } = useParams()
  const version = useResource(async () => (await api.get<BudgetVersion>(`${API}/budget-versions/${versionId}`)).data, [versionId])

  if (version.loading && !version.data) return <Loading />
  if (version.error || !version.data) return <ErrorNotice error={version.error} onRetry={version.reload} />
  return <VersionView version={version.data} budgetId={id} reload={version.reload} />
}

type Dialog = null | 'add' | 'meta' | 'reject' | 'cancel' | 'activate'

function VersionView({ version, budgetId, reload }: { version: BudgetVersion; budgetId: string | undefined; reload: () => void }) {
  const toast = useToast()
  const access = useModuleAccess(MODULES.budget)
  const action = useAct()
  const budget = version.budget
  const { periods, unavailable } = useFiscalPeriods(budget?.fiscal_year_id)
  const [dialog, setDialog] = useState<Dialog>(null)
  const [editing, setEditing] = useState<BudgetLine | null>(null)
  const [removing, setRemoving] = useState<BudgetLine | null>(null)
  const [f, setF] = useState({ q: '', period: '' })
  const [limit, setLimit] = useState(PAGE)

  const manage = access.canChange('accounting.budget.manage')
  const submit = access.canChange('accounting.budget.submit')
  const approve = access.canChange('accounting.budget.approve')
  const draft = version.status === 'DRAFT'
  const lines = useMemo(() => version.lines ?? [], [version.lines])
  const shown = useMemo(() => {
    const q = f.q.trim().toLowerCase()
    return lines.filter((l) => (!f.period || l.accounting_period_id === f.period) && (!q || `${l.account?.code ?? ''} ${l.account?.name ?? ''} ${l.description ?? ''}`.toLowerCase().includes(q)))
  }, [lines, f])
  const sod = version.sod ?? { approve: false, post: false }
  const base = `${API}/budget-versions/${version.id}`
  const backTo = `/app/akuntansi/anggaran/${budget?.id ?? budgetId}`

  const done = (message: string) => () => {
    setDialog(null)
    setEditing(null)
    setRemoving(null)
    toast.success(message)
    reload()
  }

  async function step(path: string, message: string) {
    const r = await action.run(async () => (await api.post(`${base}/${path}`)).data)
    if (r.ok) done(message)()
  }

  return (
    <>
      <PageHeader
        title={`Versi ${version.version_number} · ${version.label}`}
        description={<>{budget ? <Link to={backTo}>{budget.code} · {budget.name}</Link> : null} · <StatusBadge status={version.status} /></>}
        actions={
          <>
            {manage && draft && <Button variant="primary" onClick={() => setDialog('add')}>Tambah baris</Button>}
            {manage && draft && <Button onClick={() => setDialog('meta')}>Ubah label</Button>}
            {submit && draft && <Button variant="primary" loading={action.busy} onClick={() => void step('submit', 'Versi diajukan untuk persetujuan.')}>Ajukan</Button>}
            {approve && version.status === 'SUBMITTED' && <Button variant="primary" disabled={!sod.approve} loading={action.busy} onClick={() => void step('approve', 'Versi disetujui.')}>Setujui</Button>}
            {approve && version.status === 'SUBMITTED' && <Button disabled={!sod.approve} onClick={() => setDialog('reject')}>Tolak</Button>}
            {approve && version.status === 'APPROVED' && <Button variant="primary" onClick={() => setDialog('activate')}>Aktifkan</Button>}
            {manage && version.status === 'REJECTED' && <Button loading={action.busy} onClick={() => void step('reopen', 'Versi dibuka kembali sebagai draf.')}>Jadikan draf</Button>}
            {manage && ['DRAFT', 'SUBMITTED', 'REJECTED', 'APPROVED'].includes(version.status) && <Button variant="danger" onClick={() => setDialog('cancel')}>Batalkan versi</Button>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      {action.error != null && dialog === null && <ErrorNotice error={action.error} />}
      {version.status === 'SUBMITTED' && !sod.approve && approve && <Banner tone="info">Pemisahan tugas: Anda tidak dapat menyetujui versi yang Anda siapkan atau ajukan sendiri.</Banner>}
      {version.status === 'REJECTED' && version.reject_reason && <Banner tone="warn">Ditolak: {version.reject_reason}</Banner>}
      {version.status === 'CANCELLED' && version.cancel_reason && <Banner tone="info">Dibatalkan: {version.cancel_reason}</Banner>}
      {version.status === 'APPROVED' && budget?.status !== 'ACTIVE' && <Banner tone="info">Versi ini disetujui. Buka anggarannya terlebih dulu agar versi dapat diaktifkan.</Banner>}
      {!version.lines_complete && <Banner tone="warn">Cakupan data Anda hanya menampilkan sebagian baris versi ini, sehingga total di bawah bukan total seluruh versi.</Banner>}

      <Card>
        <dl className="facts">
          <div><dt>Anggaran</dt><dd>{budget ? `${budget.code} · ${budget.name}` : '—'}</dd></div>
          <div><dt>Tahun fiskal</dt><dd>{budget?.fiscal_year ? `${budget.fiscal_year.code} · ${budget.fiscal_year.name}` : '—'}</dd></div>
          <div><dt>Mata uang</dt><dd>{budget?.currency ?? '—'}</dd></div>
          <div><dt>Berlaku</dt><dd>{windowText(version)}</dd></div>
          <div><dt>Jumlah baris</dt><dd>{lines.length}</dd></div>
          <div><dt>Total anggaran</dt><dd><Money value={version.lines_total} strong /></dd></div>
          <div><dt>Dibuat oleh</dt><dd>{version.creator?.name ?? '—'}</dd></div>
          <div className="wide"><dt>Keterangan</dt><dd>{version.description ?? '—'}</dd></div>
        </dl>
      </Card>

      <Card title="Baris anggaran" flush>
        <div className="card-body">
          <div className="filters">
            <input className="input" type="search" aria-label="Cari akun" placeholder="Cari akun atau keterangan" value={f.q} onChange={(e) => { setF((s) => ({ ...s, q: e.target.value })); setLimit(PAGE) }} />
            {periods.length > 0 && (
              <select className="select" aria-label="Periode" value={f.period} onChange={(e) => { setF((s) => ({ ...s, period: e.target.value })); setLimit(PAGE) }}>
                <option value="">Semua periode</option>
                {periods.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
              </select>
            )}
          </div>
        </div>
        {lines.length === 0 ? (
          <EmptyState title="Belum ada baris">{manage && draft ? 'Tambahkan baris per akun dan periode, lalu ajukan versi ini.' : 'Versi ini tidak memiliki baris yang dapat Anda lihat.'}</EmptyState>
        ) : shown.length === 0 ? (
          <EmptyState title="Tidak ada baris yang cocok">Ubah kata pencarian atau periode.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Baris anggaran"
              rows={shown.slice(0, limit)}
              rowKey={(l) => l.id}
              scroll
              columns={[
                { header: 'Akun', primary: true, cell: (l) => <><span className="mono">{l.account?.code}</span> · {l.account?.name}{l.description && <div className="muted">{l.description}</div>}</> },
                { header: 'Periode', cell: (l) => l.period?.code ?? '—' },
                { header: 'Dimensi', cell: (l) => dimensions(l) },
                { header: 'Jumlah', align: 'right', cell: (l) => <Money value={l.amount} /> },
                ...(manage && draft
                  ? [{ header: 'Aksi', actions: true, cell: (l: BudgetLine) => (
                      <>
                        <Button size="sm" onClick={() => setEditing(l)}>Ubah</Button>
                        <Button size="sm" variant="danger" onClick={() => setRemoving(l)}>Hapus</Button>
                      </>
                    ) }]
                  : []),
              ]}
            />
            {shown.length > limit && (
              <div className="card-body"><Button onClick={() => setLimit((n) => n + PAGE)}>Tampilkan lebih banyak ({shown.length - limit} lagi)</Button></div>
            )}
          </>
        )}
        {unavailable && draft && manage && <div className="card-body"><span className="muted">Daftar periode tidak dapat dimuat (butuh izin melihat periode akuntansi), sehingga baris belum dapat ditambahkan dari sini.</span></div>}
      </Card>

      <Card title="Riwayat"><Timeline transitions={version.transitions} /></Card>

      {dialog === 'add' && <LineAddDialog version={version} periods={periods} onClose={() => setDialog(null)} onDone={done('Baris anggaran disimpan.')} />}
      {editing && <LineEditDialog version={version} line={editing} onClose={() => setEditing(null)} onDone={done('Baris diubah.')} />}
      {removing && <LineRemoveDialog version={version} line={removing} onClose={() => setRemoving(null)} onDone={done('Baris dihapus.')} />}
      {dialog === 'meta' && <MetaDialog version={version} onClose={() => setDialog(null)} onDone={done('Versi disimpan.')} />}
      {dialog === 'reject' && <ReasonDialog base={base} path="reject" title="Tolak versi" confirm="Tolak" message="Versi dikembalikan ke penyusun beserta alasan penolakan." onClose={() => setDialog(null)} onDone={done('Versi ditolak.')} />}
      {dialog === 'cancel' && <ReasonDialog base={base} path="cancel" title="Batalkan versi" confirm="Batalkan versi" message="Versi yang dibatalkan tidak dapat diaktifkan lagi dan tetap tersimpan sebagai riwayat." onClose={() => setDialog(null)} onDone={done('Versi dibatalkan.')} />}
      {dialog === 'activate' && budget && <ActivateDialog base={base} fiscalYear={budget.fiscal_year ?? null} onClose={() => setDialog(null)} onDone={done('Versi diaktifkan.')} />}
    </>
  )
}

function dimensions(l: BudgetLine) {
  const parts = [l.branch?.name, l.business_unit?.name, l.cost_center?.name].filter(Boolean)
  return parts.length > 0 ? parts.join(' · ') : <span className="muted">Semua</span>
}

function MetaDialog({ version, onClose, onDone }: { version: BudgetVersion; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const [f, setF] = useState({ label: version.label, description: version.description ?? '' })
  async function save() {
    const r = await run(async () => (await api.patch(`${API}/budget-versions/${version.id}`, { label: f.label.trim(), description: f.description.trim() || null })).data)
    if (r.ok) onDone()
  }
  return (
    <FormModal title="Ubah versi" busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Label" error={fieldMessage(error, 'label')}>{(p) => <input className="input" required maxLength={100} value={f.label} onChange={(e) => setF((s) => ({ ...s, label: e.target.value }))} {...p} />}</Field>
        <Field label="Keterangan" error={fieldMessage(error, 'description')} full>{(p) => <textarea className="textarea" maxLength={500} value={f.description} onChange={(e) => setF((s) => ({ ...s, description: e.target.value }))} {...p} />}</Field>
      </div>
    </FormModal>
  )
}

function ReasonDialog({ base, path, title, confirm, message, onClose, onDone }: { base: string; path: string; title: string; confirm: string; message: string; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  return (
    <ConfirmDialog
      title={title}
      confirmLabel={confirm}
      danger
      reasonRequired
      busy={busy}
      error={error}
      message={message}
      onClose={onClose}
      onConfirm={async (reason) => {
        const r = await run(() => api.post(`${base}/${path}`, { reason }))
        if (r.ok) onDone()
      }}
    />
  )
}

/** Activate an approved version. Left empty the server picks the date (the fiscal year start, or the day after the version in force began). */
function ActivateDialog({ base, fiscalYear, onClose, onDone }: { base: string; fiscalYear: { start_date: string; end_date: string } | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const [from, setFrom] = useState('')
  async function save() {
    const r = await run(async () => (await api.post(`${base}/activate`, from ? { effective_from: from } : {})).data)
    if (r.ok) onDone()
  }
  return (
    <FormModal title="Aktifkan versi" submitLabel="Aktifkan" busy={busy} error={error} onSubmit={() => void save()} onClose={onClose}>
      <p style={{ marginTop: 0 }}>Versi ini menjadi acuan laporan Anggaran vs Aktual. Versi yang sedang berlaku digantikan sehari sebelum tanggal berlaku versi baru.</p>
      <Field label="Berlaku mulai" hint={fiscalYear ? `Kosongkan agar sistem memilih tanggalnya. Harus di dalam tahun fiskal (${formatDate(fiscalYear.start_date)} – ${formatDate(fiscalYear.end_date)}).` : 'Kosongkan agar sistem memilih tanggalnya.'} error={fieldMessage(error, 'effective_from')}>
        {(p) => <input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}
