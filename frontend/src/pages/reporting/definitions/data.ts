import { formatDate } from '../../../lib/format'
import { API, useModuleAccess } from '../../../lib/operational'
import { CONTAINER_TYPES, MAPPED_TYPES, type BalanceSide, type DefinitionHeader, type LineType, type MappingEntry, type Operand, type ReportLine, type StatementType } from './types'

export const REPORTING = 'ACCOUNTING_REPORTING'

export const PATHS = {
  list: '/app/akuntansi/pemetaan-laporan',
  detail: (id: string) => `/app/akuntansi/pemetaan-laporan/${id}`,
  ledger: '/app/akuntansi/buku-besar',
} as const

const DEFINITIONS = `${API}/report-definitions`

export const ENDPOINTS = {
  list: DEFINITIONS,
  defaults: `${DEFINITIONS}/defaults`,
  one: (id: string) => `${DEFINITIONS}/${id}`,
  lines: (id: string) => `${DEFINITIONS}/${id}/lines`,
  line: (id: string, lineId: string) => `${DEFINITIONS}/${id}/lines/${lineId}`,
  mappings: (id: string) => `${DEFINITIONS}/${id}/mappings`,
  unmapped: (id: string) => `${DEFINITIONS}/${id}/unmapped-accounts`,
  validate: (id: string) => `${DEFINITIONS}/${id}/validate`,
  newVersion: (id: string) => `${DEFINITIONS}/${id}/new-version`,
  publish: (id: string) => `${DEFINITIONS}/${id}/publish`,
  archive: (id: string) => `${DEFINITIONS}/${id}/archive`,
  accountBalance: `${API}/reports/account-balance`,
  accountBalanceExport: `${API}/reports/account-balance/export`,
} as const

/**
 * What the signed-in user may do with report definitions. Authoring needs the permission AND a writable reporting module (a READ_ONLY
 * subscription keeps the views, the validation and the exports). Cosmetic only: the API enforces every one of them.
 */
export function useDefinitionAccess() {
  const access = useModuleAccess(REPORTING)
  return { ...access, canManage: access.canChange('accounting.report_definition.manage') }
}

export const isMapped = (type: LineType): boolean => MAPPED_TYPES.includes(type)
export const isContainer = (type: LineType): boolean => CONTAINER_TYPES.includes(type)

/** Lines an account can be mapped to. */
export const mappableLines = (lines: ReportLine[]): ReportLine[] => lines.filter((l) => isMapped(l.line_type))

/**
 * The whole mapping of a definition as the PUT mappings body wants it: the API replaces the mapping as a unit, so a change sends every
 * mapping that stays plus the one that is added. Only the shape is built here; the API decides whether the result is valid.
 */
export function mappingEntries(lines: ReportLine[]): MappingEntry[] {
  return lines.flatMap((l) => l.mappings.map((m) => ({ line_code: l.code, account_id: m.account_id, include_descendants: m.include_descendants })))
}

export const withMapping = (lines: ReportLine[], entry: MappingEntry): MappingEntry[] => [...mappingEntries(lines), entry]

export const withoutMapping = (lines: ReportLine[], lineCode: string, accountId: string): MappingEntry[] =>
  mappingEntries(lines).filter((e) => !(e.line_code === lineCode && e.account_id === accountId))

/** Where an account is already mapped in this definition, as "code · label" of the line (an account may be mapped once). */
export function mappedAt(lines: ReportLine[], accountId: string): ReportLine | null {
  return lines.find((l) => l.mappings.some((m) => m.account_id === accountId)) ?? null
}

/** Account id to "code · name" for every account mapped in the definition (validator findings only carry the id). */
export function accountLookup(lines: ReportLine[], extra: { id: string; code: string; name: string }[] = []) {
  const known = new Map<string, string>()
  for (const l of lines) for (const m of l.mappings) known.set(m.account_id, `${m.code} · ${m.name}`)
  for (const a of extra) known.set(a.id, `${a.code} · ${a.name}`)
  return (id: unknown): string | null => (typeof id === 'string' ? (known.get(id) ?? null) : null)
}

// ------------------------------------------------------------------------------------------------ lines

/** The body of one line for the line endpoints: every attribute the API knows, `null` where the line type does not use it. */
export type LineBody = {
  code?: string
  label: string
  line_type: LineType
  parent_code: string | null
  display_order?: number
  balance_side: 'DEBIT' | 'CREDIT' | null
  cash_flow_category: string | null
  system_key: string | null
  operands: { line: string; sign: '+' | '-' }[] | null
  show_zero: boolean
  emphasis: boolean
  notes: string | null
}

