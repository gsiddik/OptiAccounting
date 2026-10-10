import { Link, useParams } from 'react-router-dom'
import { EmptyState, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { InvoiceFormView } from './payables/InvoiceFormView'
import type { Invoice } from './payables/types'

export default function ApInvoiceEditor() {
  const { id } = useParams()
  const access = useModuleAccess(MODULES.ap)
  const invoice = useResource(async () => (id ? (await api.get<Invoice>(`${API}/ap-invoices/${id}`)).data : null), [id])

  if (id && invoice.loading && !invoice.data) return <Loading />
  if (invoice.error) return <ErrorNotice error={invoice.error} onRetry={invoice.reload} />

  const back = <Link className="btn" to={id ? `/app/akuntansi/faktur-vendor/${id}` : '/app/akuntansi/faktur-vendor'}>Kembali</Link>
  if (!access.writable) {
    return (
      <>
        <PageHeader title={id ? 'Ubah faktur vendor' : 'Faktur vendor baru'} actions={back} />
        <EmptyState title="Modul hanya baca">Utang usaha dalam mode hanya baca: faktur dapat dilihat dan diekspor, tetapi tidak dapat dibuat atau diubah.</EmptyState>
      </>
    )
  }
  if (invoice.data && (invoice.data.status !== 'DRAFT' || invoice.data.origin !== 'INVOICE')) {
    return (
      <>
        <PageHeader title="Faktur tidak dapat diubah" actions={back} />
        <EmptyState title="Faktur tidak dapat diubah">Hanya faktur vendor berstatus draf yang dapat diubah. Buka fakturnya untuk melihat riwayat; koreksi faktur yang sudah diposting dilakukan dengan pembalikan.</EmptyState>
      </>
    )
  }
  return <InvoiceFormView key={id ?? 'new'} invoice={invoice.data} />
}
