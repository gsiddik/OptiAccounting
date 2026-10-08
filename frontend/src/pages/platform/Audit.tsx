import { AuditTable } from '../../components/AuditTable'
import { Card, PageHeader } from '../../components/ui'

export default function PlatformAudit() {
  return (
    <>
      <PageHeader title="Jejak Audit Platform" description="Catatan perubahan yang tidak dapat diubah atau dihapus: siapa, apa, kapan, dan nilai sebelum/sesudahnya." />
      <Card flush>
        <AuditTable endpoint="/platform/audit-logs" />
      </Card>
    </>
  )
}
