import { useState } from 'react'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Field, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { useAccountingAccess, type AccountingProfile } from '../../lib/accounting'
import { frameworkLabels } from '../../lib/accountingLabels'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { useAction, useResource } from '../../lib/hooks'

type Payload = { data: AccountingProfile | null; frameworks: string[] }

export default function Profile() {
  const profile = useResource(async () => (await api.get<Payload>('/app/accounting/profile')).data, [])
  if (profile.loading && !profile.data) return <Loading />
  if (profile.error || !profile.data) return <ErrorNotice error={profile.error} onRetry={profile.reload} />
  return <ProfileForm key={profile.data.data?.id ?? 'new'} current={profile.data.data} frameworks={profile.data.frameworks} onSaved={profile.reload} />
}

function ProfileForm({ current, frameworks, onSaved }: { current: AccountingProfile | null; frameworks: string[]; onSaved: () => void }) {
  const toast = useToast()
  const { canChange } = useAccountingAccess()
  const { busy, error, run } = useAction()
  const editable = canChange('accounting.profile.manage')
  const locked = current?.status === 'LOCKED'
  const [f, setF] = useState({
    framework: current?.framework ?? 'SAK_EMKM',
    functional_currency: current?.functional_currency ?? 'IDR',
    currency_scale: String(current?.currency_scale ?? 2),
    approval_required: current?.approval_required ?? false,
    sod_creator_not_approver: current?.sod_creator_not_approver ?? true,
    sod_creator_not_poster: current?.sod_creator_not_poster ?? false,
    sod_approver_not_poster: current?.sod_approver_not_poster ?? false,
  })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))
  const flag = (k: 'approval_required' | 'sod_creator_not_approver' | 'sod_creator_not_poster' | 'sod_approver_not_poster') => (e: { target: { checked: boolean } }) => setF((s) => ({ ...s, [k]: e.target.checked }))

  async function save() {
    const body = { ...f, functional_currency: f.functional_currency.trim().toUpperCase(), currency_scale: Number(f.currency_scale) }
    const r = await run(() => api.put('/app/accounting/profile', body))
    if (r.ok) {
      toast.success('Profil akuntansi disimpan.')
      onSaved()
    }
  }

  return (
    <>
      <PageHeader title="Profil akuntansi" description="Kerangka akuntansi, mata uang fungsional, dan kebijakan persetujuan. Aturan integritas (jurnal seimbang, jurnal terposting tidak berubah) tidak dapat dimatikan." actions={current && <StatusBadge status={current.status} />} />
      {locked && <Banner tone="info">Jurnal pertama sudah diposting, sehingga mata uang fungsional dan presisi tidak dapat diubah lagi.</Banner>}
      <form onSubmit={(e) => { e.preventDefault(); void save() }} noValidate className="stack">
        {error != null && <ErrorNotice error={error} />}
        <Card title="Dasar pembukuan">
          <div className="form-grid">
            <Field label="Kerangka akuntansi" error={fieldError(error, 'framework')}>
              {(p) => (
                <select className="select" value={f.framework} onChange={set('framework')} disabled={!editable} {...p}>
                  {frameworks.map((k) => <option key={k} value={k}>{frameworkLabels[k] ?? k}</option>)}
                </select>
              )}
            </Field>
            <Field label="Mata uang fungsional" error={fieldError(error, 'functional_currency')} hint="Kode ISO tiga huruf, misalnya IDR.">
              {(p) => <input className="input mono" maxLength={3} value={f.functional_currency} onChange={set('functional_currency')} disabled={!editable || locked} {...p} />}
            </Field>
            <Field label="Presisi (angka desimal)" error={fieldError(error, 'currency_scale')} hint="Jumlah yang lebih presisi ditolak, bukan dibulatkan diam-diam.">
              {(p) => (
                <select className="select" value={f.currency_scale} onChange={set('currency_scale')} disabled={!editable || locked} {...p}>
                  {[0, 1, 2, 3, 4].map((n) => <option key={n} value={n}>{n}</option>)}
                </select>
              )}
            </Field>
          </div>
        </Card>

        <Card title="Persetujuan dan pemisahan tugas">
          <div className="stack" style={{ gap: 12 }}>
            <label className="check"><input type="checkbox" checked={f.approval_required} onChange={flag('approval_required')} disabled={!editable} /> Jurnal umum wajib disetujui sebelum diposting</label>
            <label className="check"><input type="checkbox" checked={f.sod_creator_not_approver} onChange={flag('sod_creator_not_approver')} disabled={!editable} /> Penyusun tidak boleh menyetujui jurnalnya sendiri</label>
            <label className="check"><input type="checkbox" checked={f.sod_creator_not_poster} onChange={flag('sod_creator_not_poster')} disabled={!editable} /> Penyusun tidak boleh memposting jurnalnya sendiri</label>
            <label className="check"><input type="checkbox" checked={f.sod_approver_not_poster} onChange={flag('sod_approver_not_poster')} disabled={!editable} /> Penyetuju tidak boleh memposting jurnal yang ia setujui</label>
            <p className="muted">Pemisahan tugas ditentukan oleh kebijakan ini, bukan oleh nama peran. Berlaku juga untuk saldo awal.</p>
          </div>
        </Card>

        {editable && <div className="actions form-actions"><Button type="submit" variant="primary" loading={busy}>{current ? 'Simpan perubahan' : 'Simpan profil'}</Button></div>}
      </form>
    </>
  )
}
