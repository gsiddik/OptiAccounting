import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, Stat, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, useCustomers } from '../../lib/operational'
import { exportQuery, useBusinessDate } from './payables/lists'
import type { ArReconciliationReport } from './receivables/types'
import { ExportButton, Filters, Money } from './shared'

export default function ArReconciliation() {
  const today = useBusinessDate()
  const { customers } = useCustomers()
  const [f, setF] = useState({ as_of: today, customer_id: '' })
  const report = useResource(async () => (f.as_of ? (await api.get<ArReconciliationReport>(`${API}/reconciliation/ar`, { params: exportQuery(f) })).data : null), [f.as_of, f.customer_id])

  return (
    <>
      <PageHeader
        title="Piutang vs buku besar"
        description="Bukti bahwa sub-buku piutang sama dengan akun kontrol piutang di buku besar pada tanggal tertentu. Selisih ditampilkan apa adanya; tidak ada jurnal penyesuaian yang dibuat otomatis."
        actions={f.as_of ? <ExportButton path={`${API}/reconciliation/ar/export`} params={exportQuery(f)} filename={`rekonsiliasi-piutang_${f.as_of}.csv`} /> : undefined}
      />
      <Card flush>
        <div className="card-body">
          <Filters>
            <label className="inline-field">Per tanggal <input className="input" type="date" value={f.as_of} onChange={(e) => setF((s) => ({ ...s, as_of: e.target.value }))} /></label>
            <select className="select" aria-label="Pelanggan" value={f.customer_id} onChange={(e) => setF((s) => ({ ...s, customer_id: e.target.value }))}>
              <option value="">Semua pelanggan</option>
              {customers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
            </select>
          </Filters>
        </div>
        {report.error ? <ErrorNotice error={report.error} onRetry={report.reload} /> : report.data ? <Result report={report.data} /> : report.loading ? <Loading /> : null}
      </Card>
    </>
  )
}

function Result({ report }: { report: ArReconciliationReport }) {
  const matched = report.status === 'MATCHED'
  return (
    <>
      <div className="card-body stack">
        {!report.complete && (
          <Banner tone="warn">Tampilan ini sebagian: hanya mencakup data dalam cakupan akses Anda (cabang atau unit bisnis tertentu), sehingga belum membuktikan kecocokan seluruh perusahaan.</Banner>
        )}
        {matched ? (
          <Banner tone="ok"><strong>Cocok.</strong> Per {formatDate(report.as_of)}, sub-buku piutang sama dengan saldo transaksional akun kontrol di buku besar.</Banner>
        ) : (
          <Banner tone="bad"><strong>Selisih.</strong> Per {formatDate(report.as_of)}, sub-buku piutang berbeda dari saldo transaksional akun kontrol di buku besar sebesar <Money value={report.difference} strong />. Tidak ada penyesuaian otomatis; periksa pelanggan yang berstatus Selisih di bawah.</Banner>
        )}
        <div className="grid grid-4">
          <Stat label="Saldo buku besar (akun kontrol)" value={<Money value={report.gl_balance} />} hint="Debit dikurangi kredit, hanya jurnal terposting" />
          <Stat label="Komponen saldo awal" value={<Money value={report.opening_balance_component} />} hint="Tidak punya faktur, tidak dibandingkan" />
          <Stat label="Saldo buku besar (transaksional)" value={<Money value={report.gl_transactional_balance} />} hint="Pembanding sub-buku" />
          <Stat label="Saldo sub-buku piutang" value={<Money value={report.subledger_balance} />} hint="Faktur terposting dikurangi penerimaan dan nota kredit" />
        </div>
        <div className="totals" role="status">
          <span>Selisih (buku besar transaksional − sub-buku) <Money value={report.difference} strong /></span>
          <span>Status <StatusBadge status={report.status} /></span>
        </div>
        <p className="muted preview-note">
          Saldo awal yang diposting langsung ke akun kontrol piutang tidak punya faktur di sub-buku, sehingga ditampilkan sebagai komponen sendiri dan dikeluarkan dari perbandingan.
          Saldo buku besar = komponen saldo awal + saldo transaksional.
        </p>
      </div>

      <div className="card-head"><h2>Akun kontrol piutang</h2></div>
      {report.control_accounts.length === 0 ? (
        <EmptyState title="Belum ada akun kontrol piutang">Akun kontrol muncul setelah peran Piutang usaha dipetakan atau ada faktur yang diposting.</EmptyState>
      ) : (
        <DataTable
          caption="Akun kontrol piutang"
          rows={report.control_accounts}
          rowKey={(a) => a.id}
          columns={[
            { header: 'Akun', primary: true, cell: (a) => <><span className="mono">{a.code}</span> · {a.name}</> },
            { header: 'Saldo buku besar', align: 'right', cell: (a) => <Money value={a.gl_balance} /> },
          ]}
        />
      )}

      <div className="card-head"><h2>Per pelanggan</h2></div>
      {report.customers.length === 0 ? (
        <EmptyState title="Tidak ada saldo pelanggan">Tidak ada pelanggan dengan saldo di buku besar maupun sub-buku pada tanggal ini.</EmptyState>
      ) : (
        <DataTable
          caption="Rekonsiliasi piutang per pelanggan"
          rows={report.customers}
          rowKey={(c) => c.customer_id}
          scroll
          columns={[
            { header: 'Pelanggan', primary: true, cell: (c) => <>{c.customer_name ?? '—'}<div className="muted mono">{c.customer_code ?? ''}</div></> },
            { header: 'Buku besar', align: 'right', cell: (c) => <Money value={c.gl_balance} /> },
            { header: 'Sub-buku', align: 'right', cell: (c) => <Money value={c.subledger_balance} /> },
            { header: 'Selisih', align: 'right', cell: (c) => <Money value={c.difference} strong={c.status === 'MISMATCH'} /> },
            { header: 'Status', cell: (c) => <StatusBadge status={c.status} /> },
          ]}
        />
      )}
    </>
  )
}
