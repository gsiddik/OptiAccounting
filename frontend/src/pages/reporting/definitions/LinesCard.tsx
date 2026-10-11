import { DataTable } from '../../../components/DataTable'
import { Button, Card, EmptyState } from '../../../components/ui'
import { isContainer, isMapped, siblingsOf } from './data'
import { categoryLabels, lineTypeLabels, sideLabels, systemKeyLabels } from './labels'
import type { LineMapping, ReportLine } from './types'

export type LineHandlers = {
  onAdd: (parentCode?: string) => void
  onEdit: (line: ReportLine) => void
  onDelete: (line: ReportLine) => void
  onMap: (line: ReportLine) => void
  onUnmap: (line: ReportLine, mapping: LineMapping) => void
  onMove: (line: ReportLine, direction: -1 | 1) => void
}

/** What the line computes, in words: its type plus the system figure or the operands. */
function describe(line: ReportLine) {
  if (line.line_type === 'SYSTEM') return <>{lineTypeLabels.SYSTEM}<div className="muted">{systemKeyLabels[line.system_key ?? ''] ?? line.system_key}</div></>
  if (line.line_type === 'CALCULATED_TOTAL') {
    return <>{lineTypeLabels.CALCULATED_TOTAL}<div className="muted mono">{(line.operands ?? []).map((o) => `${o.sign === '-' ? '−' : '+'} ${o.line}`).join(' ')}</div></>
  }
  return lineTypeLabels[line.line_type]
}

function Mappings({ line, editable, onUnmap }: { line: ReportLine; editable: boolean; onUnmap: LineHandlers['onUnmap'] }) {
  if (!isMapped(line.line_type)) return <span className="muted">—</span>
  if (line.mappings.length === 0) return <span className="muted">Belum ada akun</span>
  return (
    <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
      {line.mappings.map((m) => (
        <li key={m.account_id} className="toolbar">
          <span><span className="mono">{m.code}</span> · {m.name}{m.include_descendants && <span className="muted"> (+ akun di bawahnya)</span>}{m.status !== 'ACTIVE' && <span className="muted"> · nonaktif</span>}</span>
          {editable && <Button size="sm" variant="ghost" aria-label={`Lepas akun ${m.code} dari ${line.code}`} onClick={() => onUnmap(line, m)}>Lepas</Button>}
        </li>
      ))}
    </ul>
  )
}

/** The line tree of a definition with its account mapping. The action buttons exist only while the definition is an editable draft. */
export function LinesCard({ lines, editable, busy, handlers }: { lines: ReportLine[]; editable: boolean; busy: boolean; handlers: LineHandlers }) {
  return (
    <Card title="Baris laporan dan pemetaan akun" flush actions={editable ? <Button size="sm" variant="primary" onClick={() => handlers.onAdd()}>Tambah baris</Button> : undefined}>
      {lines.length === 0 ? (
        <EmptyState title="Belum ada baris">{editable ? 'Tambahkan baris pertama, atau hapus draf ini dan pakai Terapkan bawaan untuk memulai dari bagan akun.' : 'Definisi ini tidak memiliki baris.'}</EmptyState>
      ) : (
        <DataTable
          caption="Baris laporan"
          scroll
          rows={lines}
          rowKey={(l) => l.id}
          columns={[
            { header: 'Baris', primary: true, cell: (l) => <span style={{ paddingLeft: l.depth * 18, fontWeight: l.emphasis || isContainer(l.line_type) ? 700 : 400 }}><span className="mono">{l.code}</span> · {l.label}</span> },
            { header: 'Jenis', cell: describe },
            { header: 'Sisi saldo / kategori', cell: (l) => (l.balance_side || l.cash_flow_category ? <>{l.balance_side ? sideLabels[l.balance_side] : null}{l.cash_flow_category && <div className="muted">{categoryLabels[l.cash_flow_category]}</div>}</> : <span className="muted">—</span>) },
            { header: 'Akun terpetakan', cell: (l) => <Mappings line={l} editable={editable} onUnmap={handlers.onUnmap} /> },
            ...(editable ? [{
              header: 'Aksi', actions: true, cell: (l: ReportLine) => {
                const siblings = siblingsOf(lines, l)
                const at = siblings.findIndex((s) => s.code === l.code)
                return (
                  <div className="toolbar">
                    {isMapped(l.line_type) && <Button size="sm" onClick={() => handlers.onMap(l)} aria-label={`Petakan akun ke ${l.code}`}>Petakan</Button>}
                    {isContainer(l.line_type) && <Button size="sm" onClick={() => handlers.onAdd(l.code)} aria-label={`Tambah sub-baris di ${l.code}`}>Sub-baris</Button>}
                    <Button size="sm" onClick={() => handlers.onEdit(l)} aria-label={`Ubah baris ${l.code}`}>Ubah</Button>
                    <Button size="sm" disabled={busy || at <= 0} onClick={() => handlers.onMove(l, -1)} aria-label={`Naikkan ${l.code}`}>▲</Button>
                    <Button size="sm" disabled={busy || at < 0 || at >= siblings.length - 1} onClick={() => handlers.onMove(l, 1)} aria-label={`Turunkan ${l.code}`}>▼</Button>
                    <Button size="sm" variant="danger" onClick={() => handlers.onDelete(l)} aria-label={`Hapus baris ${l.code}`}>Hapus</Button>
                  </div>
                )
              },
            }] : []),
          ]}
        />
      )}
    </Card>
  )
}
