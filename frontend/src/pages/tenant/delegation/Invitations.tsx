import { useState } from 'react'
import { DataTable } from '../../../components/DataTable'
import { ErrorState } from '../../../components/ErrorState'
import { FormModal } from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { Badge, Banner, Button, Card, EmptyState, ErrorBanner, Field, Loading, Pagination } from '../../../components/ui'
import { useI18n, type Locale, type MessageKey } from '../../../i18n'
import { api } from '../../../lib/api'
import { fieldError } from '../../../lib/forms'
import { formatDateTime } from '../../../lib/format'
import { useAction, useDebounced, useResource } from '../../../lib/hooks'
import { RolesField } from './RolesField'
import { SeatsBanner } from './SeatsBanner'
import type { DeliveryStatus, Invitation, InvitationPage, InvitationStatus } from './types'

const STATUSES: InvitationStatus[] = ['PENDING', 'ACCEPTED', 'REVOKED', 'EXPIRED']
const STATUS_TONE: Record<InvitationStatus, 'info' | 'ok' | 'neutral' | 'warn'> = { PENDING: 'info', ACCEPTED: 'ok', REVOKED: 'neutral', EXPIRED: 'warn' }
const DELIVERY_TONE: Record<DeliveryStatus, 'info' | 'ok' | 'bad'> = { QUEUED: 'info', SENT: 'ok', FAILED: 'bad' }
const LANGUAGES: Locale[] = ['en', 'id']

/**
 * Invite a NEW person. OptiNexus e-mails the link and the invitee sets their own password there, then signs in here through the normal
 * OIDC flow. This dialog never asks for a password, and the answer never contains a link or token. The language defaults to the console's.
 */
export function InviteUser({ onClose, onDone }: { onClose: () => void; onDone: (invitation: Invitation) => void }) {
  const { t, locale } = useI18n()
  const { busy, error, run } = useAction()
  const [f, setF] = useState({ name: '', email: '', locale: locale as Locale })
  const [selected, setSelected] = useState(new Set<string>())

  async function submit() {
    const body = { name: f.name.trim(), email: f.email.trim(), locale: f.locale, role_ids: [...selected] }
    const r = await run(async () => (await api.post<Invitation>('/app/delegation/invitations', body)).data)
    if (r.ok) onDone(r.value)
  }

  return (
    <FormModal title={t('invite.title')} submitLabel={t('invite.submit')} wide busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <Banner tone="info">{t('invite.notice')}</Banner>
      <div className="form-grid">
        <Field label={t('invite.name')} error={fieldError(error, 'name')}>{(p) => <input className="input" required value={f.name} onChange={(e) => setF((s) => ({ ...s, name: e.target.value }))} {...p} />}</Field>
        <Field label={t('invite.email')} error={fieldError(error, 'email')}>{(p) => <input className="input" type="email" required autoComplete="off" value={f.email} onChange={(e) => setF((s) => ({ ...s, email: e.target.value }))} {...p} />}</Field>
        <Field label={t('invite.language')} error={fieldError(error, 'locale')} hint={t('invite.language_hint')} full>
          {(p) => (
            <select className="select" value={f.locale} onChange={(e) => setF((s) => ({ ...s, locale: e.target.value as Locale }))} {...p}>
              {LANGUAGES.map((code) => <option key={code} value={code}>{t(`invite.language.${code}` as MessageKey)}</option>)}
            </select>
          )}
        </Field>
      </div>
      <RolesField selected={selected} onChange={setSelected} />
      {fieldError(error, 'role_ids') && <span className="error">{fieldError(error, 'role_ids')}</span>}
      <small className="muted">{t('invite.scope_note')}</small>
    </FormModal>
  )
}

