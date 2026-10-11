import { useState } from 'react'
import { Link } from 'react-router-dom'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Badge, Banner, Button, Card, ErrorNotice, Field, Loading } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { formatDate } from '../../lib/format'
import { useAction, type Resource } from '../../lib/hooks'
import { Facts } from './components'
import { ENDPOINTS, PATHS } from './data'
import { closingError } from './labels'
import type { RuleDefaultsResult, RuleStatus } from './types'

function DefaultsDialog({ onDone, onClose }: { onDone: () => void; onClose: () => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [from, setFrom] = useState('')

  async function submit() {
    const result = await run(async () => (await api.post<RuleDefaultsResult>(ENDPOINTS.ruleDefaults, from ? { effective_from: from } : {})).data)
    if (!result.ok) return
    const { created, skipped } = result.value
    toast.success(created.length > 0 ? 'Aturan posting tutup tahun diterbitkan.' : skipped.length > 0 ? 'Tidak ada yang diubah: aturan sudah ada.' : 'Tidak ada aturan baru.')
    onDone()
    onClose()
  }

  return (
    <FormModal title="Terapkan aturan posting bawaan" submitLabel="Terapkan" busy={busy} error={closingError(error)} onSubmit={() => void submit()} onClose={onClose}>
      <p>Menerbitkan aturan <strong>YEAR-END-CLOSING</strong>: akun pendapatan didebit, akun beban dikredit, dan hasil tahun berjalan dipindahkan ke akun yang Anda petakan pada peran laba ditahan.</p>
      <p>Tindakan ini idempoten dan tidak menimpa aturan yang sudah ada. Setelah itu aturan dapat diubah dengan menerbitkan versi baru di Aturan posting. Pemetaan akun laba ditahan tidak diatur di sini.</p>
      <Field label="Berlaku mulai (opsional)" hint="Kosong berarti sejak awal tahun fiskal pertama." error={fieldError(error, 'effective_from')}>
        {(p) => <input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}

/** Whether the posting rule and the retained earnings account that a year-end closing needs exist, with the one-click default rule for those who may publish rules. */
export function RuleStatusCard({ rule, canApply, onApplied }: { rule: Resource<RuleStatus>; canApply: boolean; onApplied: () => void }) {
  const { can } = useCapabilities()
  const [open, setOpen] = useState(false)
  const data = rule.data
  const event = data?.events.find((e) => e.event_type === 'YEAR_END_CLOSING')

  return (
    <Card title="Prasyarat tutup tahun">
      {rule.error ? (
        <ErrorNotice error={closingError(rule.error)} onRetry={rule.reload} />
      ) : !data ? (
        <Loading />
      ) : (
        <div className="stack">
          <Facts rows={[
            ['Aturan posting YEAR_END_CLOSING', <Badge key="r" tone={event?.ready ? 'ok' : 'bad'}>{event?.ready ? 'Terbit' : 'Belum terbit'}</Badge>],
            ['Kode aturan', event?.rule_code],
            ['Berlaku sejak', event?.effective_from && formatDate(event.effective_from)],
            ['Akun laba ditahan', data.retained_earnings.mapped && data.retained_earnings.account ? `${data.retained_earnings.account.code} · ${data.retained_earnings.account.name}` : <Badge key="m" tone="bad">Belum dipetakan</Badge>],
          ]} />
          {!event?.ready && (
            <Banner tone="warn">
              Tanpa aturan posting ini setiap proses tutup tahun ditolak server (YEAR_END_RULE_MISSING).
              {canApply ? ' Anda dapat menerbitkan aturan bawaan.' : ' Minta pengguna yang berwenang mengelola aturan posting untuk menerbitkannya.'}
            </Banner>
          )}
          {!data.retained_earnings.mapped && (
            <Banner tone="warn">
              Peran RETAINED_EARNINGS belum dipetakan{data.retained_earnings.problem ? ` (${data.retained_earnings.problem})` : ''}; tutup tahun ditolak sampai akun laba ditahan dipilih.
              {can('accounting.account_mapping.view') && <> <Link to={PATHS.accountMappings}>Buka pemetaan akun</Link></>}
            </Banner>
          )}
          {!event?.ready && canApply && <div><Button variant="primary" onClick={() => setOpen(true)}>Terapkan aturan bawaan</Button></div>}
        </div>
      )}
      {open && <DefaultsDialog onDone={onApplied} onClose={() => setOpen(false)} />}
    </Card>
  )
}
