import { ApiError } from '../lib/api'
import { classifyError, type ErrorKind } from '../lib/errors'
import { describeError } from '../lib/labels'
import { useI18n, type MessageKey } from '../i18n'
import { Button, ErrorReference } from './ui'

/**
 * A failure that replaces the content it belongs to (a page, a panel), not a banner above it. The kind picks title and action; the
 * sentence is the server's own refusal when there is one. Never shows a stack trace, only the reference to quote to support.
 */
export function ErrorState({ error, onRetry, kind }: { error: unknown; onRetry?: () => void; kind?: ErrorKind }) {
  const { t } = useI18n()
  const resolved = kind ?? classifyError(error)
  const specific = error instanceof ApiError && resolved !== 'offline' && resolved !== 'chunk' && resolved !== 'session'
  const body = specific ? describeError(error) : t(`state.${resolved}.body` as MessageKey)

  return (
    <div className="state" role="alert" data-error-kind={resolved}>
      <h2>{t(`state.${resolved}.title` as MessageKey)}</h2>
      <p>{body}</p>
      <ErrorReference error={error} />
      <div className="actions">
        {resolved === 'chunk' && <Button variant="primary" onClick={() => window.location.reload()}>{t('state.action.reload')}</Button>}
        {resolved === 'session' && <a className="btn btn-primary" href="/login">{t('state.action.sign_in')}</a>}
        {resolved !== 'chunk' && resolved !== 'session' && onRetry && <Button onClick={onRetry}>{t('state.action.retry')}</Button>}
        {resolved === 'unexpected' && <Button onClick={() => window.location.reload()}>{t('state.action.reload')}</Button>}
      </div>
    </div>
  )
}
