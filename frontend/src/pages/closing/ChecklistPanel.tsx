import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { Badge, Button, EmptyState, ErrorNotice, Loading } from '../../components/ui'
import { formatDate } from '../../lib/format'
import type { Resource } from '../../lib/hooks'
import { ItemDialog, type ItemAction } from './ChecklistDialogs'
import { CheckBadge, Facts, ItemBadge } from './components'
import { closingError } from './labels'
import type { Checklist, ChecklistItem } from './types'

/**
 * The checklist of one period. Manual items are completed, waived (with a reason) or reset by people who may manage the checklist; the system checks
 * below them are computed from the ledger and are read-only: there is no control that could pass one by hand.
 */
export function ChecklistPanel({ checklist, periodId, canManage, onChanged }: { checklist: Resource<Checklist>; periodId: string; canManage: boolean; onChanged: () => void }) {
  const [dialog, setDialog] = useState<{ action: ItemAction; item: ChecklistItem } | null>(null)
  const data = checklist.data
  if (checklist.error) return <ErrorNotice error={closingError(checklist.error)} onRetry={checklist.reload} />
  if (!data) return <Loading />

  const closed = data.period.status === 'CLOSED'
  const editable = canManage && !closed
  const { summary } = data
  const note = (i: ChecklistItem) => (i.status === 'WAIVED' ? i.waive_reason : i.notes)
  const when = (i: ChecklistItem) => (i.status === 'WAIVED' ? i.waived_at : i.status === 'COMPLETED' ? i.completed_at : null)

  return (
    <>
      <div className="card-body stack">
        <Facts rows={[['Butir wajib', summary.required], ['Wajib yang menunggu', summary.pending_required], ['Selesai', summary.completed], ['Dikecualikan', summary.waived]]} />
        {closed && <p className="muted">Daftar periksa periode yang sudah ditutup adalah bukti dan tidak dapat diubah. Buka kembali periode bila perlu mengubahnya.</p>}
      </div>
      {data.manual_items.length === 0 ? (
        <EmptyState title="Belum ada butir manual">Butir daftar periksa diatur di Kebijakan penutupan. Pemeriksaan sistem tetap berjalan otomatis.</EmptyState>
      ) : (
        <DataTable
          caption="Butir daftar periksa penutupan manual"
          rows={data.manual_items}
          rowKey={(i) => i.code}
          columns={[
            { header: 'Butir', primary: true, cell: (i) => <><strong>{i.title}</strong> <span className="muted">({i.code})</span> {i.required ? <Badge tone="info">Wajib</Badge> : <Badge>Opsional</Badge>}</> },
            { header: 'Status', cell: (i) => <ItemBadge status={i.status} /> },
            { header: 'Catatan', cell: (i) => (note(i) || when(i) ? <>{note(i)}{when(i) && <div className="muted">{formatDate(when(i))}</div>}</> : '—') },
            {
              header: 'Tindakan',
              actions: true,
              cell: (i) => editable && (
                <div className="toolbar">
                  {i.status === 'PENDING' ? (
                    <>
                      <Button size="sm" aria-label={`Selesaikan ${i.title}`} onClick={() => setDialog({ action: 'complete', item: i })}>Selesai</Button>
                      <Button size="sm" aria-label={`Kecualikan ${i.title}`} onClick={() => setDialog({ action: 'waive', item: i })}>Kecualikan</Button>
                    </>
                  ) : (
                    <Button size="sm" aria-label={`Setel ulang ${i.title}`} onClick={() => setDialog({ action: 'reset', item: i })}>Setel ulang</Button>
                  )}
                </div>
              ),
            },
          ]}
        />
      )}
      <div className="card-body">
        <h3>Pemeriksaan sistem</h3>
        <p className="muted">Dihitung otomatis dari buku besar dan modul lain. Tidak ada tombol untuk meluluskannya dengan tangan; hasilnya berubah hanya bila datanya berubah.</p>
      </div>
      <DataTable
        caption="Pemeriksaan sistem (hanya baca)"
        rows={data.system_checks}
        rowKey={(c) => c.code}
        columns={[
          { header: 'Pemeriksaan', primary: true, cell: (c) => <>{c.title} <span className="muted">({c.code})</span></> },
          { header: 'Hasil', cell: (c) => <CheckBadge result={c.result} /> },
          { header: 'Sumber', cell: () => <span className="muted">Otomatis, hanya baca</span> },
        ]}
      />
      {dialog && <ItemDialog action={dialog.action} periodId={periodId} item={dialog.item} onDone={onChanged} onClose={() => setDialog(null)} />}
    </>
  )
}
