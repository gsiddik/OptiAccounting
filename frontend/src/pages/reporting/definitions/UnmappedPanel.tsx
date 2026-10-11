import { useState } from 'react'
import { DataTable } from '../../../components/DataTable'
import { Button, Card, EmptyState, ErrorNotice, Loading } from '../../../components/ui'
import { api } from '../../../lib/api'
import { useResource } from '../../../lib/hooks'
import { Filters, Money } from '../../operational/shared'
import { ENDPOINTS } from './data'
import { accountTypeText, definitionError } from './labels'
import type { DefinitionDetail, UnmappedAccount } from './types'

/**
 * Posting accounts of this statement's types that no line covers, with the balance the server found. A DRAFT can map them straight from here;
 * a published definition only shows what it leaves out (they are part of the statement's integrity block).
 */
export function UnmappedPanel({ definition, canMap, refreshKey, onMap }: { definition: DefinitionDetail; canMap: boolean; refreshKey: number; onMap: (account: UnmappedAccount) => void }) {
  const [asOf, setAsOf] = useState('')
  const [onlyActive, setOnlyActive] = useState(true)
  const list = useResource(async () => {
    const params: Record<string, string | number> = {}
    if (asOf) params.as_of = asOf
    if (!onlyActive) params.only_with_balance = 0
    return (await api.get<{ data: UnmappedAccount[] }>(ENDPOINTS.unmapped(definition.id), { params })).data.data
  }, [definition.id, asOf, onlyActive, refreshKey])
  const rows = list.data ?? []

  return (
    <Card title="Akun belum dipetakan" flush>
      <div className="card-body">
        <Filters>
          <label className="inline-field">Per tanggal <input className="input" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} /></label>
          <label className="check"><input type="checkbox" checked={onlyActive} onChange={(e) => setOnlyActive(e.target.checked)} /> Hanya yang bersaldo atau beraktivitas</label>
        </Filters>
      </div>
      {list.loading && !list.data ? (
        <Loading />
      ) : list.error ? (
        <ErrorNotice error={definitionError(list.error)} onRetry={list.reload} />
      ) : rows.length === 0 ? (
        <EmptyState title="Semua akun sudah tercakup">Tidak ada akun posting {onlyActive ? 'bersaldo atau beraktivitas ' : ''}yang luput dari definisi ini.</EmptyState>
      ) : (
        <>
          <DataTable
            caption="Akun belum dipetakan"
            scroll
            rows={rows}
            rowKey={(a) => a.id}
            columns={[
              { header: 'Akun', primary: true, cell: (a) => <><span className="mono">{a.code}</span> · {a.name}{a.status !== 'ACTIVE' && <span className="muted"> · nonaktif</span>}</> },
              { header: 'Tipe', cell: (a) => accountTypeText[a.account_type] ?? a.account_type },
              { header: 'Saldo', align: 'right', cell: (a) => <Money value={a.balance} /> },
              { header: 'Aktivitas', cell: (a) => (a.has_activity ? 'Ada' : <span className="muted">Tidak ada</span>) },
              ...(canMap ? [{ header: 'Aksi', actions: true, cell: (a: UnmappedAccount) => <Button size="sm" aria-label={`Petakan akun ${a.code}`} onClick={() => onMap(a)}>Petakan</Button> }] : []),
            ]}
          />
          <p className="card-body muted preview-note">Saldo dan aktivitas dihitung server dari jurnal terposting dalam cakupan data Anda.</p>
        </>
      )}
    </Card>
  )
}
