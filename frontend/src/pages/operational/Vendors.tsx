import { useState } from 'react'
import { Card, PageHeader, Tabs } from '../../components/ui'
import { MODULES, useModuleAccess } from '../../lib/operational'
import { PaymentTermsPanel } from './payables/PaymentTermsPanel'
import { VendorsPanel } from './payables/VendorsPanel'
import { ReadOnlyNotice } from './shared'

type Tab = 'vendors' | 'terms'

export default function Vendors() {
  const access = useModuleAccess(MODULES.ap)
  const [tab, setTab] = useState<Tab>('vendors')
  const manage = access.canChange('accounting.vendor.manage')

  return (
    <>
      <PageHeader title="Vendor" description="Master vendor dan termin pembayarannya. Vendor yang sudah memiliki dokumen dinonaktifkan, tidak dihapus." />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <Tabs tabs={[{ id: 'vendors', label: 'Vendor' }, { id: 'terms', label: 'Termin pembayaran' }]} value={tab} onChange={setTab} />
        {tab === 'vendors' ? <VendorsPanel manage={manage} /> : <PaymentTermsPanel manage={manage} />}
      </Card>
    </>
  )
}
