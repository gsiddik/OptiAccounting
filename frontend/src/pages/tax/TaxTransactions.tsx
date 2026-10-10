import { useState } from 'react'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { formatAmount } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { statusLabel } from '../../lib/labels'
import { API, listParams, type Page } from '../../lib/operational'
import { Filters } from '../operational/shared'
import { useTaxCodeOptions } from './data'
import { SOURCE_LINKS, sourceLabel, taxBasisLabels, taxDirectionLabels, taxSourceLabels } from './labels'
import { TAX_BASES, TAX_DIRECTIONS, TAX_TRANSACTION_STATUSES, type TaxTransaction } from './types'

const EMPTY = { date_from: '', date_to: '', basis: 'posting_date', direction: '', tax_code_id: '', source_type: '', status: 'POSTED' }

export default function TaxTransactions() {
  const [f, setF] = useState(EMPTY)
  const [page, setPage] = useState(1)
  const options = useTaxCodeOptions()
  const rangeInvalid = f.date_from !== '' && f.date_to !== '' && f.date_from > f.date_to
  const rows = useResource(async () => (rangeInvalid ? null : (await api.get<Page<TaxTransaction>>(`${API}/tax-transactions`, { params: listParams(f, page) })).data), [page, JSON.stringify(f)])

  const change = (patch: Partial<typeof EMPTY>) => {
    setF((s) => ({ ...s, ...patch }))
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title="Transaksi pajak"
        description="Pajak setiap baris dokumen sebagaimana dibekukan saat diposting: kode, tarif, dasar, dan pajaknya tidak berubah walau tarif kode diubah kemudian. Angka ditampilkan apa adanya dari server."
      />
      <Card flush>
        <div className="card-body">
          <Filters>
            <label className="inline-field">Dari <input className="input" type="date" aria-label="Dari tanggal" value={f.date_from} onChange={(e) => change({ date_from: e.target.value })} /></label>
            <label className="inline-field">Sampai <input className="input" type="date" aria-label="Sampai tanggal" value={f.date_to} onChange={(e) => change({ date_to: e.target.value })} /></label>
            <select className="select" aria-label="Dasar tanggal" value={f.basis} onChange={(e) => change({ basis: e.target.value })}>
              {TAX_BASES.map((b) => <option key={b} value={b}>Tanggal: {taxBasisLabels[b].toLowerCase()}</option>)}
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
            <select className="select" aria-label="Sumber dokumen" value={f.source_type} onChange={(e) => change({ source_type: e.target.value })}>
              <option value="">Semua sumber</option>
              {Object.entries(taxSourceLabels).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
            </select>
            <select className="select" aria-label="Status transaksi pajak" value={f.status} onChange={(e) => change({ status: e.target.value })}>
              {TAX_TRANSACTION_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)[0]}</option>)}
            </select>
          </Filters>
        </div>
        {rangeInvalid && <div className="card-body"><Banner tone="warn">Tanggal akhir tidak boleh sebelum tanggal awal.</Banner></div>}

        {rangeInvalid ? null : rows.loading && !rows.data ? (
          <Loading />
        ) : rows.error || !rows.data ? (
          <ErrorNotice error={rows.error} onRetry={rows.reload} />
        ) : rows.data.data.length === 0 ? (
          <EmptyState title="Tidak ada transaksi pajak">Transaksi pajak tercatat saat dokumen yang memakai kode pajak diposting. Ubah filter untuk melihat yang lain.</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Transaksi pajak"
              rows={rows.data.data}
              rowKey={(t) => t.id}
              scroll
              columns={[
                { header: 'Dokumen', primary: true, cell: (t) => <Source tx={t} /> },
                { header: 'Tanggal pajak', cell: (t) => formatDate(t.tax_date) },
                { header: 'Tanggal posting', cell: (t) => formatDate(t.posting_date) },
                { header: 'Pihak', cell: (t) => (t.counterparty_name ? <>{t.counterparty_name}{t.counterparty_tax_id && <div className="muted">{t.counterparty_tax_id}</div>}</> : <span className="muted">—</span>) },
                { header: 'Kode pajak', cell: (t) => <><span className="mono">{t.tax_code}</span><div className="muted">{t.tax_name}</div></> },
                { header: 'Arah', cell: (t) => <>{taxDirectionLabels[t.direction] ?? t.direction}{!t.is_recoverable && <div className="muted">Tidak dikreditkan</div>}</> },
                { header: 'Tarif', align: 'right', cell: (t) => <span className="money">{formatAmount(t.rate)}%</span> },
                { header: 'Dasar', align: 'right', cell: (t) => <Amount value={t.base_amount} currency={t.currency} functional={t.functional_base_amount} /> },
                { header: 'Pajak', align: 'right', cell: (t) => <Amount value={t.tax_amount} currency={t.currency} functional={t.functional_tax_amount} /> },
                { header: 'Status', cell: (t) => <StatusBadge status={t.status} /> },
              ]}
            />
            <Pagination page={rows.data.current_page} lastPage={rows.data.last_page} total={rows.data.total} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}

/** The document a tax line came from; a link when the user may open that kind of document. */
function Source({ tx }: { tx: TaxTransaction }) {
  const { can } = useCapabilities()
  const link = SOURCE_LINKS[tx.source_type]
  const number = tx.document_number ?? 'Belum bernomor'
  return (
    <>
      {link && can(link.permission) ? <Link to={`${link.path}/${tx.source_id}`} className="mono">{number}</Link> : <span className="mono">{number}</span>}
      <div className="muted">{sourceLabel(tx.source_type)}{tx.line_number > 0 && ` · baris ${tx.line_number}`}</div>
    </>
  )
}

/** An amount in the document's currency; a foreign document also shows the functional amount the report adds up. */
function Amount({ value, currency, functional }: { value: string; currency?: string | null; functional?: string | null }) {
  return (
    <>
      <span className="money">{formatAmount(value)}</span>
      {currency && <div className="muted">{currency}{functional != null && ` · setara ${formatAmount(functional)}`}</div>}
    </>
  )
}
