import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, Loading, PageHeader, Pagination, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, formatNumber } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { API, MODULES, listParams, useCashBankAccounts, useModuleAccess, type Page } from '../../lib/operational'
import { StatementFormModal } from './bank/StatementForm'
import type { BankStatement } from './bank/types'
import { ErrorNotice, Filters, Money, ReadOnlyNotice } from './shared'

const FILTER = { cash_bank_account_id: '', status: '', from: '', to: '' }

export default function BankStatements() {
  const { canChange, readOnly } = useModuleAccess(MODULES.cashBank)
  const { can } = useCapabilities()
  const navigate = useNavigate()
  const toast = useToast()
  const remove = useAction()
  const manage = canChange('accounting.bank_reconciliation.manage')
  const [f, setF] = useState(FILTER)
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const [deleting, setDeleting] = useState<BankStatement | null>(null)

  const list = useResource(async () => (await api.get<Page<BankStatement>>(`${API}/bank-statements`, { params: listParams(f, page) })).data, [page, f.cash_bank_account_id, f.status, f.from, f.to])

  const change = <K extends keyof typeof f>(k: K, v: (typeof f)[K]) => {
    setF((s) => ({ ...s, [k]: v }))
    setPage(1)
  }

  async function confirmDelete() {
    if (!deleting) return
    const r = await remove.run(() => api.delete(`${API}/bank-statements/${deleting.id}`))
    if (r.ok) {
      setDeleting(null)
      toast.success('Rekening koran dihapus.')
      list.reload()
    }
  }

  return (
    <>
      <PageHeader
        title="Rekening koran"
        description="Saldo dan mutasi yang dinyatakan bank, dicocokkan satu per satu dengan baris buku yang sudah diposting. Rekonsiliasi hanya menautkan dan melaporkan selisih; buku besar tidak pernah diubah."
        actions={manage && <Button variant="primary" onClick={() => setCreating(true)}>Rekening koran baru</Button>}
      />
      <ReadOnlyNotice show={readOnly} />

      <Card flush>
        <div className="card-body">
          <Filters>
            {can('accounting.cash_bank.view') && <AccountFilter value={f.cash_bank_account_id} onChange={(v) => change('cash_bank_account_id', v)} />}
            <select className="select" aria-label="Status" value={f.status} onChange={(e) => change('status', e.target.value)}>
              <option value="">Semua status</option>
              <option value="OPEN">Terbuka</option>
              <option value="COMPLETED">Selesai</option>
            </select>
            <label className="inline-field">Dari <input className="input" type="date" value={f.from} onChange={(e) => change('from', e.target.value)} /></label>
            <label className="inline-field">Sampai <input className="input" type="date" value={f.to} onChange={(e) => change('to', e.target.value)} /></label>
          </Filters>
        </div>

        {list.loading && !list.data ? (
          <Loading />
        ) : list.error || !list.data ? (
          <ErrorNotice error={list.error} onRetry={list.reload} />
        ) : list.data.data.length === 0 ? (
          <EmptyState title="Belum ada rekening koran" action={manage && <Button onClick={() => setCreating(true)}>Rekening koran baru</Button>}>
            Ubah filter, atau masukkan saldo dan mutasi dari rekening koran bank untuk mulai merekonsiliasi.
          </EmptyState>
        ) : (
          <>
            <DataTable
              caption="Daftar rekening koran"
              rows={list.data.data}
              rowKey={(s) => s.id}
              columns={[
                { header: 'Referensi', primary: true, cell: (s) => <Link to={`/app/akuntansi/rekening-koran/${s.id}`} className="mono">{s.reference}</Link> },
                { header: 'Akun bank', cell: (s) => (s.cash_bank_account ? <>{s.cash_bank_account.code} · {s.cash_bank_account.name}</> : <span className="muted">—</span>) },
                { header: 'Tanggal', cell: (s) => formatDate(s.statement_date) },
                { header: 'Saldo akhir', align: 'right', cell: (s) => <Money value={s.closing_balance} /> },
                { header: 'Baris', cell: (s) => <Counts statement={s} /> },
                { header: 'Status', cell: (s) => <StatusBadge status={s.status} /> },
                {
                  header: 'Aksi',
                  actions: true,
                  cell: (s) => (
                    <div className="actions">
                      {manage && s.status === 'OPEN' && Number(s.matched_items ?? 0) === 0 && (
                        <Button size="sm" variant="danger" aria-label={`Hapus ${s.reference}`} onClick={() => setDeleting(s)}>Hapus</Button>
                      )}
                    </div>
                  ),
                },
              ]}
            />
            <Pagination page={list.data.current_page} lastPage={list.data.last_page} total={list.data.total} onPage={setPage} />
          </>
        )}
      </Card>

      {creating && (
        <StatementFormModal
          defaultAccountId={f.cash_bank_account_id || undefined}
          onClose={() => setCreating(false)}
          onSaved={(saved) => {
            setCreating(false)
            toast.success('Rekening koran dibuat.')
            navigate(`/app/akuntansi/rekening-koran/${saved.id}`)
          }}
        />
      )}
      {deleting && (
        <ConfirmDialog
          title="Hapus rekening koran"
          confirmLabel="Hapus"
          danger
          busy={remove.busy}
          error={remove.error}
          onClose={() => { setDeleting(null); remove.clearError() }}
          message={`Rekening koran ${deleting.reference} dan seluruh barisnya dihapus. Hanya rekening koran terbuka tanpa baris yang dicocokkan yang dapat dihapus; buku besar tidak terpengaruh.`}
          onConfirm={() => void confirmDelete()}
        />
      )}
    </>
  )
}

/** Bank account filter; its own component so the accounts are requested only for users who may read them. */
function AccountFilter({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const { accounts } = useCashBankAccounts()
  return (
    <select className="select" aria-label="Akun bank" value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">Semua akun bank</option>
      {accounts.filter((a) => a.kind === 'BANK').map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
    </select>
  )
}

function Counts({ statement: s }: { statement: BankStatement }) {
  if (s.matched_items === undefined) return <span className="muted">—</span>
  return (
    <span>
      {formatNumber(s.matched_items)} cocok · {formatNumber(s.exception_items ?? 0)} pengecualian
      <span className="muted" style={{ display: 'block' }}>{formatNumber(s.unmatched_items ?? 0)} belum dicocokkan</span>
    </span>
  )
}
