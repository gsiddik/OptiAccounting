import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useToast } from '../../components/Toast'
import { Button, Card, EmptyState, ErrorNotice, Loading, PageHeader } from '../../components/ui'
import { api } from '../../lib/api'
import { useAction, useResource } from '../../lib/hooks'
import { useBusinessDate } from '../operational/payables/lists'
import { ExportButton, Filters, ReadOnlyNotice } from '../operational/shared'
import { CutoffFilter } from './components'
import { ENDPOINTS, PATHS, cutoffParams, hasCutoff, useReconAccess, useReconPeriods } from './data'
import { useExceptionActions } from './useExceptionActions'
import { ExceptionPanel } from './ExceptionPanel'
import { reconError, slugOf, typeHints, typeLabels, typeOfSlug } from './labels'
import { ResultView } from './ResultView'
import { RunsTable, StoredRun } from './RunPanels'
import type { Cutoff, ExceptionPage, ReconResult, RunPage, RunResponse } from './types'

export default function ReconciliationDetail() {
  const { id } = useParams()
  const type = typeOfSlug(id)
  const [query] = useSearchParams()
  const today = useBusinessDate()
  const access = useReconAccess()
  const toast = useToast()
  const { periods } = useReconPeriods()
  const [cutoff, setCutoff] = useState<Cutoff>({ as_of: query.get('as_of') ?? today, period_id: query.get('period_id') ?? '' })
  const [stored, setStored] = useState<RunResponse | null>(null)
  const save = useAction()

  const result = useResource(async () => (type && hasCutoff(cutoff) ? (await api.get<ReconResult>(ENDPOINTS.one(type), { params: cutoffParams(cutoff) })).data : null), [type, cutoff.as_of, cutoff.period_id])
  const asOf = result.data?.as_of ?? null
  const exceptions = useResource(
    async () => (type && asOf ? (await api.get<ExceptionPage>(ENDPOINTS.exceptions, { params: { type: slugOf(type), as_of_from: asOf, as_of_to: asOf, per_page: 20 } })).data.data : null),
    [type, asOf],
  )
  const runs = useResource(async () => (type ? (await api.get<RunPage>(ENDPOINTS.runs, { params: { type: slugOf(type), per_page: 10 } })).data.data : null), [type])
  const refresh = () => {
    result.reload()
    exceptions.reload()
    runs.reload()
  }
  const { start, dialogs } = useExceptionActions(refresh)

  if (!type) {
    return (
      <EmptyState title="Jenis rekonsiliasi tidak dikenal" action={<Link to={PATHS.overview}>Kembali ke ringkasan rekonsiliasi</Link>}>
        Alamat ini tidak menunjuk jenis rekonsiliasi yang ada.
      </EmptyState>
    )
  }

  async function runAndSave() {
    const r = await save.run(async () => (await api.post<RunResponse>(ENDPOINTS.run(type!), cutoffParams(cutoff))).data)
    if (!r.ok) return
    setStored(r.value)
    toast.success('Hasil rekonsiliasi disimpan.')
    refresh()
  }
  const change = (next: Cutoff) => {
    setStored(null)
    save.clearError()
    setCutoff(next)
  }

  return (
    <>
      <PageHeader
        title={typeLabels[type]}
        description={typeHints[type]}
        actions={
          <>
            {access.canRun && <Button variant="primary" loading={save.busy} disabled={!hasCutoff(cutoff)} onClick={() => void runAndSave()}>Jalankan &amp; simpan</Button>}
            {hasCutoff(cutoff) && <ExportButton path={ENDPOINTS.exportOne(type)} params={cutoffParams(cutoff)} filename={`rekonsiliasi_${slugOf(type)}_${asOf ?? cutoff.as_of ?? 'periode'}.csv`} />}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      {save.error != null && <ErrorNotice error={reconError(save.error)} />}
      <Card flush>
        <div className="card-body">
          <Filters>
            <CutoffFilter value={cutoff} onChange={change} periods={periods} />
          </Filters>
        </div>
        {result.error ? <ErrorNotice error={reconError(result.error)} onRetry={result.reload} /> : result.data ? <ResultView result={result.data} /> : result.loading ? <Loading /> : <EmptyState title="Pilih tanggal">Pilih tanggal atau periode untuk melihat rekonsiliasi.</EmptyState>}
      </Card>

      {stored && <StoredRun run={stored.run} exception={stored.exception} />}

      {result.data && (exceptions.error ? (
        <Card title="Pengecualian"><ErrorNotice error={reconError(exceptions.error)} onRetry={exceptions.reload} /></Card>
      ) : exceptions.data ? (
        <ExceptionPanel exceptions={exceptions.data} canManage={access.canManage} canRun={access.canRun} calculated={result.data.status} onAction={start} />
      ) : (
        <Card title="Pengecualian"><Loading /></Card>
      ))}

      {runs.error ? <Card title="Jalankan terakhir"><ErrorNotice error={reconError(runs.error)} onRetry={runs.reload} /></Card> : runs.data ? <RunsTable runs={runs.data} /> : <Card title="Jalankan terakhir"><Loading /></Card>}
      {dialogs}
    </>
  )
}
