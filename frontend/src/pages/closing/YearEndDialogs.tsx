import { useState } from 'react'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction } from '../../lib/hooks'
import { Money } from '../operational/shared'
import { RefusedBlockers } from './components'
import { ENDPOINTS } from './data'
import { closingError, resultText } from './labels'
import type { YearEndPreview, YearEndRun } from './types'

type Common = { year: { id: string; code: string }; onDone: () => void; onClose: () => void }

/** Run the year-end closing after a confirmation. The reason is optional; a refusal shows the first blocker and the full list the server reports. */
export function RunDialog({ year, preview, onDone, onClose }: Common & { preview: YearEndPreview }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [reason, setReason] = useState('')
  const retained = preview.retained_earnings_account

  async function submit() {
    const text = reason.trim()
    const result = await run(async () => (await api.post<YearEndRun>(ENDPOINTS.run(year.id), text ? { reason: text } : {})).data)
    if (result.ok) {
      toast.success(`Tutup tahun ${year.code} dijalankan${result.value.journal_number ? `: jurnal ${result.value.journal_number}` : ''}.`)
      onDone()
      onClose()
    }
  }

  return (
    <FormModal title={`Jalankan tutup tahun ${year.code}`} submitLabel="Jalankan tutup tahun" danger busy={busy} error={closingError(error)} onSubmit={() => void submit()} onClose={onClose}>
      <RefusedBlockers error={error} />
      <p>
        Server akan memposting satu jurnal sistem bertanggal {preview.closing_date} yang menutup {preview.accounts.length} baris saldo akun pendapatan dan beban
        {retained ? <> ke akun laba ditahan <strong>{retained.code} · {retained.name}</strong></> : null}.
        Hasil bersih saat ini <Money value={preview.totals.net_result} strong /> ({resultText(preview.totals.result)[0]}).
      </p>
      <p>Jurnal yang sudah diposting tidak dapat diubah atau dihapus; koreksinya adalah membalik tutup tahun dengan alasan tercatat. Angka dihitung ulang server di dalam transaksi, jadi dapat berbeda dari pratinjau bila ada posting baru.</p>
      <Field label="Alasan atau catatan (opsional)" hint="Maksimal 500 karakter." error={fieldError(error, 'reason')} full>
        {(p) => <textarea className="textarea" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}

/** Reverse the posted year-end closing. The reason is mandatory (at least 10 characters). */
export function ReverseDialog({ year, onDone, onClose }: Common) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [reason, setReason] = useState('')
  const [localError, setLocalError] = useState<string | undefined>()

  async function submit() {
    const text = reason.trim()
    if (text.length < 10) return setLocalError('Alasan minimal 10 karakter.')
    setLocalError(undefined)
    const result = await run(async () => (await api.post<YearEndRun>(ENDPOINTS.reverse(year.id), { reason: text })).data)
    if (result.ok) {
      toast.success(`Tutup tahun ${year.code} dibalik${result.value.reversal_journal_number ? `: jurnal pembalik ${result.value.reversal_journal_number}` : ''}.`)
      onDone()
      onClose()
    }
  }

  return (
    <FormModal title={`Balik tutup tahun ${year.code}`} submitLabel="Balik tutup tahun" danger busy={busy} error={closingError(error)} onSubmit={() => void submit()} onClose={onClose}>
      <RefusedBlockers error={error} />
      <p>Server memposting jurnal pembalik atas jurnal penutupan, menandai prosesnya Dibalik, lalu tutup tahun dapat dijalankan lagi sebagai proses berikutnya. Tidak ada yang dihapus.</p>
      <p>Bila periode terakhir atau tahun fiskal sudah ditutup, pembalikan juga membuka kembali periode itu di bawah aturan dan kebijakan buka kembali, dan memerlukan izin membuka kembali periode. Pembalikan ditolak bila tahun fiskal yang lebih baru sudah ditutup.</p>
      <Field label="Alasan (wajib, dicatat permanen)" hint="Minimal 10 karakter, maksimal 500." error={localError ?? fieldError(error, 'reason')} full>
        {(p) => <textarea className="textarea" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}
