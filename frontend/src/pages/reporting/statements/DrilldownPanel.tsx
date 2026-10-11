import { Link } from 'react-router-dom'
import { DataTable } from '../../../components/DataTable'
import { Modal } from '../../../components/Modal'
import { Banner, EmptyState, Loading } from '../../../components/ui'
import { Money } from '../../accounting/shared'
import { isZeroAmount, ledgerLink, useDrilldown, type Params } from './data'
import { PARTIAL_TEXT, ledgerNote, statementTitle, systemExplanations } from './labels'
import { StatementError } from './StatementError'
import type { Drilldown, StatementKind } from './types'

function Body({ d }: { d: Drilldown }) {
  const explanation = (d.line.system_key && systemExplanations[d.line.system_key]) || d.explanation
  return (
    <div className="stack">
      {!d.complete && <Banner tone="warn">{PARTIAL_TEXT}</Banner>}
      <dl className="facts">
        <div><dt>Baris</dt><dd>{d.line.label} <span className="muted mono">{d.line.code}</span></dd></div>
        <div><dt>Jumlah pada laporan</dt><dd><Money value={d.line.amount} strong /></dd></div>
        <div><dt>Total akun di bawahnya</dt><dd><Money value={d.accounts_total} strong /></dd></div>
      </dl>
      {explanation && <p>{explanation}</p>}
      {!isZeroAmount(d.not_explained_by_accounts) && (
        <Banner tone="warn">Sebagian angka ini tidak berasal dari akun mana pun (<Money value={d.not_explained_by_accounts} strong />): angka sistem di dalam perhitungan baris ini.</Banner>
      )}
      {d.components.length > 0 && (
        <DataTable
          caption={`Komponen ${d.line.label}`}
          rows={d.components}
          rowKey={(c) => c.code}
          scroll
          columns={[
            { header: 'Komponen', primary: true, cell: (c) => c.label },
            { header: 'Tanda', cell: (c) => (c.sign === '-' ? '− dikurangi' : '+ ditambah') },
            { header: 'Jumlah', align: 'right', cell: (c) => <Money value={c.amount} /> },
          ]}
        />
      )}
      {d.accounts.length === 0 ? (
        <EmptyState title="Tidak ada akun">Tidak ada akun dengan angka pada baris ini dalam cakupan data Anda.</EmptyState>
      ) : (
        <DataTable
          caption={`Akun di balik ${d.line.label}`}
          rows={d.accounts}
          rowKey={(a) => `${a.line_code}:${a.id}`}
          scroll
          columns={[
            { header: 'Akun', primary: true, cell: (a) => <><span className="mono">{a.code}</span> · {a.name}</> },
            { header: 'Baris', cell: (a) => <span className="mono">{a.line_code}</span> },
            { header: 'Angka akun', align: 'right', cell: (a) => <Money value={a.amount} /> },
            { header: 'Kontribusi ke baris', align: 'right', cell: (a) => <Money value={a.contribution} /> },
            { header: 'Buku besar', actions: true, cell: (a) => <Link className="btn btn-sm" to={ledgerLink(a.gl_query)} aria-label={`Buku besar ${a.code} ${a.name}`}>Buka</Link> },
          ]}
        />
      )}
      <p className="muted preview-note">{ledgerNote(d.statement, d.general_ledger.closing_journals_listed)}</p>
    </div>
  )
}

/** The accounts behind one line of a statement, with the general ledger link for each; everything comes from GET reports/drilldown. */
export function DrilldownPanel({ kind, line, label, params, onClose }: { kind: StatementKind; line: string; label: string; params: Params; onClose: () => void }) {
  const drill = useDrilldown(line, params)
  const d = drill.data
  return (
    <Modal title={`Rincian ${statementTitle(kind)}: ${label}`} onClose={onClose} wide>
      {drill.error ? <StatementError error={drill.error} onRetry={drill.reload} /> : d ? <Body d={d} /> : <Loading />}
    </Modal>
  )
}
