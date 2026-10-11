import { Link } from 'react-router-dom'
import { DataTable } from '../../../components/DataTable'
import { Banner, Card, EmptyState, Stat } from '../../../components/ui'
import { formatDate, formatDateTime } from '../../../lib/format'
import { Money } from '../../accounting/shared'
import { NOT_COMPLETE_NOTE } from '../../accounting/ledger-labels'
import { ledgerLink, type BalanceState } from './balance'
import { accountTypeText, sideNames } from './labels'
import type { AccountBalanceReport, BalanceRow } from './types'

const sideWord = (side: 'DEBIT' | 'CREDIT') => (side === 'DEBIT' ? 'debit' : 'kredit')

/** The figures the API returned, as cards: one balance for a dated report, opening / movement / closing for a range. Nothing is added up here. */
function Summary({ report }: { report: AccountBalanceReport }) {
  const t = report.totals
  const grouped = report.account !== null
  const direction = grouped ? `menurut saldo normal ${sideWord(t.normal_balance)}` : 'bersih debit - kredit'
  if (report.mode === 'AS_OF') {
    return <div className="grid grid-4"><Stat label={`Saldo ${direction}`} value={<Money value={t.balance} />} hint={`Per ${formatDate(report.as_of)}`} /></div>
  }
  const hint = `${formatDate(report.range?.from)} – ${formatDate(report.range?.to)}`
  return (
    <div className="grid grid-4">
      <Stat label={`Saldo awal (${direction})`} value={<Money value={t.opening} />} hint={formatDate(report.range?.from)} />
      <Stat label="Total debit" value={<Money value={t.debit} />} hint={hint} />
      <Stat label="Total kredit" value={<Money value={t.credit} />} hint={hint} />
      <Stat label={`Saldo akhir (${direction})`} value={<Money value={t.closing} />} hint={formatDate(report.range?.to)} />
    </div>
  )
}

function Rows({ report, state }: { report: AccountBalanceReport; state: BalanceState }) {
  const dated = report.mode === 'AS_OF'
  return (
    <DataTable
      caption="Saldo akun"
      scroll
      rows={report.data}
      rowKey={(r) => r.account_id}
      columns={[
        { header: 'Akun', primary: true, cell: (r) => <span style={{ paddingLeft: r.depth * 16, fontWeight: r.is_header ? 700 : 400 }}><span className="mono">{r.code}</span> · {r.name}</span> },
        { header: 'Tipe', cell: (r) => accountTypeText[r.account_type] ?? r.account_type },
        { header: 'Saldo normal', cell: (r) => sideNames[r.normal_balance] },
        ...(dated
          ? [{ header: 'Saldo', align: 'right' as const, cell: (r: BalanceRow) => <Money value={r.balance} strong={r.is_header} /> }]
          : [
              { header: 'Saldo awal', align: 'right' as const, cell: (r: BalanceRow) => <Money value={r.opening} /> },
              { header: 'Mutasi debit', align: 'right' as const, cell: (r: BalanceRow) => <Money value={r.debit} /> },
              { header: 'Mutasi kredit', align: 'right' as const, cell: (r: BalanceRow) => <Money value={r.credit} /> },
              { header: 'Saldo akhir', align: 'right' as const, cell: (r: BalanceRow) => <Money value={r.closing} strong={r.is_header} /> },
            ]),
        { header: 'Rincian', actions: true, cell: (r) => (r.is_header ? null : <Link className="btn btn-sm" to={ledgerLink(report, r, state)} aria-label={`Buku besar ${r.code}`}>Buku besar</Link>) },
      ]}
    />
  )
}

/** The report: the account asked for, the totals, the banner about completeness and the per-account rows. */
export function BalanceView({ report, state }: { report: AccountBalanceReport; state: BalanceState }) {
  const a = report.account
  return (
    <>
      {a && (
        <Banner tone="info">
          Kelompok akun <strong><span className="mono">{a.code}</span> · {a.name}</strong> ({accountTypeText[a.account_type] ?? a.account_type}, saldo normal {sideWord(a.normal_balance)}).
          {!a.is_postable && ' Akun induk mewakili seluruh akun posting di bawahnya.'}
        </Banner>
      )}
      {!report.complete && <Banner tone="info">{NOT_COMPLETE_NOTE}</Banner>}
      <Summary report={report} />
      <Card flush>
        {report.data.length === 0 ? (
          <EmptyState title="Tidak ada saldo">Tidak ada akun dengan saldo atau mutasi pada tanggal dan filter ini. Aktifkan <em>Sertakan saldo nol</em> untuk melihat akun tanpa saldo.</EmptyState>
        ) : (
          <>
            <Rows report={report} state={state} />
            <p className="card-body muted preview-note">
              Dihitung server dari jurnal terposting (saldo awal sudah termasuk jurnal saldo awal), {formatDateTime(report.generated_at)}.
              {report.complete ? ' Laporan mencakup seluruh buku besar dan sama dengan neraca saldo serta buku besar pada tanggal yang sama.' : ''}
            </p>
          </>
        )}
      </Card>
    </>
  )
}
