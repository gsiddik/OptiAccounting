import { Card, EmptyState, ErrorNotice, Loading, Meter, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { formatNumber } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { capacityLabels } from '../../lib/labels'
import type { CapacityUsage } from '../../lib/types'

export default function Usage() {
  const { data, loading, error, reload } = useResource(async () => (await api.get<{ data: CapacityUsage[] }>('/app/account/usage')).data.data, [])

  return (
    <>
      <PageHeader title="Penggunaan & Batas" description="Pemakaian kapasitas dibandingkan batas dari langganan. Pengguna nonaktif serta cabang dan unit nonaktif tidak dihitung." />
      {loading && !data ? <Loading /> : error || !data ? <ErrorNotice error={error} onRetry={reload} /> : data.length === 0 ? (
        <Card><EmptyState title="Belum ada batas kapasitas" /></Card>
      ) : (
        <div className="grid grid-3">
          {data.map((u) => (
            <Card key={u.code} title={capacityLabels[u.code] ?? u.code}>
              <div className="stack">
                <div style={{ fontSize: '1.6rem', fontWeight: 600 }}>
                  {formatNumber(u.used)}
                  <span className="muted" style={{ fontSize: '1rem', fontWeight: 400 }}> {u.limit === null ? 'terpakai' : `dari ${formatNumber(u.limit)}`}</span>
                </div>
                <Meter used={u.used} limit={u.limit} />
                <span className="muted">{u.remaining === null ? 'Tanpa batas pada langganan ini.' : u.remaining === 0 ? 'Batas tercapai. Tambahan baru ditolak.' : `Sisa ${formatNumber(u.remaining)}.`}</span>
              </div>
            </Card>
          ))}
        </div>
      )}
    </>
  )
}
