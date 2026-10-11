import type { Account, FiscalYear, Period } from '../../../lib/accounting'
import { accountLabel, type DimensionCatalog, type Range } from '../../accounting/data'
import { AccountFilters, LedgerWindow } from '../../accounting/ledger-filters'
import { DimensionFilters, Filters } from '../../accounting/shared'
import type { BalanceState } from './balance'

type Props = { state: BalanceState; onChange: (patch: Partial<BalanceState>) => void; accounts: Account[]; years: FiscalYear[]; periods: Period[]; catalog: DimensionCatalog; defaultRange: Range }

/** Account, date (as of a day, or a period / fiscal year / dates), dimensions and account filters of the account balance report. */
export function BalanceFilters({ state, onChange, accounts, years, periods, catalog, defaultRange }: Props) {
  return (
    <Filters>
      <select className="select" aria-label="Akun" value={state.account} onChange={(e) => onChange({ account: e.target.value })}>
        <option value="">Semua akun</option>
        {accounts.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}{a.is_postable ? '' : ' (induk)'}</option>)}
      </select>
      <select className="select" aria-label="Jenis tanggal" value={state.mode} onChange={(e) => onChange({ mode: e.target.value as BalanceState['mode'] })}>
        <option value="AS_OF">Saldo per tanggal</option>
        <option value="PERIOD">Saldo awal, mutasi, dan akhir pada rentang</option>
      </select>
      {state.mode === 'AS_OF' ? (
        <label className="inline-field">Per tanggal <input className="input" type="date" value={state.asOf} onChange={(e) => onChange({ asOf: e.target.value })} /></label>
      ) : (
        <LedgerWindow range={state.range} year={state.year} years={years} periods={periods} defaultRange={defaultRange} onChange={(next) => onChange({ range: next.range, year: next.year })} />
      )}
      <DimensionFilters catalog={catalog} value={state.dims} onChange={(patch) => onChange({ dims: { ...state.dims, ...patch } })} />
      <AccountFilters value={state.acc} onChange={(patch) => onChange({ acc: { ...state.acc, ...patch } })} />
      <label className="check"><input type="checkbox" checked={state.hierarchy} onChange={(e) => onChange({ hierarchy: e.target.checked })} /> Tampilkan akun induk</label>
      <label className="check"><input type="checkbox" checked={state.includeZero} onChange={(e) => onChange({ includeZero: e.target.checked })} /> Sertakan saldo nol</label>
    </Filters>
  )
}
