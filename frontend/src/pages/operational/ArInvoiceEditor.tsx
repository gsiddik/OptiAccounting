import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { ArInvoiceFormView } from './receivables/ArInvoiceFormView'
import { AR_PATH } from './receivables/paths'
import type { ArInvoice } from './receivables/types'

export default function ArInvoiceEditor() {
  const { id } = useParams()
  const access = useModuleAccess(MODULES.ar)
  const invoice = useResource(async () => (id ? (await api.get<ArInvoice>(`${API}/ar-invoices/${id}`)).data : null), [id])

  if (id && invoice.loading && !invoice.data) return <Loading />
  if (invoice.error) return <ErrorNotice error={invoice.error} onRetry={invoice.reload} />

  const back = <Link className="btn" to={id ? `${AR_PATH.invoices}/${id}` : AR_PATH.invoices}>Kembali</Link>
  if (!access.writable) {
    return (
      <>
        <PageHeader title={id ? 'Ubah faktur pelanggan' : 'Faktur pelanggan baru'} actions={back} />
        <EmptyState title="Modul hanya baca">Piutang usaha dalam mode hanya baca: faktur dapat dilihat dan diekspor, tetapi tidak dapat dibuat atau diubah.</EmptyState>
      </>
    )
  }
  if (invoice.data && invoice.data.status !== 'DRAFT') {
    return (
      <>
        <PageHeader title="Faktur tidak dapat diubah" actions={back} />
        <EmptyState title="Faktur tidak dapat diubah">Hanya faktur pelanggan berstatus draf yang dapat diubah. Buka fakturnya untuk melihat riwayat; koreksi faktur yang sudah diposting dilakukan dengan pembalikan atau nota kredit.</EmptyState>
      </>
    )
  }
  return <ArInvoiceFormView key={id ?? 'new'} invoice={invoice.data} />
}
