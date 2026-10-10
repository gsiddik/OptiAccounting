import { useState } from 'react'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { API, useModuleAccess } from '../../lib/operational'
import { cashKindLabels } from '../../lib/operationalLabels'
import { AccountDeleteDialog, AccountForm, AccountStatusDialog } from './cash/AccountDialogs'
import type { CashAccount } from './cash/types'
import { usePagedList } from './expense/usePagedList'
import { ErrorNotice, Filters, Money, ReadOnlyNotice } from './shared'

const INITIAL = { q: '', kind: '', status: '' }

export default function CashBankAccounts() {
  const { canChange, readOnly } = useModuleAccess('ACCOUNTING_CASH_BANK')
  const toast = useToast()
  const manage = canChange('accounting.cash_bank.manage')
  const { filter, change, setPage, list } = usePagedList<CashAccount, typeof INITIAL>(`${API}/cash-bank-accounts`, INITIAL)
  const [editing, setEditing] = useState<CashAccount | 'new' | null>(null)
  const [toggling, setToggling] = useState<CashAccount | null>(null)
  const [deleting, setDeleting] = useState<CashAccount | null>(null)

  const done = (message: string) => () => {
    setEditing(null)
    setToggling(null)
    setDeleting(null)
    list.reload()
    toast.success(message)
  }

  return (
    <>
      <PageHeader
        title="Akun kas & bank"
        description="Kotak kas dan rekening bank yang masing-masing dipetakan ke satu akun buku besar. Saldo buku dihitung dari jurnal yang sudah diposting pada akun itu; angkanya hanya tampilan dan tidak dapat diubah di sini."
        actions={manage && <Button variant="primary" onClick={() => setEditing('new')}>Akun kas/bank baru</Button>}
      />
      <ReadOnlyNotice show={readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <input className="input" type="search" placeholder="Cari kode, nama, bank" aria-label="Cari akun kas/bank" value={filter.q} onChange={(e) => change('q', e.target.value)} />
            <select className="select" aria-label="Jenis akun" value={filter.kind} onChange={(e) => change('kind', e.target.value)}>
              <option value="">Semua jenis</option>
              {Object.entries(cashKindLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
            <select className="select" aria-label="Status akun" value={filter.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
              <option value="ACTIVE">Aktif</option>
              <option value="INACTIVE">Nonaktif</option>
            </select>
          </Filters>
        </div>

        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={list.error} onRetry={list.reload} />
        ) : list.data.data.length === 0 ? (
          <EmptyState title="Tidak ada akun kas/bank">{manage ? 'Ubah filter atau buat akun pertama.' : 'Ubah filter pencarian.'}</EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar akun kas dan bank"
              rows={list.data.data}
              rowKey={(a) => a.id}
              columns={[
                { header: 'Kode', primary: true, cell: (a) => <Link to={`/app/akuntansi/kas-bank/${a.id}`} className="mono">{a.code}</Link> },
                { header: 'Nama', cell: (a) => <>{a.name}{a.bank_name && <div className="muted">{a.bank_name}</div>}</> },
                { header: 'Jenis', cell: (a) => cashKindLabels[a.kind] ?? a.kind },
                { header: 'Akun buku besar', cell: (a) => (a.gl_account ? <><span className="mono">{a.gl_account.code}</span> · {a.gl_account.name}</> : <span className="muted">—</span>) },
                { header: 'Nomor rekening', cell: (a) => (a.account_number_masked ? <span className="mono">{a.account_number_masked}</span> : <span className="muted">—</span>) },
                { header: 'Mata uang', cell: (a) => a.currency },
                { header: 'Saldo buku', align: 'right', cell: (a) => <Money value={a.book_balance} /> },
                { header: 'Status', cell: (a) => <StatusBadge status={a.status} /> },
                { header: 'Aksi', actions: true, cell: (a) => manage && (
                  <div className="actions">
                    <Button size="sm" onClick={() => setEditing(a)}>Ubah</Button>
                    <Button size="sm" onClick={() => setToggling(a)}>{a.status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan'}</Button>
                    <Button size="sm" variant="danger" onClick={() => setDeleting(a)}>Hapus</Button>
                  </div>
                ) },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>

      {editing && <AccountForm account={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={done('Akun kas/bank disimpan.')} />}
      {toggling && <AccountStatusDialog account={toggling} onClose={() => setToggling(null)} onDone={done('Status akun diperbarui.')} />}
      {deleting && <AccountDeleteDialog account={deleting} onClose={() => setDeleting(null)} onDone={done('Akun kas/bank dihapus.')} />}
    </>
  )
}
