import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { assetMethodLabels, capitalizationModeLabels, startPolicyLabels } from '../../lib/oa4Labels'
import { ErrorNotice, Money, ReadOnlyNotice, Timeline } from '../operational/shared'
import { CapitalizeDialog, DiscardDialog, ReverseCapitalizationDialog } from './AssetDialogs'
import { ScheduleCard } from './ScheduleCard'
import { PATHS } from './data'
import type { Asset, AccountRef } from './types'

export default function AssetDetail() {
  const { id } = useParams()
  const asset = useResource(async () => (await api.get<Asset>(`${API}/assets/${id}`)).data, [id])

  if (asset.loading && !asset.data) return <Loading />
  if (asset.error || !asset.data) return <ErrorNotice error={asset.error} onRetry={asset.reload} />
  return <AssetView asset={asset.data} reload={asset.reload} />
}

type Dialog = null | 'capitalize' | 'discard' | 'reverse'

const account = (a: AccountRef | null | undefined) => (a ? `${a.code} · ${a.name}` : '—')
const named = (r: { code: string; name: string } | null | undefined) => (r ? `${r.code} · ${r.name}` : '—')

function AssetView({ asset: a, reload }: { asset: Asset; reload: () => void }) {
  const toast = useToast()
  const access = useModuleAccess(MODULES.fixedAsset)
  const [dialog, setDialog] = useState<Dialog>(null)
  const manage = access.canChange('accounting.asset.manage')
  const capitalize = access.canChange('accounting.asset.capitalize')
  const dispose = access.canChange('accounting.asset.dispose')
  const sod = a.sod ?? { capitalize: true }
  const summary = a.schedule_summary
  // Capitalization can be undone only while no month was posted or is held by a draft run (the API checks it again).
  const untouched = !summary || summary.posted + summary.in_run === 0
  const disposable = a.status === 'ACTIVE' || a.status === 'FULLY_DEPRECIATED'
  const sourceMissing = a.status === 'DRAFT' && a.capitalization_mode === 'POST' && !a.source_account_id

  const done = (message: string) => () => {
    setDialog(null)
    toast.success(message)
    reload()
  }

  return (
    <>
      <PageHeader
        title={a.asset_number ?? `Draf: ${a.name}`}
        description={<>{a.name} · <StatusBadge status={a.status} /></>}
        actions={
          <>
            {a.status === 'DRAFT' && manage && <Link className="btn" to={`${PATHS.assets}/${a.id}/ubah`}>Ubah</Link>}
            {a.status === 'DRAFT' && capitalize && <Button variant="primary" disabled={!sod.capitalize} onClick={() => setDialog('capitalize')}>Kapitalisasi</Button>}
            {a.status === 'DRAFT' && manage && <Button variant="danger" onClick={() => setDialog('discard')}>Buang draf</Button>}
            {a.status === 'ACTIVE' && capitalize && untouched && <Button onClick={() => setDialog('reverse')}>Balik kapitalisasi</Button>}
            {disposable && dispose && <Link className="btn" to={`${PATHS.disposals}/baru?aset=${a.id}`}>Siapkan pelepasan</Link>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      {a.status === 'DRAFT' && capitalize && !sod.capitalize && <Banner tone="info">Pemisahan tugas: Anda tidak dapat mengkapitalisasi aset yang Anda siapkan sendiri.</Banner>}
      {a.status === 'DRAFT' && <Banner tone="info">Draf belum masuk buku besar dan belum bernomor. Kapitalisasi memberi nomor, memposting jurnal (bila caranya posting), dan mengunci ketentuan keuangan aset.</Banner>}
      {sourceMissing && <Banner tone="warn">Akun sumber belum dipilih. Lengkapi lewat Ubah sebelum aset dikapitalisasi.</Banner>}
      {a.status === 'INACTIVE' && <Banner tone="info">Aset tidak aktif{a.inactive_reason ? `: ${a.inactive_reason}` : '.'}</Banner>}
      {a.status === 'DISPOSED' && <Banner tone="info">Aset ini sudah dilepas{a.disposed_on ? ` pada ${formatDate(a.disposed_on)}` : ''} dan tidak disusutkan lagi.{a.disposal_id && <> <Link to={`${PATHS.disposals}/${a.disposal_id}`}>Lihat pelepasan</Link></>}</Banner>}

      <Card title="Rincian aset">
        <dl className="facts">
          <div><dt>Kategori</dt><dd>{named(a.category)}</dd></div>
          <div><dt>Tanggal perolehan</dt><dd>{formatDate(a.acquisition_date)}</dd></div>
          <div><dt>Tanggal kapitalisasi</dt><dd>{formatDate(a.capitalization_date)}</dd></div>
          <div><dt>Cara kapitalisasi</dt><dd>{capitalizationModeLabels[a.capitalization_mode] ?? a.capitalization_mode}</dd></div>
          {a.capitalization_mode === 'POST' && <div><dt>Akun sumber</dt><dd>{account(a.source_account)}</dd></div>}
          {a.source_type === 'AP_INVOICE_LINE' && <div><dt>Sumber</dt><dd>Baris faktur vendor</dd></div>}
          <div><dt>Referensi sumber</dt><dd>{a.source_reference ?? '—'}</dd></div>
          {a.branch && <div><dt>Cabang</dt><dd>{named(a.branch)}</dd></div>}
          {a.business_unit && <div><dt>Unit bisnis</dt><dd>{named(a.business_unit)}</dd></div>}
          {a.cost_center && <div><dt>Pusat biaya</dt><dd>{named(a.cost_center)}</dd></div>}
          <div><dt>Dibuat oleh</dt><dd>{a.creator?.name ?? '—'}</dd></div>
          {a.capitalization_journal_id && <div><dt>Jurnal kapitalisasi</dt><dd><Link to={`${PATHS.journal}/${a.capitalization_journal_id}`}>Lihat jurnal</Link></dd></div>}
          {a.capitalization_reversal_journal_id && <div><dt>Jurnal pembalik kapitalisasi</dt><dd><Link to={`${PATHS.journal}/${a.capitalization_reversal_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
          <div className="wide"><dt>Deskripsi</dt><dd>{a.description ?? '—'}</dd></div>
          {a.capitalization_reversal_reason && <div className="wide"><dt>Alasan pembalikan kapitalisasi</dt><dd>{a.capitalization_reversal_reason}</dd></div>}
        </dl>
      </Card>

      <Card title="Nilai dan penyusutan">
        <dl className="facts">
          <div><dt>Harga perolehan</dt><dd><Money value={a.acquisition_cost} strong /></dd></div>
          <div><dt>Nilai sisa</dt><dd><Money value={a.residual_value} /></dd></div>
          <div><dt>Dasar penyusutan</dt><dd>{a.depreciable_basis ? <Money value={a.depreciable_basis} /> : '—'}</dd></div>
          <div><dt>Akumulasi penyusutan</dt><dd><Money value={a.accumulated_depreciation} /></dd></div>
          <div><dt>Nilai buku</dt><dd>{a.net_book_value ? <Money value={a.net_book_value} strong /> : '—'}</dd></div>
          <div><dt>Mata uang</dt><dd>{a.currency}</dd></div>
          <div><dt>Metode</dt><dd>{assetMethodLabels[a.method] ?? a.method}{a.method === 'DECLINING_BALANCE' && a.method_params?.factor && ` (faktor ${a.method_params.factor})`}</dd></div>
          <div><dt>Umur manfaat</dt><dd>{a.useful_life_months ? `${a.useful_life_months} bulan` : '—'}</dd></div>
          <div><dt>Mulai disusutkan</dt><dd>{startPolicyLabels[a.start_policy] ?? a.start_policy}</dd></div>
          {a.asset_account && <div><dt>Akun aset tetap</dt><dd>{account(a.asset_account)}</dd></div>}
          {a.accumulated_account && <div><dt>Akun akumulasi penyusutan</dt><dd>{account(a.accumulated_account)}</dd></div>}
          {a.expense_account && <div><dt>Akun beban penyusutan</dt><dd>{account(a.expense_account)}</dd></div>}
          {a.gain_loss_account && <div><dt>Akun laba/rugi pelepasan</dt><dd>{account(a.gain_loss_account)}</dd></div>}
        </dl>
        {a.status !== 'DRAFT' && a.asset_account && <p className="muted preview-note">Akun di atas dibekukan saat kapitalisasi; mengubah kategori atau pemetaan akun tidak menggesernya.</p>}
      </Card>

      <ScheduleCard asset={a} />

      <Card title="Riwayat"><Timeline transitions={a.transitions} /></Card>

      {dialog === 'capitalize' && <CapitalizeDialog asset={a} onClose={() => setDialog(null)} onDone={done('Aset dikapitalisasi.')} />}
      {dialog === 'discard' && <DiscardDialog asset={a} onClose={() => setDialog(null)} onDone={done('Draf aset dibuang.')} />}
      {dialog === 'reverse' && <ReverseCapitalizationDialog asset={a} onClose={() => setDialog(null)} onDone={done('Kapitalisasi dibalik.')} />}
    </>
  )
}
