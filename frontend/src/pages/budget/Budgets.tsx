import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useDebounced, useResource } from '../../lib/hooks'
import { statusLabel } from '../../lib/labels'
import { API, MODULES, listParams, useModuleAccess, type Page } from '../../lib/operational'
import { useCalendar } from '../accounting/data'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { BudgetForm } from './BudgetDialogs'
import { windowText } from './data'
import { BUDGET_STATUSES, type Budget } from './types'

const EMPTY = { q: '', status: '', fiscal_year_id: '' }

export default function Budgets() {
  const navigate = useNavigate()
  const toast = useToast()
  const access = useModuleAccess(MODULES.budget)
  const calendar = useCalendar()
  const [f, setF] = useState(EMPTY)
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const q = useDebounced(f.q)
  const filter = { ...f, q }
  const budgets = useResource(async () => (await api.get<Page<Budget>>(`${API}/budgets`, { params: listParams(filter, page) })).data, [page, JSON.stringify(filter)])
  const manage = access.canChange('accounting.budget.manage')

  const change = (patch: Partial<typeof EMPTY>) => {
    setF((s) => ({ ...s, ...patch }))
    setPage(1)
  }
  const inForce = (b: Budget) => b.versions?.find((v) => v.status === 'ACTIVE')

  return (
    <>
      <PageHeader
        title="Anggaran"
        description="Rencana anggaran per tahun fiskal. Anggaran hanya data perencanaan: tidak pernah membuat atau mengubah jurnal. Revisi dibuat sebagai versi baru; versi yang sudah disetujui tidak diubah."
        actions={manage && <Button variant="primary" onClick={() => setCreating(true)}>Anggaran baru</Button>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari kode atau nama" aria-label="Cari anggaran" value={f.q} onChange={(e) => change({ q: e.target.value })} />
            <select className="select" aria-label="Status anggaran" value={f.status} onChange={(e) => change({ status: e.target.value })}>
              <option value="">Semua status</option>
              {BUDGET_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            {calendar.years.length > 0 && (
              <select className="select" aria-label="Tahun fiskal" value={f.fiscal_year_id} onChange={(e) => change({ fiscal_year_id: e.target.value })}>
                <option value="">Semua tahun fiskal</option>
                {calendar.years.map((y) => <option key={y.id} value={y.id}>{y.code} · {y.name}</option>)}
              </select>
            )}
          </Filters>
        </div>

        {budgets.loading && !budgets.data ? (
          <Loading />
        ) : budgets.error || !budgets.data ? (
          <ErrorNotice error={budgets.error} onRetry={budgets.reload} />
        ) : budgets.data.data.length === 0 ? (
          <EmptyState title="Belum ada anggaran">{manage ? 'Ubah filter atau buat anggaran pertama.' : 'Ubah filter pencarian.'}</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar anggaran"
              rows={budgets.data.data}
              rowKey={(b) => b.id}
              scroll
              columns={[
                { header: 'Kode', primary: true, cell: (b) => <Link to={`/app/akuntansi/anggaran/${b.id}`} className="mono">{b.code}</Link> },
                { header: 'Nama', cell: (b) => <>{b.name}{b.description && <div className="muted">{b.description}</div>}</> },
                { header: 'Tahun fiskal', cell: (b) => (b.fiscal_year ? `${b.fiscal_year.code} · ${b.fiscal_year.name}` : '—') },
                { header: 'Penanggung jawab', cell: (b) => b.responsible?.name ?? <span className="muted">—</span> },
                { header: 'Versi berlaku', cell: (b) => {
                  const v = inForce(b)
                  return v ? <>Versi {v.version_number} · {v.label}<div className="muted">{windowText(v)}</div></> : <span className="muted">{(b.versions ?? []).length} versi, belum ada yang aktif</span>
                } },
                { header: 'Status', cell: (b) => <StatusBadge status={b.status} /> },
              ]}
            />
            <Pagination page={budgets.data.current_page} lastPage={budgets.data.last_page} total={budgets.data.total} onPage={setPage} />
          </>
        )}
      </Card>

      {creating && (
        <BudgetForm
          budget={null}
          onClose={() => setCreating(false)}
          onDone={(saved) => {
            toast.success('Anggaran dibuat.')
            navigate(`/app/akuntansi/anggaran/${saved.id}`)
          }}
        />
      )}
    </>
  )
}
