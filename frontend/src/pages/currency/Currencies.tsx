import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Badge, Banner, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useDebounced, useResource } from '../../lib/hooks'
import { API, MODULES, listParams, useModuleAccess, type Page } from '../../lib/operational'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { CurrencyDeleteDialog, CurrencyForm, CurrencyStatusDialog } from './CurrencyDialogs'
import { useFunctionalCurrency } from './data'
import { FxRulesPanel } from './FxRulesPanel'
import type { Currency } from './types'

const EMPTY = { q: '', status: '' }

export default function Currencies() {
  const toast = useToast()
  const access = useModuleAccess(MODULES.multiCurrency)
  const manage = access.canChange('accounting.currency.manage')
  const functional = useFunctionalCurrency()
  const [f, setF] = useState(EMPTY)
  const [page, setPage] = useState(1)
  const q = useDebounced(f.q)
  const filter = { ...f, q }
  const currencies = useResource(async () => (await api.get<Page<Currency>>(`${API}/currencies`, { params: listParams(filter, page) })).data, [page, JSON.stringify(filter)])
  const [editing, setEditing] = useState<Currency | 'new' | null>(null)
  const [toggling, setToggling] = useState<Currency | null>(null)
  const [deleting, setDeleting] = useState<Currency | null>(null)

  const change = (patch: Partial<typeof EMPTY>) => {
    setF((s) => ({ ...s, ...patch }))
    setPage(1)
  }
  const done = (message: string) => () => {
    setEditing(null)
    setToggling(null)
    setDeleting(null)
    currencies.reload()
    toast.success(message)
  }
  const profile = functional.profile

  return (
    <>
      <PageHeader
        title="Mata uang"
        description="Mata uang asing yang boleh dipakai pada dokumen. Pembukuan dan seluruh laporan tetap dalam mata uang fungsional; jumlah asing hanya disimpan di samping jumlah fungsionalnya. Mata uang yang sudah dipakai dinonaktifkan, tidak dihapus."
        actions={manage && <Button variant="primary" onClick={() => setEditing('new')}>Mata uang baru</Button>}
      />
      <ReadOnlyNotice show={access.readOnly} />

      <Card title="Mata uang fungsional">
        {functional.loading && !functional.data && functional.allowed ? (
          <Loading />
        ) : profile ? (
          <dl className="facts">
            <div><dt>Kode</dt><dd className="mono">{profile.functional_currency}</dd></div>
            <div><dt>Jumlah desimal</dt><dd>{profile.currency_scale}</dd></div>
            <div><dt>Status</dt><dd><Badge tone="info">Bawaan pembukuan</Badge></dd></div>
            <div className="wide"><dt>Catatan</dt><dd>Mata uang fungsional selalu tersedia, tidak didaftarkan di daftar di bawah, dan diatur di profil akuntansi.</dd></div>
          </dl>
        ) : (
          <Banner tone="info">
            {!functional.allowed
              ? 'Mata uang fungsional ditentukan di profil akuntansi. Menampilkannya membutuhkan izin melihat profil akuntansi.'
              : functional.error
                ? 'Mata uang fungsional tidak dapat dimuat saat ini.'
                : 'Profil akuntansi belum disimpan, sehingga mata uang fungsional belum ditetapkan.'}
          </Banner>
        )}
      </Card>

      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari kode atau nama" aria-label="Cari mata uang" value={f.q} onChange={(e) => change({ q: e.target.value })} />
            <select className="select" aria-label="Status mata uang" value={f.status} onChange={(e) => change({ status: e.target.value })}>
              <option value="">Semua status</option>
              <option value="ACTIVE">Aktif</option>
              <option value="INACTIVE">Nonaktif</option>
            </select>
          </Filters>
        </div>

        {currencies.loading && !currencies.data ? (
          <Loading />
        ) : currencies.error || !currencies.data ? (
          <ErrorNotice error={currencies.error} onRetry={currencies.reload} />
        ) : currencies.data.data.length === 0 ? (
          <EmptyState title="Belum ada mata uang asing">{manage ? 'Ubah filter atau tambahkan mata uang asing pertama. Organisasi yang hanya memakai mata uang fungsional tidak perlu menambahkan apa pun.' : 'Ubah filter pencarian.'}</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar mata uang asing"
              rows={currencies.data.data}
              rowKey={(c) => c.id}
              scroll
              columns={[
                { header: 'Kode', primary: true, cell: (c) => <span className="mono">{c.code}</span> },
                { header: 'Nama', cell: (c) => c.name },
                { header: 'Simbol', cell: (c) => c.symbol ?? <span className="muted">—</span> },
                { header: 'Desimal', align: 'right', cell: (c) => c.decimal_places },
                { header: 'Pemakaian', cell: (c) => (c.in_use ? 'Sudah dipakai' : <span className="muted">Belum dipakai</span>) },
                { header: 'Status', cell: (c) => <StatusBadge status={c.status} /> },
                { header: 'Aksi', actions: true, cell: (c) => manage && (
                  <div className="actions">
                    <Button size="sm" onClick={() => setEditing(c)}>Ubah</Button>
                    <Button size="sm" onClick={() => setToggling(c)}>{c.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
                    {c.in_use === false && <Button size="sm" variant="danger" onClick={() => setDeleting(c)}>Hapus</Button>}
                  </div>
                ) },
              ]}
            />
            <Pagination page={currencies.data.current_page} lastPage={currencies.data.last_page} total={currencies.data.total} onPage={setPage} />
          </>
        )}
      </Card>

      <FxRulesPanel />

      {editing && <CurrencyForm currency={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={done(editing === 'new' ? 'Mata uang ditambahkan.' : 'Mata uang disimpan.')} />}
      {toggling && <CurrencyStatusDialog currency={toggling} onClose={() => setToggling(null)} onDone={done('Status mata uang diperbarui.')} />}
      {deleting && <CurrencyDeleteDialog currency={deleting} onClose={() => setDeleting(null)} onDone={done('Mata uang dihapus.')} />}
    </>
  )
}
