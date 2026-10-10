import { useState } from 'react'
import { DataTable } from '../../../components/DataTable'
import { ConfirmDialog, FormModal } from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { Button, EmptyState, ErrorNotice, Field, Loading, StatusBadge } from '../../../components/ui'
import { api } from '../../../lib/api'
import { API, usePaymentTerms, type PaymentTerm, type TermsEndpoint } from '../../../lib/operational'
import { termTypeLabels } from '../../../lib/operationalLabels'
import { fieldMessage, useAct } from './messages'

type TermType = PaymentTerm['term_type']
const TYPES: TermType[] = ['NET_DAYS', 'END_OF_MONTH', 'CUSTOM']

const rule = (t: PaymentTerm) => (t.term_type === 'CUSTOM' ? 'Tanggal diisi sendiri' : t.term_type === 'END_OF_MONTH' ? `Akhir bulan + ${t.due_days ?? 0} hari` : `Tanggal dokumen + ${t.due_days ?? 0} hari`)

/**
 * The "Termin pembayaran" tab: list, create / edit, activate / deactivate, delete, and the standard set. Payables use `payment-terms`; a
 * receivables page uses `ar-payment-terms` (the same terms, behind the customer permissions). `usedBy` names who may be using a term.
 */
export function PaymentTermsPanel({ manage, endpoint = 'payment-terms', usedBy = 'vendor atau faktur' }: { manage: boolean; endpoint?: TermsEndpoint; usedBy?: string }) {
  const toast = useToast()
  const { terms, loading, error, reload } = usePaymentTerms(endpoint)
  const defaults = useAct()
  const [editing, setEditing] = useState<PaymentTerm | 'new' | null>(null)
  const [toggling, setToggling] = useState<PaymentTerm | null>(null)
  const [deleting, setDeleting] = useState<PaymentTerm | null>(null)

  async function applyDefaults() {
    const r = await defaults.run(async () => (await api.post<{ created: string[] }>(`${API}/${endpoint}/defaults`)).data)
    if (!r.ok) return
    toast.success(r.value.created.length > 0 ? `${r.value.created.length} termin standar ditambahkan (${r.value.created.join(', ')}).` : 'Semua termin standar sudah ada.')
    reload()
  }

  if (loading && terms.length === 0 && !error) return <Loading />
  if (error) return <ErrorNotice error={error} onRetry={reload} />

  return (
    <>
      <div className="card-head">
        <span className="muted">Termin menentukan jatuh tempo faktur. Mengubah termin tidak menggeser jatuh tempo faktur yang sudah dibuat.</span>
        {manage && (
          <div className="actions">
            <Button size="sm" loading={defaults.busy} onClick={() => void applyDefaults()}>Terapkan termin standar</Button>
            <Button size="sm" variant="primary" onClick={() => setEditing('new')}>Termin baru</Button>
          </div>
        )}
      </div>
      {defaults.error != null && <ErrorNotice error={defaults.error} />}
      {terms.length === 0 ? (
        <EmptyState title="Belum ada termin pembayaran">{manage ? 'Terapkan termin standar atau buat termin pertama.' : 'Minta administrator menyiapkan termin pembayaran.'}</EmptyState>
      ) : (
        <DataTable
          caption="Termin pembayaran"
          rows={terms}
          rowKey={(t) => t.id}
          columns={[
            { header: 'Kode', primary: true, cell: (t) => <span className="mono">{t.code}</span> },
            { header: 'Nama', cell: (t) => <>{t.name}{t.description && <div className="muted">{t.description}</div>}</> },
            { header: 'Jenis', cell: (t) => termTypeLabels[t.term_type] ?? t.term_type },
            { header: 'Aturan jatuh tempo', cell: (t) => rule(t) },
            { header: 'Jatuh tempo manual', cell: (t) => (t.allows_due_date_override ? 'Diizinkan' : 'Tidak') },
            { header: 'Status', cell: (t) => <StatusBadge status={t.status} /> },
            {
              header: 'Aksi',
              actions: true,
              cell: (t) => manage && (
                <div className="actions">
                  <Button size="sm" aria-label={`Ubah termin ${t.code}`} onClick={() => setEditing(t)}>Ubah</Button>
                  <Button size="sm" aria-label={`${t.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'} termin ${t.code}`} onClick={() => setToggling(t)}>{t.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
                  <Button size="sm" variant="danger" aria-label={`Hapus termin ${t.code}`} onClick={() => setDeleting(t)}>Hapus</Button>
                </div>
              ),
            },
          ]}
        />
      )}
      {editing && <TermForm endpoint={endpoint} term={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); reload(); toast.success('Termin pembayaran disimpan.') }} />}
      {toggling && <TermStatus endpoint={endpoint} term={toggling} onClose={() => setToggling(null)} onDone={() => { setToggling(null); reload(); toast.success('Status termin diperbarui.') }} />}
      {deleting && <TermDelete endpoint={endpoint} usedBy={usedBy} term={deleting} onClose={() => setDeleting(null)} onDone={() => { setDeleting(null); reload(); toast.success('Termin dihapus.') }} />}
    </>
  )
}

