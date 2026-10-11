import { useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Badge, Banner, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { formatAmount, normalizeAmountInput } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { formatDate, todayIn } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { ReadOnlyNotice } from '../operational/shared'
import { taxError } from './errors'
import { methodShort, taxMethodLabels, taxTreatmentLabels, taxTypeLabels } from './labels'
import { DestinationText, RecoverableText } from './parts'
import { TaxCodeDeleteDialog, TaxCodeForm, TaxCodeStatusDialog, TaxRateForm } from './TaxDialogs'
import type { TaxCode, TaxPreview, TaxRate } from './types'

export default function TaxCodeDetail() {
  const { id } = useParams()
  const code = useResource(async () => (await api.get<TaxCode>(`${API}/tax-codes/${id}`)).data, [id])

  if (code.loading && !code.data) return <Loading />
  if (code.error || !code.data) return <ErrorNotice error={code.error} onRetry={code.reload} />
  return <TaxCodeView code={code.data} reload={code.reload} />
}

type Dialog = null | 'edit' | 'rate' | 'status' | 'delete'

function TaxCodeView({ code, reload }: { code: TaxCode; reload: () => void }) {
  const navigate = useNavigate()
  const toast = useToast()
  const { tenant } = useCapabilities()
  const access = useModuleAccess(MODULES.tax)
  const manage = access.canChange('accounting.tax.manage')
  const [dialog, setDialog] = useState<Dialog>(null)
  const today = tenant?.business_date ?? todayIn()
  const rates = code.rates ?? []

  const done = (message: string) => () => {
    setDialog(null)
    toast.success(message)
    reload()
  }

  return (
    <>
      <PageHeader
        title={code.code}
        description={<>{code.name} · <StatusBadge status={code.status} /></>}
        actions={
          manage && (
            <>
              <Button onClick={() => setDialog('edit')}>Ubah</Button>
              <Button variant="primary" onClick={() => setDialog('rate')}>Tarif baru</Button>
              <Button onClick={() => setDialog('status')}>{code.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
              {code.in_use === false && <Button variant="danger" onClick={() => setDialog('delete')}>Hapus</Button>}
            </>
          )
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      {code.status === 'INACTIVE' && <Banner tone="info">Kode pajak ini nonaktif: tidak dapat dipakai pada dokumen baru. Dokumen yang sudah memakainya dan laporan pajak tidak berubah.</Banner>}
      {code.tax_type === 'WITHHOLDING' && <Banner tone="info">Kode pemotongan/pemungutan dapat disiapkan di sini, tetapi belum dapat dipakai pada dokumen; hanya untuk laporan pajak.</Banner>}

      <Card>
        <dl className="facts">
          <div><dt>Jenis pajak</dt><dd>{taxTypeLabels[code.tax_type] ?? code.tax_type}</dd></div>
          <div><dt>Metode perhitungan</dt><dd>{taxMethodLabels[code.calculation_method] ?? code.calculation_method}</dd></div>
          <div><dt>Perlakuan</dt><dd>{taxTreatmentLabels[code.treatment] ?? code.treatment}</dd></div>
          <div><dt>Dapat dikreditkan</dt><dd><RecoverableText code={code} /></dd></div>
          <div><dt>Tarif saat ini</dt><dd>{code.current_rate != null ? `${formatAmount(code.current_rate)}%` : <span className="muted">Belum ada tarif berlaku</span>}</dd></div>
          <div><dt>Tujuan akun</dt><dd><DestinationText code={code} /></dd></div>
          <div><dt>Pemakaian</dt><dd>{code.in_use ? 'Sudah dipakai dokumen' : 'Belum dipakai'}</dd></div>
          <div className="wide"><dt>Keterangan</dt><dd>{code.description ?? '—'}</dd></div>
        </dl>
      </Card>

      <Card title="Riwayat tarif" flush>
        {rates.length === 0 ? (
          <EmptyState title="Belum ada tarif">{manage ? 'Tambahkan tarif agar kode ini dapat dihitung pada dokumen.' : 'Kode ini belum memiliki tarif.'}</EmptyState>
        ) : (
          <DataTable
            caption="Riwayat tarif"
            rows={rates}
            rowKey={(r) => r.id}
            columns={[
              { header: 'Berlaku dari', primary: true, cell: (r) => formatDate(r.effective_from) },
              { header: 'Sampai', cell: (r) => (r.effective_until ? formatDate(r.effective_until) : <span className="muted">Sampai ada tarif baru</span>) },
              { header: 'Tarif', align: 'right', cell: (r) => <span className="money">{formatAmount(r.rate)}%</span> },
              { header: 'Keterangan', cell: (r) => <RateBadge rate={r} today={today} /> },
            ]}
          />
        )}
      </Card>

      <PreviewPanel key={code.id} code={code} />

      {dialog === 'edit' && <TaxCodeForm code={code} inUse={code.in_use} onClose={() => setDialog(null)} onDone={done('Kode pajak disimpan.')} />}
      {dialog === 'rate' && <TaxRateForm code={code} onClose={() => setDialog(null)} onDone={done('Tarif baru disimpan.')} />}
      {dialog === 'status' && <TaxCodeStatusDialog code={code} onClose={() => setDialog(null)} onDone={done('Status kode pajak diperbarui.')} />}
      {dialog === 'delete' && (
        <TaxCodeDeleteDialog
          code={code}
          onClose={() => setDialog(null)}
          onDone={() => {
            toast.success('Kode pajak dihapus.')
            navigate('/app/akuntansi/kode-pajak')
          }}
        />
      )}
    </>
  )
}

function RateBadge({ rate, today }: { rate: TaxRate; today: string }) {
  if (rate.effective_from > today) return <Badge tone="info">Terjadwal</Badge>
  if (rate.effective_until === null || rate.effective_until >= today) return <Badge tone="ok">Berlaku</Badge>
  return <Badge>Riwayat</Badge>
}

/** Asks the server what a document line would calculate; shows its answer as it comes and saves nothing. */
function PreviewPanel({ code }: { code: TaxCode }) {
  const { tenant } = useCapabilities()
  const calc = useAction()
  const [f, setF] = useState({ amount: '', date: tenant?.business_date ?? todayIn() })
  const [problem, setProblem] = useState<string | null>(null)
  const [result, setResult] = useState<TaxPreview | null>(null)
  const error = taxError(calc.error)
  const change = (patch: Partial<typeof f>) => {
    setF((s) => ({ ...s, ...patch }))
    setResult(null)
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    setProblem(null)
    setResult(null)
    const amount = normalizeAmountInput(f.amount)
    if (amount === '') return setProblem('Masukkan jumlah lebih besar dari nol, paling banyak empat desimal.')
    if (!f.date) return setProblem('Pilih tanggal pajak.')
    const r = await calc.run(async () => (await api.post<TaxPreview>(`${API}/tax-codes/${code.id}/preview`, { amount, date: f.date })).data)
    if (r.ok) setResult(r.value)
  }

  return (
    <Card title="Pratinjau perhitungan">
      <form className="stack" onSubmit={(e) => void submit(e)} noValidate>
        <p className="muted">
          Menghitung dengan kalkulator pajak yang sama dengan dokumen, memakai tarif yang berlaku pada tanggal pajak. Jumlah dianggap {code.calculation_method === 'INCLUSIVE' ? 'sudah termasuk pajak (inklusif)' : 'belum termasuk pajak (eksklusif)'}. Tidak ada yang disimpan.
        </p>
        <div className="form-grid">
          <Field label="Jumlah" error={fieldError(error, 'amount') ?? problem ?? undefined} hint="Contoh: 1000000 atau 1500000,50.">
            {(p) => <input className="input amount" inputMode="decimal" value={f.amount} onChange={(e) => change({ amount: e.target.value })} {...p} />}
          </Field>
          <Field label="Tanggal pajak" error={fieldError(error, 'date')}>
            {(p) => <input className="input" type="date" value={f.date} onChange={(e) => change({ date: e.target.value })} {...p} />}
          </Field>
        </div>
        <div className="actions"><Button type="submit" loading={calc.busy}>Hitung</Button></div>
        {calc.error != null && <Banner tone="bad">{describeError(error)}</Banner>}
      </form>

      {result && (
        <div className="stack" style={{ marginTop: 16 }} aria-live="polite">
          <h3>Hasil dari server</h3>
          <dl className="facts">
            <div><dt>Tarif pada {formatDate(result.date)}</dt><dd>{formatAmount(result.rate)}%</dd></div>
            <div><dt>Metode</dt><dd>{methodShort[result.calculation_method] ?? result.calculation_method}</dd></div>
            <div><dt>Jumlah yang dimasukkan</dt><dd className="money">{formatAmount(result.entered_amount)}</dd></div>
            <div><dt>Dasar pengenaan pajak</dt><dd className="money">{formatAmount(result.base_amount)}</dd></div>
            <div><dt>Pajak</dt><dd className="money">{formatAmount(result.tax_amount)}</dd></div>
            <div><dt>Dasar + pajak</dt><dd className="money">{formatAmount(result.total_amount)}</dd></div>
          </dl>
          {!result.is_recoverable && <p className="muted">Pajak ini tidak dapat dikreditkan: pada dokumen nilainya menjadi bagian dari biaya barisnya.</p>}
        </div>
      )}
    </Card>
  )
}
