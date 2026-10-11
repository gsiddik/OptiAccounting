import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useToast } from '../../components/Toast'
import { Banner, Button, Card, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate, formatDateTime } from '../../lib/format'
import { useAction, useResource } from '../../lib/hooks'
import { describeError } from '../../lib/labels'
import { ReadOnlyNotice } from '../operational/shared'
import { ActionConfirm } from './definitions/ActionConfirm'
import { DefinitionBadge, ValidationPanel } from './definitions/components'
import { ENDPOINTS, PATHS, accountLookup, movedLines, useDefinitionAccess, windowText, withoutMapping } from './definitions/data'
import { ArchiveDialog, PublishDialog, RenameDialog } from './definitions/DefinitionDialogs'
import { definitionError, statementLabels } from './definitions/labels'
import { LineDialog } from './definitions/LineDialog'
import { LinesCard } from './definitions/LinesCard'
import { MappingDialog, type MapAccount } from './definitions/MappingDialog'
import { UnmappedPanel } from './definitions/UnmappedPanel'
import type { DefinitionDetail, LineMapping, ReportLine, Validation } from './definitions/types'

type Dialog =
  | { kind: 'rename' | 'publish' | 'archive' | 'newVersion' | 'deleteDraft' }
  | { kind: 'line'; line: ReportLine | null; parent?: string }
  | { kind: 'deleteLine'; line: ReportLine }
  | { kind: 'map'; line?: string; account?: MapAccount }

export default function ReportDefinitionDetail() {
  const { id } = useParams()
  const definition = useResource(async () => (await api.get<DefinitionDetail>(ENDPOINTS.one(id ?? ''))).data, [id])

  if (definition.loading && !definition.data) return <Loading />
  if (definition.error || !definition.data) return <ErrorNotice error={definitionError(definition.error)} onRetry={definition.reload} />
  return <DefinitionView key={definition.data.id} definition={definition.data} onChange={(d) => definition.setData(() => d)} />
}

