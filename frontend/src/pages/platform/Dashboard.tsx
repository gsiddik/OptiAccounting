import { Link } from 'react-router-dom'
import { Card, ErrorNotice, Loading, PageHeader, Stat } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDateTime } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import type { AuditEntry, Paginated, Tenant, TenantStatus } from '../../lib/types'

const STATUSES: TenantStatus[] = ['ACTIVE', 'DRAFT', 'SUSPENDED', 'INACTIVE', 'TERMINATED']

export default function PlatformDashboard() {
  const { can } = useCapabilities()

  const counts = useResource(async () => {
    if (!can('platform.tenant.view')) return null
    const pages = await Promise.all(STATUSES.map((s) => api.get<Paginated<Tenant>>('/platform/tenants', { params: { status: s } })))
    return Object.fromEntries(STATUSES.map((s, i) => [s, pages[i].data.total])) as Record<TenantStatus, number>
  }, [])

  const audit = useResource(async () => (can('platform.audit.view') ? (await api.get<Paginated<AuditEntry>>('/platform/audit-logs')).data.data.slice(0, 6) : null), [])

  return (
    <>
      <PageHeader title="Dashboard platform" description="Ringkasan tenant dan aktivitas terbaru di seluruh platform." />

      {can('platform.tenant.view') && (
        <section aria-label="Ringkasan tenant">
          {counts.loading && !counts.data ? (
            <Loading />
          ) : counts.error ? (
            <ErrorNotice error={counts.error} onRetry={counts.reload} />
          ) : counts.data ? (
            <div className="grid grid-4">
              <Stat label="Tenant aktif" value={counts.data.ACTIVE} />
              <Stat label="Draf" value={counts.data.DRAFT} hint="Belum diaktifkan" />
              <Stat label="Ditangguhkan" value={counts.data.SUSPENDED} />
              <Stat label="Nonaktif / dihentikan" value={counts.data.INACTIVE + counts.data.TERMINATED} />
            </div>
          ) : null}
        </section>
      )}

      <div className="grid grid-2">
        <Card title="Pintasan">
          <div className="stack" style={{ gap: 8 }}>
            {can('platform.tenant.view') && <Link to="/platform/tenants">Kelola tenant dan langganan</Link>}
            {can('platform.module.view') && <Link to="/platform/modules">Katalog modul, dependensi, dan fitur</Link>}
            {can('platform.bundle.view') && <Link to="/platform/bundles">Paket komersial</Link>}
            {can('platform.user.view') && <Link to="/platform/operators">Operator dan peran platform</Link>}
          </div>
        </Card>

        {can('platform.audit.view') && (
          <Card title="Aktivitas terbaru" actions={<Link to="/platform/audit">Semua audit</Link>} flush>
            {audit.loading && !audit.data ? (
              <Loading />
            ) : audit.error ? (
              <ErrorNotice error={audit.error} onRetry={audit.reload} />
            ) : (
              <ul style={{ listStyle: 'none', margin: 0, padding: '4px 20px 12px' }}>
                {(audit.data ?? []).map((a) => (
                  <li key={a.id} style={{ padding: '8px 0', borderBottom: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: '2px 12px' }}>
                    <span className="mono" style={{ overflowWrap: 'anywhere', minWidth: 0 }}>{a.action}</span>
                    <span className="muted nowrap">{formatDateTime(a.occurred_at)}</span>
                  </li>
                ))}
                {audit.data?.length === 0 && <li className="muted" style={{ padding: 12 }}>Belum ada aktivitas.</li>}
              </ul>
            )}
          </Card>
        )}
      </div>
    </>
  )
}
