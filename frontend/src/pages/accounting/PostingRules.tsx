import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, FormModal, Modal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, Pagination, StatusBadge, Tabs } from '../../components/ui'
import { normalizeAmountInput, useAccountingAccess, type AccountingEvent, type AccountRole, type EventType, type PostingRule, type RuleLine, type Simulation } from '../../lib/accounting'
import { eventComponentLabels, sideLabels } from '../../lib/accountingLabels'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { formatDate, todayIn } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { Money } from './shared'
import { useDimensions } from './data'

type Tab = 'rules' | 'events'
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number }

export default function PostingRules() {
  const [tab, setTab] = useState<Tab>('rules')
  return (
    <>
      <PageHeader title="Aturan posting" description="Aturan menyatakan peran akun (misalnya Piutang usaha), bukan nomor akun. Aturan yang sudah terbit tidak diubah; perubahan dibuat sebagai versi baru dengan tanggal berlaku." />
      <Card flush>
        <Tabs tabs={[{ id: 'rules', label: 'Aturan' }, { id: 'events', label: 'Log kejadian' }]} value={tab} onChange={setTab} />
        {tab === 'rules' ? <Rules /> : <Events />}
      </Card>
    </>
  )
}

function Rules() {
  const toast = useToast()
  const { tenant } = useCapabilities()
  const { canChange } = useAccountingAccess()
  const manage = canChange('accounting.posting_rule.manage')
  const simulateAllowed = canChange('accounting.posting_rule.view')
  const rules = useResource(async () => (await api.get<{ data: PostingRule[] }>('/app/accounting/posting-rules')).data.data, [])
  const types = useResource(async () => (await api.get<{ data: EventType[] }>('/app/accounting/event-types')).data.data, [])
  const roles = useResource(async () => {
    try {
      return (await api.get<{ roles: AccountRole[] }>('/app/accounting/account-mappings')).data.roles
    } catch {
      return [] as AccountRole[]
    }
  }, [])
  const act = useAction()
  const [dialog, setDialog] = useState<null | { kind: 'edit'; rule: PostingRule | 'new' } | { kind: 'view' | 'publish' | 'archive' | 'delete' | 'simulate' | 'version'; rule: PostingRule }>(null)
  const today = tenant?.business_date ?? todayIn()

  if ((rules.loading && !rules.data) || (types.loading && !types.data)) return <Loading />
  if (rules.error || !rules.data) return <ErrorNotice error={rules.error} onRetry={rules.reload} />
  const eventTypes = types.data ?? []
  const typeName = (code: string) => eventTypes.find((t) => t.code === code)?.name ?? code
  const hasDraft = (code: string) => rules.data!.some((r) => r.code === code && r.status === 'DRAFT')

  async function simple(path: string, method: 'post' | 'delete', body: object | undefined, done: string, after?: (data: PostingRule) => void) {
    const r = await act.run(async () => (method === 'post' ? (await api.post<PostingRule>(path, body)).data : (await api.delete(path), null)))
    if (r.ok) {
      setDialog(null)
      toast.success(done)
      rules.reload()
      if (r.value && after) after(r.value)
    }
  }

  return (
    <>
      <div className="card-head">
        <span className="muted">Satu jenis kejadian hanya punya satu versi yang berlaku pada satu tanggal.</span>
        {manage && <Button size="sm" variant="primary" onClick={() => setDialog({ kind: 'edit', rule: 'new' })}>Aturan baru</Button>}
      </div>
      {rules.data.length === 0 ? (
        <EmptyState title="Belum ada aturan posting">Aturan dipakai untuk membukukan kejadian bisnis dari sistem lain secara otomatis.</EmptyState>
      ) : (
        <DataTable
          caption="Aturan posting"
          rows={rules.data}
          rowKey={(r) => r.id}
          columns={[
            { header: 'Aturan', primary: true, cell: (r) => <><strong>{r.name}</strong><div className="muted"><span className="mono">{r.code}</span> · versi {r.version}</div></> },
            { header: 'Jenis kejadian', cell: (r) => typeName(r.event_type) },
            { header: 'Status', cell: (r) => <StatusBadge status={r.status} /> },
            { header: 'Berlaku', cell: (r) => (r.effective_from ? `${formatDate(r.effective_from)} – ${r.effective_to ? formatDate(r.effective_to) : 'seterusnya'}` : <span className="muted">—</span>) },
            {
              header: 'Aksi',
              actions: true,
              cell: (r) => (
                <div className="actions">
                  <Button size="sm" onClick={() => setDialog({ kind: 'view', rule: r })}>Lihat</Button>
                  {simulateAllowed && <Button size="sm" onClick={() => setDialog({ kind: 'simulate', rule: r })}>Simulasi</Button>}
                  {manage && r.status === 'DRAFT' && <Button size="sm" onClick={() => setDialog({ kind: 'edit', rule: r })}>Ubah</Button>}
                  {manage && r.status === 'DRAFT' && <Button size="sm" variant="primary" onClick={() => setDialog({ kind: 'publish', rule: r })}>Terbitkan</Button>}
                  {manage && r.status === 'DRAFT' && <Button size="sm" variant="danger" onClick={() => setDialog({ kind: 'delete', rule: r })}>Hapus</Button>}
                  {manage && r.status === 'PUBLISHED' && <Button size="sm" onClick={() => setDialog({ kind: 'archive', rule: r })}>Arsipkan</Button>}
                  {manage && r.status !== 'DRAFT' && !hasDraft(r.code) && <Button size="sm" onClick={() => setDialog({ kind: 'version', rule: r })}>Versi baru</Button>}
                </div>
              ),
            },
          ]}
        />
      )}

      {dialog?.kind === 'view' && <RuleView rule={dialog.rule} onClose={() => setDialog(null)} />}
      {dialog?.kind === 'edit' && <RuleForm rule={dialog.rule === 'new' ? null : dialog.rule} eventTypes={eventTypes} roles={roles.data ?? []} onClose={() => setDialog(null)} onDone={() => { setDialog(null); rules.reload(); toast.success('Aturan disimpan sebagai draf.') }} />}
      {dialog?.kind === 'simulate' && <SimulateDialog rule={dialog.rule} onClose={() => setDialog(null)} />}
      {dialog?.kind === 'publish' && <DateDialog title={`Terbitkan ${dialog.rule.name}`} label="Berlaku mulai" initial={today} required hint="Setelah terbit, baris aturan tidak dapat diubah." confirm="Terbitkan" busy={act.busy} error={act.error} onClose={() => setDialog(null)} onConfirm={(d) => void simple(`/app/accounting/posting-rules/${dialog.rule.id}/publish`, 'post', { effective_from: d }, 'Aturan diterbitkan.')} />}
      {dialog?.kind === 'archive' && <DateDialog title={`Arsipkan ${dialog.rule.name}`} label="Berlaku sampai (opsional)" initial="" hint="Kosongkan untuk menghentikan mulai hari ini." confirm="Arsipkan" busy={act.busy} error={act.error} onClose={() => setDialog(null)} onConfirm={(d) => void simple(`/app/accounting/posting-rules/${dialog.rule.id}/archive`, 'post', d ? { effective_to: d } : {}, 'Aturan diarsipkan.')} />}
      {dialog?.kind === 'delete' && <ConfirmDialog title="Hapus draf aturan" confirmLabel="Hapus" danger busy={act.busy} error={act.error} message={`Hapus draf ${dialog.rule.name} versi ${dialog.rule.version}?`} onClose={() => setDialog(null)} onConfirm={() => void simple(`/app/accounting/posting-rules/${dialog.rule.id}`, 'delete', undefined, 'Draf aturan dihapus.')} />}
      {dialog?.kind === 'version' && <ConfirmDialog title="Buat versi baru" confirmLabel="Buat versi baru" busy={act.busy} error={act.error} message={`Salin ${dialog.rule.name} menjadi draf versi berikutnya. Versi yang sedang berlaku tidak berubah sampai draf diterbitkan.`} onClose={() => setDialog(null)} onConfirm={() => void simple(`/app/accounting/posting-rules/${dialog.rule.id}/new-version`, 'post', undefined, 'Draf versi baru dibuat.', (draft) => setDialog({ kind: 'edit', rule: draft }))} />}
    </>
  )
}

