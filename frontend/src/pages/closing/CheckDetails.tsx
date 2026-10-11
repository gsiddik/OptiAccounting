import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate } from '../../lib/format'
import { statusLabel } from '../../lib/labels'
import { Money } from '../operational/shared'
import { Bullets, Facts, Tagged } from './components'
import { PATHS } from './data'
import { reconText, reconTypeLabels } from './labels'
import type { CloseCheck } from './types'

// The evidence behind one close check. The backend sends the facts (`details`, shape depends on the check code); this only lays them out.

type Row = Record<string, unknown>
const str = (v: unknown): string | null => (typeof v === 'string' ? v : null)
const num = (v: unknown): number | null => (typeof v === 'number' ? v : null)
const rowsOf = (v: unknown): Row[] => (Array.isArray(v) ? v.filter((r): r is Row => typeof r === 'object' && r !== null) : [])
const money = (v: unknown) => <Money value={str(v)} />

function Sample({ shown, total, children }: { shown: number; total: number | null; children: ReactNode }) {
  return (
    <>
      <Bullets>{children}</Bullets>
      {total !== null && total > shown && <p className="muted">Menampilkan {shown} dari {total}; sisanya tidak ditampilkan di sini.</p>}
    </>
  )
}

function Journals({ d }: { d: Row }) {
  const { can } = useCapabilities()
  const sample = rowsOf(d.sample)
  const byStatus = d.by_status && typeof d.by_status === 'object' ? Object.entries(d.by_status as Record<string, unknown>) : []
  return (
    <>
      <Facts rows={[['Jumlah jurnal', num(d.count)], ...byStatus.map(([status, n]): [string, ReactNode] => [statusLabel(status)[0], num(n)])]} />
      <Sample shown={sample.length} total={num(d.count)}>
        {sample.map((j) => {
          const label = `${str(j.description) ?? str(j.journal_type) ?? 'Jurnal'} · ${formatDate(str(j.posting_date))}`
          return <li key={String(j.id)}>{can('accounting.journal.view') ? <Link to={PATHS.journal(String(j.id))}>{label}</Link> : label}</li>
        })}
      </Sample>
    </>
  )
}

function Reconciliation({ d }: { d: Row }) {
  const { can } = useCapabilities()
  const type = str(d.type)
  const notes = Array.isArray(d.notes) ? d.notes.filter((n): n is string => typeof n === 'string') : []
  return (
    <>
      <Facts rows={[
        ['Jenis', type ? reconTypeLabels[type] ?? type : null],
        ['Status', <Tagged key="s" text={reconText(str(d.status))} />],
        ['Per tanggal', str(d.as_of) && formatDate(str(d.as_of))],
        ['Saldo sumber', str(d.source_balance) && money(d.source_balance)],
        ['Saldo buku besar', str(d.gl_balance) && money(d.gl_balance)],
        ['Selisih', str(d.difference) && <Money key="d" value={str(d.difference)} strong />],
      ]} />
      {d.complete === false && <p className="muted">Perbandingan tidak lengkap, sehingga tidak dihitung sebagai cocok.</p>}
      {notes.length > 0 && <Bullets>{notes.map((n) => <li key={n} className="muted">{n}</li>)}</Bullets>}
      {type && can('accounting.reconciliation.view') && <Link to={PATHS.reconciliation(type, str(d.as_of) ?? undefined)}>Buka rincian rekonsiliasi</Link>}
    </>
  )
}

function Depreciation({ d }: { d: Row }) {
  const { can } = useCapabilities()
  const sample = rowsOf(d.sample)
  return (
    <>
      <Facts rows={[['Bulan belum diposting', num(d.months)], ['Aset', num(d.assets)], ['Jumlah', str(d.amount) && money(d.amount)], ['Sejak', str(d.earliest_period_start) && formatDate(str(d.earliest_period_start))]]} />
      <Sample shown={sample.length} total={num(d.assets)}>
        {sample.map((a) => {
          const label = `${str(a.asset_number) ?? ''} ${str(a.name) ?? ''} · ${num(a.months) ?? 0} bulan`
          return <li key={String(a.asset_id)}>{can('accounting.asset.view') ? <Link to={PATHS.asset(String(a.asset_id))}>{label}</Link> : label}</li>
        })}
      </Sample>
    </>
  )
}

function Mapping({ d }: { d: Row }) {
  const { can } = useCapabilities()
  const sample = rowsOf(d.sample)
  const total = num(d.unmapped_count)
  return (
    <>
      <Facts rows={[['Akun belum terpetakan', total], ['Definisi laporan', rowsOf(d.definitions).map((x) => `${str(x.code)} v${num(x.version)}`).join(', ') || null]]} />
      <Sample shown={sample.length} total={total}>
        {sample.map((a) => <li key={`${String(a.account_id)}-${String(a.statement_type)}`}>{String(a.account_code)} · {String(a.account_name)} <span className="muted">(saldo</span> {money(a.balance)}<span className="muted">)</span></li>)}
      </Sample>
      {total !== null && total > 0 && can('accounting.report_definition.view') && <Link to={PATHS.reportMapping}>Buka pemetaan laporan keuangan</Link>}
    </>
  )
}

export function CheckDetails({ check }: { check: CloseCheck }) {
  const d = check.details
  if (check.result === 'SKIPPED') return null
  switch (check.code) {
    case 'TRIAL_BALANCE':
      return <Facts rows={[['Sampai tanggal', str(d.as_of) && formatDate(str(d.as_of))], ['Total debit', money(d.total_debit)], ['Total kredit', money(d.total_credit)], ['Selisih', <Money key="d" value={str(d.difference)} strong />], ['Selisih periode ini', money(d.period_difference)]]} />
    case 'PENDING_JOURNALS':
    case 'DRAFT_JOURNALS':
      return num(d.count) ? <Journals d={d} /> : null
    case 'PENDING_DOCUMENTS': {
      const docs = d.by_document && typeof d.by_document === 'object' ? Object.entries(d.by_document as Record<string, unknown>) : []
      return docs.length > 0 ? <Facts rows={docs.map(([label, n]): [string, ReactNode] => [label, num(n)])} /> : null
    }
    case 'DEPRECIATION_COMPLETE':
      return num(d.months) ? <Depreciation d={d} /> : null
    case 'PREVIOUS_PERIODS_CLOSED': {
      const sample = rowsOf(d.sample)
      return sample.length === 0 ? null : <Sample shown={sample.length} total={num(d.count)}>{sample.map((p) => <li key={String(p.id)}><Link to={PATHS.period(String(p.id))}>{String(p.code)}</Link> <span className="muted">{statusLabel(str(p.status))[0]}</span></li>)}</Sample>
    }
    case 'REPORT_MAPPING':
      return <Mapping d={d} />
    case 'CHECKLIST': {
      const pending = rowsOf(d.pending)
      return pending.length === 0 ? null : <Sample shown={pending.length} total={num(d.pending_count)}>{pending.map((p) => <li key={String(p.code)}>{String(p.title)} <span className="muted">({String(p.code)})</span></li>)}</Sample>
    }
    default:
      return check.code.endsWith('_RECONCILIATION') ? <Reconciliation d={d} /> : null
  }
}
