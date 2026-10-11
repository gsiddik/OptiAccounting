import type { ReactNode } from 'react'
import { Badge, Card } from '../../../components/ui'
import { DataTable } from '../../../components/DataTable'
import { Money } from '../../accounting/shared'
import { isZeroAmount } from './data'
import { accountTypeLabels, categoryLabels, reasonLabels } from './labels'
import type { BalanceSheetIntegrity, BudgetIntegrity, CashFlowIntegrity, Integrity, ProfitLossIntegrity, StatementKind, UnclassifiedAccount, UnmappedAccount } from './types'

const Fact = ({ label, children }: { label: string; children: ReactNode }) => <div><dt>{label}</dt><dd>{children}</dd></div>

function Verdict({ ok, yes, no, unknown = '' }: { ok: boolean | null; yes: string; no: string; unknown?: string }) {
  return ok === null ? <Badge tone="warn">{unknown}</Badge> : ok ? <Badge tone="ok">{yes}</Badge> : <Badge tone="bad">{no}</Badge>
}

function UnmappedTable({ rows, caption }: { rows: UnmappedAccount[]; caption: string }) {
  if (rows.length === 0) return null
  return (
    <DataTable
      caption={caption}
      rows={rows}
      rowKey={(a) => a.id}
      scroll
      columns={[
        { header: 'Akun tanpa baris laporan', primary: true, cell: (a) => <><span className="mono">{a.code}</span> · {a.name}</> },
        { header: 'Jenis', cell: (a) => accountTypeLabels[a.account_type] ?? a.account_type },
        { header: 'Angka', align: 'right', cell: (a) => <Money value={a.amount} /> },
      ]}
    />
  )
}

function BalanceSheetChecks({ i }: { i: BalanceSheetIntegrity }) {
  return (
    <>
      <dl className="facts">
        <Fact label="Total aset"><Money value={i.assets} strong /></Fact>
        <Fact label="Kewajiban + ekuitas (termasuk hasil berjalan)"><Money value={i.liabilities_and_equity} strong /></Fact>
        <Fact label="Selisih"><Money value={i.difference} strong /></Fact>
        <Fact label="Hasil berjalan (belum ditutup)"><Money value={i.current_result} /></Fact>
        <Fact label="Status"><Verdict ok={i.balanced} yes="Seimbang" no="Tidak seimbang" unknown="Tidak dapat dipastikan (cakupan sebagian)" /></Fact>
      </dl>
      <UnmappedTable rows={i.unmapped} caption="Akun bersaldo yang tidak ada di definisi neraca" />
    </>
  )
}

function ProfitLossChecks({ i }: { i: ProfitLossIntegrity }) {
  return (
    <>
      <dl className="facts">
        <Fact label="Hasil menurut buku besar"><Money value={i.ledger_result} strong /></Fact>
        <Fact label="Hasil menurut laporan"><Money value={i.mapped_result} strong /></Fact>
        <Fact label="Selisih"><Money value={i.difference} strong /></Fact>
        <Fact label="Status"><Verdict ok={isZeroAmount(i.difference)} yes="Sesuai buku besar" no="Tidak sesuai" /></Fact>
      </dl>
      <UnmappedTable rows={i.unmapped} caption="Akun bergerak yang tidak ada di definisi laba rugi" />
    </>
  )
}

