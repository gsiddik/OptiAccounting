import { Link, useParams } from 'react-router-dom'
import { Banner, Card, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { ApiError, api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { disposalTypeLabels } from '../../lib/oa4Labels'
import { ErrorNotice, Money, ReadOnlyNotice, Timeline } from '../operational/shared'
import { useDocumentActions } from '../operational/workflow'
import { PATHS } from './data'
import type { Disposal } from './types'

export default function AssetDisposalDetail() {
  const { id } = useParams()
  const disposal = useResource(async () => (await api.get<Disposal>(`${API}/asset-disposals/${id}`)).data, [id])
  const d = disposal.data
  const actions = useDocumentActions({
    base: `${API}/asset-disposals/${id}`,
    status: d?.status ?? '',
    sod: d?.sod,
    noun: 'pelepasan',
    perms: {
      update: 'accounting.asset.dispose',
      submit: 'accounting.asset.dispose',
      approve: 'accounting.asset.disposal.approve',
      post: 'accounting.asset.disposal.post',
      reverse: 'accounting.asset.disposal.post',
    },
    modules: [MODULES.fixedAsset],
    editPath: `${PATHS.disposals}/${id}/ubah`,
    onChanged: disposal.reload,
  })
  const { readOnly } = useModuleAccess(MODULES.fixedAsset)

  if (disposal.loading && !d) return <Loading />
  if (disposal.error || !d) return <ErrorNotice error={disposal.error} onRetry={disposal.reload} />
  const settled = d.status === 'POSTED' || d.status === 'REVERSED'
  const p = d.preview

  return (
    <>
      <PageHeader
        title={d.document_number ?? `Draf pelepasan ${d.asset?.asset_number ?? ''}`.trim()}
        description={<>{disposalTypeLabels[d.disposal_type] ?? d.disposal_type} · <StatusBadge status={d.status} /></>}
        actions={actions.buttons}
      />
      <ReadOnlyNotice show={readOnly} />
      {actions.notes.map((n) => <Banner key={n} tone="info">{n}</Banner>)}
      {actions.error != null && <ErrorNotice error={actions.error} />}
      <PendingDepreciation error={actions.error} />
      {d.status === 'REJECTED' && d.reject_reason && <Banner tone="warn">Ditolak: {d.reject_reason}</Banner>}
      {d.status === 'CANCELLED' && d.cancel_reason && <Banner tone="info">Dibatalkan: {d.cancel_reason}</Banner>}

      <Card title="Rincian pelepasan">
        <dl className="facts">
          <div><dt>Aset</dt><dd>{d.asset ? <Link to={`${PATHS.assets}/${d.fixed_asset_id}`}>{d.asset.asset_number ?? 'Draf'} · {d.asset.name}</Link> : '—'}</dd></div>
          <div><dt>Jenis</dt><dd>{disposalTypeLabels[d.disposal_type] ?? d.disposal_type}</dd></div>
          <div><dt>Tanggal dokumen</dt><dd>{formatDate(d.document_date)}</dd></div>
          <div><dt>Tanggal pelepasan</dt><dd>{formatDate(d.disposal_date)}</dd></div>
          <div><dt>Tanggal posting</dt><dd>{formatDate(d.posting_date)}</dd></div>
          {d.disposal_type === 'SALE' && <div><dt>Akun penerimaan</dt><dd>{d.proceeds_account ? `${d.proceeds_account.code} · ${d.proceeds_account.name}` : '—'}</dd></div>}
          <div><dt>Referensi</dt><dd>{d.reference ?? '—'}</dd></div>
          <div><dt>Dibuat oleh</dt><dd>{d.creator?.name ?? '—'}</dd></div>
          {d.posted_at && <div><dt>Diposting pada</dt><dd>{formatDateTime(d.posted_at)}</dd></div>}
          {d.branch && <div><dt>Cabang</dt><dd>{d.branch.code} · {d.branch.name}</dd></div>}
          {d.business_unit && <div><dt>Unit bisnis</dt><dd>{d.business_unit.code} · {d.business_unit.name}</dd></div>}
          {d.cost_center && <div><dt>Pusat biaya</dt><dd>{d.cost_center.code} · {d.cost_center.name}</dd></div>}
          {d.journal_entry_id && <div><dt>Jurnal</dt><dd><Link to={`${PATHS.journal}/${d.journal_entry_id}`}>Lihat jurnal</Link></dd></div>}
          {d.reversal_journal_id && <div><dt>Jurnal pembalik</dt><dd><Link to={`${PATHS.journal}/${d.reversal_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
          <div className="wide"><dt>Alasan</dt><dd>{d.reason}</dd></div>
          {d.reversal_reason && <div className="wide"><dt>Alasan pembalikan{d.reversal_posting_date ? ` (${formatDate(d.reversal_posting_date)})` : ''}</dt><dd>{d.reversal_reason}</dd></div>}
        </dl>
      </Card>

      <Card title="Perhitungan server">
        {p ? (
          <>
            <dl className="facts">
              <div><dt>Harga perolehan</dt><dd><Money value={p.cost} /></dd></div>
              <div><dt>Akumulasi penyusutan</dt><dd><Money value={p.accumulated} /></dd></div>
              <div><dt>Nilai buku</dt><dd><Money value={p.book_value} strong /></dd></div>
              <div><dt>Hasil penjualan</dt><dd><Money value={p.proceeds} /></dd></div>
              <div><dt>Laba pelepasan</dt><dd><Money value={p.gain} strong /></dd></div>
              <div><dt>Rugi pelepasan</dt><dd><Money value={p.loss} strong /></dd></div>
            </dl>
            <p className="muted preview-note">
              {settled
                ? 'Angka ini adalah snapshot yang dibekukan saat pelepasan diposting.'
                : 'Perkiraan server dari register saat ini. Server menghitung ulang dan menetapkannya saat pelepasan diposting; penyusutan harus sudah diposting sampai tanggal pelepasan.'}
            </p>
          </>
        ) : (
          <p className="muted">Perhitungan tidak tersedia.</p>
        )}
      </Card>

      <Card title="Riwayat"><Timeline transitions={d.transitions} /></Card>
      {actions.dialogs}
    </>
  )
}

/** The refusal "depreciation is not posted up to the disposal date" comes with the first pending month; point the user to where it is posted. */
function PendingDepreciation({ error }: { error: unknown }) {
  const { can } = useCapabilities()
  if (!(error instanceof ApiError) || error.code !== 'ASSET_DEPRECIATION_PENDING') return null
  const from = typeof error.details.first_pending_period === 'string' ? error.details.first_pending_period : null
  const months = typeof error.details.pending_months === 'number' ? error.details.pending_months : null
  return (
    <Banner tone="warn">
      {from && <>Penyusutan yang belum diposting dimulai {formatDate(from)}{months ? ` (${months} bulan)` : ''}. </>}
      {can('accounting.asset.view') && <Link to={PATHS.runs}>Buka halaman penyusutan</Link>}
    </Banner>
  )
}
