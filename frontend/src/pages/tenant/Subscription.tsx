import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { Badge, Banner, Card, EmptyState, ErrorNotice, Loading, PageHeader, StatusBadge, Tabs } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { sourceLabels, statusLabel } from '../../lib/labels'
import type { EffectiveSubscription, Subscription as SubscriptionRow } from '../../lib/types'

type Tab = 'summary' | 'modules' | 'features'

type ModuleRow = { module: { code: string; name: string; description: string | null }; state: string; source: string; effective_from: string; effective_until: string | null; current: boolean; effective_mode: string }
type FeatureRow = { feature: { code: string; name: string }; module: { code: string; name: string }; state: string; source: string; effective_from: string; effective_until: string | null; current: boolean; usable: boolean }

export default function Subscription() {
  const [tab, setTab] = useState<Tab>('summary')
  return (
    <>
      <PageHeader title="Langganan" description="Paket, modul, dan fitur yang boleh dipakai organisasi ini. Perubahan dilakukan oleh administrator platform." />
      <Card flush>
        <Tabs tabs={[{ id: 'summary', label: 'Ringkasan' }, { id: 'modules', label: 'Modul' }, { id: 'features', label: 'Fitur' }]} value={tab} onChange={setTab} />
        {tab === 'summary' && <Summary />}
        {tab === 'modules' && <Modules />}
        {tab === 'features' && <Features />}
      </Card>
    </>
  )
}

function Summary() {
  const { data, loading, error, reload } = useResource(async () => (await api.get<{ effective: EffectiveSubscription; history: SubscriptionRow[] }>('/app/account/subscription')).data, [])
  if (loading && !data) return <Loading />
  if (error || !data) return <ErrorNotice error={error} onRetry={reload} />

  const [modeLabel] = statusLabel(data.effective.mode)
  return (
    <div className="card-body stack">
      {data.effective.mode === 'READ_ONLY' && <Banner tone="warn">Langganan menunggak. Semua modul hanya dapat dibaca sampai pembayaran diselesaikan.</Banner>}
      {data.effective.mode === 'NONE' && <Banner tone="bad">Tidak ada langganan yang berlaku saat ini. Hubungi administrator platform.</Banner>}
      <dl className="facts">
        <div><dt>Status</dt><dd><StatusBadge status={data.effective.status} /></dd></div>
        <div><dt>Mode akses</dt><dd>{modeLabel}</dd></div>
        <div><dt>Mulai</dt><dd>{formatDate(data.effective.starts_on)}</dd></div>
        <div><dt>Berakhir</dt><dd>{formatDate(data.effective.ends_on)}</dd></div>
      </dl>
      <h2 style={{ marginTop: 8 }}>Riwayat langganan</h2>
      {data.history.length === 0 ? (
        <EmptyState title="Belum ada langganan" />
      ) : (
        <DataTable
          caption="Riwayat langganan"
          rows={data.history}
          rowKey={(s) => s.id}
          columns={[
            { header: 'Paket', primary: true, cell: (s) => <strong>{s.bundle?.name ?? 'Tanpa paket'}</strong> },
            { header: 'Status', cell: (s) => <StatusBadge status={s.status} /> },
            { header: 'Mulai', cell: (s) => formatDate(s.starts_on) },
            { header: 'Berakhir', cell: (s) => formatDate(s.ends_on) },
          ]}
        />
      )}
    </div>
  )
}

function Modules() {
  const { data, loading, error, reload } = useResource(async () => (await api.get<{ data: ModuleRow[]; business_date: string }>('/app/account/modules')).data, [])
  if (loading && !data) return <Loading />
  if (error || !data) return <ErrorNotice error={error} onRetry={reload} />
  if (data.data.length === 0) return <EmptyState title="Belum ada modul">Modul muncul setelah langganan dibuat.</EmptyState>

  return (
    <DataTable
      caption="Hak akses modul"
      rows={data.data}
      rowKey={(m) => `${m.module.code}-${m.effective_from}`}
      columns={[
        { header: 'Modul', primary: true, cell: (m) => <><strong>{m.module.name}</strong><div className="muted">{m.module.description}</div></> },
        { header: 'Akses sekarang', cell: (m) => <StatusBadge status={m.effective_mode} /> },
        { header: 'Sumber', cell: (m) => sourceLabels[m.source] ?? m.source },
        { header: 'Periode', cell: (m) => <span className="nowrap">{formatDate(m.effective_from)} – {m.effective_until ? formatDate(m.effective_until) : 'terbuka'}</span> },
        { header: 'Berlaku', cell: (m) => (m.current ? <Badge tone="ok">Ya</Badge> : <Badge>Tidak</Badge>) },
      ]}
    />
  )
}

function Features() {
  const { data, loading, error, reload } = useResource(async () => (await api.get<{ data: FeatureRow[] }>('/app/account/features')).data, [])
  if (loading && !data) return <Loading />
  if (error || !data) return <ErrorNotice error={error} onRetry={reload} />
  if (data.data.length === 0) return <EmptyState title="Belum ada fitur khusus">Fitur mengikuti modulnya kecuali dibatasi atau diberikan secara khusus.</EmptyState>

  return (
    <DataTable
      caption="Hak akses fitur"
      rows={data.data}
      rowKey={(f) => `${f.feature.code}-${f.effective_from}`}
      columns={[
        { header: 'Fitur', primary: true, cell: (f) => <><strong>{f.feature.name}</strong><div className="muted">{f.module.name}</div></> },
        { header: 'Status', cell: (f) => <StatusBadge status={f.state} /> },
        { header: 'Dapat dipakai', cell: (f) => (f.usable ? <Badge tone="ok">Ya</Badge> : <Badge>Tidak</Badge>) },
        { header: 'Sumber', cell: (f) => sourceLabels[f.source] ?? f.source },
        { header: 'Periode', cell: (f) => <span className="nowrap">{formatDate(f.effective_from)} – {f.effective_until ? formatDate(f.effective_until) : 'terbuka'}</span> },
      ]}
    />
  )
}
