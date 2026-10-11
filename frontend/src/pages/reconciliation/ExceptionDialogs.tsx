import { useState } from 'react'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { formatDate } from '../../lib/format'
import { useAction, useDebounced } from '../../lib/hooks'
import { ENDPOINTS, useMembers } from './data'
import { isStillMismatched, reconError, typeLabels } from './labels'
import type { ReconException } from './types'

export type ExceptionAction = 'assign' | 'explain' | 'resolve'

const SUCCESS: Record<ExceptionAction, string> = {
  assign: 'Pemilik pengecualian ditetapkan.',
  explain: 'Penjelasan dicatat. Status hitung tidak berubah.',
  resolve: 'Pengecualian diselesaikan: hitung ulang menunjukkan Cocok.',
}

export function ExceptionDialog({ action, exception, onChanged, onClose }: { action: ExceptionAction; exception: ReconException; onChanged: () => void; onClose: () => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  async function send(body: Record<string, string>) {
    const result = await run(async () => {
      try {
        return (await api.post<ReconException>(ENDPOINTS.exception(exception.id, action), body)).data
      } catch (e) {
        // The server recomputed (and kept the verification as evidence) before it refused, so what the page shows is stale.
        if (isStillMismatched(e)) onChanged()
        throw e
      }
    })
    if (result.ok) {
      toast.success(SUCCESS[action])
      onChanged()
      onClose()
    }
  }

  const title = `${typeLabels[exception.type] ?? exception.type} · ${formatDate(exception.as_of_date)}`
  if (action === 'assign') return <AssignForm title={title} exception={exception} busy={busy} error={error} onSubmit={(owner) => void send({ owner_id: owner })} onClose={onClose} />
  return <TextForm action={action} title={title} exception={exception} busy={busy} error={error} onSubmit={(text) => void send(action === 'explain' ? { notes: text } : { note: text })} onClose={onClose} />
}

type FormProps = { title: string; exception: ReconException; busy: boolean; error: unknown; onClose: () => void }

function AssignForm({ title, exception, busy, error, onSubmit, onClose }: FormProps & { onSubmit: (owner: string) => void }) {
  const [localError, setLocalError] = useState<string | undefined>()
  const { state } = useAuth()
  const me = state.status === 'ready' ? state.me.user : null
  const [owner, setOwner] = useState(exception.owner_id ?? '')
  const [search, setSearch] = useState('')
  const { members, canList } = useMembers(useDebounced(search))
  const others = members.filter((m) => m.user.id !== me?.id)
  // The current owner stays selectable even when the member list does not hold them (search, paging, or no right to list members).
  const current = exception.owner && exception.owner.id !== me?.id && !others.some((m) => m.user.id === exception.owner!.id) ? exception.owner : null

  function submit() {
    if (!owner) return setLocalError('Pilih pemilik.')
    setLocalError(undefined)
    onSubmit(owner)
  }

  return (
    <FormModal title="Tetapkan pemilik" submitLabel="Tetapkan" busy={busy} error={reconError(error)} onSubmit={submit} onClose={onClose}>
      <p><strong>{title}</strong></p>
      <p>Pemilik bertanggung jawab menindaklanjuti selisih ini. Penugasan hanya mengubah daftar kerja; hasil rekonsiliasi tidak berubah.</p>
      <div className="form-grid">
        {canList && (
          <Field label="Cari anggota" hint="Nama atau e-mail.">
            {(p) => <input className="input" type="search" value={search} onChange={(e) => setSearch(e.target.value)} {...p} />}
          </Field>
        )}
        <Field label="Pemilik" error={localError} full>
          {(p) => (
            <select className="select" value={owner} onChange={(e) => setOwner(e.target.value)} {...p}>
              <option value="">Pilih pemilik…</option>
              {me && <option value={me.id}>Saya sendiri · {me.name}</option>}
              {current && <option value={current.id}>{current.name}</option>}
              {others.map((m) => <option key={m.user.id} value={m.user.id}>{m.user.name} · {m.user.email}</option>)}
            </select>
          )}
        </Field>
      </div>
    </FormModal>
  )
}

function TextForm({ action, title, exception, busy, error, onSubmit, onClose }: FormProps & { action: 'explain' | 'resolve'; onSubmit: (text: string) => void }) {
  const [localError, setLocalError] = useState<string | undefined>()
  const explain = action === 'explain'
  const [text, setText] = useState(explain ? exception.notes ?? '' : '')

  function submit() {
    if (text.trim().length < 3) return setLocalError('Isi minimal 3 karakter.')
    setLocalError(undefined)
    onSubmit(text.trim())
  }

  return (
    <FormModal
      title={explain ? 'Jelaskan selisih' : 'Selesaikan pengecualian'}
      submitLabel={explain ? 'Simpan penjelasan' : 'Hitung ulang & selesaikan'}
      busy={busy}
      error={reconError(error)}
      onSubmit={submit}
      onClose={onClose}
    >
      <p><strong>{title}</strong></p>
      {explain ? (
        <p>Penjelasan hanya mencatat mengapa selisih ada. <strong>Penjelasan tidak mengubah status hitung maupun angkanya</strong>: hanya perbaikan di buku besar atau sub-buku, lalu hitung ulang, yang dapat membuatnya Cocok.</p>
      ) : (
        <p>Server menghitung ulang rekonsiliasi ini sekarang dan menyimpan hasilnya sebagai bukti. Pengecualian hanya selesai bila hasilnya <strong>Cocok</strong>; bila masih berselisih, pengecualian tetap terbuka dengan angka terbaru dan tidak ada cara menyelesaikannya dengan tangan.</p>
      )}
      <Field label={explain ? 'Penjelasan' : 'Catatan penyelesaian'} error={localError} hint="Minimal 3 karakter, maksimal 1000." full>
        {(p) => <textarea className="textarea" required maxLength={1000} value={text} onChange={(e) => setText(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}
