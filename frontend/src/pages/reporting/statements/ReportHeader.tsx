import type { ReactNode } from 'react'
import { Badge, Card } from '../../../components/ui'
import { formatDateTime } from '../../../lib/format'
import { useCompanyName } from './data'
import { dateText, definitionStatusText } from './labels'
import type { DateRange, DefinitionRef } from './types'

/**
 * The identity of a statement, always shown above its figures: report title, company, date or period, functional currency, when it was produced,
 * which version of which definition produced it, and whether it covers the whole company. A printed or exported copy must say what it is.
 */
export function ReportHeader({ title, dates, definition, currency, generatedAt, complete, extra }: {
  title: string
  dates: { as_of?: string; range?: DateRange }
  definition: DefinitionRef
  currency: string | null
  generatedAt: string
  complete: boolean
  extra?: ReactNode
}) {
  const company = useCompanyName()
  const [status, tone] = definitionStatusText(definition.status)
  return (
    <Card title={title}>
      <dl className="facts">
        <div><dt>Perusahaan</dt><dd>{company}</dd></div>
        <div><dt>{dates.as_of ? 'Per tanggal' : 'Periode'}</dt><dd>{dateText(dates)}</dd></div>
        <div><dt>Mata uang fungsional</dt><dd>{currency ?? '—'}</dd></div>
        <div><dt>Dibuat pada</dt><dd>{formatDateTime(generatedAt)}</dd></div>
        <div><dt>Definisi laporan</dt><dd>{definition.name} · versi {definition.version} <Badge tone={tone}>{status}</Badge></dd></div>
        <div><dt>Cakupan data</dt><dd>{complete ? 'Seluruh perusahaan' : <Badge tone="warn">Sebagian</Badge>}</dd></div>
        {extra}
      </dl>
    </Card>
  )
}