function CashFlowChecks({ i }: { i: CashFlowIntegrity }) {
  const unclassified: UnclassifiedAccount[] = i.unclassified
  return (
    <>
      <dl className="facts">
        <Fact label="Hasil bersih periode"><Money value={i.net_result} /></Fact>
        <Fact label="Kas awal"><Money value={i.cash_opening} /></Fact>
        <Fact label="Perubahan kas bersih"><Money value={i.net_change} strong /></Fact>
        <Fact label="Kas akhir (hitungan laporan)"><Money value={i.cash_closing_calculated} /></Fact>
        <Fact label="Kas akhir (buku besar)"><Money value={i.cash_closing_ledger} strong /></Fact>
        <Fact label="Selisih"><Money value={i.difference} strong /></Fact>
        <Fact label="Rekonsiliasi ke kas/bank"><Verdict ok={i.reconciled} yes="Cocok" no="Selisih" unknown="Tidak dapat dipastikan (cakupan sebagian)" /></Fact>
      </dl>
      <dl className="facts">
        {Object.entries(i.categories).map(([code, amount]) => <Fact key={code} label={`Arus ${categoryLabels[code] ?? code}`}><Money value={amount} /></Fact>)}
      </dl>
      {i.cash_accounts.length > 0 && (
        <DataTable
          caption="Akun kas dan bank yang dijelaskan laporan"
          rows={i.cash_accounts}
          rowKey={(a) => a.id}
          scroll
          columns={[
            { header: 'Akun kas/bank', primary: true, cell: (a) => <><span className="mono">{a.code}</span> · {a.name}</> },
            { header: 'Saldo awal', align: 'right', cell: (a) => <Money value={a.opening} /> },
            { header: 'Mutasi', align: 'right', cell: (a) => <Money value={a.movement} /> },
            { header: 'Saldo akhir', align: 'right', cell: (a) => <Money value={a.closing} /> },
          ]}
        />
      )}
      {unclassified.length > 0 && (
        <DataTable
          caption="Pergerakan kas yang belum diklasifikasi"
          rows={unclassified}
          rowKey={(a) => `${a.id}:${a.reason}`}
          scroll
          columns={[
            { header: 'Akun belum diklasifikasi', primary: true, cell: (a) => <><span className="mono">{a.code}</span> · {a.name}</> },
            { header: 'Sebab', cell: (a) => reasonLabels[a.reason] ?? a.reason },
            { header: 'Dampak ke kas', align: 'right', cell: (a) => <Money value={a.amount} /> },
          ]}
        />
      )}
    </>
  )
}

/** The proof the API attaches to a statement (what the statement shows against what the ledger holds), as figures; nothing is recomputed here. */
export function IntegrityDetails({ kind, integrity, title = 'Pemeriksaan' }: { kind: StatementKind; integrity: Integrity; title?: string }) {
  return (
    <Card title={title}>
      <div className="stack">
        {kind === 'BALANCE_SHEET' && <BalanceSheetChecks i={integrity as BalanceSheetIntegrity} />}
        {kind === 'PROFIT_LOSS' && <ProfitLossChecks i={integrity as ProfitLossIntegrity} />}
        {kind === 'CASH_FLOW' && <CashFlowChecks i={integrity as CashFlowIntegrity} />}
      </div>
    </Card>
  )
}

/** The budget side of a comparison: the budgeted result and the budget rows that could not be placed on exactly one line. */
export function BudgetChecks({ budget }: { budget: BudgetIntegrity }) {
  return (
    <Card title="Pemeriksaan anggaran">
      <div className="stack">
        <dl className="facts">
          <Fact label="Hasil menurut anggaran (baris yang ditempatkan)"><Money value={budget.budget_result} strong /></Fact>
          <Fact label="Anggaran tidak tertempatkan"><Badge tone={budget.unplaced.length > 0 ? 'warn' : 'ok'}>{budget.unplaced.length > 0 ? `${budget.unplaced.length} akun` : 'Tidak ada'}</Badge></Fact>
        </dl>
        {budget.unplaced.length > 0 && (
          <DataTable
            caption="Anggaran yang tidak tertempatkan pada satu baris"
            rows={budget.unplaced}
            rowKey={(a) => a.id}
            scroll
            columns={[
              { header: 'Akun beranggaran', primary: true, cell: (a) => <><span className="mono">{a.code}</span> · {a.name}</> },
              { header: 'Anggaran (tidak ikut perbandingan)', align: 'right', cell: (a) => <Money value={a.budget} /> },
            ]}
          />
        )}
      </div>
    </Card>
  )
}
