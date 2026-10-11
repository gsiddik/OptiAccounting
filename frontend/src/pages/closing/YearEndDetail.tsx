import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Button, Card, ErrorNotice, Loading, PageHeader, StatusBadge } from '../../components/ui'
import { api } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { useResource } from '../../lib/hooks'
import { ReadOnlyNotice } from '../operational/shared'
import { ReverseDialog, RunDialog } from './YearEndDialogs'
import { PreviewPanels } from './YearEndPreview'
import { YearEndRuns } from './YearEndRuns'
import { Facts, ServerDecides, YearEndBadge } from './components'
import { ENDPOINTS, PATHS, useClosingAccess } from './data'
import { closingError, fiscalYearStates } from './labels'
import type { YearEndPreview, YearEndShow } from './types'

export default function YearEndDetail() {
  const { id = '' } = useParams()
  const access = useClosingAccess()
  const detail = useResource(async () => (await api.get<YearEndShow>(ENDPOINTS.yearEndOne(id))).data, [id])
  const preview = useResource(async () => (await api.get<YearEndPreview>(ENDPOINTS.preview(id))).data, [id])
  const [dialog, setDialog] = useState<'run' | 'reverse' | null>(null)
  const year = detail.data
  const pv = preview.data

  // After a run or a reversal the year, its runs and the preview are all read again from the server.
  const reloadAll = () => {
    detail.reload()
    preview.reload()
  }

  if (detail.error) return <ErrorNotice error={closingError(detail.error)} onRetry={detail.reload} />
  if (!year) return <Loading />

  const posted = year.year_end.status === 'POSTED'
  return (
    <>
      <PageHeader
        title={`Tutup tahun ${year.code}`}
        description={<>{year.name} · {formatDate(year.start_date)} – {formatDate(year.end_date)} · <YearEndBadge status={year.year_end.status} /></>}
        actions={
          <>
            <Link className="btn" to={PATHS.yearEnd}>Semua tahun fiskal</Link>
            {access.canRunYearEnd && !posted && <Button variant="primary" disabled={!pv || !pv.can_run} onClick={() => setDialog('run')}>Jalankan tutup tahun</Button>}
            {access.canRunYearEnd && posted && <Button variant="danger" onClick={() => setDialog('reverse')}>Balik tutup tahun</Button>}
          </>
        }
      />
      <ReadOnlyNotice show={access.readOnly} />
      <ServerDecides />

      <Card title="Tahun fiskal">
        <Facts rows={[
          ['Status tahun fiskal', fiscalYearStates[year.status] ?? year.status],
          ['Tanggal penutupan', formatDate(year.closing_date)],
          ['Periode terakhir', year.last_period && <><Link to={PATHS.period(year.last_period.id)}>{year.last_period.code}</Link> <StatusBadge status={year.last_period.status} /></>],
          ['Aturan posting', pv?.posting_rule ? `${pv.posting_rule.code} v${pv.posting_rule.version} · sejak ${formatDate(pv.posting_rule.effective_from)}` : pv && 'Belum terbit'],
          ['Akun laba ditahan', pv?.retained_earnings_account ? `${pv.retained_earnings_account.code} · ${pv.retained_earnings_account.name}` : pv && 'Belum dipetakan'],
          ['Status tutup tahun', <YearEndBadge key="s" status={year.year_end.status} />],
        ]} />
      </Card>

      {preview.error ? <Card><ErrorNotice error={closingError(preview.error)} onRetry={preview.reload} /></Card> : !pv ? <Card><Loading label="Menghitung pratinjau…" /></Card> : (
        <>
          <p className="muted preview-note">Pratinjau dihitung ulang dari jurnal terposting setiap kali halaman dibuka dan tidak menulis apa pun.</p>
          <PreviewPanels preview={pv} />
        </>
      )}
      <YearEndRuns runs={year.runs} />

      {dialog === 'run' && pv && <RunDialog year={year} preview={pv} onDone={reloadAll} onClose={() => setDialog(null)} />}
      {dialog === 'reverse' && <ReverseDialog year={year} onDone={reloadAll} onClose={() => setDialog(null)} />}
    </>
  )
}
