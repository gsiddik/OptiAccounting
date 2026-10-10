import { useParams } from 'react-router-dom'
import { EmptyState, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { ReceiptFormView } from './receivables/ReceiptFormView'
import type { Receipt } from './receivables/types'

export default function CustomerReceiptEditor() {
  const { id } = useParams()
  const access = useModuleAccess(MODULES.ar)
  const receipt = useResource(async () => (id ? (await api.get<Receipt>(`${API}/customer-receipts/${id}`)).data : null), [id])

  if (id && receipt.loading && !receipt.data) return <Loading />
  if (receipt.error) return <ErrorNotice error={receipt.error} onRetry={receipt.reload} />

  if (!access.writable) {
    return (
      <>
        <PageHeader title={id ? 'Ubah penerimaan pelanggan' : 'Penerimaan pelanggan baru'} />
        <EmptyState title="Modul hanya baca">Piutang usaha dalam mode hanya baca: penerimaan dapat dilihat dan diekspor, tetapi tidak dapat dibuat atau diubah.</EmptyState>
      </>
    )
  }
  if (receipt.data && receipt.data.status !== 'DRAFT') {
    return (
      <>
        <PageHeader title="Penerimaan tidak dapat diubah" />
        <EmptyState title="Penerimaan tidak dapat diubah">Hanya penerimaan berstatus draf yang dapat diubah. Penerimaan yang sudah diposting dikoreksi dengan pembalikan.</EmptyState>
      </>
    )
  }
  return <ReceiptFormView key={id ?? 'new'} receipt={receipt.data} />
}
