import { Link } from 'react-router-dom'
import { Badge, Banner, Card, ErrorNotice, Loading, Meter, PageHeader, Stat, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useCapabilities } from '../../lib/capabilities'
import { formatDate, formatNumber } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { capacityLabels, statusLabel } from '../../lib/labels'
import type { CapacityUsage } from '../../lib/types'

type ModuleRow = { module: { code: string; name: string }; current: boolean; effective_mode: string }

export default function TenantDashboard() {
  const { can, tenant } = useCapabilities()
  const canSeeAccount = can('account.subscription.view')

  const usage = useResource(async () => (canSeeAccount ? (await api.get<{ data: CapacityUsage[] }>('/app/account/usage')).data.data : null), [canSeeAccount])
  const modules = useResource(async () => (canSeeAccount ? (await api.get<{ data: ModuleRow[] }>('/app/account/modules')).data.data.filter((m) => m.current) : null), [canSeeAccount])

  if (!tenant) return <Loading />

  return (
    <>
      <PageHeader title={tenant.tenant.name} description={`Kode ${tenant.tenant.code} · zona waktu ${tenant.tenant.timezone} · mata uang ${tenant.tenant.default_currency}`} />

      <div className="grid grid-4">
        <Stat label="Status organisasi" value={<StatusBadge status={tenant.tenant.status} />} />
        <Stat label="Langganan" value={<StatusBadge status={tenant.subscription.status} />} hint={tenant.subscription.ends_on ? `Berakhir ${formatDate(tenant.subscription.ends_on)}` : 'Tanpa tanggal berakhir'} />
        <Stat label="Tanggal bisnis" value={formatDate(tenant.business_date)} hint="Menurut zona waktu organisasi" />
        <Stat label="Modul aktif" value={formatNumber(Object.values(tenant.modules).filter((m) => m !== 'NONE').length)} hint="Dari paket langganan" />
      </div>

      <div className="grid grid-2">
        {canSeeAccount && (
          <Card title="Penggunaan & batas" actions={<Link to="/app/penggunaan">Rincian</Link>}>
            {usage.loading && !usage.data ? (
              <Loading />
            ) : usage.error ? (
              <ErrorNotice error={usage.error} onRetry={usage.reload} />
            ) : (
              <div className="stack">
                {(usage.data ?? []).map((u) => (
                  <div key={u.code} className="stack" style={{ gap: 6 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                      <strong>{capacityLabels[u.code] ?? u.code}</strong>
                      <span>{u.limit === null ? `${formatNumber(u.used)} (tanpa batas)` : `${formatNumber(u.used)} dari ${formatNumber(u.limit)}`}</span>
                    </div>
                    <Meter used={u.used} limit={u.limit} />
                  </div>
                ))}
              </div>
            )}
          </Card>
        )}

        <Card title="Modul" actions={canSeeAccount ? <Link to="/app/langganan">Langganan</Link> : undefined}>
          {Object.keys(tenant.modules).length === 0 ? (
            <p className="muted">Belum ada modul pada langganan ini.</p>
          ) : (
            <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
              {Object.entries(tenant.modules).map(([code, mode]) => {
                const name = modules.data?.find((m) => m.module.code === code)?.module.name
                const [label, tone] = statusLabel(mode)
                return (
                  <li key={code} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
                    <span>{name ?? <span className="mono">{code}</span>}</span>
                    <Badge tone={tone}>{label}</Badge>
                  </li>
                )
              })}
            </ul>
          )}
        </Card>
      </div>

      <Banner tone="info">Fondasi akses, organisasi, dan langganan sudah aktif. Jurnal, buku besar, dan laporan keuangan hadir pada fase berikutnya.</Banner>
    </>
  )
}