function DefinitionView({ definition, onChange }: { definition: DefinitionDetail; onChange: (detail: DefinitionDetail) => void }) {
  const navigate = useNavigate()
  const toast = useToast()
  const access = useDefinitionAccess()
  const act = useAction()
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const [validation, setValidation] = useState<Validation | null>(null)
  const [refresh, setRefresh] = useState(0)
  const draft = definition.status === 'DRAFT'
  const editable = draft && access.canManage
  const close = () => setDialog(null)

  /** A changed definition from any mutation: show it, keep the validation the server returned with it, and refresh the unmapped list. */
  const apply = (next: DefinitionDetail) => {
    setDialog(null)
    if (next.validation) setValidation(next.validation)
    else if (next.warnings) setValidation({ valid: true, errors: [], warnings: next.warnings })
    onChange(next)
    setRefresh((k) => k + 1)
  }

  async function validate() {
    const r = await act.run(async () => (await api.post<Validation>(ENDPOINTS.validate(definition.id))).data)
    if (r.ok) setValidation(r.value)
  }
  async function mutate(request: () => Promise<DefinitionDetail>, success: string) {
    const r = await act.run(request)
    if (r.ok) {
      toast.success(success)
      apply(r.value)
    }
  }
  const move = (line: ReportLine, direction: -1 | 1) => {
    const lines = movedLines(definition.lines, line.code, direction)
    if (lines) void mutate(async () => (await api.put<DefinitionDetail>(ENDPOINTS.lines(definition.id), { lines })).data, 'Urutan baris disimpan.')
  }
  const unmap = (line: ReportLine, m: LineMapping) =>
    void mutate(async () => (await api.put<DefinitionDetail>(ENDPOINTS.mappings(definition.id), { mappings: withoutMapping(definition.lines, line.code, m.account_id) })).data, 'Akun dilepas dari baris.')

  const lookup = accountLookup(definition.lines)
  const based = definition.based_on_id

  return (
    <>
      <PageHeader
        title={definition.name}
        description={<>{statementLabels[definition.statement_type]} · versi {definition.version} · <DefinitionBadge status={definition.status} /></>}
        actions={access.canManage && (
          <>
            {draft && <Button onClick={() => setDialog({ kind: 'rename' })}>Ubah nama</Button>}
            {draft && <Button variant="primary" onClick={() => setDialog({ kind: 'publish' })}>Terbitkan</Button>}
            {draft && <Button variant="danger" onClick={() => setDialog({ kind: 'deleteDraft' })}>Hapus draf</Button>}
            {!draft && <Button variant={definition.status === 'ACTIVE' ? 'primary' : 'secondary'} onClick={() => setDialog({ kind: 'newVersion' })}>Versi baru</Button>}
            {definition.status === 'ACTIVE' && <Button onClick={() => setDialog({ kind: 'archive' })}>Arsipkan</Button>}
          </>
        )}
      />
      <ReadOnlyNotice show={access.readOnly} />
      {!draft && (
        <Banner tone="info">
          Definisi ini sudah {definition.status === 'ACTIVE' ? 'terbit' : 'diarsipkan'} dan bersifat final: baris dan pemetaannya tidak dapat diubah. Untuk merevisi, buat <strong>versi baru</strong> (draf), perbaiki, lalu terbitkan;
          versi ini tetap melayani tanggal berlakunya sehingga laporan lama tidak berubah.
        </Banner>
      )}
      {draft && !editable && <Banner tone="info">Draf ini hanya dapat dilihat. Mengubah baris dan pemetaan membutuhkan izin kelola pemetaan laporan dan modul pelaporan yang dapat diubah.</Banner>}
      {act.error != null && <Banner tone="bad">{describeError(definitionError(act.error))}</Banner>}

      <Card>
        <dl className="facts">
          <div><dt>Jenis laporan</dt><dd>{statementLabels[definition.statement_type]}</dd></div>
          <div><dt>Kode</dt><dd className="mono">{definition.code}</dd></div>
          <div><dt>Versi</dt><dd>{definition.version}{based && <> · <Link to={PATHS.detail(based)}>berdasarkan versi sebelumnya</Link></>}</dd></div>
          <div><dt>Berlaku</dt><dd>{windowText(definition)}</dd></div>
          {definition.published_at && <div><dt>Diterbitkan</dt><dd>{formatDateTime(definition.published_at)}</dd></div>}
          {definition.archived_at && <div><dt>Diarsipkan</dt><dd>{formatDate(definition.archived_at)}</dd></div>}
          <div className="wide"><dt>Keterangan</dt><dd>{definition.description ?? '—'}</dd></div>
        </dl>
      </Card>

      <ValidationPanel validation={validation} lookup={lookup} busy={act.busy} onValidate={() => void validate()} />

      <LinesCard
        lines={definition.lines}
        editable={editable}
        busy={act.busy}
        handlers={{
          onAdd: (parent) => setDialog({ kind: 'line', line: null, parent }),
          onEdit: (line) => setDialog({ kind: 'line', line }),
          onDelete: (line) => setDialog({ kind: 'deleteLine', line }),
          onMap: (line) => setDialog({ kind: 'map', line: line.code }),
          onUnmap: unmap,
          onMove: move,
        }}
      />
      <UnmappedPanel definition={definition} canMap={editable} refreshKey={refresh} onMap={(account) => setDialog({ kind: 'map', account })} />

      {dialog?.kind === 'rename' && <RenameDialog definition={definition} onClose={close} onSaved={apply} />}
      {dialog?.kind === 'publish' && <PublishDialog definition={definition} onClose={close} onPublished={apply} />}
      {dialog?.kind === 'archive' && <ArchiveDialog definition={definition} onClose={close} onArchived={apply} />}
      {dialog?.kind === 'line' && <LineDialog definition={definition} line={dialog.line} parentCode={dialog.parent} onClose={close} onSaved={apply} />}
      {dialog?.kind === 'map' && <MappingDialog definition={definition} lineCode={dialog.line} account={dialog.account} onClose={close} onSaved={apply} />}
      {dialog?.kind === 'deleteLine' && (
        <ActionConfirm
          title={`Hapus baris ${dialog.line.code}`}
          message={<p>Baris <strong>{dialog.line.label}</strong> dihapus dari draf beserta pemetaan akunnya. Baris yang masih memiliki sub-baris tidak dapat dihapus.</p>}
          confirmLabel="Hapus baris"
          danger
          success="Baris dihapus."
          request={async () => (await api.delete<DefinitionDetail>(ENDPOINTS.line(definition.id, dialog.line.id))).data}
          onDone={apply}
          onClose={close}
        />
      )}
      {dialog?.kind === 'newVersion' && (
        <ActionConfirm
          title={`Buat versi baru dari versi ${definition.version}`}
          message={<p>Draf baru berisi salinan baris dan pemetaan versi ini. Versi ini tidak berubah dan tetap berlaku sampai draf baru diterbitkan. Hanya boleh ada satu draf per jenis laporan.</p>}
          confirmLabel="Buat versi baru"
          success="Draf versi baru dibuat."
          request={async () => (await api.post<DefinitionDetail>(ENDPOINTS.newVersion(definition.id))).data}
          onDone={(next) => navigate(PATHS.detail(next.id))}
          onClose={close}
        />
      )}
      {dialog?.kind === 'deleteDraft' && (
        <ActionConfirm
          title="Hapus draf"
          message={<p>Draf versi {definition.version} dihapus beserta seluruh baris dan pemetaannya. Hanya draf yang belum pernah terbit yang dapat dihapus; tindakan ini tidak dapat dibatalkan.</p>}
          confirmLabel="Hapus draf"
          danger
          success="Draf dihapus."
          request={async () => (await api.delete(ENDPOINTS.one(definition.id))).data}
          onDone={() => navigate(PATHS.list)}
          onClose={close}
        />
      )}
    </>
  )
}