function RuleView({ rule, onClose }: { rule: PostingRule; onClose: () => void }) {
  return (
    <Modal title={`${rule.name} · versi ${rule.version}`} onClose={onClose} wide>
      <div className="modal-body">
        {rule.description && <p>{rule.description}</p>}
        <DataTable
          caption="Baris aturan"
          rows={rule.lines}
          rowKey={(l) => l.id ?? String(l.line_number)}
          columns={[
            { header: 'Sisi', cell: (l) => sideLabels[l.side] },
            { header: 'Peran akun', primary: true, cell: (l) => <span className="mono">{l.account_role}</span> },
            { header: 'Komponen', cell: (l) => eventComponentLabels[l.amount_key] ?? l.amount_key },
            { header: 'Lewati bila nol', cell: (l) => (l.skip_if_zero ? 'Ya' : 'Tidak') },
          ]}
        />
      </div>
    </Modal>
  )
}

const blankLine = (side: 'DEBIT' | 'CREDIT'): RuleLine => ({ side, account_role: '', amount_key: '', skip_if_zero: false, description: null })

function RuleForm({ rule, eventTypes, roles, onClose, onDone }: { rule: PostingRule | null; eventTypes: EventType[]; roles: AccountRole[]; onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ code: rule?.code ?? '', event_type: rule?.event_type ?? eventTypes[0]?.code ?? '', name: rule?.name ?? '', description: rule?.description ?? '' })
  const [lines, setLines] = useState<RuleLine[]>(() => (rule && rule.lines.length > 0 ? rule.lines.map(({ side, account_role, amount_key, skip_if_zero, description }) => ({ side, account_role, amount_key, skip_if_zero, description })) : [blankLine('DEBIT'), blankLine('CREDIT')]))
  const components = useMemo(() => eventTypes.find((t) => t.code === f.event_type)?.components ?? [], [eventTypes, f.event_type])
  const patch = (i: number, change: Partial<RuleLine>) => setLines((ls) => ls.map((l, n) => (n === i ? { ...l, ...change } : l)))

  async function submit() {
    const body = { name: f.name.trim(), description: f.description.trim() || null, lines: lines.map((l) => ({ ...l, description: l.description?.trim() || null })) }
    const r = await run(() => (rule ? api.patch(`/app/accounting/posting-rules/${rule.id}`, body) : api.post('/app/accounting/posting-rules', { ...body, code: f.code.trim(), event_type: f.event_type })))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={rule ? `Ubah draf ${rule.code} versi ${rule.version}` : 'Aturan posting baru'} busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <div className="form-grid">
        <Field label="Kode" error={fieldError(error, 'code')} hint={rule ? 'Kode tidak dapat diubah.' : 'Huruf, angka, titik, atau strip.'}>{(p) => <input className="input mono" required maxLength={60} disabled={!!rule} value={f.code} onChange={(e) => setF((s) => ({ ...s, code: e.target.value }))} {...p} />}</Field>
        <Field label="Jenis kejadian" error={fieldError(error, 'event_type')}>
          {(p) => (
            <select className="select" disabled={!!rule} value={f.event_type} onChange={(e) => { setF((s) => ({ ...s, event_type: e.target.value })); setLines((ls) => ls.map((l) => ({ ...l, amount_key: '' }))) }} {...p}>
              {eventTypes.map((t) => <option key={t.code} value={t.code}>{t.name}</option>)}
            </select>
          )}
        </Field>
        <Field label="Nama" error={fieldError(error, 'name')} full>{(p) => <input className="input" required maxLength={255} value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} {...p} />}</Field>
        <Field label="Keterangan" error={fieldError(error, 'description')} full>{(p) => <input className="input" maxLength={500} value={f.description} onChange={(e) => setF((s) => ({ ...s, description: e.target.value }))} {...p} />}</Field>
      </div>

      <div className="rule-lines">
        {lines.map((l, i) => (
          <div className="rule-line" key={i} role="group" aria-label={`Baris aturan ${i + 1}`}>
            <Field label="Sisi" error={fieldError(error, `lines.${i}.side`)}>
              {(p) => (
                <select className="select" value={l.side} onChange={(e) => patch(i, { side: e.target.value as RuleLine['side'] })} {...p}>
                  <option value="DEBIT">Debit</option>
                  <option value="CREDIT">Kredit</option>
                </select>
              )}
            </Field>
            <Field label="Peran akun" error={fieldError(error, `lines.${i}.account_role`)}>
              {(p) =>
                roles.length > 0 ? (
                  <select className="select" value={l.account_role} onChange={(e) => patch(i, { account_role: e.target.value })} {...p}>
                    <option value="">Pilih peran…</option>
                    {roles.map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}
                  </select>
                ) : (
                  <input className="input mono" value={l.account_role} onChange={(e) => patch(i, { account_role: e.target.value })} {...p} />
                )
              }
            </Field>
            <Field label="Komponen jumlah" error={fieldError(error, `lines.${i}.amount_key`)}>
              {(p) => (
                <select className="select" value={l.amount_key} onChange={(e) => patch(i, { amount_key: e.target.value })} {...p}>
                  <option value="">Pilih komponen…</option>
                  {components.map((c) => <option key={c} value={c}>{eventComponentLabels[c] ?? c}</option>)}
                </select>
              )}
            </Field>
            <label className="check"><input type="checkbox" checked={l.skip_if_zero} onChange={(e) => patch(i, { skip_if_zero: e.target.checked })} /> Lewati bila nol</label>
            <Button variant="ghost" size="sm" aria-label={`Hapus baris aturan ${i + 1}`} disabled={lines.length <= 2} onClick={() => setLines((ls) => ls.filter((_, n) => n !== i))}>✕</Button>
          </div>
        ))}
        <div className="actions">
          <Button size="sm" onClick={() => setLines((ls) => [...ls, blankLine('DEBIT')])}>Tambah baris</Button>
        </div>
        {fieldError(error, 'lines') && <span className="error">{fieldError(error, 'lines')}</span>}
      </div>
    </FormModal>
  )
}

