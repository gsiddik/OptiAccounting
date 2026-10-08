import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { Banner, Button, Field } from '../components/ui'
import { ApiError } from '../lib/api'
import { homePath, useAuth } from '../lib/auth'
import { describeError } from '../lib/labels'

export default function Login() {
  const { state, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<unknown>(null)

  if (state.status === 'ready') return <Navigate to={homePath(state.me)} replace />

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
    <div className="auth">
      <form className="card auth-card" onSubmit={submit} noValidate aria-labelledby="login-title">
        <div className="brand">
          <span className="brand-mark" aria-hidden="true">OA</span>
          OptiAccounting
        </div>
        <div>
          <h1 id="login-title">Masuk</h1>
          <p className="muted">Gunakan akun Anda untuk membuka portal platform atau organisasi.</p>
        </div>
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
    </div>
  )
}
