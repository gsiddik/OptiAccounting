import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { todayIn } from '../../lib/format'
import { API } from '../../lib/operational'
import { fieldMessage, useAct } from '../operational/payables/messages'
import type { Asset } from './types'

/** Capitalize a draft: the server issues the number, posts the journal (mode "posting") and builds the schedule from the saved terms. */
export function CapitalizeDialog({ asset, onClose, onDone }: { asset: Asset; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const registerOnly = asset.capitalization_mode === 'REGISTER_ONLY'
  return (
    <ConfirmDialog
      title="Kapitalisasi aset"
      confirmLabel="Kapitalisasi"
      busy={busy}
      error={error}
      message={registerOnly
        ? `Aset ${asset.name} dicatat di register dengan nomor resmi dan jadwal penyusutan. Tidak ada jurnal baru: biayanya sudah dijurnal oleh faktur vendor yang dipilih.`
        : `Aset ${asset.name} mendapat nomor resmi, jurnal kapitalisasi (Debit aset tetap, Kredit akun sumber) diposting, dan jadwal penyusutan dibuat dari ketentuan yang tersimpan. Ketentuan keuangannya tidak dapat diubah lagi.`}
      onClose={onClose}
      onConfirm={async () => {
        const r = await run(() => api.post(`${API}/assets/${asset.id}/capitalize`))
        if (r.ok) onDone()
      }}
    />
  )
}

/** Put a draft aside. It stays in the history as inactive and is never deleted. */
export function DiscardDialog({ asset, onClose, onDone }: { asset: Asset; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  return (
    <ConfirmDialog
      title="Buang draf aset"
      confirmLabel="Buang draf"
      danger
      reasonRequired
      busy={busy}
      error={error}
      message={`Draf aset ${asset.name} dinonaktifkan dan tidak dapat dikapitalisasi lagi. Riwayatnya tetap tersimpan.`}
      onClose={onClose}
      onConfirm={async (reason) => {
        const r = await run(() => api.post(`${API}/assets/${asset.id}/discard`, { reason }))
        if (r.ok) onDone()
      }}
    />
  )
}

/** Undo a capitalization (only while nothing was depreciated or disposed). The posting date matters only when a journal has to be reversed. */
export function ReverseCapitalizationDialog({ asset, onClose, onDone }: { asset: Asset; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAct()
  const { tenant } = useCapabilities()
  const [f, setF] = useState({ reason: '', posting_date: tenant?.business_date ?? todayIn() })
  const posted = asset.capitalization_mode === 'POST'

  async function submit() {
    const body = { reason: f.reason.trim(), ...(posted && f.posting_date && { posting_date: f.posting_date }) }
    const r = await run(() => api.post(`${API}/assets/${asset.id}/reverse-capitalization`, body))
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Balik kapitalisasi" submitLabel="Balik kapitalisasi" danger busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <p>
        {posted
          ? 'Jurnal kapitalisasi dibalik dengan jurnal pembalik yang langsung diposting, jadwal penyusutan yang belum berjalan dibatalkan, dan aset menjadi nonaktif.'
          : 'Pencatatan aset dibatalkan dan jadwal penyusutan yang belum berjalan dibatalkan; aset menjadi nonaktif. Jurnal faktur vendor tidak tersentuh.'}
        {' '}Hanya bisa selama belum ada penyusutan atau pelepasan.
      </p>
      <div className="form-grid">
        {posted && (
          <Field label="Tanggal posting pembalikan" error={fieldMessage(error, 'posting_date')} hint="Harus pada periode yang terbuka.">
            {(p) => <input className="input" type="date" value={f.posting_date} onChange={(e) => setF((s) => ({ ...s, posting_date: e.target.value }))} {...p} />}
          </Field>
        )}
        <Field label="Alasan (dicatat di audit)" error={fieldMessage(error, 'reason')} full>
          {(p) => <textarea className="textarea" required maxLength={500} value={f.reason} onChange={(e) => setF((s) => ({ ...s, reason: e.target.value }))} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}
