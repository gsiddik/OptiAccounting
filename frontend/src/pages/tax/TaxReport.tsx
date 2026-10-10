import { useState } from 'react'
import { DataTable, type Column } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, Stat } from '../../components/ui'
import { formatAmount } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useDefaultRange } from '../accounting/data'
import { useResource } from '../../lib/hooks'
import { API } from '../../lib/operational'
import { ExportButton, Filters, Money } from '../operational/shared'
import { useTaxCodeOptions } from './data'
import { taxBasisLabels, taxDirectionLabels, taxTypeLabels, treatmentShort } from './labels'
import { TAX_BASES, TAX_DIRECTIONS, type TaxReport as Report, type TaxReportRow } from './types'

export default function TaxReport() {
  const range = useDefaultRange()
  const options = useTaxCodeOptions()
  const [f, setF] = useState({ date_from: range.from, date_to: range.to, basis: 'posting_date', direction: '', tax_code_id: '' })
  const change = (patch: Partial<typeof f>) => setF((s) => ({ ...s, ...patch }))
  const invalid = f.date_from !== '' && f.date_to !== '' && f.date_from > f.date_to
  const query = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')) as Record<string, string>
  const report = useResource(async () => (invalid ? null : (await api.get<Report>(`${API}/tax-report`, { params: query })).data), [JSON.stringify(query)])
  const r = report.data

  return (
    <>
      <PageHeader
        title="Laporan pajak"
        description="Rekap pajak dari transaksi pajak yang sudah diposting, per kode dan per arah. Pembalikan mengurangi angka pada periode yang dipilih. Seluruh angka dihitung server dalam mata uang fungsional dan tidak dihitung ulang dengan tarif saat ini."
        actions={!invalid ? <ExportButton path={`${API}/tax-report/export`} params={query} filename={`laporan-pajak_${f.date_from || 'awal'}_${f.date_to || 'akhir'}.csv`} /> : undefined}
      />
      <Card flush>
        <div className="card-body">
          <Filters>
            <label className="inline-field">Dari <input className="input" type="date" aria-label="Dari tanggal" value={f.date_from} onChange={(e) => change({ date_from: e.target.value })} /></label>
            <label className="inline-field">Sampai <input className="input" type="date" aria-label="Sampai tanggal" value={f.date_to} onChange={(e) => change({ date_to: e.target.value })} /></label>
            <select className="select" aria-label="Dasar laporan" value={f.basis} onChange={(e) => change({ basis: e.target.value })}>
              {TAX_BASES.map((b) => <option key={b} value={b}>Dasar: {taxBasisLabels[b].toLowerCase()}</option>)}
            </select>
            <select className="select" aria-label="Arah pajak" value={f.direction} onChange={(e) => change({ direction: e.target.value })}>
              <option value="">Semua arah</option>
              {TAX_DIRECTIONS.map((d) => <option key={d} value={d}>{taxDirectionLabels[d]}</option>)}
            </select>
            {options.available && (
              <select className="select" aria-label="Kode pajak" value={f.tax_code_id} onChange={(e) => change({ tax_code_id: e.target.value })}>
                <option value="">Semua kode pajak</option>
                {options.codes.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
              </select>
            )}
          </Filters>
        </div>
      </Card>

      {invalid ? (
        <Banner tone="warn">Tanggal akhir tidak boleh sebelum tanggal awal.</Banner>
      ) : report.loading && !r ? (
        <Loading />
      ) : report.error || !r ? (
        <ErrorNotice error={report.error} onRetry={report.reload} />
      ) : (
        <ReportBody report={r} />
      )}
    </>
  )
}

function ReportBody({ report: r }: { report: Report }) {
  const output = r.rows.filter((row) => row.direction === 'OUTPUT')
  const input = r.rows.filter((row) => row.direction === 'INPUT')
  const refund = r.totals.net_payable.startsWith('-')

  return (
    <>
      {!r.complete && <Banner tone="warn">Laporan ini hanya memuat dokumen dalam cakupan data Anda (cabang atau unit bisnis tertentu), sehingga angkanya bukan total seluruh organisasi.</Banner>}
      <Banner tone="info">Dasar laporan: {taxBasisLabels[r.basis] ?? r.basis.replace('_', ' ')}. {r.basis === 'tax_date' ? 'Dokumen yang dibalik tidak dihitung sama sekali karena pembalikan menjadi bagian dari titik pajak yang sama.' : 'Dokumen yang dibalik dihitung pada periode posting aslinya dan dikurangkan pada periode pembaliknya.'}</Banner>

      <div className="grid grid-4">
        <Stat label="Pajak keluaran" value={<Money value={r.totals.output_tax} strong />} hint={<>Dasar <Money value={r.totals.output_base} /></>} />
        <Stat label="Pajak masukan dapat dikreditkan" value={<Money value={r.totals.input_tax_recoverable} strong />} hint={<>Dasar masukan <Money value={r.totals.input_base} /></>} />
        <Stat label="Pajak masukan tidak dapat dikreditkan" value={<Money value={r.totals.input_tax_non_recoverable} strong />} hint="Sudah menjadi bagian dari biaya" />
        <Stat label="Pajak kurang (lebih) bayar" value={<Money value={r.totals.net_payable} strong />} hint={refund ? 'Lebih bayar' : 'Kurang bayar'} />
      </div>

      {r.rows.length === 0 ? (
        <Card><EmptyState title="Tidak ada pajak terposting">Belum ada transaksi pajak yang diposting pada rentang dan filter ini.</EmptyState></Card>
      ) : (
        <>
          {(output.length > 0 || input.length === 0) && (
            <Card title="Pajak keluaran" flush>
              <RowsTable caption="Pajak keluaran per kode" rows={output} direction="OUTPUT" />
              <div className="table-total"><span>Total dasar <Money value={r.totals.output_base} strong /></span><span>Total pajak <Money value={r.totals.output_tax} strong /></span></div>
            </Card>
          )}
          {(input.length > 0 || output.length === 0) && (
            <Card title="Pajak masukan" flush>
              <RowsTable caption="Pajak masukan per kode" rows={input} direction="INPUT" />
              <div className="table-total">
                <span>Total dasar <Money value={r.totals.input_base} strong /></span>
                <span>Dapat dikreditkan <Money value={r.totals.input_tax_recoverable} strong /></span>
                <span>Tidak dapat dikreditkan <Money value={r.totals.input_tax_non_recoverable} strong /></span>
              </div>
            </Card>
          )}
        </>
      )}

      {r.credit_note_tax.ar_credit_notes > 0 && (
        <Banner tone="info">
          Pajak pada {r.credit_note_tax.ar_credit_notes} nota kredit pelanggan sebesar <Money value={r.credit_note_tax.ar_credit_note_tax} strong /> diisi manual dan hanya ditampilkan sebagai informasi; angka ini tidak termasuk dalam pajak kurang (lebih) bayar di atas.
        </Banner>
      )}
    </>
  )
}

function RowsTable({ caption, rows, direction }: { caption: string; rows: TaxReportRow[]; direction: 'INPUT' | 'OUTPUT' }) {
  const columns: Column<TaxReportRow>[] = [
    { header: 'Kode', primary: true, cell: (row) => <><span className="mono">{row.tax_code}</span><div className="muted">{row.tax_name}</div></> },
    { header: 'Jenis', cell: (row) => <>{taxTypeLabels[row.tax_type] ?? row.tax_type}<div className="muted">{treatmentShort[row.treatment] ?? row.treatment}</div></> },
    { header: 'Tarif', align: 'right', cell: (row) => <span className="money">{formatAmount(row.rate)}%</span> },
    ...(direction === 'INPUT' ? [{ header: 'Dikreditkan', cell: (row: TaxReportRow) => (row.is_recoverable ? 'Ya' : 'Tidak') } satisfies Column<TaxReportRow>] : []),
    { header: 'Dasar', align: 'right', cell: (row) => <Money value={row.base_amount} /> },
    { header: 'Pajak', align: 'right', cell: (row) => <Money value={row.tax_amount} /> },
    { header: 'Transaksi', align: 'right', cell: (row) => row.transactions },
  ]
  if (rows.length === 0) return <EmptyState title="Tidak ada data">Tidak ada pajak {direction === 'OUTPUT' ? 'keluaran' : 'masukan'} pada rentang ini.</EmptyState>
  return <DataTable caption={caption} scroll rows={rows} rowKey={(row) => `${row.tax_code_id}:${row.direction}:${row.rate}:${row.treatment}:${row.is_recoverable}`} columns={columns} />
}
