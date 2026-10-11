import { useState } from 'react'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction } from '../../lib/hooks'
import { ENDPOINTS } from './data'
import { closingError } from './labels'
import type { ChecklistItem } from './types'

export type ItemAction = 'complete' | 'waive' | 'reset'

const DONE: Record<ItemAction, string> = { complete: 'Butir ditandai selesai.', waive: 'Butir dikecualikan dengan alasan tercatat.', reset: 'Butir dikembalikan ke menunggu.' }

/** Complete, waive (reason required) or reset one manual checklist item of a period. The server refuses a system check and a closed period. */
export function ItemDialog({ action, periodId, item, onDone, onClose }: { action: ItemAction; periodId: string; item: ChecklistItem; onDone: () => void; onClose: () => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [text, setText] = useState('')
  const [localError, setLocalError] = useState<string | undefined>()

  async function send(body?: Record<string, string>) {
    const result = await run(async () => (await api.post(ENDPOINTS.item(periodId, item.code, action), body)).data)
    if (result.ok) {
      toast.success(DONE[action])
      onDone()
      onClose()
    }
  }

  if (action === 'reset') {
    return (
      <ConfirmDialog
        title="Setel ulang butir"
        confirmLabel="Setel ulang"
        busy={busy}
        error={closingError(error)}
        message={<p><strong>{item.title}</strong> kembali ke Menunggu. Penyelesaian atau pengecualian sebelumnya tetap tercatat di jejak audit, dan butir wajib yang menunggu menghalangi tutup periode.</p>}
        onConfirm={() => void send()}
        onClose={onClose}
      />
    )
  }

  const waive = action === 'waive'
  function submit() {
    const value = text.trim()
    if (waive && value.length < 5) return setLocalError('Alasan minimal 5 karakter.')
    setLocalError(undefined)
    void send(waive ? { reason: value } : value ? { notes: value } : {})
  }

  return (
    <FormModal title={waive ? 'Kecualikan butir' : 'Selesaikan butir'} submitLabel={waive ? 'Kecualikan' : 'Tandai selesai'} busy={busy} error={closingError(error)} onSubmit={submit} onClose={onClose}>
      <p><strong>{item.title}</strong> <span className="muted">({item.code})</span></p>
      {waive
        ? <p>Pengecualian berarti langkah ini tidak dikerjakan untuk periode ini. Alasan dicatat di jejak audit; butir wajib yang dikecualikan tidak lagi menghalangi tutup periode.</p>
        : <p>Tandai hanya bila langkah ini benar-benar sudah dikerjakan. Catatan bersifat opsional dan tersimpan bersama butir.</p>}
      <Field label={waive ? 'Alasan pengecualian' : 'Catatan (opsional)'} error={localError ?? fieldError(error, waive ? 'reason' : 'notes')} hint={waive ? 'Minimal 5 karakter, maksimal 500.' : 'Maksimal 1000 karakter.'} full>
        {(p) => <textarea className="textarea" maxLength={waive ? 500 : 1000} value={text} onChange={(e) => setText(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}
