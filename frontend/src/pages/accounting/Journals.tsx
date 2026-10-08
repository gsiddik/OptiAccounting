import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { useAccountingAccess, type Journal } from '../../lib/accounting'
import { journalTypeLabels } from '../../lib/accountingLabels'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useDebounced, useResource } from '../../lib/hooks'
import { Filters, Money } from './shared'

type Page<T> = { data: T[]; current_page: number; last_page: number; total: number }

const STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'POSTED', 'CANCELLED']

export default function Journals() {
  const { canChange } = useAccountingAccess()
  const navigate = useNavigate()
  const [f, setF] = useState({ status: '', type: '', from: '', to: '', q: '', mine: false })
  const [page, setPage] = useState(1)
  const q = useDebounced(f.q)

  const journals = useResource(async () => {
    const params: Record<string, string | number | boolean> = { page }
    for (const [k, v] of Object.entries({ status: f.status, type: f.type, from: f.from, to: f.to, q })) if (v) params[k] = v
    if (f.mine) params.mine = true
    return (await api.get<Page<Journal>>('/app/accounting/journals', { params })).data
  }, [page, f.status, f.type, f.from, f.to, f.mine, q])

  const change = <K extends keyof typeof f>(k: K, v: (typeof f)[K]) => {
    setF((s) => ({ ...s, [k]: v }))
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title="Jurnal"
        description="Jurnal umum, saldo awal, jurnal pembalik, dan jurnal otomatis. Jurnal yang sudah diposting tidak dapat diubah; koreksi dilakukan dengan jurnal pembalik."
        actions={canChange('accounting.journal.create') && <Button variant="primary" onClick={() => navigate('/app/akuntansi/jurnal/baru')}>Jurnal baru</Button>}
      />
      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari nomor, deskripsi, referensi" aria-label="Cari jurnal" value={f.q} onChange={(e) => change('q', e.target.value)} />
            <select className="select" aria-label="Status" value={f.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
              {STATUSES.map((s) => <option key={s} value={s}>{statusText(s)}</option>)}
            </select>
            <select className="select" aria-label="Jenis jurnal" value={f.type} onChange={(e) => change('type', e.target.value)}>
              <option value="">Semua jenis</option>
              {Object.entries(journalTypeLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
            <label className="inline-field">Dari <input className="input" type="date" value={f.from} onChange={(e) => change('from', e.target.value)} /></label>
            <label className="inline-field">Sampai <input className="input" type="date" value={f.to} onChange={(e) => change('to', e.target.value)} /></label>
            <label className="check"><input type="checkbox" checked={f.mine} onChange={(e) => change('mine', e.target.checked)} /> Buatan saya</label>
          </Filters>
        </div>

        {journals.loading && !journals.data ? (
          <Loading />
        ) : journals.error || !journals.data ? (
          <ErrorNotice error={journals.error} onRetry={journals.reload} />
        ) : journals.data.data.length === 0 ? (
          <EmptyState title="Tidak ada jurnal">Ubah filter atau buat jurnal baru.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar jurnal"
              rows={journals.data.data}
              rowKey={(j) => j.id}
              columns={[
                { header: 'Nomor', primary: true, cell: (j) => <Link to={`/app/akuntansi/jurnal/${j.id}`} className="mono">{j.journal_number ?? 'Draf'}</Link> },
                { header: 'Tanggal posting', cell: (j) => formatDate(j.posting_date) },
                { header: 'Deskripsi', cell: (j) => <>{j.description}{j.reference && <div className="muted">Ref {j.reference}</div>}</> },
                { header: 'Jenis', cell: (j) => journalTypeLabels[j.journal_type] ?? j.journal_type },
                { header: 'Total', align: 'right', cell: (j) => <Money value={j.total_debit} /> },
                { header: 'Dibuat oleh', cell: (j) => j.creator?.name ?? <span className="muted">Sistem</span> },
                { header: 'Status', cell: (j) => <StatusBadge status={j.status} /> },
              ]}
            />
            <Pagination page={journals.data.current_page} lastPage={journals.data.last_page} total={journals.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}

function statusText(status: string): string {
  return { DRAFT: 'Draf', SUBMITTED: 'Menunggu persetujuan', APPROVED: 'Disetujui', REJECTED: 'Ditolak', POSTED: 'Terposting', CANCELLED: 'Dibatalkan' }[status] ?? status
}
