import type { ReactNode } from 'react'
import { Badge } from '../../components/ui'
import { blockerTitles, checkResultText, eventText, itemText, readinessText, refusedBlockers, refusedChecks, severityText, yearEndText, type Tone } from './labels'

/** A compact bullet list (the browser default margins are too loose inside table cells and banners). */
export function Bullets({ children }: { children: ReactNode }) {
  return <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>{children}</ul>
}

export function Tagged({ text }: { text: [string, Tone] }) {
  return <Badge tone={text[1]}>{text[0]}</Badge>
}

export const ReadinessBadge = ({ result }: { result: string | null | undefined }) => <Tagged text={readinessText(result)} />
export const CheckBadge = ({ result }: { result: string }) => <Tagged text={checkResultText(result)} />
export const SeverityBadge = ({ severity }: { severity: string }) => <Tagged text={severityText(severity)} />
export const ItemBadge = ({ status }: { status: string }) => <Tagged text={itemText(status)} />
export const EventBadge = ({ type }: { type: string }) => <Tagged text={eventText(type)} />
export const YearEndBadge = ({ status }: { status: string | null | undefined }) => <Tagged text={yearEndText(status)} />

/** Label/value pairs in the shared `facts` grid; a row whose value is false, null or undefined is left out. */
export function Facts({ rows }: { rows: [string, ReactNode][] }) {
  return (
    <dl className="facts">
      {rows.filter(([, value]) => value !== null && value !== undefined && value !== false).map(([label, value]) => (
        <div key={label}><dt>{label}</dt><dd>{value}</dd></div>
      ))}
    </dl>
  )
}

/** The statement every closing screen makes: the screen shows, the server decides. */
export function ServerDecides() {
  return <p className="muted preview-note">Layar ini hanya menampilkan hasil hitung server. Server memeriksa ulang kesiapan dan hak akses pada setiap tindakan, jadi sebuah tombol tidak menjamin tindakannya akan diterima.</p>
}

/** The failing checks a refused hard close (409 PERIOD_NOT_READY) lists, inside the dialog that sent it. */
export function RefusedChecks({ error }: { error: unknown }) {
  const checks = refusedChecks(error)
  if (checks.length === 0) return null
  return (
    <div>
      <strong>Pemeriksaan penghalang</strong>
      <Bullets>
        {checks.map((c) => <li key={c.code}><strong>{c.title}</strong> <span className="muted">({c.code})</span>{c.message && <div>{c.message}</div>}</li>)}
      </Bullets>
    </div>
  )
}

/** The blockers a refused year-end run lists (`details.blockers`), first one first. */
export function RefusedBlockers({ error }: { error: unknown }) {
  const blockers = refusedBlockers(error)
  if (blockers.length === 0) return null
  return (
    <div>
      <strong>Penghalang yang dilaporkan server</strong>
      <Bullets>
        {blockers.map((b) => <li key={b.code}><strong>{b.code}</strong>: {blockerTitles[b.code] ?? b.message}</li>)}
      </Bullets>
    </div>
  )
}
