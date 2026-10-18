import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import { setLocale } from '../../../i18n'
import { SeatsBanner } from './SeatsBanner'
import type { Seats } from './types'

const seats = (over: Partial<Seats>): Seats => ({
  state: 'LIMITED', controlled: true, limit: 5, unlimited: false, used: 2, reserved: 1, available: 2, over_limit: false, over_by: 0, can_add: true, blocked_reason: null, ...over,
})

function show(value: Seats | null) {
  return render(<SeatsBanner seats={value} />)
}

describe('SeatsBanner', () => {
  beforeEach(() => setLocale('en'))

  it('shows used, reserved, available and the limit exactly as OptiNexus counted them', () => {
    show(seats({}))
    expect(screen.getByRole('status')).toHaveTextContent('User seats: 2 used, 1 reserved by open invitations, 2 available of a limit of 5.')
  })

  it('warns when no seat is left', () => {
    show(seats({ used: 3, reserved: 2, available: 0, can_add: false, blocked_reason: 'SEAT_LIMIT_REACHED' }))
    expect(screen.getByRole('alert')).toHaveTextContent('No seat is left: nobody new can be added or invited.')
  })

  it('reports a limit below usage as over, never as a negative number, and says nobody is removed', () => {
    show(seats({ limit: 2, used: 3, reserved: 1, available: 0, over_limit: true, over_by: 2, can_add: false, blocked_reason: 'SEAT_OVER_LIMIT' }))
    const alert = screen.getByRole('alert')
    expect(alert).toHaveTextContent('Over the seat limit by 2.')
    expect(alert).toHaveTextContent('3 used and 1 reserved against a limit of 2. No access is removed automatically.')
    expect(alert).not.toHaveTextContent('-')
  })

  it.each([
    ['ENTITLEMENT_MISSING', /does not set a seat limit/],
    ['ENTITLEMENT_INVALID', /is not valid/],
    ['ENTITLEMENT_UNAVAILABLE', /cannot be read right now/],
  ] as const)('says a %s limit is undecided and blocks additions instead of showing unlimited', (state, text) => {
    show(seats({ state, limit: null, available: null, can_add: false, blocked_reason: `SEAT_${state}` }))
    expect(screen.getByRole('alert')).toHaveTextContent(text)
    expect(screen.queryByText(/no limit/i)).not.toBeInTheDocument()
  })

  it('says unlimited only for an explicit unlimited entitlement, and that no limit is applied when the product has none', () => {
    const { unmount } = show(seats({ state: 'UNLIMITED', unlimited: true, limit: null, available: null }))
    expect(screen.getByRole('status')).toHaveTextContent('2 used, 1 reserved by open invitations (no limit)')
    unmount()
    show(seats({ state: 'NOT_CONTROLLED', controlled: false, limit: null, available: null }))
    expect(screen.getByRole('status')).toHaveTextContent('OptiNexus does not apply a seat limit to this product (2 users with access).')
  })

  it('shows nothing when OptiNexus sent no figures', () => {
    const { container } = show(null)
    expect(container).toBeEmptyDOMElement()
  })

  it('reads in Indonesian', () => {
    setLocale('id')
    show(seats({}))
    expect(screen.getByRole('status')).toHaveTextContent('Kursi pengguna: 2 terpakai, 1 dicadangkan undangan terbuka, 2 tersedia dari batas 5.')
  })
})
