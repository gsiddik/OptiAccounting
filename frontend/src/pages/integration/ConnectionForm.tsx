import { useState } from 'react'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Field } from '../../components/ui'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction } from '../../lib/hooks'
import { ENDPOINTS, OPTIFLEET } from './data'
import { cutoverPolicyLabels, integrationError, sourceSystemLabels } from './labels'
import type { Connection } from './types'

const OTHER = 'other'
const SYSTEM_CODE = /^[a-z][a-z0-9_]{1,39}$/
const POLICIES = ['OPENING_BALANCE_ONLY', 'START_FROM_CUTOVER_DATE']

type Draft = { name: string; system: string; code: string; reference: string; baseUrl: string; credential: string; cutoverDate: string; policy: string; interval: string }

const blank: Draft = { name: '', system: OPTIFLEET, code: '', reference: '', baseUrl: '', credential: '', cutoverDate: '', policy: 'OPENING_BALANCE_ONLY', interval: '5' }

const toDraft = (c: Connection): Draft => ({
  name: c.name, system: c.source_system, code: '', reference: c.source_tenant_reference ?? '', baseUrl: c.base_url ?? '', credential: '', cutoverDate: c.cutover_date ?? '',
  policy: c.cutover_policy, interval: String(c.pull_interval_minutes),
})

/** The create body: what was filled in. The credential is sent once and never read back. */
function createBody(d: Draft) {
  const body: Record<string, unknown> = { name: d.name.trim(), source_system: d.system === OTHER ? d.code.trim() : d.system, cutover_policy: d.policy, pull_interval_minutes: Number(d.interval) }
  if (d.reference.trim()) body.source_tenant_reference = d.reference.trim()
  if (d.baseUrl.trim()) body.base_url = d.baseUrl.trim()
  if (d.credential) body.credential = d.credential
  if (d.cutoverDate) body.cutover_date = d.cutoverDate
  return body
}

/** The update body: only what differs from the saved connection; the credential only when a new one was typed. */
function updateBody(c: Connection, d: Draft, locked: boolean) {
  const before = toDraft(c)
  const body: Record<string, unknown> = {}
  if (d.name.trim() !== before.name) body.name = d.name.trim()
  if (d.baseUrl.trim() !== before.baseUrl) body.base_url = d.baseUrl.trim() || null
  if (d.credential) body.credential = d.credential
  if (d.interval !== before.interval) body.pull_interval_minutes = Number(d.interval)
  if (!locked) {
    if (d.reference.trim() !== before.reference) body.source_tenant_reference = d.reference.trim() || null
    if (d.cutoverDate !== before.cutoverDate) body.cutover_date = d.cutoverDate || null
    if (d.policy !== before.policy) body.cutover_policy = d.policy
  }
  return body
}

/**
 * Create a connection, or (with `connection`) change its configuration. `locked` is true once events were received: the source tenant
 * reference and the cutover then identify what was received and cannot change (the API answers CONNECTION_LOCKED otherwise).
 */
