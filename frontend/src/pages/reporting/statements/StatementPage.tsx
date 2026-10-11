import { useState } from 'react'
import { Card, EmptyState, Loading, PageHeader } from '../../../components/ui'
import { useCapabilities } from '../../../lib/capabilities'
import { Money } from '../../accounting/shared'
import { ExportButton } from '../../operational/shared'
import { ENDPOINTS, drilldownParams, emptyFilters, statementParams, useBusinessDate, useStatement, type FilterState } from './data'
import { DrilldownPanel } from './DrilldownPanel'
import { IntegrityDetails } from './IntegrityDetails'
import { STATEMENT_META, datesOf, fileDate, reliabilityReasons, reliableText } from './labels'
import { Reliability } from './Reliability'
import { ReportHeader } from './ReportHeader'
import { StatementError } from './StatementError'
import { StatementFilters } from './StatementFilters'
import { StatementTable, type AmountColumn } from './StatementTable'
import type { StatementKind, StatementLine, StatementReport } from './types'

/**
 * One page for the balance sheet, the profit and loss and the cash flow: filters, the report header, the verdict on its reliability, the lines as
 * the API returned them, and the integrity proof. Every figure is the API's; a line with an amount opens its drill-down for users who may read the ledger.
 */
export function StatementPage({ kind }: { kind: StatementKind }) {
  const meta = STATEMENT_META[kind]
  const { can } = useCapabilities()
  const businessDate = useBusinessDate()
  const [filters, setFilters] = useState<FilterState>(() => emptyFilters(kind === 'BALANCE_SHEET' ? businessDate : ''))
  const [showAccounts, setShowAccounts] = useState(false)
  const [drill, setDrill] = useState<string | null>(null)
  const params = statementParams(kind, filters)
  const report = useStatement(kind, params)
  const data = report.data

  return (
    <>
      <PageHeader
        title={meta.title}
        description={meta.description}
        actions={<ExportButton path={ENDPOINTS.export(kind)} params={params} filename={`${meta.file}_${data ? fileDate(datesOf(data)) : 'laporan'}.csv`} />}
      />
      <Card flush>
        <div className="card-body">
          <StatementFilters kind={kind} value={filters} onChange={setFilters} showAccounts={showAccounts} onShowAccounts={setShowAccounts} />
        </div>
      </Card>
      {report.error ? (
        <Card flush><StatementError error={report.error} onRetry={report.reload} /></Card>
      ) : !data ? (
        <Loading />
      ) : (
        <>
          {report.loading && <Loading label="Memuat ulang…" />}
          <ReportHeader title={meta.title} dates={datesOf(data)} definition={data.definition} currency={data.currency} generatedAt={data.generated_at} complete={data.complete} />
          <Reliability complete={data.complete} reliable={data.integrity.reliable} reasons={reliabilityReasons(data.integrity)} confirmed={reliableText(data)} warnings={data.warnings} />
          <Card flush>
            {data.lines.length === 0 ? (
              <EmptyState title="Belum ada angka">Tidak ada baris dengan angka pada tanggal atau periode ini. Pilih tanggal lain, atau tampilkan baris nol.</EmptyState>
            ) : (
              <StatementTable
                rows={data.lines}
                columns={columns(data)}
                caption={`Baris ${meta.title.toLowerCase()}`}
                onDrill={can('accounting.gl.view') ? setDrill : undefined}
                expand={showAccounts ? accountRows : undefined}
              />
            )}
          </Card>
          {can('accounting.gl.view') && <p className="muted preview-note">Pilih sebuah baris untuk melihat akun di baliknya dan membuka buku besarnya.</p>}
          <IntegrityDetails kind={kind} integrity={data.integrity} />
          {drill && (
            <DrilldownPanel kind={kind} line={drill} label={data.lines.find((l) => l.code === drill)?.label ?? drill} params={drilldownParams(data)} onClose={() => setDrill(null)} />
          )}
        </>
      )}
    </>
  )
}

const columns = (data: StatementReport): AmountColumn<StatementLine>[] => [
  { key: 'amount', header: data.currency ? `Jumlah (${data.currency})` : 'Jumlah', cell: (l) => <Money value={l.amount} strong={l.emphasis || l.line_type === 'SUBTOTAL' || l.line_type === 'CALCULATED_TOTAL'} /> },
]

const accountRows = (line: StatementLine) =>
  line.accounts.map((a) => ({ key: a.id, label: <><span className="mono">{a.code}</span> · {a.name}</>, cells: [<Money key="amount" value={a.amount} />] }))
