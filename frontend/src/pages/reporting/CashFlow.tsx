import { StatementPage } from './statements/StatementPage'

/** Arus kas metode tidak langsung (GET reports/cash-flow), dengan rekonsiliasi ke kas/bank, pergerakan yang belum diklasifikasi, rincian akun dan ekspor. */
export default function CashFlow() {
  return <StatementPage kind="CASH_FLOW" />
}
