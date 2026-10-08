import type { DataScopeRow } from './types'

export type ScopeDraft = { scope_type: DataScopeRow['scope_type']; branch_id: string; business_unit_id: string }

export const SCOPE_TYPES: ScopeDraft['scope_type'][] = ['TENANT', 'BRANCH', 'BUSINESS_UNIT', 'OWN']

export const toDraft = (row: DataScopeRow): ScopeDraft => ({ scope_type: row.scope_type, branch_id: row.branch_id ?? '', business_unit_id: row.business_unit_id ?? '' })

/** Request body rows: only the reference that matches the scope type is sent. */
export function toPayload(rows: ScopeDraft[]): DataScopeRow[] {
  return rows.map((r) => ({
    scope_type: r.scope_type,
    ...(r.scope_type === 'BRANCH' ? { branch_id: r.branch_id || null } : {}),
    ...(r.scope_type === 'BUSINESS_UNIT' ? { business_unit_id: r.business_unit_id || null } : {}),
  }))
}
