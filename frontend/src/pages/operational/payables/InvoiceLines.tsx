import { Button, Field } from '../../../components/ui'
import { amountToApi, formatAmount, type Account } from '../../../lib/accounting'
import { fieldError } from '../../../lib/forms'
import type { ExpenseCategory } from '../../../lib/operational'
import { accountLabel, type DimensionCatalog } from '../../accounting/data'
import { emptyInvoiceLine, type InvoicePreview, type LineForm } from './invoiceForm'
import { lineMessage } from './messages'

type Role = { code: string; name: string }

/** The lines of a vendor invoice: description, an amount (or quantity x unit price) and the classification the posting engine uses. */
export function InvoiceLines({ lines, onChange, preview, categories, accounts, roles, catalog, error }: {
  lines: LineForm[]
  onChange: (lines: LineForm[]) => void
  preview: InvoicePreview
  categories: ExpenseCategory[]
  accounts: Account[]
  roles: Role[]
  catalog: DimensionCatalog
  error: unknown
}) {
  const patch = (key: string, change: Partial<LineForm>) => onChange(lines.map((l) => (l.key === key ? { ...l, ...change } : l)))
  const destinations = accounts.filter((a) => a.status === 'ACTIVE' && a.is_postable && !a.is_control && (a.account_type === 'EXPENSE' || a.account_type === 'ASSET'))
  const problem = (i: number, name: string) => fieldError(error, `lines.${i}.${name}`)

  return (
    <div className="stack">
      {lines.map((line, i) => {
        const n = i + 1
        const shownAmount = preview.lineAmounts[i]
        const rowError = lineMessage(error, n)
        return (
          <div key={line.key} role="group" aria-label={`Baris ${n}`} style={{ border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', padding: 14 }}>
            <div className="card-head" style={{ padding: '0 0 10px', border: 0 }}>
              <strong>Baris {n}</strong>
              <Button variant="ghost" size="sm" aria-label={`Hapus baris ${n}`} disabled={lines.length <= 1} onClick={() => onChange(lines.filter((l) => l.key !== line.key))}>Hapus</Button>
            </div>
            <div className="form-grid">
              <Field label={`Deskripsi baris ${n}`} error={problem(i, 'description')} full>
                {(p) => <input className="input" maxLength={255} value={line.description} onChange={(e) => patch(line.key, { description: e.target.value })} {...p} />}
              </Field>
              <label className="check full">
                <input type="checkbox" checked={line.useQuantity} onChange={(e) => patch(line.key, { useQuantity: e.target.checked })} />
                Hitung dari kuantitas × harga satuan (baris {n})
              </label>
              {line.useQuantity ? (
                <>
                  <Field label={`Kuantitas baris ${n}`} error={problem(i, 'quantity')}>
                    {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" value={line.quantity} onChange={(e) => patch(line.key, { quantity: e.target.value })} {...p} />}
                  </Field>
                  <Field label={`Harga satuan baris ${n}`} error={problem(i, 'unit_price')}>
                    {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" value={line.unit_price} onChange={(e) => patch(line.key, { unit_price: e.target.value })} {...p} />}
                  </Field>
                  <p className="muted full" style={{ margin: 0 }}>Jumlah baris (pratinjau): <span className="money">{shownAmount === null ? '—' : formatAmount(amountToApi(shownAmount))}</span></p>
                </>
              ) : (
                <Field label={`Jumlah baris ${n}`} error={problem(i, 'amount')}>
                  {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={line.amount} onChange={(e) => patch(line.key, { amount: e.target.value })} {...p} />}
                </Field>
              )}
              <Field label={`Kategori beban baris ${n}`} error={problem(i, 'expense_category_id')}>
                {(p) => (
                  <select className="select" value={line.expense_category_id} onChange={(e) => patch(line.key, { expense_category_id: e.target.value })} {...p}>
                    <option value="">Tanpa kategori</option>
                    {categories.filter((c) => c.status === 'ACTIVE' || c.id === line.expense_category_id).map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                  </select>
                )}
              </Field>
              <Field label={`Akun baris ${n}`} error={problem(i, 'account_id')} hint="Opsional. Menggantikan akun dari kategori.">
                {(p) => (
                  <select className="select" value={line.account_id} onChange={(e) => patch(line.key, { account_id: e.target.value })} {...p}>
                    <option value="">Ikuti kategori / peran</option>
                    {line.account_id && !destinations.some((a) => a.id === line.account_id) && <option value={line.account_id}>Akun terpilih</option>}
                    {destinations.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
                  </select>
                )}
              </Field>
              {(roles.length > 0 || line.account_role) && (
                <Field label={`Peran akun baris ${n}`} error={problem(i, 'account_role')} hint="Opsional. Dipakai bila tanpa kategori atau akun.">
                  {(p) => (
                    <select className="select" value={line.account_role} onChange={(e) => patch(line.key, { account_role: e.target.value })} {...p}>
                      <option value="">Tanpa peran</option>
                      {line.account_role && !roles.some((r) => r.code === line.account_role) && <option value={line.account_role}>{line.account_role}</option>}
                      {roles.map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}
                    </select>
                  )}
                </Field>
              )}
              {catalog.cost_centers.length > 0 && (
                <Field label={`Pusat biaya baris ${n}`} error={problem(i, 'cost_center_id')}>
                  {(p) => (
                    <select className="select" value={line.cost_center_id} onChange={(e) => patch(line.key, { cost_center_id: e.target.value })} {...p}>
                      <option value="">Ikuti pusat biaya faktur</option>
                      {catalog.cost_centers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                    </select>
                  )}
                </Field>
              )}
            </div>
            {rowError && <p className="field"><span className="error">{rowError}</span></p>}
          </div>
        )
      })}
      <div>
        <Button size="sm" disabled={lines.length >= 300} onClick={() => onChange([...lines, emptyInvoiceLine()])}>Tambah baris</Button>
      </div>
    </div>
  )
}
