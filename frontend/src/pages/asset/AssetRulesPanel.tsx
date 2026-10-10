import { useState } from 'react'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Badge, Banner, Button, Card, ErrorNotice, Field, Loading } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { assetRuleEventLabels } from '../../lib/oa4Labels'
import { fieldMessage, useAct } from '../operational/payables/messages'
import { assetRoleLabels, ruleSkipLabels } from './labels'
import type { AssetRules, RuleDefaultsResult } from './types'

/**
 * Whether the three asset events (capitalization, depreciation, disposal) have a published posting rule, and which account roles the role mapping
 * still lacks. A user who may manage posting rules can apply the ready-made rules: the server never overwrites a rule that exists.
 */
export function AssetRulesPanel() {
  const { can } = useCapabilities()
  const access = useModuleAccess(MODULES.fixedAsset)
  const toast = useToast()
  const [applying, setApplying] = useState(false)
  const [result, setResult] = useState<RuleDefaultsResult | null>(null)
  const allowed = can('accounting.asset.view')
  const rules = useResource(async () => (allowed ? (await api.get<AssetRules>(`${API}/asset-rules`)).data : null), [allowed])
  const manage = access.canChange('accounting.posting_rule.manage')

  if (!allowed) return null
  const data = rules.data
  const ready = data?.events.every((e) => e.ready) ?? false

  return (
    <Card title="Aturan posting aset" actions={manage && data && !ready ? <Button onClick={() => setApplying(true)}>Terapkan aturan standar</Button> : undefined}>
      {rules.loading && !data ? (
        <Loading />
      ) : rules.error || !data ? (
        <ErrorNotice error={rules.error} onRetry={rules.reload} />
      ) : (
        <div className="stack">
          {ready ? (
            <Banner tone="ok">Aturan posting untuk kapitalisasi, penyusutan, dan pelepasan aset sudah terbit.</Banner>
          ) : (
            <Banner tone="warn">Ada peristiwa aset yang belum punya aturan posting, sehingga belum dapat diposting. {manage ? 'Terapkan aturan standar untuk melengkapinya.' : 'Minta pengguna dengan izin mengelola aturan posting untuk menerapkannya.'}</Banner>
          )}
          {result && (
            <Banner tone="info">
              {result.created.length > 0 ? `${result.created.length} aturan ditambahkan.` : 'Tidak ada aturan baru yang ditambahkan.'}
              {result.skipped.length > 0 && ` Dilewati: ${result.skipped.map((s) => `${assetRuleEventLabels[s.event_type] ?? s.event_type} (${ruleSkipLabels[s.reason] ?? s.reason})`).join(', ')}.`}
            </Banner>
          )}
          <DataTable
            caption="Aturan posting aset"
            rows={data.events}
            rowKey={(e) => e.event_type}
            columns={[
              { header: 'Peristiwa', primary: true, cell: (e) => assetRuleEventLabels[e.event_type] ?? e.name },
              { header: 'Status', cell: (e) => (e.ready ? <Badge tone="ok">Siap</Badge> : <Badge tone="warn">Belum ada aturan</Badge>) },
              { header: 'Kode aturan', cell: (e) => (e.rule_code ? <span className="mono">{e.rule_code}</span> : <span className="muted">—</span>) },
              { header: 'Berlaku mulai', cell: (e) => formatDate(e.effective_from) },
            ]}
          />
          {data.unmapped_roles.length > 0 && (
            <p className="muted" role="status">
              Peran akun yang belum dipetakan: {data.unmapped_roles.map((r) => assetRoleLabels[r] ?? r).join(', ')}. Tidak masalah bila kategori aset memakai akunnya sendiri; selain itu petakan peran tersebut
              {can('accounting.account_mapping.view') ? <> di <Link to="/app/akuntansi/pemetaan-akun">Pemetaan akun</Link></> : ' (butuh izin melihat pemetaan akun)'}.
            </p>
          )}
        </div>
      )}
      {applying && (
        <ApplyDefaultsDialog
          onClose={() => setApplying(false)}
          onDone={(outcome) => {
            setApplying(false)
            setResult(outcome)
            toast.success(outcome.created.length > 0 ? 'Aturan posting aset ditambahkan.' : 'Tidak ada aturan baru yang perlu ditambahkan.')
            rules.reload()
          }}
        />
      )}
    </Card>
  )
}

function ApplyDefaultsDialog({ onClose, onDone }: { onClose: () => void; onDone: (result: RuleDefaultsResult) => void }) {
  const { busy, error, run } = useAct()
  const [from, setFrom] = useState('')

  async function submit() {
    const r = await run(async () => (await api.post<RuleDefaultsResult>(`${API}/asset-rules/defaults`, from ? { effective_from: from } : {})).data)
    if (r.ok) onDone(r.value)
  }

  return (
    <FormModal title="Terapkan aturan posting aset" submitLabel="Terapkan" busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <p>
        Membuat dan menerbitkan aturan standar untuk peristiwa aset yang belum punya aturan terbit: kapitalisasi (Debit aset tetap, Kredit akun sumber), penyusutan (Debit beban, Kredit akumulasi), dan pelepasan.
        Aturan yang sudah ada tidak diubah; aturan standar dapat diganti dengan versi baru di halaman Aturan posting.
      </p>
      <div className="form-grid">
        <Field label="Berlaku mulai" error={fieldMessage(error, 'effective_from')} hint="Kosong berarti awal tahun fiskal pertama." full>
          {(p) => <input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}
