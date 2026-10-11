import { StatementPage } from './statements/StatementPage'

/** Laba rugi: mutasi pendapatan dan beban selama periode (GET reports/profit-loss), dengan bukti terhadap buku besar, rincian akun dan ekspor. */
export default function ProfitLoss() {
  return <StatementPage kind="PROFIT_LOSS" />
}
