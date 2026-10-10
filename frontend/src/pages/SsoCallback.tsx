import { useEffect, useRef, useState } from 'react'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { BrandLogo } from '../components/BrandLogo'
import { Banner, Loading } from '../components/ui'
import { homePath, useAuth } from '../lib/auth'
import { describeError } from '../lib/labels'

/**
 * Where the API sends the browser after OptiNexus signed the person in: a one-time ticket in the address,
 * never a token. The ticket is exchanged once (the ref keeps React StrictMode from spending it twice),
 * then the address is replaced so the ticket does not stay in history.
 */
export default function SsoCallback() {
  const { signInWithTicket } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const ticket = params.get('ticket')
  const started = useRef(false)
  const [error, setError] = useState<unknown>(null)

  useEffect(() => {
    if (!ticket || started.current) return
    started.current = true
    signInWithTicket(ticket).then(
      (me) => navigate(homePath(me), { replace: true }),
      (e: unknown) => setError(e),
    )
  }, [ticket, signInWithTicket, navigate])

  if (!ticket) return <Navigate to="/login" replace />

  return (
    <div className="auth">
      <section className="card auth-card" aria-live="polite">
        <BrandLogo size="auth" />
        {error != null ? (
          <>
            <Banner tone="bad">{describeError(error)}</Banner>
            <Link className="btn btn-primary btn-block" to="/login" replace>Kembali ke halaman masuk</Link>
          </>
        ) : (
          <Loading label="Menyelesaikan proses masuk…" />
        )}
      </section>
    </div>
  )
}