/** Invitations of this organization: OptiNexus' answer, with the actions it allows (`can_resend`, `can_revoke`). The page keeps no list of its own. */
export function InvitationList({ canManage, refreshKey }: { canManage: boolean; refreshKey: number }) {
  const { t } = useI18n()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const q = useDebounced(search)
  const [status, setStatus] = useState<InvitationStatus | ''>('')
  const [revoking, setRevoking] = useState<Invitation | null>(null)
  const resend = useAction()

  const invitations = useResource(
    async () => (await api.get<InvitationPage>('/app/delegation/invitations', { params: { page, ...(q ? { search: q } : {}), ...(status ? { status } : {}) } })).data,
    [page, q, status, refreshKey],
  )

  async function sendAgain(invitation: Invitation) {
    const r = await resend.run(async () => (await api.post<Invitation>(`/app/delegation/invitations/${invitation.id}/resend`)).data)
    if (r.ok) toast.success(t('invitations.toast.resent', { email: invitation.email }))
    // After a refusal the list is read again too: the state OptiNexus holds (accepted, revoked, cooling down) is what the next click sees.
    invitations.reload()
  }

  const filtered = Boolean(q || status)

  return (
    <>
      {resend.error != null && <ErrorBanner error={resend.error} />}
      <SeatsBanner seats={invitations.data?.meta.seats} />
      <Card flush>
        <div className="card-head">
          <div className="toolbar">
            <label htmlFor="invitation-search" className="sr-only">{t('invitations.search_label')}</label>
            <input id="invitation-search" className="input" placeholder={t('users.search')} value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
            <label htmlFor="invitation-status" className="sr-only">{t('invitations.filter_label')}</label>
            <select id="invitation-status" className="select" value={status} onChange={(e) => { setStatus(e.target.value as InvitationStatus | ''); setPage(1) }}>
              <option value="">{t('invitations.filter_all')}</option>
              {STATUSES.map((s) => <option key={s} value={s}>{t(`invitations.status.${s}` as MessageKey)}</option>)}
            </select>
          </div>
        </div>
        {invitations.loading && !invitations.data ? <Loading /> : invitations.error || !invitations.data ? <ErrorState error={invitations.error} onRetry={invitations.reload} /> : invitations.data.data.length === 0 ? (
          filtered ? <EmptyState title={t('invitations.empty_filtered.title')}>{t('invitations.empty_filtered.body')}</EmptyState> : <EmptyState title={t('invitations.empty.title')}>{t('invitations.empty.body')}</EmptyState>
        ) : (
          <>
            <DataTable
              caption={t('invitations.caption')}
              rows={invitations.data.data}
              rowKey={(i) => i.id}
              columns={[
                { header: t('invitations.col.person'), primary: true, cell: (i) => (
                  <>
                    <strong>{i.name}</strong>
                    <div className="muted">{i.email}</div>
                    <div className="muted">{i.invited_by ? t('invitations.invited_by', { name: i.invited_by.name, date: formatDateTime(i.created_at) }) : t('invitations.invited_on', { date: formatDateTime(i.created_at) })}</div>
                  </>
                ) },
                { header: t('invitations.col.roles'), cell: (i) => <div className="chips">{i.roles.length === 0 ? <span className="muted">{t('users.no_roles')}</span> : i.roles.map((r) => <Badge key={r.id} tone="info">{r.name}</Badge>)}</div> },
                { header: t('invitations.col.status'), cell: (i) => <StatusCell invitation={i} /> },
                { header: t('invitations.col.delivery'), cell: (i) => <DeliveryCell invitation={i} /> },
                { header: t('invitations.col.actions'), actions: true, cell: (i) => (
                  <div className="actions">
                    {canManage && (i.status === 'PENDING' || i.status === 'EXPIRED') && (
                      <Button size="sm" disabled={!i.can_resend || resend.busy} title={i.can_resend ? undefined : t('invitations.resend_unavailable')} onClick={() => void sendAgain(i)}>{t('invitations.action.resend')}</Button>
                    )}
                    {canManage && i.can_revoke && <Button size="sm" variant="danger" onClick={() => setRevoking(i)}>{t('invitations.action.revoke')}</Button>}
                  </div>
                ) },
              ]}
            />
            <Pagination page={invitations.data.meta.pagination.current_page} lastPage={invitations.data.meta.pagination.last_page} total={invitations.data.meta.pagination.total} onPage={setPage} />
          </>
        )}
      </Card>

      {revoking && <RevokeInvitation invitation={revoking} onClose={() => setRevoking(null)} onDone={() => { const email = revoking.email; setRevoking(null); invitations.reload(); toast.success(t('invitations.toast.revoked', { email })) }} />}
    </>
  )
}

/** The state of the invitation and, in words, what it means for the link (expired, revoked, used). */
function StatusCell({ invitation }: { invitation: Invitation }) {
  const { t } = useI18n()
  const at = { PENDING: invitation.expires_at, EXPIRED: invitation.expires_at, ACCEPTED: invitation.accepted_at, REVOKED: invitation.revoked_at }[invitation.status]
  const date = formatDateTime(at)
  return (
    <div className="cell-block">
      <Badge tone={STATUS_TONE[invitation.status] ?? 'neutral'}>{t(`invitations.status.${invitation.status}` as MessageKey)}</Badge>
      <div className="muted">{t(`invitations.note.${invitation.status}` as MessageKey, { date })}</div>
    </div>
  )
}

/** Whether OptiNexus has e-mailed the link; a failure says how to recover. */
function DeliveryCell({ invitation }: { invitation: Invitation }) {
  const { t } = useI18n()
  const { status, error_code: code } = invitation.delivery
  if (!status) return <span className="muted">—</span>
  return (
    <div className="cell-block">
      <Badge tone={DELIVERY_TONE[status] ?? 'neutral'}>{t(`invitations.delivery.${status}` as MessageKey)}</Badge>
      {status === 'FAILED' ? (
        <div className="muted" role="note">{t('invitations.delivery_hint.FAILED')}{code && <> <code>{t('invitations.delivery_code', { code })}</code></>}</div>
      ) : (
        <div className="muted">{status === 'SENT' ? t('invitations.delivery_note.SENT', { date: formatDateTime(invitation.last_sent_at) }) : t('invitations.delivery_note.QUEUED')}{invitation.send_count > 1 && <> {t('invitations.sent_count', { count: invitation.send_count })}</>}</div>
      )}
    </div>
  )
}

function RevokeInvitation({ invitation, onClose, onDone }: { invitation: Invitation; onClose: () => void; onDone: () => void }) {
  const { t } = useI18n()
  const { busy, error, run } = useAction()
  const [reason, setReason] = useState('')

  async function submit() {
    const r = await run(() => api.post(`/app/delegation/invitations/${invitation.id}/revoke`, reason.trim() ? { reason: reason.trim() } : {}))
    if (r.ok) onDone()
  }

  return (
    <FormModal title={t('invitations.revoke.title')} submitLabel={t('invitations.revoke.confirm')} danger busy={busy} error={error} onSubmit={() => void submit()} onClose={onClose}>
      <div>{t('invitations.revoke.body', { name: invitation.name, email: invitation.email })}</div>
      <Field label={t('invitations.revoke.reason')} hint={t('invitations.revoke.reason_hint')} error={fieldError(error, 'reason')}>
        {(p) => <textarea className="textarea" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} {...p} />}
      </Field>
    </FormModal>
  )
}
