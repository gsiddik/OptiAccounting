import { useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, EmptyState, Field, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { todayIn } from '../../lib/format'
import { useDebounced, useResource } from '../../lib/hooks'
import { API } from '../../lib/operational'
import { disposalTypeLabels } from '../../lib/oa4Labels'
import { useAccounts } from '../accounting/data'
import { errorText, fieldMessage, useAct } from '../operational/payables/messages'
import { ErrorNotice, Money } from '../operational/shared'
import { PATHS, accountOptions, useAssetById, useDisposableAssets } from './data'
import { buildDisposalBody, disposalFormFrom, emptyDisposalForm, type DisposalForm } from './payload'
import { DISPOSABLE_STATUSES, DISPOSAL_TYPES, type Asset, type Disposal } from './types'

export default function AssetDisposalEditor() {
  const { id } = useParams()
  const [search] = useSearchParams()
  const disposal = useResource(async () => (id ? (await api.get<Disposal>(`${API}/asset-disposals/${id}`)).data : null), [id])

  if (disposal.loading && !disposal.data && id) return <Loading />
  if (disposal.error) return <ErrorNotice error={disposal.error} onRetry={disposal.reload} />
  if (disposal.data && disposal.data.status !== 'DRAFT') {
    return <EmptyState title="Pelepasan tidak dapat diubah">Hanya pelepasan berstatus draf yang dapat diubah. Buka dokumennya untuk melihat riwayat; pelepasan yang ditolak dijadikan draf lebih dulu.</EmptyState>
  }
  return <Form key={id ?? 'new'} disposal={disposal.data} presetAssetId={search.get('aset')} />
}

function Form({ disposal, presetAssetId }: { disposal: Disposal | null; presetAssetId: string | null }) {
  const navigate = useNavigate()
  const toast = useToast()
  const { tenant } = useCapabilities()
  const { busy, error, run, clearError } = useAct()
  const today = tenant?.business_date ?? todayIn()
  const [find, setFind] = useState('')
  const q = useDebounced(find)
  const disposable = useDisposableAssets(q)
  const preset = useAssetById(disposal ? null : presetAssetId)
  const accounts = useAccounts()

  const [f, setF] = useState<DisposalForm>(() => (disposal ? disposalFormFrom(disposal) : emptyDisposalForm(today, presetAssetId ?? '')))
  const [hint, setHint] = useState<string | null>(null)
  const set = (k: keyof DisposalForm) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  // The asset of a saved draft and the asset the user came from stay selectable even when the search no longer lists them.
  const known = new Map<string, Asset>()
  for (const a of [...(preset.asset && DISPOSABLE_STATUSES.includes(preset.asset.status) ? [preset.asset] : []), ...disposable.assets]) known.set(a.id, a)
  const options = [...known.values()]
  const stored = disposal?.asset && !known.has(disposal.asset.id) ? disposal.asset : null
  const chosen = known.get(f.fixed_asset_id) ?? (disposal?.asset?.id === f.fixed_asset_id ? disposal.asset : undefined)
  const presetBlocked = !disposal && preset.asset && !DISPOSABLE_STATUSES.includes(preset.asset.status)
  const proceedsOptions = accountOptions(accounts.accounts, ['ASSET'], [disposal?.proceeds_account])
  const sale = f.disposal_type === 'SALE'
  const preview = disposal?.preview

  function changeDisposalDate(value: string) {
    // The document and posting dates follow the disposal date until the user sets them apart.
    setF((s) => ({ ...s, disposal_date: value, document_date: s.document_date === s.disposal_date ? value : s.document_date, posting_date: s.posting_date === s.disposal_date ? value : s.posting_date }))
  }

  async function save() {
    const built = buildDisposalBody(f)
    if ('problem' in built) return setHint(built.problem)
    setHint(null)
    clearError()
    const saved = await run(async () => (await (disposal ? api.patch<Disposal>(`${API}/asset-disposals/${disposal.id}`, built.body) : api.post<Disposal>(`${API}/asset-disposals`, built.body))).data)
    if (saved.ok) {
      toast.success('Draf pelepasan disimpan.')
      navigate(`${PATHS.disposals}/${saved.value.id}`)
    }
  }

  return (
    <>
      <PageHeader
        title={disposal ? `Ubah draf pelepasan ${disposal.asset?.asset_number ?? ''}`.trim() : 'Pelepasan aset baru'}
        description="Pilih aset yang masih tercatat di buku. Nilai buku, laba, dan rugi dihitung server dari register; tidak ada angka yang dihitung di layar ini."
      />
      <form onSubmit={(e) => { e.preventDefault(); void save() }} noValidate className="stack">
        {error != null && <Banner tone="bad">{errorText(error)}</Banner>}
        {hint && <Banner tone="warn">{hint}</Banner>}
        {presetBlocked && <Banner tone="warn">Aset {preset.asset?.asset_number ?? preset.asset?.name} berstatus {preset.asset?.status} dan tidak dapat dilepas. Pilih aset lain.</Banner>}

        <Card title="Aset">
          <div className="form-grid">
            {!disposal && (
              <Field label="Cari aset" hint="Nomor atau nama aset aktif atau yang sudah susut penuh.">
                {(p) => <input className="input" type="search" autoComplete="off" value={find} onChange={(e) => setFind(e.target.value)} {...p} />}
              </Field>
            )}
            <Field label="Aset" error={fieldMessage(error, 'fixed_asset_id')} hint={disposal ? 'Aset pada draf pelepasan tidak dapat diganti. Buat pelepasan baru untuk aset lain.' : disposable.error ? errorText(disposable.error) : 'Hanya aset aktif atau susut penuh yang dapat dilepas.'}>
              {(p) => (
                <select className="select" required disabled={!!disposal} value={f.fixed_asset_id} onChange={set('fixed_asset_id')} {...p}>
                  <option value="">Pilih aset…</option>
                  {stored && <option value={stored.id}>{stored.asset_number ?? 'Draf'} · {stored.name}</option>}
                  {options.map((a) => <option key={a.id} value={a.id}>{a.asset_number ?? 'Draf'} · {a.name}</option>)}
                </select>
              )}
            </Field>
          </div>
          {chosen && (
            <dl className="facts" aria-label="Posisi register aset">
              <div><dt>Harga perolehan</dt><dd><Money value={chosen.acquisition_cost} /></dd></div>
              <div><dt>Akumulasi penyusutan saat ini</dt><dd><Money value={chosen.accumulated_depreciation} /></dd></div>
            </dl>
          )}
        </Card>

        <Card title="Pelepasan">
          <div className="form-grid">
            <fieldset className="field full">
              <legend className="label">Jenis pelepasan</legend>
              {DISPOSAL_TYPES.map((t) => (
                <label className="check" key={t}>
                  <input type="radio" name="disposal_type" checked={f.disposal_type === t} onChange={() => setF((s) => ({ ...s, disposal_type: t }))} /> {disposalTypeLabels[t]}
                </label>
              ))}
              <span className="hint">{sale ? 'Penjualan membutuhkan hasil penjualan dan akun penerimaannya.' : 'Penghapusan (dibuang atau di-write-off) tidak menerima hasil; seluruh nilai buku menjadi rugi.'}</span>
            </fieldset>
            <Field label="Tanggal pelepasan" error={fieldMessage(error, 'disposal_date')} hint="Penyusutan harus sudah diposting sampai tanggal ini.">
              {(p) => <input className="input" type="date" required value={f.disposal_date} onChange={(e) => changeDisposalDate(e.target.value)} {...p} />}
            </Field>
            <Field label="Tanggal dokumen" error={fieldMessage(error, 'document_date')}>
              {(p) => <input className="input" type="date" value={f.document_date} onChange={set('document_date')} {...p} />}
            </Field>
            <Field label="Tanggal posting" error={fieldMessage(error, 'posting_date')} hint="Menentukan periode akuntansi; tidak boleh sebelum tanggal pelepasan.">
              {(p) => <input className="input" type="date" value={f.posting_date} onChange={set('posting_date')} {...p} />}
            </Field>
            <Field label="Referensi" error={fieldMessage(error, 'reference')} hint="Nomor dokumen pendukung, opsional.">
              {(p) => <input className="input" maxLength={100} value={f.reference} onChange={set('reference')} {...p} />}
            </Field>
            {sale && (
              <>
                <Field label="Hasil penjualan" error={fieldMessage(error, 'proceeds_amount')} hint="Contoh 5000000 atau 5000000,50.">
                  {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={f.proceeds_amount} onChange={set('proceeds_amount')} {...p} />}
                </Field>
                <Field label="Akun penerimaan" error={fieldMessage(error, 'proceeds_account_id')} hint={accounts.error ? 'Daftar akun tidak dapat dimuat (butuh izin melihat bagan akun).' : 'Akun aset yang aktif dan bukan akun kontrol, mis. kas atau bank.'}>
                  {(p) => (
                    <select className="select" value={f.proceeds_account_id} onChange={set('proceeds_account_id')} {...p}>
                      <option value="">Pilih akun…</option>
                      {proceedsOptions.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                    </select>
                  )}
                </Field>
              </>
            )}
            <Field label="Alasan" error={fieldMessage(error, 'reason')} full>
              {(p) => <textarea className="textarea" required maxLength={500} value={f.reason} onChange={set('reason')} {...p} />}
            </Field>
          </div>
        </Card>

        <Card title="Perhitungan server">
          {preview ? (
            <>
              <dl className="facts">
                <div><dt>Harga perolehan</dt><dd><Money value={preview.cost} /></dd></div>
                <div><dt>Akumulasi penyusutan</dt><dd><Money value={preview.accumulated} /></dd></div>
                <div><dt>Nilai buku</dt><dd><Money value={preview.book_value} strong /></dd></div>
                <div><dt>Hasil</dt><dd><Money value={preview.proceeds} /></dd></div>
                <div><dt>Laba</dt><dd><Money value={preview.gain} /></dd></div>
                <div><dt>Rugi</dt><dd><Money value={preview.loss} /></dd></div>
              </dl>
              <p className="muted preview-note">Dihitung server dari draf yang tersimpan dan register saat ini. Simpan perubahan untuk menghitung ulang; angka final ditetapkan saat diposting.</p>
            </>
          ) : (
            <p className="muted">Nilai buku, laba, dan rugi dihitung server setelah draf disimpan, dan ditetapkan saat pelepasan diposting.</p>
          )}
        </Card>

        <div className="actions form-actions">
          <Button onClick={() => navigate(disposal ? `${PATHS.disposals}/${disposal.id}` : PATHS.disposals)}>Batal</Button>
          <Button type="submit" variant="primary" loading={busy}>Simpan draf</Button>
        </div>
      </form>
    </>
  )
}
