import { useId, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { describeError, statusLabel } from '../lib/labels'

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost'

export function Button({
  variant = 'secondary',
  size,
  loading,
  className = '',
  children,
  disabled,
  type = 'button',
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: 'sm'; loading?: boolean }) {
  const cls = ['btn', variant !== 'secondary' ? `btn-${variant}` : '', size ? `btn-${size}` : '', className].filter(Boolean).join(' ')
  return (
    <button type={type} className={cls} disabled={disabled || loading} aria-busy={loading || undefined} {...rest}>
      {loading && <span className="spinner" aria-hidden="true" />}
      {children}
    </button>
  )
}

export function Badge({ tone = 'neutral', children }: { tone?: 'ok' | 'warn' | 'bad' | 'info' | 'neutral'; children: ReactNode }) {
  return <span className={`badge${tone === 'neutral' ? '' : ` badge-${tone}`}`}>{children}</span>
}

export function StatusBadge({ status }: { status: string | null | undefined }) {
  const [label, tone] = statusLabel(status)
  return <Badge tone={tone}>{label}</Badge>
}

export function Banner({ tone = 'info', children }: { tone?: 'info' | 'warn' | 'bad' | 'ok'; children: ReactNode }) {
  return (
    <div className={`banner banner-${tone}`} role={tone === 'bad' || tone === 'warn' ? 'alert' : 'status'}>
      <div>{children}</div>
    </div>
  )
}

export function Loading({ label = 'Memuat…' }: { label?: string }) {
  return (
    <div className="loading" role="status">
      <span className="spinner" aria-hidden="true" /> {label}
    </div>
  )
}

export function ErrorNotice({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  return (
    <div className="stack" style={{ padding: 20, alignItems: 'flex-start' }}>
      <Banner tone="bad">{describeError(error)}</Banner>
      {onRetry && <Button onClick={onRetry}>Coba lagi</Button>}
    </div>
  )
}

export function EmptyState({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <div className="empty">
      <strong>{title}</strong>
      {children && <span>{children}</span>}
      {action}
    </div>
  )
}

export function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="page-header">
      <div>
        <h1>{title}</h1>
        {description && <p>{description}</p>}
      </div>
      {actions && <div className="actions">{actions}</div>}
    </div>
  )
}

export function Card({ title, actions, children, flush }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; flush?: boolean }) {
  return (
    <section className="card">
      {title && (
        <div className="card-head">
          <h2>{title}</h2>
          {actions && <div className="actions">{actions}</div>}
        </div>
      )}
      <div className={flush ? undefined : 'card-body'}>{children}</div>
    </section>
  )
}

export function Stat({ label, value, hint }: { label: string; value: ReactNode; hint?: ReactNode }) {
  return (
    <div className="card stat">
      <span className="label">{label}</span>
      <span className="value">{value}</span>
      {hint && <span className="muted" style={{ fontSize: '0.82rem' }}>{hint}</span>}
    </div>
  )
}

/** Label + control + error wired together for screen readers. The control gets the generated id through the render prop. */
export function Field({
  label,
  error,
  hint,
  full,
  children,
}: {
  label: string
  error?: string
  hint?: string
  full?: boolean
  children: (props: { id: string; 'aria-invalid'?: true; 'aria-describedby'?: string }) => ReactNode
}) {
  const id = useId()
  const describedBy = [hint ? `${id}-hint` : '', error ? `${id}-err` : ''].filter(Boolean).join(' ') || undefined
  return (
    <div className={`field${full ? ' full' : ''}`}>
      <label htmlFor={id}>{label}</label>
      {children({ id, 'aria-invalid': error ? true : undefined, 'aria-describedby': describedBy })}
      {hint && <span className="hint" id={`${id}-hint`}>{hint}</span>}
      {error && <span className="error" id={`${id}-err`}>{error}</span>}
    </div>
  )
}

export function Meter({ used, limit }: { used: number; limit: number | null }) {
  if (limit === null) return <span className="muted">Tanpa batas</span>
  const pct = limit === 0 ? 100 : Math.min(100, Math.round((used / limit) * 100))
  const tone = pct >= 100 ? ' full' : pct >= 80 ? ' warn' : ''
  return (
    <div className={`meter${tone}`} role="progressbar" aria-valuemin={0} aria-valuemax={limit} aria-valuenow={used} aria-label={`${used} dari ${limit}`}>
      <span style={{ width: `${pct}%` }} />
    </div>
  )
}

export function Tabs<T extends string>({ tabs, value, onChange }: { tabs: { id: T; label: string }[]; value: T; onChange: (id: T) => void }) {
  return (
    <div className="tabs" role="tablist">
      {tabs.map((t) => (
        <button key={t.id} role="tab" type="button" className="tab" aria-selected={t.id === value} onClick={() => onChange(t.id)}>
          {t.label}
        </button>
      ))}
    </div>
  )
}

export function Pagination({ page, lastPage, total, onPage }: { page: number; lastPage: number; total: number; onPage: (page: number) => void }) {
  return (
    <div className="pagination">
      <span>
        {total} data · halaman {page} dari {Math.max(lastPage, 1)}
      </span>
      <div className="actions">
        <Button size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Sebelumnya
        </Button>
        <Button size="sm" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
          Berikutnya
        </Button>
      </div>
    </div>
  )
}
