import { useState } from 'react'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Field, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { previewTotals, useAccountingAccess, amountToApi, type OpeningBalance as Opening } from '../../lib/accounting'
import { api } from '../../lib/api'
import { fieldError } from '../../lib/forms'
import { formatDate } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { LinesEditor } from './LinesEditor'
import { emptyLine, linesFrom, linesPayload, lineProblems, type LineState } from './lines'
import { Money, Side } from './shared'
import { useAccounts, useDimensions } from './data'

export default function OpeningBalancePage() {
  const opening = useResource(async () => (await api.get<{ data: Opening | null }>('/app/accounting/opening-balance')).data.data, [])
  const accounts = useAccounts()
  if ((opening.loading && opening.data === null) || (accounts.loading && accounts.accounts.length === 0)) return <Loading />
  if (opening.error) return <ErrorNotice error={opening.error} onRetry={opening.reload} />
  if (accounts.error) return <ErrorNotice error={accounts.error} onRetry={accounts.reload} />
  const current = opening.data
  const active = current && (current.status === 'DRAFT' || current.status === 'POSTED') ? current : null
  return <Body key={active ? `${active.id}:${active.status}` : 'none'} opening={active} previous={current && !active ? current : null} accounts={accounts.accounts} onChanged={opening.reload} />
}

