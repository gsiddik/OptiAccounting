import { useCapabilities } from '../../../lib/capabilities'
import { useBudgetOptions } from '../../budget/data'
import type { ComparativeChoice } from './data'
import { modeLabels, statementTitle } from './labels'
import { STATEMENT_KINDS, type CompareMode, type StatementKind } from './types'

/** The budget to compare with: every budget the user may read; the API picks the approved version in force. */
function BudgetSelect({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const { budgets, loading } = useBudgetOptions()
  return (
    <select className="select" aria-label="Anggaran" value={value} onChange={(e) => onChange(e.target.value)} disabled={loading}>
      <option value="">Pilih anggaran</option>
      {budgets.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
    </select>
  )
}

/**
 * Which statement to compare and with what. A budget comparison exists only for the profit and loss and needs the budget permission and module
 * (cosmetic: the API enforces both), so the choice is offered only then.
 */
export function ComparativeControls({ value, onChange }: { value: ComparativeChoice; onChange: (value: ComparativeChoice) => void }) {
  const { can, moduleMode } = useCapabilities()
  const budgetAllowed = value.statement === 'PROFIT_LOSS' && can('accounting.budget.view') && moduleMode('ACCOUNTING_BUDGET') !== 'NONE'
  const modes: CompareMode[] = budgetAllowed ? ['PRIOR_PERIOD', 'PRIOR_YEAR', 'BUDGET'] : ['PRIOR_PERIOD', 'PRIOR_YEAR']
  const statement = (next: StatementKind) => onChange({ ...value, statement: next, mode: next !== 'PROFIT_LOSS' && value.mode === 'BUDGET' ? 'PRIOR_PERIOD' : value.mode })
  return (
    <>
      <select className="select" aria-label="Laporan" value={value.statement} onChange={(e) => statement(e.target.value as StatementKind)}>
        {STATEMENT_KINDS.map((k) => <option key={k} value={k}>{statementTitle(k)}</option>)}
      </select>
      <select className="select" aria-label="Pembanding" value={value.mode} onChange={(e) => onChange({ ...value, mode: e.target.value as CompareMode })}>
        {modes.map((m) => <option key={m} value={m}>{modeLabels[m]}</option>)}
      </select>
      {value.mode === 'BUDGET' && <BudgetSelect value={value.budget_id} onChange={(budget_id) => onChange({ ...value, budget_id })} />}
    </>
  )
}
