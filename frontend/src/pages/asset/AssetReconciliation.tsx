import { useState } from 'react'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, Stat, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API } from '../../lib/operational'
import { useBusinessDate } from '../operational/payables/lists'
import { ExportButton, Filters, Money } from '../operational/shared'
import { AssetRulesPanel } from './AssetRulesPanel'
import type { AssetReconciliation as Reconciliation, ReconRow } from './types'

export default function AssetReconciliation() {
  const today = useBusinessDate()
  const [asOf, setAsOf] = useState(today)
  const report = useResource(async () => (asOf ? (await api.get<Reconciliation>(`${API}/asset-reconciliation`, { params: { as_of: asOf } })).data : null), [asOf])

  return (
    <>
      <PageHeader
        title="Aset tetap vs buku besar"
        description="Bukti bahwa register aset tetap sama dengan akun aset dan akumulasi penyusutan di buku besar pada tanggal tertentu. Selisih ditampilkan apa adanya; tidak ada jurnal penyesuaian yang dibuat otomatis."
        actions={asOf ? <ExportButton path={`${API}/asset-reconciliation/export`} params={{ as_of: asOf }} filename={`rekonsiliasi-aset-tetap_${asOf}.csv`} /> : undefined}
      />
      <Card flush>
        <div className="card-body">
          <Filters>
            <label className="inline-field">Per tanggal <input className="input" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} /></label>
          </Filters>
        </div>
        {report.error ? <ErrorNotice error={report.error} onRetry={report.reload} /> : report.data ? <Result report={report.data} /> : report.loading ? <Loading /> : null}
      </Card>
      <AssetRulesPanel />
    </>
  )
}

function Result({ report }: { report: Reconciliation }) {
  const t = report.totals
  return (
    <>
      <div className="card-body stack">
        {!report.complete && (
          <Banner tone="warn">Tampilan ini sebagian: hanya mencakup aset dalam cakupan akses Anda (cabang atau unit bisnis tertentu), sehingga belum membuktikan kecocokan seluruh perusahaan.</Banner>
        )}
        {report.reconciled ? (
          <Banner tone="ok"><strong>Cocok.</strong> Per {formatDate(report.as_of)}, register aset tetap sama dengan buku besar untuk harga perolehan dan akumulasi penyusutan.</Banner>
        ) : (
          <Banner tone="bad"><strong>Selisih.</strong> Per {formatDate(report.as_of)}, nilai buku di buku besar berbeda dari register sebesar <Money value={t.difference} strong />. Tidak ada penyesuaian otomatis; periksa akun yang berstatus Selisih di bawah (mis. faktur vendor yang diposting ke akun aset tanpa didaftarkan, atau jurnal manual).</Banner>
        )}
        <div className="grid grid-4">
          <Stat label="Nilai buku register" value={<Money value={t.register_net_book_value} />} hint="Harga perolehan dikurangi akumulasi penyusutan di register" />
          <Stat label="Nilai buku buku besar" value={<Money value={t.ledger_net_book_value} />} hint="Saldo akun aset dikurangi akumulasi, hanya jurnal terposting" />
          <Stat label="Selisih" value={<Money value={t.difference} strong />} hint="Buku besar dikurangi register" />
          <Stat label="Status" value={<StatusBadge status={report.reconciled ? 'MATCHED' : 'MISMATCH'} />} />
        </div>
      </div>

      <Section title="Harga perolehan per akun" rows={report.cost} empty="Belum ada akun aset tetap. Akun muncul setelah aset dikapitalisasi atau peran Aset tetap dipetakan." registerTotal={t.register_cost} ledgerTotal={t.ledger_cost} />
      <Section title="Akumulasi penyusutan per akun" rows={report.accumulated_depreciation} empty="Belum ada akun akumulasi penyusutan." registerTotal={t.register_accumulated} ledgerTotal={t.ledger_accumulated} />
      <p className="card-body muted preview-note">
        Register menghitung aset yang sudah dikapitalisasi dan belum dilepas per tanggal ini, serta penyusutan dari proses yang sudah diposting. Buku besar membaca jurnal terposting.
        {' '}<Link to="/app/akuntansi/aset">Buka register aset</Link>
      </p>
    </>
  )
}

function Section({ title, rows, empty, registerTotal, ledgerTotal }: { title: string; rows: ReconRow[]; empty: string; registerTotal: string; ledgerTotal: string }) {
  return (
    <>
      <div className="card-head"><h2>{title}</h2></div>
      {rows.length === 0 ? (
        <EmptyState title="Belum ada akun">{empty}</EmptyState>
      ) : (
        <>
          <DataTable
            caption={title}
            rows={rows}
            rowKey={(r) => r.account.id}
            scroll
            columns={[
              { header: 'Akun', primary: true, cell: (r) => <><span className="mono">{r.account.code ?? '—'}</span> · {r.account.name ?? 'Akun tidak ditemukan'}</> },
              { header: 'Jumlah aset', align: 'right', cell: (r) => r.asset_count },
              { header: 'Register', align: 'right', cell: (r) => <Money value={r.register} /> },
              { header: 'Buku besar', align: 'right', cell: (r) => <Money value={r.ledger} /> },
              { header: 'Selisih', align: 'right', cell: (r) => <Money value={r.difference} strong={!r.matched} /> },
              { header: 'Status', cell: (r) => <StatusBadge status={r.matched ? 'MATCHED' : 'MISMATCH'} /> },
            ]}
          />
          <div className="card-body totals" role="status">
            <span>Total register <Money value={registerTotal} strong /></span>
            <span>Total buku besar <Money value={ledgerTotal} strong /></span>
          </div>
        </>
      )}
    </>
  )
}
