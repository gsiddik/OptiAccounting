import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { statusLabel } from '../../lib/labels'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { usePagedList } from '../operational/expense/usePagedList'
import { Filters, Money, ReadOnlyNotice } from '../operational/shared'
import { CalculateRunDialog } from './RunDialogs'
import { PATHS } from './data'
import { RUN_STATUSES, type DepreciationRun } from './types'

const INITIAL = { q: '', status: '', posting_from: '', posting_to: '' }

export default function DepreciationRuns() {
  const navigate = useNavigate()
  const toast = useToast()
  const access = useModuleAccess(MODULES.fixedAsset)
  const run = access.canChange('accounting.asset.depreciation.run')
  const { filter, change, setPage, list } = usePagedList<DepreciationRun, typeof INITIAL>(`${API}/depreciation-runs`, INITIAL)
  const [calculating, setCalculating] = useState(false)

  const date = (name: 'posting_from' | 'posting_to', short: string, label: string) => (
    <label className="inline-field">{short} <input className="input" type="date" aria-label={label} value={filter[name]} onChange={(e) => change(name, e.target.value)} /></label>
  )

  return (
    <>
      <PageHeader
        title="Penyusutan"
        description="Proses penyusutan per periode: server menghitung draf untuk diperiksa, lalu diposting sebagai satu jurnal. Proses yang sudah diposting tidak diubah; koreksinya dengan pembalikan, mulai dari proses terbaru."
        actions={run && <Button variant="primary" onClick={() => setCalculating(true)}>Hitung penyusutan</Button>}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari nomor atau deskripsi" aria-label="Cari proses penyusutan" value={filter.q} onChange={(e) => change('q', e.target.value)} />
            <select className="select" aria-label="Status proses" value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
              {RUN_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
            {date('posting_from', 'Posting dari', 'Tanggal posting dari')}
            {date('posting_to', 'sampai', 'Tanggal posting sampai')}
          </Filters>
        </div>

        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={list.error} onRetry={list.reload} />
        ) : list.data.data.length === 0 ? (
          <EmptyState title="Belum ada proses penyusutan">{run ? 'Hitung penyusutan periode pertama, atau ubah filter.' : 'Ubah filter pencarian.'}</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Proses penyusutan"
              rows={list.data.data}
              rowKey={(r) => r.id}
              scroll
              columns={[
                { header: 'Nomor', primary: true, cell: (r) => <Link to={`${PATHS.runs}/${r.id}`} className="mono">{r.document_number ?? 'Draf'}</Link> },
                { header: 'Periode', cell: (r) => r.period?.code ?? '—' },
                { header: 'Tanggal posting', cell: (r) => formatDate(r.posting_date) },
                { header: 'Aset', align: 'right', cell: (r) => r.asset_count },
                { header: 'Total penyusutan', align: 'right', cell: (r) => <Money value={r.total_amount} strong /> },
                { header: 'Dibuat oleh', cell: (r) => r.creator?.name ?? '—' },
                { header: 'Status', cell: (r) => <StatusBadge status={r.status} /> },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>

      {calculating && (
        <CalculateRunDialog
          onClose={() => setCalculating(false)}
          onDone={(saved) => {
            toast.success('Penyusutan dihitung. Periksa drafnya sebelum diposting.')
            navigate(`${PATHS.runs}/${saved.id}`)
          }}
        />
      )}
    </>
  )
}
