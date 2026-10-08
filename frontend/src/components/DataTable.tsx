import type { ReactNode } from 'react'

export type Column<T> = {
  header: string
  cell: (row: T) => ReactNode
  align?: 'right'
  /** Action cells render without a label on phones. */
  actions?: boolean
  /** Identity cell (name, code): on phones it heads the card, full width and without a label. */
  primary?: boolean
}

/** Plain table; on phones each row becomes a labelled card (see .table in index.css). `scroll` keeps a wide numeric table as a table that scrolls sideways instead. */
export function DataTable<T>({ rows, columns, rowKey, caption, scroll }: { rows: T[]; columns: Column<T>[]; rowKey: (row: T) => string; caption: string; scroll?: boolean }) {
  return (
    <div className={scroll ? 'table-wrap keep-table' : 'table-wrap'}>
      <table className="table">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c.header} scope="col" className={c.align === 'right' ? 'right' : undefined}>
                {c.actions ? <span className="sr-only">{c.header}</span> : c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={rowKey(row)}>
              {columns.map((c) => (
                <td key={c.header} data-label={c.actions || c.primary ? undefined : c.header} className={[c.align === 'right' ? 'num' : '', c.actions ? 'cell-actions' : '', c.primary ? 'cell-primary' : ''].join(' ').trim() || undefined}>
                  {c.cell(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
