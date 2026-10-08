import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Loading, PageHeader, Stat, StatusBadge } from '../../components/ui'
import { useAccountingAccess, type AccountingDashboard, type Readiness } from '../../lib/accounting'
import { journalTypeLabels } from '../../lib/accountingLabels'
import { api } from '../../lib/api'
import { formatDate, formatNumber } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { Money } from './shared'

const ACTION_PATH: Record<string, string> = {
  profile: '/app/akuntansi/profil',
  'fiscal-years': '/app/akuntansi/periode',
  accounts: '/app/akuntansi/akun',
  'account-mappings': '/app/akuntansi/pemetaan-akun',
  'opening-balance': '/app/akuntansi/saldo-awal',
}

export default function AccountingHome() {
  const { can, canChange, readOnly } = useAccountingAccess()
  const navigate = useNavigate()
  const toast = useToast()
  const activate = useAction()
  const canSeeSetup = can('accounting.profile.view')

  const readiness = useResource(async () => (canSeeSetup ? (await api.get<Readiness>('/app/accounting/readiness')).data : null), [canSeeSetup])
  const dashboard = useResource(async () => (await api.get<AccountingDashboard>('/app/accounting/dashboard')).data, [])

  if (dashboard.loading && !dashboard.data) return <Loading />
  if (dashboard.error || !dashboard.data) return <ErrorNotice error={dashboard.error} onRetry={dashboard.reload} />
  const d = dashboard.data
  const r = readiness.data

  async function turnOn() {
    const result = await activate.run(() => api.post('/app/accounting/profile/activate'))
    if (result.ok) {
      toast.success('Akuntansi diaktifkan. Pembukuan siap digunakan.')
      readiness.reload()
    }
  }

  return (
    <>
      <PageHeader
        title="Akuntansi"
        description="Ringkasan pembukuan: kesiapan pengaturan, periode berjalan, dan jurnal yang menunggu tindakan."
        actions={canChange('accounting.journal.create') && r?.ready !== false && <Button variant="primary" onClick={() => navigate('/app/akuntansi/jurnal/baru')}>Jurnal baru</Button>}
      />
      {readOnly && <Banner tone="warn">Langganan modul Akuntansi bersifat hanya-baca. Anda dapat melihat data, tetapi tidak dapat mengubah atau memposting.</Banner>}

      {r && !r.ready && (
        <Card
          title="Pengaturan awal"
          actions={r.can_activate && canChange('accounting.profile.manage') && <Button variant="primary" size="sm" loading={activate.busy} onClick={() => void turnOn()}>Aktifkan akuntansi</Button>}
        >
          <p className="muted" style={{ marginBottom: 12 }}>Selesaikan langkah wajib berikut sebelum jurnal dapat diposting.</p>
          {activate.error != null && <ErrorNotice error={activate.error} />}
          <ul className="checklist">
            {r.checks.map((c) => (
              <li key={c.code} className={c.done ? 'done' : ''}>
                <span className="mark" aria-hidden="true">{c.done ? '✓' : '○'}</span>
                <div>
                  <strong>{c.label}</strong>{!c.required && <span className="muted"> (opsional)</span>}
                  <div className="muted">{c.detail}</div>
                </div>
                <span className="sr-only">{c.done ? 'Selesai' : 'Belum selesai'}</span>
                {!c.done && ACTION_PATH[c.action] && <Link to={ACTION_PATH[c.action]}>Atur</Link>}
              </li>
            ))}
          </ul>
        </Card>
      )}

      <div className="grid grid-4">
        <Stat label="Tahun fiskal" value={d.fiscal_year?.code ?? '—'} hint={d.fiscal_year ? <StatusBadge status={d.fiscal_year.status} /> : 'Belum ada tahun fiskal untuk hari ini'} />
        <Stat label="Periode berjalan" value={d.period?.code ?? '—'} hint={d.period ? <StatusBadge status={d.period.status} /> : `Tanggal bisnis ${formatDate(d.business_date)}`} />
        <Stat label="Draf" value={formatNumber(d.journals.draft)} hint="Jurnal belum diajukan" />
        <Stat label="Menunggu" value={formatNumber(d.journals.pending_approval + d.journals.awaiting_posting)} hint={`${d.journals.pending_approval} persetujuan · ${d.journals.awaiting_posting} posting`} />
      </div>

      <Card title="Jurnal terposting terbaru" flush actions={<Link to="/app/akuntansi/jurnal">Semua jurnal</Link>}>
        {d.recent_posted.length === 0 ? (
          <p className="muted card-body">Belum ada jurnal yang diposting.</p>
        ) : (
          <DataTable
            caption="Jurnal terposting terbaru"
            rows={d.recent_posted}
            rowKey={(j) => j.id}
            columns={[
              { header: 'Nomor', primary: true, cell: (j) => <Link to={`/app/akuntansi/jurnal/${j.id}`} className="mono">{j.journal_number}</Link> },
              { header: 'Tanggal posting', cell: (j) => formatDate(j.posting_date) },
              { header: 'Deskripsi', cell: (j) => j.description },
              { header: 'Jenis', cell: (j) => journalTypeLabels[j.journal_type] ?? j.journal_type },
              { header: 'Total', align: 'right', cell: (j) => <Money value={j.total_debit} /> },
            ]}
          />
        )}
      </Card>
    </>
  )
}
