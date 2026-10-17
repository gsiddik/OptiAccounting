import { useEffect, useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { BrandLogo } from '../components/BrandLogo'
import { Banner, Button, Field, Loading } from '../components/ui'
import { hasMessage, useI18n, type MessageKey } from '../i18n'
import { api, ApiError } from '../lib/api'
import { homePath, useAuth } from '../lib/auth'
import { useResource } from '../lib/hooks'
import { navigation } from '../lib/navigation'
import { tenantHintOf } from '../lib/tenantHint'
import { describeError, describeSsoError } from '../lib/labels'
import type { SsoStatus } from '../lib/types'

/** Used when the status call fails: the password form still works and the API decides what is allowed. */
const FALLBACK: SsoStatus = { identity_mode: 'standalone', sso_enabled: false, password_login: true }

async function loadStatus(): Promise<SsoStatus> {
  try {
    return (await api.get<SsoStatus>('/auth/sso/status')).data
  } catch {
    return FALLBACK
  }
}

const AUTOSTART_KEY = 'oa.sso.autostart'

/** True at most once a minute, so a hint that OptiNexus turns away cannot bounce the browser back and forth. */
function mayAutoStart(): boolean {
  try {
    const last = Number(sessionStorage.getItem(AUTOSTART_KEY) ?? 0)
    if (Date.now() - last < 60_000) return false
    sessionStorage.setItem(AUTOSTART_KEY, String(Date.now()))
    return true
  } catch {
    return false
  }
}

export default function Login() {
  const { state } = useAuth()
  const { t } = useI18n()
  const [params] = useSearchParams()
  const status = useResource(loadStatus, [])
  const hint = tenantHintOf(`?${params.toString()}`)
  const ended = state.status === 'anonymous' ? state.ended : undefined
  const sso = status.data
  const ssoError = describeSsoError(params.get('sso_error'))
  const viaNexus = sso?.sso_enabled === true
  const startUrl = `${api.defaults.baseURL ?? ''}/auth/sso/redirect${hint ? `?tenant_hint=${encodeURIComponent(hint)}` : ''}`

  // Arriving from another product's switcher: OptiNexus already knows the person, so go straight on instead of asking for a click.
  useEffect(() => {
    if (state.status !== 'anonymous' || !viaNexus || !hint || ssoError || ended) return
    if (mayAutoStart()) navigation.go(startUrl)
  }, [state.status, viaNexus, hint, ssoError, ended, startUrl])

  if (state.status === 'ready') return <Navigate to={homePath(state.me)} replace />

  const heading = viaNexus ? 'Gunakan akun OptiNexus Anda untuk membuka OptiEntry.' : 'Gunakan akun Anda untuk membuka portal platform atau organisasi.'

  return (
    <div className="auth">
      <section className="card auth-card" aria-labelledby="login-title">
        <BrandLogo size="auth" />
        <div>
          <h1 id="login-title">Masuk</h1>
          <p className="muted">{heading}</p>
        </div>
        {ended && (
          <Banner tone="warn">
            <strong style={{ display: 'block' }}>{t('session.ended.title')}</strong>{t((hasMessage(`session.ended.${ended}`) ? `session.ended.${ended}` : 'session.ended.generic') as MessageKey)}
          </Banner>
        )}
        {ssoError && <Banner tone="bad">{ssoError}</Banner>}
        {status.loading || !sso ? (
          <Loading />
        ) : viaNexus ? (
          <>
            <a className="btn btn-primary btn-block" href={startUrl}>Masuk dengan OptiNexus</a>
            {sso.password_login && (
              <details className="auth-alt">
                <summary>Masuk sebagai operator platform</summary>
                <PasswordForm />
              </details>
            )}
          </>
        ) : sso.identity_mode === 'optinexus' && !sso.password_login ? (
          <Banner tone="warn">Masuk belum dapat digunakan di instalasi ini. Hubungi administrator.</Banner>
        ) : (
          <>
            {sso.identity_mode === 'optinexus' && <Banner tone="warn">Masuk melalui OptiNexus belum diaktifkan. Hanya operator platform yang dapat masuk dengan kata sandi.</Banner>}
            <PasswordForm />
          </>
        )}
      </section>
    </div>
  )
}

function PasswordForm() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<unknown>(null)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const me = await login(email.trim(), password)
      const from = (location.state as { from?: string } | null)?.from
      navigate(from && me.scope !== 'identity' ? from : homePath(me), { replace: true })
    } catch (e) {
      setError(e)
      setPassword('')
    } finally {
      setBusy(false)
    }
  }

  const fields = error instanceof ApiError ? error.fields : {}

  return (
    <form className="auth-form" onSubmit={submit} noValidate aria-label="Masuk dengan kata sandi">
      {error != null && <Banner tone="bad">{describeError(error)}</Banner>}
      <Field label="E-mail" error={fields.email?.[0]}>
        {(p) => <input className="input" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} {...p} />}
      </Field>
      <Field label="Kata sandi" error={fields.password?.[0]}>
        {(p) => <input className="input" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} {...p} />}
      </Field>
      <Button type="submit" variant="primary" loading={busy} disabled={!email || !password}>
        Masuk
      </Button>
    </form>
  )
}
