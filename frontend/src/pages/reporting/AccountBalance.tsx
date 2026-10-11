import { useState } from 'react'
import { Card, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { todayIn } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { useAccounts, useCalendar, useDefaultRange, useDimensions } from '../accounting/data'
import { ledgerError } from '../accounting/ledger-labels'
import { NO_ACCOUNT_FILTER } from '../accounting/ledger-params'
import { ExportButton } from '../operational/shared'
import { BalanceFilters } from './definitions/BalanceFilters'
import { BalanceView } from './definitions/BalanceView'
import { balanceParams, type BalanceState } from './definitions/balance'
import { ENDPOINTS } from './definitions/data'
import type { AccountBalanceReport } from './definitions/types'

export default function AccountBalance() {
  const { tenant } = useCapabilities()
  const { periods, years } = useCalendar()
  const { accounts } = useAccounts()
  const { catalog } = useDimensions()
  const defaultRange = useDefaultRange()
  const [state, setState] = useState<BalanceState>({
    mode: 'AS_OF', asOf: tenant?.business_date ?? todayIn(), range: defaultRange, year: '', account: '', acc: NO_ACCOUNT_FILTER,
    dims: { branch_id: '', business_unit_id: '', cost_center_id: '' }, hierarchy: true, includeZero: false,
  })
  const params = balanceParams(state)
  const report = useResource(async () => (await api.get<AccountBalanceReport>(ENDPOINTS.accountBalance, { params })).data, [JSON.stringify(params)])
  const r = report.data

  return (
    <>
      <PageHeader
        title="Saldo akun"
        description="Saldo satu akun, rentang akun atau kelompok akun pada tanggal tertentu atau dalam satu rentang, dihitung server dari jurnal yang sudah diposting. Angkanya sama dengan buku besar dan neraca saldo."
        actions={<ExportButton path={ENDPOINTS.accountBalanceExport} params={params} filename={`saldo-akun_${r?.as_of ?? r?.range?.to ?? 'laporan'}.csv`} />}
      />
      <Card flush>
        <div className="card-body">
          <BalanceFilters state={state} onChange={(patch) => setState((s) => ({ ...s, ...patch }))} accounts={accounts} years={years} periods={periods} catalog={catalog} defaultRange={defaultRange} />
        </div>
      </Card>
      {report.loading && !r ? <Loading /> : report.error || !r ? <ErrorNotice error={ledgerError(report.error)} onRetry={report.reload} /> : <BalanceView report={r} state={state} />}
    </>
  )
}
