import { useState } from 'react'
import { Card, PageHeader, Tabs } from '../../components/ui'
import { MODULES, useModuleAccess } from '../../lib/operational'
import { PaymentTermsPanel } from './payables/PaymentTermsPanel'
import { CustomersPanel } from './receivables/CustomersPanel'
import { ReadOnlyNotice } from './shared'

type Tab = 'customers' | 'terms'

export default function Customers() {
  const access = useModuleAccess(MODULES.ar)
  const [tab, setTab] = useState<Tab>('customers')
  const manage = access.canChange('accounting.customer.manage')

  return (
    <>
      <PageHeader title="Pelanggan" description="Master pelanggan dan termin pembayarannya. Pelanggan yang sudah memiliki dokumen dinonaktifkan, tidak dihapus. Batas kredit hanya informasi." />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <Tabs tabs={[{ id: 'customers', label: 'Pelanggan' }, { id: 'terms', label: 'Termin pembayaran' }]} value={tab} onChange={setTab} />
        {tab === 'customers' ? <CustomersPanel manage={manage} /> : <PaymentTermsPanel manage={manage} endpoint="ar-payment-terms" usedBy="pelanggan atau faktur" />}
      </Card>
    </>
  )
}