export function ConnectionForm({ connection, locked = false, onDone, onClose }: { connection?: Connection; locked?: boolean; onDone: (saved: Connection) => void; onClose: () => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [d, setD] = useState<Draft>(connection ? toDraft(connection) : blank)
  const [local, setLocal] = useState<Record<string, string>>({})
  const set = (patch: Partial<Draft>) => setD((s) => ({ ...s, ...patch }))
  const editing = connection !== undefined

  async function submit() {
    const problems: Record<string, string> = {}
    if (d.name.trim().length < 2) problems.name = 'Nama minimal 2 karakter.'
    if (!editing && d.system === OTHER && !SYSTEM_CODE.test(d.code.trim())) problems.source_system = 'Kode huruf kecil, angka atau garis bawah, diawali huruf (2 sampai 40 karakter).'
    if (!/^\d+$/.test(d.interval) || Number(d.interval) < 1 || Number(d.interval) > 1440) problems.pull_interval_minutes = 'Isi 1 sampai 1440 menit.'
    setLocal(problems)
    if (Object.keys(problems).length > 0) return

    const result = await run(async () => {
      if (!connection) return (await api.post<Connection>(ENDPOINTS.connections, createBody(d))).data
      return (await api.patch<Connection>(ENDPOINTS.connection(connection.id), updateBody(connection, d, locked))).data
    })
    if (result.ok) {
      toast.success(editing ? 'Konfigurasi koneksi disimpan.' : 'Koneksi dibuat.')
      onDone(result.value)
    }
  }

  const err = (name: string) => local[name] ?? fieldError(error, name)
  const lockedHint = 'Sudah ada kejadian yang diterima, jadi tidak dapat diubah lagi.'
  return (
    <FormModal title={editing ? 'Ubah koneksi' : 'Koneksi baru'} submitLabel={editing ? 'Simpan' : 'Buat koneksi'} busy={busy} error={integrationError(error)} onSubmit={() => void submit()} onClose={onClose} wide>
      {editing && connection.status === 'DISCONNECTED' && <Banner tone="info">Menyimpan perubahan pada koneksi yang terputus menyambungkannya kembali ke status konfigurasi.</Banner>}
      <div className="form-grid">
        <Field label="Nama koneksi" error={err('name')}>
          {(p) => <input className="input" value={d.name} maxLength={120} onChange={(e) => set({ name: e.target.value })} {...p} />}
        </Field>
        {editing ? (
          <Field label="Sistem sumber" hint="Tidak dapat diubah.">
            {(p) => <input className="input" value={sourceSystemLabels[connection.source_system] ?? connection.source_system} disabled {...p} />}
          </Field>
        ) : (
          <Field label="Sistem sumber" error={err('source_system')}>
            {(p) => (
              <select className="select" value={d.system} onChange={(e) => set({ system: e.target.value })} {...p}>
                <option value={OPTIFLEET}>{sourceSystemLabels[OPTIFLEET]}</option>
                <option value={OTHER}>Sistem lain (kode manual)</option>
              </select>
            )}
          </Field>
        )}
        {!editing && d.system === OTHER && (
          <Field label="Kode sistem sumber" error={err('source_system')} hint="Contoh: sistem_pos." full>
            {(p) => <input className="input" value={d.code} maxLength={40} onChange={(e) => set({ code: e.target.value })} {...p} />}
          </Field>
        )}
        <Field label="Referensi tenant sumber" error={err('source_tenant_reference')} hint={locked ? lockedHint : 'Pengenal organisasi ini di sistem sumber.'}>
          {(p) => <input className="input" value={d.reference} maxLength={120} disabled={locked} onChange={(e) => set({ reference: e.target.value })} {...p} />}
        </Field>
        <Field label="URL sistem sumber" error={err('base_url')} hint="Alamat HTTPS sistem sumber, tanpa garis miring di akhir.">
          {(p) => <input className="input" type="url" value={d.baseUrl} maxLength={255} placeholder="https://" onChange={(e) => set({ baseUrl: e.target.value })} {...p} />}
        </Field>
        <Field label="Kredensial" error={err('credential')} hint={connection?.has_credential ? 'Tersimpan terenkripsi. Isi hanya untuk menggantinya; kolom ini tidak pernah menampilkan nilai tersimpan.' : 'Token akses hanya-baca dari sistem sumber (minimal 8 karakter). Disimpan terenkripsi dan tidak pernah ditampilkan kembali.'}>
          {(p) => <input className="input" type="password" autoComplete="new-password" value={d.credential} maxLength={500} onChange={(e) => set({ credential: e.target.value })} {...p} />}
        </Field>
        <Field label="Tanggal cutover" error={err('cutover_date')} hint={locked ? lockedHint : 'Kejadian sebelum tanggal ini tidak dibukukan.'}>
          {(p) => <input className="input" type="date" value={d.cutoverDate} disabled={locked} onChange={(e) => set({ cutoverDate: e.target.value })} {...p} />}
        </Field>
        <Field label="Kebijakan cutover" error={err('cutover_policy')} hint={locked ? lockedHint : undefined}>
          {(p) => (
            <select className="select" value={d.policy} disabled={locked} onChange={(e) => set({ policy: e.target.value })} {...p}>
              {POLICIES.map((v) => <option key={v} value={v}>{cutoverPolicyLabels[v]}</option>)}
            </select>
          )}
        </Field>
        <Field label="Interval tarik (menit)" error={err('pull_interval_minutes')} hint="Seberapa sering kejadian ditarik otomatis: 1 sampai 1440.">
          {(p) => <input className="input" inputMode="numeric" value={d.interval} onChange={(e) => set({ interval: e.target.value })} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}
