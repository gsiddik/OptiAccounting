import type { ReactNode } from 'react'
import { Link, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { Shell, type NavItem } from './components/Shell'
import { Banner, EmptyState, Loading } from './components/ui'
import { homePath, useAuth } from './lib/auth'
import { CapabilityProvider, useCapabilities } from './lib/capabilities'
import ChooseAccess from './pages/ChooseAccess'
import Login from './pages/Login'
import PlatformAudit from './pages/platform/Audit'
import Bundles from './pages/platform/Bundles'
import PlatformDashboard from './pages/platform/Dashboard'
import Modules from './pages/platform/Modules'
import Operators from './pages/platform/Operators'
import TenantDetail from './pages/platform/TenantDetail'
import Tenants from './pages/platform/Tenants'
import TenantAudit from './pages/tenant/Audit'
import TenantDashboard from './pages/tenant/Dashboard'
import Organization from './pages/tenant/Organization'
import Roles from './pages/tenant/Roles'
import Subscription from './pages/tenant/Subscription'
import Usage from './pages/tenant/Usage'
import Users from './pages/tenant/Users'

// Navigation is declarative: an item shows only when the user holds its permission (cosmetic; the API enforces).
const PLATFORM_NAV: NavItem[] = [
  { to: '/platform', label: 'Dashboard', icon: 'home', end: true },
  { to: '/platform/tenants', label: 'Tenant', icon: 'building', permission: 'platform.tenant.view', group: 'Pelanggan' },
  { to: '/platform/modules', label: 'Modul & fitur', icon: 'grid', permission: 'platform.module.view', group: 'Katalog' },
  { to: '/platform/bundles', label: 'Paket', icon: 'box', permission: 'platform.bundle.view', group: 'Katalog' },
  { to: '/platform/operators', label: 'Operator & akses', icon: 'shield', permission: 'platform.user.view', group: 'Platform' },
  { to: '/platform/audit', label: 'Audit', icon: 'log', permission: 'platform.audit.view', group: 'Platform' },
]

const TENANT_NAV: NavItem[] = [
  { to: '/app', label: 'Dashboard', icon: 'home', end: true },
  { to: '/app/organisasi', label: 'Organisasi', icon: 'building', permission: 'organization.view', group: 'Pengaturan' },
  { to: '/app/pengguna', label: 'Pengguna', icon: 'users', permission: 'access.user.view', group: 'Pengaturan' },
  { to: '/app/peran', label: 'Peran & izin', icon: 'shield', permission: 'access.role.view', group: 'Pengaturan' },
  { to: '/app/langganan', label: 'Langganan', icon: 'card', permission: 'account.subscription.view', group: 'Akun' },
  { to: '/app/penggunaan', label: 'Penggunaan & batas', icon: 'chart', permission: 'account.subscription.view', group: 'Akun' },
  { to: '/app/audit', label: 'Audit', icon: 'log', permission: 'audit.view', group: 'Akun' },
]

/** Sends the visitor to sign-in, or to the portal their session actually belongs to. */
function RequireScope({ scope, children }: { scope: 'platform' | 'tenant'; children: ReactNode }) {
  const { state } = useAuth()
  const location = useLocation()
  if (state.status === 'loading') return <Loading label="Memuat sesi…" />
  if (state.status === 'anonymous') return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (state.me.scope !== scope) return <Navigate to={homePath(state.me)} replace />
  return <>{children}</>
}

function Portal({ scope, items }: { scope: 'platform' | 'tenant'; items: NavItem[] }) {
  const { state } = useAuth()
  const tenantId = state.status === 'ready' ? state.me.tenant_id : null
  // Keyed by tenant so a switch discards every cached capability and page state of the previous organisation.
  return (
    <CapabilityProvider key={`${scope}:${tenantId}`} scope={scope} tenantId={tenantId}>
      <Shell scope={scope} items={items} />
    </CapabilityProvider>
  )
}

/** Page-level guard: a direct URL without the permission shows a refusal instead of a failing request. */
function Guard({ permission, children }: { permission: string; children: ReactNode }) {
  const { can } = useCapabilities()
  if (!can(permission)) return <EmptyState title="Akses ditolak">Anda tidak memiliki izin untuk membuka halaman ini.</EmptyState>
  return <>{children}</>
}

function NotFound() {
  return (
    <EmptyState title="Halaman tidak ditemukan" action={<Link to="/">Kembali ke beranda</Link>}>
      Alamat yang Anda buka tidak ada.
    </EmptyState>
  )
}

function Landing() {
  const { state } = useAuth()
  if (state.status === 'loading') return <Loading label="Memuat sesi…" />
  if (state.status === 'anonymous') return <Navigate to="/login" replace />
  return <Navigate to={homePath(state.me)} replace />
}

export default function App() {
  return (
    <Routes>
        <Route path="/" element={<Landing />} />
        <Route path="/login" element={<Login />} />
        <Route path="/pilih-akses" element={<ChooseAccess />} />

        <Route path="/platform" element={<RequireScope scope="platform"><Portal scope="platform" items={PLATFORM_NAV} /></RequireScope>}>
          <Route index element={<PlatformDashboard />} />
          <Route path="tenants" element={<Guard permission="platform.tenant.view"><Tenants /></Guard>} />
          <Route path="tenants/:id" element={<Guard permission="platform.tenant.view"><TenantDetail /></Guard>} />
          <Route path="modules" element={<Guard permission="platform.module.view"><Modules /></Guard>} />
          <Route path="bundles" element={<Guard permission="platform.bundle.view"><Bundles /></Guard>} />
          <Route path="operators" element={<Guard permission="platform.user.view"><Operators /></Guard>} />
          <Route path="audit" element={<Guard permission="platform.audit.view"><PlatformAudit /></Guard>} />
          <Route path="*" element={<NotFound />} />
        </Route>

        <Route path="/app" element={<RequireScope scope="tenant"><Portal scope="tenant" items={TENANT_NAV} /></RequireScope>}>
          <Route index element={<TenantDashboard />} />
          <Route path="organisasi" element={<Guard permission="organization.view"><Organization /></Guard>} />
          <Route path="pengguna" element={<Guard permission="access.user.view"><Users /></Guard>} />
          <Route path="peran" element={<Guard permission="access.role.view"><Roles /></Guard>} />
          <Route path="langganan" element={<Guard permission="account.subscription.view"><Subscription /></Guard>} />
          <Route path="penggunaan" element={<Guard permission="account.subscription.view"><Usage /></Guard>} />
          <Route path="audit" element={<Guard permission="audit.view"><TenantAudit /></Guard>} />
          <Route path="*" element={<NotFound />} />
        </Route>

        <Route path="*" element={<div className="auth"><Banner tone="info">Halaman tidak ditemukan. <Link to="/">Kembali ke beranda</Link></Banner></div>} />
    </Routes>
  )
}
