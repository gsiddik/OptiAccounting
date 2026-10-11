import { useState } from 'react'
import { FormModal } from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { Field } from '../../../components/ui'
import { api } from '../../../lib/api'
import { fieldError } from '../../../lib/forms'
import { useAction } from '../../../lib/hooks'
import { useAccounts } from '../../accounting/data'
import { RefusalFindings } from './components'
import { ENDPOINTS, accountLookup, mappableLines, mappedAt, withMapping } from './data'
import { accountTypesFor, definitionError, refusalFindings } from './labels'
import type { DefinitionDetail } from './types'

export type MapAccount = { id: string; code: string; name: string; is_postable?: boolean }

/** The account select of the dialog: the chart of accounts the user may read, limited to the types this statement uses. */
function AccountSelect({ definition, value, onChange, error, onPick }: { definition: DefinitionDetail; value: string; onChange: (id: string) => void; error?: string; onPick: (account: MapAccount | null) => void }) {
  const { accounts, error: failed } = useAccounts()
  const types = accountTypesFor(definition.statement_type)
  const choices = accounts.filter((a) => a.status === 'ACTIVE' && types.includes(a.account_type))
  const pick = (id: string) => {
    onChange(id)
    onPick(choices.find((a) => a.id === id) ?? null)
  }
  return (
    <Field label="Akun" error={error} hint={failed ? 'Daftar akun tidak dapat dimuat (butuh izin melihat bagan akun).' : 'Akun induk mencakup seluruh akun posting di bawahnya.'}>
      {(p) => (
        <select className="select" value={value} onChange={(e) => pick(e.target.value)} {...p}>
          <option value="">Pilih akun…</option>
          {choices.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}{a.is_postable ? '' : ' (induk)'}</option>)}
        </select>
      )}
    </Field>
  )
}

/**
 * Map one account to one line of a draft. The API replaces the mapping as a unit, so the request carries every mapping that stays plus this one;
 * the server validates the whole proposal (an account maps once per definition, no cash accounts on a cash flow, correct account types) and
 * refuses it with findings that are shown here. From the unmapped panel the account is fixed; from a line the line is fixed.
 */
export function MappingDialog({ definition, lineCode, account, onClose, onSaved }: { definition: DefinitionDetail; lineCode?: string; account?: MapAccount; onClose: () => void; onSaved: (detail: DefinitionDetail) => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const lines = mappableLines(definition.lines)
  const [line, setLine] = useState(lineCode ?? '')
  const [accountId, setAccountId] = useState(account?.id ?? '')
  const [picked, setPicked] = useState<MapAccount | null>(account ?? null)
  const [local, setLocal] = useState<{ line?: string; account?: string }>({})
  const { errors, warnings } = refusalFindings(error)
  const already = accountId ? mappedAt(definition.lines, accountId) : null

  async function submit() {
    if (!line || !accountId) return setLocal({ line: line ? undefined : 'Pilih baris.', account: accountId ? undefined : 'Pilih akun.' })
    setLocal({})
    // A header account stands for every posting account below it; a posting account has none, so the flag follows the account.
    const entry = { line_code: line, account_id: accountId, include_descendants: picked?.is_postable === false }
    const r = await run(async () => (await api.put<DefinitionDetail>(ENDPOINTS.mappings(definition.id), { mappings: withMapping(definition.lines, entry) })).data)
    if (r.ok) {
      toast.success('Pemetaan disimpan.')
      onSaved(r.value)
    }
  }

  return (
    <FormModal title="Petakan akun ke baris" submitLabel="Petakan" busy={busy} error={definitionError(error)} onSubmit={() => void submit()} onClose={onClose} wide>
      <RefusalFindings errors={errors} warnings={warnings} lookup={accountLookup(definition.lines, picked ? [{ id: picked.id, code: picked.code, name: picked.name }] : [])} />
      <div className="form-grid">
        <Field label="Baris" error={local.line ?? fieldError(error, 'mappings.0.line_code')}>
          {(p) => (
            <select className="select" value={line} disabled={!!lineCode} onChange={(e) => setLine(e.target.value)} {...p}>
              <option value="">Pilih baris…</option>
              {lines.map((l) => <option key={l.code} value={l.code}>{l.code} · {l.label}</option>)}
            </select>
          )}
        </Field>
        {account ? (
          <Field label="Akun" hint="Akun ini belum tercakup oleh baris mana pun.">
            {(p) => <input className="input" readOnly value={`${account.code} · ${account.name}`} {...p} />}
          </Field>
        ) : (
          <AccountSelect definition={definition} value={accountId} onChange={setAccountId} onPick={setPicked} error={local.account ?? fieldError(error, 'mappings.0.account_id')} />
        )}
      </div>
      {already && <p className="muted">Akun ini sudah dipetakan ke baris <span className="mono">{already.code}</span>. Satu akun hanya boleh dipetakan sekali per definisi, jadi server akan menolak pemetaan kedua.</p>}
    </FormModal>
  )
}
