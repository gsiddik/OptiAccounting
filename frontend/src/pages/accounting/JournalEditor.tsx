import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Banner, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader } from '../../components/ui'
import { useToast } from '../../components/Toast'
import { useAccountingAccess, type Journal } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { todayIn } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { LinesEditor } from './LinesEditor'
import { emptyLine, linesFrom, linesPayload, lineProblems, type LineState } from './lines'
import { useAccounts, useDimensions } from './data'

export default function JournalEditor() {
  const { id } = useParams()
  const journal = useResource(async () => (id ? (await api.get<Journal>(`/app/accounting/journals/${id}`)).data : null), [id])

  if (journal.loading && !journal.data && id) return <Loading />
  if (journal.error) return <ErrorNotice error={journal.error} onRetry={journal.reload} />
  if (journal.data && journal.data.status !== 'DRAFT') {
    return <EmptyState title="Jurnal tidak dapat diubah">Hanya jurnal berstatus draf yang dapat diubah. Buka jurnalnya untuk melihat riwayat.</EmptyState>
  }
  return <Form key={id ?? 'new'} journal={journal.data} />
}

function Form({ journal }: { journal: Journal | null }) {
  const navigate = useNavigate()
  const toast = useToast()
  const { tenant } = useCapabilities()
  const { canChange } = useAccountingAccess()
  const { busy, error, run, clearError } = useAction()
  const { accounts, loading: loadingAccounts, error: accountsError, reload } = useAccounts()
  const { catalog } = useDimensions()
  const today = tenant?.business_date ?? todayIn()

  const [f, setF] = useState({
    document_date: journal?.document_date.slice(0, 10) ?? today,
    posting_date: journal?.posting_date.slice(0, 10) ?? today,
    reference: journal?.reference ?? '',
    description: journal?.description ?? '',
  })
  const [lines, setLines] = useState<LineState[]>(() => (journal ? linesFrom(journal.lines) : [emptyLine(), emptyLine()]))
  const [hint, setHint] = useState<string | null>(null)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  if (loadingAccounts && accounts.length === 0) return <Loading />
  if (accountsError) return <ErrorNotice error={accountsError} onRetry={reload} />

  async function save(thenSubmit: boolean) {
    const problem = lineProblems(lines)
    setHint(problem)
    if (problem) return
    clearError()
    const body = { ...f, reference: f.reference.trim() || null, description: f.description.trim(), lines: linesPayload(lines) }
    const saved = await run(async () => {
      const draft = (await (journal ? api.patch<Journal>(`/app/accounting/journals/${journal.id}`, body) : api.post<Journal>('/app/accounting/journals', body))).data
      if (thenSubmit) await api.post(`/app/accounting/journals/${draft.id}/submit`)
      return draft
    })
    if (saved.ok) {
      toast.success(thenSubmit ? 'Jurnal disimpan dan diajukan.' : 'Draf jurnal disimpan.')
      navigate(`/app/akuntansi/jurnal/${saved.value.id}`)
    }
  }

  return (
    <>
      <PageHeader title={journal ? `Ubah draf ${journal.journal_number ?? ''}`.trim() : 'Jurnal baru'} description="Setiap jurnal harus seimbang: total debit sama dengan total kredit. Total dihitung ulang oleh server." />
      <form onSubmit={(e) => { e.preventDefault(); void save(false) }} noValidate className="stack">
        {error != null && <Banner tone="bad">{describeError(error)}</Banner>}
        {hint && <Banner tone="warn">{hint}</Banner>}

        <Card title="Informasi jurnal">
          <div className="form-grid">
            <Field label="Tanggal dokumen" error={fieldError(error, 'document_date')}>
              {(p) => <input className="input" type="date" required value={f.document_date} onChange={set('document_date')} {...p} />}
            </Field>
            <Field label="Tanggal posting" error={fieldError(error, 'posting_date')} hint="Menentukan periode akuntansi.">
              {(p) => <input className="input" type="date" required value={f.posting_date} onChange={set('posting_date')} {...p} />}
            </Field>
            <Field label="Deskripsi" error={fieldError(error, 'description')} full>
              {(p) => <input className="input" required maxLength={500} value={f.description} onChange={set('description')} {...p} />}
            </Field>
            <Field label="Referensi" error={fieldError(error, 'reference')} hint="Nomor dokumen sumber, opsional.">
              {(p) => <input className="input" maxLength={100} value={f.reference} onChange={set('reference')} {...p} />}
            </Field>
          </div>
        </Card>

        <Card title="Baris jurnal" flush>
          <div className="card-body">
            <LinesEditor lines={lines} onChange={setLines} accounts={accounts} catalog={catalog} errorFor={(path) => fieldError(error, path)} />
          </div>
        </Card>

        <div className="actions form-actions">
          <Button onClick={() => navigate(journal ? `/app/akuntansi/jurnal/${journal.id}` : '/app/akuntansi/jurnal')}>Batal</Button>
          <Button type="submit" variant={canChange('accounting.journal.submit') ? 'secondary' : 'primary'} loading={busy}>Simpan draf</Button>
          {canChange('accounting.journal.submit') && <Button variant="primary" loading={busy} onClick={() => void save(true)}>Simpan & ajukan</Button>}
        </div>
      </form>
    </>
  )
}
