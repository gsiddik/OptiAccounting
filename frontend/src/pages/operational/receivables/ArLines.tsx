import { Button, Field } from '../../../components/ui'
import { amountToApi, formatAmount, type Account } from '../../../lib/accounting'
import { fieldError } from '../../../lib/forms'
import { accountLabel, type DimensionCatalog } from '../../accounting/data'
import { placesHint } from '../foreignSupport'
import type { InvoicePreview } from '../payables/invoiceForm'
import { lineMessage } from '../payables/messages'
import { TaxCodeField, TaxPreviewNote } from '../taxOptions'
import { type TaxSupport } from '../taxSupport'
import { emptyArLine, type ArLineForm } from './arLines'

type Role = { code: string; name: string }

/**
 * The lines of a customer invoice or a credit note: description, an amount (or quantity x unit price) and the classification the posting
 * engine uses (revenue account, account role, cost centre). The amounts previewed here are exact arithmetic on what is typed; the server
 * recomputes them.
 */
export function ArLines({ lines, onChange, preview, accounts, roles, catalog, error, tax, fx }: {
  lines: ArLineForm[]
  onChange: (lines: ArLineForm[]) => void
  preview: InvoicePreview
  accounts: Account[]
  roles: Role[]
  catalog: DimensionCatalog
  error: unknown
  /** Present only on a customer invoice when the user may use tax codes: each line then offers a "Kode pajak" (output tax) select and the server's preview of its tax. */
  tax?: TaxSupport
  /** Present only for a foreign-currency invoice: the currency and its decimal places, for the amount hints. */
  fx?: { code: string; places: number } | null
}) {
  const patch = (key: string, change: Partial<ArLineForm>) => onChange(lines.map((l) => (l.key === key ? { ...l, ...change } : l)))
  const revenue = accounts.filter((a) => a.status === 'ACTIVE' && a.is_postable && !a.is_control && a.account_type === 'REVENUE')
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
                <Field label={`Jumlah baris ${n}`} error={problem(i, 'amount')} hint={placesHint(fx ?? null, line.amount) ?? (line.tax_code_id ? 'Jumlah yang Anda masukkan; untuk kode pajak inklusif sudah termasuk pajak.' : undefined)}>
                  {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={line.amount} onChange={(e) => patch(line.key, { amount: e.target.value })} {...p} />}
                </Field>
              )}
              {tax && (
                <>
                  <TaxCodeField label={`Kode pajak baris ${n}`} value={line.tax_code_id} codes={tax.codes} onChange={(id) => patch(line.key, { tax_code_id: id })} error={problem(i, 'tax_code_id')} />
                  <TaxPreviewNote codeId={line.tax_code_id} amount={shownAmount !== null && shownAmount > 0n ? amountToApi(shownAmount) : ''} date={tax.date} foreign={tax.foreign} />
                </>
              )}
              <Field label={`Akun pendapatan baris ${n}`} error={problem(i, 'account_id')} hint="Opsional. Tanpa pilihan, dipakai peran akun, akun default pelanggan, atau pemetaan pendapatan.">
                {(p) => (
                  <select className="select" value={line.account_id} onChange={(e) => patch(line.key, { account_id: e.target.value })} {...p}>
                    <option value="">Ikuti peran / default pelanggan</option>
                    {line.account_id && !revenue.some((a) => a.id === line.account_id) && <option value={line.account_id}>Akun terpilih</option>}
                    {revenue.map((a) => <option key={a.id} value={a.id}>{accountLabel(a)}</option>)}
                  </select>
                )}
              </Field>
              {(roles.length > 0 || line.account_role) && (
                <Field label={`Peran akun baris ${n}`} error={problem(i, 'account_role')} hint="Opsional. Dipakai bila tanpa akun.">
                  {(p) => (
                    <select className="select" value={line.account_role} onChange={(e) => patch(line.key, { account_role: e.target.value })} {...p}>
                      <option value="">Tanpa peran</option>
                      {line.account_role && roles.every((r) => r.code !== line.account_role) && <option value={line.account_role}>{line.account_role}</option>}
                      {roles.map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}
                    </select>
                  )}
                </Field>
              )}
              {catalog.cost_centers.length > 0 && (
                <Field label={`Pusat biaya baris ${n}`} error={problem(i, 'cost_center_id')}>
                  {(p) => (
                    <select className="select" value={line.cost_center_id} onChange={(e) => patch(line.key, { cost_center_id: e.target.value })} {...p}>
                      <option value="">Ikuti pusat biaya dokumen</option>
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
        <Button size="sm" disabled={lines.length >= 300} onClick={() => onChange([...lines, emptyArLine()])}>Tambah baris</Button>
      </div>
    </div>
  )
}
