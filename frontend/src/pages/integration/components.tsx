import { Link } from 'react-router-dom'
import { useI18n } from '../../i18n'
import { useCapabilities } from '../../lib/capabilities'
import { Tagged } from '../closing/components'
import { PATHS } from './data'
import { accessText, bookText, connectionText, documentTypeText, eventStatusText, healthText, outcomeText, type Tone } from './labels'
import type { SourceLink } from './types'

export { Facts } from '../closing/components'

// The badges subscribe to the language themselves, so a language change re-renders them wherever they sit.
function Localized({ text }: { text: () => [string, Tone] }) {
  useI18n()
  return <Tagged text={text()} />
}

export const ConnectionBadge = ({ status }: { status: string | null | undefined }) => <Localized text={() => connectionText(status)} />
export const EventBadge = ({ status }: { status: string | null | undefined }) => <Localized text={() => eventStatusText(status)} />
/** What processing decided for the books; "not processed yet" until the event reached POSTED. */
export const OutcomeBadge = ({ outcome }: { outcome: string | null | undefined }) => <Localized text={() => outcomeText(outcome)} />
/** Where the books stand now (read live from the document); only IN_LEDGER is in the general ledger. */
export const BooksBadge = ({ state }: { state: string | null | undefined }) => <Localized text={() => bookText(state)} />
export const AccessBadge = ({ state }: { state: string | null | undefined }) => <Localized text={() => accessText(state)} />
export const HealthBadge = ({ level }: { level: string | null | undefined }) => <Localized text={() => healthText(level)} />

/** A link to a journal, shown only to a user who may open journals (the page itself is guarded again). */
export function JournalLink({ id, children }: { id: string; children?: string }) {
  const { can } = useCapabilities()
  const { t } = useI18n()
  return can('accounting.journal.view') ? <Link to={PATHS.journal(id)}>{children ?? t('it.links.open_journal')}</Link> : <>{children ?? t('it.links.journal')}</>
}

const DOCUMENTS: Record<string, { permission: string; path: (id: string) => string }> = {
  ap_invoice: { permission: 'accounting.ap_invoice.view', path: PATHS.apInvoice },
  vendor_payment: { permission: 'accounting.ap_payment.view', path: PATHS.apPayment },
}

/** A link to the OptiEntry document of a type this screen knows; a user without the view permission sees its name, a type it does not know is shown as its code. */
export function DocumentRef({ type, id }: { type: string | null; id: string | null }) {
  const { can } = useCapabilities()
  useI18n()
  if (!type || !id) return <>—</>
  const known = DOCUMENTS[type]
  if (!known) return <>{type}</>
  const label = documentTypeText(type)
  return can(known.permission) ? <Link to={known.path(id)}>{label}</Link> : <>{label}</>
}

export const DocumentLink = ({ link }: { link: SourceLink }) => <DocumentRef type={link.document_type} id={link.document_id} />
