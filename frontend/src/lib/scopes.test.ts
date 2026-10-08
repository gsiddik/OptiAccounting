import { describe, expect, it } from 'vitest'
import { toDraft, toPayload } from './scopes'

describe('data scope payload', () => {
  it('sends only the reference that belongs to the scope type', () => {
    const payload = toPayload([
      { scope_type: 'TENANT', branch_id: 'stale-branch', business_unit_id: 'stale-unit' },
      { scope_type: 'BRANCH', branch_id: 'b-1', business_unit_id: 'stale-unit' },
      { scope_type: 'BUSINESS_UNIT', branch_id: 'stale-branch', business_unit_id: 'u-1' },
      { scope_type: 'OWN', branch_id: '', business_unit_id: '' },
    ])
    expect(payload).toEqual([
      { scope_type: 'TENANT' },
      { scope_type: 'BRANCH', branch_id: 'b-1' },
      { scope_type: 'BUSINESS_UNIT', business_unit_id: 'u-1' },
      { scope_type: 'OWN' },
    ])
  })

  it('turns a missing branch into null so the API reports it instead of guessing', () => {
    expect(toPayload([{ scope_type: 'BRANCH', branch_id: '', business_unit_id: '' }])).toEqual([{ scope_type: 'BRANCH', branch_id: null }])
  })

  it('round-trips a stored row into an editable draft', () => {
    expect(toDraft({ scope_type: 'BRANCH', branch_id: 'b-1' })).toEqual({ scope_type: 'BRANCH', branch_id: 'b-1', business_unit_id: '' })
  })
})
