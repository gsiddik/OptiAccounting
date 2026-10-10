import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, EmptyState, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { ApiError, api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, formatDateTime } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { AddLinesModal, EditItemModal, ExceptionDialog, MatchDialog } from './bank/ItemDialogs'
import { DirectionAmount } from './bank/parts'
import { StatementFormModal } from './bank/StatementForm'
import { SummaryPanel } from './bank/SummaryPanel'
import type { BankStatement, StatementItem } from './bank/types'
import { useMatchedJournals, type MatchedJournal } from './bank/useMatchedJournals'
import { ErrorNotice, ExportButton, Money, ReadOnlyNotice } from './shared'

type Dialog =
  | null
  | { kind: 'complete' | 'delete' | 'edit' | 'add' }
  | { kind: 'match' | 'exception' | 'editItem' | 'deleteItem'; item: StatementItem }

export default function BankStatementDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const { can } = useCapabilities()
  const { canChange, readOnly } = useModuleAccess(MODULES.cashBank)
  const statement = useResource(async () => (await api.get<BankStatement>(`${API}/bank-statements/${id}`)).data, [id])
  const journals = useMatchedJournals(statement.data)
  const action = useAction()
  const [dialog, setDialog] = useState<Dialog>(null)

  if (statement.loading && !statement.data) return <Loading />
  if (statement.error || !statement.data) return <ErrorNotice error={statement.error} onRetry={statement.reload} />
  const s = statement.data
  const summary = s.summary
  const base = `${API}/bank-statements/${s.id}`
  const open = s.status === 'OPEN'
  const manage = open && canChange('accounting.bank_reconciliation.manage')
  const items = s.items ?? []

  /** Every item action answers with the whole statement: show it as it is, with the figures the API just computed. */
  function applied(saved: BankStatement, message: string) {
    statement.setData(() => saved)
    setDialog(null)
    action.clearError()
    toast.success(message)
  }
  function close() {
    setDialog(null)
    action.clearError()
  }

  async function post(path: string, message: string) {
    const r = await action.run(async () => (await api.post<BankStatement>(`${base}/${path}`)).data)
    if (r.ok) applied(r.value, message)
  }

  async function removeItem(item: StatementItem) {
    const r = await action.run(async () => (await api.delete<BankStatement>(`${base}/items/${item.id}`)).data)
    if (r.ok) applied(r.value, 'Baris dihapus.')
  }

  async function removeStatement() {
    const r = await action.run(() => api.delete(base))
    if (r.ok) {
      toast.success('Rekening koran dihapus.')
      navigate('/app/akuntansi/rekening-koran')
    }
  }

  const account = s.cash_bank_account
  const matchedCount = summary?.matched_items ?? 0

  return (
    <>
      <PageHeader
        title={s.reference}
        description={<>{account ? `${account.code} · ${account.name} · ` : ''}per {formatDate(s.statement_date)} · <StatusBadge status={s.status} /></>}
        actions={
          <>
            <Link to="/app/akuntansi/rekening-koran" className="btn btn-ghost">Kembali ke daftar</Link>
            {can('accounting.cash_bank.view') && <Link to={`/app/akuntansi/kas-bank/${s.cash_bank_account_id}`} className="btn">Mutasi akun</Link>}
            <ExportButton path={`${base}/export`} filename={`rekening-koran_${s.reference}.csv`} />
            {manage && <Button onClick={() => setDialog({ kind: 'edit' })}>Ubah</Button>}
            {manage && matchedCount === 0 && <Button variant="danger" onClick={() => setDialog({ kind: 'delete' })}>Hapus</Button>}
            {manage && <Button variant="primary" onClick={() => setDialog({ kind: 'complete' })}>Selesaikan rekonsiliasi</Button>}
          </>
        }
      />
      <ReadOnlyNotice show={readOnly} />
      <Banner tone="info">Rekonsiliasi tidak pernah mengubah buku besar. Mencocokkan, menandai pengecualian, dan menyelesaikan hanya mencatat tautan dan bukti; tidak ada jurnal yang dibuat atau diubah, dan selisih tidak disesuaikan otomatis.</Banner>
      {action.error != null && dialog === null && <ErrorNotice error={action.error} />}

      <Card title="Rekening koran">
        <dl className="facts">
          <div><dt>Akun bank</dt><dd>{account ? <>{can('accounting.cash_bank.view') ? <Link to={`/app/akuntansi/kas-bank/${account.id}`}>{account.code} · {account.name}</Link> : `${account.code} · ${account.name}`}{account.bank_name && <div className="muted">{account.bank_name}{account.account_number_masked ? ` · ${account.account_number_masked}` : ''}</div>}</> : '—'}</dd></div>
          <div><dt>Tanggal rekening koran</dt><dd>{formatDate(s.statement_date)}</dd></div>
          <div><dt>Awal periode</dt><dd>{formatDate(s.period_start)}</dd></div>
          <div><dt>Saldo awal</dt><dd>{s.opening_balance === null ? '—' : <Money value={s.opening_balance} />}</dd></div>
          <div><dt>Saldo akhir</dt><dd><Money value={s.closing_balance} strong /></dd></div>
          <div><dt>Mata uang</dt><dd>{s.currency}</dd></div>
          <div><dt>Dibuat oleh</dt><dd>{s.creator?.name ?? '—'}</dd></div>
          {s.branch && <div><dt>Cabang</dt><dd>{s.branch.code} · {s.branch.name}</dd></div>}
          {s.notes && <div className="wide"><dt>Catatan</dt><dd>{s.notes}</dd></div>}
        </dl>
      </Card>

      {summary && <SummaryPanel statement={s} summary={summary} />}

      <Card title="Baris rekening koran" flush actions={manage && <Button size="sm" onClick={() => setDialog({ kind: 'add' })}>Tambah baris</Button>}>
        {items.length === 0 ? (
          <EmptyState title="Belum ada baris" action={manage && <Button onClick={() => setDialog({ kind: 'add' })}>Tambah baris</Button>}>
            Masukkan baris dari rekening koran bank, satu per satu atau dengan menempelkan dari spreadsheet.
          </EmptyState>
        ) : (
          <DataTable
            caption="Baris rekening koran"
            rows={items}
            rowKey={(i) => i.id}
            columns={[
              { header: 'No', cell: (i) => i.line_number },
              { header: 'Tanggal', cell: (i) => formatDate(i.item_date) },
              {
                header: 'Keterangan',
                primary: true,
                cell: (i) => (
                  <>
                    {i.description}
                    {i.reference && <div className="muted">Ref {i.reference}</div>}
                    {i.notes && <div className="muted">Alasan: {i.notes}</div>}
                  </>
                ),
              },
              { header: 'Jumlah', align: 'right', cell: (i) => <DirectionAmount amount={i.amount} /> },
              { header: 'Status', cell: (i) => <StatusBadge status={i.status} /> },
              { header: 'Jurnal buku', cell: (i) => <JournalCell item={i} journals={journals} linkable={can('accounting.journal.view')} /> },
              {
                header: 'Aksi',
                actions: true,
                cell: (i) =>
                  manage && (
                    <div className="actions">
                      {i.status !== 'MATCHED' && <Button size="sm" disabled={action.busy} aria-label={`Cocokkan baris ${i.line_number}`} onClick={() => setDialog({ kind: 'match', item: i })}>Cocokkan</Button>}
                      {i.status === 'UNMATCHED' && <Button size="sm" disabled={action.busy} aria-label={`Pengecualian baris ${i.line_number}`} onClick={() => setDialog({ kind: 'exception', item: i })}>Pengecualian</Button>}
                      {i.status !== 'UNMATCHED' && <Button size="sm" disabled={action.busy} aria-label={`Lepas baris ${i.line_number}`} onClick={() => void post(`items/${i.id}/unmatch`, 'Pencocokan dilepas.')}>Lepas</Button>}
                      {i.status !== 'MATCHED' && <Button size="sm" disabled={action.busy} aria-label={`Ubah baris ${i.line_number}`} onClick={() => setDialog({ kind: 'editItem', item: i })}>Ubah</Button>}
                      {i.status !== 'MATCHED' && <Button size="sm" variant="danger" disabled={action.busy} aria-label={`Hapus baris ${i.line_number}`} onClick={() => setDialog({ kind: 'deleteItem', item: i })}>Hapus</Button>}
                    </div>
                  ),
              },
            ]}
          />
        )}
      </Card>

      {dialog?.kind === 'add' && <AddLinesModal statement={s} onClose={close} onSaved={(saved) => applied(saved, 'Baris ditambahkan.')} />}
      {dialog?.kind === 'edit' && <StatementFormModal statement={s} onClose={close} onSaved={(saved) => applied(saved, 'Rekening koran diperbarui.')} />}
      {dialog?.kind === 'match' && <MatchDialog statement={s} item={dialog.item} onClose={close} onMatched={(saved) => applied(saved, `Baris ${dialog.item.line_number} dicocokkan.`)} />}
      {dialog?.kind === 'exception' && <ExceptionDialog statement={s} item={dialog.item} onClose={close} onSaved={(saved) => applied(saved, `Baris ${dialog.item.line_number} ditandai pengecualian.`)} />}
      {dialog?.kind === 'editItem' && <EditItemModal statement={s} item={dialog.item} onClose={close} onSaved={(saved) => applied(saved, 'Baris diperbarui.')} />}
      {dialog?.kind === 'deleteItem' && (
        <ConfirmDialog title={`Hapus baris ${dialog.item.line_number}`} confirmLabel="Hapus baris" danger busy={action.busy} error={action.error} onClose={close}
          message={`Baris "${dialog.item.description}" dihapus dari rekening koran ini. Buku besar tidak terpengaruh.`} onConfirm={() => void removeItem(dialog.item)} />
      )}
      {dialog?.kind === 'delete' && (
        <ConfirmDialog title="Hapus rekening koran" confirmLabel="Hapus" danger busy={action.busy} error={action.error} onClose={close}
          message={`Rekening koran ${s.reference} dan seluruh barisnya dihapus. Buku besar tidak terpengaruh.`} onConfirm={() => void removeStatement()} />
      )}
      {dialog?.kind === 'complete' && (
        <ConfirmDialog title="Selesaikan rekonsiliasi" confirmLabel="Selesaikan" busy={action.busy} error={completionError(action.error)} onClose={close}
          message="Setiap baris harus sudah dicocokkan atau ditandai pengecualian. Setelah diselesaikan, rekening koran dan barisnya terkunci dan angka rekonsiliasi dibekukan sebagai bukti. Selisih yang tersisa dicatat apa adanya; tidak ada jurnal yang dibuat atau diubah."
          onConfirm={() => void post('complete', 'Rekonsiliasi diselesaikan.')} />
      )}
    </>
  )
}

