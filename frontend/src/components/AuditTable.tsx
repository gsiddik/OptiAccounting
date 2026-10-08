import { useState } from 'react'
import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { useDebounced, useResource } from '../lib/hooks'
import type { AuditEntry, Paginated } from '../lib/types'
import { DataTable } from './DataTable'
import { Modal } from './Modal'
import { Badge, Button, EmptyState, ErrorNotice, Loading, Pagination } from './ui'

const ACTOR: Record<string, string> = { platform: 'Operator platform', tenant: 'Pengguna organisasi', system: 'Sistem' }

/** Append-only audit trail viewer; `endpoint` decides the scope (platform sees all tenants, tenant sees its own). */
export function AuditTable({ endpoint, params = {} }: { endpoint: string; params?: Record<string, string> }) {
  const [page, setPage] = useState(1)
  const [action, setAction] = useState('')
  const debounced = useDebounced(action)
  const [selected, setSelected] = useState<AuditEntry | null>(null)
  const query = JSON.stringify(params)

  const { data, loading, error, reload } = useResource(
    async () => (await api.get<Paginated<AuditEntry>>(endpoint, { params: { ...params, page, ...(debounced ? { action: debounced } : {}) } })).data,
    [endpoint, query, page, debounced],
  )

  return (
    <div>
      <div className="card-head">
        <div className="toolbar">
          <label htmlFor="audit-action" className="sr-only">Filter aksi</label>
          <input id="audit-action" className="input" placeholder="Filter aksi, mis. branch atau role" value={action} onChange={(e) => { setAction(e.target.value); setPage(1) }} />
        </div>
        <Button size="sm" onClick={reload}>Muat ulang</Button>
      </div>
      {loading && !data ? (
        <Loading />
      ) : error ? (
        <ErrorNotice error={error} onRetry={reload} />
      ) : data && data.data.length === 0 ? (
        <EmptyState title="Belum ada catatan audit">Aktivitas yang tercatat akan muncul di sini.</EmptyState>
      ) : data ? (
        <>
          <DataTable
            caption="Catatan audit"
            rows={data.data}
            rowKey={(r) => r.id}
            columns={[
              { header: 'Waktu', cell: (r) => <span className="nowrap">{formatDateTime(r.occurred_at)}</span> },
              { header: 'Aksi', cell: (r) => <span className="mono">{r.action}</span> },
              { header: 'Pelaku', cell: (r) => <Badge tone={r.actor_scope === 'system' ? 'neutral' : 'info'}>{ACTOR[r.actor_scope] ?? r.actor_scope}</Badge> },
              { header: 'Objek', cell: (r) => r.resource_type },
              { header: 'Rincian', actions: true, cell: (r) => <Button size="sm" onClick={() => setSelected(r)}>Lihat</Button> },
            ]}
          />
          <Pagination page={data.current_page} lastPage={data.last_page} total={data.total} onPage={setPage} />
        </>
      ) : null}

      {selected && (
        <Modal title="Rincian audit" wide onClose={() => setSelected(null)} footer={<Button onClick={() => setSelected(null)}>Tutup</Button>}>
          <div className="modal-body">
            <dl className="facts">
              <div><dt>Aksi</dt><dd className="mono">{selected.action}</dd></div>
              <div><dt>Waktu</dt><dd>{formatDateTime(selected.occurred_at)}</dd></div>
              <div><dt>ID objek</dt><dd className="mono">{selected.resource_id ?? '—'}</dd></div>
              <div><dt>ID permintaan</dt><dd className="mono">{selected.context?.request_id ?? '—'}</dd></div>
              <div><dt>Alamat IP</dt><dd className="mono">{selected.context?.ip ?? '—'}</dd></div>
            </dl>
            {selected.changes ? (
              <div className="grid grid-2">
                <div><h3>Sebelum</h3><pre className="mono" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{JSON.stringify(selected.changes.before, null, 2) ?? '—'}</pre></div>
                <div><h3>Sesudah</h3><pre className="mono" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{JSON.stringify(selected.changes.after, null, 2) ?? '—'}</pre></div>
              </div>
            ) : (
              <span className="muted">Tidak ada perubahan data yang tercatat.</span>
            )}
          </div>
        </Modal>
      )}
    </div>
  )
}