function DateDialog({ title, label, initial, hint, required, confirm, busy, error, onClose, onConfirm }: { title: string; label: string; initial: string; hint?: string; required?: boolean; confirm: string; busy: boolean; error: unknown; onClose: () => void; onConfirm: (date: string) => void }) {
  const [date, setDate] = useState(initial)
  return (
    <FormModal title={title} submitLabel={confirm} busy={busy} error={error} onClose={onClose} onSubmit={() => (!required || date) && onConfirm(date)}>
      <Field label={label} hint={hint} error={fieldError(error, 'effective_from') ?? fieldError(error, 'effective_to')}>
        {(p) => <input className="input" type="date" required={required} value={date} onChange={(e) => setDate(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}

function SimulateDialog({ rule, onClose }: { rule: PostingRule; onClose: () => void }) {
  const { catalog } = useDimensions()
  const { busy, error, run } = useAction()
  const keys = useMemo(() => [...new Set(rule.lines.map((l) => l.amount_key))], [rule.lines])
  const [amounts, setAmounts] = useState<Record<string, string>>({})
  const [scope, setScope] = useState({ branch_id: '', business_unit_id: '' })
  const [result, setResult] = useState<Simulation | null>(null)

  async function submit() {
    const payload = Object.fromEntries(keys.map((k) => [k, normalizeAmountInput(amounts[k] ?? '') || '0.0000']))
    const r = await run(async () => (await api.post<Simulation>(`/app/accounting/posting-rules/${rule.id}/simulate`, { payload, branch_id: scope.branch_id || null, business_unit_id: scope.business_unit_id || null })).data)
    if (r.ok) setResult(r.value)
  }

  return (
    <FormModal title={`Simulasi ${rule.name}`} submitLabel="Simulasikan" busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose} wide>
      <p className="muted">Simulasi tidak membuat jurnal dan tidak menyimpan apa pun. Hasilnya memakai pemetaan akun yang berlaku sekarang.</p>
      <div className="form-grid">
        {keys.map((k) => (
          <Field key={k} label={eventComponentLabels[k] ?? k} error={fieldError(error, `payload.${k}`)}>
            {(p) => <input className="input amount" inputMode="decimal" autoComplete="off" placeholder="0" value={amounts[k] ?? ''} onChange={(e) => setAmounts((s) => ({ ...s, [k]: e.target.value }))} {...p} />}
          </Field>
        ))}
        {catalog.branches.length > 0 && (
          <Field label="Cabang (opsional)">
            {(p) => (
              <select className="select" value={scope.branch_id} onChange={(e) => setScope({ branch_id: e.target.value, business_unit_id: '' })} {...p}>
                <option value="">Tanpa cabang</option>
                {catalog.branches.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}
              </select>
            )}
          </Field>
        )}
      </div>
      {result && (
        <>
          <Banner tone={result.balanced ? 'ok' : 'bad'}>{result.balanced ? 'Jurnal hasil simulasi seimbang.' : 'Jurnal hasil simulasi tidak seimbang; perbaiki aturan.'}</Banner>
          <DataTable
            caption="Hasil simulasi"
            rows={result.lines}
            rowKey={(l) => String(l.line)}
            columns={[
              { header: 'Akun', primary: true, cell: (l) => <><span className="mono">{l.account_code}</span> · {l.account_name}<div className="muted">{l.account_role}</div></> },
              { header: 'Debit', align: 'right', cell: (l) => (l.side === 'DEBIT' ? <Money value={l.amount} /> : <span className="muted">—</span>) },
              { header: 'Kredit', align: 'right', cell: (l) => (l.side === 'CREDIT' ? <Money value={l.amount} /> : <span className="muted">—</span>) },
            ]}
          />
          <div className="table-total"><span>Total</span><span>Debit <Money value={result.total_debit} strong /></span><span>Kredit <Money value={result.total_credit} strong /></span></div>
        </>
      )}
    </FormModal>
  )
}

function Events() {
  const [f, setF] = useState({ status: '', event_type: '' })
  const [page, setPage] = useState(1)
  const types = useResource(async () => (await api.get<{ data: EventType[] }>('/app/accounting/event-types')).data.data, [])
  const events = useResource(async () => {
    const params: Record<string, string | number> = { page }
    if (f.status) params.status = f.status
    if (f.event_type) params.event_type = f.event_type
    return (await api.get<Page<AccountingEvent>>('/app/accounting/accounting-events', { params })).data
  }, [page, f.status, f.event_type])

  return (
    <>
      <div className="card-body">
        <div className="filters">
          <select className="select" aria-label="Status kejadian" value={f.status} onChange={(e) => { setF((s) => ({ ...s, status: e.target.value })); setPage(1) }}>
            <option value="">Semua status</option>
            <option value="POSTED">Terposting</option>
            <option value="FAILED">Gagal</option>
            <option value="PENDING">Menunggu</option>
          </select>
          <select className="select" aria-label="Jenis kejadian" value={f.event_type} onChange={(e) => { setF((s) => ({ ...s, event_type: e.target.value })); setPage(1) }}>
            <option value="">Semua jenis</option>
            {(types.data ?? []).map((t) => <option key={t.code} value={t.code}>{t.name}</option>)}
          </select>
        </div>
      </div>
      {events.loading && !events.data ? (
        <Loading />
      ) : events.error || !events.data ? (
        <ErrorNotice error={events.error} onRetry={events.reload} />
      ) : events.data.data.length === 0 ? (
        <EmptyState title="Belum ada kejadian">Kejadian bisnis dari sistem lain tercatat di sini beserta hasil posting atau alasan kegagalannya.</EmptyState>
      ) : (
        <>
          <DataTable
            caption="Log kejadian akuntansi"
            rows={events.data.data}
            rowKey={(e) => e.id}
            columns={[
              { header: 'Kejadian', primary: true, cell: (e) => <><strong>{e.event_type}</strong><div className="muted">{e.source_type} · <span className="mono">{e.source_id}</span></div></> },
              { header: 'Tujuan', cell: (e) => e.posting_purpose },
              { header: 'Tanggal posting', cell: (e) => formatDate(e.posting_date) },
              { header: 'Status', cell: (e) => <StatusBadge status={e.status} /> },
              { header: 'Hasil', cell: (e) => (e.journal_entry_id ? <Link to={`/app/akuntansi/jurnal/${e.journal_entry_id}`}>Lihat jurnal</Link> : e.failure_code ? <span title={e.failure_message ?? undefined}>{e.failure_code}</span> : <span className="muted">—</span>) },
              { header: 'Percobaan', align: 'right', cell: (e) => e.attempts },
            ]}
          />
          <Pagination page={events.data.current_page} lastPage={events.data.last_page} total={events.data.total} onPage={setPage} />
        </>
      )}
    </>
  )
}
