import { useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog } from '../../components/Modal'
import { useToast } from '../../components/Toast'
import { Badge, Button, Card, EmptyState, ErrorNotice, Loading, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { useAction, type Resource } from '../../lib/hooks'
import { TemplateDialog } from './TemplateDialog'
import { ENDPOINTS } from './data'
import { closingError } from './labels'
import type { Template } from './types'

type Dialog = { kind: 'edit'; template: Template | null } | { kind: 'delete'; template: Template } | null

/** The manual checklist steps every period gets. Deleting is refused once a period used the template; deactivate it instead. */
export function TemplatesPanel({ templates, canManage }: { templates: Resource<Template[]>; canManage: boolean }) {
  const toast = useToast()
  const remove = useAction()
  const [dialog, setDialog] = useState<Dialog>(null)
  const rows = templates.data

  async function confirmDelete(template: Template) {
    const result = await remove.run(async () => (await api.delete(ENDPOINTS.template(template.id))).data)
    if (result.ok) {
      toast.success(`Butir ${template.code} dihapus.`)
      setDialog(null)
      templates.reload()
    }
  }

  return (
    <Card flush title="Butir daftar periksa penutupan" actions={canManage ? <Button variant="primary" size="sm" onClick={() => setDialog({ kind: 'edit', template: null })}>Tambah butir</Button> : undefined}>
      {templates.error ? (
        <ErrorNotice error={closingError(templates.error)} onRetry={templates.reload} />
      ) : !rows ? (
        <Loading />
      ) : rows.length === 0 ? (
        <EmptyState title="Belum ada butir">Butir manual (misalnya meninjau akrual atau menandatangani paket laporan) ditambahkan di sini dan muncul di daftar periksa setiap periode.</EmptyState>
      ) : (
        <DataTable
          caption="Butir daftar periksa penutupan"
          rows={rows}
          rowKey={(t) => t.id}
          scroll
          columns={[
            { header: 'Urutan', cell: (t) => t.sort_order },
            { header: 'Butir', primary: true, cell: (t) => <><strong>{t.title}</strong> <span className="muted">({t.code})</span>{t.description && <div className="muted">{t.description}</div>}</> },
            { header: 'Sifat', cell: (t) => (t.required ? <Badge tone="info">Wajib</Badge> : <Badge>Opsional</Badge>) },
            { header: 'Status', cell: (t) => <StatusBadge status={t.status} /> },
            {
              header: 'Tindakan',
              actions: true,
              cell: (t) => canManage && (
                <div className="toolbar">
                  <Button size="sm" aria-label={`Ubah ${t.code}`} onClick={() => setDialog({ kind: 'edit', template: t })}>Ubah</Button>
                  <Button size="sm" variant="danger" aria-label={`Hapus ${t.code}`} onClick={() => { remove.clearError(); setDialog({ kind: 'delete', template: t }) }}>Hapus</Button>
                </div>
              ),
            },
          ]}
        />
      )}
      {dialog?.kind === 'edit' && <TemplateDialog template={dialog.template} onDone={templates.reload} onClose={() => setDialog(null)} />}
      {dialog?.kind === 'delete' && (
        <ConfirmDialog
          title={`Hapus butir ${dialog.template.code}`}
          confirmLabel="Hapus"
          danger
          busy={remove.busy}
          error={closingError(remove.error)}
          message={<p>Butir <strong>{dialog.template.title}</strong> dihapus dari konfigurasi. Hanya butir yang belum pernah dipakai sebuah periode yang dapat dihapus; yang sudah dipakai harus dinonaktifkan agar riwayat periode tetap utuh.</p>}
          onConfirm={() => void confirmDelete(dialog.template)}
          onClose={() => setDialog(null)}
        />
      )}
    </Card>
  )
}
