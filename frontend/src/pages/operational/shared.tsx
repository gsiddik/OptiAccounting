import { useState } from 'react'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, ErrorNotice, Field } from '../../components/ui'
import { downloadCsv } from '../../lib/accounting'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { formatDateTime } from '../../lib/format'
import { useAction } from '../../lib/hooks'
import { statusLabel, describeError } from '../../lib/labels'
import type { DocTransition } from '../../lib/operational'
import { DimensionFilters } from '../accounting/shared'
import type { DimensionCatalog } from '../accounting/data'

export { Money, Side, Filters } from '../accounting/shared'

/** The status history of a document, oldest first. */
export function Timeline({ transitions }: { transitions: DocTransition[] | undefined }) {
  return (
    <ol className="timeline">
      {(transitions ?? []).map((t) => (
        <li key={t.id}>
          <strong>{t.from_status ? `${statusLabel(t.from_status)[0]} → ` : ''}{statusLabel(t.to_status)[0]}</strong>
          <span className="muted"> · {t.actor?.name ?? 'Sistem'} · {formatDateTime(t.occurred_at)}</span>
          {t.reason && <div>{t.reason}</div>}
        </li>
      ))}
    </ol>
  )
}

// ------------------------------------------------------------------------------------------------ export

/** Downloads a CSV export with the current list filters. Shown only to users with `accounting.report.export` (the API checks it too). */
export function ExportButton({ path, params, filename, label = 'Ekspor CSV' }: { path: string; params?: Record<string, string | number | boolean | undefined>; filename: string; label?: string }) {
  const { can } = useCapabilities()
  const toast = useToast()
  const action = useAction()
  if (!can('accounting.report.export')) return null
  async function run() {
    const r = await action.run(() => downloadCsv(path, params ?? {}, filename))
    if (!r.ok) toast.error('Ekspor gagal.')
  }
  return (
    <>
      <Button loading={action.busy} onClick={() => void run()}>{label}</Button>
      {action.error != null && <span className="muted" role="alert">{describeError(action.error)}</span>}
    </>
  )
}

// ------------------------------------------------------------------------------------------------ forms

/** Branch / business unit / cost center selectors for a document form. */
export function DimensionFields({ catalog, value, onChange, error }: { catalog: DimensionCatalog; value: { branch_id: string; business_unit_id: string; cost_center_id: string }; onChange: (patch: Partial<{ branch_id: string; business_unit_id: string; cost_center_id: string }>) => void; error?: unknown }) {
  return (
    <>
      {catalog.branches.length > 0 && <Field label="Cabang" error={fieldError(error, 'branch_id')}>{(p) => (
        <select className="select" value={value.branch_id} onChange={(e) => onChange({ branch_id: e.target.value, business_unit_id: '' })} {...p}>
          <option value="">Tanpa cabang</option>
          {catalog.branches.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
        </select>
      )}</Field>}
      {catalog.business_units.length > 0 && <Field label="Unit bisnis" error={fieldError(error, 'business_unit_id')}>{(p) => (
        <select className="select" value={value.business_unit_id} onChange={(e) => onChange({ business_unit_id: e.target.value })} {...p}>
          <option value="">Tanpa unit bisnis</option>
          {catalog.business_units.filter((u) => !value.branch_id || u.branch_id === null || u.branch_id === value.branch_id).map((u) => <option key={u.id} value={u.id}>{u.code} · {u.name}</option>)}
        </select>
      )}</Field>}
      {catalog.cost_centers.length > 0 && <Field label="Pusat biaya" error={fieldError(error, 'cost_center_id')}>{(p) => (
        <select className="select" value={value.cost_center_id} onChange={(e) => onChange({ cost_center_id: e.target.value })} {...p}>
          <option value="">Tanpa pusat biaya</option>
          {catalog.cost_centers.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
        </select>
      )}</Field>}
    </>
  )
}

export { DimensionFilters }

/** A banner explaining a read-only subscription at the top of an OA2 page. */
export function ReadOnlyNotice({ show }: { show: boolean }) {
  return show ? <Banner tone="warn">Modul ini dalam mode hanya baca: data dapat dilihat dan diekspor, tetapi tidak dapat diubah.</Banner> : null
}

export { ErrorNotice }

/** Reverse dialog shared by the document detail pages: posting date and a reason, recorded in the audit trail. */
export function ReverseDialog({ noun, busy, error, onClose, onConfirm }: { noun: string; busy: boolean; error: unknown; onClose: () => void; onConfirm: (body: { reason: string; posting_date: string | null }) => void }) {
  const { tenant } = useCapabilities()
  const [f, setF] = useState({ reason: '', posting_date: tenant?.business_date ?? '' })
  return (
    <FormModal title={`Balik ${noun}`} submitLabel="Balik" busy={busy} error={error} onSubmit={() => onConfirm({ reason: f.reason.trim(), posting_date: f.posting_date || null })} onClose={onClose}>
      <p>Pembalikan membuat jurnal pembalik yang langsung diposting. Dokumen asal tetap tidak berubah dan hanya dapat dibalik sekali.</p>
      <div className="form-grid">
        <Field label="Tanggal posting pembalikan" error={fieldError(error, 'posting_date')} hint="Harus pada periode yang terbuka.">
          {(p) => <input className="input" type="date" value={f.posting_date} onChange={(e) => setF((s) => ({ ...s, posting_date: e.target.value }))} {...p} />}
        </Field>
        <Field label="Alasan (dicatat di audit)" error={fieldError(error, 'reason')} full>
          {(p) => <textarea className="textarea" required maxLength={500} value={f.reason} onChange={(e) => setF((s) => ({ ...s, reason: e.target.value }))} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}

