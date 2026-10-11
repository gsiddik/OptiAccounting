import { Badge, Banner, Button, Card } from '../../../components/ui'
import { findingText, statusText } from './labels'
import type { Finding, Validation } from './types'

export function DefinitionBadge({ status }: { status: string }) {
  const [label, tone] = statusText(status)
  return <Badge tone={tone}>{label}</Badge>
}

type Lookup = (accountId: unknown) => string | null

/** The findings of the validator, one per line, with the line each one is about. */
export function FindingList({ findings, tone, lookup }: { findings: Finding[]; tone: 'bad' | 'warn'; lookup?: Lookup }) {
  if (findings.length === 0) return null
  return (
    <ul style={{ margin: 0, paddingLeft: 20 }} aria-label={tone === 'bad' ? 'Kesalahan' : 'Peringatan'}>
      {findings.map((f, i) => (
        <li key={`${f.code}-${f.line ?? ''}-${i}`}>
          {findingText(f, lookup)}
          {f.line && <span className="muted"> · baris <span className="mono">{f.line}</span></span>}
        </li>
      ))}
    </ul>
  )
}

/** Errors and warnings a refusal (publish, save mapping) carried, shown under the message of the refusal. */
export function RefusalFindings({ errors, warnings, lookup }: { errors: Finding[]; warnings: Finding[]; lookup?: Lookup }) {
  if (errors.length === 0 && warnings.length === 0) return null
  return (
    <div className="stack" style={{ marginTop: 12 }}>
      {errors.length > 0 && <div><strong>Kesalahan ({errors.length})</strong><FindingList findings={errors} tone="bad" lookup={lookup} /></div>}
      {warnings.length > 0 && <div><strong>Peringatan ({warnings.length})</strong><FindingList findings={warnings} tone="warn" lookup={lookup} /></div>}
    </div>
  )
}

/** Result of the last validation: valid or not, then every error (blocks publishing) and warning (reported only). */
export function ValidationPanel({ validation, lookup, busy, onValidate }: { validation: Validation | null; lookup?: Lookup; busy: boolean; onValidate: () => void }) {
  return (
    <Card title="Validasi definisi" actions={<Button size="sm" loading={busy} onClick={onValidate}>Validasi sekarang</Button>}>
      {validation === null ? (
        <p className="muted">Belum diperiksa pada sesi ini. Validasi memeriksa struktur baris, rumus, dan pemetaan akun, serta akun bersaldo yang belum dipetakan.</p>
      ) : (
        <div className="stack">
          {validation.valid ? (
            <Banner tone={validation.warnings.length > 0 ? 'warn' : 'ok'}>
              <strong>Definisi valid.</strong> {validation.warnings.length > 0 ? `Ada ${validation.warnings.length} peringatan; tidak menghalangi penerbitan.` : 'Tidak ada kesalahan maupun peringatan.'}
            </Banner>
          ) : (
            <Banner tone="bad"><strong>Definisi belum valid.</strong> {validation.errors.length} kesalahan harus diperbaiki sebelum diterbitkan atau dipakai laporan.</Banner>
          )}
          {validation.errors.length > 0 && <div><strong>Kesalahan</strong><FindingList findings={validation.errors} tone="bad" lookup={lookup} /></div>}
          {validation.warnings.length > 0 && <div><strong>Peringatan</strong><FindingList findings={validation.warnings} tone="warn" lookup={lookup} /></div>}
        </div>
      )}
    </Card>
  )
}
