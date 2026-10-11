import { Banner, Card, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useResource } from '../../lib/hooks'
import { ReadOnlyNotice } from '../operational/shared'
import { PolicyEditor } from './PolicyEditor'
import { TemplatesPanel } from './TemplatesPanel'
import { ENDPOINTS, useClosingAccess } from './data'
import { closingError } from './labels'
import type { Policy, Template } from './types'

export default function ClosingPolicy() {
  const access = useClosingAccess()
  const policy = useResource(async () => (await api.get<Policy>(ENDPOINTS.policy)).data, [])
  const templates = useResource(async () => (await api.get<{ data: Template[] }>(ENDPOINTS.templates)).data.data, [])

  return (
    <>
      <PageHeader
        title="Kebijakan penutupan"
        description="Aturan yang menentukan apa yang menghalangi penutupan periode, batas membuka kembali periode, dan butir manual daftar periksa. Setiap perubahan diaudit dengan nilai lama dan baru."
      />
      <ReadOnlyNotice show={access.readOnly} />
      {!access.readOnly && !access.can('accounting.closing.policy.manage') && <Banner tone="info">Tampilan hanya baca: mengubah kebijakan penutupan memerlukan izin kelola kebijakan penutupan.</Banner>}
      <Banner tone="warn">Tiga pemeriksaan adalah aturan akuntansi yang tetap (neraca saldo seimbang, tidak ada jurnal diajukan atau disetujui yang belum diposting, butir wajib daftar periksa). Pemeriksaan itu terkunci dan tidak dapat dilonggarkan oleh kebijakan apa pun.</Banner>

      <Card flush title="Pemeriksaan dan pengaturan">
        {policy.error ? (
          <ErrorNotice error={closingError(policy.error)} onRetry={policy.reload} />
        ) : !policy.data ? (
          <Loading />
        ) : (
          <PolicyEditor key={JSON.stringify(policy.data)} policy={policy.data} editable={access.canPolicy} onSaved={(saved) => policy.setData(() => saved)} />
        )}
      </Card>
      <TemplatesPanel templates={templates} canManage={access.canPolicy} />
    </>
  )
}
