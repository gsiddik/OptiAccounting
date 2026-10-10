import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Badge, Banner, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination } from '../../components/ui'
import { formatAmount } from '../../lib/accounting'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { API, MODULES, listParams, useModuleAccess, type Page } from '../../lib/operational'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { useCurrencyOptions } from './data'
import { rateTypeLabels } from './labels'
import { RateDeleteDialog, RateForm, RateStatusDialog } from './RateDialogs'
import { RateLookup } from './RateLookup'
import { RATE_TYPES, type ExchangeRate } from './types'

const EMPTY = { currency: '', rate_type: '', status: '', date_from: '', date_to: '' }

export default function ExchangeRates() {
  const toast = useToast()
  const access = useModuleAccess(MODULES.multiCurrency)
  const manage = access.canChange('accounting.exchange_rate.manage')
  const options = useCurrencyOptions()
  const [f, setF] = useState(EMPTY)
  const [page, setPage] = useState(1)
  const invalid = f.date_from !== '' && f.date_to !== '' && f.date_from > f.date_to
  // A half-typed ISO code is never sent: the API accepts exactly three capital letters.
  const query = { ...f, currency: /^[A-Z]{3}$/.test(f.currency) ? f.currency : '' }
  const rates = useResource(async () => (invalid ? null : (await api.get<Page<ExchangeRate>>(`${API}/exchange-rates`, { params: listParams(query, page) })).data), [page, JSON.stringify(query)])
  const [creating, setCreating] = useState(false)
  const [toggling, setToggling] = useState<ExchangeRate | null>(null)
  const [deleting, setDeleting] = useState<ExchangeRate | null>(null)

  const change = (patch: Partial<typeof EMPTY>) => {
    setF((s) => ({ ...s, ...patch }))
    setPage(1)
  }
  const done = (message: string) => () => {
    setCreating(false)
    setToggling(null)
    setDeleting(null)
    rates.reload()
    toast.success(message)
  }

  return (
    <>
      <PageHeader
        title="Kurs"
        description="Kurs mata uang asing ke mata uang fungsional menurut tanggal berlaku. Kurs adalah fakta yang tidak diubah: kurs yang keliru ditarik lalu dimasukkan kurs yang benar. Dokumen menyimpan kurs yang dipakainya, jadi perubahan di sini tidak menggeser dokumen yang sudah ada."
        actions={manage && <Button variant="primary" onClick={() => setCreating(true)}>Kurs baru</Button>}
      />
      <ReadOnlyNotice show={access.readOnly} />

      <RateLookup />

      <Card flush>
        <div className="card-body">
          <Filters>
            {options.available ? (
              <select className="select" aria-label="Mata uang" value={f.currency} onChange={(e) => change({ currency: e.target.value })}>
                <option value="">Semua mata uang</option>
                {options.currencies.map((c) => <option key={c.id} value={c.code}>{c.code} · {c.name}</option>)}
              </select>
            ) : (
              <input className="input mono" style={{ maxWidth: 140 }} placeholder="Kode mata uang" aria-label="Mata uang" maxLength={3} value={f.currency} onChange={(e) => change({ currency: e.target.value.toUpperCase() })} />
            )}
            <select className="select" aria-label="Jenis kurs" value={f.rate_type} onChange={(e) => change({ rate_type: e.target.value })}>
              <option value="">Semua jenis</option>
              {RATE_TYPES.map((t) => <option key={t} value={t}>{rateTypeLabels[t]}</option>)}
            </select>
            <select className="select" aria-label="Status kurs" value={f.status} onChange={(e) => change({ status: e.target.value })}>
              <option value="">Semua status</option>
              <option value="ACTIVE">Aktif</option>
              <option value="INACTIVE">Ditarik</option>
            </select>
            <label className="inline-field">Dari <input className="input" type="date" aria-label="Dari tanggal" value={f.date_from} onChange={(e) => change({ date_from: e.target.value })} /></label>
            <label className="inline-field">Sampai <input className="input" type="date" aria-label="Sampai tanggal" value={f.date_to} onChange={(e) => change({ date_to: e.target.value })} /></label>
          </Filters>
        </div>
        {invalid && <div className="card-body"><Banner tone="warn">Tanggal akhir tidak boleh sebelum tanggal awal.</Banner></div>}

        {invalid ? null : rates.loading && !rates.data ? (
          <Loading />
        ) : rates.error || !rates.data ? (
          <ErrorNotice error={rates.error} onRetry={rates.reload} />
        ) : rates.data.data.length === 0 ? (
          <EmptyState title="Belum ada kurs">{manage ? 'Ubah filter atau masukkan kurs pertama. Organisasi yang hanya memakai mata uang fungsional tidak membutuhkan kurs.' : 'Ubah filter pencarian.'}</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar kurs"
              rows={rates.data.data}
              rowKey={(r) => r.id}
              scroll
              columns={[
                { header: 'Mata uang', primary: true, cell: (r) => <><span className="mono">{r.from_currency}</span> → <span className="mono">{r.to_currency}</span></> },
                { header: 'Berlaku mulai', cell: (r) => formatDate(r.effective_date) },
                { header: 'Jenis', cell: (r) => rateTypeLabels[r.rate_type] ?? r.rate_type },
                { header: 'Nilai kurs', align: 'right', cell: (r) => <span className="money">{formatAmount(r.rate)}</span> },
                { header: 'Sumber', cell: (r) => r.source ?? <span className="muted">—</span> },
                { header: 'Catatan', cell: (r) => r.notes ?? <span className="muted">—</span> },
                { header: 'Status', cell: (r) => (r.status === 'ACTIVE' ? <Badge tone="ok">Aktif</Badge> : <Badge>Ditarik</Badge>) },
                { header: 'Aksi', actions: true, cell: (r) => manage && (
                  <div className="actions">
                    <Button size="sm" onClick={() => setToggling(r)}>{r.status === 'ACTIVE' ? 'Tarik' : 'Aktifkan'}</Button>
                    {r.in_use !== true && <Button size="sm" variant="danger" onClick={() => setDeleting(r)}>Hapus</Button>}
                  </div>
                ) },
              ]}
            />
            <Pagination page={rates.data.current_page} lastPage={rates.data.last_page} total={rates.data.total} onPage={setPage} />
          </>
        )}
      </Card>

      {creating && <RateForm onClose={() => setCreating(false)} onDone={done('Kurs disimpan.')} />}
      {toggling && <RateStatusDialog rate={toggling} onClose={() => setToggling(null)} onDone={done(toggling.status === 'ACTIVE' ? 'Kurs ditarik.' : 'Kurs diaktifkan.')} />}
      {deleting && <RateDeleteDialog rate={deleting} onClose={() => setDeleting(null)} onDone={done('Kurs dihapus.')} />}
    </>
  )
}
