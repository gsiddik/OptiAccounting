import { useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { homePath, useAuth } from '../lib/auth'
import { useCapabilities } from '../lib/capabilities'
import { formatDate } from '../lib/format'
import { describeError } from '../lib/labels'
import { Icon } from './Icon'
import { useToast } from './Toast'
import { Banner, Button, Loading } from './ui'

export type NavItem = { to: string; label: string; icon: string; permission?: string; /** Shown only while the module is entitled (FULL or READ_ONLY). */ module?: string; /** Shown only while the feature is entitled. */ feature?: string; end?: boolean; group?: string }

/** Sidebar + topbar shared by both portals. Navigation items appear only when `can(permission)` (cosmetic; the API enforces). */
export function Shell({ scope, items }: { scope: 'platform' | 'tenant'; items: NavItem[] }) {
  const { state, enter, logout } = useAuth()
  const { can, loading, failed, tenant, readOnly, moduleMode, featureEnabled } = useCapabilities()
  const location = useLocation()
  // The off-canvas menu belongs to the page it was opened on, so navigating closes it without an effect.
  const [openAt, setOpenAt] = useState<string | null>(null)
  const open = openAt === location.pathname
  const navigate = useNavigate()
  const toast = useToast()

  if (state.status !== 'ready') return null
  const me = state.me
  const visible = items.filter((i) => (!i.permission || can(i.permission)) && (!i.module || moduleMode(i.module) !== 'NONE') && (!i.feature || featureEnabled(i.feature)))
  const current = scope === 'platform' ? 'platform' : `tenant:${me.tenant_id}`
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
        <div className="brand">
          <span className="brand-mark" aria-hidden="true">OA</span>
          OptiAccounting
        </div>
        <nav aria-label={scope === 'platform' ? 'Menu platform' : 'Menu organisasi'} style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          {visible.map((item, index) => {
            const header = item.group && item.group !== visible[index - 1]?.group ? <div className="nav-group">{item.group}</div> : null
            return (
              <div key={item.to}>
                {header}
                <NavLink to={item.to} end={item.end} className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}>
                  <Icon name={item.icon} />
                  {item.label}
                </NavLink>
              </div>
            )
          })}
        </nav>
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
