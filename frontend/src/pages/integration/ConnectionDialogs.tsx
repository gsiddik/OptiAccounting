import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Field } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { fieldError } from '../../lib/forms'
import { useAction } from '../../lib/hooks'
import { ENDPOINTS, useSettingOptions } from './data'
import { integrationError, sourceLabel } from './labels'
import type { Connection, ConnectionSettings } from './types'

export type Lifecycle = 'activate' | 'pause' | 'resume' | 'disconnect'

const COPY: Record<Lifecycle, { title: string; confirm: string; done: string; danger?: boolean }> = {
  activate: { title: 'Aktifkan koneksi', confirm: 'Aktifkan', done: 'Koneksi diaktifkan.' },
  pause: { title: 'Jeda koneksi', confirm: 'Jeda', done: 'Koneksi dijeda.' },
  resume: { title: 'Lanjutkan koneksi', confirm: 'Lanjutkan', done: 'Koneksi dilanjutkan.' },
  disconnect: { title: 'Putuskan koneksi', confirm: 'Putuskan', done: 'Koneksi diputus.', danger: true },
}

function message(action: Lifecycle, c: Connection) {
  const source = sourceLabel(c.source_system)
  switch (action) {
    case 'activate':
      return <p>Setelah aktif, OptiEntry menarik kejadian dari {source} secara berkala dan membukukannya lewat aturan posting. Hanya kejadian sejak tanggal cutover ({formatDate(c.cutover_date)}) yang diproses; riwayat sebelumnya tidak pernah diposting otomatis.</p>
    case 'pause':
      return <p>Penarikan dari {source} berhenti sementara. Kejadian yang sudah diterima tetap tersimpan dan dapat dilanjutkan kapan saja.</p>
    case 'resume':
      return <p>Penarikan dari {source} dilanjutkan dari posisi terakhir dan penghitung kegagalan dimulai dari nol.</p>
    case 'disconnect':
      return <p>Penarikan dari {source} berhenti. Kejadian yang sudah diterima serta dokumen dan jurnal yang sudah dibukukan tetap ada dan tidak berubah. Koneksi dapat dikonfigurasi ulang nanti.</p>
  }
}

/** Activate, pause, resume or disconnect a connection after a confirmation. The API decides whether the move is allowed from the current status. */
export function LifecycleDialog({ connection, action, onDone, onClose }: { connection: Connection; action: Lifecycle; onDone: () => void; onClose: () => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const copy = COPY[action]

  async function send() {
    const result = await run(async () => (await api.post(ENDPOINTS.action(connection.id, action))).data)
    if (result.ok) {
      toast.success(copy.done)
      onDone()
      onClose()
    }
  }

  return <ConfirmDialog title={copy.title} confirmLabel={copy.confirm} danger={copy.danger} busy={busy} error={integrationError(error)} message={message(action, connection)} onConfirm={() => void send()} onClose={onClose} />
}

/**
 * The OptiFleet connector settings: which cash/bank account vendor payments are paid from and which expense category invoices are booked to.
 * Both are required before an OptiFleet connection can be activated; the API checks that the ids belong to this organisation.
 */
export function SettingsDialog({ connection, current, onDone, onClose }: { connection: Connection; current: ConnectionSettings; onDone: () => void; onClose: () => void }) {
  const toast = useToast()
  const options = useSettingOptions()
  const { busy, error, run } = useAction()
  const [cash, setCash] = useState(current.payables_cash_bank_account_id ?? '')
  const [category, setCategory] = useState(current.expense_category_id ?? '')

  async function save() {
    // Settings merge key by key on the server, so only the keys that changed are sent (null removes one).
    const settings: ConnectionSettings = {}
    if (cash !== (current.payables_cash_bank_account_id ?? '')) settings.payables_cash_bank_account_id = cash || null
    if (category !== (current.expense_category_id ?? '')) settings.expense_category_id = category || null
    if (Object.keys(settings).length === 0) return onClose()
    const result = await run(async () => (await api.patch(ENDPOINTS.connection(connection.id), { settings })).data)
    if (result.ok) {
      toast.success('Pengaturan OptiFleet disimpan.')
      onDone()
      onClose()
    }
  }

  return (
    <FormModal title="Pengaturan OptiFleet" busy={busy} error={integrationError(error)} onSubmit={() => void save()} onClose={onClose}>
      <p>Faktur bengkel dibukukan sebagai faktur vendor ke kategori beban di bawah, dan pembayarannya keluar dari akun kas/bank yang dipilih. Akun jurnal tetap ditentukan aturan posting dan pemetaan akun, bukan oleh sistem sumber.</p>
      {options.error != null && (
        <>
          <Banner tone="bad">Daftar akun kas/bank atau kategori beban tidak dapat dimuat. Memilih membutuhkan izin melihat keduanya.</Banner>
          <Button onClick={options.reload}>Coba lagi</Button>
        </>
      )}
      <div className="form-grid">
        <Field label="Akun kas/bank pembayaran utang" error={fieldError(error, 'settings.payables_cash_bank_account_id')} full>
          {(p) => (
            <select className="select" value={cash} onChange={(e) => setCash(e.target.value)} {...p}>
              <option value="">Belum dipilih</option>
              {cash && !options.accounts.some((a) => a.id === cash) && <option value={cash}>Akun terpilih sebelumnya</option>}
              {options.accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
            </select>
          )}
        </Field>
        <Field label="Kategori beban faktur bengkel" error={fieldError(error, 'settings.expense_category_id')} full>
          {(p) => (
            <select className="select" value={category} onChange={(e) => setCategory(e.target.value)} {...p}>
              <option value="">Belum dipilih</option>
              {category && !options.categories.some((c) => c.id === category) && <option value={category}>Kategori terpilih sebelumnya</option>}
              {options.categories.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
            </select>
          )}
        </Field>
      </div>
    </FormModal>
  )
}