function TermForm({ endpoint, term, onClose, onDone }: { endpoint: TermsEndpoint; term: PaymentTerm | null; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const [f, setF] = useState({
    code: term?.code ?? '',
    name: term?.name ?? '',
    term_type: (term?.term_type ?? 'NET_DAYS') as TermType,
    due_days: term?.due_days === null || term?.due_days === undefined ? '30' : String(term.due_days),
    allows_due_date_override: term?.allows_due_date_override ?? false,
    description: term?.description ?? '',
  })
  const custom = f.term_type === 'CUSTOM'
  const err = (name: string) => fieldMessage(error, name)

  async function submit() {
    const body = {
      code: f.code.trim(), name: f.name.trim(), term_type: f.term_type,
      due_days: custom ? null : f.due_days.trim() === '' ? null : Number(f.due_days),
      allows_due_date_override: custom ? true : f.allows_due_date_override,
      description: f.description.trim() || null,
    }
    const r = await run(() => (term ? api.patch(`${API}/${endpoint}/${term.id}`, body) : api.post(`${API}/${endpoint}`, body)))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={term ? `Ubah termin ${term.code}` : 'Termin baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Kode" error={err('code')} hint="Huruf, angka, titik, atau strip.">{(p) => <input className="input mono" required maxLength={30} value={f.code} onChange={(e) => setF((s) => ({ ...s, code: e.target.value }))} {...p} />}</Field>
        <Field label="Nama" error={err('name')}>{(p) => <input className="input" required maxLength={100} value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} {...p} />}</Field>
        <Field label="Jenis termin" error={err('term_type')}>
          {(p) => (
            <select className="select" value={f.term_type} onChange={(e) => setF((s) => ({ ...s, term_type: e.target.value as TermType }))} {...p}>
              {TYPES.map((t) => <option key={t} value={t}>{termTypeLabels[t]}</option>)}
            </select>
          )}
        </Field>
        {!custom && (
          <Field label="Jumlah hari" error={err('due_days')} hint={f.term_type === 'END_OF_MONTH' ? 'Jatuh tempo = akhir bulan tanggal dokumen + jumlah hari.' : 'Jatuh tempo = tanggal dokumen + jumlah hari.'}>
            {(p) => <input className="input amount" type="number" min={0} max={3650} step={1} required value={f.due_days} onChange={(e) => setF((s) => ({ ...s, due_days: e.target.value }))} {...p} />}
          </Field>
        )}
        <label className="check full">
          <input type="checkbox" checked={custom ? true : f.allows_due_date_override} disabled={custom} onChange={(e) => setF((s) => ({ ...s, allows_due_date_override: e.target.checked }))} />
          Izinkan jatuh tempo yang berbeda dari hasil hitung{custom ? ' (selalu untuk termin ini)' : ''}
        </label>
        <Field label="Keterangan" error={err('description')} full>{(p) => <input className="input" maxLength={255} value={f.description} onChange={(e) => setF((s) => ({ ...s, description: e.target.value }))} {...p} />}</Field>
      </div>
    </FormModal>
  )
}

function TermStatus({ endpoint, term, onClose, onDone }: { endpoint: TermsEndpoint; term: PaymentTerm; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const next = term.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  return (
    <ConfirmDialog
      title={next === 'ACTIVE' ? 'Aktifkan termin' : 'Nonaktifkan termin'}
      confirmLabel={next === 'ACTIVE' ? 'Aktifkan' : 'Nonaktifkan'}
      danger={next === 'INACTIVE'}
      busy={busy}
      error={error}
      message={next === 'ACTIVE' ? `Aktifkan kembali ${term.code} · ${term.name}?` : `${term.code} · ${term.name} tidak dapat dipilih untuk faktur baru. Faktur yang sudah ada tidak berubah.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`${API}/${endpoint}/${term.id}/status`, { status: next }))
        if (r.ok) onDone()
      }}
    />
  )
}

function TermDelete({ endpoint, usedBy, term, onClose, onDone }: { endpoint: TermsEndpoint; usedBy: string; term: PaymentTerm; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  return (
    <ConfirmDialog
      title="Hapus termin"
      confirmLabel="Hapus"
      danger
      busy={busy}
      error={error}
      message={`Hapus ${term.code} · ${term.name}? Termin yang sudah dipakai ${usedBy} tidak dapat dihapus; nonaktifkan saja.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.delete(`${API}/${endpoint}/${term.id}`))
        if (r.ok) onDone()
      }}
    />
  )
}