function Body({ opening, previous, accounts, onChanged }: { opening: Opening | null; previous: Opening | null; accounts: ReturnType<typeof useAccounts>['accounts']; onChanged: () => void }) {
  const toast = useToast()
  const { canChange } = useAccountingAccess()
  const { catalog } = useDimensions()
  const save = useAction()
  const act = useAction()
  const posted = opening?.status === 'POSTED'
  const canManage = canChange('accounting.opening_balance.manage')
  const canPost = canChange('accounting.opening_balance.post')

  const [f, setF] = useState({ cutover_date: opening?.cutover_date.slice(0, 10) ?? '', reference: opening?.reference ?? '', description: opening?.description ?? '' })
  const [lines, setLines] = useState<LineState[]>(() => (opening ? linesFrom(opening.journal.lines) : [emptyLine(), emptyLine()]))
  const [saved, setSaved] = useState(() => snapshot(f, lines))
  const [hint, setHint] = useState<string | null>(null)
  const [dialog, setDialog] = useState<'post' | 'cancel' | null>(null)
  const dirty = snapshot(f, lines) !== saved
  const totals = previewTotals(lines)

  async function submit() {
    const problem = lineProblems(lines)
    setHint(problem)
    if (problem) return
    const r = await save.run(() => api.put('/app/accounting/opening-balance', { ...f, reference: f.reference.trim() || null, description: f.description.trim() || null, lines: linesPayload(lines) }))
    if (r.ok) {
      toast.success('Draf saldo awal disimpan.')
      setSaved(snapshot(f, lines))
      onChanged()
    }
  }

  async function step(path: 'post' | 'cancel', body: object | undefined, done: string) {
    const r = await act.run(() => api.post(`/app/accounting/opening-balance/${path}`, body))
    if (r.ok) {
      setDialog(null)
      toast.success(done)
      onChanged()
    }
  }

  return (
    <>
      <PageHeader
        title="Saldo awal"
        description="Saldo awal dibukukan satu kali sebagai satu jurnal pada tanggal cutover. Setelah diposting, jurnal tidak dapat diubah; untuk mengulang, balik jurnal saldo awal lalu buat yang baru."
        actions={opening && <StatusBadge status={opening.status} />}
      />
      {previous && <Banner tone="info">Saldo awal sebelumnya berstatus {previous.status === 'REVERSED' ? 'dibalik' : 'dibatalkan'}. Anda dapat menyusun saldo awal yang baru.</Banner>}
      {!opening && !previous && <Banner tone="info">Lewati halaman ini bila pembukuan dimulai dari nol. Untuk pembukuan berjalan, masukkan saldo setiap akun pada tanggal cutover; total debit harus sama dengan total kredit.</Banner>}

      {posted && opening ? (
        <>
          <Card>
            <dl className="facts">
              <div><dt>Tanggal cutover</dt><dd>{formatDate(opening.cutover_date)}</dd></div>
              <div><dt>Referensi</dt><dd>{opening.reference ?? '—'}</dd></div>
              <div><dt>Nomor jurnal</dt><dd><Link to={`/app/akuntansi/jurnal/${opening.journal.id}`} className="mono">{opening.journal.journal_number}</Link></dd></div>
              <div><dt>Total debit</dt><dd><Money value={opening.total_debit} /></dd></div>
              <div><dt>Total kredit</dt><dd><Money value={opening.total_credit} /></dd></div>
            </dl>
          </Card>
          <Card title="Saldo per akun" flush>
            <DataTable
              caption="Saldo awal per akun"
              rows={opening.journal.lines ?? []}
              rowKey={(l) => l.id ?? String(l.line_number)}
              columns={[
                { header: 'Akun', primary: true, cell: (l) => <><span className="mono">{l.account?.code}</span> · {l.account?.name}</> },
                { header: 'Debit', align: 'right', cell: (l) => <Side value={l.debit} /> },
                { header: 'Kredit', align: 'right', cell: (l) => <Side value={l.credit} /> },
              ]}
            />
          </Card>
        </>
      ) : (
        <form onSubmit={(e) => { e.preventDefault(); void submit() }} noValidate className="stack">
          {save.error != null && <ErrorNotice error={save.error} />}
          {hint && <Banner tone="warn">{hint}</Banner>}
          <Card title="Informasi saldo awal">
            <div className="form-grid">
              <Field label="Tanggal cutover" error={fieldError(save.error, 'cutover_date')} hint="Tanggal saldo ini berlaku. Posting biasa hanya dapat dilakukan mulai tanggal ini.">
                {(p) => <input className="input" type="date" required disabled={!canManage} value={f.cutover_date} onChange={(e) => setF((s) => ({ ...s, cutover_date: e.target.value }))} {...p} />}
              </Field>
              <Field label="Referensi" error={fieldError(save.error, 'reference')}>
                {(p) => <input className="input" maxLength={100} disabled={!canManage} value={f.reference} onChange={(e) => setF((s) => ({ ...s, reference: e.target.value }))} {...p} />}
              </Field>
              <Field label="Deskripsi" error={fieldError(save.error, 'description')} full>
                {(p) => <input className="input" maxLength={500} disabled={!canManage} value={f.description} onChange={(e) => setF((s) => ({ ...s, description: e.target.value }))} {...p} />}
              </Field>
            </div>
          </Card>
          <Card title="Saldo per akun">
            <LinesEditor lines={lines} onChange={setLines} accounts={accounts} catalog={catalog} allowControl errorFor={(path) => fieldError(save.error, path)} />
          </Card>
          <div className="actions form-actions">
            {opening && canManage && <Button variant="danger" onClick={() => setDialog('cancel')}>Batalkan draf</Button>}
            {canManage && <Button type="submit" variant={opening && canPost && !dirty ? 'secondary' : 'primary'} loading={save.busy}>Simpan draf</Button>}
            {opening && canPost && <Button variant="primary" disabled={dirty || !totals.balanced} title={dirty ? 'Simpan perubahan terlebih dulu' : undefined} onClick={() => setDialog('post')}>Posting saldo awal</Button>}
          </div>
        </form>
      )}

      {dialog === 'post' && opening && (
        <ConfirmDialog title="Posting saldo awal" confirmLabel="Posting" busy={act.busy} error={act.error} onClose={() => setDialog(null)}
          message={<>Saldo awal sebesar <strong>{amountToApi(totals.debit)}</strong> pada tanggal cutover {formatDate(opening.cutover_date)} diposting sebagai satu jurnal dan tidak dapat diubah. Posting lain sebelum tanggal itu akan ditolak.</>}
          onConfirm={() => void step('post', undefined, 'Saldo awal diposting.')} />
      )}
      {dialog === 'cancel' && (
        <ConfirmDialog title="Batalkan draf saldo awal" confirmLabel="Batalkan draf" danger reasonRequired busy={act.busy} error={act.error} onClose={() => setDialog(null)}
          message="Draf dibatalkan dan tetap tersimpan sebagai riwayat. Anda dapat menyusun draf baru." onConfirm={(reason) => void step('cancel', { reason }, 'Draf saldo awal dibatalkan.')} />
      )}
    </>
  )
}

const snapshot = (f: object, lines: LineState[]) => JSON.stringify([f, lines.map(({ key: _key, ...rest }) => rest)])