/** A stored line as the replace-lines body wants it (the whole tree travels together; lines are matched by code). */
export function lineBody(l: ReportLine): LineBody & { code: string } {
  return {
    code: l.code, label: l.label, line_type: l.line_type, parent_code: l.parent_code, display_order: l.display_order, balance_side: l.balance_side,
    cash_flow_category: l.cash_flow_category, system_key: l.system_key, operands: l.operands, show_zero: l.show_zero, emphasis: l.emphasis, notes: l.notes,
  }
}

/** Codes of the line and every line below it (a line cannot be moved under itself). */
export function descendantCodes(lines: ReportLine[], code: string): Set<string> {
  const out = new Set<string>([code])
  let grew = true
  while (grew) {
    grew = false
    for (const l of lines) {
      if (l.parent_code && out.has(l.parent_code) && !out.has(l.code)) {
        out.add(l.code)
        grew = true
      }
    }
  }
  return out
}

/** Siblings of a line (same parent) in the order the tree shows them. */
export const siblingsOf = (lines: ReportLine[], line: ReportLine): ReportLine[] => lines.filter((l) => l.parent_code === line.parent_code)

/**
 * The replace-lines body after moving a line one place up or down among its siblings, or null when it cannot move that way. Only the
 * display order of the siblings changes (renumbered 10, 20, ...); codes, mappings and everything else travel unchanged.
 */
export function movedLines(lines: ReportLine[], code: string, direction: -1 | 1): (LineBody & { code: string })[] | null {
  const line = lines.find((l) => l.code === code)
  if (!line) return null
  const siblings = siblingsOf(lines, line)
  const at = siblings.findIndex((l) => l.code === code)
  const to = at + direction
  if (to < 0 || to >= siblings.length) return null
  const order = [...siblings]
  ;[order[at], order[to]] = [order[to], order[at]]
  const next = new Map(order.map((l, i) => [l.code, (i + 1) * 10]))
  return lines.map((l) => ({ ...lineBody(l), display_order: next.get(l.code) ?? l.display_order }))
}

/** The dates a definition serves, as text: "1 Jan 2026 – sekarang". A draft serves none yet. */
export function windowText(d: Pick<DefinitionHeader, 'effective_from' | 'effective_to' | 'status'>): string {
  if (d.status === 'DRAFT') return 'Belum berlaku'
  const from = d.effective_from ? formatDate(d.effective_from) : 'awal'
  return `${from} – ${d.effective_to ? formatDate(d.effective_to) : 'sekarang'}`
}

/** What the line dialog edits; empty strings mean "not set". */
export type LineForm = {
  code: string
  label: string
  line_type: LineType
  parent_code: string
  balance_side: '' | BalanceSide
  cash_flow_category: string
  system_key: string
  operands: Operand[]
  show_zero: boolean
  emphasis: boolean
  notes: string
  display_order: string
}

export function lineForm(line: ReportLine | null, parentCode = ''): LineForm {
  return {
    code: line?.code ?? '', label: line?.label ?? '', line_type: line?.line_type ?? 'ACCOUNT', parent_code: line ? (line.parent_code ?? '') : parentCode,
    balance_side: line?.balance_side ?? '', cash_flow_category: line?.cash_flow_category ?? '', system_key: line?.system_key ?? '', operands: line?.operands ?? [],
    show_zero: line?.show_zero ?? false, emphasis: line?.emphasis ?? false, notes: line?.notes ?? '', display_order: line ? String(line.display_order) : '',
  }
}

/**
 * The request body of the form: the attributes the chosen line type uses, `null` for every other one (the API refuses a stray balance side, system
 * figure or operand list, and a type change must clear what the old type had). The code travels only when the line is created.
 */
export function lineBodyFromForm(f: LineForm, statement: StatementType, creating: boolean): LineBody {
  const mapped = isMapped(f.line_type)
  const order = f.display_order.trim()
  return {
    ...(creating && { code: f.code.trim() }),
    label: f.label.trim(),
    line_type: f.line_type,
    parent_code: f.parent_code || null,
    ...(order !== '' && Number.isInteger(Number(order)) && { display_order: Number(order) }),
    balance_side: mapped && f.balance_side ? f.balance_side : null,
    cash_flow_category: mapped && statement === 'CASH_FLOW' && f.cash_flow_category ? f.cash_flow_category : null,
    system_key: f.line_type === 'SYSTEM' ? f.system_key || null : null,
    operands: f.line_type === 'CALCULATED_TOTAL' ? f.operands.filter((o) => o.line) : null,
    show_zero: f.show_zero,
    emphasis: f.emphasis,
    notes: f.notes.trim() || null,
  }
}