/** The API refuses completion while lines are unmatched and says how many; the count belongs in the message. */
function completionError(error: unknown): unknown {
  if (error instanceof ApiError && error.code === 'BANK_STATEMENT_HAS_UNMATCHED_ITEMS') {
    const count = Number(error.details.unmatched_items)
    return new Error(`${describeError(error)}${Number.isFinite(count) ? ` Masih ada ${count} baris yang belum dicocokkan.` : ''}`)
  }
  return error
}

function JournalCell({ item, journals, linkable }: { item: StatementItem; journals: Map<string, MatchedJournal>; linkable: boolean }) {
  if (item.status !== 'MATCHED') return <span className="muted">—</span>
  const journal = journals.get(item.id)
  return (
    <>
      {journal ? (
        linkable ? <Link to={`/app/akuntansi/jurnal/${journal.journal_entry_id}`} className="mono">{journal.journal_number ?? 'Lihat jurnal'}</Link> : <span className="mono">{journal.journal_number ?? '—'}</span>
      ) : (
        <span className="muted">Terhubung ke baris buku</span>
      )}
      {(item.matcher?.name || item.matched_at) && (
        <div className="muted">{[item.matcher?.name && `oleh ${item.matcher.name}`, item.matched_at && formatDateTime(item.matched_at)].filter(Boolean).join(' · ')}</div>
      )}
    </>
  )
}
