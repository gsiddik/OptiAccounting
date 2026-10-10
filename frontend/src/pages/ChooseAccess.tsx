import { useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { BrandLogo } from '../components/BrandLogo'
import { Banner, Button } from '../components/ui'
import { homePath, useAuth, type EnterTarget } from '../lib/auth'
import { describeError } from '../lib/labels'

/** After sign-in with several places to go: pick one. The server verifies membership and tenant state. */
export default function ChooseAccess() {
  const { state, enter, logout } = useAuth()
  const navigate = useNavigate()
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<unknown>(null)

  if (state.status !== 'ready') return <Navigate to="/login" replace />
  const me = state.me
  if (me.scope !== 'identity') return <Navigate to={homePath(me)} replace />

  async function go(key: string, target: EnterTarget) {
    setBusy(key)
    setError(null)
    try {
      navigate(homePath(await enter(target)), { replace: true })
    } catch (e) {
      setError(e)
      setBusy(null)
    }
  }

  const empty = me.tenants.length === 0 && !me.platform_access

  return (
    <div className="auth">
      <section className="card auth-card" aria-labelledby="choose-title">
        <BrandLogo size="auth" />
        <div>
          <h1 id="choose-title">{empty ? 'Belum ada akses' : 'Pilih tujuan'}</h1>
          <p className="muted">
            {empty
              ? `Akun ${me.user.email} belum menjadi anggota organisasi yang aktif. Hubungi administrator organisasi Anda.`
              : `Halo, ${me.user.name}. Ke mana Anda ingin masuk?`}
          </p>
        </div>
        {error != null && <Banner tone="bad">{describeError(error)}</Banner>}
        <div className="stack" style={{ gap: 10 }}>
          {me.platform_access && (
            <button type="button" className="choice" disabled={busy !== null} onClick={() => void go('platform', { kind: 'platform' })}>
              <span>
                Portal Platform
                <small>Kelola tenant, langganan, dan katalog modul</small>
              </span>
              {busy === 'platform' && <span className="spinner" aria-hidden="true" />}
            </button>
          )}
          {me.tenants.map((t) => (
            <button key={t.id} type="button" className="choice" disabled={busy !== null} onClick={() => void go(t.id, { kind: 'tenant', tenantId: t.id })}>
              <span>
                {t.name}
                <small>{t.code}</small>
              </span>
              {busy === t.id && <span className="spinner" aria-hidden="true" />}
            </button>
          ))}
        </div>
        <Button onClick={() => void logout().then(() => navigate('/login'))}>Keluar</Button>
      </section>
    </div>
  )
}
