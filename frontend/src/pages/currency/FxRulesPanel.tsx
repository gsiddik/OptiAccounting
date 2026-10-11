import { useState } from 'react'
import { Link } from 'react-router-dom'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Field, Loading } from '../../components/ui'
import { ApiError, api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { formatDate } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { fxRoleLabels, fxSkipReasons } from './labels'
import type { FxDefaultsResult, FxSetup } from './types'

/** Why a default rule was skipped: a known state in plain words, else the localized text of the refusal code. */
const skipReason = (reason: string): string => fxSkipReasons[reason] ?? describeError(new ApiError(409, reason, reason, {}, {}))

/**
 * "Selisih kurs": whether the posting rules and account roles the realised exchange difference needs are ready. The status comes from the
 * server; applying the defaults needs `accounting.posting_rule.manage` and never overwrites a rule that is already published.
 */
export function FxRulesPanel() {
  const toast = useToast()
  const { can } = useCapabilities()
  const access = useModuleAccess(MODULES.multiCurrency)
  const allowed = can('accounting.exchange_rate.view')
  const apply = access.canChange('accounting.posting_rule.manage')
  const setup = useResource(async () => (allowed ? (await api.get<FxSetup>(`${API}/fx-rules`)).data : null), [allowed])
  const [applying, setApplying] = useState(false)
  const [result, setResult] = useState<FxDefaultsResult | null>(null)

  if (!allowed) return null
  const data = setup.data
  const missing = data ? data.events.filter((e) => !e.ready) : []
  const ready = data != null && missing.length === 0 && data.unmapped_roles.length === 0

  return (
    <Card title="Selisih kurs">
      <div className="stack">
        <p className="muted">
          Organisasi yang hanya memakai satu mata uang tidak membutuhkan pengaturan ini. Selisih kurs terealisasi muncul saat faktur mata uang asing dilunasi dengan kurs yang berbeda dari kurs fakturnya, dan dicatat lewat aturan posting.
        </p>

        {setup.loading && !data ? (
          <Loading />
        ) : setup.error || !data ? (
          <ErrorNotice error={setup.error} onRetry={setup.reload} />
        ) : (
          <>
            {ready ? <Banner tone="ok">Siap: aturan posting dan peran akun selisih kurs sudah lengkap.</Banner> : <Banner tone="warn">Belum lengkap: pelunasan faktur mata uang asing belum dapat diposting sampai aturan dan akunnya siap.</Banner>}
            <ul className="checklist" aria-label="Kesiapan selisih kurs">
              {data.events.map((e) => (
                <li key={e.event_type} className={e.ready ? 'done' : undefined}>
                  <span className="mark" aria-hidden="true">{e.ready ? '✓' : '!'}</span>
                  <div>
                    <strong>{e.name}</strong>
                    <div className="muted">{e.ready ? `Aturan ${e.rule_code ?? ''} berlaku sejak ${formatDate(e.effective_from)}.` : 'Belum ada aturan posting yang terbit.'}</div>
                  </div>
                  <span className="muted">{e.ready ? 'Siap' : 'Belum siap'}</span>
                </li>
              ))}
              <li className={data.unmapped_roles.length === 0 ? 'done' : undefined}>
                <span className="mark" aria-hidden="true">{data.unmapped_roles.length === 0 ? '✓' : '!'}</span>
                <div>
                  <strong>Peran akun</strong>
                  <div className="muted">
                    {data.unmapped_roles.length === 0
                      ? 'Semua peran akun yang dipakai sudah dipetakan ke akun.'
                      : `Belum dipetakan ke akun: ${data.unmapped_roles.map((r) => fxRoleLabels[r] ?? r).join(', ')}.`}
                    {data.unmapped_roles.length > 0 && can('accounting.account_mapping.view') && <> <Link to="/app/akuntansi/pemetaan-akun">Atur pemetaan akun</Link></>}
                  </div>
                </div>
                <span className="muted">{data.unmapped_roles.length === 0 ? 'Siap' : 'Belum siap'}</span>
              </li>
            </ul>

            {result && result.skipped.length > 0 && (
              <Banner tone="info">
                Dilewati: {result.skipped.map((s) => `${data.events.find((e) => e.event_type === s.event_type)?.name ?? s.event_type} (${skipReason(s.reason)})`).join('; ')}.
              </Banner>
            )}
            {apply && missing.length > 0 && <div className="actions"><Button variant="primary" onClick={() => setApplying(true)}>Terapkan aturan bawaan</Button></div>}
          </>
        )}
      </div>

      {applying && (
        <ApplyDefaultsDialog
          onClose={() => setApplying(false)}
          onDone={(r) => {
            setApplying(false)
            setResult(r)
            toast.success(r.created.length > 0 ? `${r.created.length} aturan posting selisih kurs diterbitkan.` : 'Tidak ada aturan baru: aturan yang diperlukan sudah ada.')
            setup.reload()
          }}
        />
      )}
    </Card>
  )
}

function ApplyDefaultsDialog({ onClose, onDone }: { onClose: () => void; onDone: (result: FxDefaultsResult) => void }) {
  const { busy, error, run } = useAction()
  const [from, setFrom] = useState('')

  async function submit() {
    const r = await run(async () => (await api.post<FxDefaultsResult>(`${API}/fx-rules/defaults`, from ? { effective_from: from } : {})).data)
    if (r.ok) onDone(r.value)
  }

  return (
    <FormModal title="Terapkan aturan posting selisih kurs" submitLabel="Terapkan" busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <p>
        Menerbitkan aturan posting bawaan untuk pembayaran vendor dan penerimaan pelanggan dalam mata uang asing. Aturan yang sudah terbit tidak diubah, dan aturan dapat direvisi kemudian sebagai versi baru. Peran akun laba dan rugi selisih kurs tetap perlu dipetakan ke akun.
      </p>
      <div className="form-grid">
        <Field label="Berlaku mulai" error={fieldError(error, 'effective_from')} hint="Kosongkan untuk memakai awal tahun fiskal pertama.">
          {(p) => <input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}
