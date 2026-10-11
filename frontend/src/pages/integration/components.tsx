import { Link } from 'react-router-dom'
import { useCapabilities } from '../../lib/capabilities'
import { Tagged } from '../closing/components'
import { PATHS } from './data'
import { connectionText, eventStatusText } from './labels'
import type { SourceLink } from './types'

export { Facts } from '../closing/components'

export const ConnectionBadge = ({ status }: { status: string | null | undefined }) => <Tagged text={connectionText(status)} />
export const EventBadge = ({ status }: { status: string | null | undefined }) => <Tagged text={eventStatusText(status)} />

/** A link to a journal, shown only to a user who may open journals (the page itself is guarded again). */
export function JournalLink({ id, children }: { id: string; children?: string }) {
  const { can } = useCapabilities()
  return can('accounting.journal.view') ? <Link to={PATHS.journal(id)}>{children ?? 'Buka jurnal'}</Link> : <>{children ?? 'Jurnal'}</>
}

const DOCUMENTS: Record<string, { label: string; permission: string; path: (id: string) => string }> = {
  ap_invoice: { label: 'Faktur vendor', permission: 'accounting.ap_invoice.view', path: PATHS.apInvoice },
  ap_payment: { label: 'Pembayaran vendor', permission: 'accounting.ap_payment.view', path: PATHS.apPayment },
}

/** The OptiEntry document a source link points at; a type this screen does not know is shown as its code, not guessed. */
export function DocumentLink({ link }: { link: SourceLink }) {
  const { can } = useCapabilities()
  if (!link.document_type || !link.document_id) return <>—</>
  const known = DOCUMENTS[link.document_type]
  if (!known) return <>{link.document_type}</>
  return can(known.permission) ? <Link to={known.path(link.document_id)}>{known.label}</Link> : <>{known.label}</>
}
