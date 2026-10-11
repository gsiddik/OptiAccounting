import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { Banner, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { downloadCsv, useAccountingAccess, type TrialBalanceReport } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { DimensionFilters, Filters, Money, Side } from './shared'
import { useCalendar, useDefaultRange, useDimensions } from './data'
import { AccountFilters, LedgerWindow } from './ledger-filters'
import { ledgerError } from './ledger-labels'
import { NO_ACCOUNT_FILTER, accountFilterParams, windowParams } from './ledger-params'

export default function TrialBalance() {
  const { can } = useAccountingAccess()
  const { periods, years } = useCalendar()
  const { catalog } = useDimensions()
  const initial = useDefaultRange()
  const [range, setRange] = useState(initial)
  const [year, setYear] = useState('')
  const [acc, setAcc] = useState(NO_ACCOUNT_FILTER)
  const [f, setF] = useState({ branch_id: '', business_unit_id: '', cost_center_id: '', hierarchy: true, include_zero: false })
  const [exportError, setExportError] = useState<unknown>(null)

  const params = () => {
    const out: Record<string, string | number> = { ...windowParams(range, year), ...accountFilterParams(acc), hierarchy: f.hierarchy ? 1 : 0, include_zero: f.include_zero ? 1 : 0 }
    for (const k of ['branch_id', 'business_unit_id', 'cost_center_id'] as const) if (f[k]) out[k] = f[k]
    return out
  }
  const report = useResource(async () => (await api.get<TrialBalanceReport>('/app/accounting/trial-balance', { params: params() })).data, [range, year, acc, f])
  const r = report.data

  return (
    <>
      <PageHeader
        title="Neraca saldo"
        description="Saldo awal, mutasi, dan saldo akhir setiap akun, dihitung server dari jurnal terposting. Saldo awal sudah memuat jurnal saldo awal."
        actions={can('accounting.report.export') && <Button onClick={() => void downloadCsv('/app/accounting/trial-balance/export', params(), 'neraca-saldo.csv').then(() => setExportError(null), setExportError)}>Ekspor CSV</Button>}
      />
      {exportError != null && <Banner tone="bad">{describeError(exportError)}</Banner>}

      <Card flush>
        <div className="card-body">
          <Filters>
            <LedgerWindow range={range} year={year} years={years} periods={periods} defaultRange={initial} onChange={(next) => { setRange(next.range); setYear(next.year) }} />
            <DimensionFilters catalog={catalog} value={f} onChange={(patch) => setF((s) => ({ ...s, ...patch }))} />
            <AccountFilters value={acc} onChange={(patch) => setAcc((s) => ({ ...s, ...patch }))} />
            <label className="check"><input type="checkbox" checked={f.hierarchy} onChange={(e) => setF((s) => ({ ...s, hierarchy: e.target.checked }))} /> Tampilkan akun induk</label>
            <label className="check"><input type="checkbox" checked={f.include_zero} onChange={(e) => setF((s) => ({ ...s, include_zero: e.target.checked }))} /> Sertakan saldo nol</label>
          </Filters>
        </div>
      </Card>

      {report.loading && !r ? (
        <Loading />
      ) : report.error || !r ? (
        <ErrorNotice error={ledgerError(report.error)} onRetry={report.reload} />
      ) : (
        <>
          {r.reconciliation.reconciled === true && <Banner tone="ok">Seimbang: total debit sama dengan total kredit pada saldo awal, mutasi, dan saldo akhir.</Banner>}
          {r.reconciliation.reconciled === false && (
            <Banner tone="bad">
              Neraca saldo tidak seimbang. Selisih saldo awal <Money value={r.reconciliation.opening.difference} />, mutasi <Money value={r.reconciliation.movement.difference} />, saldo akhir <Money value={r.reconciliation.ending.difference} />. Hubungi administrator; pembukuan tidak boleh menghasilkan keadaan ini.
            </Banner>
          )}
          {r.reconciliation.reconciled === null && <Banner tone="info">Laporan ini hanya memuat sebagian data (cakupan data Anda, filter dimensi, akun, atau mata uang), jadi keseimbangannya tidak dapat diuji.</Banner>}

          <Card flush>
            {r.data.length === 0 ? (
              <EmptyState title="Tidak ada saldo">Belum ada jurnal terposting pada rentang ini.</EmptyState>
            ) : (
              <>
                <DataTable
                  caption="Neraca saldo"
                  scroll
                  rows={r.data}
                  rowKey={(row) => row.account_id}
                  columns={[
                    { header: 'Akun', primary: true, cell: (row) => <span style={{ paddingLeft: row.depth * 16, fontWeight: row.is_header ? 700 : 400 }}><span className="mono">{row.code}</span> · {row.name}</span> },
                    { header: 'Saldo awal debit', align: 'right', cell: (row) => <Side value={row.opening_debit} /> },
                    { header: 'Saldo awal kredit', align: 'right', cell: (row) => <Side value={row.opening_credit} /> },
                    { header: 'Mutasi debit', align: 'right', cell: (row) => <Side value={row.debit} /> },
                    { header: 'Mutasi kredit', align: 'right', cell: (row) => <Side value={row.credit} /> },
                    { header: 'Saldo akhir debit', align: 'right', cell: (row) => <Side value={row.ending_debit} /> },
                    { header: 'Saldo akhir kredit', align: 'right', cell: (row) => <Side value={row.ending_credit} /> },
                  ]}
                />
                <div className="table-total tb-total">
                  <span>Total</span>
                  <span>Awal <Money value={r.totals.opening_debit} strong /> / <Money value={r.totals.opening_credit} strong /></span>
                  <span>Mutasi <Money value={r.totals.debit} strong /> / <Money value={r.totals.credit} strong /></span>
                  <span>Akhir <Money value={r.totals.ending_debit} strong /> / <Money value={r.totals.ending_credit} strong /></span>
                </div>
                <p className="card-body muted preview-note">Jurnal penutup akhir tahun (sumber YEAR_END_CLOSING) ikut dihitung; saldo akun pendapatan dan beban setelah penutupan tahun terbaca nol.</p>
              </>
            )}
          </Card>
        </>
      )}
    </>
  )
}
