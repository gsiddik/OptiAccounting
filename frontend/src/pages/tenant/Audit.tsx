import { AuditTable } from '../../components/AuditTable'
import { Card, PageHeader } from '../../components/ui'

export default function TenantAudit() {
  return (
    <>
      <PageHeader title="Jejak Audit" description="Catatan perubahan akses, organisasi, dan langganan yang tidak dapat diubah atau dihapus." />
      <Card flush>
        <AuditTable endpoint="/app/audit-logs" />
      </Card>
    </>
  )
}
