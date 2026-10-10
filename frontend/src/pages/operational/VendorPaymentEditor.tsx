import { useParams } from 'react-router-dom'
import { EmptyState, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useResource } from '../../lib/hooks'
import { API, MODULES, useModuleAccess } from '../../lib/operational'
import { PaymentFormView } from './payables/PaymentFormView'
import type { Payment } from './payables/types'

export default function VendorPaymentEditor() {
  const { id } = useParams()
  const access = useModuleAccess(MODULES.ap)
  const payment = useResource(async () => (id ? (await api.get<Payment>(`${API}/vendor-payments/${id}`)).data : null), [id])

  if (id && payment.loading && !payment.data) return <Loading />
  if (payment.error) return <ErrorNotice error={payment.error} onRetry={payment.reload} />

  if (!access.writable) {
    return (
      <>
        <PageHeader title={id ? 'Ubah pembayaran vendor' : 'Pembayaran vendor baru'} />
        <EmptyState title="Modul hanya baca">Utang usaha dalam mode hanya baca: pembayaran dapat dilihat dan diekspor, tetapi tidak dapat dibuat atau diubah.</EmptyState>
      </>
    )
  }
  if (payment.data && payment.data.status !== 'DRAFT') {
    return (
      <>
        <PageHeader title="Pembayaran tidak dapat diubah" />
        <EmptyState title="Pembayaran tidak dapat diubah">Hanya pembayaran berstatus draf yang dapat diubah. Pembayaran yang sudah diposting dikoreksi dengan pembalikan.</EmptyState>
      </>
    )
  }
  return <PaymentFormView key={id ?? 'new'} payment={payment.data} />
}
