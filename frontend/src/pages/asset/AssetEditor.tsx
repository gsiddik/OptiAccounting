import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, EmptyState, Field, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { todayIn } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API } from '../../lib/operational'
import { assetMethodLabels, capitalizationModeLabels, startPolicyLabels } from '../../lib/oa4Labels'
import { useAccounts, useDimensions } from '../accounting/data'
import { errorText, fieldMessage, useAct } from '../operational/payables/messages'
import { DimensionFields, ErrorNotice } from '../operational/shared'
import { ApLinePicker } from './ApLinePicker'
import { PATHS, accountOptions, useAssetCategories } from './data'
import { methodText, residualPolicyText } from './labels'
import { assetFormFrom, buildAssetBody, effectiveMethod, emptyAssetForm, type AssetForm } from './payload'
import { ASSET_METHODS, START_POLICIES, type Asset } from './types'

export default function AssetEditor() {
  const { id } = useParams()
  const asset = useResource(async () => (id ? (await api.get<Asset>(`${API}/assets/${id}`)).data : null), [id])

  if (asset.loading && !asset.data && id) return <Loading />
  if (asset.error) return <ErrorNotice error={asset.error} onRetry={asset.reload} />
  if (asset.data && asset.data.status !== 'DRAFT') {
    return <EmptyState title="Aset tidak dapat diubah">Hanya aset berstatus draf yang dapat diubah. Ketentuan keuangan aset yang sudah dikapitalisasi dibekukan; koreksinya lewat pembalikan kapitalisasi.</EmptyState>
  }
  return <Form key={id ?? 'new'} asset={asset.data} />
}

