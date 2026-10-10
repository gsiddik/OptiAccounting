import { useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Field, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { useAccountingAccess, type Journal, type JournalLine } from '../../lib/accounting'
import { journalTypeLabels } from '../../lib/accountingLabels'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { formatDate, formatDateTime } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { statusLabel } from '../../lib/labels'
import { DataTable } from '../../components/DataTable'
import { Money, Side } from './shared'

type Dialog = null | { kind: 'reject' | 'cancel' | 'post' | 'reverse' }

export default function JournalDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const { tenant } = useCapabilities()
  const { canChange } = useAccountingAccess()
  const journal = useResource(async () => (await api.get<Journal>(`/app/accounting/journals/${id}`)).data, [id])
  const action = useAction()
  const [dialog, setDialog] = useState<Dialog>(null)

  if (journal.loading && !journal.data) return <Loading />
  if (journal.error || !journal.data) return <ErrorNotice error={journal.error} onRetry={journal.reload} />
  const j = journal.data
  const manual = j.journal_type === 'MANUAL'
  const sod = j.sod ?? { approve: false, post: false }
  const base = `/app/accounting/journals/${j.id}`

  async function step(path: string, body: object | undefined, done: string) {
    const r = await action.run(async () => (await api.post<Journal>(`${base}/${path}`, body)).data)
    if (r.ok) {
      setDialog(null)
      toast.success(done)
      journal.reload()
    }
    return r.ok
  }

  const buttons: ReactNode[] = []
  const notes: string[] = []
  if (manual) {
    if (j.status === 'DRAFT') {
      if (canChange('accounting.journal.update')) buttons.push(<Button key="edit" onClick={() => navigate(`/app/akuntansi/jurnal/${j.id}/ubah`)}>Ubah</Button>)
      if (canChange('accounting.journal.submit')) buttons.push(<Button key="submit" variant="primary" loading={action.busy} onClick={() => void step('submit', undefined, 'Jurnal diajukan.')}>Ajukan</Button>)
      if (!j.approval_required && canChange('accounting.journal.post')) {
        buttons.push(<Button key="post" variant="primary" disabled={!sod.post} onClick={() => setDialog({ kind: 'post' })}>Posting</Button>)
        if (!sod.post) notes.push('Pemisahan tugas: Anda tidak dapat memposting jurnal yang Anda siapkan sendiri.')
      }
    }
    if (j.status === 'SUBMITTED' && canChange('accounting.journal.approve')) {
      buttons.push(<Button key="approve" variant="primary" disabled={!sod.approve} loading={action.busy} onClick={() => void step('approve', undefined, 'Jurnal disetujui.')}>Setujui</Button>)
      buttons.push(<Button key="reject" disabled={!sod.approve} onClick={() => setDialog({ kind: 'reject' })}>Tolak</Button>)
      if (!sod.approve) notes.push('Pemisahan tugas: Anda tidak dapat menyetujui jurnal yang Anda siapkan atau ajukan sendiri.')
    }
    if (j.status === 'APPROVED' && canChange('accounting.journal.post')) {
      buttons.push(<Button key="post" variant="primary" disabled={!sod.post} onClick={() => setDialog({ kind: 'post' })}>Posting</Button>)
      if (!sod.post) notes.push('Pemisahan tugas: Anda tidak dapat memposting jurnal yang Anda siapkan atau setujui sendiri.')
    }
    if (j.status === 'REJECTED' && canChange('accounting.journal.update')) {
      buttons.push(<Button key="reopen" onClick={() => void step('reopen', undefined, 'Jurnal dibuka kembali sebagai draf.')}>Jadikan draf</Button>)
    }
    if (['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'].includes(j.status) && canChange('accounting.journal.update')) {
      buttons.push(<Button key="cancel" variant="danger" onClick={() => setDialog({ kind: 'cancel' })}>Batalkan</Button>)
    }
  }
  if (j.status === 'POSTED' && j.journal_type !== 'REVERSAL' && !j.reversed_by_journal_id && canChange('accounting.journal.reverse')) {
    buttons.push(<Button key="reverse" onClick={() => setDialog({ kind: 'reverse' })}>Balik jurnal</Button>)
  }

  return (
    <>
      <PageHeader
        title={j.journal_number ?? (j.status === 'DRAFT' ? 'Draf jurnal' : 'Jurnal belum bernomor')}
        description={<>{journalTypeLabels[j.journal_type] ?? j.journal_type} · <StatusBadge status={j.status} /></>}
        actions={buttons}
      />
      {notes.map((n) => <Banner key={n} tone="info">{n}</Banner>)}
      {action.error != null && dialog === null && <ErrorNotice error={action.error} />}
      {j.status === 'REJECTED' && j.reject_reason && <Banner tone="warn">Ditolak: {j.reject_reason}</Banner>}
      {j.journal_type === 'OPENING' && j.status === 'DRAFT' && <Banner tone="info">Draf saldo awal dikelola di halaman <Link to="/app/akuntansi/saldo-awal">Saldo awal</Link>.</Banner>}

      <Card>
        <dl className="facts">
          <div><dt>Tanggal dokumen</dt><dd>{formatDate(j.document_date)}</dd></div>
          <div><dt>Tanggal posting</dt><dd>{formatDate(j.posting_date)}</dd></div>
          <div><dt>Mata uang</dt><dd>{j.currency}</dd></div>
          <div><dt>Referensi</dt><dd>{j.reference ?? '—'}</dd></div>
          <div><dt>Dibuat oleh</dt><dd>{j.creator?.name ?? (j.source_type ? 'Sistem' : '—')}</dd></div>
          {j.posted_at && <div><dt>Diposting pada</dt><dd>{formatDateTime(j.posted_at)}</dd></div>}
          {j.source_type && <div><dt>Sumber</dt><dd>{j.source_type}</dd></div>}
          {j.reverses_journal_id && <div><dt>Membalik jurnal</dt><dd><Link to={`/app/akuntansi/jurnal/${j.reverses_journal_id}`}>Lihat jurnal asal</Link></dd></div>}
          {j.reversed_by_journal_id && <div><dt>Dibalik oleh</dt><dd><Link to={`/app/akuntansi/jurnal/${j.reversed_by_journal_id}`}>Lihat jurnal pembalik</Link></dd></div>}
          <div className="wide"><dt>Deskripsi</dt><dd>{j.description}</dd></div>
          {j.reversal_reason && <div className="wide"><dt>Alasan pembalikan</dt><dd>{j.reversal_reason}</dd></div>}
        </dl>
      </Card>

      <Card title="Baris jurnal" flush>
        <DataTable
          caption="Baris jurnal"
          rows={j.lines ?? []}
          rowKey={(l) => l.id ?? String(l.line_number)}
          columns={[
            { header: 'Akun', primary: true, cell: (l) => <><span className="mono">{l.account?.code}</span> · {l.account?.name}{l.description && <div className="muted">{l.description}</div>}</> },
            { header: 'Dimensi', cell: (l) => dimensions(l) },
            { header: 'Debit', align: 'right', cell: (l) => <Side value={l.debit} /> },
            { header: 'Kredit', align: 'right', cell: (l) => <Side value={l.credit} /> },
          ]}
        />
        <div className="table-total">
          <span>Total</span>
          <span>Debit <Money value={j.total_debit} strong /></span>
          <span>Kredit <Money value={j.total_credit} strong /></span>
        </div>
      </Card>

      <Card title="Riwayat">
        <ol className="timeline">
          {(j.transitions ?? []).map((t) => (
            <li key={t.id}>
              <strong>{t.from_status ? `${statusLabel(t.from_status)[0]} → ` : ''}{statusLabel(t.to_status)[0]}</strong>
              <span className="muted"> · {t.actor?.name ?? 'Sistem'} · {formatDateTime(t.occurred_at)}</span>
              {t.reason && <div>{t.reason}</div>}
            </li>
          ))}
        </ol>
      </Card>

      {dialog?.kind === 'post' && (
        <ConfirmDialog title="Posting jurnal" confirmLabel="Posting" busy={action.busy} error={action.error} onClose={() => setDialog(null)}
          message="Setelah diposting, jurnal mendapat nomor resmi dan tidak dapat diubah. Koreksi hanya dengan jurnal pembalik." onConfirm={() => void step('post', undefined, 'Jurnal diposting.')} />
      )}
      {dialog?.kind === 'reject' && (
        <ConfirmDialog title="Tolak jurnal" confirmLabel="Tolak" danger reasonRequired busy={action.busy} error={action.error} onClose={() => setDialog(null)}
          message="Jurnal dikembalikan ke penyusun beserta alasan penolakan." onConfirm={(reason) => void step('reject', { reason }, 'Jurnal ditolak.')} />
      )}
      {dialog?.kind === 'cancel' && (
        <ConfirmDialog title="Batalkan jurnal" confirmLabel="Batalkan jurnal" danger reasonRequired busy={action.busy} error={action.error} onClose={() => setDialog(null)}
          message="Jurnal yang dibatalkan tidak dapat diposting lagi dan tetap tersimpan sebagai riwayat." onConfirm={(reason) => void step('cancel', { reason }, 'Jurnal dibatalkan.')} />
      )}
      {dialog?.kind === 'reverse' && (
        <ReverseDialog journal={j} defaultDate={tenant?.business_date ?? j.posting_date} onClose={() => setDialog(null)} onDone={(id) => { setDialog(null); toast.success('Jurnal pembalik dibuat dan diposting.'); navigate(`/app/akuntansi/jurnal/${id}`) }} />
      )}
    </>
  )
}

