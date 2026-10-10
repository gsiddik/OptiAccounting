import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, Stat } from '../../components/ui'
import { api } from '../../lib/api'
import { formatAmount } from '../../lib/accounting'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { budgetGroupLabels } from '../../lib/oa4Labels'
import { API } from '../../lib/operational'
import { useDimensions } from '../accounting/data'
import { DimensionFilters, ExportButton, Filters, Money } from '../operational/shared'
import { useBudgetOptions, useFiscalPeriods } from './data'
import type { BudgetVsActual as Report, GroupBy, ReportRow } from './types'

const GROUPS = Object.keys(budgetGroupLabels) as GroupBy[]

/** Variance as the server reports it ("favorable" is the server's call, since it depends on the account's nature). */
function Variance({ value, favorable }: { value: string; favorable: boolean | null }) {
  const tone = favorable === null ? 'neutral' : favorable ? 'ok' : 'bad'
  return <Badge tone={tone}><span className="money">{formatAmount(value)}</span></Badge>
}

const pct = (value: string | null) => (value === null ? '—' : `${formatAmount(value)}%`)

export default function BudgetVsActual() {
  const [params] = useSearchParams()
  const { budgets, loading: budgetsLoading } = useBudgetOptions()
  const { catalog } = useDimensions()
  const [f, setF] = useState({ budget_id: params.get('budget_id') ?? '', version_id: '', group_by: 'account' as GroupBy, period_from: '', period_to: '', as_of: '', branch_id: '', business_unit_id: '', cost_center_id: '' })
  // Without a chosen budget the first one that has a version in force is selected, so the page opens on a report.
  const fallback = budgets.find((b) => b.versions?.some((v) => v.status === 'ACTIVE')) ?? budgets[0]
  const budgetId = f.budget_id || fallback?.id || ''
  const budget = budgets.find((b) => b.id === budgetId)
  const { periods } = useFiscalPeriods(budget?.fiscal_year_id)
  const change = (patch: Partial<typeof f>) => setF((s) => ({ ...s, ...patch }))

  const query = Object.fromEntries(Object.entries({ ...f, budget_id: budgetId }).filter(([, v]) => v !== '')) as Record<string, string>
  const report = useResource(async () => (budgetId ? (await api.get<Report>(`${API}/budget-vs-actual`, { params: query })).data : null), [JSON.stringify(query)])
  const versions = (budget?.versions ?? []).filter((v) => ['ACTIVE', 'SUPERSEDED', 'APPROVED'].includes(v.status))

  return (
    <>
      <PageHeader
        title="Anggaran vs aktual"
        description="Membandingkan anggaran versi yang berlaku dengan realisasi dari jurnal yang sudah diposting. Selisih dihitung oleh server; anggaran tidak pernah membuat atau mengubah jurnal."
        actions={budgetId ? <ExportButton path={`${API}/budget-vs-actual/export`} params={query} filename={`anggaran-vs-aktual_${budget?.code ?? 'anggaran'}.csv`} /> : undefined}
      />
      <Card flush>
        <div className="card-body">
          <Filters>
            <select className="select" aria-label="Anggaran" value={budgetId} onChange={(e) => change({ budget_id: e.target.value, version_id: '', period_from: '', period_to: '' })}>
              <option value="">Pilih anggaran…</option>
              {budgets.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
            </select>
            <select className="select" aria-label="Versi" value={f.version_id} onChange={(e) => change({ version_id: e.target.value })}>
              <option value="">Versi yang berlaku</option>
              {versions.map((v) => <option key={v.id} value={v.id}>Versi {v.version_number} · {v.label}</option>)}
            </select>
            <select className="select" aria-label="Kelompokkan" value={f.group_by} onChange={(e) => change({ group_by: e.target.value as GroupBy })}>
              {GROUPS.map((g) => <option key={g} value={g}>{budgetGroupLabels[g]}</option>)}
            </select>
            {periods.length > 0 && (
              <>
                <select className="select" aria-label="Dari periode" value={f.period_from} onChange={(e) => change({ period_from: e.target.value })}>
                  <option value="">Dari periode pertama</option>
                  {periods.map((p) => <option key={p.id} value={p.id}>{p.code}</option>)}
                </select>
                <select className="select" aria-label="Sampai periode" value={f.period_to} onChange={(e) => change({ period_to: e.target.value })}>
                  <option value="">Sampai periode terakhir</option>
                  {periods.map((p) => <option key={p.id} value={p.id}>{p.code}</option>)}
                </select>
              </>
            )}
            <label className="inline-field">Per tanggal <input className="input" type="date" value={f.as_of} onChange={(e) => change({ as_of: e.target.value })} /></label>
            <DimensionFilters catalog={catalog} value={f} onChange={change} />
          </Filters>
        </div>

        {!budgetId ? (
          budgetsLoading ? <Loading /> : <EmptyState title="Belum ada anggaran">Buat anggaran dan aktifkan satu versinya untuk melihat perbandingan dengan realisasi.</EmptyState>
        ) : report.loading && !report.data ? (
          <Loading />
        ) : report.error || !report.data ? (
          <ErrorNotice error={report.error} onRetry={report.reload} />
        ) : (
          <ReportView report={report.data} />
        )}
      </Card>
    </>
  )
}

function ReportView({ report }: { report: Report }) {
  const t = report.totals
  const grouped = report.group_by
  const dimension = (r: ReportRow) => r[grouped === 'branch' ? 'branch' : grouped === 'business_unit' ? 'business_unit' : 'cost_center']
  const label = (r: ReportRow) => {
    if (grouped === 'branch' || grouped === 'business_unit' || grouped === 'cost_center') {
      const d = dimension(r)
      return d?.code ? <><span className="mono">{d.code}</span> · {d.name}</> : <span className="muted">Tanpa {grouped === 'branch' ? 'cabang' : grouped === 'business_unit' ? 'unit bisnis' : 'pusat biaya'}</span>
    }
    return <>{r.code && <span className="mono">{r.code}</span>}{r.code && r.name ? ' · ' : ''}{r.name}{r.description && <div className="muted">{r.description}</div>}</>
  }

  return (
    <>
      {!report.complete && <div className="card-body"><Banner tone="warn">Cakupan data Anda hanya mencakup sebagian anggaran atau realisasi, sehingga angka ini bukan total seluruh perusahaan.</Banner></div>}
      <div className="card-body">
        <p className="muted" style={{ margin: '0 0 12px' }}>
          {report.budget.code} · {report.budget.name} · Versi {report.version.version_number} ({report.version.label}) · tahun fiskal {report.fiscal_year.code} · {formatDate(report.from)} – {formatDate(report.to)} · {report.budget.currency}
        </p>
        <div className="grid grid-4">
          <Stat label="Anggaran" value={<Money value={t.budget} strong />} />
          <Stat label="Aktual" value={<Money value={t.actual} strong />} />
          <Stat label="Selisih" value={<Variance value={t.variance} favorable={t.favorable} />} hint={pct(t.variance_pct)} />
          <Stat label="Aktual tanpa anggaran" value={<Money value={t.unbudgeted_actual} />} hint="Realisasi pada akun yang tidak dianggarkan" />
        </div>
        {report.basis && <p className="muted" style={{ marginBottom: 0 }}>Dasar perhitungan: hanya baris jurnal yang sudah diposting, jurnal saldo awal tidak dihitung, jumlah dalam mata uang fungsional dan searah saldo normal tiap akun.</p>}
      </div>
      {report.rows.length === 0 ? (
        <EmptyState title="Tidak ada data">Tidak ada baris anggaran maupun realisasi untuk filter ini.</EmptyState>
      ) : (
        <DataTable
          caption="Anggaran versus aktual"
          rows={report.rows}
          rowKey={(r) => `${r.key ?? 'none'}|${r.period?.id ?? ''}|${r.description ?? ''}|${r.code ?? ''}`}
          scroll
          columns={[
            { header: grouped === 'period' ? 'Periode' : grouped === 'account' ? 'Akun' : budgetGroupLabels[grouped].replace('Per ', ''), primary: true, cell: (r) => <>{label(r)}{r.unbudgeted && <div><Badge tone="warn">Tanpa anggaran</Badge></div>}</> },
            ...(grouped === 'line' ? [{ header: 'Periode', cell: (r: ReportRow) => r.period?.code ?? '—' }] : []),
            { header: 'Anggaran', align: 'right' as const, cell: (r: ReportRow) => <Money value={r.budget} /> },
            { header: 'Aktual', align: 'right' as const, cell: (r: ReportRow) => <Money value={r.actual} /> },
            { header: 'Selisih', align: 'right' as const, cell: (r: ReportRow) => <Variance value={r.variance} favorable={r.favorable} /> },
            { header: '%', align: 'right' as const, cell: (r: ReportRow) => pct(r.variance_pct) },
          ]}
        />
      )}
    </>
  )
}
