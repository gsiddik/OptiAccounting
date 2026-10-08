import { useEffect, useState } from 'react'
import { fetchHealth, type Health } from './lib/api'

type State =
  | { kind: 'loading' }
  | { kind: 'ready'; health: Health }
  | { kind: 'error' }

const modeLabel: Record<Health['identity_mode'], string> = {
  standalone: 'Standalone',
  optinexus: 'SaaS (OptiNexus)',
}

// MASTER scaffold: the platform and tenant portals arrive in OA0.
export default function App() {
  const [state, setState] = useState<State>({ kind: 'loading' })

  useEffect(() => {
    fetchHealth()
      .then((health) => setState({ kind: 'ready', health }))
      .catch(() => setState({ kind: 'error' }))
  }, [])

  return (
    <div className="shell">
      <header className="topbar">
        <span className="brand">OptiAccounting</span>
      </header>
      <main className="content">
        <section className="card" aria-live="polite">
          <h1>Fondasi aplikasi siap</h1>
          <p className="muted">
            Akuntansi double-entry untuk SaaS multi-tenant dan standalone. Portal
            platform dan tenant tersedia mulai fase OA0.
          </p>
          <dl className="facts">
            <div>
              <dt>Status API</dt>
              <dd>
                {state.kind === 'loading' && <span className="badge">Memeriksa…</span>}
                {state.kind === 'error' && <span className="badge badge-bad">Tidak terhubung</span>}
                {state.kind === 'ready' && (
                  <span className={state.health.checks.database === 'ok' ? 'badge badge-ok' : 'badge badge-bad'}>
                    {state.health.checks.database === 'ok' ? 'Terhubung' : 'Database tidak tersedia'}
                  </span>
                )}
              </dd>
            </div>
            <div>
              <dt>Mode identitas</dt>
              <dd>{state.kind === 'ready' ? modeLabel[state.health.identity_mode] : '—'}</dd>
            </div>
            <div>
              <dt>Versi API</dt>
              <dd>{state.kind === 'ready' ? state.health.api_version : '—'}</dd>
            </div>
          </dl>
        </section>
      </main>
    </div>
  )
}
