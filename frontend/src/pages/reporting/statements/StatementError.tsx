import { Link } from 'react-router-dom'
import { ErrorNotice } from '../../../components/ui'
import { ApiError } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { PATHS } from './data'
import { statementMessage } from './labels'

const DEFINITION_CODES = ['REPORT_DEFINITION_NOT_FOUND', 'REPORT_DEFINITION_INVALID']

/** A refusal of the statement endpoints in Indonesian, with a way out: a missing or broken report definition links to where it is fixed. */
export function StatementError({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const { can } = useCapabilities()
  const api = error instanceof ApiError ? error : null
  const text = api ? statementMessage(api.code, api.details) : null
  const definitionIssue = api?.code != null && DEFINITION_CODES.includes(api.code)
  return (
    <>
      <ErrorNotice error={text ? new Error(text) : error} onRetry={onRetry} />
      {definitionIssue && can('accounting.report_definition.view') && (
        <div className="card-body"><Link className="btn" to={PATHS.definitions}>Buka Pemetaan laporan</Link></div>
      )}
    </>
  )
}
