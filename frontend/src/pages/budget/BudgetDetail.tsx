import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { ReadOnlyNotice, Timeline } from '../operational/shared'
import { BudgetForm, BudgetStatusDialog, VersionForm } from './BudgetDialogs'
import { windowText } from './data'
import type { Budget } from './types'

export default function BudgetDetail() {
  const { id } = useParams()
  const budget = useResource(async () => (await api.get<Budget>(`${API}/budgets/${id}`)).data, [id])

  if (budget.loading && !budget.data) return <Loading />
  if (budget.error || !budget.data) return <ErrorNotice error={budget.error} onRetry={budget.reload} />
  return <BudgetView budget={budget.data} reload={budget.reload} />
}

type Dialog = null | 'edit' | 'version' | 'open' | 'close' | 'cancel'

function BudgetView({ budget, reload }: { budget: Budget; reload: () => void }) {
  const navigate = useNavigate()
  const toast = useToast()
  const access = useModuleAccess(MODULES.budget)
  const [dialog, setDialog] = useState<Dialog>(null)
  const versions = budget.versions ?? []
  const manage = access.canChange('accounting.budget.manage')
  const approve = access.canChange('accounting.budget.approve')
  const editable = budget.status === 'DRAFT' || budget.status === 'ACTIVE'
  // The API refuses to cancel a budget that ever had an approved version; the button is only offered while none did.
  const everApproved = versions.some((v) => ['APPROVED', 'ACTIVE', 'SUPERSEDED'].includes(v.status))

  const done = (message: string) => () => {
    setDialog(null)
    toast.success(message)
    reload()
  }

  return (
    <>
      <PageHeader
        title={budget.code}
        description={<>{budget.name} · <StatusBadge status={budget.status} /></>}
        actions={
          <>
            {manage && editable && <Button onClick={() => setDialog('edit')}>Ubah</Button>}
            {manage && editable && <Button onClick={() => setDialog('version')}>Versi baru</Button>}
            {approve && budget.status === 'DRAFT' && <Button variant="primary" onClick={() => setDialog('open')}>Buka anggaran</Button>}
            {approve && budget.status === 'ACTIVE' && <Button onClick={() => setDialog('close')}>Tutup anggaran</Button>}
            {manage && editable && !everApproved && <Button variant="danger" onClick={() => setDialog('cancel')}>Batalkan</Button>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      {budget.status === 'CANCELLED' && budget.cancel_reason && <Banner tone="info">Dibatalkan: {budget.cancel_reason}</Banner>}
      {budget.status === 'DRAFT' && <Banner tone="info">Anggaran masih draf. Buka anggaran agar versi yang disetujui dapat diaktifkan dan dipakai laporan Anggaran vs Aktual.</Banner>}

      <Card>
        <dl className="facts">
          <div><dt>Tahun fiskal</dt><dd>{budget.fiscal_year ? `${budget.fiscal_year.code} · ${budget.fiscal_year.name}` : '—'}</dd></div>
          <div><dt>Periode tahun fiskal</dt><dd>{budget.fiscal_year ? `${formatDate(budget.fiscal_year.start_date)} – ${formatDate(budget.fiscal_year.end_date)}` : '—'}</dd></div>
          <div><dt>Mata uang</dt><dd>{budget.currency}</dd></div>
          <div><dt>Penanggung jawab</dt><dd>{budget.responsible?.name ?? '—'}</dd></div>
          <div><dt>Dibuat oleh</dt><dd>{budget.creator?.name ?? '—'}</dd></div>
          {budget.activated_at && <div><dt>Dibuka pada</dt><dd>{formatDateTime(budget.activated_at)}</dd></div>}
          {budget.closed_at && <div><dt>Ditutup pada</dt><dd>{formatDateTime(budget.closed_at)}</dd></div>}
          <div className="wide"><dt>Keterangan</dt><dd>{budget.description ?? '—'}</dd></div>
        </dl>
      </Card>

      <Card title="Versi anggaran" flush actions={budget.status === 'ACTIVE' && access.can('accounting.budget.view') ? <Link className="btn btn-sm" to={`/app/akuntansi/anggaran-vs-aktual?budget_id=${budget.id}`}>Lihat Anggaran vs Aktual</Link> : undefined}>
        {versions.length === 0 ? (
          <EmptyState title="Belum ada versi">{manage && editable ? 'Buat versi pertama, isi barisnya, lalu ajukan untuk disetujui.' : 'Anggaran ini tidak memiliki versi.'}</EmptyState>
        ) : (
          <DataTable
            caption="Versi anggaran"
            rows={versions}
            rowKey={(v) => v.id}
            columns={[
              { header: 'Versi', primary: true, cell: (v) => <Link to={`/app/akuntansi/anggaran/${budget.id}/versi/${v.id}`}>Versi {v.version_number} · {v.label}</Link> },
              { header: 'Berlaku', cell: (v) => windowText(v) },
              { header: 'Status', cell: (v) => <StatusBadge status={v.status} /> },
              { header: 'Aksi', actions: true, cell: (v) => <Button size="sm" onClick={() => navigate(`/app/akuntansi/anggaran/${budget.id}/versi/${v.id}`)}>Buka</Button> },
            ]}
          />
        )}
      </Card>

      <Card title="Riwayat"><Timeline transitions={budget.transitions} /></Card>

      {dialog === 'edit' && <BudgetForm budget={budget} onClose={() => setDialog(null)} onDone={done('Anggaran disimpan.')} />}
      {dialog === 'version' && <VersionForm budget={budget} versions={versions} onClose={() => setDialog(null)} onDone={(versionId) => { toast.success('Versi dibuat.'); navigate(`/app/akuntansi/anggaran/${budget.id}/versi/${versionId}`) }} />}
      {(dialog === 'open' || dialog === 'close' || dialog === 'cancel') && (
        <BudgetStatusDialog budget={budget} action={dialog} onClose={() => setDialog(null)} onDone={done(dialog === 'open' ? 'Anggaran dibuka.' : dialog === 'close' ? 'Anggaran ditutup.' : 'Anggaran dibatalkan.')} />
      )}
    </>
  )
}
