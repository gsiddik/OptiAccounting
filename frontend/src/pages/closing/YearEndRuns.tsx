import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Card, EmptyState, StatusBadge } from '../../components/ui'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, formatDateTime } from '../../lib/format'
import { Money } from '../operational/shared'
import { PATHS } from './data'
import type { YearEndRun } from './types'

/** Every run of the fiscal year, reversed ones included: the closing journal and its reversal link to the ledger. */
export function YearEndRuns({ runs }: { runs: YearEndRun[] }) {
  const { can } = useCapabilities()
  const journal = (id: string | null, number: string | null) => (id ? (can('accounting.journal.view') ? <Link to={PATHS.journal(id)}>{number ?? 'Lihat jurnal'}</Link> : number ?? '—') : '—')

  return (
    <Card flush title="Proses tutup tahun">
      {runs.length === 0 ? (
        <EmptyState title="Belum pernah dijalankan">Tahun fiskal ini belum memiliki proses tutup tahun.</EmptyState>
      ) : (
        <DataTable
          caption="Proses tutup tahun tahun fiskal ini"
          rows={runs}
          rowKey={(r) => r.id}
          scroll
          columns={[
            { header: 'Proses', primary: true, cell: (r) => `Ke-${r.run_no}` },
            { header: 'Status', cell: (r) => <StatusBadge status={r.status} /> },
            { header: 'Tanggal penutupan', cell: (r) => formatDate(r.closing_date) },
            { header: 'Jurnal penutupan', cell: (r) => journal(r.journal_entry_id, r.journal_number) },
            { header: 'Akun laba ditahan', cell: (r) => (r.retained_earnings_account ? `${r.retained_earnings_account.code} · ${r.retained_earnings_account.name}` : '—') },
            { header: 'Hasil bersih', align: 'right', cell: (r) => <Money value={r.net_result} strong /> },
            { header: 'Dijalankan', cell: (r) => `${r.closed_account_count} akun · ${formatDateTime(r.run_at)}` },
            { header: 'Pembalikan', cell: (r) => (r.status === 'REVERSED' ? <>{journal(r.reversal_journal_id, r.reversal_journal_number)}<div className="muted">{formatDateTime(r.reversed_at)}</div>{r.reverse_reason && <div>{r.reverse_reason}</div>}</> : '—') },
          ]}
        />
      )}
    </Card>
  )
}
