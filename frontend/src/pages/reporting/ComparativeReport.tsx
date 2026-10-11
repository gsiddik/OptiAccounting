import { useState } from 'react'
import { Card, EmptyState, Loading, PageHeader } from '../../components/ui'
import { ExportButton } from '../operational/shared'
import { ComparativeControls } from './statements/ComparativeControls'
import { ComparativeView } from './statements/ComparativeView'
import { ENDPOINTS, comparativeParams, emptyFilters, useBusinessDate, useComparative, type ComparativeChoice, type FilterState } from './statements/data'
import { fileDate, modeLabels } from './statements/labels'
import { StatementError } from './statements/StatementError'
import { StatementFilters } from './statements/StatementFilters'

/**
 * Laporan komparatif: a statement beside its prior period, prior year or budget (GET reports/comparative). The variance and its percentage are the
 * API's own columns; the page only chooses what to compare and shows what comes back.
 */
export default function ComparativeReport() {
  const businessDate = useBusinessDate()
  const [choice, setChoice] = useState<ComparativeChoice>({ statement: 'PROFIT_LOSS', mode: 'PRIOR_PERIOD', budget_id: '' })
  const [filters, setFilters] = useState<FilterState>(() => emptyFilters(businessDate))
  const [showAccounts, setShowAccounts] = useState(false)
  const waitingForBudget = choice.mode === 'BUDGET' && !choice.budget_id
  const params = waitingForBudget ? null : comparativeParams(choice, filters)
  const report = useComparative(params)
  const data = report.data

  function choose(next: ComparativeChoice) {
    if (next.statement !== choice.statement) setFilters((f) => ({ ...f, definition_id: '' })) // a definition belongs to one statement
    setChoice(next)
  }

  return (
    <>
      <PageHeader
        title="Laporan komparatif"
        description="Laporan keuangan berdampingan dengan periode sebelumnya, tahun sebelumnya, atau anggaran. Selisih dan persennya dihitung server dari definisi laporan yang sama di kedua sisi."
        actions={params && <ExportButton path={ENDPOINTS.comparativeExport} params={params} filename={`komparatif_${choice.statement.toLowerCase()}_${modeLabels[choice.mode].toLowerCase().replace(/ /g, '-')}_${data ? fileDate(data) : 'laporan'}.csv`} />}
      />
      <Card flush>
        <div className="card-body">
          <StatementFilters kind={choice.statement} value={filters} onChange={setFilters} lead={<ComparativeControls value={choice} onChange={choose} />} showAccounts={showAccounts} onShowAccounts={setShowAccounts} />
          {choice.mode === 'BUDGET' && (
            <p className="muted preview-note" style={{ marginTop: 8 }}>Anggaran disimpan per periode akuntansi: pilih periode, atau rentang yang dimulai di hari pertama dan berakhir di hari terakhir periode pada tahun fiskal anggaran.</p>
          )}
        </div>
      </Card>
      {waitingForBudget ? (
        <Card flush><EmptyState title="Pilih anggaran">Pilih anggaran yang menjadi pembanding laba rugi. Server memakai versi anggaran yang berlaku.</EmptyState></Card>
      ) : report.error ? (
        <Card flush><StatementError error={report.error} onRetry={report.reload} /></Card>
      ) : !data ? (
        <Loading />
      ) : (
        <>
          {report.loading && <Loading label="Memuat ulang…" />}
          <ComparativeView report={data} showAccounts={showAccounts} />
        </>
      )}
    </>
  )
}
