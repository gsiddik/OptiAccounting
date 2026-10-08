import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, FormModal } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, EmptyState, ErrorNotice, Field, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { useAccountingAccess, type FiscalYear, type Period } from '../../lib/accounting'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { fieldError } from '../../lib/forms'
import { formatDate, todayIn } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'

type Pending =
  | { kind: 'open-year'; year: FiscalYear }
  | { kind: 'close-year'; year: FiscalYear }
  | { kind: 'delete-year'; year: FiscalYear }
  | { kind: 'close-period'; period: Period }
  | null

export default function FiscalCalendar() {
  const { canChange } = useAccountingAccess()
  const toast = useToast()
  const years = useResource(async () => (await api.get<{ data: FiscalYear[] }>('/app/accounting/fiscal-years')).data.data, [])
  const act = useAction()
  const [creating, setCreating] = useState(false)
  const [pending, setPending] = useState<Pending>(null)
  const [openAll, setOpenAll] = useState(true)
  const canManage = canChange('accounting.period.manage')
  const canClose = canChange('accounting.period.close')

  if (years.loading && !years.data) return <Loading />
  if (years.error || !years.data) return <ErrorNotice error={years.error} onRetry={years.reload} />

  async function perform(path: string, method: 'post' | 'delete', body: object | undefined, done: string) {
    const r = await act.run(() => (method === 'post' ? api.post(path, body) : api.delete(path)))
    if (r.ok) {
      setPending(null)
      toast.success(done)
      years.reload()
    }
  }

  const transition = (p: Period, to: 'open' | 'soft-close') => void perform(`/app/accounting/periods/${p.id}/${to}`, 'post', undefined, to === 'open' ? `Periode ${p.code} dibuka.` : `Periode ${p.code} ditutup sementara.`)

  return (
    <>
      <PageHeader
        title="Tahun fiskal & periode"
        description="Jurnal hanya dapat diposting ke periode yang terbuka. Periode yang ditutup bersifat final pada fase ini; pembukaan kembali yang terkendali tersedia di fase penutupan."
        actions={canManage && <Button variant="primary" onClick={() => setCreating(true)}>Tahun fiskal baru</Button>}
      />
      {act.error != null && !pending && <Banner tone="bad">{describeError(act.error)}</Banner>}
      {years.data.length === 0 && <Card><EmptyState title="Belum ada tahun fiskal">Buat tahun fiskal, lalu buka agar jurnal dapat diposting.</EmptyState></Card>}

      {years.data.map((y) => (
        <Card
          key={y.id}
          flush
          title={<>{y.code} · {y.name} <StatusBadge status={y.status} /></>}
          actions={
            <>
              <span className="muted">{formatDate(y.start_date)} – {formatDate(y.end_date)}</span>
              {canManage && y.status === 'DRAFT' && <Button size="sm" variant="primary" onClick={() => { setOpenAll(true); setPending({ kind: 'open-year', year: y }) }}>Buka tahun</Button>}
              {canManage && y.status === 'DRAFT' && <Button size="sm" variant="danger" onClick={() => setPending({ kind: 'delete-year', year: y })}>Hapus</Button>}
              {canClose && y.status === 'OPEN' && <Button size="sm" onClick={() => setPending({ kind: 'close-year', year: y })}>Tutup tahun</Button>}
            </>
          }
        >
          <DataTable
            caption={`Periode ${y.code}`}
            rows={y.periods ?? []}
            rowKey={(p) => p.id}
            columns={[
              { header: 'Periode', primary: true, cell: (p) => <><span className="mono">{p.code}</span> · {p.name}</> },
              { header: 'Mulai', cell: (p) => formatDate(p.start_date) },
              { header: 'Selesai', cell: (p) => formatDate(p.end_date) },
              { header: 'Status', cell: (p) => <StatusBadge status={p.status} /> },
              {
                header: 'Aksi',
                actions: true,
                cell: (p) => (
                  <div className="actions">
                    {canManage && y.status === 'OPEN' && (p.status === 'FUTURE' || p.status === 'SOFT_CLOSED') && <Button size="sm" onClick={() => transition(p, 'open')}>Buka</Button>}
                    {canManage && p.status === 'OPEN' && <Button size="sm" onClick={() => transition(p, 'soft-close')}>Tutup sementara</Button>}
                    {canClose && (p.status === 'OPEN' || p.status === 'SOFT_CLOSED') && <Button size="sm" variant="danger" onClick={() => setPending({ kind: 'close-period', period: p })}>Tutup</Button>}
                  </div>
                ),
              },
            ]}
          />
        </Card>
      ))}

      {creating && <NewYear onClose={() => setCreating(false)} onDone={() => { setCreating(false); years.reload(); toast.success('Tahun fiskal dibuat sebagai draf.') }} />}

      {pending?.kind === 'open-year' && (
        <ConfirmDialog title={`Buka tahun fiskal ${pending.year.code}`} confirmLabel="Buka tahun" busy={act.busy} error={act.error} onClose={() => setPending(null)}
          message={<><p>Tahun fiskal yang dibuka dapat menerima jurnal.</p><label className="check" style={{ marginTop: 8 }}><input type="checkbox" checked={openAll} onChange={(e) => setOpenAll(e.target.checked)} /> Buka semua periode sekarang</label></>}
          onConfirm={() => void perform(`/app/accounting/fiscal-years/${pending.year.id}/open`, 'post', { open_periods: openAll }, `Tahun fiskal ${pending.year.code} dibuka.`)} />
      )}
      {pending?.kind === 'close-year' && (
        <ConfirmDialog title={`Tutup tahun fiskal ${pending.year.code}`} confirmLabel="Tutup tahun" danger busy={act.busy} error={act.error} onClose={() => setPending(null)}
          message="Semua periode harus sudah ditutup. Penutupan tahun bersifat final pada fase ini." onConfirm={() => void perform(`/app/accounting/fiscal-years/${pending.year.id}/close`, 'post', undefined, `Tahun fiskal ${pending.year.code} ditutup.`)} />
      )}
      {pending?.kind === 'delete-year' && (
        <ConfirmDialog title={`Hapus tahun fiskal ${pending.year.code}`} confirmLabel="Hapus" danger busy={act.busy} error={act.error} onClose={() => setPending(null)}
          message="Hanya tahun fiskal draf yang belum dipakai yang dapat dihapus." onConfirm={() => void perform(`/app/accounting/fiscal-years/${pending.year.id}`, 'delete', undefined, `Tahun fiskal ${pending.year.code} dihapus.`)} />
      )}
      {pending?.kind === 'close-period' && (
        <ConfirmDialog title={`Tutup periode ${pending.period.code}`} confirmLabel="Tutup periode" danger busy={act.busy} error={act.error} onClose={() => setPending(null)}
          message="Periode yang ditutup tidak dapat dibuka kembali pada fase ini dan tidak menerima posting apa pun. Semua jurnal yang menunggu persetujuan atau posting harus diselesaikan lebih dulu."
          onConfirm={() => void perform(`/app/accounting/periods/${pending.period.id}/close`, 'post', undefined, `Periode ${pending.period.code} ditutup.`)} />
      )}
    </>
  )
}