function Form({ asset }: { asset: Asset | null }) {
  const navigate = useNavigate()
  const toast = useToast()
  const { tenant } = useCapabilities()
  const { busy, error, run, clearError } = useAct()
  const { categories } = useAssetCategories()
  const accounts = useAccounts()
  const { catalog } = useDimensions()
  const today = tenant?.business_date ?? todayIn()

  const [f, setF] = useState<AssetForm>(() => (asset ? assetFormFrom(asset) : emptyAssetForm(today)))
  const [hint, setHint] = useState<string | null>(null)
  const set = (k: keyof AssetForm) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  // The draft's own category stays selectable even if it was deactivated after the draft was saved.
  const categoryOptions = categories.filter((c) => c.status === 'ACTIVE' || c.id === f.asset_category_id)
  const category = categories.find((c) => c.id === f.asset_category_id)
  const method = effectiveMethod(f, category)
  const sourceOptions = accountOptions(accounts.accounts, ['ASSET', 'LIABILITY', 'EQUITY'], [asset?.source_account])
  const registerOnly = f.capitalization_mode === 'REGISTER_ONLY'

  async function save() {
    const built = buildAssetBody(f, category, asset !== null)
    if ('problem' in built) return setHint(built.problem)
    setHint(null)
    clearError()
    const saved = await run(async () => (await (asset ? api.patch<Asset>(`${API}/assets/${asset.id}`, built.body) : api.post<Asset>(`${API}/assets`, built.body))).data)
    if (saved.ok) {
      toast.success('Draf aset disimpan.')
      navigate(`${PATHS.assets}/${saved.value.id}`)
    }
  }

  return (
    <>
      <PageHeader
        title={asset ? `Ubah draf aset ${asset.name}` : 'Aset baru'}
        description="Aset berstatus draf belum masuk buku besar. Jadwal penyusutan dan jurnal kapitalisasi dibuat server saat aset dikapitalisasi, dari ketentuan yang tersimpan di sini."
      />
      <form onSubmit={(e) => { e.preventDefault(); void save() }} noValidate className="stack">
        {error != null && <Banner tone="bad">{errorText(error)}</Banner>}
        {hint && <Banner tone="warn">{hint}</Banner>}

        <Card title="Identitas aset">
          <div className="form-grid">
            <Field label="Kategori" error={fieldMessage(error, 'asset_category_id')} hint={category ? `Default kategori: ${categoryDefaults(category.default_method, category.default_useful_life_months)}.` : undefined}>
              {(p) => (
                <select className="select" required value={f.asset_category_id} onChange={set('asset_category_id')} {...p}>
                  <option value="">Pilih kategori…</option>
                  {categoryOptions.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                </select>
              )}
            </Field>
            <Field label="Nama aset" error={fieldMessage(error, 'name')}>{(p) => <input className="input" required maxLength={200} value={f.name} onChange={set('name')} {...p} />}</Field>
            <Field label="Deskripsi" error={fieldMessage(error, 'description')} full>{(p) => <input className="input" maxLength={500} value={f.description} onChange={set('description')} {...p} />}</Field>
            <Field label="Referensi sumber" error={fieldMessage(error, 'source_reference')} hint="Nomor dokumen pembelian atau bukti lain, opsional.">
              {(p) => <input className="input" maxLength={100} value={f.source_reference} onChange={set('source_reference')} {...p} />}
            </Field>
          </div>
        </Card>

        <Card title="Perolehan">
          <div className="form-grid">
            <Field label="Tanggal perolehan" error={fieldMessage(error, 'acquisition_date')}>
              {(p) => <input className="input" type="date" required value={f.acquisition_date} onChange={set('acquisition_date')} {...p} />}
            </Field>
            <Field label="Tanggal kapitalisasi" error={fieldMessage(error, 'capitalization_date')} hint="Tanggal posting jurnal kapitalisasi dan acuan awal penyusutan. Kosong berarti sama dengan tanggal perolehan.">
              {(p) => <input className="input" type="date" value={f.capitalization_date} onChange={set('capitalization_date')} {...p} />}
            </Field>
            <Field label="Harga perolehan" error={fieldMessage(error, 'acquisition_cost')} hint="Contoh 15000000 atau 15000000,50.">
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={f.acquisition_cost} onChange={set('acquisition_cost')} {...p} />}
            </Field>
            <Field label="Nilai sisa" error={fieldMessage(error, 'residual_value')} hint={asset ? 'Kosong berarti nol.' : category ? `Kosong berarti mengikuti kategori (${residualPolicyText(category)}).` : 'Kosong berarti mengikuti kebijakan nilai sisa kategori.'}>
              {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={f.residual_value} onChange={set('residual_value')} {...p} />}
            </Field>
          </div>
        </Card>

        <Card title="Penyusutan">
          <div className="form-grid">
            <Field label="Metode penyusutan" error={fieldMessage(error, 'method')}>
              {(p) => (
                <select className="select" value={f.method} onChange={set('method')} {...p}>
                  <option value="">{category ? `Ikuti kategori (${methodText(category.default_method)})` : 'Ikuti kategori'}</option>
                  {ASSET_METHODS.map((m) => <option key={m} value={m}>{assetMethodLabels[m]}</option>)}
                </select>
              )}
            </Field>
            <Field label="Umur manfaat (bulan)" error={fieldMessage(error, 'useful_life_months')} hint={method === 'NONE' ? 'Aset yang tidak disusutkan tidak memakai umur manfaat.' : category?.default_useful_life_months ? `Kosong berarti ${category.default_useful_life_months} bulan (default kategori).` : 'Antara 1 dan 1200 bulan.'}>
              {(p) => <input className="input" inputMode="numeric" autoComplete="off" disabled={method === 'NONE'} value={method === 'NONE' ? '' : f.useful_life_months} onChange={set('useful_life_months')} {...p} />}
            </Field>
            <Field label="Mulai disusutkan" error={fieldMessage(error, 'start_policy')}>
              {(p) => (
                <select className="select" value={f.start_policy} onChange={set('start_policy')} {...p}>
                  <option value="">{category ? `Ikuti kategori (${startPolicyLabels[category.default_start_policy]})` : 'Ikuti kategori'}</option>
                  {START_POLICIES.map((s) => <option key={s} value={s}>{startPolicyLabels[s]}</option>)}
                </select>
              )}
            </Field>
            {method === 'DECLINING_BALANCE' && (
              <Field label="Faktor saldo menurun" error={fieldMessage(error, 'method_params')} hint="Antara 1 dan 4, paling banyak dua desimal. Kosong berarti 2.">
                {(p) => <input className="input" inputMode="decimal" autoComplete="off" placeholder="2" value={f.factor} onChange={set('factor')} {...p} />}
              </Field>
            )}
          </div>
          <p className="muted preview-note">Jadwal bulanan dihitung server. Setelah draf disimpan, halaman aset menampilkan pratinjau jadwalnya sebelum dikapitalisasi.</p>
        </Card>

        <Card title="Kapitalisasi">
          <div className="form-grid">
            <fieldset className="field full">
              <legend className="label">Cara kapitalisasi</legend>
              {(['POST', 'REGISTER_ONLY'] as const).map((m) => (
                <label className="check" key={m}>
                  <input type="radio" name="capitalization_mode" checked={f.capitalization_mode === m} onChange={() => setF((s) => ({ ...s, capitalization_mode: m }))} /> {capitalizationModeLabels[m]}
                </label>
              ))}
              <span className="hint">
                {registerOnly
                  ? 'Tidak ada jurnal kedua: biaya sudah masuk buku besar lewat baris faktur vendor yang diposting, sehingga tidak tercatat dua kali.'
                  : 'Saat dikapitalisasi: Debit akun aset tetap, Kredit akun sumber di bawah ini (utang, bank, atau akun penampung).'}
              </span>
            </fieldset>
            {registerOnly ? (
              <ApLinePicker value={f.ap_invoice_line_id} onChange={(id) => setF((s) => ({ ...s, ap_invoice_line_id: id }))} error={fieldMessage(error, 'ap_invoice_line_id')} />
            ) : (
              <Field label="Akun sumber" error={fieldMessage(error, 'source_account_id')} hint={accounts.error ? 'Daftar akun tidak dapat dimuat (butuh izin melihat bagan akun).' : 'Wajib diisi sebelum kapitalisasi; boleh dilengkapi nanti. Akun aset, kewajiban, atau ekuitas yang aktif dan bukan akun kontrol.'} full>
                {(p) => (
                  <select className="select" value={f.source_account_id} onChange={set('source_account_id')} {...p}>
                    <option value="">Belum dipilih</option>
                    {sourceOptions.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                  </select>
                )}
              </Field>
            )}
          </div>
        </Card>

        {(catalog.branches.length > 0 || catalog.business_units.length > 0 || catalog.cost_centers.length > 0) && (
          <Card title="Dimensi">
            <div className="form-grid">
              <DimensionFields catalog={catalog} value={f} onChange={(patch) => setF((s) => ({ ...s, ...patch }))} error={error} />
            </div>
          </Card>
        )}

        <div className="actions form-actions">
          <Button onClick={() => navigate(asset ? `${PATHS.assets}/${asset.id}` : PATHS.assets)}>Batal</Button>
          <Button type="submit" variant="primary" loading={busy}>Simpan draf</Button>
        </div>
      </form>
    </>
  )
}

const categoryDefaults = (method: string, life: number | null) => `${methodText(method)}${life ? `, ${life} bulan` : ''}`
