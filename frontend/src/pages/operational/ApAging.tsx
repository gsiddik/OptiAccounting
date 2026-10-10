import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { DataTable, type Column } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, Stat } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useDebounced, useResource } from '../../lib/hooks'
import { API, useVendors } from '../../lib/operational'
import { useDimensions } from '../accounting/data'
import { BUCKET_PATTERN, exportQuery, useBusinessDate } from './payables/lists'
import type { AgingReport } from './payables/types'
import { DimensionFilters, ExportButton, Filters, Money } from './shared'

/** One line of the vendor table: a vendor, or the grand total (`strong`). */
type Line = { id: string; name: ReactNode; count: number; amounts: Record<string, string>; strong?: boolean }

export default function ApAging() {
  const today = useBusinessDate()
  const { vendors } = useVendors()
  const { catalog } = useDimensions()
  const [f, setF] = useState({ as_of: today, buckets: '30,60,90', vendor_id: '', branch_id: '', business_unit_id: '', cost_center_id: '', detail: false })
  const buckets = useDebounced(f.buckets.replace(/\s+/g, ''))
  const bucketsValid = BUCKET_PATTERN.test(buckets)
  const filter = { as_of: f.as_of, buckets, vendor_id: f.vendor_id, branch_id: f.branch_id, business_unit_id: f.business_unit_id, cost_center_id: f.cost_center_id, detail: f.detail }

  // A half-typed bucket list is never sent (the hint below explains the format); the report returns as soon as the list is valid.
  const report = useResource(async () => (bucketsValid && f.as_of ? (await api.get<AgingReport>(`${API}/ap-aging`, { params: exportQuery(filter) })).data : null), [JSON.stringify(filter), bucketsValid])
  const change = (patch: Partial<typeof f>) => setF((s) => ({ ...s, ...patch }))

  return (
    <>
      <PageHeader
        title="Umur utang"
        description="Utang yang masih terbuka pada tanggal tertentu, dikelompokkan menurut keterlambatan dari jatuh tempo. Angka berasal dari sub-buku utang dan tidak berubah oleh pembayaran setelah tanggal tersebut."
        actions={bucketsValid && f.as_of ? <ExportButton path={`${API}/ap-aging/export`} params={exportQuery({ ...filter, detail: false })} filename={`umur-utang_${f.as_of}.csv`} /> : undefined}
      />
      <Card flush>
        <div className="card-body stack">
          <Filters>
            <label className="inline-field">Per tanggal <input className="input" type="date" value={f.as_of} onChange={(e) => change({ as_of: e.target.value })} /></label>
            <label className="inline-field">Kelompok umur (hari) <input className="input" style={{ width: 150 }} inputMode="numeric" placeholder="30,60,90" aria-describedby="bucket-hint" value={f.buckets} onChange={(e) => change({ buckets: e.target.value })} /></label>
            <select className="select" aria-label="Vendor" value={f.vendor_id} onChange={(e) => change({ vendor_id: e.target.value })}>
              <option value="">Semua vendor</option>
              {vendors.map((v) => <option key={v.id} value={v.id}>{v.code} · {v.name}</option>)}
            </select>
            <DimensionFilters catalog={catalog} value={f} onChange={change} />
            <label className="check"><input type="checkbox" checked={f.detail} onChange={(e) => change({ detail: e.target.checked })} /> Tampilkan rincian faktur</label>
          </Filters>
          <span id="bucket-hint" className={bucketsValid ? 'muted' : 'error'} style={bucketsValid ? undefined : { color: 'var(--bad-text)' }} role={bucketsValid ? undefined : 'alert'}>
            {bucketsValid ? 'Batas hari keterlambatan, dipisah koma (1 sampai 8 batas, mis. 30,60,90).' : 'Isi 1 sampai 8 batas hari yang menaik dipisah koma, mis. 30,60,90.'}
          </span>
        </div>

        {report.error ? <ErrorNotice error={report.error} onRetry={report.reload} /> : report.data ? <Report report={report.data} /> : report.loading ? <Loading /> : null}
      </Card>
    </>
  )
}

function Report({ report }: { report: AgingReport }) {
  const label = (key: string) => report.buckets.find((b) => b.key === key)?.label ?? key
  const lines: Line[] = [
    ...report.data.map((v) => ({ id: v.vendor_id, name: <>{v.vendor_name}<div className="muted mono">{v.vendor_code}</div></>, count: v.invoice_count, amounts: { ...v.buckets, total: v.total } })),
    { id: 'total', name: 'Total', count: report.invoice_count, amounts: report.totals, strong: true },
  ]
  const columns: Column<Line>[] = [
    { header: 'Vendor', primary: true, cell: (l) => (l.strong ? <strong>{l.name}</strong> : l.name) },
    { header: 'Faktur', align: 'right', cell: (l) => l.count },
    ...report.buckets.map((b): Column<Line> => ({ header: b.label, align: 'right', cell: (l) => <Money value={l.amounts[b.key]} strong={l.strong} /> })),
    { header: 'Total', align: 'right', cell: (l) => <Money value={l.amounts.total} strong /> },
  ]

  return (
    <>
      {!report.complete && (
        <div className="card-body">
          <Banner tone="warn">Laporan ini hanya mencakup faktur dalam cakupan data Anda (cabang atau unit bisnis tertentu), sehingga totalnya bukan total seluruh utang perusahaan.</Banner>
        </div>
      )}
      <div className="card-body">
        <p className="muted" style={{ margin: '0 0 12px' }}>Posisi per {formatDate(report.as_of)} · {report.invoice_count} faktur terbuka</p>
        <div className="grid grid-4">
          {report.buckets.map((b) => <Stat key={b.key} label={b.label} value={<Money value={report.totals[b.key]} />} />)}
          <Stat label="Total utang" value={<Money value={report.totals.total} strong />} />
        </div>
      </div>
      {report.data.length === 0 ? (
        <EmptyState title="Tidak ada utang terbuka">Tidak ada faktur dengan saldo terutang pada tanggal ini untuk filter yang dipilih.</EmptyState>
      ) : (
        <DataTable caption="Umur utang per vendor" rows={lines} rowKey={(l) => l.id} columns={columns} scroll />
      )}
      {report.invoices && report.invoices.length > 0 && (
        <>
          <div className="card-head"><h2>Rincian faktur</h2></div>
          <DataTable
            caption="Rincian umur utang per faktur"
            rows={report.invoices}
            rowKey={(i) => i.id}
            scroll
            columns={[
              { header: 'Vendor', primary: true, cell: (i) => <>{i.vendor_name}<div className="muted mono">{i.vendor_code}</div></> },
              { header: 'No. dokumen', cell: (i) => <Link className="mono" to={`/app/akuntansi/faktur-vendor/${i.id}`}>{i.document_number ?? i.vendor_invoice_number}</Link> },
              { header: 'No. faktur vendor', cell: (i) => <span className="mono">{i.vendor_invoice_number}</span> },
              { header: 'Tanggal posting', cell: (i) => formatDate(i.posting_date) },
              { header: 'Jatuh tempo', cell: (i) => formatDate(i.due_date) },
              { header: 'Hari lewat', align: 'right', cell: (i) => i.days_overdue },
              { header: 'Kelompok', cell: (i) => label(i.bucket) },
              { header: 'Nilai faktur', align: 'right', cell: (i) => <Money value={i.total_amount} /> },
              { header: 'Dibayar', align: 'right', cell: (i) => <Money value={i.paid_amount} /> },
              { header: 'Saldo', align: 'right', cell: (i) => <Money value={i.outstanding_amount} strong /> },
            ]}
          />
        </>
      )}
    </>
  )
}
