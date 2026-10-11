import { useState } from 'react'
import { FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Field } from '../../components/ui'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction } from '../../lib/hooks'
import { ENDPOINTS } from './data'
import { closingError } from './labels'
import type { Template } from './types'

/** Create (code editable) or edit (code fixed: it is the item's identity in every period) a checklist template. */
export function TemplateDialog({ template, onDone, onClose }: { template: Template | null; onDone: () => void; onClose: () => void }) {
  const toast = useToast()
  const { busy, error, run } = useAction()
  const [f, setF] = useState({
    code: template?.code ?? '', title: template?.title ?? '', description: template?.description ?? '', required: template?.required ?? true,
    sort_order: String(template?.sort_order ?? 0), status: template?.status ?? 'ACTIVE',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const set = (over: Partial<typeof f>) => setF((s) => ({ ...s, ...over }))

  async function submit() {
    const found: Record<string, string> = {}
    if (!template && !/^[A-Za-z0-9_]{1,40}$/.test(f.code.trim())) found.code = 'Kode hanya huruf, angka dan garis bawah (maksimal 40).'
    if (!f.title.trim()) found.title = 'Judul wajib diisi.'
    if (!/^\d{1,5}$/.test(f.sort_order) || Number(f.sort_order) > 65535) found.sort_order = 'Urutan adalah bilangan bulat 0 sampai 65535.'
    setErrors(found)
    if (Object.keys(found).length > 0) return

    const body = { title: f.title.trim(), description: f.description.trim() || null, required: f.required, sort_order: Number(f.sort_order), status: f.status }
    const result = await run(async () => (template ? await api.patch(ENDPOINTS.template(template.id), body) : await api.post(ENDPOINTS.templates, { code: f.code.trim(), ...body })).data)
    if (result.ok) {
      toast.success(template ? 'Butir daftar periksa diperbarui.' : 'Butir daftar periksa ditambahkan.')
      onDone()
      onClose()
    }
  }

  const fe = (name: string) => errors[name] ?? fieldError(error, name)
  return (
    <FormModal title={template ? `Ubah butir ${template.code}` : 'Tambah butir daftar periksa'} busy={busy} error={closingError(error)} onSubmit={() => void submit()} onClose={onClose}>
      <p>Butir aktif muncul di daftar periksa setiap periode. Butir yang sudah dipakai sebuah periode menyimpan salinan judul dan sifat wajibnya, jadi mengubahnya di sini tidak menulis ulang periode yang sudah berjalan.</p>
      <div className="form-grid">
        <Field label="Kode" error={fe('code')} hint={template ? 'Kode tidak dapat diubah.' : 'Huruf besar otomatis; tidak boleh sama dengan kode pemeriksaan sistem.'}>
          {(p) => <input className="input" maxLength={40} value={f.code} disabled={template !== null} onChange={(e) => set({ code: e.target.value })} {...p} />}
        </Field>
        <Field label="Urutan" error={fe('sort_order')}>
          {(p) => <input className="input" inputMode="numeric" value={f.sort_order} onChange={(e) => set({ sort_order: e.target.value })} {...p} />}
        </Field>
        <Field label="Judul" error={fe('title')} full>
          {(p) => <input className="input" maxLength={200} value={f.title} onChange={(e) => set({ title: e.target.value })} {...p} />}
        </Field>
        <Field label="Deskripsi" error={fe('description')} full>
          {(p) => <textarea className="textarea" maxLength={500} value={f.description} onChange={(e) => set({ description: e.target.value })} {...p} />}
        </Field>
        <Field label="Status">
          {(p) => (
            <select className="select" value={f.status} onChange={(e) => set({ status: e.target.value as Template['status'] })} {...p}>
              <option value="ACTIVE">Aktif</option>
              <option value="INACTIVE">Nonaktif</option>
            </select>
          )}
        </Field>
        <label className="check"><input type="checkbox" checked={f.required} onChange={(e) => set({ required: e.target.checked })} /> Wajib (menghalangi tutup periode selama menunggu)</label>
      </div>
    </FormModal>
  )
}
