import { Fragment, type CSSProperties, type ReactNode } from 'react'
import { Badge } from '../../../components/ui'
import { systemLabels } from './labels'
import type { LineType } from './types'

/** What the table needs of a line: statement lines and comparative lines both fit. */
export type RowBase = { code: string; label: string; line_type: LineType; depth: number; emphasis: boolean; notes?: string | null; system_key?: string | null }

/** One amount column: the header and how a row shows its figure (`Money`, a percentage, ...). The table never computes anything. */
export type AmountColumn<T> = { key: string; header: ReactNode; cell: (row: T) => ReactNode }

/** An indented line under a row (an account of the line): a label and one cell per amount column. */
export type SubRow = { key: string; label: ReactNode; cells: ReactNode[] }

const INDENT = 14
const TOTALS: LineType[] = ['SUBTOTAL', 'CALCULATED_TOTAL']

const linkButton: CSSProperties = { background: 'none', border: 0, padding: 0, font: 'inherit', color: 'var(--brand)', cursor: 'pointer', textAlign: 'left', textDecoration: 'underline dotted', textUnderlineOffset: 3 }

/**
 * A financial statement laid out as the API returned it: one row per line in the API's order, indented by its level, headers as labels without
 * an amount, totals set off by a rule, system lines marked. With `onDrill` every line that has an amount opens its drill-down (button for the
 * keyboard, the whole row for the mouse). The table scrolls inside its card on narrow screens instead of folding into cards.
 */
export function StatementTable<T extends RowBase>({ rows, columns, caption, onDrill, expand }: {
  rows: T[]
  columns: AmountColumn<T>[]
  caption: string
  onDrill?: (code: string) => void
  expand?: (row: T) => SubRow[]
}) {
  return (
    <div className="table-wrap keep-table">
      <table className="table">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr>
            <th scope="col">Uraian</th>
            {columns.map((c) => <th key={c.key} scope="col" className="right">{c.header}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const header = row.line_type === 'HEADER'
            const strong = header || row.emphasis || TOTALS.includes(row.line_type)
            const drillable = Boolean(onDrill) && !header
            const style: CSSProperties = {
              fontWeight: strong ? 700 : 400,
              background: header ? 'var(--surface-2)' : undefined,
              borderTop: TOTALS.includes(row.line_type) ? '1px solid var(--border-strong)' : undefined,
              cursor: drillable ? 'pointer' : undefined,
            }
            const system = row.line_type === 'SYSTEM' ? (systemLabels[row.system_key ?? ''] ?? 'Dihitung sistem') : null
            return (
              <Fragment key={row.code}>
                <tr style={style} onClick={drillable ? () => onDrill?.(row.code) : undefined}>
                  <td className="cell-primary" style={{ paddingLeft: 16 + row.depth * INDENT }}>
                    {drillable ? <button type="button" style={linkButton} title="Lihat akun di balik angka ini" aria-label={`Rincian ${row.label}`}>{row.label}</button> : row.label}
                    {system && <> <span title={system}><Badge tone="info">Sistem</Badge></span><span className="sr-only">{system}</span></>}
                    {row.notes && <div className="muted" style={{ fontWeight: 400, fontSize: '0.8rem' }}>{row.notes}</div>}
                  </td>
                  {columns.map((c) => <td key={c.key} className="num">{header ? null : c.cell(row)}</td>)}
                </tr>
                {expand?.(row).map((sub) => (
                  <tr key={`${row.code}:${sub.key}`}>
                    <td className="cell-primary muted" style={{ paddingLeft: 16 + (row.depth + 1) * INDENT, fontWeight: 400 }}>{sub.label}</td>
                    {sub.cells.map((cell, i) => <td key={columns[i].key} className="num muted" style={{ fontWeight: 400 }}>{cell}</td>)}
                  </tr>
                ))}
              </Fragment>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
