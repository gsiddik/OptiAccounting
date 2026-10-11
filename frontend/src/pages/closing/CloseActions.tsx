import { useState, type ReactNode } from 'react'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Field } from '../../components/ui'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction } from '../../lib/hooks'
import { RefusedChecks } from './components'
import { ENDPOINTS, type useClosingAccess } from './data'
import { closingError, targetLabels } from './labels'
import type { PeriodState, PreCloseResponse, Readiness } from './types'

type ActionKey = 'pre-close' | 'soft-close' | 'hard-close' | 'reopen'
type Access = ReturnType<typeof useClosingAccess>
type Subject = { id: string; code: string; status: PeriodState }

const COPY: Record<ActionKey, { title: string; submit: string; intro: ReactNode }> = {
  'pre-close': {
    title: 'Pra-tutup periode',
    submit: 'Catat pra-tutup',
    intro: 'Server menghitung kesiapan sekarang dan mencatat hasilnya sebagai bukti di riwayat penutupan. Status periode tidak berubah dan pra-tutup dapat diulang.',
  },
  'soft-close': {
    title: 'Tutup sementara periode',
    submit: 'Tutup sementara',
    intro: 'Periode berhenti menerima posting biasa; hanya pengguna dengan izin khusus yang masih dapat memposting, dan setiap posting seperti itu diaudit. Tutup sementara tidak menunggu kesiapan penuh dan dapat dibuka kembali.',
  },
  'hard-close': {
    title: 'Tutup periode',
    submit: 'Tutup periode',
    intro: 'Periode tidak lagi menerima posting apa pun. Server menghitung ulang kesiapan di dalam transaksi penutupan dan menolak bila ada pemeriksaan penghalang yang gagal. Setelah ditutup, periode hanya dapat dibuka kembali dengan alasan tercatat.',
  },
  reopen: {
    title: 'Buka kembali periode',
    submit: 'Buka kembali',
    intro: 'Periode dapat menerima posting lagi. Alasan dicatat permanen di riwayat penutupan dan audit. Server memeriksa kebijakan: periode lebih baru yang tertutup, batas mundur, dan pemisahan penutup dengan pembuka.',
  },
}

function successText(action: ActionKey, code: string, response: unknown): string {
  if (action === 'pre-close') {
    const r = response as PreCloseResponse
    return r.result === 'READY' ? `Pra-tutup ${code} dicatat: siap ditutup.` : `Pra-tutup ${code} dicatat: belum siap, ${r.blocking_count} penghalang.`
  }
  return { 'soft-close': `Periode ${code} ditutup sementara.`, 'hard-close': `Periode ${code} ditutup.`, reopen: `Periode ${code} dibuka kembali.` }[action]
}

function ActionDialog({ action, period, readiness, onDone, onClose }: { action: ActionKey; period: Subject; readiness: Readiness | null; onDone: () => void; onClose: () => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [reason, setReason] = useState('')
  const [target, setTarget] = useState(period.status === 'CLOSED' ? 'SOFT_CLOSED' : 'OPEN')
  const [localError, setLocalError] = useState<string | undefined>()
  const copy = COPY[action]
  const reopen = action === 'reopen'

  async function submit() {
    const text = reason.trim()
    if (reopen && text.length < 10) return setLocalError('Alasan minimal 10 karakter.')
    setLocalError(undefined)
    const body = reopen ? { target, reason: text } : action === 'pre-close' || !text ? {} : { reason: text }
    const result = await run(async () => (await api.post(ENDPOINTS.action(period.id, action), body)).data)
    if (result.ok) {
      toast.success(successText(action, period.code, result.value))
      onDone()
      onClose()
    }
  }

  return (
    <FormModal title={`${copy.title} ${period.code}`} submitLabel={copy.submit} busy={busy} error={closingError(error)} danger={action !== 'pre-close'} onSubmit={() => void submit()} onClose={onClose}>
      <RefusedChecks error={error} />
      <p>{copy.intro}</p>
      {action === 'hard-close' && readiness?.result === 'NOT_READY' && (
        <Banner tone="warn">Hasil kesiapan terakhir di layar: belum siap ({readiness.blocking_count} penghalang). Anda tetap dapat mencoba; keputusan ada di server.</Banner>
      )}
      {reopen && (
        <Field label="Buka kembali ke status">
          {(p) => (
            <select className="select" value={target} onChange={(e) => setTarget(e.target.value)} {...p}>
              {period.status === 'CLOSED' && <option value="SOFT_CLOSED">{targetLabels.SOFT_CLOSED} (langkah mundur paling hati-hati)</option>}
              <option value="OPEN">{targetLabels.OPEN}</option>
            </select>
          )}
        </Field>
      )}
      {action !== 'pre-close' && (
        <Field label={reopen ? 'Alasan (wajib, dicatat permanen)' : 'Alasan (opsional)'} error={localError ?? fieldError(error, 'reason')} hint={reopen ? 'Minimal 10 karakter, maksimal 500.' : 'Maksimal 500 karakter.'} full>
          {(p) => <textarea className="textarea" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} {...p} />}
        </Field>
      )}
    </FormModal>
  )
}

/** The closing actions of one period, each behind a confirmation. A button shows only for a permission the user holds on a writable module and a state it applies to; the server decides again. */
export function CloseActions({ period, readiness, access, onDone }: { period: Subject; readiness: Readiness | null; access: Access; onDone: () => void }) {
  const [open, setOpen] = useState<ActionKey | null>(null)
  const state = period.status
  const live = state === 'OPEN' || state === 'SOFT_CLOSED'
  return (
    <>
      {access.canPreClose && live && <Button onClick={() => setOpen('pre-close')}>Pra-tutup</Button>}
      {access.canSoftClose && state === 'OPEN' && <Button onClick={() => setOpen('soft-close')}>Tutup sementara</Button>}
      {access.canHardClose && live && <Button variant="primary" onClick={() => setOpen('hard-close')}>Tutup periode</Button>}
      {access.canReopen && (state === 'SOFT_CLOSED' || state === 'CLOSED') && <Button variant="danger" onClick={() => setOpen('reopen')}>Buka kembali</Button>}
      {open && <ActionDialog action={open} period={period} readiness={readiness} onDone={onDone} onClose={() => setOpen(null)} />}
    </>
  )
}