function NewYear({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { busy, error, run } = useAction()
  const { tenant } = useCapabilities()
  const year = Number((tenant?.business_date ?? todayIn()).slice(0, 4))
  const [f, setF] = useState({ code: `FY${year}`, name: `Tahun fiskal ${year}`, start_date: `${year}-01-01`, months: '12' })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }))

  async function submit() {
    const r = await run(() => api.post('/app/accounting/fiscal-years', { code: f.code.trim(), name: f.name.trim(), start_date: f.start_date, months: Number(f.months) }))
    if (r.ok) onDone()
  }

  return (
    <FormModal title="Tahun fiskal baru" busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <div className="form-grid">
        <Field label="Kode" error={fieldError(error, 'code')} hint="Dipakai pada nomor jurnal, misalnya FY2027.">
          {(p) => <input className="input mono" required maxLength={30} value={f.code} onChange={set('code')} {...p} />}
        </Field>
        <Field label="Nama" error={fieldError(error, 'name')}>{(p) => <input className="input" required maxLength={255} value={f.name} onChange={set('name')} {...p} />}</Field>
        <Field label="Mulai" error={fieldError(error, 'start_date')} hint="Selalu tanggal 1.">{(p) => <input className="input" type="date" required value={f.start_date} onChange={set('start_date')} {...p} />}</Field>
        <Field label="Jumlah periode (bulan)" error={fieldError(error, 'months')} hint="1 sampai 18.">{(p) => <input className="input" type="number" min={1} max={18} value={f.months} onChange={set('months')} {...p} />}</Field>
      </div>
    </FormModal>
  )
}
