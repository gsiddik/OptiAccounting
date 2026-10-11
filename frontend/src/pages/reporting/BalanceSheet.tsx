import { StatementPage } from './statements/StatementPage'

/** Neraca: posisi keuangan per tanggal (GET reports/balance-sheet), dengan bukti keseimbangan, rincian akun dan ekspor. */
export default function BalanceSheet() {
  return <StatementPage kind="BALANCE_SHEET" />
}
