import { useState } from 'react'
import { Outlet, useLocation, useNavigate, useNavigationType } from 'react-router-dom'
import { homePath, useAuth } from '../lib/auth'
import { buildTrail, parentOf } from '../lib/breadcrumbs'
import { useCapabilities } from '../lib/capabilities'
import { useCrumbLabels } from '../lib/crumbContext'
import { formatDate } from '../lib/format'
import { describeError } from '../lib/labels'
import { BrandLogo } from './BrandLogo'
import { CrumbLabelProvider } from './CrumbLabels'
import { Breadcrumbs, BackButton } from './PageNav'
import { SidebarNav } from './SidebarNav'
import { useToast } from './Toast'
import { Icon } from './Icon'
import { Banner, Button, Loading } from './ui'

export type NavItem = { to: string; label: string; icon: string; permission?: string; /** Shown only while the module is entitled (FULL or READ_ONLY). */ module?: string; /** Shown only while the feature is entitled. */ feature?: string; end?: boolean; group?: string }

/** Sidebar + topbar shared by both portals. Navigation items appear only when `can(permission)` (cosmetic; the API enforces). */
export function Shell(props: { scope: 'platform' | 'tenant'; items: NavItem[] }) {
  return (
    <CrumbLabelProvider>
      <ShellFrame {...props} />
    </CrumbLabelProvider>
  )
}

function ShellFrame({ scope, items }: { scope: 'platform' | 'tenant'; items: NavItem[] }) {
  const { state, enter, logout } = useAuth()
  const { can, loading, failed, tenant, readOnly, moduleMode, featureEnabled } = useCapabilities()
  const location = useLocation()
  // The off-canvas menu belongs to the page it was opened on, so navigating closes it without an effect.
  const [openAt, setOpenAt] = useState<string | null>(null)
  const open = openAt === location.pathname
  const navigate = useNavigate()
  const navigationType = useNavigationType()
  const toast = useToast()
  const crumbLabels = useCrumbLabels()
  // How many pages of this portal lie behind the current one, so "Kembali" never leaves the portal (e.g. back to the sign-in page).
  const [history, setHistory] = useState({ key: location.key, depth: 0 })
  if (history.key !== location.key) {
    const depth = navigationType === 'PUSH' ? history.depth + 1 : navigationType === 'POP' ? Math.max(0, history.depth - 1) : history.depth
    setHistory({ key: location.key, depth })
  }

  if (state.status !== 'ready') return null
  const me = state.me
  const visible = items.filter((i) => (!i.permission || can(i.permission)) && (!i.module || moduleMode(i.module) !== 'NONE') && (!i.feature || featureEnabled(i.feature)))
  const current = scope === 'platform' ? 'platform' : `tenant:${me.tenant_id}`
  const trail = buildTrail(location.pathname, crumbLabels)
  const parent = parentOf(trail)
  const goBack = history.depth > 0 ? () => navigate(-1) : parent ? () => navigate(parent) : null
  const targets = [
    ...(me.platform_access ? [{ value: 'platform', label: 'Portal Platform' }] : []),
    ...me.tenants.map((t) => ({ value: `tenant:${t.id}`, label: t.name })),
  ]

  async function switchTo(value: string) {
    try {
      const next = await enter(value === 'platform' ? { kind: 'platform' } : { kind: 'tenant', tenantId: value.slice('tenant:'.length) })
      navigate(homePath(next))
    } catch (e) {
      toast.error(describeError(e))
    }
  }

  return (
    <div className="app">
      <a className="skip-link" href="#main">Lewati ke konten</a>
      <aside className={`sidebar${open ? ' open' : ''}`} aria-label="Navigasi utama" id="sidebar">
        <BrandLogo to={scope === 'platform' ? '/platform' : '/app'} />
        <SidebarNav scope={scope} items={visible} label={scope === 'platform' ? 'Menu platform' : 'Menu organisasi'} />
      </aside>
      <div className={`scrim${open ? ' open' : ''}`} onClick={() => setOpenAt(null)} aria-hidden="true" />

      <div className="main">
        <header className="topbar">
          <Button variant="ghost" className="menu-btn btn-icon" aria-label="Buka menu" aria-expanded={open} aria-controls="sidebar" onClick={() => setOpenAt(open ? null : location.pathname)}>
            <Icon name="menu" size={20} />
          </Button>
          <div className="context-chip">
            <strong>{scope === 'platform' ? 'Portal Platform' : (tenant?.tenant.name ?? '…')}</strong>
            <span>{scope === 'platform' ? 'Pengelolaan seluruh tenant' : tenant ? `${tenant.tenant.code} · tanggal bisnis ${formatDate(tenant.business_date)}` : ' '}</span>
          </div>
          <div className="spacer" />
          {targets.length > 1 && (
            <>
              <label htmlFor="context-switch" className="sr-only">Ganti organisasi atau portal</label>
              <select id="context-switch" className="select" style={{ width: 'auto', maxWidth: 200 }} value={current} onChange={(e) => void switchTo(e.target.value)}>
                {targets.map((t) => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </select>
            </>
          )}
          <div className="context-chip right" style={{ maxWidth: 180 }}>
            <strong>{me.user.name}</strong>
            <span style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{me.user.email}</span>
          </div>
          <Button size="sm" onClick={() => void logout().then(() => navigate('/login'))} aria-label="Keluar">
            <Icon name="logout" /> <span className="hide-sm">Keluar</span>
          </Button>
        </header>

        <main className="content" id="main">
          {trail.length > 0 && (
            <div className="page-nav">
              {goBack && <BackButton onBack={goBack} />}
              <Breadcrumbs trail={trail} />
            </div>
          )}
          {readOnly && (
            <Banner tone="warn">
              <strong>Langganan menunggak.</strong> Modul akuntansi berada dalam mode hanya baca: data dapat dilihat, tetapi tidak dapat diubah sampai pembayaran diselesaikan.
            </Banner>
          )}
          {loading ? <Loading /> : failed ? <Banner tone="bad">Tidak dapat memuat hak akses Anda. Muat ulang halaman.</Banner> : <Outlet />}
        </main>
      </div>
    </div>
  )
}
