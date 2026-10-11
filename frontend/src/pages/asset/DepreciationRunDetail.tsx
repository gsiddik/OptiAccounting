import { Link, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { ErrorNotice, Money, ReadOnlyNotice, Timeline } from '../operational/shared'
import { useDocumentActions } from '../operational/workflow'
import { PATHS } from './data'
import type { DepreciationRun } from './types'

export default function DepreciationRunDetail() {
  const { id } = useParams()
  const run = useResource(async () => (await api.get<DepreciationRun>(`${API}/depreciation-runs/${id}`)).data, [id])
  const r = run.data
  const actions = useDocumentActions({
    base: `${API}/depreciation-runs/${id}`,
    status: r?.status ?? '',
    sod: r?.sod,
    noun: 'proses penyusutan',
    // A calculated draft is the review step: there is no approval workflow, only post (or cancel) and, later, reverse.
    approvalFlow: false,
    perms: { update: 'accounting.asset.depreciation.run', post: 'accounting.asset.depreciation.post', reverse: 'accounting.asset.depreciation.post' },
    modules: [MODULES.fixedAsset],
    onChanged: run.reload,
  })
  const { readOnly } = useModuleAccess(MODULES.fixedAsset)

  if (run.loading && !r) return <Loading />
  if (run.error || !r) return <ErrorNotice error={run.error} onRetry={run.reload} />
  const lines = r.lines ?? []

  return (
    <>
      <PageHeader
        title={r.document_number ?? `Draf penyusutan ${r.period?.code ?? ''}`.trim()}
        description={<>Periode {r.period?.code ?? '—'} · <StatusBadge status={r.status} /></>}
        actions={actions.buttons}
      />
      <ReadOnlyNotice show={readOnly} />
      {actions.notes.map((n) => <Banner key={n} tone="info">{n}</Banner>)}
      {actions.error != null && <ErrorNotice error={actions.error} />}
      {r.status === 'DRAFT' && <Banner tone="info">Draf ini belum masuk buku besar, tetapi bulan penyusutan asetnya sudah dipesan. Periksa angkanya, lalu posting; batalkan untuk melepas bulan-bulan itu.</Banner>}
      {r.status === 'CANCELLED' && r.cancel_reason && <Banner tone="info">Dibatalkan: {r.cancel_reason}</Banner>}

      <Card title="Rincian proses">
        <dl className="facts">
          <div><dt>Periode</dt><dd>{r.period ? `${r.period.code} (${formatDate(r.period.start_date)} – ${formatDate(r.period.end_date)})` : '—'}</dd></div>
          <div><dt>Tanggal posting</dt><dd>{formatDate(r.posting_date)}</dd></div>
          <div><dt>Jumlah aset</dt><dd>{r.asset_count}</dd></div>
          <div><dt>Total penyusutan</dt><dd><Money value={r.total_amount} strong /></dd></div>
          <div><dt>Referensi</dt><dd>{r.reference ?? '—'}</dd></div>
          <div><dt>Dibuat oleh</dt><dd>{r.creator?.name ?? '—'}</dd></div>
          {r.posted_at && <div><dt>Diposting pada</dt><dd>{formatDateTime(r.posted_at)}</dd></div>}
          {r.journal_entry_id && <div><dt>Jurnal</dt><dd><Link to={`${PATHS.journal}/${r.journal_entry_id}`}>Lihat jurnal</Link></dd></div>}
          {r.reversal_journal_id && <div><dt>Jurnal pembalik</dt><dd><Link to={`${PATHS.journal}/${r.reversal_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
          <div className="wide"><dt>Deskripsi</dt><dd>{r.description ?? '—'}</dd></div>
          {r.reversal_reason && <div className="wide"><dt>Alasan pembalikan{r.reversal_posting_date ? ` (${formatDate(r.reversal_posting_date)})` : ''}</dt><dd>{r.reversal_reason}</dd></div>}
        </dl>
      </Card>

      <Card title="Penyusutan per aset" flush>
        {lines.length === 0 ? (
          <EmptyState title="Tidak ada baris">Proses ini tidak memiliki baris aset.</EmptyState>
        ) : (
          <DataTable
            caption="Penyusutan per aset"
            rows={lines}
            rowKey={(l) => l.id}
            scroll
            columns={[
              { header: 'Aset', primary: true, cell: (l) => <Link to={`${PATHS.assets}/${l.fixed_asset_id}`}>{l.asset ? `${l.asset.asset_number ?? 'Draf'} · ${l.asset.name}` : l.fixed_asset_id}</Link> },
              { header: 'Bulan', align: 'right', cell: (l) => l.schedule_rows },
              { header: 'Penyusutan', align: 'right', cell: (l) => <Money value={l.amount} strong /> },
              { header: 'Akumulasi sebelum', align: 'right', cell: (l) => <Money value={l.accumulated_before} /> },
              { header: 'Akumulasi sesudah', align: 'right', cell: (l) => <Money value={l.accumulated_after} /> },
              { header: 'Nilai buku sesudah', align: 'right', cell: (l) => <Money value={l.book_value_after} /> },
            ]}
          />
        )}
      </Card>

      <Card title="Riwayat"><Timeline transitions={r.transitions} /></Card>
      {actions.dialogs}
    </>
  )
}
