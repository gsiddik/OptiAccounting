import { Link, useParams, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { CreditNoteFormView } from './receivables/CreditNoteFormView'
import { AR_PATH } from './receivables/paths'
import type { ArInvoice, CreditNote } from './receivables/types'

/** New (`/nota-kredit/baru`, optionally `?faktur=<invoice id>` from the invoice page) or edit (`/nota-kredit/:id/ubah`) credit note. */
export default function ArCreditNoteEditor() {
  const { id } = useParams()
  const [params] = useSearchParams()
  const invoiceId = id ? null : params.get('faktur')
  const access = useModuleAccess(MODULES.ar)
  const note = useResource(async () => (id ? (await api.get<CreditNote>(`${API}/ar-credit-notes/${id}`)).data : null), [id])
  const prefill = useResource(async () => (invoiceId ? (await api.get<ArInvoice>(`${API}/ar-invoices/${invoiceId}`)).data : null), [invoiceId])

  if ((id && note.loading && !note.data) || (invoiceId && prefill.loading && !prefill.data)) return <Loading />
  if (note.error) return <ErrorNotice error={note.error} onRetry={note.reload} />
  if (prefill.error) return <ErrorNotice error={prefill.error} onRetry={prefill.reload} />

  const back = <Link className="btn" to={id ? `${AR_PATH.creditNotes}/${id}` : AR_PATH.creditNotes}>Kembali</Link>
  if (!access.writable) {
    return (
      <>
        <PageHeader title={id ? 'Ubah nota kredit' : 'Nota kredit baru'} actions={back} />
        <EmptyState title="Modul hanya baca">Piutang usaha dalam mode hanya baca: nota kredit dapat dilihat dan diekspor, tetapi tidak dapat dibuat atau diubah.</EmptyState>
      </>
    )
  }
  if (note.data && note.data.status !== 'DRAFT') {
    return (
      <>
        <PageHeader title="Nota kredit tidak dapat diubah" actions={back} />
        <EmptyState title="Nota kredit tidak dapat diubah">Hanya nota kredit berstatus draf yang dapat diubah. Nota kredit yang sudah diposting dikoreksi dengan pembalikan.</EmptyState>
      </>
    )
  }
  return <CreditNoteFormView key={id ?? invoiceId ?? 'new'} note={note.data} prefill={prefill.data} />
}
