import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { FormModal } from '../../../components/Modal'
import { Banner, Field } from '../../../components/ui'
import { api } from '../../../lib/api'
import { useCapabilities } from '../../../lib/capabilities'
import { fieldError } from '../../../lib/forms'
import { todayIn } from '../../../lib/format'
import { useAction } from '../../../lib/hooks'
import { API, useCashBankAccounts } from '../../../lib/operational'
import { parseSignedDecimal } from './amounts'
import { buildLines, isIsoDate, type RowDraft } from './lines'
import { StatementLines } from './StatementLines'
import type { BankStatement } from './types'

type Header = { cash_bank_account_id: string; reference: string; statement_date: string; period_start: string; opening_balance: string; closing_balance: string; notes: string }
type Local = Partial<Record<keyof Header, string>>

/**
 * Create a bank statement (header, optional lines) or, with `statement`, edit the header of an OPEN one. Balances may be negative
 * (an overdraft) and are sent as exact decimal strings. The server re-validates everything and owns the account's currency.
 */
export function StatementFormModal({ statement, defaultAccountId, onClose, onSaved }: { statement?: BankStatement; defaultAccountId?: string; onClose: () => void; onSaved: (saved: BankStatement) => void }) {
  const { tenant } = useCapabilities()
  const action = useAction()
  const [f, setF] = useState<Header>({
    cash_bank_account_id: statement?.cash_bank_account_id ?? defaultAccountId ?? '',
    reference: statement?.reference ?? '',
    statement_date: statement?.statement_date ?? tenant?.business_date ?? todayIn(),
    period_start: statement?.period_start ?? '',
    opening_balance: statement?.opening_balance ?? '',
    closing_balance: statement?.closing_balance ?? '',
    notes: statement?.notes ?? '',
  })
  const [rows, setRows] = useState<RowDraft[]>([])
  const [paste, setPaste] = useState('')
  const [local, setLocal] = useState<Local>({})
  const [submitted, setSubmitted] = useState(false)
  const built = useMemo(() => buildLines(rows, paste), [rows, paste])

  const accountId = f.cash_bank_account_id
  const set = (k: keyof Header) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const error = action.error
  const errorOf = (name: keyof Header) => local[name] ?? fieldError(error, name)

  async function submit() {
    setSubmitted(true)
    const problems: Local = {}
    const closing = parseSignedDecimal(f.closing_balance)
    const opening = f.opening_balance.trim() === '' ? null : parseSignedDecimal(f.opening_balance)
    if (!statement && !accountId) problems.cash_bank_account_id = 'Pilih akun bank.'
    if (f.reference.trim() === '') problems.reference = 'Referensi wajib diisi.'
    if (!isIsoDate(f.statement_date)) problems.statement_date = 'Tanggal rekening koran wajib diisi.'
    if (f.period_start && !isIsoDate(f.period_start)) problems.period_start = 'Tanggal tidak valid.'
    else if (f.period_start && f.statement_date && f.period_start > f.statement_date) problems.period_start = 'Awal periode tidak boleh setelah tanggal rekening koran.'
    if (!closing.ok) problems.closing_balance = closing.error
    if (opening && !opening.ok) problems.opening_balance = opening.error
    setLocal(problems)
    if (Object.keys(problems).length > 0 || !closing.ok || (opening && !opening.ok) || !(statement || built.ready)) return

    const body = {
      reference: f.reference.trim(),
      statement_date: f.statement_date,
      period_start: f.period_start || null,
      opening_balance: opening?.ok ? opening.value : null,
      closing_balance: closing.value,
      notes: f.notes.trim() || null,
    }
    const r = await action.run(async () =>
      statement
        ? (await api.patch<BankStatement>(`${API}/bank-statements/${statement.id}`, body)).data
        : (await api.post<BankStatement>(`${API}/bank-statements`, { ...body, cash_bank_account_id: accountId, ...(built.items.length > 0 && { items: built.items }) })).data,
    )
    if (r.ok) onSaved(r.value)
  }

  return (
    <FormModal title={statement ? `Ubah rekening koran ${statement.reference}` : 'Rekening koran baru'} submitLabel={statement ? 'Simpan' : 'Simpan rekening koran'} busy={action.busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      {!statement && <BankAccountField value={accountId} onChange={(id) => setF((s) => ({ ...s, cash_bank_account_id: id }))} error={errorOf('cash_bank_account_id')} />}
      <div className="form-grid">
        <Field label="Referensi rekening koran" error={errorOf('reference')} hint="Nomor atau kode rekening koran dari bank; unik per akun.">
          {(p) => <input className="input" maxLength={100} value={f.reference} onChange={set('reference')} {...p} />}
        </Field>
        <Field label="Tanggal rekening koran" error={errorOf('statement_date')} hint="Tanggal saldo akhir menurut bank.">
          {(p) => <input className="input" type="date" value={f.statement_date} onChange={set('statement_date')} {...p} />}
        </Field>
        <Field label="Awal periode (opsional)" error={errorOf('period_start')}>
          {(p) => <input className="input" type="date" value={f.period_start} onChange={set('period_start')} {...p} />}
        </Field>
        <Field label="Saldo awal (opsional)" error={errorOf('opening_balance')} hint="Bila diisi, sistem menguji saldo awal + total baris = saldo akhir.">
          {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" value={f.opening_balance} onChange={set('opening_balance')} {...p} />}
        </Field>
        <Field label="Saldo akhir" error={errorOf('closing_balance')} hint="Menurut bank; boleh negatif (cerukan). Contoh: 3.965.000,00 atau -2500000.">
          {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" value={f.closing_balance} onChange={set('closing_balance')} {...p} />}
        </Field>
        <Field label="Catatan" error={errorOf('notes')} full>
          {(p) => <textarea className="textarea" maxLength={500} value={f.notes} onChange={set('notes')} {...p} />}
        </Field>
      </div>
      {!statement && (
        <>
          <h3>Baris rekening koran (opsional)</h3>
          <StatementLines rows={rows} onRows={setRows} paste={paste} onPaste={setPaste} built={built} showErrors={submitted} serverError={error} />
        </>
      )}
    </FormModal>
  )
}

/** The bank account select: active BANK accounts only (a cash box has no statement). A single account is chosen for the user. */
function BankAccountField({ value, onChange, error }: { value: string; onChange: (id: string) => void; error?: string }) {
  const accounts = useCashBankAccounts('ACTIVE')
  const banks = useMemo(() => accounts.accounts.filter((a) => a.kind === 'BANK'), [accounts.accounts])
  const only = banks.length === 1 ? banks[0].id : ''
  useEffect(() => {
    if (only && !value) onChange(only)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [only])

  return (
    <>
      {accounts.error != null && <Banner tone="warn">Daftar akun bank tidak dapat dimuat. Melihat akun membutuhkan izin akun kas & bank.</Banner>}
      {!accounts.loading && accounts.error == null && banks.length === 0 && (
        <Banner tone="warn">Belum ada akun bank yang aktif. Rekening koran milik akun bank, bukan kas: buat dulu di <Link to="/app/akuntansi/kas-bank">Akun kas & bank</Link>.</Banner>
      )}
      <Field label="Akun bank" error={error}>
        {(p) => (
          <select className="select" value={value} onChange={(e) => onChange(e.target.value)} {...p}>
            <option value="">Pilih akun bank…</option>
            {banks.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}{a.account_number_masked ? ` (${a.account_number_masked})` : ''}</option>)}
          </select>
        )}
      </Field>
    </>
  )
}
