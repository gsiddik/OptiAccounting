import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { Badge, Button, Card, EmptyState, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { formatNumber } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { Filters, ReadOnlyNotice } from '../operational/shared'
import { DefinitionBadge } from './definitions/components'
import { ENDPOINTS, PATHS, useDefinitionAccess, windowText } from './definitions/data'
import { CreateDefinitionDialog, DefaultsDialog } from './definitions/DefinitionDialogs'
import { definitionError, statementLabels, statusTexts } from './definitions/labels'
import { DEFINITION_STATUSES, STATEMENT_TYPES, type DefinitionHeader, type UnmappedAccount } from './definitions/types'

/** How many accounts the definition leaves out. Counted by the server for each draft or published definition; an archived one is not asked. */
function UnmappedCount({ definition }: { definition: DefinitionHeader }) {
  const active = definition.status !== 'ARCHIVED'
  const unmapped = useResource(async () => (active ? (await api.get<{ data: UnmappedAccount[] }>(ENDPOINTS.unmapped(definition.id))).data.data.length : null), [definition.id, active])
  if (!active || unmapped.error) return <span className="muted">—</span>
  if (unmapped.data === null) return <span className="muted" aria-label="Memuat">…</span>
  return unmapped.data === 0 ? <Badge tone="ok">Lengkap</Badge> : <Badge tone="warn">{formatNumber(unmapped.data)} akun</Badge>
}

export default function ReportDefinitions() {
  const navigate = useNavigate()
  const access = useDefinitionAccess()
  const [f, setF] = useState({ statement_type: '', status: '' })
  const [dialog, setDialog] = useState<null | 'create' | 'defaults'>(null)
  const list = useResource(async () => (await api.get<{ data: DefinitionHeader[] }>(ENDPOINTS.list, { params: Object.fromEntries(Object.entries(f).filter(([, v]) => v)) })).data.data, [f.statement_type, f.status])
  const rows = list.data ?? []

  return (
    <>
      <PageHeader
        title="Pemetaan laporan keuangan"
        description="Definisi laporan keuangan: struktur baris dan pemetaan akun ke baris. Definisi yang terbit bersifat final dan berlaku pada rentang tanggal tertentu; revisi dibuat sebagai versi baru."
        actions={access.canManage && (
          <>
            <Button onClick={() => setDialog('defaults')}>Terapkan bawaan</Button>
            <Button variant="primary" onClick={() => setDialog('create')}>Definisi baru</Button>
          </>
        )}
      />
      <ReadOnlyNotice show={access.readOnly} />
      <Card flush>
        <div className="card-body">
          <Filters>
            <select className="select" aria-label="Jenis laporan" value={f.statement_type} onChange={(e) => setF((s) => ({ ...s, statement_type: e.target.value }))}>
              <option value="">Semua jenis laporan</option>
              {STATEMENT_TYPES.map((t) => <option key={t} value={t}>{statementLabels[t]}</option>)}
            </select>
            <select className="select" aria-label="Status definisi" value={f.status} onChange={(e) => setF((s) => ({ ...s, status: e.target.value }))}>
              <option value="">Semua status</option>
              {DEFINITION_STATUSES.map((s) => <option key={s} value={s}>{statusTexts[s][0]}</option>)}
            </select>
          </Filters>
        </div>
        {list.loading && !list.data ? (
          <Loading />
        ) : list.error ? (
          <ErrorNotice error={definitionError(list.error)} onRetry={list.reload} />
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada definisi laporan">{access.canManage ? 'Pakai Terapkan bawaan untuk membuat draf dari bagan akun, atau buat definisi baru.' : 'Belum ada definisi yang dapat ditampilkan.'}</EmptyState>
        ) : (
          <DataTable
            caption="Daftar definisi laporan"
            scroll
            rows={rows}
            rowKey={(d) => d.id}
            columns={[
              { header: 'Definisi', primary: true, cell: (d) => <><Link to={PATHS.detail(d.id)}>{d.name}</Link><div className="muted">{statementLabels[d.statement_type]} · <span className="mono">{d.code}</span></div></> },
              { header: 'Versi', cell: (d) => `Versi ${d.version}` },
              { header: 'Status', cell: (d) => <DefinitionBadge status={d.status} /> },
              { header: 'Berlaku', cell: (d) => windowText(d) },
              { header: 'Baris / pemetaan', align: 'right', cell: (d) => `${formatNumber(d.lines_count)} / ${formatNumber(d.mappings_count)}` },
              { header: 'Akun belum dipetakan', cell: (d) => <UnmappedCount definition={d} /> },
              { header: 'Aksi', actions: true, cell: (d) => <Link className="btn btn-sm" to={PATHS.detail(d.id)} aria-label={`Buka ${d.name} versi ${d.version}`}>Buka</Link> },
            ]}
          />
        )}
      </Card>
      {dialog === 'create' && <CreateDefinitionDialog onClose={() => setDialog(null)} onCreated={(id) => navigate(PATHS.detail(id))} />}
      {dialog === 'defaults' && <DefaultsDialog onClose={() => setDialog(null)} onDone={list.reload} />}
    </>
  )
}