function dimensions(l: JournalLine): ReactNode {
  const parts = [l.branch && `Cabang ${l.branch.code}`, l.business_unit && `Unit ${l.business_unit.code}`, l.cost_center && `Biaya ${l.cost_center.code}`].filter(Boolean)
  return parts.length ? parts.join(' · ') : <span className="muted">—</span>
}

function ReverseDialog({ journal, defaultDate, onClose, onDone }: { journal: Journal; defaultDate: string; onClose: () => void; onDone: (id: string) => void }) {
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ reason: '', reference: '', posting_date: defaultDate })

  async function submit() {
    const r = await run(async () => (await api.post<Journal>(`/app/accounting/journals/${journal.id}/reverse`, { reason: f.reason.trim(), reference: f.reference.trim() || null, posting_date: f.posting_date || null })).data)
    if (r.ok) onDone(r.value.id)
  }

  return (
    <FormModal title={`Balik jurnal ${journal.journal_number}`} submitLabel="Balik jurnal" busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <p>Jurnal pembalik menukar sisi debit dan kredit seluruh baris dan langsung diposting. Jurnal asal tetap tidak berubah dan hanya dapat dibalik sekali.</p>
      <div className="form-grid">
        <Field label="Tanggal posting pembalik" error={fieldError(error, 'posting_date')} hint="Harus pada periode yang terbuka.">
          {(p) => <input className="input" type="date" value={f.posting_date} onChange={(e) => setF((s) => ({ ...s, posting_date: e.target.value }))} {...p} />}
        </Field>
        <Field label="Referensi" error={fieldError(error, 'reference')}>
          {(p) => <input className="input" maxLength={100} value={f.reference} onChange={(e) => setF((s) => ({ ...s, reference: e.target.value }))} {...p} />}
        </Field>
        <Field label="Alasan (dicatat di audit)" error={fieldError(error, 'reason')} full>
          {(p) => <textarea className="textarea" required maxLength={500} value={f.reason} onChange={(e) => setF((s) => ({ ...s, reason: e.target.value }))} {...p} />}
        </Field>
      </div>
    </FormModal>
  )
}
