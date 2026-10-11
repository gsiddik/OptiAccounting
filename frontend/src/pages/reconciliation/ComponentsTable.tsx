import { DataTable } from '../../components/DataTable'
import { EmptyState } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { Money } from '../operational/shared'
import { ReconBadge } from './components'
import { componentReference, componentTitle, typeLabels } from './labels'
import { isCompareRow, isTaxRow, type CompareRow, type ReconComponent, type ReconType, type TaxRow } from './types'

const text = (value: unknown): string | null => (typeof value === 'string' ? value : null)

/** What a component adds to its title, as the API reported it (nothing is derived here). */
function Extras({ c }: { c: ReconComponent }) {
  const book = text(c.book_balance)
  const other = text(c.other_activity)
  const foreign = text(c.foreign_outstanding)
  return (
    <>
      {(book !== null || other !== null) && <div className="muted">Saldo buku <Money value={book} />{other !== null && <> · aktivitas lain di buku besar <Money value={other} /></>}</div>}
      {foreign !== null && <div className="muted">Saldo valas terbuka {c.currency} <Money value={foreign} />{typeof c.documents === 'number' ? ` · ${formatNumber(c.documents)} dokumen` : ''}</div>}
    </>
  )
}

/** Source against ledger per component. An informational component is marked and never decides the result. */
export function ComponentsTable({ type, components }: { type: ReconType; components: ReconComponent[] }) {
  if (components.length === 0) return <EmptyState title="Belum ada komponen">Tidak ada yang dibandingkan pada tanggal ini.</EmptyState>
  const withBreakdown = components.filter((c) => (c.breakdown?.length ?? 0) > 0)
  return (
    <>
      <DataTable
        caption={`Komponen ${typeLabels[type]}`}
        rows={components}
        rowKey={(c) => `${c.code}|${componentReference(c) ?? ''}`}
        scroll
        columns={[
          {
            header: 'Komponen',
            primary: true,
            cell: (c) => (
              <>
                {componentTitle(type, c)}
                {componentReference(c) && <div className="muted mono">{componentReference(c)}</div>}
                <Extras c={c} />
              </>
            ),
          },
          { header: 'Saldo sumber', align: 'right', cell: (c) => <Money value={c.source_balance} /> },
          { header: 'Saldo buku besar', align: 'right', cell: (c) => <Money value={c.gl_balance} /> },
          { header: 'Selisih', align: 'right', cell: (c) => <Money value={c.difference} strong={c.status === 'MISMATCH'} /> },
          { header: 'Status', cell: (c) => <ReconBadge status={c.informational ? 'INFORMATIONAL' : c.status} /> },
        ]}
      />
      {withBreakdown.map((c) => <Breakdown key={`${c.code}|${componentReference(c) ?? ''}`} type={type} component={c} />)}
    </>
  )
}

function Breakdown({ type, component: c }: { type: ReconType; component: ReconComponent }) {
  const rows = c.breakdown ?? []
  const title = componentTitle(type, c)
  const compared = rows.filter(isCompareRow)
  const taxes = rows.filter(isTaxRow)
  const shown = compared.length > 0 ? compared.length : taxes.length
  if (shown === 0) return null
  const total = c.breakdown_total ?? shown

  return (
    <details className="card-body">
      <summary>Rincian: {title} ({formatNumber(total)})</summary>
      {compared.length > 0 ? <CompareTable title={title} rows={compared} /> : <TaxTable title={title} rows={taxes} />}
      {c.breakdown_truncated && <p className="muted preview-note">Menampilkan {formatNumber(shown)} dari {formatNumber(total)} baris yang berselisih.</p>}
    </details>
  )
}

const nameOf = (r: CompareRow): string => {
  const ref = r.account ?? r
  return [ref.code, ref.name].filter(Boolean).join(' · ') || '—'
}

function CompareTable({ title, rows }: { title: string; rows: CompareRow[] }) {
  return (
    <DataTable
      caption={`Rincian ${title}`}
      rows={rows}
      rowKey={(r) => nameOf(r)}
      scroll
      columns={[
        { header: 'Nama', primary: true, cell: (r) => nameOf(r) },
        { header: 'Saldo sumber', align: 'right', cell: (r) => <Money value={r.source_balance} /> },
        { header: 'Saldo buku besar', align: 'right', cell: (r) => <Money value={r.gl_balance} /> },
        { header: 'Selisih', align: 'right', cell: (r) => <Money value={r.difference} strong={r.status !== 'MATCHED'} /> },
        ...(rows.some((r) => r.status) ? [{ header: 'Status', cell: (r: CompareRow) => <ReconBadge status={r.status} /> }] : []),
      ]}
    />
  )
}

function TaxTable({ title, rows }: { title: string; rows: TaxRow[] }) {
  return (
    <DataTable
      caption={`Rincian ${title}`}
      rows={rows}
      rowKey={(r) => r.tax_code}
      scroll
      columns={[
        { header: 'Kode pajak', primary: true, cell: (r) => <><span className="mono">{r.tax_code}</span> · {r.tax_name}</> },
        { header: 'Jumlah pajak', align: 'right', cell: (r) => <Money value={r.tax_amount} /> },
        { header: 'Transaksi', align: 'right', cell: (r) => formatNumber(r.transactions) },
      ]}
    />
  )
}
