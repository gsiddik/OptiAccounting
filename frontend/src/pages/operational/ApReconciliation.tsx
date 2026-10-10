import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, Stat, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, useVendors } from '../../lib/operational'
import { exportQuery, useBusinessDate } from './payables/lists'
import type { ApReconciliation as Reconciliation } from './payables/types'
import { ExportButton, Filters, Money } from './shared'

export default function ApReconciliation() {
  const today = useBusinessDate()
  const { vendors } = useVendors()
  const [f, setF] = useState({ as_of: today, vendor_id: '' })
  const report = useResource(async () => (f.as_of ? (await api.get<Reconciliation>(`${API}/reconciliation/ap`, { params: exportQuery(f) })).data : null), [f.as_of, f.vendor_id])

  return (
    <>
      <PageHeader
        title="Utang vs buku besar"
        description="Bukti bahwa sub-buku utang sama dengan akun kontrol utang di buku besar pada tanggal tertentu. Selisih ditampilkan apa adanya; tidak ada jurnal penyesuaian yang dibuat otomatis."
        actions={f.as_of ? <ExportButton path={`${API}/reconciliation/ap/export`} params={exportQuery(f)} filename={`rekonsiliasi-utang_${f.as_of}.csv`} /> : undefined}
      />
      <Card flush>
        <div className="card-body">
          <Filters>
            <label className="inline-field">Per tanggal <input className="input" type="date" value={f.as_of} onChange={(e) => setF((s) => ({ ...s, as_of: e.target.value }))} /></label>
            <select className="select" aria-label="Vendor" value={f.vendor_id} onChange={(e) => setF((s) => ({ ...s, vendor_id: e.target.value }))}>
              <option value="">Semua vendor</option>
              {vendors.map((v) => <option key={v.id} value={v.id}>{v.code} · {v.name}</option>)}
            </select>
          </Filters>
        </div>
        {report.error ? <ErrorNotice error={report.error} onRetry={report.reload} /> : report.data ? <Result report={report.data} /> : report.loading ? <Loading /> : null}
      </Card>
    </>
  )
}

function Result({ report }: { report: Reconciliation }) {
  const matched = report.status === 'MATCHED'
  return (
    <>
      <div className="card-body stack">
        {!report.complete && (
          <Banner tone="warn">Tampilan ini sebagian: hanya mencakup data dalam cakupan akses Anda (cabang atau unit bisnis tertentu), sehingga belum membuktikan kecocokan seluruh perusahaan.</Banner>
        )}
        {matched ? (
          <Banner tone="ok"><strong>Cocok.</strong> Per {formatDate(report.as_of)}, sub-buku utang sama dengan saldo transaksional akun kontrol di buku besar.</Banner>
        ) : (
          <Banner tone="bad"><strong>Selisih.</strong> Per {formatDate(report.as_of)}, sub-buku utang berbeda dari saldo transaksional akun kontrol di buku besar sebesar <Money value={report.difference} strong />. Tidak ada penyesuaian otomatis; periksa vendor yang berstatus Selisih di bawah.</Banner>
        )}
        <div className="grid grid-4">
          <Stat label="Saldo buku besar (akun kontrol)" value={<Money value={report.gl_balance} />} hint="Kredit dikurangi debit, hanya jurnal terposting" />
          <Stat label="Komponen saldo awal" value={<Money value={report.opening_balance_component} />} hint="Tidak punya faktur, tidak dibandingkan" />
          <Stat label="Saldo buku besar (transaksional)" value={<Money value={report.gl_transactional_balance} />} hint="Pembanding sub-buku" />
          <Stat label="Saldo sub-buku utang" value={<Money value={report.subledger_balance} />} hint="Faktur terposting dikurangi alokasi" />
        </div>
        <div className="totals" role="status">
          <span>Selisih (buku besar transaksional − sub-buku) <Money value={report.difference} strong /></span>
          <span>Status <StatusBadge status={report.status} /></span>
        </div>
        <p className="muted preview-note">
          Saldo awal yang diposting langsung ke akun kontrol utang tidak punya faktur di sub-buku, sehingga ditampilkan sebagai komponen sendiri dan dikeluarkan dari perbandingan.
          Saldo buku besar = komponen saldo awal + saldo transaksional.
        </p>
      </div>

      <div className="card-head"><h2>Akun kontrol utang</h2></div>
      {report.control_accounts.length === 0 ? (
        <EmptyState title="Belum ada akun kontrol utang">Akun kontrol muncul setelah peran Utang usaha dipetakan atau ada faktur yang diposting.</EmptyState>
      ) : (
        <DataTable
          caption="Akun kontrol utang"
          rows={report.control_accounts}
          rowKey={(a) => a.id}
          columns={[
            { header: 'Akun', primary: true, cell: (a) => <><span className="mono">{a.code}</span> · {a.name}</> },
            { header: 'Saldo buku besar', align: 'right', cell: (a) => <Money value={a.gl_balance} /> },
          ]}
        />
      )}

      <div className="card-head"><h2>Per vendor</h2></div>
      {report.vendors.length === 0 ? (
        <EmptyState title="Tidak ada saldo vendor">Tidak ada vendor dengan saldo di buku besar maupun sub-buku pada tanggal ini.</EmptyState>
      ) : (
        <DataTable
          caption="Rekonsiliasi utang per vendor"
          rows={report.vendors}
          rowKey={(v) => v.vendor_id}
          scroll
          columns={[
            { header: 'Vendor', primary: true, cell: (v) => <>{v.vendor_name ?? '—'}<div className="muted mono">{v.vendor_code ?? ''}</div></> },
            { header: 'Buku besar', align: 'right', cell: (v) => <Money value={v.gl_balance} /> },
            { header: 'Sub-buku', align: 'right', cell: (v) => <Money value={v.subledger_balance} /> },
            { header: 'Selisih', align: 'right', cell: (v) => <Money value={v.difference} strong={v.status === 'MISMATCH'} /> },
            { header: 'Status', cell: (v) => <StatusBadge status={v.status} /> },
          ]}
        />
      )}
    </>
  )
}
