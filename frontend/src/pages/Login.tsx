import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { BrandLogo } from '../components/BrandLogo'
import { Banner, Button, Field, Loading } from '../components/ui'
import { api, ApiError } from '../lib/api'
import { homePath, useAuth } from '../lib/auth'
import { useResource } from '../lib/hooks'
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

export default function Login() {
  const { state } = useAuth()
  const [params] = useSearchParams()
  const status = useResource(loadStatus, [])

  if (state.status === 'ready') return <Navigate to={homePath(state.me)} replace />

  const sso = status.data
  const ssoError = describeSsoError(params.get('sso_error'))
  const viaNexus = sso?.sso_enabled === true
  const heading = viaNexus ? 'Gunakan akun OptiNexus Anda untuk membuka OptiEntry.' : 'Gunakan akun Anda untuk membuka portal platform atau organisasi.'

  return (
    <div className="auth">
      <section className="card auth-card" aria-labelledby="login-title">
        <BrandLogo size="auth" />
        <div>
          <h1 id="login-title">Masuk</h1>
          <p className="muted">{heading}</p>
        </div>
        {ssoError && <Banner tone="bad">{ssoError}</Banner>}
        {status.loading || !sso ? (
          <Loading />
        ) : viaNexus ? (
          <>
            <a className="btn btn-primary btn-block" href={`${api.defaults.baseURL ?? ''}/auth/sso/redirect`}>Masuk dengan OptiNexus</a>
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
