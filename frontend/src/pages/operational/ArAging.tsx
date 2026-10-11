import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { DataTable, type Column } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, Stat } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate } from '../../lib/format'
import { useDebounced, useResource } from '../../lib/hooks'
import { API, useCustomers } from '../../lib/operational'
import { useDimensions } from '../accounting/data'
import { formatRate, isForeignRow } from './foreignSupport'
import { BUCKET_PATTERN, exportQuery, useBusinessDate } from './payables/lists'
import { AR_PATH } from './receivables/paths'
import type { ArAgingInvoiceRow, ArAgingReport } from './receivables/types'
import { DimensionFilters, ExportButton, Filters, Money } from './shared'

/** One line of the customer table: a customer, or the grand total (`strong`). */
type Line = { id: string; name: ReactNode; count: number; amounts: Record<string, string>; strong?: boolean }

export default function ArAging() {
  const today = useBusinessDate()
  const { customers } = useCustomers()
  const { catalog } = useDimensions()
  const [f, setF] = useState({ as_of: today, buckets: '30,60,90', customer_id: '', branch_id: '', business_unit_id: '', cost_center_id: '', detail: false })
  const buckets = useDebounced(f.buckets.replace(/\s+/g, ''))
  const bucketsValid = BUCKET_PATTERN.test(buckets)
  const filter = { as_of: f.as_of, buckets, customer_id: f.customer_id, branch_id: f.branch_id, business_unit_id: f.business_unit_id, cost_center_id: f.cost_center_id, detail: f.detail }

  // A half-typed bucket list is never sent (the hint below explains the format); the report returns as soon as the list is valid.
  const report = useResource(async () => (bucketsValid && f.as_of ? (await api.get<ArAgingReport>(`${API}/ar-aging`, { params: exportQuery(filter) })).data : null), [JSON.stringify(filter), bucketsValid])
  const change = (patch: Partial<typeof f>) => setF((s) => ({ ...s, ...patch }))

  return (
    <>
      <PageHeader
        title="Umur piutang"
        description="Piutang yang masih terbuka pada tanggal tertentu, dikelompokkan menurut keterlambatan dari jatuh tempo. Angka berasal dari sub-buku piutang dan tidak berubah oleh penerimaan atau nota kredit setelah tanggal tersebut."
        actions={bucketsValid && f.as_of ? <ExportButton path={`${API}/ar-aging/export`} params={exportQuery({ ...filter, detail: false })} filename={`umur-piutang_${f.as_of}.csv`} /> : undefined}
      />
      <Card flush>
        <div className="card-body stack">
          <Filters>
            <label className="inline-field">Per tanggal <input className="input" type="date" value={f.as_of} onChange={(e) => change({ as_of: e.target.value })} /></label>
            <label className="inline-field">Kelompok umur (hari) <input className="input" style={{ width: 150 }} inputMode="numeric" placeholder="30,60,90" aria-describedby="ar-bucket-hint" value={f.buckets} onChange={(e) => change({ buckets: e.target.value })} /></label>
            <select className="select" aria-label="Pelanggan" value={f.customer_id} onChange={(e) => change({ customer_id: e.target.value })}>
              <option value="">Semua pelanggan</option>
              {customers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
            </select>
            <DimensionFilters catalog={catalog} value={f} onChange={change} />
            <label className="check"><input type="checkbox" checked={f.detail} onChange={(e) => change({ detail: e.target.checked })} /> Tampilkan rincian faktur</label>
          </Filters>
          <span id="ar-bucket-hint" className={bucketsValid ? 'muted' : 'error'} style={bucketsValid ? undefined : { color: 'var(--bad-text)' }} role={bucketsValid ? undefined : 'alert'}>
            {bucketsValid ? 'Batas hari keterlambatan, dipisah koma (1 sampai 8 batas, mis. 30,60,90).' : 'Isi 1 sampai 8 batas hari yang menaik dipisah koma, mis. 30,60,90.'}
          </span>
        </div>

        {report.error ? <ErrorNotice error={report.error} onRetry={report.reload} /> : report.data ? <Report report={report.data} /> : report.loading ? <Loading /> : null}
      </Card>
    </>
  )
}

function Report({ report }: { report: ArAgingReport }) {
  const functional = useCapabilities().tenant?.tenant.default_currency ?? 'IDR'
  // Currency, rate and functional balance show only when some invoice of the report is in a foreign currency.
  const foreign = (report.invoices ?? []).some((i) => isForeignRow(i, functional))
  const label = (key: string) => report.buckets.find((b) => b.key === key)?.label ?? key
  const lines: Line[] = [
    ...report.data.map((c) => ({ id: c.customer_id, name: <>{c.customer_name}<div className="muted mono">{c.customer_code}</div></>, count: c.invoice_count, amounts: { ...c.buckets, total: c.total } })),
    { id: 'total', name: 'Total', count: report.invoice_count, amounts: report.totals, strong: true },
  ]
  const columns: Column<Line>[] = [
    { header: 'Pelanggan', primary: true, cell: (l) => (l.strong ? <strong>{l.name}</strong> : l.name) },
    { header: 'Faktur', align: 'right', cell: (l) => l.count },
    ...report.buckets.map((b): Column<Line> => ({ header: b.label, align: 'right', cell: (l) => <Money value={l.amounts[b.key]} strong={l.strong} /> })),
    { header: 'Total', align: 'right', cell: (l) => <Money value={l.amounts.total} strong /> },
  ]

  return (
    <>
      {!report.complete && (
        <div className="card-body">
          <Banner tone="warn">Laporan ini hanya mencakup faktur dalam cakupan data Anda (cabang atau unit bisnis tertentu), sehingga totalnya bukan total seluruh piutang perusahaan.</Banner>
        </div>
      )}
      <div className="card-body">
        <p className="muted" style={{ margin: '0 0 12px' }}>Posisi per {formatDate(report.as_of)} · {report.invoice_count} faktur terbuka</p>
        <div className="grid grid-4">
          {report.buckets.map((b) => <Stat key={b.key} label={b.label} value={<Money value={report.totals[b.key]} />} />)}
          <Stat label="Total piutang" value={<Money value={report.totals.total} strong />} />
        </div>
      </div>
      {report.data.length === 0 ? (
        <EmptyState title="Tidak ada piutang terbuka">Tidak ada faktur dengan saldo piutang pada tanggal ini untuk filter yang dipilih.</EmptyState>
      ) : (
        <DataTable caption="Umur piutang per pelanggan" rows={lines} rowKey={(l) => l.id} columns={columns} scroll />
      )}
      {report.invoices && report.invoices.length > 0 && (
        <>
          <div className="card-head"><h2>Rincian faktur</h2></div>
          {foreign && <div className="card-body"><p className="muted preview-note">Nilai faktur, dibayar dan saldo memakai mata uang masing-masing faktur. Kelompok umur dan total di atas dalam mata uang fungsional, sesuai kolom saldo fungsional.</p></div>}
          <DataTable
            caption="Rincian umur piutang per faktur"
            rows={report.invoices}
            rowKey={(i) => i.id}
            scroll
            columns={[
              { header: 'Pelanggan', primary: true, cell: (i) => <>{i.customer_name}<div className="muted mono">{i.customer_code}</div></> },
              { header: 'No. dokumen', cell: (i) => <Link className="mono" to={`${AR_PATH.invoices}/${i.id}`}>{i.document_number ?? i.customer_reference ?? 'Faktur'}</Link> },
              { header: 'Referensi pelanggan', cell: (i) => <span className="mono">{i.customer_reference ?? '—'}</span> },
              { header: 'Tanggal posting', cell: (i) => formatDate(i.posting_date) },
              { header: 'Jatuh tempo', cell: (i) => formatDate(i.due_date) },
              { header: 'Hari lewat', align: 'right', cell: (i) => i.days_overdue },
              { header: 'Kelompok', cell: (i) => label(i.bucket) },
              ...(foreign ? [
                { header: 'Mata uang', cell: (i: ArAgingInvoiceRow) => <span className="mono">{i.currency ?? functional}</span> },
                { header: 'Kurs', align: 'right' as const, cell: (i: ArAgingInvoiceRow) => (isForeignRow(i, functional) ? <span className="money">{formatRate(i.exchange_rate)}</span> : <span className="muted">—</span>) },
              ] : []),
              { header: 'Nilai faktur', align: 'right', cell: (i) => <Money value={i.total_amount} /> },
              { header: 'Diterima', align: 'right', cell: (i) => <Money value={i.received_amount} /> },
              { header: 'Nota kredit', align: 'right', cell: (i) => <Money value={i.credited_amount} /> },
              { header: 'Saldo', align: 'right', cell: (i) => <Money value={i.outstanding_amount} strong /> },
              ...(foreign ? [{ header: 'Saldo fungsional', align: 'right' as const, cell: (i: ArAgingInvoiceRow) => <Money value={i.outstanding_functional} strong /> }] : []),
            ]}
          />
        </>
      )}
    </>
  )
}
