import type { ReactNode } from 'react'
import { Card, EmptyState } from '../../../components/ui'
import { formatAmount } from '../../../lib/accounting'
import { Money } from '../../accounting/shared'
import { BudgetChecks, IntegrityDetails } from './IntegrityDetails'
import { dateText, modeLabels, reliabilityReasons, reliableText, statementTitle } from './labels'
import { Reliability } from './Reliability'
import { ReportHeader } from './ReportHeader'
import { StatementTable, type AmountColumn } from './StatementTable'
import type { ComparativeAccount, ComparativeLine, ComparativeReport } from './types'

const Percent = ({ value }: { value: string | null }) => <span className="money">{value == null ? '—' : `${formatAmount(value)}%`}</span>

const Head = ({ title, hint }: { title: string; hint?: string }): ReactNode => (
  <>{title}{hint && <div className="muted" style={{ fontWeight: 400, textTransform: 'none', letterSpacing: 0 }}>{hint}</div>}</>
)

/** What the comparison column holds: the other date or period, or the budget with its version. */
function comparisonText(r: ComparativeReport): string {
  if (r.mode === 'BUDGET') {
    const b = r.comparison.budget
    const version = r.comparison.version?.label ? ` · ${r.comparison.version.label}` : ''
    return b ? `${b.code} · ${b.name}${version}` : 'Anggaran'
  }
  return dateText(r.comparison)
}

function columns(r: ComparativeReport): AmountColumn<ComparativeLine>[] {
  const strong = (l: ComparativeLine) => l.emphasis || l.line_type === 'SUBTOTAL' || l.line_type === 'CALCULATED_TOTAL'
  return [
    { key: 'current', header: <Head title="Saat ini" hint={dateText(r)} />, cell: (l) => <Money value={l.current} strong={strong(l)} /> },
    { key: 'comparison', header: <Head title={modeLabels[r.mode]} hint={comparisonText(r)} />, cell: (l) => <Money value={l.comparison} strong={strong(l)} /> },
    { key: 'variance', header: <Head title="Selisih" hint="saat ini − pembanding" />, cell: (l) => <Money value={l.variance} strong={strong(l)} /> },
    { key: 'variance_pct', header: <Head title="Selisih %" hint="terhadap pembanding" />, cell: (l) => <Percent value={l.variance_pct} /> },
  ]
}

const accountRows = (line: ComparativeLine) =>
  line.accounts.map((a: ComparativeAccount) => ({
    key: a.id,
    label: <><span className="mono">{a.code}</span> · {a.name}</>,
    cells: [<Money key="c" value={a.current} />, <Money key="p" value={a.comparison} />, <Money key="v" value={a.variance} />, <Percent key="pct" value={a.variance_pct} />],
  }))

/** A comparative report as the API returned it: header, verdict on both sides, the lines with their variances, and the integrity of each side. */
export function ComparativeView({ report, showAccounts }: { report: ComparativeReport; showAccounts: boolean }) {
  const { current, comparison, budget } = report.integrity
  const reasons = [...reliabilityReasons(current).map((x) => `sisi saat ini: ${x}`), ...(comparison ? reliabilityReasons(comparison).map((x) => `sisi pembanding: ${x}`) : [])]
  const reliable = current.reliable && (comparison?.reliable ?? true)
  const title = `Komparatif ${statementTitle(report.statement)}`
  return (
    <>
      <ReportHeader
        title={title}
        dates={report}
        definition={report.definition}
        currency={report.currency}
        generatedAt={report.generated_at}
        complete={report.complete}
        extra={<div><dt>Dibandingkan dengan</dt><dd>{modeLabels[report.mode]} · {comparisonText(report)}</dd></div>}
      />
      <Reliability complete={report.complete} reliable={reliable} reasons={reasons} confirmed={`${reliableText({ statement: report.statement })} Berlaku untuk kedua sisi perbandingan.`} warnings={report.warnings} />
      <Card flush>
        {report.lines.length === 0 ? (
          <EmptyState title="Belum ada angka">Tidak ada baris dengan angka di kedua sisi. Pilih tanggal atau periode lain, atau tampilkan baris nol.</EmptyState>
        ) : (
          <StatementTable rows={report.lines} columns={columns(report)} caption={`Baris ${title.toLowerCase()}`} expand={showAccounts ? accountRows : undefined} />
        )}
      </Card>
      <p className="muted preview-note">Selisih adalah angka saat ini dikurangi angka pembanding, dihitung server; persen terhadap nilai mutlak pembanding dan kosong bila pembanding nol.</p>
      <IntegrityDetails kind={report.statement} integrity={current} title="Pemeriksaan: sisi saat ini" />
      {comparison && <IntegrityDetails kind={report.statement} integrity={comparison} title="Pemeriksaan: sisi pembanding" />}
      {budget && <BudgetChecks budget={budget} />}
    </>
  )
}
